<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\templates;

use PHPUnit\Framework\TestCase;

final class ConsoleTemplateContractTest extends TestCase
{
    public function testShellTabsReadinessAnalyzeAndCompileContract(): void
    {
        $source = $this->consoleSource([
            'index.twig',
            '_tabs.twig',
            '_readiness.twig',
            '_analyze.twig',
            '_compile.twig',
        ]);

        foreach ([
            'Kunstmaan Migration Console',
            'Review readiness, mapping coverage, queued runs, logs, and artifacts for the Kunstmaan → Craft migration. CLI remains the canonical workflow.',
            'Readiness',
            'Analyze',
            'Mapping',
            'Compile',
            'Runs',
            'Reports',
            'Danger Zone',
            'Environment',
            'Connectivity',
            'Mapping & Compile',
            'Queue',
            'Latest run',
            'Passed',
            'Warning',
            'Blocked',
            'Unknown',
            'storage/migration',
            'latest compile',
            'fatal warnings',
            'equivalent CLI',
            'queue-analyze',
            'queue-compile',
            'csrfInput()',
            'actionInput',
            'filters',
            'I understand analyze may send schema and mapping context to Anthropic.',
            'Analyze is unavailable because no Anthropic API key is configured. Add an API key in plugin settings or run the CLI with an approved environment.',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testMappingRunsReportsAndRunDetailContract(): void
    {
        $source = $this->consoleSource([
            '_mapping.twig',
            '_runs.twig',
            '_run-detail.twig',
            '_reports.twig',
        ]);

        foreach ([
            'No mapping rows found',
            'Run analyze and compile first, then return here to review mapping coverage.',
            'No rows match the current filters. Clear filters to view all mapping rows.',
            'Run ID',
            'Stage',
            'Mode',
            'Status',
            'Filters',
            'Initiated by',
            'Queue job IDs',
            'Progress',
            'Started',
            'Finished',
            'Artifacts',
            'Actions',
            'View details',
            'View log',
            'View artifacts',
            'Copy CLI command',
            'Retry safe stage',
            'No migration runs yet',
            'Queue a dry run after readiness checks pass, or run the equivalent CLI command from your terminal.',
            'Gate snapshot',
            'Filters/options',
            'Initiating admin',
            'No log lines have been written for this run yet. Refresh after the queue worker starts processing.',
            'No artifacts have been recorded for this run yet. Artifacts are written under storage/migration.',
            '<details',
            'REPORT.md',
            'VERIFY-',
            'PAGE-ROOTED-COVERAGE.md',
            'MAPPING-AUDIT.md',
            'kunstmaan-schema.json',
            'craft-schema.json',
            'Queue verify/report',
            'queue-verify',
            'csrfInput()',
            'actionInput',
            'Progress will appear in the current run record',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    /**
     * @param list<string> $files
     */
    private function consoleSource(array $files): string
    {
        $root = dirname(__DIR__, 3) . '/templates/_console';
        $source = '';
        foreach ($files as $file) {
            $path = $root . '/' . $file;
            self::assertFileExists($path);
            $source .= "\n" . file_get_contents($path);
        }

        return $source;
    }
}
