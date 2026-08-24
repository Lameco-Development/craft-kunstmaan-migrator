<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\finalize;

use Craft;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\MigrationStateStream;
use yii\base\Component;

/**
 * Rewrites legacy Kunstmaan CKEditor body HTML into Craft-native form.
 *
 * Transformations (in order):
 *   1. <img src="/uploads/media/*">   → {asset:<id>@<siteId>:url} ref-token
 *   2. <a href="/uploads/media/*">    → same ref-token (PDFs etc.)
 *   3. <a href="/some/path">          → {entry:<id>@<siteId>:url} when path maps to a migrated entry
 *   4. Strip class tokens matching /^kma-/ (drops the class attr entirely when all tokens are kma-*)
 *   5. Remove empty <p></p>, <p>&nbsp;</p>, <p> </p>, <p><br></p>
 *
     * Unresolvable asset refs keep the original URL + HTML comment marker with a
     * delimiter-safe source payload:
     *   <!-- MIGRATION:UNRESOLVED sourceB64=... -->
     * so editors can grep and tooling can recover the source. HTML Purifier
     * strips comments on save, so rendered pages won't leak them — scan the
     * raw DB column for this marker BEFORE the save-pipeline runs through
     * Purifier (no dedicated CLI command exists for this; grep the column
     * directly).
 *
 * Ref-token format (Craft 5 CKEditor, imageMode=img):
 *   {asset:<numeric-id>@<siteId>:url}
 *   {entry:<numeric-id>@<siteId>:url}
 * Pitfall 7: numeric ID, NOT UID — HtmlField::serializeValue's regex only
 * matches numeric IDs when normalizing fragments.
 */
class CkeditorRewriterService extends Component
{
    private const LEGACY_MEDIA_URL_PREFIX = '/uploads/media/';

    /**
     * Kunstmaan CKEditor media placeholder `[M<digits>]` — the numeric value is
     * the kuma_media.id directly. Typical occurrence: `href="[M482]"`.
     *
     * Public const so AssetScanService + tests can reuse the same regex.
     */
    /**
     * Matches both the raw form `[M482]` and the URL-encoded form `%5BM482%5D`
     * that Craft's CKEditor serializer produces when saving a shortcode as an
     * `src`/`href` attribute value (brackets are not valid in URLs so they get
     * percent-encoded on save). The `i` flag handles the rare `%5b`/`%5d`
     * lower-case variant emitted by some HTTP clients.
     */
    public const KUMA_MEDIA_PLACEHOLDER_REGEX = '~(?:\[|%5B)M(\d+)(?:\]|%5D)~i';

    /**
     * Kunstmaan CKEditor internal-link placeholder `[NT<digits>]` — the numeric
     * value is `kuma_node_translations.id`, which maps (via node_id) to a state
     * row for a migrated entry. Typical occurrence: `href="[NT80]"`.
     * Also matches the URL-encoded form `%5BNT80%5D` (same encoding as [M]).
     */
    public const KUMA_NT_PLACEHOLDER_REGEX = '~(?:\[|%5B)NT(\d+)(?:\]|%5D)~i';

    /**
     * Task 8 / docs/loader-contract.md — the v2 loader's OWN normalized
     * in-payload media token, e.g. `{{kuma:media:123}}`. Distinct from
     * KUMA_MEDIA_PLACEHOLDER_REGEX's `[M<id>]`/`%5BM<id>%5D`, which is
     * Kunstmaan's legacy CKEditor-plugin output surviving in already-migrated
     * HTML — this is the loader's canonical intermediate representation,
     * embedded directly into a payload's `fieldValues` body text by the
     * orchestration side wherever a legacy media reference sits inline.
     */
    public const KUMA_MEDIA_TOKEN_REGEX = '~\{\{kuma:media:(\d+)\}\}~';

    /**
     * State stream — injected by the module. Typed to the streaming interface,
     * not to MigrationStateService, so the cache-warming paths below can be
     * driven by a fake and are reachable without booting Craft. Null-safe:
     * when nothing is wired, warming yields an empty cache.
     */
    public ?MigrationStateStream $migrationState = null;

    /**
     * LegacyDbService — injected by the module. Null-safe: warmNtCache()
     * guards against absence.
     */
    public ?LegacyDbService $legacyDb = null;

    /**
     * AssetResolver — injected by the module. Null-safe: resolveKumaMediaId
     * guards against absence.
     */
    // AssetResolver responsibility folded into AssetMigrationService per Phase 3 advisor decision; typed ?object slot wired by Plugin::init() Plan 03-14.
    public ?object $assetResolver = null;

    /** @var array<string, int> */
    private array $urlIdCache = [];

