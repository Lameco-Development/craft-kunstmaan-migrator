<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use lameco\kunstmaanmigrator\load\TaxonomyMigrationService;
use PHPUnit\Framework\TestCase;

/**
 * 2026-05-09 — The default taxonomy mode is `referenced-only`: Customs are
 * materialised on demand when a Page entry references them via a relation.
 * `RelationHandler::resolveDirect` already had this — m2o relations to
 * Customs trigger `resolveTaxonomyMiss` on a state-table miss, which
 * delegates to TaxonomyMigrationService::resolveReferenced for JIT
 * materialisation.
 *
 * `resolveViaJoinTable` (the m2m path) was missing the same wiring.
 * Effect: every m2m relation to a Custom across the portfolio silently
 * dropped its values at transform time. dewert's NewsPage.categories
 * (m2m → Category) was the operator-spotted symptom — 4 source category
 * rows but 0 materialised entries in the smoke target.
 *
 * Fix: after the state-table miss + asset-deferred-token branch, fall
 * through to resolveTaxonomyMiss with `taxonomyBacked: true` synthesized
 * into options. resolveTaxonomyMiss handles unknown-taxonomy gracefully
 * (TaxonomyMigrationService::findTaxonomyMapping returns null + a
 * warning when the source isn't in mapping.taxonomies), so the
 * unconditional opt-in is safe.
 */
final class RelationHandlerJoinTableTaxonomyMissTest extends TestCase
{
    public function testJoinTableMissDelegatesToTaxonomyResolver(): void
    {
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('queryAll')
            ->with(
                'SELECT category_id FROM news_categories WHERE newspage_id = :ref',
                [':ref' => 7],
            )
            ->willReturn([['category_id' => 11], ['category_id' => 22]]);

        // Both legacy ids miss the state table — newly-introduced taxonomies
        // that have never been migrated. This is the default state at
        // transform time under `referenced-only` taxonomy mode.
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $resolver = $this->createMock(TaxonomyMigrationService::class);
        $resolver->expects($this->exactly(2))
            ->method('resolveReferenced')
            ->willReturnCallback(static fn(string $src, int|string $id): int => match ((int) $id) {
                11 => 1100,
                22 => 2200,
                default => throw new \LogicException("unexpected id $id"),
            });

        $result = (new RelationHandler())->resolve(7, $this->ctx(state: $state, legacyDb: $legacyDb, taxonomyResolver: $resolver), [
            'stateSource'       => 'App\\Entity\\Category',
            'joinTable'         => 'news_categories',
            'joinLocalColumn'   => 'newspage_id',
            'joinForeignColumn' => 'category_id',
        ]);

        self::assertSame([1100, 2200], $result);
    }

    public function testStateHitsTakeSamePath(): void
    {
        // Sanity: the JIT branch only fires on a miss. State hits short-circuit
        // before the resolver is consulted.
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('queryAll')
            ->willReturn([['category_id' => 11]]);

        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(8888);

        $resolver = $this->createMock(TaxonomyMigrationService::class);
        $resolver->expects($this->never())->method('resolveReferenced');

        $result = (new RelationHandler())->resolve(7, $this->ctx(state: $state, legacyDb: $legacyDb, taxonomyResolver: $resolver), [
            'stateSource'       => 'App\\Entity\\Category',
            'joinTable'         => 'news_categories',
            'joinLocalColumn'   => 'newspage_id',
            'joinForeignColumn' => 'category_id',
        ]);

        self::assertSame([8888], $result);
    }

    public function testAssetRelationStillEmitsDeferredToken(): void
    {
        // Asset relation path (`media` source + `kuma_media:` prefix) keeps
        // emitting deferred tokens — the fall-through to taxonomy resolver
        // mustn't fire here because asset materialisation has its own
        // load-time path (AtomicMigrationService::ingestAndResolveAssets).
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())
            ->method('queryAll')
            ->willReturn([['media_id' => 99]]);

        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $resolver = $this->createMock(TaxonomyMigrationService::class);
        $resolver->expects($this->never())->method('resolveReferenced');

        $result = (new RelationHandler())->resolve(7, $this->ctx(state: $state, legacyDb: $legacyDb, taxonomyResolver: $resolver), [
            'stateSource'       => 'media',
            'stateKeyPrefix'    => 'kuma_media:',
            'joinTable'         => 'page_media',
            'joinLocalColumn'   => 'page_id',
            'joinForeignColumn' => 'media_id',
        ]);

        self::assertSame(['asset:99'], $result);
    }

    public function testTaxonomyResolverMissDropsTheValueSilently(): void
    {
        // When TaxonomyMigrationService::resolveReferenced returns null
        // (unknown taxonomy mapping, or the source row genuinely doesn't
        // exist), the value drops silently — preserves the legacy taxonomy
        // semantic from resolveDirect. Operator review goes through the
        // report's `taxonomy relation unresolved` warnings.
        $legacyDb = $this->createMock(LegacyDbService::class);
        $legacyDb->expects($this->once())->method('queryAll')->willReturn([['category_id' => 11]]);

        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $resolver = $this->createMock(TaxonomyMigrationService::class);
        $resolver->expects($this->once())->method('resolveReferenced')->willReturn(null);

        $result = (new RelationHandler())->resolve(7, $this->ctx(state: $state, legacyDb: $legacyDb, taxonomyResolver: $resolver), [
            'stateSource'       => 'App\\Entity\\Category',
            'joinTable'         => 'news_categories',
            'joinLocalColumn'   => 'newspage_id',
            'joinForeignColumn' => 'category_id',
        ]);

        self::assertSame([], $result);
    }

    private function ctx(
        ?MigrationStateReader $state = null,
        ?LegacyDbService $legacyDb = null,
        ?TaxonomyMigrationService $taxonomyResolver = null,
        bool $dryRun = false,
        ?MigrationReport $report = null,
    ): ResolverContext {
        return new ResolverContext(
            siteId: 1,
            siteHandle: 'default',
            state: $state ?? $this->createStub(MigrationStateReader::class),
            ck: $this->createStub(CkeditorRewriterService::class),
            paths: $this->createStub(AssetPathResolver::class),
            siteMap: ['nl' => 1, 'en' => 2],
            legacyDb: $legacyDb,
            taxonomyResolver: $taxonomyResolver,
            dryRun: $dryRun,
            report: $report,
        );
    }
}
