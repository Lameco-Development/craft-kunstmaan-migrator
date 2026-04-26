<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\load\MigrationReport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-07 / Task 3 — characterization tests for the REC-01
 * `migrate/sync-assets` recovery command surface on MigrateController.
 *
 * The action body itself touches Craft DI (Plugin::getInstance, Console
 * progress, file system) and so cannot run under the autoloader-only test
 * bootstrap. The pure logic — candidate filtering and SYNC-ASSETS-*.md
 * rendering — is extracted into static helpers (mirrors Phase 4 / Plan 12
 * ReportEmptyState pattern + Phase 4.1 / Plan 04.1-05 testability surface)
 * and characterized here via Reflection.
 *
 * D-37 contract locks (`testCandidateMatchesFiltersSkipsTerminalRows` +
 * `testCandidateMatchesFiltersSkipsHealedRows`) prevent the retry-loop DoS
 * (T-04.1-07-01).
 *
 * Per Task 1 discovery (Finding B), only sync-assets ships in this plan;
 * sync-relations is deferred to Phase 4.2 — there is no sibling test file.
 */
final class MigrateControllerSyncAssetsTest extends TestCase
{
    /**
     * @param array<string, mixed> $row
     */
    private function candidateMatchesFilters(array $row, MigrationFilters $filters): bool
    {
        $m = new ReflectionMethod(MigrateController::class, 'syncAssetsCandidateMatchesFilters');
        return (bool) $m->invoke(null, $row, $filters);
    }

    private function makeFilters(array $entities = [], array $locales = [], ?string $since = null): MigrationFilters
    {
        // Constructor signature (Phase 4.1 / Plan 04.1-05): (entities, locales, since, noSeo, noRetour).
        return new MigrationFilters($entities, $locales, $since, false, false);
    }

    public function testCandidateMatchesFiltersFiltersByEntities(): void
    {
        $filters = $this->makeFilters(entities: ['blogPosts']);
        // Asset row owned by a blogPost entry — included.
        self::assertTrue($this->candidateMatchesFilters(
            ['sourceKey' => 'kuma_media:1', 'meta' => ['ownerEntity' => 'blogPosts']],
            $filters,
        ));
        // Asset row owned by an event entry — excluded.
        self::assertFalse($this->candidateMatchesFilters(
            ['sourceKey' => 'kuma_media:2', 'meta' => ['ownerEntity' => 'events']],
            $filters,
        ));
    }

    public function testCandidateMatchesFiltersFiltersBySince(): void
    {
        $filters = $this->makeFilters(since: '2026-01-01');
        // Row dated after the floor — included.
        self::assertTrue($this->candidateMatchesFilters(
            ['sourceKey' => 'kuma_media:3', 'dateUpdated' => '2026-02-15 10:00:00'],
            $filters,
        ));
        // Row dated before the floor — excluded.
        self::assertFalse($this->candidateMatchesFilters(
            ['sourceKey' => 'kuma_media:4', 'dateUpdated' => '2025-11-30 10:00:00'],
            $filters,
        ));
    }

    public function testCandidateMatchesFiltersSkipsTerminalRows(): void
    {
        // D-37 contract — the candidate predicate must reject terminal rows
        // even when entity/locale/since filters would otherwise admit them.
        // T-04.1-07-01 mitigation lock.
        $filters = $this->makeFilters();
        self::assertFalse($this->candidateMatchesFilters(
            [
                'sourceKey' => 'kuma_media:5',
                'meta' => [
                    'terminalState' => 'permanently_failed',
                    'terminalReason' => 'filesystem_404',
                ],
            ],
            $filters,
        ));
    }

    public function testCandidateMatchesFiltersSkipsHealedRows(): void
    {
        // D-36 idempotence — a row whose targetId is set has been successfully
        // healed (either by a prior sync-assets run or by the main migrate
        // pass since the skip-marker was written). Re-running sync-assets
        // must NOT re-process it.
        $filters = $this->makeFilters();
        self::assertFalse($this->candidateMatchesFilters(
            ['sourceKey' => 'kuma_media:6', 'targetId' => 4242],
            $filters,
        ));
    }