    private bool $urlCacheWarm = false;

    /** @var array<string, int> legacy media URL/path → kuma_media.id */
    private array $urlToKumaMediaIdCache = [];

    /** @var array<int, int> kuma_media.id → Craft asset numeric id */
    private array $kumaMediaIdCache = [];

    private bool $kumaMediaCacheWarm = false;

    /**
     * @var array<int, true> kuma_media.id known to have no matching Craft
     *   asset this request — Task 8 review Finding 2: without this, a broken
     *   id occurring more than once (same string, different fields, or across
     *   saves) would re-invoke AssetResolver::resolveFromLegacyId()'s
     *   expensive legacy-DB ingest attempt every single time.
     */
    private array $kumaMediaIdMissCache = [];

    /** @var array<int, int> kuma_node_translations.id → Craft entry numeric id */
    private array $ntToEntryCache = [];

    private bool $ntCacheWarm = false;

    /** @var list<array<string, mixed>> */
    private array $unresolvedDiagnostics = [];

    /** @var list<array<string, mixed>> */
    private array $outOfScopeDiagnostics = [];

    /** @var array<int, string> */
    private array $outOfScopeNtReasons = [];

    /** @var array<int, string> */
    private array $outOfScopeMediaReasons = [];

    /**
     * Rewrite a legacy CKEditor body HTML string to Craft-native form.
     *
     * @param array<string, int> $entryUrlToId optional map of legacy URL path → Craft entry numeric id
     */
    public function rewrite(?string $html, int $siteId, array $entryUrlToId = []): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $html = $this->stripUnresolvedMarkers($html);

        // Step 1 — rewrite <img src="/uploads/media/..."> and <a href="/uploads/media/...">
        $html = $this->rewriteAssetAttributes($html, $siteId);

        // Step 1b — rewrite Kunstmaan `[M<id>]` media placeholders (appear in
        // the raw href/data-href of CKEditor-plugin-generated media links).
        $html = $this->rewriteMediaPlaceholders($html, $siteId);

        // Step 1c — rewrite Kunstmaan `[NT<id>]` internal-link placeholders.
        // NT<id> = kuma_node_translations.id → kuma_nodes → migrated entry.
        $html = $this->rewriteNodeTranslationPlaceholders($html, $siteId);

        // Step 1d — rewrite the loader's own `{{kuma:media:<id>}}` payload
        // tokens (docs/loader-contract.md), distinct from the legacy
        // `[M<id>]` placeholder handled by Step 1b.
        $html = $this->rewriteCurlyMediaTokens($html, $siteId);

        // Step 2 — rewrite <a href="/internal/path"> when path resolves to a migrated entry
        if (!empty($entryUrlToId)) {
            $html = $this->rewriteEntryLinks($html, $siteId, $entryUrlToId);
        }

        // Step 3 — strip kma-* classes
        $html = $this->stripKumaClasses($html);

        // Step 4 — remove empty <p>
        $html = $this->removeEmptyParagraphs($html);

