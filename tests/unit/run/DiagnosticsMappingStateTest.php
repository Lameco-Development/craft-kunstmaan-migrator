<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\Diagnostics;
use PHPUnit\Framework\TestCase;

/**
 * The control-panel doctor answers the CLI doctor's mapping-state questions —
 * an open conflict or an unreviewed backlog must block from both surfaces, and
 * a todo must never block from either.
 */
final class DiagnosticsMappingStateTest extends TestCase
{
    public function testACleanMappingProducesTwoGreenChecksAndNoTodoRow(): void
    {
        $checks = Diagnostics::mappingStateChecks($this->mapping(<<<'YAML'
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            parts:
              HeaderPagePart:
                table: header_pageparts
                block: header
                map: { heading: title }
                ignore: []
            YAML));

        self::assertSame(['mapping_conflicts', 'mapping_unreviewed'], array_column($checks, 'check'));
        self::assertSame([true, true], array_column($checks, 'ok'));
    }

    public function testAnOpenConflictAndUnreviewedColumnsBlockAndTodosDoNot(): void
    {
        $checks = Diagnostics::mappingStateChecks($this->mapping(<<<'YAML'
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            parts:
              HeaderPagePart:
                table: header_pageparts
                block: header
                map: { heading: title }
                ignore: []
                conflict: { status: open, artifact: says-hero, spec: says-header }
              TextPagePart:
                table: text_pageparts
                block: text
                map: { body: content }
                unreviewed: [subtitle, icon]
                todo: decide where the icon goes
            YAML));

        $byName = array_column($checks, null, 'check');

        self::assertFalse($byName['mapping_conflicts']['ok']);
        self::assertStringContainsString('HeaderPagePart', $byName['mapping_conflicts']['detail']);
        self::assertStringContainsString('conflict.status: decided', $byName['mapping_conflicts']['detail']);

        self::assertFalse($byName['mapping_unreviewed']['ok']);
        self::assertStringContainsString('TextPagePart', $byName['mapping_unreviewed']['detail']);

        self::assertTrue($byName['mapping_todos']['ok'], 'a todo is visible but never blocking — same rule as the CLI');
        self::assertStringContainsString('decide where the icon goes', $byName['mapping_todos']['detail']);
    }

    /**
     * A resolved conflict is a decision, not a problem — the CLI doctor only
     * counts `status: open`, and this side must agree.
     */
    public function testADecidedConflictDoesNotBlock(): void
    {
        $checks = Diagnostics::mappingStateChecks($this->mapping(<<<'YAML'
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            parts:
              HeaderPagePart:
                table: header_pageparts
                block: header
                map: { heading: title }
                ignore: []
                conflict: { status: decided, artifact: says-hero, spec: says-header }
            YAML));

        self::assertTrue(array_column($checks, null, 'check')['mapping_conflicts']['ok']);
    }

    private function mapping(string $yaml): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }
}
