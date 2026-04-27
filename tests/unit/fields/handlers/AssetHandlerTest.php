<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

use lameco\kunstmaanmigrator\fields\handlers\AssetHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for AssetHandler.
 *
 * Strategy: createStub for MigrationStateReader (handlers consume it for
 * legacy media-id → migrated asset-id lookup). The `as=imgTag` SUCCESS branch
 * calls Craft\Asset::findOne() which requires a Craft bootstrap and is exercised
 * in the integration tier; here we cover the deferred-token path
 * (`as=imgTag` + state miss → `[M{id}]`) and the relation path in full.
 *
 * The optional `assetResolver` slot (FH-03 JIT lazy-resolve) is covered via a
 * minimal anonymous-class stand-in implementing the
 * `resolveFromLegacyId(int): int` surface (the handler types the slot as
 * ?object — D-08 in src/fields/handlers/AssetHandler.php).
 *
 * Coverage target: ≥ 70.0% line coverage on
 * src/fields/handlers/AssetHandler.php (D-08 directory prefix gate).
 */
final class AssetHandlerTest extends TestCase
{
    private function ctx(?MigrationStateReader $state = null): ResolverContext
    {
        return new ResolverContext(
            siteId: 1,
            siteHandle: 'default',
            state: $state ?? $this->createStub(MigrationStateReader::class),
            ck: $this->createStub(CkeditorRewriterService::class),
            paths: $this->createStub(AssetPathResolver::class),
            siteMap: ['nl' => 1],
            legacyDb: null,
        );
    }

    public function testIdReturnsAsset(): void
    {
        self::assertSame('asset', (new AssetHandler())->id());
    }

    // ---------- empty-input handling ----------

    public function testRelationModeReturnsEmptyArrayForNullEmptyOrZeroInput(): void
    {
        $h = new AssetHandler();
        self::assertSame([], $h->resolve(null, $this->ctx()));
        self::assertSame([], $h->resolve('', $this->ctx()));
        self::assertSame([], $h->resolve(0, $this->ctx()));
        self::assertSame([], $h->resolve('0', $this->ctx()));
    }

    public function testImgTagModeReturnsEmptyStringForNullOrZeroInput(): void
    {
        $h = new AssetHandler();
        self::assertSame('', $h->resolve(null, $this->ctx(), ['as' => 'imgTag']));
        self::assertSame('', $h->resolve(0, $this->ctx(), ['as' => 'imgTag']));
        self::assertSame('', $h->resolve('0', $this->ctx(), ['as' => 'imgTag']));
    }

    // ---------- relation path: hit ----------

    public function testRelationModeReturnsAssetIdForKnownLegacyMediaId(): void
    {
        $state = $this->createMock(MigrationStateReader::class);
        $state->expects($this->once())
            ->method('getTargetId')
            ->with('media', 'kuma_media:42', null)
            ->willReturn(777);

        $h = new AssetHandler();
        self::assertSame([777], $h->resolve(42, $this->ctx(state: $state)));
    }

    public function testRelationModeRespectsCustomStateSourceAndKeyFormat(): void
    {
        $state = $this->createMock(MigrationStateReader::class);
        $state->expects($this->once())
            ->method('getTargetId')
            ->with('media-alt', 'legacy:99', null)
            ->willReturn(123);

        $h = new AssetHandler();
        $result = $h->resolve(99, $this->ctx(state: $state), [
            'stateSource' => 'media-alt',
            'keyFormat' => 'legacy:%d',
        ]);
        self::assertSame([123], $result);
    }

    // ---------- relation path: miss → JIT lazy resolve via assetResolver ----------

    public function testRelationModeUsesAssetResolverWhenStateMisses(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        // Anonymous class implementing the resolveFromLegacyId surface.
        // The handler's $assetResolver slot is typed ?object — see
        // src/fields/handlers/AssetHandler.php docblock (FH-03).
        $resolver = new class {
            public int $callCount = 0;
            public int $lastLegacyId = 0;
            public function resolveFromLegacyId(int $legacyId): int
            {
                $this->callCount++;
                $this->lastLegacyId = $legacyId;
                return 555;
            }
        };

        $h = new AssetHandler();
        $h->assetResolver = $resolver;
        $result = $h->resolve(42, $this->ctx(state: $state));

        self::assertSame([555], $result);
        self::assertSame(1, $resolver->callCount);
        self::assertSame(42, $resolver->lastLegacyId);
    }

    public function testRelationModeFallsBackToDeferredTokenWhenAssetResolverReturnsZero(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $resolver = new class {
            public function resolveFromLegacyId(int $legacyId): int
            {
                return 0; // miss — handler falls back to deferred token
            }
        };

        $h = new AssetHandler();
        $h->assetResolver = $resolver;
        $result = $h->resolve(42, $this->ctx(state: $state));
        self::assertSame(['asset:42'], $result);
    }

    // ---------- relation path: miss + no resolver → deferred token ----------

    public function testRelationModeEmitsDeferredAssetTokenWhenStateAndResolverBothMiss(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $h = new AssetHandler();
        // assetResolver left null — exercises the no-JIT path.
        $result = $h->resolve(42, $this->ctx(state: $state));
        self::assertSame(['asset:42'], $result);
    }

    public function testRelationModeMissWithNonDefaultKeyFormatSkipsJitAndEmitsDeferredToken(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        // Non-default keyFormat → JIT branch skipped (resolver's hardcoded
        // 'kuma_media:{id}' wouldn't match), even if a resolver is wired.
        $resolver = new class {
            public int $callCount = 0;
            public function resolveFromLegacyId(int $legacyId): int
            {
                $this->callCount++;
                return 999;
            }
        };

        $h = new AssetHandler();
        $h->assetResolver = $resolver;
        $result = $h->resolve(7, $this->ctx(state: $state), ['keyFormat' => '%d']);

        self::assertSame(['asset:7'], $result);
        self::assertSame(0, $resolver->callCount, 'JIT must be skipped for non-default keyFormat');
    }

    // ---------- imgTag path: miss → bracket-form deferred token ----------

    public function testImgTagModeEmitsBracketDeferredTokenWhenStateMisses(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $h = new AssetHandler();
        $result = $h->resolve(42, $this->ctx(state: $state), ['as' => 'imgTag']);
        self::assertSame('[M42]', $result);
    }
}
