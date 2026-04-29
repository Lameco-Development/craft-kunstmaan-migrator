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
