<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\craft\CraftUriJobGuard;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Payload\PayloadValidator;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryUriJobGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The hold on Craft's per-save maintenance, driven through the seams.
 *
 * Two things Craft does on every save that a run ending in the closing
 * passes does once instead: it queues `UpdateElementSlugsAndUris` for a
 * Structure entry's descendants — 5,084 of them sat in the queue after the
 * reference run — and it extracts search keywords, inline in a console
 * request, for every owner and every block. A run that does not end in the
 * passes must leave both alone, or an interrupted or narrowed run is worse
 * off than before.
 */
final class MaintenanceGuardTest extends TestCase
{
    private InMemoryUriJobGuard $guard;

    private InMemoryElementWriter $writer;

    protected function setUp(): void
    {
        $this->guard = new InMemoryUriJobGuard();
        $this->writer = new InMemoryElementWriter();
    }

    private function pipeline(): EnvironmentPipeline
    {
        $transforms = new Transforms([]);
        $gateway = new class() implements SchemaGateway {
            public function sectionByHandle(string $handle): ?array
            {
                return null;
            }

            public function entryTypeByHandle(string $handle): ?array
            {
                return null;
            }

            public function siteByHandle(string $handle): ?array
            {
                return null;
            }

            public function primarySite(): array
            {
                return ['id' => 1, 'handle' => 'default'];
            }

            public function fieldHandlesFor(string $entryTypeHandle): array
            {
                return [];
            }

            public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
            {
                return [];
            }

            public function fieldSlotsFor(string $entryTypeHandle): array
            {
                return [];
            }
        };

        return new EnvironmentPipeline(
            new PayloadValidator($gateway),
            null,
            new Compiler(Mapping::fromArray([]), $transforms),
            $transforms,
            $this->guard,
            $this->writer,
        );
    }

    private function entry(int $id): Entry
    {
        $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
        $entry->id = $id;

        return $entry;
    }

    public function testARunThatSettlesUrisVetoesEntryUriJobsAndNothingElse(): void
    {
        $guard = $this->guard;
        $tally = new RunTally();

        $this->pipeline()->guardMaintenance(new RunSettings(), $tally, static function() use ($guard): void {
            self::assertFalse($guard->push(), 'an entry URI job pushed mid-run is vetoed');
            self::assertFalse($guard->push());
            self::assertTrue($guard->pushSearchIndex(), 'a search job is deferred at the save, not vetoed at the queue');
            self::assertTrue($guard->push(elementType: 'craft\elements\Category'), 'the pass walks entries only');
        });

        self::assertSame(2, $tally->slugJobsVetoed);
        self::assertSame(['arm', 'disarm'], $guard->transitions);
        self::assertFalse($guard->armed);
        self::assertTrue($guard->push(), 'after the run Craft\'s maintenance is back');
    }

    /**
     * Every save made inside the run tells Craft not to index; the first
     * save after it indexes again. The count of unindexed saves is what the
     * operator compares against what the index stage queues.
     */
    public function testEverySaveInsideARunThatSettlesUrisSkipsTheSearchIndex(): void
    {
        $writer = $this->writer;
        $tally = new RunTally();

        $this->pipeline()->guardMaintenance(new RunSettings(), $tally, function() use ($writer): void {
            self::assertTrue($writer->searchIndexDeferred);
            $writer->save($this->entry(1));
            $writer->save($this->entry(2));
            $writer->save($this->entry(3));
        });

        $writer->save($this->entry(4));

        self::assertSame(
            [false, false, false, true],
            array_column($writer->saved, 'updateSearchIndex'),
            'the three saves inside the run carry updateSearchIndex=false; the one after carries true',
        );
        self::assertFalse($writer->searchIndexDeferred);
        self::assertSame(3, $tally->searchIndexDeferred);
    }

    /** @return iterable<string, array{0: RunSettings}> */
    public static function runsThatSkipTheClosingPasses(): iterable
    {
        yield 'entries only' => [new RunSettings(entriesOnly: true)];
        yield 'dry run' => [new RunSettings(dryRun: true)];
    }

