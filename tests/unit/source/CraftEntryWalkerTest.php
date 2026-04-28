<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\source\CraftEntryWalker;
use lameco\kunstmaanmigrator\source\CraftGraphContract;
use PHPUnit\Framework\TestCase;

final class CraftEntryWalkerTest extends TestCase
{
    public function testRootVersionEntryFieldsAndStableReadOnlyGraph(): void
    {
        $walker = new CraftEntryWalker();
        $walker->targetSchemaSnapshot = $this->schema();

        $first = $walker->walk(['newsPage', 'homePage']);
        $second = $walker->walk(['homePage', 'newsPage']);

        self::assertSame(CraftGraphContract::GRAPH_VERSION, $first[CraftGraphContract::KEY_GRAPH_VERSION]);
        self::assertSame($first[CraftGraphContract::KEY_ROOTS], $second[CraftGraphContract::KEY_ROOTS]);
        self::assertArrayHasKey(CraftGraphContract::craftEntryTypeRef('newsPage'), $first[CraftGraphContract::KEY_ROOTS]);
        self::assertArrayHasKey(
            CraftGraphContract::craftFieldRef('newsPage', 'title'),
            $first[CraftGraphContract::KEY_FIELDS],
        );
        self::assertArrayHasKey(
            CraftGraphContract::craftFieldRef('newsPage', 'caseTeamMembers'),
            $first[CraftGraphContract::KEY_FIELDS],
        );
    }

    public function testMatrixBlockEntriesAssetsConstraintAndOwnershipRegistries(): void
    {
        $walker = new CraftEntryWalker();
        $walker->targetSchemaSnapshot = $this->schema();

        $graph = $walker->walk(['newsPage', 'homePage']);

        self::assertArrayHasKey(
            CraftGraphContract::matrixBlockRef('pageBuilder', 'textContentBlock'),
            $graph[CraftGraphContract::KEY_MATRIX_BLOCK_TYPES],
        );
        self::assertNotEmpty($graph[CraftGraphContract::KEY_MATRIX_USAGES]);
        self::assertNotEmpty($graph[CraftGraphContract::KEY_RELATION_TARGETS]);
        self::assertNotEmpty($graph[CraftGraphContract::KEY_ASSET_VOLUMES]);
        self::assertArrayHasKey(
            CraftGraphContract::craftFieldRef('textContentBlock', 'content'),
            $graph[CraftGraphContract::KEY_FIELDS],
        );
        self::assertNotEmpty(array_filter(
            $graph[CraftGraphContract::KEY_CONSTRAINTS],
            static fn($constraint): bool => is_array($constraint) && ($constraint['type'] ?? '') === 'matrixBlockOwnership',
        ));
    }

    public function testRepeatedMatrixAndEntriesReferencesAreCycleSafeAndDeduplicated(): void
    {
        $walker = new CraftEntryWalker();
        $walker->targetSchemaSnapshot = $this->schema([
            'entryTypes' => [
                'homePage' => [
                    ['handle' => 'pageBuilder', 'type' => 'Matrix', 'allowedBlockTypes' => ['textContentBlock', 'textContentBlock']],
                    ['handle' => 'pageBuilder.content', 'type' => 'PlainText', 'blockTypes' => ['textContentBlock']],
                    ['handle' => 'relatedPages', 'type' => 'Entries', 'sources' => ['entryType:homePage', 'entryType:homePage']],
                ],
            ],
            'matrixFields' => [
                'pageBuilder' => ['textContentBlock', 'textContentBlock'],
            ],
        ]);

        $graph = $walker->walk(['homePage'], 2);

        self::assertCount(1, $graph[CraftGraphContract::KEY_MATRIX_BLOCK_TYPES]);
        self::assertCount(1, $graph[CraftGraphContract::KEY_MATRIX_USAGES]);
        self::assertCount(1, $graph[CraftGraphContract::KEY_RELATION_TARGETS]);
    }

    public function testMissingKnowledgeBaseReturnsVersionedEmptyGraph(): void
    {
        $graph = (new CraftEntryWalker())->walk(['newsPage']);

        self::assertSame(CraftGraphContract::GRAPH_VERSION, $graph[CraftGraphContract::KEY_GRAPH_VERSION]);
        self::assertSame([], $graph[CraftGraphContract::KEY_ROOTS]);
    }

    /** @param array<string, mixed> $overrides */
    private function schema(array $overrides = []): array
    {
        return array_replace_recursive([
            'sections' => [
                'news' => ['newsPage'],
                'home' => ['homePage'],
            ],
            'entryTypes' => [
                'newsPage' => [
                    ['handle' => 'caseTeamMembers', 'type' => 'Entries', 'sources' => ['entryType:teamMember']],
                    ['handle' => 'image', 'type' => 'Assets', 'allowedKinds' => ['image'], 'volumes' => ['images']],
                ],
                'homePage' => [
                    ['handle' => 'pageBuilder', 'type' => 'Matrix', 'allowedBlockTypes' => ['textContentBlock']],
                    ['handle' => 'pageBuilder.content', 'type' => 'PlainText', 'blockTypes' => ['textContentBlock']],
                ],
            ],
            'matrixFields' => [
                'pageBuilder' => ['textContentBlock'],
            ],
            'volumes' => ['images'],
        ], $overrides);
    }
}
