<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\filter;

use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;

/**
 * Phase 9 / Plan 09-02C: cross-stage source-domain filter consistency.
 *
 * This suite intentionally stays mostly source/pure-level even though it lives
 * under integration/: the real runtime controllers/services extend Craft/Yii
 * classes and need a configured Craft app. The assertions lock the handoff
 * contract that the Craft integration exercises in operator runs.
 */
final class CrossStageFilterConsistencyTest extends TestCase
{
    public function testStageFiltersNormalizeRelationGraphArtifact(): void
    {
        $artifact = [
            'App\\Entity\\Pages\\NewsPage' => [
                'manyToOne' => [
                    ['targetEntity' => 'App\\Entity\\Taxonomy\\NewsCategory'],
                    ['targetEntity' => 'App\\Entity\\Author'],
                    ['targetEntity' => ''],
                ],
            ],
            'App\\Entity\\Taxonomy\\NewsCategory' => [
                'manyToOne' => [
                    ['targetEntity' => 'App\\Entity\\Taxonomy\\CategoryGroup'],
                ],
            ],
        ];

        self::assertSame(
            [
                'App\\Entity\\Pages\\NewsPage' => [
                    'App\\Entity\\Taxonomy\\NewsCategory',
                    'App\\Entity\\Author',
                ],
                'App\\Entity\\Taxonomy\\NewsCategory' => [
                    'App\\Entity\\Taxonomy\\CategoryGroup',
                ],
            ],
            FilterFactory::relationGraphFromArtifact($artifact),
        );

        $filters = new MigrationFilters(
            entities: ['NewsPage'],
            relationGraph: FilterFactory::relationGraphFromArtifact($artifact),
        );
        self::assertTrue($filters->allows('App\\Entity\\Taxonomy\\NewsCategory'));
        self::assertTrue($filters->allows('CategoryGroup'));
        self::assertFalse($filters->allows('App\\Entity\\Unrelated'));
    }

    public function testStageFilterHandoffIsCentralizedAcrossControllers(): void
    {
        $migrate = $this->sourceFor('MigrateController');
        self::assertStringContainsString('buildRuntimeFilters', $migrate);
        self::assertStringContainsString('loadRuntimeRelationGraph', $migrate);
        self::assertStringContainsString('relationGraphFromArtifact', $migrate);
        self::assertStringContainsString('filterFactory->fromCli(', $migrate);
        self::assertStringContainsString('relationGraph: $this->loadRuntimeRelationGraph()', $migrate);

        foreach ([
            'AnalyzeWorkflow' => 'src/workflow/AnalyzeWorkflow.php',
            'MapController' => 'src/console/MapController.php',
            'VerifyWorkflow' => 'src/workflow/VerifyWorkflow.php',
        ] as $label => $file) {
            $source = (string) file_get_contents(__DIR__ . '/../../../' . $file);
            self::assertStringContainsString('relationGraphFromArtifact', $source, $label . ' must use the same relationGraph normalizer.');
            self::assertStringContainsString('relationGraph:', $source, $label . ' must pass normalized graph into FilterFactory::fromCli().');
        }
    }

    public function testStageConsumersUseSourceDomainAllowsOrTranslatedCraftScopeOnly(): void
    {
        foreach ([
            'src/extract/ExtractService.php',
            'src/transform/TransformService.php',
            'src/console/MigrateController.php',
            'src/load/TaxonomyMigrationService.php',
        ] as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../../../' . $file);
            self::assertStringContainsString('->allows(', $source, $file . ' must consume the normalized source-domain reachability set.');
        }

        $baseline = (string) file_get_contents(__DIR__ . '/../../../src/verify/BaselineCounterService.php');
        self::assertStringContainsString('translatedScope', $baseline);
        self::assertStringContainsString('CountGateService::isSectionFilteredOut', $baseline);
        self::assertStringNotContainsString('in_array($handle, $filters->entities', $baseline);
    }

    public function testSidecarClosureSurfacesAreScopedOrClassified(): void
    {
        $migrate = $this->sourceFor('MigrateController');

        self::assertStringContainsString('taxonomyMigrationService->filters = $filters', $migrate);
        self::assertStringContainsString('seoMigrationService->filters = $filters', $migrate);
        self::assertStringContainsString('redirectMigrationService->filters = $filters', $migrate);
        self::assertStringContainsString('collectReferencedAssetIdsFromPayloadDirectory($transformedDir, $filters)', $migrate);
        self::assertStringContainsString('payloadFileMatchesFilters', $migrate);

        self::assertStringContainsString("preg_match_all('/\\basset:(\\d+)\\b/'", $migrate);
        self::assertStringContainsString("preg_match_all('/\\[M(\\d+)\\]/'", $migrate);
        self::assertStringContainsString("'referencedMediaIds' => true", $migrate);
        self::assertStringNotContainsString('preg_match_all(\'/\\[NT', $migrate, '[NT] stays an entry-token for finalize, not an asset preload id.');

        $coverage = (string) file_get_contents(__DIR__ . '/../../../src/audit/PageRootedCoverageAuditor.php');
        self::assertStringContainsString('unsupported', $coverage);
        self::assertStringContainsString('warning', $coverage);
        self::assertStringContainsString('out_of_scope', $coverage);
    }

    public function testSidecarGraphKeepsPageOwnedDependenciesInScope(): void
    {
        $filters = new MigrationFilters(
            entities: ['NewsPage'],
            relationGraph: [
                'App\\Entity\\Pages\\NewsPage' => [
                    'App\\Entity\\Taxonomy\\NewsCategory',
                    'App\\Entity\\Providers\\FeaturedNewsProvider',
                ],
                'App\\Entity\\Taxonomy\\NewsCategory' => [
                    'App\\Entity\\Taxonomy\\CategoryGroup',
                ],
            ],
        );

        self::assertTrue($filters->allows('App\\Entity\\Pages\\NewsPage'));
        self::assertTrue($filters->allows('App\\Entity\\Taxonomy\\NewsCategory'));
        self::assertTrue($filters->allows('FeaturedNewsProvider'));
        self::assertTrue($filters->allows('App\\Entity\\Taxonomy\\CategoryGroup'));
        self::assertFalse($filters->allows('App\\Entity\\Pages\\UnrelatedPage'));
    }

    private function sourceFor(string $class): string
    {
        $map = [
            'MigrateController' => __DIR__ . '/../../../src/workflow/MigrateWorkflow.php',
        ];

        return (string) file_get_contents($map[$class]);
    }
}
