<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 / D-13 — dataProvider compile pass coverage.
 *
 * Verifies that accepted `kind=dataProvider` proposals emit a
 * `mapping.dataProviders[fqcn]` entry with the expected
 * `{ sourceTable, target, configFields }` shape; that
 * `status=needs-review` rows do not emit; and that operator-curated
 * `mapping.dataProviders` entries survive skip-existing per MAP-04.
 */
final class MappingCompilerDataProvidersTest extends TestCase
{
    private MappingCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new MappingCompiler();
    }

    public function testAcceptedDataProviderRowEmitsDataProvidersEntry(): void
    {
        $mapping = [
            'proposals' => [
                $this->dataProviderRow(
                    fqcn: 'App\\PagePartProvider\\NewsListProvider',
                    sourceTable: 'kuma_news',
                    target: 'newsListBlock',
                    configFields: ['limit' => 10, 'orderBy' => 'publicationDate DESC'],
                    status: 'accepted',
                ),
            ],
        ];

        $compiled = $this->compiler->compile($mapping, [], ['nl' => 'default']);

        $this->assertArrayHasKey('App\\PagePartProvider\\NewsListProvider', $compiled['dataProviders']);
        $entry = $compiled['dataProviders']['App\\PagePartProvider\\NewsListProvider'];
        $this->assertSame('kuma_news', $entry['sourceTable']);
        $this->assertSame('newsListBlock', $entry['target']);
        $this->assertSame(['limit' => 10, 'orderBy' => 'publicationDate DESC'], $entry['configFields']);
        $this->assertSame(1, (int) $compiled['_compileReport']['dataProvidersEmitted']);
    }

    public function testNeedsReviewDataProviderRowIsNotEmitted(): void
    {
        $mapping = [
            'proposals' => [
                $this->dataProviderRow(
                    fqcn: 'App\\PagePartProvider\\TagListProvider',
                    sourceTable: 'kuma_tags',
                    target: 'tagListBlock',
                    configFields: [],
                    status: 'needs-review',
                ),
            ],
        ];

        $compiled = $this->compiler->compile($mapping, [], ['nl' => 'default']);

        $this->assertArrayNotHasKey(
            'App\\PagePartProvider\\TagListProvider',
            (array) ($compiled['dataProviders'] ?? []),
        );
        $this->assertSame(0, (int) $compiled['_compileReport']['dataProvidersEmitted']);
    }

    public function testOperatorEditedDataProviderEntryWins(): void
    {
        $mapping = [
            'dataProviders' => [
                'App\\PagePartProvider\\NewsListProvider' => [
                    'sourceTable'  => 'kuma_news',
                    'target'       => 'OPERATOR_OVERRIDE_TARGET',
                    'configFields' => ['limit' => 99],
                ],
            ],
            'proposals' => [
                $this->dataProviderRow(
                    fqcn: 'App\\PagePartProvider\\NewsListProvider',
                    sourceTable: 'kuma_news',
                    target: 'incomingTarget',
                    configFields: ['limit' => 10],
                    status: 'accepted',
                ),
            ],
        ];

        $compiled = $this->compiler->compile($mapping, [], ['nl' => 'default']);

        $entry = $compiled['dataProviders']['App\\PagePartProvider\\NewsListProvider'];
        $this->assertSame('OPERATOR_OVERRIDE_TARGET', $entry['target']);
        $this->assertSame(['limit' => 99], $entry['configFields']);
        $this->assertSame(0, (int) $compiled['_compileReport']['dataProvidersEmitted']);
    }

    /**
     * @param  array<string, mixed> $configFields
     * @return array<string, mixed>
     */
    private function dataProviderRow(
        string $fqcn,
        string $sourceTable,
        string $target,
        array $configFields,
        string $status,
    ): array {
        return [
            'kind'         => 'dataProvider',
            'fqcn'         => $fqcn,
            'sourceTable'  => $sourceTable,
            'target'       => $target,
            'configFields' => $configFields,
            'confidence'   => 'high',
            'rationale'    => 'test',
            'status'       => $status,
        ];
    }
}
