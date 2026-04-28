<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields\handlers;

use lameco\kunstmaanmigrator\fields\FieldHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\fields\DeferredAssetToken;
use craft\elements\Asset;

/**
 * Resolves a legacy kuma_media id → Craft asset id via the state table.
 *
 * Options:
 *   stateSource (string, default 'media')    — state-table source key
 *   keyFormat   (string, default 'kuma_media:%d') — sprintf format for sourceKey
 *   as          (string, default 'relation') — 'relation' | 'imgTag'
 *
 * Output shape depends on `as`:
 *   'relation' → [int $assetId] (one-element list, ready for Craft asset fields)
 *   'imgTag'   → <img src="..." alt="..."/> (used when CKEditor inlines imgs)
 *
 * State-table source uses siteId=null per existing convention
 * ({@see \lameco\kunstmaanmigrator\load\AssetMigrationService}) — assets
 * are migrated once and referenced from every site.
 *
 * JIT lazy-resolve (FH-03 default): when the state-table lookup misses AND
 * the caller is using the default state-key contract (`source='media'`,
 * `keyFormat='kuma_media:%d'`), delegate to the asset resolver so the asset
 * materialises lazily. Non-default keyFormat callers preserve the
 * deferred-token miss behaviour below — the resolver's hardcoded
 * `kuma_media:{id}` key wouldn't match their state rows.
 *
 * Deferred-token contract (FH-04): when both the state lookup and JIT
 * resolve miss, emit one of two token formats (consumer determined by `as`):
 *
 *   - `[M{$legacyValue}]`                            (CKEditor placeholder)
 *     Consumed at finalize time by CkeditorRewriterService (FIN-01) — only
 *     emitted when `as=imgTag`, i.e. the rewriter inlined an `<img>` whose
 *     asset hasn't been materialised yet.
 *
 *   - `[DeferredAssetToken::emit($legacyValue)]`     ('asset:N' string)
 *     Consumed at load time by AtomicMigrationService::ingestAndResolveAssets
 *     per the /^asset:\d+$/ regex pair — emitted for the relation path so
 *     the load pass materialises the asset and resolves the id before save.
 *
 * Both deferred-token formats are load-bearing for FH-04 — DO NOT modify.
 */
final class AssetHandler implements FieldHandler
{
    /**
     * Asset resolver — injected by Plugin::init() (Plan 03-14) which wires
     * AssetMigrationService into this slot. Null-safe: falls back to the
     * deferred-token miss behaviour when absent.
     *
     * AssetResolver folded into AssetMigrationService per Phase 3 advisor decision;
     * $assetResolver->resolveFromLegacyId(int): int is the consumed surface.
     */
    public ?object $assetResolver = null;

    public function id(): string
    {
        return 'asset';
    }

    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
    {
        $as = (string) ($options['as'] ?? 'relation');

        if ($legacyValue === null || $legacyValue === '' || $legacyValue === 0 || $legacyValue === '0') {
            return $as === 'imgTag' ? '' : [];
        }

        $source = (string) ($options['stateSource'] ?? 'media');
        $keyFormat = (string) ($options['keyFormat'] ?? 'kuma_media:%d');
        $key = sprintf($keyFormat, (int) $legacyValue);

        $id = $ctx->state->getTargetId($source, $key, null);
        if ($id === null && $source === 'media' && $keyFormat === 'kuma_media:%d') {
            // Lazy materialise via the asset resolver (FH-03 JIT default).
            // Only safe for the default source+keyFormat that the resolver's
            // internal `kuma_media:{id}` state-key contract matches.
            // Non-default callers (e.g. `keyFormat='%d'`) keep the existing
            // deferred-token miss behaviour below.
            if ($this->assetResolver !== null) {
                $resolved = $this->assetResolver->resolveFromLegacyId((int) $legacyValue);
                if ($resolved > 0) {
                    $id = $resolved;
                }
            }
        }
        if ($id === null) {
            // Emit a deferred token instead of silently dropping. At transform time
            // the asset state table is empty (assets migrate after transform), so the
            // state lookup above always misses. The token "asset:{legacyId}" is picked
            // up by AtomicMigrationService::collectReferencedMediaIds() at load time,
            // which materialises the asset and resolves the ID before entry save.
            return $as === 'imgTag' ? "[M{$legacyValue}]" : [DeferredAssetToken::emit((int) $legacyValue)];
        }

        if ($as === 'imgTag') {
            $asset = Asset::findOne(['id' => $id]);
            if (!$asset) {
                return '';
            }
            $url = (string) ($asset->getUrl() ?? '');
            $alt = (string) ($asset->title ?? '');
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"/>';
        }

        return [$id];
    }
}
