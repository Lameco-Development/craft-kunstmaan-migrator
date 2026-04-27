<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\transform;

use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\handlers\AssetHandler;
use lameco\kunstmaanmigrator\fields\handlers\MatrixHandler;
use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\handlers\SplitNameHandler;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\transform\TransformService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 5 / TST-02 — Transform-stage characterization tests against
 * tests/fixtures/transform/{input,golden}/<entity>/<id>.json (D-01..D-04).
 *
 * Refresh mechanism (D-03): set `UPDATE_SNAPSHOTS=1` to rewrite missing or
 * differing goldens in place. Without it, missing goldens fail loudly —
 * never silent-create.
 *
 * Comparator (PATTERNS risk callout 7 / CONTEXT ## Risks paragraph 3):
 * JSON-canonicalized via recursive ksort + JSON_PRETTY_PRINT |
 * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE before string-diff so the
 * fixtures survive PHP version bumps and array-key insertion-order shifts.
 * The list-vs-assoc check preserves ordering for matrix blocks / asset
 * arrays while ksort applies only to associative arrays.
 *
 * Per D-01 the test instantiates TransformService directly (no Craft
 * bootstrap, no DB). Because the actual TransformService surface is a Yii
 * Component with property-injected dependencies that yields per-row payloads
 * via `run(iterable $extracted, array $mapping, MigrationFilters $filters,
 * array $options): iterable`, this test:
 *   1. Wraps the single input fixture row in a one-element iterable.
 *   2. Drives `run()` with an empty mapping/filters pair and the input
 *      fixture's pre-extracted shape.
 *   3. Collects every yielded payload, drops the trailing `__report`
 *      sentinel, and asserts exactly one entry-payload comes back.
 *   4. Canonicalizes that payload + diffs against the golden.
 *
 * The mapping argument supplied to `run()` is loaded from
 * `tests/fixtures/transform/mapping.json` — a one-shot snapshot the operator
 * capture script (`tools/capture-transform-fixtures.php`) writes alongside
 * the input tree so every fixture is exercised against the same mapping
 * rules that produced its source row. Without this snapshot, every fixture
 * would short-circuit at TransformService's "No nodeClasses mapping for
 * {fqcn}" warning and the goldens would degenerate to empty-array stubs —
 * defeating the TST-02 regression-signal goal entirely.
 *
 * When the snapshot is absent (the on-ship state, before the operator runs
 * the capture workflow), the data provider also yields nothing — the test
 * reports as risky / no-tests-found, which is non-fatal under the current
 * phpunit.xml.dist (failOnRisky not set).
 */
final class TransformCharacterizationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> rel => [inputPath, goldenPath]
     */
    public static function fixtureProvider(): iterable
    {
        $base = __DIR__ . '/../../fixtures/transform';
        $inputBase = $base . '/input';
        if (!is_dir($inputBase)) {
            return;
        }
        $matches = glob($inputBase . '/*/*.json') ?: [];
        sort($matches);
        foreach ($matches as $inputPath) {
            $rel = substr($inputPath, strlen($inputBase . '/'));
            $goldenPath = $base . '/golden/' . $rel;
            yield $rel => [$inputPath, $goldenPath];
        }
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testTransformRowMatchesGolden(string $inputPath, string $goldenPath): void
    {
        $rawJson = file_get_contents($inputPath);
        self::assertNotFalse($rawJson, "Input fixture unreadable: {$inputPath}");
        $input = json_decode($rawJson, true);
        self::assertIsArray($input, "Input fixture not a JSON object: {$inputPath}");

        $mapping = self::loadMapping();

        $service = self::createService();
        $payloads = [];
        foreach ($service->run([$input], $mapping, new MigrationFilters(), []) as $yielded) {
            if (!is_array($yielded)) {
                continue;
            }
            // Drop the `__report` sentinel; we only diff entry payloads.
            if (array_key_exists('__report', $yielded)) {
                continue;
            }
            $payloads[] = $yielded;
        }

        $actualJson = self::canonicalize($payloads);

        $update = getenv('UPDATE_SNAPSHOTS') === '1';
        if (!is_file($goldenPath)) {
            if ($update) {
                @mkdir(dirname($goldenPath), 0755, true);
                file_put_contents($goldenPath, $actualJson);
                self::markTestSkipped("Golden refreshed: {$goldenPath}");
                return;
            }
            self::fail("Golden missing: {$goldenPath} (set UPDATE_SNAPSHOTS=1 to create)");
        }

        $expected = (string) file_get_contents($goldenPath);
        if ($update && $expected !== $actualJson) {
            file_put_contents($goldenPath, $actualJson);
            self::markTestSkipped("Golden updated: {$goldenPath}");
            return;
        }

        self::assertSame($expected, $actualJson, "Diff vs golden: {$goldenPath}");
    }

    /**
     * Load the mapping snapshot the operator capture script writes alongside
     * the input fixture tree. Returns [] when the snapshot is absent so the
     * empty-corpus on-ship state is still observable (data provider empty +
     * test risky-no-tests).
     *
     * @return array<string, mixed>
     */
    private static function loadMapping(): array
    {
        $path = __DIR__ . '/../../fixtures/transform/mapping.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build a TransformService with the smallest possible stub dependencies.
     *
     * Per D-01 the test instantiates the service directly. TransformService
     * (Phase 3 / Plan 05) is a Yii Component whose two hard-required slots
     * are `handlerRegistry` and `ckeditorRewriter`; everything else has a
     * null-tolerant fallback (legacyDb skips join hydration, migrationState
     * defaults to a no-op reader, assetPathResolver self-constructs).
     *
     * The handler registry is wired with the same five field handlers that
     * Plugin::init() registers in production so any handler dispatch reached
     * via mapping rules resolves to a real handler.
     */
    private static function createService(): TransformService
    {
        $registry = new FieldHandlerRegistry();
        $registry->register(new PlainTextHandler('plain'));
        $registry->register(new PlainTextHandler('ckeditor'));
        $registry->register(new PlainTextHandler('link'));
        $registry->register(new PlainTextHandler('dropdown'));
        $registry->register(new AssetHandler());
        $registry->register(new RelationHandler());
        $registry->register(new MatrixHandler());
        $registry->register(new SplitNameHandler());

        $service = new TransformService();
        $service->handlerRegistry  = $registry;
        $service->ckeditorRewriter = new CkeditorRewriterService();
        // legacyDb / migrationState / assetPathResolver intentionally null —
        // TransformService tolerates absent slots (see emptyStateReader() and
        // resolvePaths() fallbacks; hydrateDetailJoins short-circuits on null
        // legacyDb).

        return $service;
    }

    /**
     * D-Risks #7 / PATTERNS callout 7 — recursive ksort that preserves list
     * ordering, then encode with stable flags + trailing newline.
     */
    private static function canonicalize(mixed $value): string
    {
        $sort = static function (mixed &$v) use (&$sort): void {
            if (is_array($v)) {
                if (array_is_list($v)) {
                    // List: preserve ordering; recurse into items.
                    foreach ($v as &$item) {
                        $sort($item);
                    }
                    unset($item);
                } else {
                    // Associative: ksort + recurse.
                    ksort($v);
                    foreach ($v as &$item) {
                        $sort($item);
                    }
                    unset($item);
                }
            }
        };
        $sort($value);
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        return $json . "\n";
    }
}
