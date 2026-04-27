<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 / D-07 — taxonomy compile pass coverage.
 *
 * Verifies that accepted `kind=taxonomy` proposals are folded into
 * `mapping.taxonomies[fqcn]` with the `fields` sub-map inferred from
 * matching same-`sourceTable` `kind=column` rows; that `needs-review`
 * rows do not emit; and that operator-curated `mapping.taxonomies`
 * entries survive skip-existing per MAP-04.
 */
final class MappingCompilerTaxonomiesTest extends TestCase
{
    private MappingCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MappingCompiler();
    }

    public function testAcceptedTaxonomyRowEmitsTaxonomiesEntryWithFieldsSubMap(): void
    {
        $mapping = [
            'proposals' => [
                $this->taxonomyRow(
                    fqcn: 'App\\Entity\\NewsCategory',
                    sourceTable: 'kuma_news_categories',
                    targetSection: 'newsCategories',
                    targetEntryType: 'newsCategory',
                    status: 'accepted',
                ),
                $this->columnRow('kuma_news_categories', 'name', 'newsCategory', 'title'),
            ],
        ];

        $compiled = $this->compiler->compile($mapping, [], ['nl' => 'default']);

        $this->assertArrayHasKey('App\\Entity\\NewsCategory', $compiled['taxonomies']);
        $entry = $compiled['taxonomies']['App\\Entity\\NewsCategory'];
        $this->assertSame('kuma_news_categories', $entry['sourceTable']);
        $this->assertSame('newsCategories', $entry['targetSection']);
        $this->assertSame('newsCategory', $entry['targetEntryType']);
        $this->assertSame(['name' => 'title'], $entry['fields']);

        $this->assertSame(1, (int) $compiled['_compileReport']['taxonomiesEmitted']);
    }

    public function testTaxonomyWithStatusNeedsReviewIsNotEmitted(): void
    {
        $mapping = [
            'proposals' => [
                $this->taxonomyRow(
                    fqcn: 'App\\Entity\\Tag',
                    sourceTable: 'kuma_tags',
                    targetSection: 'tags',
                    targetEntryType: 'tag',
                    status: 'needs-review',
                ),
                // Column rows on the same sourceTable should NOT cause a
                // fields sub-map to leak through — the taxonomy row itself
                // never emits, so there is no parent entry to attach to.
                $this->columnRow('kuma_tags', 'name', 'tag', 'title'),
            ],
        ];

        $compiled = $this->compiler->compile($mapping, [], ['nl' => 'default']);

        $this->assertArrayNotHasKey('App\\Entity\\Tag', (array) ($compiled['taxonomies'] ?? []));
        $this->assertSame(0, (int) $compiled['_compileReport']['taxonomiesEmitted']);
    }

    public function testOperatorEditedTaxonomyEntryWins(): void
    {
        $mapping = [
            'taxonomies' => [
                'App\\Entity\\NewsCategory' => [
                    'sourceTable'     => 'kuma_news_categories',
                    'targetSection'   => 'OPERATOR_OVERRIDE_SECTION',
                    'targetEntryType' => 'OPERATOR_OVERRIDE_ENTRY_TYPE',
                    'fields'          => [],
                ],
            ],
            'proposals' => [
                $this->taxonomyRow(
                    fqcn: 'App\\Entity\\NewsCategory',
                    sourceTable: 'kuma_news_categories',
                    targetSection: 'incomingSection',
                    targetEntryType: 'incomingEntryType',
                    status: 'accepted',
                ),
                // Column row that would normally produce fields[name => title]
                // — operator's empty fields map must survive intact.
                $this->columnRow('kuma_news_categories', 'name', 'newsCategory', 'title'),
            ],
        ];

        $compiled = $this->compiler->compile($mapping, [], ['nl' => 'default']);

        $entry = $compiled['taxonomies']['App\\Entity\\NewsCategory'];
        $this->assertSame('OPERATOR_OVERRIDE_SECTION', $entry['targetSection']);
        $this->assertSame('OPERATOR_OVERRIDE_ENTRY_TYPE', $entry['targetEntryType']);
        $this->assertSame([], $entry['fields']);
        $this->assertSame(0, (int) $compiled['_compileReport']['taxonomiesEmitted']);
    }

    /** @return array<string, mixed> */
    private function taxonomyRow(
        string $fqcn,
        string $sourceTable,
        string $targetSection,
        string $targetEntryType,
        string $status,
    ): array {
        return [
            'kind'            => 'taxonomy',
            'fqcn'            => $fqcn,
            'sourceTable'     => $sourceTable,
            'targetSection'   => $targetSection,
            'targetEntryType' => $targetEntryType,
            'confidence'      => 'high',
            'rationale'       => 'test',
            'status'          => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function columnRow(
        string $table,
        string $column,
        string $entryType,
        string $targetHandle,
    ): array {
        return [
            'kind'            => 'column',
            'table'           => $table,
            'column'          => $column,
            'targetEntryType' => $entryType,
            'targetHandle'    => $targetHandle,
            'handler'         => 'plain',
            'status'          => 'accepted',
        ];
    }
}
