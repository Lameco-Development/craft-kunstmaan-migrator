<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\filter;

use lameco\kunstmaanmigrator\filter\MappingFilterTranslator;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;

final class MappingFilterTranslatorTest extends TestCase
{
    public function testEmptyEntityFiltersReturnUnboundedCraftScope(): void
    {
        $scope = (new MappingFilterTranslator())->translate($this->compiledMapping(), new MigrationFilters());

        self::assertSame([], $scope['sectionHandles']);
        self::assertSame([], $scope['entryTypeHandles']);
        self::assertSame([], $scope['unmappedSourceEntities']);
    }

    public function testTranslatesSourceFqcnFiltersToCraftSectionAndEntryTypeHandles(): void
    {
        $scope = (new MappingFilterTranslator())->translate(
            $this->compiledMapping(),
            new MigrationFilters(entities: ['App\\Entity\\Pages\\ArticlePage']),
        );

        self::assertSame(['articles'], $scope['sectionHandles']);
        self::assertSame(['articlePage'], $scope['entryTypeHandles']);
        self::assertSame([], $scope['unmappedSourceEntities']);
    }

    public function testTranslatesSourceBasenameFiltersDeterministically(): void
    {
        $scope = (new MappingFilterTranslator())->translate(
            $this->compiledMapping(),
            new MigrationFilters(entities: ['EventPage', 'ArticlePage']),
        );

        self::assertSame(['articles', 'events'], $scope['sectionHandles']);
        self::assertSame(['articlePage', 'eventPage'], $scope['entryTypeHandles']);
        self::assertSame([], $scope['unmappedSourceEntities']);
    }

    public function testUnmappedSourceFiltersAreReturnedVisibly(): void
    {
        $scope = (new MappingFilterTranslator())->translate(
            $this->compiledMapping(),
            new MigrationFilters(entities: ['ArticlePage', 'GhostPage', 'App\\Entity\\MissingPage']),
        );

        self::assertSame(['articles'], $scope['sectionHandles']);
        self::assertSame(['articlePage'], $scope['entryTypeHandles']);
        self::assertSame(['App\\Entity\\MissingPage', 'GhostPage'], $scope['unmappedSourceEntities']);
    }

    /**
     * @return array<string, mixed>
     */
    private function compiledMapping(): array
    {
        return [
            'sections' => [
                'articlePage' => ['section' => 'articles', 'entryType' => 'articlePage'],
                'eventPage' => ['section' => 'events', 'entryType' => 'eventPage'],
            ],
            'nodeClasses' => [
                'App\\Entity\\Pages\\ArticlePage' => ['section' => 'articlePage'],
                'App\\Entity\\Pages\\EventPage' => ['section' => 'eventPage'],
            ],
        ];
    }
}
