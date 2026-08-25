<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\craft\CraftUriJobGuard;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Payload\PayloadValidator;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryUriJobGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The veto on Craft's deferred entry-URI jobs, driven through the seam.
 *
 * A run that ends in the URI pass makes those jobs redundant — 5,084 of them
 * sat in the queue after the reference run, every one an element save that
 * queues its descendants. A run that does not end in the pass must leave
 * them alone, or an interrupted or narrowed run is worse off than before.
 */
final class UriJobGuardTest extends TestCase
{
    private function pipeline(InMemoryUriJobGuard $guard): EnvironmentPipeline
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
            $guard,
        );
    }

    public function testARunThatSettlesUrisVetoesEntryUriJobsAndNothingElse(): void
    {
        $guard = new InMemoryUriJobGuard();
        $tally = new RunTally();

        $this->pipeline($guard)->guardUriJobs(new RunSettings(), $tally, static function() use ($guard): void {
            self::assertFalse($guard->push(), 'an entry URI job pushed mid-run is vetoed');
            self::assertFalse($guard->push());
            self::assertTrue($guard->pushSearchIndex(), 'the search index is not the pass\'s to replace');
            self::assertTrue($guard->push(elementType: 'craft\elements\Category'), 'the pass walks entries only');
        });

        self::assertSame(2, $tally->slugJobsVetoed);
        self::assertSame(['arm', 'disarm'], $guard->transitions);
        self::assertFalse($guard->armed);
        self::assertTrue($guard->push(), 'after the run Craft\'s maintenance is back');
    }

    /** @return iterable<string, array{0: RunSettings}> */
    public static function runsThatSkipTheUriPass(): iterable
    {
        yield 'entries only' => [new RunSettings(entriesOnly: true)];
        yield 'dry run' => [new RunSettings(dryRun: true)];
    }

    /**
     * `--entries-only` and `--dry-run` both skip the pass in both callers, so
     * a veto there would leave URIs unsettled for good.
     */
    #[DataProvider('runsThatSkipTheUriPass')]
    public function testARunThatSkipsTheUriPassLeavesCraftsMaintenanceAlone(RunSettings $settings): void
    {
        $guard = new InMemoryUriJobGuard();
        $tally = new RunTally();

        self::assertFalse($settings->settlesUris());

        $this->pipeline($guard)->guardUriJobs($settings, $tally, static function() use ($guard): void {
            self::assertTrue($guard->push());
        });

        self::assertSame(0, $tally->slugJobsVetoed);
        self::assertSame([], $guard->transitions);
    }

    /**
     * The handler sits on a process-wide singleton. Left armed by an
     * exception, it would veto whatever the process did next.
     */
    public function testAnExceptionInsideTheRunStillDisarms(): void
    {
        $guard = new InMemoryUriJobGuard();
        $tally = new RunTally();
        $pipeline = $this->pipeline($guard);

        try {
            $pipeline->guardUriJobs(new RunSettings(), $tally, static function() use ($guard): void {
                $guard->push();

                throw new RuntimeException('a unit died');
            });
            self::fail('the exception propagates');
        } catch (RuntimeException) {
        }

        self::assertFalse($guard->armed);
        self::assertSame(1, $tally->slugJobsVetoed);
    }

    /**
     * The batched job arms when a batch loads and disarms after its last
     * item, with Craft's loop in between; arming twice in one process is one
     * handler, not two.
     */
    public function testTheTwoHalvesPairUpForTheBatchedJob(): void
    {
        $guard = new InMemoryUriJobGuard();
        $tally = new RunTally();
        $pipeline = $this->pipeline($guard);

        $pipeline->armUriJobGuard(new RunSettings());
        $pipeline->armUriJobGuard(new RunSettings());
        $guard->push();
        $pipeline->disarmUriJobGuard($tally);
        $pipeline->disarmUriJobGuard($tally);

        self::assertSame(['arm', 'disarm'], $guard->transitions);
        self::assertSame(1, $tally->slugJobsVetoed);
    }

    /**
     * Both callers arm through the pipeline and both gate on the chain
     * reaching the pass — the batch on `chainCorpusPasses`, the console on
     * the same settings the pass is run under.
     */
    public function testBothCallersArmThroughThePipeline(): void
    {
        $root = dirname(__DIR__, 3);
        $batch = (string) file_get_contents($root . '/src/queue/MigrateEnvironmentJob.php');
        $adapters = (string) file_get_contents($root . '/src/queue/RunAdaptersJob.php');
        $console = (string) file_get_contents($root . '/src/console/MigrateController.php');
        $pipeline = (string) file_get_contents($root . '/src/run/EnvironmentPipeline.php');

        self::assertStringContainsString('armUriJobGuard($this->settings)', $batch);
        self::assertStringContainsString('disarmUriJobGuard($this->tally)', $batch);
        self::assertStringContainsString("'slugJobsVetoed' => \$this->slugJobsVetoed", $batch);
        self::assertStringContainsString('guardUriJobs($settings, $tally', $adapters);
        self::assertStringContainsString('$this->guardUriJobs($settings, $tally', $pipeline);
        self::assertStringContainsString("'slugJobsVetoed' => \$tally->slugJobsVetoed", $console);
        self::assertStringContainsString("'slugJobsReleased' => \$slugJobsReleased", $console);
        self::assertStringNotContainsString('UriJobGuard', (string) file_get_contents($root . '/src/console/LoadController.php'));
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
