<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\finalize;

use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use Craft;
use yii\base\Component;
use yii\db\Query;

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
 * Unresolvable asset refs keep the original URL + HTML comment marker
 *   <!-- MIGRATION:UNRESOLVED source=... -->
 * so editors can grep. HTML Purifier strips comments on save, so rendered
 * pages won't leak them — Plan 09's `kunstmaan-migrator/migrate/check` scans
 * the raw DB column BEFORE the save-pipeline runs through Purifier.
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
     * MigrationStateService — injected by the module. Null-safe: when absent
     * (e.g., tests without a container), cache-warming methods short-circuit.
     */
    public ?MigrationStateService $migrationState = null;

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

    /** @var array<int, int> kuma_media.id → Craft asset numeric id */
    private array $kumaMediaIdCache = [];

    private bool $kumaMediaCacheWarm = false;

    /** @var array<int, int> kuma_node_translations.id → Craft entry numeric id */
    private array $ntToEntryCache = [];

    private bool $ntCacheWarm = false;

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

        // Step 1 — rewrite <img src="/uploads/media/..."> and <a href="/uploads/media/...">
        $html = $this->rewriteAssetAttributes($html, $siteId);

        // Step 1b — rewrite Kunstmaan `[M<id>]` media placeholders (appear in
        // the raw href/data-href of CKEditor-plugin-generated media links).
        $html = $this->rewriteMediaPlaceholders($html, $siteId);

        // Step 1c — rewrite Kunstmaan `[NT<id>]` internal-link placeholders.
        // NT<id> = kuma_node_translations.id → kuma_nodes → migrated entry.
        $html = $this->rewriteNodeTranslationPlaceholders($html, $siteId);

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
            function ($m) use ($siteId) {
                $kumaMediaId = (int) $m[1];
                $craftAssetId = $this->resolveKumaMediaId($kumaMediaId);
                if ($craftAssetId !== null) {
                    return '{asset:' . $craftAssetId . '@' . $siteId . ':url}';
                }
                return $m[0] . '<!-- MIGRATION:UNRESOLVED source=kuma_media:' . $kumaMediaId . ' -->';
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
            function ($m) use ($siteId) {
                $ntId = (int) $m[1];
                $craftEntryId = $this->resolveNodeTranslationId($ntId);
                if ($craftEntryId !== null) {
                    return '{entry:' . $craftEntryId . '@' . $siteId . ':url}';
                }
                return $m[0] . '<!-- MIGRATION:UNRESOLVED source=kuma_node_translation:' . $ntId . ' -->';
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
     * without re-entering the resolver.
     */
    private function resolveKumaMediaId(int $kumaMediaId): ?int
    {
        if (!$this->kumaMediaCacheWarm) {
            $this->warmKumaMediaCacheFromState();
        }
        if (isset($this->kumaMediaIdCache[$kumaMediaId])) {
            return $this->kumaMediaIdCache[$kumaMediaId];
        }
        if ($this->assetResolver === null) {
            return null;
        }
        $resolved = $this->assetResolver->resolveFromLegacyId($kumaMediaId);
        if ($resolved > 0) {
            return $this->kumaMediaIdCache[$kumaMediaId] = $resolved;
        }
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
        if (!class_exists(Craft::class, false) || $this->migrationState === null) {
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

    private function warmNtCache(): void
    {
        if (!class_exists(Craft::class, false) || $this->migrationState === null) {
            $this->ntCacheWarm = true;
            return;
        }
        // Build kuma_nodes.id → targetEntryId map by walking state rows.
        //
        // state.sourceKey = kuma_node_versions.ref_id (entity-table PK), which is
        // a DIFFERENT sequence from kuma_nodes.id. The NT join at the bottom of
        // this method uses kuma_node_translations.node_id = kuma_nodes.id, so we
        // need to bridge the two via a legacy-DB lookup table:
        //   ref_entity_name + ref_id  →  kuma_nodes.id
        //
        // For non-node-backed sources (singletons): meta carries kumaNodeId
        // as before.
        $nodeIdToTargetId = [];

        // Pre-load legacy ref_id+class → kuma_nodes.id map (one query).
        $refToNodeMap = []; // ['FQCN'][$refId] = $nodeId
        try {
            $refNodeRows = $this->legacyDb->queryAll(
                'SELECT DISTINCT t.node_id, v.ref_id, v.ref_entity_name'
                . ' FROM kuma_node_translations t'
                . ' JOIN kuma_node_versions v ON v.id = t.public_node_version_id'
                . ' WHERE t.public_node_version_id IS NOT NULL',
            );
            foreach ($refNodeRows as $r) {
                $fqcn  = (string) ($r['ref_entity_name'] ?? '');
                $refId = (int) ($r['ref_id'] ?? 0);
                $nid   = (int) ($r['node_id'] ?? 0);
                if ($fqcn !== '' && $refId > 0 && $nid > 0) {
                    $refToNodeMap[$fqcn][$refId] = $nid;
                }
            }
        } catch (\Throwable $e) {
            Craft::warning(
                'CkeditorRewriterService: could not load ref->node map: ' . $e->getMessage(),
                __METHOD__,
            );
        }

        $sources = array_column(
            (new Query())
                ->select('source')
                ->distinct()
                ->from('{{%kunstmaanmigrator_state}}')
                ->where(['targetType' => 'entry'])
                ->all(),
            'source',
        );

        foreach ($sources as $source) {
            // Convert state source slug (underscores) back to FQCN (backslashes)
            // so it matches ref_entity_name in kuma_node_versions.
            $fqcn = str_replace('_', '\\', $source);

            foreach ($this->migrationState->all($source) as $row) {
                if (($row['targetType'] ?? null) !== 'entry' || empty($row['targetId'])) {
                    continue;
                }
                $targetId  = (int) $row['targetId'];
                $sourceKey = (int) ($row['sourceKey'] ?? 0);

                // Primary path: resolve kuma_nodes.id via the ref_id+class lookup.
                $kumaNodeId = ($sourceKey > 0) ? ($refToNodeMap[$fqcn][$sourceKey] ?? 0) : 0;
                if ($kumaNodeId > 0) {
                    $nodeIdToTargetId[$kumaNodeId] = $targetId;
                } elseif ($sourceKey > 0) {
                    // Fallback: for sources not in refToNodeMap (e.g. singletons
                    // whose sourceKey might already be a kuma_nodes.id), store as-is.
                    $nodeIdToTargetId[$sourceKey] = $targetId;
                }

                // Secondary fallback: meta.kumaNodeId (singleton/team legacy case).
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
        }

        if (empty($nodeIdToTargetId)) {
            $this->ntCacheWarm = true;
            return;
        }

        // Join NT rows from legacy DB to get nt.id → node_id
        try {
            $ntRows = $this->legacyDb->queryAll(
                'SELECT id AS nt_id, node_id FROM kuma_node_translations WHERE node_id IS NOT NULL',
            );
            foreach ($ntRows as $r) {
                $ntId = (int) ($r['nt_id'] ?? 0);
                $nodeId = (int) ($r['node_id'] ?? 0);
                if ($ntId > 0 && isset($nodeIdToTargetId[$nodeId])) {
                    $this->ntToEntryCache[$ntId] = $nodeIdToTargetId[$nodeId];
                }
            }
        } catch (\Throwable $e) {
            Craft::warning(
                'CkeditorRewriterService: could not warm NT cache: ' . $e->getMessage(),
                __METHOD__,
            );
        }

        $this->ntCacheWarm = true;
    }

    private function rewriteAssetAttributes(string $html, int $siteId): string
    {
        // Match <img src="..."> and <a href="..."> where the URL contains /uploads/media/
        $pattern = '#(src|href)=(["\'])([^"\']*?' . preg_quote(self::LEGACY_MEDIA_URL_PREFIX, '#') . '[^"\']+)\2#i';

        return preg_replace_callback($pattern, function ($m) use ($siteId) {
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
            $marker = '<!-- MIGRATION:UNRESOLVED source=' . $url . ' -->';
            return $attr . '=' . $quote . $url . $quote . $marker;
        }, $html) ?? $html;
    }

    /**
     * @param array<string, int> $entryUrlToId
     */
    private function rewriteEntryLinks(string $html, int $siteId, array $entryUrlToId): string
    {
        return preg_replace_callback(
            '#href=(["\'])(/[^"\']+)\1#i',
            function ($m) use ($siteId, $entryUrlToId) {
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
            function ($m) {
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
        return $this->urlIdCache[$stripped] ?? null;
    }

    private function warmUrlCacheFromState(): void
    {
        // Defensive: if Craft isn't bootstrapped (unit tests that forgot to
        // seed), skip the scan rather than fatal.
        if (!class_exists(Craft::class, false) || $this->migrationState === null) {
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
