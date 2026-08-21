<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\BlockBuilder;
use Lameco\KumaCompile\Compile\EntityIndex;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\PartReader;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityLaneTest extends TestCase
{
    private const ENTITIES = [
        'CaseCategory' => ['table' => 'case_categories', 'dedupe' => true],
        'BlogCategory' => ['table' => 'blog_categories', 'dedupe' => false],
    ];

    private function builderWithNodes(array $nodeOfTranslation): BlockBuilder
    {
        return new BlockBuilder(
            new PartReader(new PDO('sqlite::memory:')),
            new Transforms(),
            'COM',
            null,
            null,
            null,
            new EntityIndex(self::ENTITIES, $nodeOfTranslation),
        );
    }

    private function builder(string $environment): BlockBuilder
    {
        return new BlockBuilder(
            new PartReader(new PDO('sqlite::memory:')),
            new Transforms(),
            $environment,
            null,
            null,
            null,
            new EntityIndex(self::ENTITIES),
        );
    }

    #[Test]
    public function a_deduplicated_entity_gets_one_uid_whichever_environment_reads_it(): void
    {
        $index = new EntityIndex(self::ENTITIES);

        self::assertSame('kuma:shared:case_categories:3', $index->uidFor('CaseCategory', 3, 'COM'));
        self::assertSame('kuma:shared:case_categories:3', $index->uidFor('CaseCategory', 3, 'DE'));
    }

    #[Test]
    public function an_entity_that_is_not_deduplicated_stays_per_environment(): void
    {
        $index = new EntityIndex(self::ENTITIES);

        // Ids 17/18/20/21 hold unrelated names per database, so one uid would merge
        // unrelated categories into a single entry.
        self::assertSame('kuma:COM:blog_categories:17', $index->uidFor('BlogCategory', 17, 'COM'));
        self::assertSame('kuma:DE:blog_categories:17', $index->uidFor('BlogCategory', 17, 'DE'));
    }

    #[Test]
    public function the_node_tree_is_reachable_without_being_declared(): void
    {
        $index = new EntityIndex();

        self::assertTrue($index->has('node'));
        self::assertSame('kuma:COM:kuma_nodes:912', $index->uidFor('node', 912, 'COM'));
    }

    #[Test]
    public function an_empty_foreign_key_produces_no_relation(): void
    {
        $index = new EntityIndex(self::ENTITIES);

        self::assertNull($index->uidFor('CaseCategory', null, 'COM'));
        self::assertNull($index->uidFor('CaseCategory', '', 'COM'));
    }

    #[Test]
    public function a_ref_compiles_to_a_list_because_a_relation_field_takes_one(): void
    {
        // A bare `['_ref' => …]` saves without complaint and relates nothing: the loader
        // replaces the node in place, so the containing list has to come from here.
        self::assertSame(
            ['caseCategory' => [['_ref' => 'kuma:shared:case_categories:7']]],
            $this->builder('COM')->fieldsFrom(
                ['caseCategory' => 'category_id | ref(CaseCategory)'],
                ['category_id' => 7],
                'CasePage',
            ),
        );
    }

    #[Test]
    public function a_ref_to_an_undeclared_entity_is_rejected_by_the_mapping_not_silently_dropped(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            entities:
              CaseCategory:
                table: case_categories
                section: caseCategories
                entryType: caseCategory
                title: name
                dedupe: true
                ignore: []
            pages:
              CasePage:
                entryType: casePage
                map: { caseCategory: category_id | ref(CaseCatagory) }
            YAML);

        self::assertCount(1, $errors);
        self::assertStringContainsString('`ref(CaseCatagory)` names no entity', $errors[0]);
    }

    #[Test]
    public function an_entity_that_does_not_say_whether_it_deduplicates_is_rejected(): void
    {
        self::assertContains(
            'entity `CaseCategory`: no `dedupe:` — say whether rows with the same id in different '
            . 'environments are the same thing',
            $this->validate(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                entities:
                  CaseCategory:
                    table: case_categories
                    section: caseCategories
                    entryType: caseCategory
                    title: name
                    ignore: []
                YAML),
        );
    }

    #[Test]
    public function an_entity_with_no_title_column_is_rejected(): void
    {
        self::assertContains(
            'entity `CaseCategory`: missing `title:`',
            $this->validate(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                entities:
                  CaseCategory:
                    table: case_categories
                    section: caseCategories
                    entryType: caseCategory
                    dedupe: true
                    ignore: []
                YAML),
        );
    }

    #[Test]
    public function an_internal_link_resolves_through_the_node_it_names_a_translation_of(): void
    {
        // `[NT24]` addresses a node *translation*; the node is what becomes an entry.
        $index = new EntityIndex(self::ENTITIES, [24 => 7]);

        self::assertSame('kuma:COM:kuma_nodes:7', $index->uidFor('nodeLink', '[NT24]', 'COM'));
        self::assertNull($index->uidFor('nodeLink', 'https://example.test/', 'COM'), 'a URL is not an internal link');
        self::assertNull($index->uidFor('nodeLink', '[NT999]', 'COM'), 'a token naming no translation resolves to nothing');
    }

    #[Test]
    public function coalesce_takes_the_first_column_that_has_something(): void
    {
        $builder = $this->builderWithNodes([24 => 7]);
        $expression = 'coalesce(brand_id | ref(node), brand_url | ref(nodeLink))';

        self::assertSame(
            ['brandPage' => [['_ref' => 'kuma:COM:kuma_nodes:3']]],
            $builder->fieldsFrom(['brandPage' => $expression], ['brand_id' => 3, 'brand_url' => '[NT24]'], 'CasePage'),
        );

        // …and falls through when it does not.
        self::assertSame(
            ['brandPage' => [['_ref' => 'kuma:COM:kuma_nodes:7']]],
            $builder->fieldsFrom(['brandPage' => $expression], ['brand_id' => null, 'brand_url' => '[NT24]'], 'CasePage'),
        );
    }

    #[Test]
    public function a_url_column_holding_an_internal_link_yields_no_url(): void
    {
        // A Craft Link field rejects `[NT115]` outright, taking the whole entry with it.
        $transforms = new Transforms();
        $builder = new BlockBuilder(
            new PartReader(new PDO('sqlite::memory:')),
            $transforms,
            'COM',
        );

        self::assertSame(
            ['brandUrl' => 'https://www.swyx.com'],
            $builder->fieldsFrom(['brandUrl' => 'brand_url | externalUrl'], ['brand_url' => 'https://www.swyx.com'], 'CasePage'),
        );
        self::assertSame(
            [],
            $builder->fieldsFrom(['brandUrl' => 'brand_url | externalUrl'], ['brand_url' => '[NT115]'], 'CasePage'),
        );
        self::assertSame(1, $transforms->lossCount(), 'a dropped value is a counted loss, not a silent one');
    }

    /** @return list<string> */
    private function validate(string $yaml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new Schema())->validate(Mapping::fromFile($path));
    }
}
