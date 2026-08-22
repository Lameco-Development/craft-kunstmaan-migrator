<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\utilities;

use PHPUnit\Framework\TestCase;

/**
 * The run form's switches, and what a queued run actually covers.
 */
final class UtilityRunFormTest extends TestCase
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
        $template = $this->source('src/templates/_utility.twig');

        self::assertStringNotContainsString('.checked', $template);
        self::assertStringContainsString("getAttribute('aria-checked')", $template);
    }

    /**
     * An inline `migrate` ends with fixup and finalize. A queued "full" that
     * stopped after the environments left every deferred reference dangling
     * with nothing saying so.
     */
    public function testAQueuedFullRunChainsTheTwoCorpusWidePasses(): void
    {
        foreach (['src/controllers/MigrationController.php', 'src/console/MigrateController.php'] as $file) {
            $source = $this->source($file);

            self::assertStringContainsString('new ResolveDeferredRefsJob()', $source, $file);
            self::assertStringContainsString('new FinalizeJob(', $source, $file);
        }
    }
}
