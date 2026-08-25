<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\run\MaintenanceGuard;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryUriJobGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The hold as the corpus-wide passes take it: on its own, with no pipeline.
 *
 * The fixup and finalize passes run after every pipeline has finished, and
 * the fixup job carries no mapping to build one from. What they need is the
 * guard, so the guard is a value they can build — and what it does for them
 * has to be exactly what it does for the entry loop.
 */
final class CorpusPassGuardTest extends TestCase
{
    private InMemoryUriJobGuard $uriJobs;

    private InMemoryElementWriter $writer;

    protected function setUp(): void
    {
        $this->uriJobs = new InMemoryUriJobGuard();
        $this->writer = new InMemoryElementWriter();
    }

    private function guard(): MaintenanceGuard
    {
        return new MaintenanceGuard($this->uriJobs, $this->writer);
    }

    private function entry(int $id): Entry
    {
        $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
        $entry->id = $id;

        return $entry;
    }

    /**
     * A patch save during fixup and a rewrite save during finalize carry the
     * same deferral the entry loop's saves do, and their entry-URI jobs are
     * vetoed the same way; the counts land on the same tally fields.
     */
    public function testAPassUnderTheHoldSavesUnindexedAndPushesNoUriJobs(): void
    {
        $uriJobs = $this->uriJobs;
        $writer = $this->writer;
        $tally = new RunTally();
        $tally->slugJobsVetoed = 1_935;
        $tally->searchIndexDeferred = 13_348;

        $this->guard()->guard(new RunSettings(), $tally, function() use ($uriJobs, $writer): void {
            self::assertTrue($writer->searchIndexDeferred);
            $writer->save($this->entry(1));
            $writer->save($this->entry(2));
            self::assertFalse($uriJobs->push(), 'a patched parent pushes no entry URI job');
        });

        $writer->save($this->entry(3));

        self::assertSame([false, false, true], array_column($writer->saved, 'updateSearchIndex'));
        self::assertSame(['arm', 'disarm'], $this->uriJobs->transitions);
        self::assertSame(1_936, $tally->slugJobsVetoed, 'folded onto what the entry loop vetoed');
        self::assertSame(13_350, $tally->searchIndexDeferred, 'folded onto what the entry loop deferred');
    }

    /** @return iterable<string, array{0: RunSettings}> */
    public static function runsThatSkipTheClosingPasses(): iterable
    {
        yield 'entries only' => [new RunSettings(entriesOnly: true)];
        yield 'dry run' => [new RunSettings(dryRun: true)];
    }

    #[DataProvider('runsThatSkipTheClosingPasses')]
    public function testARunThatSkipsTheClosingPassesLeavesCraftsMaintenanceAlone(RunSettings $settings): void
    {
        $writer = $this->writer;
        $tally = new RunTally();

        $this->guard()->guard($settings, $tally, function() use ($writer): void {
            self::assertFalse($writer->searchIndexDeferred);
            $writer->save($this->entry(1));
        });

        self::assertSame([], $this->uriJobs->transitions);
        self::assertTrue($writer->saved[0]['updateSearchIndex']);
        self::assertSame(0, $tally->slugJobsVetoed);
        self::assertSame(0, $tally->searchIndexDeferred);
    }

    public function testAPassThatThrowsStillDisarms(): void
    {
        $writer = $this->writer;
        $tally = new RunTally();

        try {
            $this->guard()->guard(new RunSettings(), $tally, function() use ($writer): void {
                $writer->save($this->entry(1));

                throw new RuntimeException('a patch died');
            });
            self::fail('the exception propagates');
        } catch (RuntimeException) {
        }

        self::assertFalse($this->uriJobs->armed);
        self::assertFalse($writer->searchIndexDeferred);
        self::assertSame(1, $tally->searchIndexDeferred);
    }

    /**
     * The production pair, and only the production pair: the guard the jobs
     * build must veto on the real queue and defer on the real writer.
     */
    public function testTheBuiltGuardIsTheProductionPair(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(MaintenanceGuard::class))->getFileName());

        self::assertStringContainsString('new self(new CraftUriJobGuard(), new CraftElementWriter())', $source);
    }
}