        return $html;
    }

    /**
     * Internal test seam — pre-populate the URL→id cache without touching
     * the state table. Non-test callers leave this alone; the cache warms
     * itself from MigrationStateService on first lookup in production.
     *
     * @param array<string, int> $map legacy URL path → Craft asset numeric id
     *
     * @internal used by tests
     */
    public function seedUrlIdCache(array $map): void
    {
        foreach ($map as $url => $id) {
            $key = '/' . ltrim((string) $url, '/');
            $this->urlIdCache[$key] = (int) $id;
        }
        $this->urlCacheWarm = true;
    }

    /**
     * Internal test seam — pre-populate legacy media URL → kuma_media.id lookup
     * used when finalize encounters raw /uploads/media/... CKEditor URLs that
     * have not already been materialised into state rows.
     *
     * @param array<string, int> $map legacy media URL/path → kuma_media.id
     *
     * @internal used by tests
     */
    public function seedMediaUrlToKumaMediaIdCache(array $map): void
    {
        foreach ($map as $url => $id) {
            $this->urlToKumaMediaIdCache[$this->normalizeMediaUrlLookupKey((string) $url)] = (int) $id;
        }
    }

    /**
     * Internal test seam — pre-populate the kuma_media.id → Craft asset id
     * cache used to resolve [M<id>] placeholders.
     *
     * @param array<int, int> $map kuma_media.id → Craft asset numeric id
     *
     * @internal used by tests
     */
    public function seedKumaMediaIdCache(array $map): void
    {
        foreach ($map as $kumaMediaId => $craftAssetId) {
            $this->kumaMediaIdCache[(int) $kumaMediaId] = (int) $craftAssetId;
        }
        $this->kumaMediaCacheWarm = true;
    }

    /**
     * Internal test seam — pre-populate the kuma_node_translations.id → Craft
     * entry id cache used to resolve [NT<id>] placeholders.
     *
     * @param array<int, int> $map kuma_node_translations.id → Craft entry numeric id
     *
     * @internal used by tests
     */
    public function seedNtToEntryCache(array $map): void
    {
        foreach ($map as $ntId => $craftEntryId) {
            $this->ntToEntryCache[(int) $ntId] = (int) $craftEntryId;
        }
        $this->ntCacheWarm = true;
    }

    /**
     * @param array<int, string> $map kuma_node_translations.id → reason
     *
     * @internal used by tests
     */
    public function seedOutOfScopeNtReasons(array $map): void
    {
        foreach ($map as $ntId => $reason) {
            $this->outOfScopeNtReasons[(int) $ntId] = (string) $reason;
        }
    }

    /**
     * @param array<int, string> $map kuma_media.id → reason
     *
     * @internal used by tests
     */
    public function seedOutOfScopeMediaReasons(array $map): void
    {
        foreach ($map as $mediaId => $reason) {
            $this->outOfScopeMediaReasons[(int) $mediaId] = (string) $reason;
        }
    }

    /**
     * Consume reasoned unresolved-token diagnostics for the most recent rewrite
     * batch. The buffer is reset after consumption so callers can safely attach
     * field-local context without stale rows leaking into later fields.
     *
     * @return list<array<string, mixed>>
     */
    public function consumeUnresolvedDiagnostics(): array
    {
        $rows = $this->unresolvedDiagnostics;
        $this->unresolvedDiagnostics = [];
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function consumeOutOfScopeDiagnostics(): array
    {
        $rows = $this->outOfScopeDiagnostics;
        $this->outOfScopeDiagnostics = [];
        return $rows;
    }

    /**
     * Clear per-request lookup caches before a finalize walk.
     *
     * Transform handlers may use this same service instance before all entries
     * and assets have been loaded. Finalize must warm fresh caches from the
     * completed state table, otherwise broad migrations can keep stale NT/media
     * misses from earlier transform-time rewrites.
     */
    public function resetLookupCaches(): void
    {
        $this->urlIdCache = [];
        $this->urlCacheWarm = false;
        $this->urlToKumaMediaIdCache = [];
        $this->kumaMediaIdCache = [];
        $this->kumaMediaCacheWarm = false;
        $this->kumaMediaIdMissCache = [];
        $this->ntToEntryCache = [];
        $this->ntCacheWarm = false;
        $this->unresolvedDiagnostics = [];
        $this->outOfScopeDiagnostics = [];
        $this->outOfScopeNtReasons = [];
        $this->outOfScopeMediaReasons = [];
    }

    /**
     * Rewrite `[M<id>]` Kunstmaan media placeholders to Craft asset ref tokens.
     * Works whether the placeholder sits inside an attribute (`href="[M482]"`)
     * or bare; the regex matches anywhere.
     */
    private function rewriteMediaPlaceholders(string $html, int $siteId): string
    {
        if (!str_contains($html, '[M') && !str_contains($html, '%5BM') && !str_contains($html, '%5bM')) {
            return $html;
        }

        return preg_replace_callback(
            self::KUMA_MEDIA_PLACEHOLDER_REGEX,
            function($m) use ($siteId) {
                $kumaMediaId = (int) $m[1];
                $craftAssetId = $this->resolveKumaMediaId($kumaMediaId);
                if ($craftAssetId !== null) {
                    return '{asset:' . $craftAssetId . '@' . $siteId . ':url}';
                }
                $outOfScopeReason = $this->outOfScopeKumaMediaReason($kumaMediaId);
                if ($outOfScopeReason !== null) {
                    $this->recordOutOfScopeDiagnostic('media', $kumaMediaId, $siteId, (string) $m[0], 'kuma_media:' . $kumaMediaId, $outOfScopeReason);
                    return (string) $m[0];
                }
                $this->recordUnresolvedDiagnostic('media', $kumaMediaId, $siteId, (string) $m[0], 'kuma_media:' . $kumaMediaId, 'no matching Craft asset id');
                return $m[0] . $this->unresolvedMarker('kuma_media:' . $kumaMediaId);
            },
            $html,
        ) ?? $html;
    }

    /**
     * Rewrite `[NT<id>]` Kunstmaan internal-link placeholders to Craft entry
     * ref tokens.
     */
    private function rewriteNodeTranslationPlaceholders(string $html, int $siteId): string
    {
        if (!str_contains($html, '[NT') && !str_contains($html, '%5BNT') && !str_contains($html, '%5bNT')) {
            return $html;
        }

        return preg_replace_callback(
            self::KUMA_NT_PLACEHOLDER_REGEX,
            function($m) use ($siteId) {
                $ntId = (int) $m[1];
                $craftEntryId = $this->resolveNodeTranslationId($ntId);
                if ($craftEntryId !== null) {
                    return '{entry:' . $craftEntryId . '@' . $siteId . ':url}';
                }
                $outOfScopeReason = $this->outOfScopeNodeTranslationReason($ntId);
                if ($outOfScopeReason !== null) {
                    $this->recordOutOfScopeDiagnostic('nt', $ntId, $siteId, (string) $m[0], 'kuma_node_translation:' . $ntId, $outOfScopeReason);
                    return (string) $m[0];
                }
                $this->recordUnresolvedDiagnostic('nt', $ntId, $siteId, (string) $m[0], 'kuma_node_translation:' . $ntId, 'no matching Craft entry id');
                return $m[0] . $this->unresolvedMarker('kuma_node_translation:' . $ntId);
            },
            $html,
        ) ?? $html;
    }

    /**
     * Rewrite `{{kuma:media:<id>}}` loader payload tokens to Craft asset ref
     * tokens. Reuses the exact same `kumaMediaIdCache`/`resolveKumaMediaId()`
     * plumbing as `rewriteMediaPlaceholders()` — only the surface regex
     * differs, since this is a distinct token grammar (see
     * KUMA_MEDIA_TOKEN_REGEX docblock).
     *
     * Public (Task 8 review Finding 1): `PayloadEntrySaver` calls this method
     * directly instead of the full `rewrite()` pipeline — the loader-contract
     * payload path only ever promises `{{kuma:media:<id>}}` rewriting, so it
     * must not also run `rewrite()`'s `[NT<id>]`/`[M<id>]` placeholder
     * resolution, raw `<img src="/uploads/media/...">` rewriting, or
     * `kma-*` class/empty-`<p>` stripping — those remain full-pipeline-only
     * concerns for whichever caller genuinely wants them (`rewrite()` itself,
     * still used by `MatrixHandler`/`PlainTextHandler`'s transform-stage
     * field handlers).
     */
    public function rewriteCurlyMediaTokens(string $html, int $siteId): string
    {
        if (!str_contains($html, '{{kuma:media:')) {
            return $html;
        }

        return preg_replace_callback(
            self::KUMA_MEDIA_TOKEN_REGEX,
            function($m) use ($siteId) {
                $kumaMediaId = (int) $m[1];
                $craftAssetId = $this->resolveKumaMediaId($kumaMediaId);
                if ($craftAssetId !== null) {
                    return '{asset:' . $craftAssetId . '@' . $siteId . ':url}';
                }
                $this->recordUnresolvedDiagnostic(
                    'media_token',
                    $kumaMediaId,
                    $siteId,
                    (string) $m[0],
                    'kuma_media:' . $kumaMediaId,
                    'no matching Craft asset id',
                );
                // Inert visible marker: `{{...}}` is not a Craft ref-tag
                // grammar (single braces), so the original token surviving
                // verbatim can never be mistaken for a resolved reference.
                return $m[0] . $this->unresolvedMarker('kuma_media:' . $kumaMediaId);
            },
            $html,
        ) ?? $html;
    }

    /**
     * Resolve kuma_media.id → Craft asset numeric id via state rows.
     * Cache warmed lazily, then reused across all rewrites this request.
     *
     * Phase 05.5-05 (D-05 / D-07): on cache miss, delegate to AssetResolver
     * so the asset materialises lazily. Positive hits are written back into
     * the rewriter's own per-request cache so subsequent lookups short-circuit
     * without re-entering the resolver. Misses are cached too (Task 8 review
     * Finding 2) so a genuinely broken id costs at most one resolver call
     * per request, however many times it recurs.
     */
    private function resolveKumaMediaId(int $kumaMediaId): ?int
    {
        if (!$this->kumaMediaCacheWarm) {
            $this->warmKumaMediaCacheFromState();
        }
        if (isset($this->kumaMediaIdCache[$kumaMediaId])) {
            return $this->kumaMediaIdCache[$kumaMediaId];
        }
        if (isset($this->kumaMediaIdMissCache[$kumaMediaId])) {
            return null;
        }
        if ($this->assetResolver === null) {
            return null;
        }
        $resolved = $this->assetResolver->resolveFromLegacyId($kumaMediaId);
        if ($resolved > 0) {
            return $this->kumaMediaIdCache[$kumaMediaId] = $resolved;
        }
        $this->kumaMediaIdMissCache[$kumaMediaId] = true;
        return null;
    }

    /**
     * Resolve kuma_node_translations.id → Craft entry numeric id via the
     * legacy DB (NT→node_id) plus state rows (node_id→targetId). Cache warmed
     * lazily. Handles news/cases/page (sourceKey is node_id) and singleton/team
     * (meta.kumaNodeId) transparently.
     */
    private function resolveNodeTranslationId(int $ntId): ?int
    {
        if (!$this->ntCacheWarm) {
            $this->warmNtCache();
        }
        return $this->ntToEntryCache[$ntId] ?? null;
    }

    private function warmKumaMediaCacheFromState(): void
    {
        if ($this->migrationState === null) {
            $this->kumaMediaCacheWarm = true;
            return;
        }

        foreach ($this->migrationState->all('media') as $row) {
            if (($row['targetType'] ?? null) !== 'asset' || empty($row['targetId'])) {
                continue;
            }
            $sourceKey = (string) ($row['sourceKey'] ?? '');
            if (!str_starts_with($sourceKey, 'kuma_media:')) {
                continue;
            }
            $kumaMediaId = (int) substr($sourceKey, strlen('kuma_media:'));
            if ($kumaMediaId > 0) {
                $this->kumaMediaIdCache[$kumaMediaId] = (int) $row['targetId'];
            }
        }
        $this->kumaMediaCacheWarm = true;
    }

    /**
     * Warm the `[NT<id>]` → Craft entry-id cache.
     *
     * Three inputs, and the mapping itself lives in the pure static below so
     * it can be tested without a database: the legacy ref→node lookup and the
     * node-translation rows both come from the legacy DB, the state rows come
     * through MigrationStateReader.
     */
    private function warmNtCache(): void
    {
        if ($this->migrationState === null || $this->legacyDb === null) {
            $this->ntCacheWarm = true;
            return;
        }

        // Bridge the two legacy id sequences: state.sourceKey is
        // kuma_node_versions.ref_id, while the NT join needs kuma_nodes.id.
        $refNodeRows = [];
        try {
            $refNodeRows = $this->legacyDb->queryAll(
                'SELECT DISTINCT t.node_id, v.ref_id, v.ref_entity_name'
                . ' FROM kuma_node_translations t'
                . ' JOIN kuma_node_versions v ON v.id = t.public_node_version_id'
                . ' WHERE t.public_node_version_id IS NOT NULL',
            );
        } catch (\Throwable $e) {
            $this->warn('could not load ref->node map: ' . $e->getMessage());
        }

        try {
            $ntRows = $this->legacyDb->queryAll(
                'SELECT id AS nt_id, node_id FROM kuma_node_translations WHERE node_id IS NOT NULL',
            );
            $this->ntToEntryCache += self::buildNtToEntryCacheFromRows(
                $this->stateEntryRows(),
                $refNodeRows,
                $ntRows,
            );
        } catch (\Throwable $e) {
            $this->warn('could not warm NT cache: ' . $e->getMessage());
        }

        $this->ntCacheWarm = true;
    }

    /**
     * Pure NT cache builder used by warmNtCache() and PHPUnit. It maps state rows
     * to node ids through both sourceKey/ref rows and decoded meta.kumaNodeId,
     * then maps node translations to Craft entry ids.
     *
     * @param list<array<string, mixed>> $stateRows
     * @param list<array<string, mixed>> $refNodeRows
     * @param list<array<string, mixed>> $ntRows
     * @return array<int, int>
     */
    private static function buildNtToEntryCacheFromRows(array $stateRows, array $refNodeRows, array $ntRows): array
    {
        $refToNodeMap = [];
        foreach ($refNodeRows as $r) {
            $fqcn = (string) ($r['ref_entity_name'] ?? $r['source'] ?? '');
            $refId = (int) ($r['ref_id'] ?? $r['sourceKey'] ?? 0);
            $nodeId = (int) ($r['node_id'] ?? $r['kumaNodeId'] ?? 0);
            if ($fqcn !== '' && $refId > 0 && $nodeId > 0) {
                $refToNodeMap[$fqcn][$refId] = $nodeId;
                $refToNodeMap[str_replace('\\', '_', $fqcn)][$refId] = $nodeId;
            }
        }

        $nodeIdToTargetId = [];
        foreach ($stateRows as $row) {
            if (($row['targetType'] ?? null) !== 'entry' || empty($row['targetId'])) {
                continue;
            }
            $source = (string) ($row['source'] ?? '');
            $fqcn = str_replace('_', '\\', $source);
            $targetId = (int) $row['targetId'];
            $sourceKey = (int) ($row['sourceKey'] ?? 0);
            $mappedNodeId = $sourceKey > 0
                ? (int) ($refToNodeMap[$fqcn][$sourceKey] ?? $refToNodeMap[$source][$sourceKey] ?? 0)
                : 0;
            if ($mappedNodeId > 0) {
                $nodeIdToTargetId[$mappedNodeId] = $targetId;
            } elseif ($sourceKey > 0) {
                $nodeIdToTargetId[$sourceKey] = $targetId;
            }
            $meta = $row['meta'] ?? null;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            if (is_array($meta)) {
                $metaNodeId = (int) ($meta['kumaNodeId'] ?? 0);
                if ($metaNodeId > 0) {
                    $nodeIdToTargetId[$metaNodeId] = $targetId;
                }
            }
        }

        $out = [];
        foreach ($ntRows as $r) {
            $ntId = (int) ($r['nt_id'] ?? $r['id'] ?? 0);
            $nodeId = (int) ($r['node_id'] ?? 0);
            if ($ntId > 0 && isset($nodeIdToTargetId[$nodeId])) {
                $out[$ntId] = $nodeIdToTargetId[$nodeId];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Every entry-producing state row.
     *
     * @return list<array<string, mixed>>
     */
    private function stateEntryRows(): array
    {
        if ($this->migrationState === null) {
            return [];
        }

        return iterator_to_array($this->migrationState->entryRows(), false);
    }

    /**
     * Log a warning when Craft is there to log it.
     *
     * Warming runs under PHPUnit as well as under a console command, and the
     * logger is the only part of these paths that genuinely needs Craft — so
     * the check belongs here rather than in front of the work.
     */
    private function warn(string $message): void
    {
        if (class_exists(Craft::class, false)) {
            Craft::warning('CkeditorRewriterService: ' . $message, __METHOD__);
        }
    }

    private function recordUnresolvedDiagnostic(string $family, int $legacyId, int $siteId, string $token, string $source, string $reason): void
    {
        $this->unresolvedDiagnostics[] = [
            'tokenFamily' => $family,
            'legacyId' => $legacyId,
            'siteId' => $siteId,
            'token' => $token,
            'source' => $source,
            'reason' => $reason,
        ];
    }

    private function recordOutOfScopeDiagnostic(string $family, int $legacyId, int $siteId, string $token, string $source, string $reason): void
    {
        $this->outOfScopeDiagnostics[] = [
            'tokenFamily' => $family,
            'legacyId' => $legacyId,
            'siteId' => $siteId,
            'token' => $token,
            'source' => $source,
            'reason' => $reason,
        ];
    }

    private function outOfScopeNodeTranslationReason(int $ntId): ?string
    {
        if (isset($this->outOfScopeNtReasons[$ntId])) {
            return $this->outOfScopeNtReasons[$ntId];
        }
        if ($this->legacyDb === null) {
            return null;
        }

        try {
            $row = $this->legacyDb->queryOne(
                'SELECT t.online AS translation_online, n.deleted AS node_deleted'
                . ' FROM kuma_node_translations t'
                . ' LEFT JOIN kuma_nodes n ON n.id = t.node_id'
                . ' WHERE t.id = :id LIMIT 1',
                [':id' => $ntId],
            );
        } catch (\Throwable $e) {
            $this->warn('could not classify NT scope: ' . $e->getMessage());
            return null;
        }

        if (!is_array($row)) {
            return null;
        }
        if ((int) ($row['node_deleted'] ?? 0) === 1) {
            return 'legacy node translation points to a deleted node; classified outside live page-rooted scope';
        }
        if ((int) ($row['translation_online'] ?? 1) === 0) {
            return 'legacy node translation is offline; classified outside live page-rooted scope';
        }

        return null;
    }

    private function outOfScopeKumaMediaReason(int $mediaId): ?string
    {
        if (isset($this->outOfScopeMediaReasons[$mediaId])) {
            return $this->outOfScopeMediaReasons[$mediaId];
        }
        if ($this->legacyDb === null) {
            return null;
        }

        try {
            $row = $this->legacyDb->queryOne(
                'SELECT content_type, url FROM kuma_media WHERE id = :id LIMIT 1',
                [':id' => $mediaId],
            );
        } catch (\Throwable $e) {
            $this->warn('could not classify kuma_media scope: ' . $e->getMessage());
            return null;
        }

        if (!is_array($row)) {
            return null;
        }
        $contentType = strtolower((string) ($row['content_type'] ?? ''));
        $extension = strtolower((string) pathinfo((string) ($row['url'] ?? ''), PATHINFO_EXTENSION));
        if ($contentType === 'text/html' || in_array($extension, ['htm', 'html'], true)) {
            return 'legacy media row is HTML, not a Craft asset import target; classified outside asset scope';
        }

        return null;
    }

    private function rewriteAssetAttributes(string $html, int $siteId): string
    {
        // Match <img src="..."> and <a href="..."> where the URL contains /uploads/media/
        $pattern = '#(src|href)=(["\'])([^"\']*?' . preg_quote(self::LEGACY_MEDIA_URL_PREFIX, '#') . '[^"\']+)\2#i';

        return preg_replace_callback($pattern, function($m) use ($siteId) {
            $attr = $m[1];
            $quote = $m[2];
            $url = $m[3];

            $assetId = $this->resolveMediaIdForUrl($url);

            if ($assetId !== null) {
                $refToken = '{asset:' . $assetId . '@' . $siteId . ':url}';
                return $attr . '=' . $quote . $refToken . $quote;
            }

            // Unresolved — emit a marker comment adjacent to the attribute so
            // editors and the check action can grep the raw HTML.
            $diagnosticUrl = $this->diagnosticMediaUrlToken($url);
            $this->recordUnresolvedDiagnostic(
                'media_url',
                0,
                $siteId,
                $diagnosticUrl,
                $diagnosticUrl,
                'no matching Craft asset id for legacy media URL',
            );
            $marker = $this->unresolvedMarker($url);
            return $attr . '=' . $quote . $url . $quote . $marker;
        }, $html) ?? $html;
    }

    private function diagnosticMediaUrlToken(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = preg_replace('/[?#].*$/', '', $url) ?? $url;
        }

        $path = preg_replace('/[\x00-\x1F\x7F`|<>]/', '?', $path) ?? $path;

        if (strlen($path) > 255) {
            return substr($path, 0, 252) . '...';
        }

        return $path;
    }

    private function stripUnresolvedMarkers(string $html): string
    {
        return preg_replace('/<!-- MIGRATION:UNRESOLVED sourceB64=[A-Za-z0-9_-]+ -->/', '', $html) ?? $html;
    }

    /**
     * Build a comment-safe unresolved marker.
     *
     * Raw legacy URLs can contain HTML-comment delimiters (`-->`) or markup-like
     * text. Keep the raw value only in the original attribute/literal and encode
     * the marker payload using unpadded base64url so it never emits comment or
     * attribute delimiters such as `+`, `/`, `=`, `<`, `>`, or `--`.
     */
    private function unresolvedMarker(string $source): string
    {
        $sourceB64 = rtrim(strtr(base64_encode($source), '+/', '-_'), '=');

        return '<!-- MIGRATION:UNRESOLVED sourceB64=' . $sourceB64 . ' -->';
    }

    /**
     * @param array<string, int> $entryUrlToId
     */
    private function rewriteEntryLinks(string $html, int $siteId, array $entryUrlToId): string
    {
        return preg_replace_callback(
            '#href=(["\'])(/[^"\']+)\1#i',
            function($m) use ($siteId, $entryUrlToId) {
                $quote = $m[1];
                $url = $m[2];
                // Skip media URLs — already handled by rewriteAssetAttributes
                if (str_contains($url, self::LEGACY_MEDIA_URL_PREFIX)) {
                    return $m[0];
                }
                if (isset($entryUrlToId[$url])) {
                    $refToken = '{entry:' . $entryUrlToId[$url] . '@' . $siteId . ':url}';
                    return 'href=' . $quote . $refToken . $quote;
                }
                return $m[0];
            },
            $html,
        ) ?? $html;
    }

    private function stripKumaClasses(string $html): string
    {
        return preg_replace_callback(
            '#\s?class=(["\'])([^"\']*)\1#i',
            function($m) {
                $quote = $m[1];
                $classes = preg_split('/\s+/', trim($m[2])) ?: [];
                $filtered = array_values(array_filter(
                    $classes,
                    static fn($c) => $c !== '' && !str_starts_with($c, 'kma-'),
                ));
                if (empty($filtered)) {
                    // Drop the class attribute entirely (including the leading space)
                    return '';
                }
                return ' class=' . $quote . implode(' ', $filtered) . $quote;
            },
            $html,
        ) ?? $html;
    }

    private function removeEmptyParagraphs(string $html): string
    {
        // <p></p>, <p> </p>, <p>&nbsp;</p>, <p><br></p>
        return preg_replace(
            '#<p[^>]*>\s*(?:&nbsp;|<br\s*/?>)?\s*</p>#i',
            '',
            $html,
        ) ?? $html;
    }

    /**
     * Resolve a Craft Asset numeric id for a legacy URL by scanning state meta.
     * Cache is warmed once per request; tests pre-populate via seedUrlIdCache.
     */
    private function resolveMediaIdForUrl(string $url): ?int
    {
        if (!$this->urlCacheWarm) {
            $this->warmUrlCacheFromState();
        }

        // Try exact match first, then stripped (query/fragment removed)
        if (isset($this->urlIdCache[$url])) {
            return $this->urlIdCache[$url];
        }
        $stripped = preg_replace('/[?#].*$/', '', $url) ?? $url;
        if (isset($this->urlIdCache[$stripped])) {
            return $this->urlIdCache[$stripped];
        }

        $kumaMediaId = $this->resolveKumaMediaIdForUrl($stripped);

        if ($kumaMediaId === null) {
            return $this->resolveByLegacyUrl($stripped);
        }

        $assetId = $this->resolveKumaMediaId($kumaMediaId);

        if ($assetId === null) {
            // Finding a legacy media id and then failing to map it to an asset is NOT a dead end,
            // and used to be treated as one: the fallback below only ran when the legacy-database
            // lookup failed outright. That made it a workaround for a missing legacy connection
            // rather than for a missing mapping — so once `legacyDb` was correctly wired for the
            // finalize pass, the lookup started succeeding and this path started returning null,
            // silently, for every image.
            //
            // The id→asset cache it consults is warmed only from state rows keyed `kuma_media:`,
            // and AssetMigrationService writes them keyed `legacy_url:sha1(path)` — on the Enreach
            // corpus, 978 of the latter and none of the former. So that cache is empty by
            // construction and this branch is the normal case, not the exception.
            return $this->resolveByLegacyUrl($stripped);
        }

        $this->urlIdCache[$stripped] = $assetId;
        $this->urlIdCache['/' . ltrim($stripped, '/')] = $assetId;
        return $assetId;
    }

    private function resolveKumaMediaIdForUrl(string $url): ?int
    {
        $key = $this->normalizeMediaUrlLookupKey($url);
        if (isset($this->urlToKumaMediaIdCache[$key])) {
            return $this->urlToKumaMediaIdCache[$key] > 0 ? $this->urlToKumaMediaIdCache[$key] : null;
        }

        if ($this->legacyDb === null) {
            return null;
        }

        $candidates = array_values(array_unique([
            $key,
            '/' . ltrim($key, '/'),
            rawurldecode($key),
            '/' . ltrim(rawurldecode($key), '/'),
        ]));

        $placeholders = [];
        $params = [];
        foreach ($candidates as $i => $candidate) {
            $name = ':url' . $i;
            $placeholders[] = $name;
            $params[$name] = $candidate;
        }

        $row = $this->legacyDb->queryOne(
            'SELECT id FROM kuma_media WHERE url IN (' . implode(', ', $placeholders) . ') ORDER BY deleted ASC, id ASC LIMIT 1',
            $params,
        );
        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        $this->urlToKumaMediaIdCache[$key] = $id;

        return $id > 0 ? $id : null;
    }

    private function normalizeMediaUrlLookupKey(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = preg_replace('/[?#].*$/', '', $url) ?? $url;
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * Last resort: ask the asset resolver to map the legacy URL itself.
     *
     * It hashes the path to `legacy_url:sha1(path)`, which is the key AssetMigrationService
     * actually writes, so it finds rows the id-keyed cache cannot.
     */
    private function resolveByLegacyUrl(string $strippedUrl): ?int
    {
        if ($this->assetResolver === null || !method_exists($this->assetResolver, 'resolveFromLegacyUrl')) {
            return null;
        }

        $resolved = $this->assetResolver->resolveFromLegacyUrl($strippedUrl);

        if ($resolved <= 0) {
            return null;
        }

        $this->urlIdCache[$strippedUrl] = $resolved;
        $this->urlIdCache['/' . ltrim($strippedUrl, '/')] = $resolved;

        return $resolved;
    }

    private function warmUrlCacheFromState(): void
    {
        if ($this->migrationState === null) {
            $this->urlCacheWarm = true;
            return;
        }

        foreach ($this->migrationState->all('media') as $row) {
            $meta = $row['meta'] ?? null;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            if (!is_array($meta) || empty($meta['originalUrl'])) {
                continue;
            }
            if (($row['targetType'] ?? null) !== 'asset' || empty($row['targetId'])) {
                continue;
            }
            $key = '/' . ltrim((string) $meta['originalUrl'], '/');
            $this->urlIdCache[$key] = (int) $row['targetId'];
        }
        $this->urlCacheWarm = true;
    }
}
