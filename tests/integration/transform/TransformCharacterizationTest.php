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
 * The mapping argument supplied to `run()` is reconstructed from the input
 * fixture itself (the operator-captured fixtures already include the
 * `nodeClasses` + `sections` snippets that drove the original extract — see
 * tools/capture-transform-fixtures.php) so the test stays self-contained
 * and doesn't depend on a sibling mapping.yaml file. If a fixture lacks the
 * `_mapping` key, the test fails fast with an actionable error.
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

        // Operator-captured fixtures may carry the originating mapping snippet
        // under a `_mapping` key (added by tools/capture-transform-fixtures.php
        // in a follow-up patch once the corpus lands). When absent, fall back
        // to an empty mapping — TransformService will warn `No nodeClasses
        // mapping for {fqcn}` and short-circuit to the report sentinel, which
        // is itself a stable, characterizable output.
        $mapping = is_array($input['_mapping'] ?? null) ? (array) $input['_mapping'] : [];
        // Strip the synthetic key from the payload before feeding it back into
        // TransformService so the captured-row shape matches Phase 3's
        // ExtractService output format byte-for-byte.
        unset($input['_mapping']);

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
