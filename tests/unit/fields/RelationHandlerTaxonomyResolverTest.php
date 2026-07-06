<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use lameco\kunstmaanmigrator\fields\TaxonomyRelationResolver;
use PHPUnit\Framework\TestCase;

final class RelationHandlerTaxonomyResolverTest extends TestCase
{
    private function ctx(
        ?MigrationStateReader $state = null,
        ?TaxonomyRelationResolver $taxonomyResolver = null,
        ?MigrationReport $report = null,
        bool $dryRun = false,
    ): ResolverContext {
        return new ResolverContext(
            siteId: 1,
            siteHandle: 'default',
            state: $state ?? $this->createStub(MigrationStateReader::class),
            ck: $this->createStub(CkeditorRewriterService::class),
            paths: $this->createStub(AssetPathResolver::class),
            siteMap: ['nl' => 1],
            legacyDb: $this->createStub(LegacyDbService::class),
            taxonomyResolver: $taxonomyResolver,
            dryRun: $dryRun,
            report: $report,
        );
    }

    public function testNonEmptyTaxonomyMissDelegatesToResolver(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $resolver = $this->createMock(TaxonomyRelationResolver::class);
        $resolver->expects($this->once())
            ->method('resolveReferenced')
            ->with('App\\Entity\\TopicTaxonomy', 42)
            ->willReturn(9001);

        $result = (new RelationHandler())->resolve(42, $this->ctx($state, $resolver), [
            'stateSource' => 'App_Entity_TopicTaxonomy',
            'taxonomySource' => 'App\\Entity\\TopicTaxonomy',
        ]);

        self::assertSame([9001], $result);
    }

    public function testNonTaxonomyStateMissDoesNotInvokeResolver(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $resolver = $this->createMock(TaxonomyRelationResolver::class);
        $resolver->expects($this->never())->method('resolveReferenced');

        $result = (new RelationHandler())->resolve(42, $this->ctx($state, $resolver), [
            'stateSource' => 'news',
        ]);

        self::assertSame([], $result);
    }

    public function testEmptyTaxonomyValueDoesNotInvokeResolver(): void
    {
        $resolver = $this->createMock(TaxonomyRelationResolver::class);
        $resolver->expects($this->never())->method('resolveReferenced');

        $result = (new RelationHandler())->resolve('', $this->ctx(taxonomyResolver: $resolver), [
            'stateSource' => 'App_Entity_TopicTaxonomy',
            'taxonomySource' => 'App\\Entity\\TopicTaxonomy',
        ]);

        self::assertSame([], $result);
    }

    public function testUnresolvedTaxonomyMissIsVisibleInReport(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $resolver = $this->createMock(TaxonomyRelationResolver::class);
        $resolver->method('resolveReferenced')->willReturn(null);

        $report = new MigrationReport();
        $result = (new RelationHandler())->resolve(12, $this->ctx($state, $resolver, $report, true), [
            'stateSource' => 'App_Entity_TopicTaxonomy',
            'taxonomySource' => 'App\\Entity\\TopicTaxonomy',
        ]);

        self::assertSame([], $result);
        self::assertNotSame([], $report->warnings);
        self::assertStringContainsString('taxonomy relation unresolved', implode("\n", $report->warnings));
    }
}
