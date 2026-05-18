<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\workflow;

use lameco\kunstmaanmigrator\workflow\MigrateWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MigrateWorkflowTest extends TestCase
{
    public function testWorkflowExposesSharedMigrateRunContract(): void
    {
        self::assertTrue(class_exists(MigrateWorkflow::class));

        $class = new ReflectionClass(MigrateWorkflow::class);
        self::assertTrue($class->hasMethod('run'));
        $source = (string) file_get_contents((string) $class->getFileName());

        foreach ([
            'live',
            'confirm',
            'preloadAssets',
            'force',
            'entities',
            'locales',
            'since',
            'noSeo',
            'noRetour',
            'noRelJoin',
            'includeUnreferencedTaxonomies',
            'limit',
            'onlyId',
            'verbose',
            'batchOffset',
            'batchLimit',
        ] as $key) {
            self::assertStringContainsString($key, $source);
        }

        foreach ([
            'status',
            'mode',
            'filters',
            'artifactPaths',
            'logPath',
            'summary',
            'failure',
            'processed',
            'total',
            'nextBatchOffset',
        ] as $key) {
            self::assertStringContainsString($key, $source);
        }

        self::assertStringContainsString('preflightCompiledMapping', $source);
        self::assertStringNotContainsString('shell_exec', $source);
        self::assertStringNotContainsString('php craft', $source);
    }

    public function testMigrateControllerDelegatesIndexActionToWorkflow(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/console/MigrateController.php');

        self::assertStringContainsString('MigrateWorkflow', $source);
        self::assertStringContainsString('->run(', $source);
        $action = substr($source, (int) strpos($source, 'public function actionIndex(): int'), 1700);
        self::assertStringContainsString('(new MigrateWorkflow())->run(', $action);
        self::assertStringNotContainsString('$plugin = Plugin::getInstance()', $action);
    }

    public function testMigrateWorkflowKeepsCliCanonicalSafetyAndArtifacts(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/workflow/MigrateWorkflow.php');

        self::assertStringContainsString('NeverProductionTrait', $source);
        self::assertStringContainsString('dryRun', $source);
        self::assertStringContainsString('live', $source);
        self::assertStringContainsString('REPORT.md', $source);
        self::assertStringContainsString('defaultLogPath', $source);
        self::assertStringContainsString('MigrationOptions', $source);
        self::assertStringContainsString('MigrationReport', $source);
    }

    public function testMigrateWorkflowGatesSidecarsWhenNarrowed(): void
    {
        // When --entities / --locales / --since / --limit / --only-id is
        // active the four global-scope sidecars (seo, retour, translations,
        // navigation) must skip with a WARN — they rebuild from full source
        // tables and otherwise push duplicate rows into a partial slice.
        // See MigrationFilters::isNarrowed() and the sidecar gate just
        // before the SEO stage in MigrateWorkflow::run().
        $source = (string) file_get_contents(__DIR__ . '/../../../src/workflow/MigrateWorkflow.php');

        self::assertStringContainsString('$filters->isNarrowed()', $source);
        self::assertStringContainsString('$sidecarSkipReason', $source);
        foreach (['seo', 'retour', 'translations', 'navigation'] as $stage) {
            self::assertStringContainsString(
                "WARN {$stage} {\$sidecarSkipReason}",
                $source,
                "Expected MigrateWorkflow to gate the {$stage} stage on the narrowed-filter check",
            );
        }
    }
}
