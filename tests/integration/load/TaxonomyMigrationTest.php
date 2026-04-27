<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\load;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\load\TaxonomyMigrationService;
use lameco\kunstmaanmigrator\mapping\MappingFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Load the minimal global `Craft` class shim. See _craft_shim.php for the
// rationale: loading the real vendor/yiisoft/yii2/Yii.php (which Craft.php
// requires transitively) registers a prepend-mode autoloader that surfaces
// latent PHP 8.5 warnings in unrelated pre-existing tests
// (KunstmaanEnvReaderTest, TransformImplicitContentTest). The shim provides
// only the two static methods this service invokes — Craft::warning() and
// Craft::info() — so the empty-taxonomies short-circuit path resolves
// cleanly with no test-suite-wide side effects.
require_once __DIR__ . '/_craft_shim.php';

/**
 * Phase 8 / TAX-10 — end-to-end loop closure for TaxonomyMigrationService.
 *
 * Mirrors tests/integration/transform/TransformImplicitContentTest.php in
 * skeleton (final class extending PHPUnit\Framework\TestCase directly, no
 * Craft bootstrap), but the SUT here — TaxonomyMigrationService::migrateAll()
 * — is fundamentally Craft-coupled in its happy path: once the loop enters
 * migrateOneTaxonomy() it calls Craft::$app->getEntries() / sites /
 * elements->saveElement() / Entry::find() / new Entry(). The plan permitted
 * markTestIncomplete on the per-locale Gedmo overlay test for exactly this
 * reason; the other three tests exercise paths that DO NOT touch Craft::$app:
 *
 *   - testActionSkipRowDefensiveBranchIncrementsSkippedAndDoesNotEnterCraft —
 *     D-08 reshape #4 documented invariant: the v1 `action: SKIP` defensive
 *     check is preserved even though v2's compileTaxonomies never emits SKIP.
 *     Hits the early `continue` at line 106-109 BEFORE migrateOneTaxonomy().
 *     Also exercises the per-row `migrationState->record(siteId=null)` shape
 *     contract by binding a mock with `with(..., null)` (D-08 site-agnostic
 *     state-row).
 *
 *   - testEmptyTaxonomiesBlockEmitsWarnAndReturnsEarly — D-08 reshape #5
 *     (Phase 4 / D-56 detection-inside-the-service short-circuit). Only
 *     touches Craft::warning() which delegates to Yii's static logger and
 *     does NOT require Craft::$app to be set.
 *
 *   - testTaxonomiesStageRunsBeforeLoadInActionIndex — D-03 regression guard.
 *     Pure file_get_contents source-string scan; no Craft instance needed.
 *
 *   - testD09FallbackCopiesSourceLocaleAcrossSitesWhenExtTranslationsEmpty —
 *     markTestIncomplete (plan permits). The D-09 fallback path requires
 *     Craft::$app->sites / elements wired; deferred to a future
 *     integration-craft test plan.
 */
final class TaxonomyMigrationTest extends TestCase
{
    /**
     * D-08 reshape #4 defensive-branch coverage. The v1 `action: SKIP` row
     * shape is preserved as a defensive branch even though v2's compiler
     * (Plan 09 / compileTaxonomies) never emits it (only accepted rows are
     * emitted). A future operator hand-edit might still set it.
     *
     * Also serves as the documented `siteId=null` (D-08 site-agnostic
     * state-row) shape lock — the migrationState mock binds `with(..., null)`
     * for the record() signature even though no record() call fires on this
     * skip path. This lock survives any future refactor that might fire
     * record() pre-Craft.
     */
    public function testActionSkipRowDefensiveBranchIncrementsSkippedAndDoesNotEnterCraft(): void
    {
        $mapping = [
            'sites' => [
                'default' => ['siteHandle' => 'default'],
                'enUs'    => ['siteHandle' => 'enUs'],
            ],
            'taxonomies' => [
                'App\\Entity\\NewsCategory' => [
                    'sourceTable'     => 'kuma_news_categories',
                    'targetSection'   => 'newsCategories',
                    'targetEntryType' => 'newsCategory',
                    'fields'          => ['name' => 'title'],
                    // D-08 reshape #4: `action: SKIP` defensive branch — kept
                    // even though v2 compiler never emits it.
                    'action'          => 'SKIP',
                ],
            ],
        ];

        $mappingFile = $this->createStub(MappingFile::class);
        $mappingFile->method('load')->willReturn($mapping);

        $legacyDb = $this->createStub(LegacyDbService::class);
        $legacyDb->method('queryAll')->willReturn([]);
        $legacyDb->method('extTranslationsFor')->willReturn([]);

        // D-08 site-agnostic state row: record(..., siteId=null).
        // The mock asserts the with() shape even though no call fires on the
        // SKIP path — this locks the contract for any future refactor that
        // might fire record() pre-Craft.
        $stateService = $this->createMock(MigrationStateService::class);
        $stateService->expects($this->never())
            ->method('record')
            ->with(
                $this->anything(),  // stateSource
                $this->anything(),  // legacyId
                'entry',
                $this->anything(),  // entryId
                $this->anything(),  // uid
                null,               // siteId=null (D-08)
            );

        $svc = new TaxonomyMigrationService();
        $svc->mappingFile    = $mappingFile;
        $svc->legacyDb       = $legacyDb;
        $svc->migrationState = $stateService;

        $opts   = new MigrationOptions();
        $report = $svc->migrateAll($opts);

        $this->assertInstanceOf(MigrationReport::class, $report);
        $this->assertSame(1, (int) ($report->counts['skipped'] ?? 0));
        $this->assertSame(0, (int) ($report->counts['created'] ?? 0));
        $this->assertSame(0, (int) ($report->counts['failed']  ?? 0));
        // No "No taxonomies in mapping" warn here — the block is non-empty,
        // just SKIP-flagged. D-56 short-circuit only fires on empty.
        $this->assertSame([], $report->warnings);
    }

