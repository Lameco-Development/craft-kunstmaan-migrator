<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\BlockBuilder;
use Lameco\Kunstmaanmigrator\Compile\EntityIndex;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Source\PartReader;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `m2m()` reads the ids an owning row selects through a ManyToMany join table — a relation
 * Doctrine keeps in two foreign keys, invisible to every column map — and `ref()` turns each
 * into the entry it became. Introspection is what surfaced these: seven pagepart classes and
 * four page types selected content this way, and the mapping read none of it.
 */
final class RelationExpressionTest extends TestCase
{
    private function builder(): BlockBuilder
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE part_node (part_id INTEGER, node_id INTEGER)');
        $pdo->exec('INSERT INTO part_node VALUES (7, 42), (7, 13), (8, 99)');

        // Node 13 exists; 42 does too; 99 belongs to another owner. 500 resolves to nothing.
        $pdo->exec('INSERT INTO part_node VALUES (9, 500)');

        return new BlockBuilder(
            new PartReader($pdo),
            new Transforms(),
            'COM',
            null,
            'caseBlock',
            null,
            new EntityIndex(['node' => 'kuma_nodes'], []),
        );
    }

    #[Test]
    public function an_m2m_selection_becomes_an_ordered_ref_list(): void
    {
        self::assertSame(
            ['commonCases' => [
                ['_ref' => 'kuma:COM:kuma_nodes:13'],
                ['_ref' => 'kuma:COM:kuma_nodes:42'],
            ]],
            $this->builder()->fieldsFrom(
                ['commonCases' => 'm2m(part_node, part_id, node_id) | ref(node)'],
                ['id' => 7],
                'ContentCase',
            ),
        );
    }

    #[Test]
    public function a_row_selecting_nothing_produces_no_field(): void
    {
        self::assertSame(
            [],
            $this->builder()->fieldsFrom(
                ['commonCases' => 'm2m(part_node, part_id, node_id) | ref(node)'],
                ['id' => 999],
                'ContentCase',
            ),
        );
    }

    #[Test]
    public function a_literal_supplies_the_value_the_table_never_had(): void
    {
        // ContentHighlight has no background_color column; its variant is a design fact.
        self::assertSame(
            ['contentMediaVariant' => 'band'],
            $this->builder()->fieldsFrom(
                ['contentMediaVariant' => "'band'"],
                ['id' => 1],
                'ContentHighlight',
            ),
        );
    }
}