    /**
     * `--entries-only` and `--dry-run` both skip the closing passes in both
     * callers, so a hold there would leave URIs unsettled and content
     * unindexed for good.
     */
    #[DataProvider('runsThatSkipTheClosingPasses')]
    public function testARunThatSkipsTheClosingPassesLeavesCraftsMaintenanceAlone(RunSettings $settings): void
    {
        $guard = $this->guard;
        $writer = $this->writer;
        $tally = new RunTally();

        self::assertFalse($settings->settlesUris());

        $this->pipeline()->guardMaintenance($settings, $tally, function() use ($guard, $writer): void {
            self::assertTrue($guard->push());
            self::assertFalse($writer->searchIndexDeferred);
            $writer->save($this->entry(1));
        });

        self::assertSame(0, $tally->slugJobsVetoed);
        self::assertSame(0, $tally->searchIndexDeferred);
        self::assertSame([], $guard->transitions);
        self::assertTrue($writer->saved[0]['updateSearchIndex'], 'Craft indexes on save, as it always did');
    }

    /**
     * The handler sits on a process-wide singleton and the deferral is
     * process-wide too. Left armed by an exception, they would veto and
     * un-index whatever the process did next.
     */
    public function testAnExceptionInsideTheRunStillDisarms(): void
    {
        $guard = $this->guard;
        $writer = $this->writer;
        $tally = new RunTally();
        $pipeline = $this->pipeline();

        try {
            $pipeline->guardMaintenance(new RunSettings(), $tally, function() use ($guard, $writer): void {
                $guard->push();
                $writer->save($this->entry(1));

                throw new RuntimeException('a unit died');
            });
            self::fail('the exception propagates');
        } catch (RuntimeException) {
        }

        self::assertFalse($guard->armed);
        self::assertFalse($writer->searchIndexDeferred);
        self::assertSame(1, $tally->slugJobsVetoed);
        self::assertSame(1, $tally->searchIndexDeferred);
    }

    /**
     * The batched job arms when a batch loads and disarms after its last
     * item, with Craft's loop in between; arming twice in one process is one
     * handler and one deferral, not two.
     */
    public function testTheTwoHalvesPairUpForTheBatchedJob(): void
    {
        $tally = new RunTally();
        $pipeline = $this->pipeline();

        $pipeline->armMaintenanceGuard(new RunSettings());
        $pipeline->armMaintenanceGuard(new RunSettings());
        $this->guard->push();
        $this->writer->save($this->entry(1));
        $pipeline->disarmMaintenanceGuard($tally);
        $pipeline->disarmMaintenanceGuard($tally);

        self::assertSame(['arm', 'disarm'], $this->guard->transitions);
        self::assertSame(1, $tally->slugJobsVetoed);
        self::assertSame(1, $tally->searchIndexDeferred);
        self::assertFalse($this->writer->saved[0]['updateSearchIndex']);
    }

    /**
     * Both callers arm through the pipeline and both gate on the chain
     * reaching the closing passes — the batch on `chainCorpusPasses`, the
     * console on the same settings the passes are run under — and both end
     * in the index stage, after the URI pass.
     */
    public function testBothCallersArmThroughThePipelineAndEndInTheIndexStage(): void
    {
        $root = dirname(__DIR__, 3);
        $batch = (string) file_get_contents($root . '/src/queue/MigrateEnvironmentJob.php');
        $adapters = (string) file_get_contents($root . '/src/queue/RunAdaptersJob.php');
        $console = (string) file_get_contents($root . '/src/console/MigrateController.php');
        $pipeline = (string) file_get_contents($root . '/src/run/EnvironmentPipeline.php');

        self::assertStringContainsString('armMaintenanceGuard($this->settings)', $batch);
        self::assertStringContainsString('disarmMaintenanceGuard($this->tally)', $batch);
        self::assertStringContainsString("'slugJobsVetoed' => \$this->slugJobsVetoed", $batch);
        self::assertStringContainsString("'searchIndexDeferred' => \$this->searchIndexDeferred", $batch);
        self::assertStringContainsString('guardMaintenance($settings, $tally', $adapters);
        self::assertStringContainsString('new IndexForSearchJob(', $adapters);
        self::assertLessThan(
            strpos($adapters, 'new IndexForSearchJob('),
            strpos($adapters, 'new RecomputeStructureUrisJob('),
            'the index stage runs after the URI pass',
        );
        self::assertStringContainsString('$this->guardMaintenance($settings, $tally', $pipeline);
        self::assertStringContainsString("'slugJobsVetoed' => \$tally->slugJobsVetoed", $console);
        self::assertStringContainsString("'slugJobsReleased' => \$slugJobsReleased", $console);
        self::assertStringContainsString("'searchIndexDeferred' => \$tally->searchIndexDeferred", $console);
        self::assertStringContainsString("'searchIndexQueued' =>", $console);
        self::assertLessThan(
            strpos($console, 'new SearchIndexPass('),
            strpos($console, 'new StructureUriPass('),
            'the index stage runs after the URI pass',
        );

        $load = (string) file_get_contents($root . '/src/console/LoadController.php');
        self::assertStringNotContainsString('UriJobGuard', $load);
        self::assertStringNotContainsString('MaintenanceGuard', $load);
        self::assertStringNotContainsString('deferSearchIndexing', $load);
    }

