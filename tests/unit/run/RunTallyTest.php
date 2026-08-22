<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\run;

use lameco\kunstmaanmigrator\run\RunSettings;
use lameco\kunstmaanmigrator\run\RunTally;
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
        $tally->onProblem = static function (string $problem) use (&$announced): void {
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

    public function testUnresolvedAssetsKeepEveryOccurrenceSoTheyCanBeCounted(): void
    {
        $tally = new RunTally();
        $tally->unresolvedAsset('/uploads/media/a.png');
        $tally->unresolvedAsset('/uploads/media/a.png');

        self::assertCount(2, $tally->unresolvedAssets);
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
