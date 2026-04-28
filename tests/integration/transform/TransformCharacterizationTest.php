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
use lameco\kunstmaanmigrator\load\MigrationStateReader;
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
 * the capture workflow), the default test run skips the sentinel fixture so
 * contributors can run the suite without private rehearsal evidence. Release
 * mode is different: set `RELEASE_REHEARSAL=1` and an empty corpus fails
 * loudly with "Release rehearsal fixture corpus is empty".
 */
final class TransformCharacterizationTest extends TestCase
{
    public function testStructuralFixtureCapturesPagePartMatrixBlockWithoutNativeTitle(): void
    {
        $payload = self::transformOne(
            self::structuralExtractedRow([
                'pageParts' => [[
                    'fqcn' => 'App\\Entity\\TextPart',
                    'context' => 'main',
                    'sourcePartId' => 77,
                    'row' => ['heading' => 'Synthetic heading'],
                ]],
            ]),
            self::structuralMapping([
                'nodeClasses' => [
                    'App\\Entity\\StructuralPage' => [
                        'sourceTable' => 'structural_pages',
                        'section' => 'structuralPage',
                        'fields' => [
                            'title' => ['handler' => 'plain', 'source' => 'title'],
                        ],
                        'pageBuilderHandle' => 'pageBuilder',
                        'pageBuilderContexts' => ['main'],
                    ],
                ],
                'pageParts' => [
                    'App\\Entity\\TextPart' => [
                        'target' => 'contentBlock',
                        'fields' => [
                            'heading' => ['handler' => 'plain', 'source' => 'heading'],
                        ],
                    ],
                ],
            ]),
        );

        $block = $payload['perSite']['default']['fieldValues']['pageBuilder']['new1'];
        self::assertSame('contentBlock', $block['type']);
        self::assertSame('Synthetic heading', $block['fields']['heading']);
        self::assertSame('TextPart:77', $block['fields']['_sourcePartRef']);
        self::assertArrayNotHasKey('title', $block, 'Structural fixture intentionally models Matrix blocks that reach load without native titles.');
    }

    public function testStructuralFixtureCapturesSparseLocalePrimarySaveShape(): void
    {
        $payload = self::transformOne(
            self::structuralExtractedRow(siteHandle: 'en'),
            self::structuralMapping(),
        );

        self::assertSame(['en'], array_keys($payload['perSite']));
        self::assertArrayNotHasKey('default', $payload['perSite']);
        self::assertSame('Sparse locale title', $payload['perSite']['en']['title']);
        self::assertSame('sparse-locale-title', $payload['perSite']['en']['slug']);
    }

    public function testStructuralFixtureCapturesInvalidSectionEntryTypeTargetShape(): void
    {
        $payload = self::transformOne(
            self::structuralExtractedRow(),
            self::structuralMapping([
                'sections' => [
                    'formContentBlock' => [
                        'section' => 'contentPages',
                        'entryType' => 'formContentBlock',
                    ],
                ],
                'nodeClasses' => [
                    'App\\Entity\\StructuralPage' => [
                        'sourceTable' => 'structural_pages',
                        'section' => 'formContentBlock',
                        'fields' => [
                            'title' => ['handler' => 'plain', 'source' => 'title'],
                        ],
                    ],
                ],
            ]),
        );

        self::assertSame('contentPages', $payload['section']);
        self::assertSame('formContentBlock', $payload['entryType']);
    }