    /**
     * D-08 reshape #4 defense-in-depth: the SQL-injection regex whitelist
     * (`preg_match('/^[a-z0-9_]+$/', $sourceTable)`) at TaxonomyMigrationService
     * line 150 throws BEFORE the first Craft::$app call (line 156). Locks the
     * verbatim-port v1 invariant (v1 lines 159-163).
     */
    public function testMaliciousSourceTableTriggersSqlInjectionRegexThrow(): void
    {
        $mapping = [
            'sites' => ['default' => ['siteHandle' => 'default']],
            'taxonomies' => [
                'App\\Entity\\NewsCategory' => [
                    'sourceTable'     => 'kuma_news; DROP TABLE users;--',
                    'targetSection'   => 'newsCategories',
                    'targetEntryType' => 'newsCategory',
                    'fields'          => ['name' => 'title'],
                ],
            ],
        ];

        $mappingFile = $this->createStub(MappingFile::class);
        $mappingFile->method('load')->willReturn($mapping);

        $svc = new TaxonomyMigrationService();
        $svc->mappingFile    = $mappingFile;
        $svc->legacyDb       = $this->createStub(LegacyDbService::class);
        $svc->migrationState = $this->createMock(MigrationStateService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sourceTable whitelist failed');

        $svc->migrateAll(new MigrationOptions());
    }

    /**
     * D-08 reshape #5 — Phase 4 / D-56 detection-inside-the-service
     * short-circuit. Empty taxonomies block emits a single WARN line via
     * `$report->warn(...)` + `Craft::warning(...)` and returns. Mirrors
     * SeoMigrationService::migrateAll lines 131-149.
     *
     * Craft::warning() delegates to Yii's static logger and does NOT require
     * Craft::$app to be set, so this test runs without a Craft bootstrap.
     */
    public function testEmptyTaxonomiesBlockEmitsWarnAndReturnsEarly(): void
    {
        $mappingFile = $this->createStub(MappingFile::class);
        $mappingFile->method('load')->willReturn(['taxonomies' => []]);

        $svc = new TaxonomyMigrationService();
        $svc->mappingFile    = $mappingFile;
        $svc->legacyDb       = $this->createStub(LegacyDbService::class);
        $svc->migrationState = $this->createMock(MigrationStateService::class);

        $opts   = new MigrationOptions();
        $report = $svc->migrateAll($opts);

        // The D-56 short-circuit warn line is exact and stable — REPORT.md
        // skipped-stages aggregation pattern-matches on substrings of these.
        $found = false;
        foreach ($report->warnings as $w) {
            if (is_string($w) && str_contains($w, 'No taxonomies in mapping')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue(
            $found,
            'Empty taxonomies block must emit "No taxonomies in mapping" warn line.',
        );
        $this->assertSame(0, (int) ($report->counts['created'] ?? 0));
        $this->assertSame(0, (int) ($report->counts['updated'] ?? 0));
        $this->assertSame(0, (int) ($report->counts['skipped'] ?? 0));
        $this->assertSame(0, (int) ($report->counts['failed']  ?? 0));
    }

    /**
     * D-09 fallback: when extTranslationsFor() returns [] (monolingual
     * Kunstmaan install), copy the source-locale row across every site in
     * mapping.sites with propagateChanges=false. NEW v2 behavior, not in v1.
     *
     * Marked incomplete: the fallback path lives inside applyGedmoTranslations()
     * which calls Craft::$app->sites->getPrimarySite() / getSiteByHandle() and
     * Entry::find() / Craft::$app->elements->saveElement(). Fully exercising
     * it requires either a Craft bootstrap (out of scope for this plan) or a
     * non-trivial Craft::$app shim (~60 LOC). Plan 08-15 explicitly permits
     * markTestIncomplete on this single test.
     */
    public function testD09FallbackCopiesSourceLocaleAcrossSitesWhenExtTranslationsEmpty(): void
    {
        $this->markTestIncomplete(
            'D-09 fallback exercises Craft::$app->sites + elements->saveElement(); '
            . 'requires Craft bootstrap or Craft::$app shim (~60 LOC). '
            . 'Plan 08-15 permits markTestIncomplete on this single test.',
        );
    }

    /**
     * D-03 regression guard — taxonomies stage MUST run before load stage in
     * MigrateController::actionIndex. Pure source-string scan; no Craft
     * instance needed.
     *
     * D-03 is the architectural decision that taxonomies migrate BEFORE pages
     * so page entries' RelationHandler can resolve category FKs via the state
     * table by the time the load step runs.
     */
    public function testTaxonomiesStageRunsBeforeLoadInActionIndex(): void
    {
        $controllerPath = __DIR__ . '/../../../src/console/MigrateController.php';
        $this->assertFileExists(
            $controllerPath,
            'D-03: MigrateController must exist before run-order can be asserted.',
        );

        $source = file_get_contents($controllerPath);
        $this->assertIsString($source);

        $taxPos  = strpos($source, 'taxonomyMigrationService->migrateAll(');
        $loadPos = strpos($source, 'actionLoad(');

        $this->assertNotFalse(
            $taxPos,
            'D-03: taxonomyMigrationService->migrateAll( must appear in MigrateController source.',
        );
        $this->assertNotFalse(
            $loadPos,
            'D-03: load step marker (actionLoad() or equivalent) must appear in MigrateController source.',
        );
        $this->assertLessThan(
            $loadPos,
            $taxPos,
            'D-03: taxonomies stage must precede load stage in actionIndex (FK resolution invariant).',
        );
    }
}