    public function testCandidateMatchesFiltersAcceptsRowWithoutOwnerWhenEntityFilterSet(): void
    {
        // Edge case: many media state-rows are written with no ownerEntity
        // metadata (the existing AssetMigrationService::record() shape doesn't
        // include it — see lines 402-415 + 558-573). Excluding rows-without-
        // owner when an entity filter is set would silently drop most
        // candidates. Instead: pass-through when ownerEntity is unknown so the
        // operator sees them and can narrow further with a different scope.
        $filters = $this->makeFilters(entities: ['blogPosts']);
        self::assertTrue($this->candidateMatchesFilters(
            ['sourceKey' => 'kuma_media:7', 'meta' => []],
            $filters,
        ));
    }

    public function testRenderSyncAssetsReportEmitsHeadingAndPlaceholderWhenNoCandidates(): void
    {
        $rendered = $this->renderReport(
            healed: 0,
            failed: 0,
            terminal: 0,
            candidates: 0,
            filters: $this->makeFilters(),
        );
        self::assertStringContainsString('# Sync Assets', $rendered);
        self::assertStringContainsString('## Rehearsal summary', $rendered);
        self::assertStringContainsString('Candidates: 0', $rendered);
        self::assertStringContainsString(
            '_No candidates — all prior skipped media has been healed or marked terminal._',
            $rendered,
        );
    }

    public function testRenderSyncAssetsReportEmitsFilterScopeLine(): void
    {
        $rendered = $this->renderReport(
            healed: 3,
            failed: 1,
            terminal: 2,
            candidates: 6,
            filters: $this->makeFilters(
                entities: ['blogPosts', 'events'],
                locales: ['nl'],
                since: '2026-02-01',
            ),
        );
        self::assertStringContainsString('Healed:     3', $rendered);
        self::assertStringContainsString('Failed:     1', $rendered);
        self::assertStringContainsString('Terminal:   2', $rendered);
        self::assertStringContainsString('entities=blogPosts,events', $rendered);
        self::assertStringContainsString('locales=nl', $rendered);
        self::assertStringContainsString('since=2026-02-01', $rendered);
        // Placeholder absent when candidates > 0.
        self::assertStringNotContainsString('_No candidates', $rendered);
    }

    public function testClassifyResolveFailureMessageBucketsKnownPhrases(): void
    {
        // The static failure-classifier mirrors AssetMigrationService::
        // classifyAssetFailureReason heuristics so REC-01 doesn't need
        // to widen AssetMigrationService's surface (acceptance lock:
        // git diff src/load/AssetMigrationService.php must be empty).
        $m = new ReflectionMethod(MigrateController::class, 'syncAssetsClassifyResolveFailureMessage');
        self::assertSame('filesystem_404', $m->invoke(null, 'No such file or directory'));
        self::assertSame('filesystem_404', $m->invoke(null, 'asset not found'));
        self::assertSame('filesystem_404', $m->invoke(null, 'Copy failed: /a → /b'));
        self::assertSame('mime_mismatch', $m->invoke(null, 'invalid mime'));
        self::assertSame('mime_mismatch', $m->invoke(null, 'content_type unknown'));
        self::assertSame('mime_mismatch', $m->invoke(null, 'allowedFileExtensions does not permit'));
        self::assertSame('too_large', $m->invoke(null, 'file too large'));
        self::assertSame('too_large', $m->invoke(null, 'PostMaxSize exceeded'));
        self::assertSame('deferred_unresolved', $m->invoke(null, 'something else entirely'));
        self::assertSame('deferred_unresolved', $m->invoke(null, ''));
    }

    /**
     * Reflection convenience: invoke the public static report renderer.
     */
    private function renderReport(
        int $healed,
        int $failed,
        int $terminal,
        int $candidates,
        MigrationFilters $filters,
    ): string {
        $report = new MigrationReport();
        if ($healed > 0)   { $report->incr('healed', $healed); }
        if ($failed > 0)   { $report->incr('failed', $failed); }
        if ($terminal > 0) { $report->incr('terminal', $terminal); }

        $m = new ReflectionMethod(MigrateController::class, 'renderSyncAssetsReport');
        return (string) $m->invoke(null, $report, $filters, $candidates, 0.0);
    }
}
