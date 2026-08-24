<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Source\EntityTableIndex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityTableIndexTest extends TestCase
{
    private function index(): EntityTableIndex
    {
        return EntityTableIndex::fromSource(__DIR__ . '/fixtures');
    }

    #[Test]
    public function it_reads_table_names_from_orm_attributes(): void
    {
        self::assertSame('legacy_user_stories_page_parts', $this->index()->tableFor('UserStories'));
    }

    #[Test]
    public function child_ownership_follows_the_relation_not_the_column_name(): void
    {
        // The join column is named block_link_pp_id, but the relation targets
        // UserStoriesPagePart. A name-based heuristic gets this wrong.
        self::assertSame(
            [['table' => 'legacy_user_story_items', 'fk' => 'block_link_pp_id']],
            $this->index()->childrenOf('UserStories'),
        );

        self::assertSame([], $this->index()->childrenOf('BlockLink'));
    }

    #[Test]
    public function without_a_source_checkout_there_is_no_relation_data_to_use(): void
    {
        self::assertNull(EntityTableIndex::empty()->childrenOf('UserStories'));
        self::assertTrue(EntityTableIndex::empty()->isEmpty());
    }
}