    public function testStructuralFixtureCapturesTaxonomyRelationBeforeStateExists(): void
    {
        $service = self::createService();
        $service->migrationState = new class implements MigrationStateReader {
            public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
            {
                return null;
            }

            public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
            {
                return null;
            }

            public function get(string $source, string $key, ?int $siteId = null): ?array
            {
                return null;
            }
        };

        $payloads = [];
        foreach ($service->run([
            self::structuralExtractedRow([
                'detail' => [
                    'id' => 101,
                    'title' => 'Taxonomy relation title',
                    'category_id' => 44,
                ],
            ]),
        ], self::structuralMapping([
            'nodeClasses' => [
                'App\\Entity\\StructuralPage' => [
                    'sourceTable' => 'structural_pages',
                    'section' => 'structuralPage',
                    'fields' => [
                        'title' => ['handler' => 'plain', 'source' => 'title'],
                        'category' => [
                            'handler' => 'relation',
                            'source' => 'category_id',
                            'handlerOptions' => ['stateSource' => 'App_Entity_TaxonomyCategory'],
                        ],
                    ],
                ],
            ],
        ]), new MigrationFilters(), []) as $yielded) {
            if (is_array($yielded) && !isset($yielded['__report'])) {
                $payloads[] = $yielded;
            }
        }

        self::assertCount(1, $payloads);
        self::assertSame([], $payloads[0]['perSite']['default']['fieldValues']['category']);
    }

    /**
     * @return iterable<string, array{string, string}> rel => [inputPath, goldenPath]
     */
    public static function fixtureProvider(): iterable
    {
        $base = __DIR__ . '/../../fixtures/transform';
        $inputBase = $base . '/input';
        $matches = is_dir($inputBase) ? (glob($inputBase . '/*/*.json') ?: []) : [];
        sort($matches);
        if ($matches === []) {
            // PHPUnit 11 errors on empty data providers. Yield a sentinel so
            // the on-ship empty-corpus state stays non-fatal outside release
            // mode, while RELEASE_REHEARSAL=1 fails loudly in the test body.
            yield '__no_fixtures__' => ['', ''];
            return;
        }
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
        if ($inputPath === '' && $goldenPath === '') {
            if (getenv('RELEASE_REHEARSAL') === '1') {
                self::fail(
                    'Release rehearsal fixture corpus is empty; run tools/capture-transform-fixtures.php '
                    . 'against the CQM rehearsal target and commit non-empty input/golden fixture pairs.',
                );
            }
            self::markTestSkipped('No transform fixtures present (run tools/capture-transform-fixtures.php to populate).');
        }
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
     * @param array<string, mixed> $extractedOverrides
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private static function transformOne(array $extractedOverrides, array $mapping): array
    {
        $payloads = [];
        foreach (self::createService()->run([$extractedOverrides], $mapping, new MigrationFilters(), []) as $yielded) {
            if (is_array($yielded) && !isset($yielded['__report'])) {
                $payloads[] = $yielded;
            }
        }
        self::assertCount(1, $payloads);
        return $payloads[0];
    }

    /**
     * @param array<string, mixed> $siteOverrides
     * @return array<string, mixed>
     */
    private static function structuralExtractedRow(array $siteOverrides = [], string $siteHandle = 'nl'): array
    {
        $siteData = array_replace_recursive([
            'online' => true,
            'title' => $siteHandle === 'en' ? 'Sparse locale title' : 'Structural title',
            'slug' => $siteHandle === 'en' ? 'sparse-locale-title' : 'structural-title',
            'detail' => [
                'id' => 101,
                'title' => $siteHandle === 'en' ? 'Sparse locale title' : 'Structural title',
                'body' => '<p>Synthetic body</p>',
            ],
            'pageParts' => [],
        ], $siteOverrides);

        return [
            'fqcn' => 'App\\Entity\\StructuralPage',
            'kunstmaanSourceId' => 'structural:101',
            'kuma_node_id' => 101,
            'kuma_parent_id' => null,
            'refIdsByLocale' => [$siteHandle => 101],
            'perSite' => [
                $siteHandle => $siteData,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function structuralMapping(array $overrides = []): array
    {
        return array_replace_recursive([
            'sites' => [
                'nl' => 'default',
                'en' => 'en',
            ],
            'sections' => [
                'structuralPage' => [
                    'section' => 'contentPages',
                    'entryType' => 'contentPage',
                ],
            ],
            'nodeClasses' => [
                'App\\Entity\\StructuralPage' => [
                    'sourceTable' => 'structural_pages',
                    'section' => 'structuralPage',
                    'fields' => [
                        'title' => ['handler' => 'plain', 'source' => 'title'],
                    ],
                ],
            ],
        ], $overrides);
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
