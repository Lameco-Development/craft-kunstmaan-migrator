<?php

namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\craft\CraftElementWriter;
use lameco\kunstmaanmigrator\craft\ElementWriter;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use yii\db\Query;
use Craft;
use craft\elements\Entry;
use yii\base\Component;

/**
 * SeoMigrationService — writes the SEOmatic `seo` field on every migrated
 * entry per site, after assets exist (so og_image / twitter_image references
 * resolve via the state map).
 *
 * Per DEC-17, this runs LAST in the migration sequence so:
 *   - all kuma_seo image references can resolve to migrated legacyMedia
 *     asset ids via state lookup ('media:kuma_media:<id>')
 *   - all entries (news / cases / team / contentPages / singletons) already
 *     exist so the per-site Entry::find()->id($targetId)->siteId(...)
 *     reload is guaranteed to return a real Entry object
 *
 * Per RESEARCH.md §2 + Pitfall 2, every per-site save passes
 * `propagateChanges=false` and re-loads the entry scoped to the target site
 * before writing the second site.
 *
 * Optional-plugin gate (CONFIG-08): If SEOmatic is not installed, the
 * entire pass is skipped with a warning — never a hard error. Consuming
 * projects that don't use SEOmatic simply see the skip log line.
 *
 * Table-name override: the legacy SEO table defaults to the canonical
 * `kuma_seo` name but is exposed as `$seoTableName` so host projects with a
 * different Kunstmaan schema flavour can override via Settings::$seoTableName.
 */
class SeoMigrationService extends Component
{
    public LegacyDbService $legacyDb;
    public MigrationStateService $stateService;
    public SeomaticPayloadBuilder $seoPayload;

    /**
     * Legacy SEO table name (unwrapped — passed verbatim into raw SQL).
     * Defaults to the canonical Kunstmaan `kuma_seo`; host projects with
     * a non-standard schema override via Settings::$seoTableName. Plan 04-09
     * config wiring populates this from the plugin Settings.
     */
    public string $seoTableName = 'kuma_seo';

    /**
     * D-08-19 — Kunstmaan locale → Craft site handle map. v2 wires this
     * from `Plugin::resolveSitesMap()` in `Plugin::init()` (the same map
     * already feeds `EntryMigrationService::$sites`); v1 sourced it from a
     * mapping.yaml top-level `sites:` block — that block is gone in v2.
     *
     * Drives the per-site fan-out below: every Craft site whose handle
     * appears in this map gets a SEOmatic write per migrated entry, with
     * default-locale fallback when the site's own locale row is missing
     * from kuma_seo.
     *
     * Empty by default so the service degrades gracefully (the fan-out
     * short-circuits with a WARN if the operator hasn't wired locales yet).
     *
     * @var array<string, string>
     */
    public array $sites = [];

    private const STATE_SOURCE = 'seo_meta';
    private const SEO_FIELD_HANDLE = 'seo';

    /**
     * Whether this entry already holds SEO worth clearing on this site.
     *
     * Reads the stored value rather than the rendered one: an empty SEOmatic bundle still
     * normalises into a full object of defaults, so "is there anything here" has to be asked
     * of the two fields a migration ever writes.
     */
    private function hasStoredSeo(Entry $entry): bool
    {
        try {
            $value = $entry->getSerializedFieldValues([self::SEO_FIELD_HANDLE])[self::SEO_FIELD_HANDLE] ?? null;
        } catch (\Throwable) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        $vars = $value['metaGlobalVars'] ?? [];

        return trim((string) ($vars['seoTitle'] ?? '')) !== ''
            || trim((string) ($vars['seoDescription'] ?? '')) !== '';
    }

