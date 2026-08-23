<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SourceRefStripTest extends TestCase
{
    private function strip(array $fieldValues): array
    {
        $m = new ReflectionMethod(EntryMigrationService::class, 'stripSourcePartRefs');

        return $m->invoke(new EntryMigrationService(), $fieldValues);
    }

    #[Test]
    public function a_top_level_block_loses_its_migration_tag(): void
    {
        $out = $this->strip([
            'pageBuilder' => [
                ['type' => 'contentBlock', 'fields' => ['_sourcePartRef' => 'COM:t:1', 'content' => 'x']],
            ],
        ]);

        self::assertSame(['content' => 'x'], $out['pageBuilder'][0]['fields']);
    }

    #[Test]
    public function a_tag_nested_inside_a_block_is_stripped_too(): void
    {
        // Craft rejects an unknown custom field, so a tag surviving one level down fails the
        // whole entry — which is exactly what happened before the strip recursed.
        $out = $this->strip([
            'pageBuilder' => [
                [
                    'type' => 'contentBlock',
                    'fields' => [
                        '_sourcePartRef' => 'COM:t:1',
                        'contentColumns' => [
                            ['type' => 'contentColumn', 'fields' => ['_sourcePartRef' => 'COM:c:9', 'content' => 'y']],
                        ],
                    ],
                ],
            ],
        ]);

        $column = $out['pageBuilder'][0]['fields']['contentColumns'][0]['fields'];

        self::assertArrayNotHasKey('_sourcePartRef', $column);
        self::assertSame('y', $column['content']);
    }
}
