<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\controllers;

use lameco\kunstmaanmigrator\controllers\MigrationConsoleController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source-level/unit contract for the Phase 12 CP migration console controller.
 *
 * The repository test suite does not bootstrap a Craft Control Panel request,
 * so these tests keep the view-model and queue-action contracts deterministic
 * at the source boundary.
 */
final class MigrationConsoleControllerTest extends TestCase
{
    public function testUtilityVariablesExposeConsoleViewModelKeysAndTabs(): void
    {
        self::assertTrue(class_exists(MigrationConsoleController::class));

        $variables = MigrationConsoleController::utilityVariables();

        foreach ([
            'title',
            'subtitle',
            'tabs',
            'activeTab',
            'readiness',
            'analyzeGates',
            'compileGates',
            'dryRunGates',
            'liveGates',
            'latestRun',
            'runs',
            'reports',
            'mapping',
            'compileSummary',
            'runActions',
            'cliCommands',
            'copy',
        ] as $key) {
            self::assertArrayHasKey($key, $variables);
        }

        self::assertSame(['readiness', 'analyze', 'mapping', 'compile', 'runs', 'reports', 'danger'], array_column($variables['tabs'], 'id'));
        self::assertSame('readiness', $variables['activeTab']);
        self::assertSame('Kunstmaan Migration Console', $variables['title']);
        self::assertStringContainsString('CLI remains the canonical workflow.', $variables['subtitle']);
    }

    public function testUtilityVariablesExposeExactOperatorCopy(): void
    {
        $copy = MigrationConsoleController::utilityVariables()['copy'];
        $flattened = implode("\n", array_map('strval', $copy));

        foreach ([
            'CLI remains the canonical workflow.',
            'Use the CLI when you need full debug output, production safety verification, or a gate cannot be verified from the Control Panel.',
            'This action cannot start because one or more safety gates failed. Review the failed gates below, fix the issue, then try again or use the shown CLI command.',
            'Queue dry run',
            'Queue analyze',
            'Queue compile',
            'Queue verify/report',
            'Queue live migration',
            'Analyze is unavailable because no Anthropic API key is configured. Add an API key in plugin settings or run the CLI with an approved environment.',
            'A successful dry run with the same filters and options is required before live migration can be queued.',
            'A recent compile with no fatal warnings is required before live migration can be queued.',
            'I understand selected warning/unsupported mapping rows may be skipped, dropped, or migrated imperfectly.',
            'No log lines have been written for this run yet. Refresh after the queue worker starts processing.',
            'No artifacts have been recorded for this run yet. Artifacts are written under storage/migration.',
            'No mapping rows found',
            'Run analyze and compile first, then return here to review mapping coverage.',
            'No rows match the current filters. Clear filters to view all mapping rows.',
            'Migration actions are disabled in production. Run this workflow only from a development or staging Craft environment.',
        ] as $expectedCopy) {
            self::assertStringContainsString($expectedCopy, $flattened);
        }
    }

    public function testControllerSourceDeclaresViewModelHelpers(): void
    {
        $source = $this->source();

        foreach ([
            'compileSummary',
            'runActions',
            'cliCommands',
            'readiness',
            'analyze',
            'mapping',
            'compile',
            'runs',
            'reports',
            'danger',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    private function source(): string
    {
        $reflection = new ReflectionClass(MigrationConsoleController::class);
        $source = file_get_contents((string) $reflection->getFileName());
        self::assertIsString($source);

        return $source;
    }
}