    /**
     * D-08-19 — build the per-site list to fan out to. Each entry is
     * `{siteId, siteHandle, locale}`. Sites not present in $this->sites
     * are skipped (with a WARN logged into MigrationReport by the caller).
     *
     * @return list<array{siteId: int, siteHandle: string, locale: string}>
     */
    private function buildSiteList(MigrationReport $report): array
    {
        $out = [];

        // D-08-19: drive the loop from Craft::$app->sites->getAllSites()
        // and resolve each site's locale via the sites: block. Sites not
        // mapped are explicit warnings (operator misconfig).
        foreach (Craft::$app->sites->getAllSites() as $site) {
            $handle = (string) $site->handle;
            $locale = array_search($handle, $this->sites, true);
            if ($locale === false) {
                $report->warn(sprintf(
                    'SEO: Craft site handle="%s" not present in mapping.yaml sites: block; skipping site.',
                    $handle,
                ));
                continue;
            }
            $out[] = [
                'siteId'     => (int) $site->id,
                'siteHandle' => $handle,
                'locale'     => (string) $locale,
            ];
        }
        return $out;
    }

    /**
     * Walk every state row whose target is a Craft entry and write the
     * SEOmatic `seo` field per site. Returns a MigrationReport with counts
     * + warnings for any unresolved rows.
     *
     * CONFIG-08: if SEOmatic is not installed the pass is skipped with a
     * warning — never a hard error.
     */
    /**
     * The seam at Craft's element writes. Wired in Plugin::init(); read
     * through elements() so no call site has to cope with "not wired yet".
     */
    public ?ElementWriter $elementWriter = null;

    private function elements(): ElementWriter
    {
        return $this->elementWriter ??= new CraftElementWriter();
    }

