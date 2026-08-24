<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\load\SaveResult;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use PHPUnit\Framework\TestCase;

/**
 * What a run has done so far.
 *
 * This replaced four by-reference accumulators threaded through a closure.
 * They worked while one console process owned the whole run; a job that
 * handles one environment and hands the next to another job needs something it
 * can hold and report.
 */
final class RunTallyTest extends TestCase
{
    public function testCountsStartAtZeroRatherThanAbsent(): void
    {
        $tally = new RunTally();

        self::assertSame(0, $tally->counts['compiled']);
        self::assertSame(0, $tally->counts['failed']);
        self::assertFalse($tally->hasFailures());
    }

    public function testCountingAnUnknownBucketCreatesIt(): void
    {
        $tally = new RunTally();
        $tally->count('somethingNew');

        self::assertSame(1, $tally->counts['somethingNew']);
    }

    public function testFailuresAreVisibleToTheCaller(): void
    {
        $tally = new RunTally();
        $tally->count('failed');

        self::assertTrue($tally->hasFailures());
    }

    /**
     * The JSON summary only reaches stdout once every environment has been
     * walked, which on a real corpus is hours. Twenty-two taxonomy entries once
     * failed on every one of them and nothing showed it until the run ended.
     */
    public function testProblemsAreAnnouncedAsTheyHappenNotOnlyCollected(): void
    {
        $announced = [];
        $tally = new RunTally();
        $tally->onProblem = static function(string $problem) use (&$announced): void {
            $announced[] = $problem;
        };

        $tally->problem('kuma:COM:nt_page:1: save refused');

        self::assertSame(['kuma:COM:nt_page:1: save refused'], $announced);
        self::assertSame(['kuma:COM:nt_page:1: save refused'], $tally->problems);
    }

    public function testProblemsAreStillCollectedWithNoListener(): void
    {
        $tally = new RunTally();
        $tally->problem('something went wrong');

        self::assertSame(['something went wrong'], $tally->problems);
    }

    public function testDroppedAddressesAreCountedPerFieldAndSite(): void
    {
        $tally = new RunTally();
        $tally->droppedAddress('visitorAddress', 'comEnUs');
        $tally->droppedAddress('visitorAddress', 'comEnUs');
        $tally->droppedAddress('postalAddress', 'comEnUs');

        self::assertSame(
            ['visitorAddress on comEnUs' => 2, 'postalAddress on comEnUs' => 1],
            $tally->droppedAddresses,
        );
    }

    /**
     * `SaveResult` carries five things a save could not do cleanly. The pipeline
     * used to read three of them and the standalone loader a different three, so
     * a `migrate` run never reported an unresolvable media token and `load/entry`
     * never reported a dropped address. One absorb, nothing dropped.
     */
    public function testAbsorbingASaveDropsNothing(): void
    {
        $tally = new RunTally();
        $tally->absorb(new SaveResult(
            sourceUid: 'kuma:COM:nt_page:7',
            entryId: 70,
            created: false,
            deferredRefs: [['field' => 'related', 'site' => 'comEnUs', 'ref' => 'kuma:COM:nt_page:9', 'path' => []]],
            unresolvedAssets: [['field' => 'hero', 'site' => 'comEnUs', 'path' => [], 'asset' => '/uploads/media/a.png']],
            mediaTokenIssues: [['field' => 'body', 'site' => 'comEnUs', 'token' => '{{kuma:media:3}}', 'reason' => 'no such media']],
            droppedAddresses: [['field' => 'visitorAddress', 'site' => 'comDeDe']],
        ), refreshesExisting: true);

        self::assertSame(1, $tally->counts['updated']);
        self::assertSame([['sourceUid' => 'kuma:COM:nt_page:7', 'field' => 'related', 'site' => 'comEnUs', 'ref' => 'kuma:COM:nt_page:9', 'path' => []]], $tally->deferredRefs);
        self::assertSame('/uploads/media/a.png', $tally->unresolvedAssets[0]['asset']);
        self::assertSame('kuma:COM:nt_page:7', $tally->unresolvedAssets[0]['sourceUid']);
        self::assertSame('{{kuma:media:3}}', $tally->mediaTokenIssues[0]['token']);
        self::assertSame(['visitorAddress on comDeDe' => 1], $tally->droppedAddresses);
    }

    public function testANonCreatedEntryIsSkippedUnlessTheSaverRefreshes(): void
    {
        $tally = new RunTally();
        $result = new SaveResult('kuma:COM:nt_page:7', 70, false, []);

        $tally->absorb($result, refreshesExisting: false);
        $tally->absorb($result, refreshesExisting: true);

        self::assertSame(1, $tally->counts['skipped']);
        self::assertSame(1, $tally->counts['updated']);
    }

    public function testUnresolvedAssetsKeepEveryOccurrenceAndOfferTheDistinctReferences(): void
    {
        $tally = new RunTally();
        $hit = ['field' => 'hero', 'site' => 'comEnUs', 'path' => [], 'asset' => '/uploads/media/a.png'];
        $tally->absorb(new SaveResult('kuma:COM:nt_page:1', 1, true, [], [$hit]), false);
        $tally->absorb(new SaveResult('kuma:COM:nt_page:2', 2, true, [], [$hit]), false);

        self::assertCount(2, $tally->unresolvedAssets);
        self::assertSame(['/uploads/media/a.png'], $tally->unresolvedAssetReferences());
    }

    /**
     * The console folds once from its one pipeline; a queue batch is a fresh
     * pipeline and folds after every batch — the sums must be the same.
     */
    public function testCompileReportsFoldAdditivelyAcrossBatches(): void
    {
        $tally = new RunTally();
        $tally->absorbCompileReport(['not-in-mapping' => 2], ['ckeditor' => ['<table> -> dropped' => 1]], 1);
        $tally->absorbCompileReport(['not-in-mapping' => 3, 'offline' => 1], ['ckeditor' => ['<table> -> dropped' => 2]], 2);

        self::assertSame(['not-in-mapping' => 5, 'offline' => 1], $tally->skippedSources);
        self::assertSame(['ckeditor' => ['<table> -> dropped' => 3]], $tally->losses);
        self::assertSame(3, $tally->lossyConversions);
    }

    public function testSettingsCarryTheFlagsAJobCannotReadOffAController(): void
    {
        $settings = new RunSettings(dryRun: true, force: true, limit: 10, entriesOnly: true, only: ['newsPage']);

        self::assertTrue($settings->dryRun);
        self::assertTrue($settings->force);
        self::assertSame(10, $settings->limit);
        self::assertTrue($settings->entriesOnly);
        self::assertSame(['newsPage'], $settings->only);
        self::assertNull($settings->dumpDir);
    }
}
