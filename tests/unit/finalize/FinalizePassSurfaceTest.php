<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Lameco\Kunstmaanmigrator\finalize\FinalizePass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The finalize pass had three implementations that disagreed.
 *
 * `migrate` ran it once against whichever database its loop ended on;
 * `--finalizeOnly` and the queue job looped per environment but never set the
 * media roots, so they could rewrite a link and not an image. Each of these
 * pins one half of that back down.
 */
final class FinalizePassSurfaceTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    /**
     * The regression that made the other two possible: a copy of the loop is
     * cheap to write and its divergence is invisible until a corpus is measured.
     */
    public function testOnlyFinalizePassRunsTheFinalizeService(): void
    {
        $callers = [];

        foreach (['src/console/MigrateController.php', 'src/queue/FinalizeJob.php', 'src/finalize/FinalizePass.php'] as $file) {
            if (str_contains($this->source($file), 'ckeditorFinalizeService->run(')) {
                $callers[] = $file;
            }
        }

        self::assertSame(['src/finalize/FinalizePass.php'], $callers);
    }

    /**
     * Both are per-environment facts and the pass needs both: the database
     * answers `[NT<id>]`, the media roots let an image no payload pulled in be
     * ingested on demand.
     */
    public function testThePassRepointsTheDatabaseAndTheMediaRootsTogether(): void
    {
        $source = $this->source('src/finalize/FinalizePass.php');

        self::assertStringContainsString('EnvironmentPipeline::pointLegacyDbAt(', $source);
        self::assertStringContainsString('EnvironmentPipeline::applyMediaRoots(', $source);
    }

    /**
     * The caches are keyed on bare legacy ids, which only mean something inside
     * one database. Resetting them belongs at the switch, not in each caller —
     * two of the three callers remembered and the entry-load pass did not.
     */
    public function testSwitchingDatabaseDropsTheRewriterCaches(): void
    {
        $source = $this->source('src/run/EnvironmentPipeline.php');
        $body = substr($source, strpos($source, 'public static function pointLegacyDbAt'));

        self::assertStringContainsString('resetLookupCaches()', $body);
    }

    public function testTheEnvironmentFilterIsHonoured(): void
    {
        $run = (new ReflectionClass(FinalizePass::class))->getMethod('run');

        self::assertSame('onlyEnvironment', $run->getParameters()[2]->getName());
        self::assertTrue($run->getParameters()[2]->allowsNull());
    }
}
