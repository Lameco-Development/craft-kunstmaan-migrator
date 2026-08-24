<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\run;

use PHPUnit\Framework\TestCase;

/**
 * The run form's switches, and what a queued run actually covers.
 */
final class RunFormTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    /**
     * A Craft lightswitch is a `<button role="switch">`. Reading `.checked` off
     * it yields undefined, so Dry run and Re-save posted as 0 whatever the
     * operator set — and a migration cannot be repaired by re-running it, so
     * the wrong answer here is expensive.
     */
    public function testTheRunFormReadsLightswitchesAsSwitchesNotCheckboxes(): void
    {
        $template = $this->source('src/templates/_run-panel.twig');

        self::assertStringNotContainsString('.checked', $template);
        self::assertStringContainsString("getAttribute('aria-checked')", $template);
    }

    /**
     * An inline `migrate` ends with fixup and finalize. A queued "full" must
     * reach them too — but since #48 the ordering is structural, not FIFO:
     * both push sites start ONE chain (first environment, chainCorpusPasses),
     * and RunAdaptersJob is the only place the corpus-wide passes are pushed —
     * after the last environment's adapters, never before the entries exist.
     */
    public function testAQueuedFullRunChainsTheTwoCorpusWidePasses(): void
    {
        foreach (['src/controllers/MigrationController.php', 'src/console/MigrateController.php'] as $file) {
            $source = $this->source($file);

            self::assertStringContainsString('remainingEnvironments', $source, $file);
            self::assertStringContainsString('chainCorpusPasses', $source, $file);
            self::assertStringContainsString('mappingHash', $source, $file);
        }

        // The console has no standalone pass commands; only the chain reaches
        // the corpus passes there. (The CP keeps its manual fixup/finalize
        // buttons — an operator escape hatch, not part of a full run.)
        self::assertStringNotContainsString(
            'new ResolveDeferredRefsJob()',
            $this->source('src/console/MigrateController.php'),
        );

        $chain = $this->source('src/queue/RunAdaptersJob.php');
        self::assertStringContainsString('new ResolveDeferredRefsJob()', $chain);
        self::assertStringContainsString('new FinalizeJob(', $chain);
        self::assertStringContainsString('new MigrateEnvironmentJob(', $chain);
    }
}