    public function migrateAll(MigrationOptions $opts): MigrationReport
    {
        $report = new MigrationReport();

        // Phase 4.1 / D-25 — settings-disabled gate runs FIRST, BEFORE the
        // plugin-presence check. D-27: warn-line copy is distinct from
        // plugin-not-installed so REPORT.md skipped-stages aggregation can
        // distinguish "operator opted out" from "plugin unavailable".
        if (!Plugin::getInstance()->getSettings()->seoEnabled) {
            Craft::info(
                'SEOmatic adapter explicitly disabled via Settings::seoEnabled; skipping SEO migration pass.',
                'kunstmaanmigrator',
            );
            $report->warn(self::disabledWarnLine());
            return $report;
        }

        // CONFIG-08: SEOmatic is optional. If the plugin is not installed,
        // skip the entire SEO migration pass with a warning.
        if (Craft::$app->plugins->getPlugin('seomatic') === null) {
            Craft::warning(
                'SEOmatic plugin not installed; skipping SEO migration pass.',
                'kunstmaanmigrator',
            );
            $report->warn('SEOmatic plugin not installed; SEO migration skipped.');
            return $report;
        }

        // D-08-19 — per-site fan-out driven by Plugin::resolveSitesMap().
        $siteList = $this->buildSiteList($report);
        if ($siteList === []) {
            $report->warn('No Craft sites mapped; SEO migration aborted.');
            return $report;
        }

        /** @var array<int, string> $siteLocales */
        $siteLocales = [];
        $siteIds = [];
        foreach ($siteList as $entry) {
            $siteLocales[$entry['siteId']] = $entry['locale'];
            $siteIds[] = $entry['siteId'];
        }

        // Discover actual entry sources from the state table rather than
        // relying on hardcoded aliases. EntryMigrationService writes state
        // rows keyed by FQCN-derived source names (e.g. App_Entity_Pages_NewsPage),
        // which don't match the legacy section-alias list (news/cases/team/etc.).
        // Querying the state table keeps SEO source-list-agnostic and works
        // for any project regardless of mapping.yaml naming conventions.
        //
        // Exclude `seo_meta` — this service itself records state rows under
        // that source (source='seo_meta', targetType='entry') after each
        // successful per-site SEO save. Including it here would cause a
        // second pass with meta=null (no refIdsByLocale) that would overwrite
        // the just-written per-locale SEO with empty payloads.
        $sources = array_column(
            (new Query())
                ->select('source')
                ->distinct()
                ->from('{{%kunstmaanmigrator_state}}')
                ->where(['targetType' => 'entry'])
                ->andWhere(['not in', 'source', [self::STATE_SOURCE]])
                ->all(),
            'source',
        );

        // Respect --fqcns from MigrateController: when the operator scoped the
        // load to one or more legacy FQCNs, only process matching sources.
        // State `source` values are FQCN-derived slugs (backslashes → underscores),
        // so compare against the slug form.
        if ($opts->legacyClassFilter !== null && $opts->legacyClassFilter !== []) {
            $allowedSources = array_map(
                static fn(string $fqcn): string => str_replace('\\', '_', $fqcn),
                $opts->legacyClassFilter,
            );
            $sources = array_values(array_intersect($sources, $allowedSources));
        }

        $rowCount = 0;
        foreach ($sources as $source) {
            foreach ($this->stateService->all($source) as $stateRow) {
                $entryId = (int) ($stateRow['targetId'] ?? 0);
                $sourceKey = (string) ($stateRow['sourceKey'] ?? '');
                if ($entryId === 0 || ($stateRow['targetType'] ?? '') !== 'entry') {
                    continue;
                }

                try {
                    $written = $this->migrateForEntryInternal(
                        $entryId,
                        $source,
                        $sourceKey,
                        $stateRow['meta'] ?? null,
                        $siteIds,
                        $siteLocales,
                        $opts,
                        $report,
                    );
                    if ($written > 0) {
                        $report->incr('updated', $written);
                    } else {
                        $report->incr('skipped');
                    }
                } catch (\Throwable $e) {
                    $report->incr('failed');
                    $report->warn(
                        sprintf(
                            'seo migrate failed for %s:%s entryId=%d — %s',
                            $source,
                            $sourceKey,
                            $entryId,
                            $e->getMessage(),
                        ),
                    );
                }

                if ((++$rowCount % 50) === 0) {
                    $this->elements()->invalidateCaches();
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }
        }

        return $report;
    }

    // --------------------------------------------------------------------------
    // Private — core per-entry write
    // --------------------------------------------------------------------------

    /**
     * @param array<int> $siteIds
     * @param array<int, string> $siteLocales siteId → legacy locale code ('nl','en')
     * @param array<string, mixed>|string|null $meta
     */
    private function migrateForEntryInternal(
        int $entryId,
        string $source,
        string $sourceKey,
        mixed $meta,
        array $siteIds,
        array $siteLocales,
        MigrationOptions $opts,
        MigrationReport $report,
        ?array $directRefIdsByLocale = null,
    ): int {
        // Resolve legacy ref_entity_name + canonical ref_id (used as the fallback
        // when meta.refIdsByLocale is missing, e.g. for older state rows).
        [$legacyClass, $legacyEntityId] = $this->resolveLegacyRef($source, $sourceKey, $meta);
        if ($legacyClass === null || $legacyEntityId === 0) {
            $report->warn(
                sprintf(
                    'seo: unresolved legacy ref for %s:%s entryId=%d (meta missing legacyClass/legacyEntityId)',
                    $source,
                    $sourceKey,
                    $entryId,
                ),
            );
            return 0;
        }

        // Per-locale ref_id map. $directRefIdsByLocale lets a caller bypass
        // the state-meta read when it already has the value in hand (e.g.
        // from the same DB transaction that wrote it, before it's visible
        // to a streaming cursor). No current caller passes one; migrateAll()
        // always falls back to whatever was persisted in meta.
        $metaArr = is_array($meta) ? $meta : (is_string($meta) ? (array) (json_decode($meta, true) ?? []) : []);
        $refIdsByLocale = $directRefIdsByLocale !== null && $directRefIdsByLocale !== []
            ? $directRefIdsByLocale
            : (array) ($metaArr['refIdsByLocale'] ?? []);

        // D-08-19 — pre-fetch every per-locale kuma_seo row.
        //
        // refIdsByLocale is the authoritative per-locale ref_id map written by
        // ExtractService via LegacyDbService::translationsFor. It's the ONLY
        // valid source for deciding which kuma_seo row to copy into each site.
        //
        // No fallback: the migrator's job is to migrate source data as-is,
        // never to invent content. If a locale has no ref_id mapping (the
        // whole entry wasn't translated on the Kunstmaan side, or state meta
        // is stale), we write an empty payload for that site rather than
        // collapsing to a shared ref_id — which would silently leak the
        // primary locale's SEO into every other site.
        /** @var array<string, array<string, mixed>|null> $seoByLocale */
        $seoByLocale = [];
        foreach ($siteLocales as $loc) {
            if (isset($seoByLocale[$loc])) {
                continue;
            }
            $seoByLocale[$loc] = isset($refIdsByLocale[$loc])
                ? $this->fetchKumaSeoRow((int) $refIdsByLocale[$loc], $legacyClass)
                : null;
        }

        $written = 0;
        $skippedEmpty = 0;
        foreach ($siteIds as $siteId) {
            $locale = $siteLocales[$siteId] ?? null;
            if ($locale === null) {
                continue;
            }

            // D-08-19 — use the per-locale SEO row. When null (no legacy SEO
            // for this locale), pass null to the payload builder so it writes
            // an explicit empty payload — this clears any NL-propagated
            // content that Craft copies to non-primary sites during the initial
            // entry save. Skipping silently would leave the NL description in
            // all sites that have no Kunstmaan SEO row.
            $seoRow = $seoByLocale[$locale] ?? null;

            $entry = Entry::find()
                ->id($entryId)
                ->siteId($siteId)
                ->status(null)
                ->one();
            if ($entry === null) {
                // Site disabled for this entry — skip silently.
                continue;
            }

            // Verify the entry has a SEOmatic field at handle 'seo' before
            // writing — defensive check for entry types without SEO.
            try {
                $field = $entry->getFieldLayout()?->getFieldByHandle(self::SEO_FIELD_HANDLE);
            } catch (\Throwable) {
                $field = null;
            }
            if ($field === null) {
                continue;
            }

            if ($opts->dryRun) {
                $written++;
                continue;
            }

            $payload = $this->seoPayload->build($seoRow, $siteId);

            // A locale with no legacy SEO row gets an explicit empty payload, to clear
            // anything Craft propagated from the primary site during the entry save. That is
            // worth a full `saveElement()` when there is something to clear, and pure waste
            // when there is not — and there usually is not: on the first real corpus 81% of
            // these writes were empty-over-empty, ~11,500 of COM's 14,000, each one the most
            // expensive call available. It put the SEO pass on a fifteen-hour trajectory.
            if ($seoRow === null && !$this->hasStoredSeo($entry)) {
                $skippedEmpty++;
                continue;
            }

            $entry->setFieldValue(self::SEO_FIELD_HANDLE, $payload);

            // SEOmatic's SeoSettings field normalizeValue pulls `metaSiteVars`
            // defaults (siteName, identity, creator, referrer, …) from
            // `Craft::$app->sites->getCurrentSite()`, not from the Entry's
            // `$siteId`. During a CLI load, current site stays fixed on the
            // primary site (NL) for the whole process, so every per-site SEO
            // save ends up with NL's siteName/identity baked into the content
            // JSON — even for EN. Temporarily switch the current site to match
            // the target and restore after each save to avoid leaking NL
            // defaults into the EN SEO bundle.
            $previousSite = Craft::$app->sites->getCurrentSite();
            $targetSite = Craft::$app->sites->getSiteById($siteId);
            if ($targetSite !== null) {
                Craft::$app->sites->setCurrentSite($targetSite);
            }
            try {
                $entry->resaving = true;
                $saved = $this->elements()->save($entry);
            } finally {
                Craft::$app->sites->setCurrentSite($previousSite);
            }

            // Pitfall 2 — propagate=false on every per-site save
            if ($saved) {
                $written++;
                $this->stateService->record(
                    source: self::STATE_SOURCE,
                    key: $entryId . ':' . $siteId,
                    targetType: 'entry',
                    targetId: $entryId,
                    targetUid: (string) $entry->uid,
                    siteId: $siteId,
                    meta: [
                        'legacyClass' => $legacyClass,
                        'legacyEntityId' => $legacyEntityId,
                        'sourceState' => $source,
                    ],
                );
            } else {
                $report->warn(
                    sprintf(
                        'seo save failed entryId=%d siteId=%d — %s',
                        $entryId,
                        $siteId,
                        json_encode($entry->getErrors()),
                    ),
                );
            }
        }

        if ($skippedEmpty > 0) {
            $report->incr('seo.skippedEmpty', $skippedEmpty);
        }

        return $written;
    }

    /**
     * Look up the SEO row for a given legacy entity. Returns null when
     * no SEO row was authored for the page (common: only some pages had
     * editor-set SEOmatic content).
     *
     * v2 reshape: v1 delegated to `LegacyDbService::seoFor()` which used a
     * hardcoded `KunstmaanCoreTables::SEO` constant — meaning the v1
     * `$seoTableName` override surface was declared but never actually flowed
     * into SQL. v2 inlines the query here so D-57's `$this->seoTableName`
     * override genuinely takes effect (the docblock on $seoTableName,
     * "passed verbatim into raw SQL", is now truthful).
     *
     * @return array<string, mixed>|null
     */
    private function fetchKumaSeoRow(int $legacyEntityId, string $legacyClass): ?array
    {
        return $this->legacyDb->queryOne(
            'SELECT * FROM ' . $this->seoTableName
            . ' WHERE ref_id = :rid AND ref_entity_name = :class'
            . ' ORDER BY id DESC LIMIT 1',
            [':rid' => $legacyEntityId, ':class' => $legacyClass],
        );
    }

    /**
     * Resolve the legacy class FQCN + entity id for a state row.
     *
     * Preferred path: read `meta.legacyClass` + `meta.legacyEntityId` that
     * the per-type migrators (news/cases/team/contentPages/singleton) write
     * during their pass.
     *
     * Fallback path (closes the stale-meta gap surfaced 2026-05-09 against
     * dewert-craft-smoke — 131 entries skipped because older state rows
     * lacked the meta keys): both values can be derived directly from the
     * state row.
     *   - `legacyClass` ← `state.source` with underscores → backslashes.
     *     The source is FQCN-derived per `EntryMigrationService`'s state-write
     *     convention (`App_Entity_Pages_TextPage` ↔ `App\Entity\Pages\TextPage`).
     *   - `legacyEntityId` ← `(int) sourceKey` directly. sourceKey is the
     *     FQCN entity row id (i.e., `kuma_<entity>.id`), identical to what
     *     `kuma_seo.ref_id` stores. The meta cache is redundant; falling
     *     back to the source/sourceKey pair recovers the same value.
     *
     * The previous `legacyRefRowForNode()` fallback was broken — it tried
     * `kuma_nodes WHERE id = sourceKey` on the assumption sourceKey was a
     * kuma_node_id, which it isn't. That helper has been removed; if any
     * state row genuinely needs a kuma_node-tree lookup, that's a job for
     * a different resolver, not this one.
     *
     * Singleton state rows store the section handle as sourceKey, so the
     * derivation produces a string id (`globalSettings`) that's not numeric
     * — they MUST carry meta and short-circuit at the existing branch.
     *
     * @param array<string, mixed>|string|null $meta
     * @return array{0: ?string, 1: int}
     */
    private function resolveLegacyRef(string $source, string $sourceKey, mixed $meta): array
    {
        // Decode meta if a raw JSON string slipped through
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        if (is_array($meta)
            && !empty($meta['legacyClass'])
            && !empty($meta['legacyEntityId'])
        ) {
            return [(string) $meta['legacyClass'], (int) $meta['legacyEntityId']];
        }

        // Singletons — no fallback (sourceKey is a section handle, not a numeric id)
        if ($source === 'singleton') {
            return [null, 0];
        }

        // Fallback: derive from source + sourceKey directly. Requires source
        // to be FQCN-shaped (contains underscores → backslashes) and sourceKey
        // to be numeric (the FQCN entity row id).
        if (!ctype_digit((string) $sourceKey) || !str_contains($source, '_')) {
            return [null, 0];
        }

        $derivedClass = str_replace('_', '\\', $source);
        return [$derivedClass, (int) $sourceKey];
    }

    /**
     * Phase 4.1 / D-25 + D-27 — testable warn-line for the Settings-disabled
     * gate. Distinct copy from the existing plugin-not-installed line ('SEOmatic
     * plugin not installed; SEO migration skipped.') so REPORT.md skipped-stages
     * aggregation can pattern-match operator-opted-out vs adapter-unavailable.
     *
     * @internal
     */
    private static function disabledWarnLine(): string
    {
        return 'SEO adapter disabled (explicitly via Settings::seoEnabled); SEO migration skipped.';
    }
}