    /**
     * The corpus-wide passes save entries too and run before the URI pass
     * and the index stage just the same. Unguarded, the two grew
     * `searchindex` by 23,973 rows on the reference corpus after the entry
     * loop had deferred 13,348 saves — so both callers hold them the same
     * way, and the two jobs gate on the chain the way the adapter job does:
     * the run screen's stand-alone buttons queue them with nothing after.
     */
    public function testBothCallersHoldTheCorpusPassesUnderTheSameGuard(): void
    {
        $root = dirname(__DIR__, 3);
        $console = (string) file_get_contents($root . '/src/console/MigrateController.php');
        $adapters = (string) file_get_contents($root . '/src/queue/RunAdaptersJob.php');

        $hold = (int) strpos($console, '$pipeline->guardMaintenance($settings, $tally');
        self::assertGreaterThan(0, $hold);
        self::assertLessThan((int) strpos($console, '$this->fixup('), $hold, 'the fixup pass runs under the hold');
        self::assertLessThan((int) strpos($console, '$this->finalize('), $hold, 'the finalize pass runs under the hold');
        self::assertLessThan((int) strpos($console, 'new StructureUriPass('), $hold, 'the URI pass consumes the hold, it is not under it');

        foreach (['ResolveDeferredRefsJob', 'FinalizeJob'] as $job) {
            $source = (string) file_get_contents(sprintf('%s/src/queue/%s.php', $root, $job));

            self::assertStringContainsString('if ($this->chainCorpusPasses) {', $source, $job);
            self::assertStringContainsString('MaintenanceGuard::build()->guard(', $source, $job);
            self::assertStringContainsString("\$extra['slugJobsVetoed'] = \$tally->slugJobsVetoed", $source, $job);
            self::assertStringContainsString("\$extra['searchIndexDeferred'] = \$tally->searchIndexDeferred", $source, $job);
            self::assertMatchesRegularExpression(
                sprintf("/new %s\\(\\[[^\\]]*'chainCorpusPasses' => true/", $job),
                $adapters,
                sprintf('the chain tells %s the closing passes follow', $job),
            );
        }

        $panel = (string) file_get_contents($root . '/src/controllers/MigrationController.php');
        self::assertStringContainsString('new ResolveDeferredRefsJob()', $panel, 'the stand-alone fixup button does not hold');
        self::assertStringNotContainsString("new FinalizeJob(['mappingPath' => \$path, 'dryRun' => \$dryRun, 'chainCorpusPasses'", $panel);
    }

    /**
     * The production adapter is unreachable here, so what it must never do
     * is pinned in its source: only the one job class, only for entries,
     * and only while waiting — a job a worker holds is not ours to drop.
     */
    public function testTheProductionAdapterTouchesOnlyWaitingEntryUriJobs(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(CraftUriJobGuard::class))->getFileName());

        self::assertStringContainsString('instanceof UpdateElementSlugsAndUris', $source);
        self::assertStringContainsString('elementType === Entry::class', $source);
        self::assertStringContainsString('Queue::STATUS_WAITING', $source);
        self::assertStringContainsString('Queue::EVENT_BEFORE_PUSH', $source);
        self::assertStringNotContainsString('UpdateSearchIndex', $source);
        self::assertStringNotContainsString('releaseAll', $source);
        self::assertStringNotContainsString('Db::delete', $source);
    }
}
