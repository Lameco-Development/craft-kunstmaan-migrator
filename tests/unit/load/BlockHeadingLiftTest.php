<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `heading` used to be lifted onto the block's native title unconditionally. On a target
 * whose block types declare a real `heading` field — which is most of them — that moved
 * editorial copy off the field and onto a title the entry type does not have, and Craft
 * dropped it.
 */
final class BlockHeadingLiftTest extends TestCase
{
    /** @param list<string> $typesWithHeading */
    private function strip(array $fieldValues, array $typesWithHeading): array
    {
        $service = new EntryMigrationService();
        $service->setEntryTypeFieldProbe(
            static fn(string $entryType, string $field): bool =>
                $field === 'heading' && in_array($entryType, $typesWithHeading, true)
        );

        $m = new ReflectionMethod(EntryMigrationService::class, 'stripSourcePartRefs');

        return $m->invoke($service, $fieldValues);
    }

    #[Test]
    public function a_block_type_that_has_a_heading_field_keeps_its_heading(): void
    {
        $out = $this->strip([
            'pageBuilder' => [
                ['type' => 'mediaBlock', 'fields' => ['heading' => 'Watch the interview', 'titleLevel' => 'h3']],
            ],
        ], ['mediaBlock']);

        $block = $out['pageBuilder'][0];

        self::assertSame('Watch the interview', $block['fields']['heading']);
        self::assertSame('h3', $block['fields']['titleLevel']);
    }

    #[Test]
    public function a_block_type_without_a_heading_field_still_lifts_it_to_the_title(): void
    {
        $out = $this->strip([
            'pageBuilder' => [
                ['type' => 'newsGridBlock', 'fields' => ['heading' => 'Latest news']],
            ],
        ], []);

        $block = $out['pageBuilder'][0];

        self::assertArrayNotHasKey('heading', $block['fields']);
        self::assertSame('Latest news', $block['title']);
    }

    #[Test]
    public function a_heading_nested_one_level_down_is_kept_too(): void
    {
        $out = $this->strip([
            'pageBuilder' => [
                [
                    'type' => 'contentBlock',
                    'fields' => [
                        'contentColumns' => [
                            ['type' => 'contentColumn', 'fields' => ['heading' => 'In het kort']],
                        ],
                    ],
                ],
            ],
        ], ['contentColumn']);

        $column = $out['pageBuilder'][0]['fields']['contentColumns'][0];

        self::assertSame('In het kort', $column['fields']['heading']);
    }

    #[Test]
    public function a_native_title_is_still_lifted_regardless(): void
    {
        $out = $this->strip([
            'pageBuilder' => [
                ['type' => 'mediaBlock', 'fields' => ['title' => 'Block title', 'heading' => 'Kept']],
            ],
        ], ['mediaBlock']);

        $block = $out['pageBuilder'][0];

        self::assertSame('Block title', $block['title']);
        self::assertSame('Kept', $block['fields']['heading']);
        self::assertArrayNotHasKey('title', $block['fields']);
    }
}
