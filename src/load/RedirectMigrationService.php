<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\run\EnvironmentContext;
use lameco\kunstmaanmigrator\sites\SiteMap;
use lameco\kunstmaanmigrator\adapters\GatedAdapter;
use lameco\kunstmaanmigrator\adapters\MigrationAdapter;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use Craft;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\payload\RefResolver;
use lameco\kunstmaanmigrator\console\LoadController;
use Lameco\KumaCompile\Compile\RedirectCompiler;
use craft\elements\Entry;
use nystudio107\retour\Retour;
use yii\base\Component;

/**
 * RedirectMigrationService — imports the legacy redirects table + computed
 * section-move 301s into Retour per DEC-19.
 *
 * Two redirect sources are handled:
 *
 *   1. **Direct legacy-redirects import** — every row in the legacy
 *      redirects table (default `kuma_redirects`, overridable via
 *      `kuma_redirects`) is imported verbatim into Retour via
 *      `Retour::$plugin->redirects->saveRedirect()`. The row's `permanent`
 *      flag (tinyint(1)) maps to HTTP 301 / 302. Destinations that point
 *      at a legacy URL (a path that matches a migrated entry's old
 *      kuma_node_translations.url) are re-resolved to the new Craft
 *      entry URI via state lookup so the redirect chain doesn't bounce
 *      through the now-defunct legacy URL.
 *
 *   2. **Section-move 301s (DEC-18)** — every migrated team / news / cases
 *      entry whose new URL differs from the legacy URL gets an additional
 *      301 redirect from old → new path. NL contentPages preserve their
 *      hierarchy per DEC-18 (no auto-redirect emission), but EN URLs may
 *      have been normalised — those get redirects too.
 *
 * Optional-plugin gate (D-56): If Retour is not installed, the entire
 * migration pass is skipped with a warning — never a hard error. Consuming
 * projects that don't need redirect import simply see the skip log line.
 *
 * Idempotency (Pitfall 5 avoidance — RESEARCH.md §3): every saveRedirect
 * call is preceded by `getRedirectByRedirectSrcUrl($srcUrl, null)` and the
 * existing row's `id` is included in the config when present, so the
 * underlying Retour code takes the update branch instead of inserting a
 * duplicate. Verified by integration test.
 *
 * State key: `('redirect', "kuma:{$row['id']}")` for direct imports and
 * `('redirect', "section_move:{source}:{nodeId}:{lang}")` for computed
 * section-move 301s.
 *
 * Threat T-04-09-02 (open-redirect via attacker-supplied target URL):
 * legacy targets are NOT validated against an allowed-host list because
 * Retour itself accepts external destinations and the legacy DB is the
 * editorial source-of-truth. Operators audit the redirects table manually
 * before each migration run. Internal targets (starting with `/`) are
 * re-resolved through the state map without external network egress.
 */
class RedirectMigrationService extends Component implements MigrationAdapter
{
    /**
     * The Kunstmaan schema is fixed: these table names are the same in every
     * corpus this migrator targets, so they are constants rather than a
     * settings surface nobody ever used.
     */
    public const REDIRECTS_TABLE = 'kuma_redirects';

    public LegacyDbService $legacyDb;
    public MigrationStateService $stateService;



    private const STATE_SOURCE = 'redirect';

    /**
     * Import every legacy redirects row into Retour and compute section-move
     * 301s for migrated entries whose URL changed.
     *
     * D-56: if Retour is not installed the pass is skipped with a
     * warning — never a hard error.
     */
    use GatedAdapter;

    public function handle(): string
    {
        return 'redirects';
    }

    /**
     * The `redirects:` lane: compile this environment's redirect pages from the
     * mapping and load them.
     *
     * This was 85 lines inside EnvironmentPipeline and the one adapter the loop
     * could not run, because `migrateAll(MigrationOptions, SiteMap)` had nowhere
     * to put the mapping it compiles from or the connection it reads. That is
     * what the registry documented as a permanent exception. With the
     * environment arriving as a value it is an ordinary adapter, and the
     * exception is gone rather than explained.
     *
     * A redirect page is a node like any other: one legacy row produces one
     * redirect per published translation, not one in total.
     */
    public function migrateAll(MigrationOptions $opts, EnvironmentContext $context): MigrationReport
    {
        $report = new MigrationReport();

        if (!$this->isGateOpen($report)) {
            return $report;
        }

        if ($context->mapping === null || $context->legacy === null) {
            $report->warn('The redirects lane compiles from the mapping and reads the legacy database; one of them was not supplied.');

            return $report;
        }

        $compiler = new RedirectCompiler($context->mapping, $context->only);
        $records = [];

        $compiler->compile($context->legacy, $context->name, static function (array $record) use (&$records): void {
            $records[] = $record;
        });

        foreach ($compiler->skipped() as $reason => $count) {
            $report->warn(sprintf('%d skipped: %s', $count, $reason));
        }

        if ($records === []) {
            return $report;
        }

        $report->incr('compiled', count($records));

        if ($opts->dryRun) {
            return $report;
        }

        $outcome = LoadController::reportForRedirects(
            $records,
            new RefResolver(Plugin::getInstance()->migrationStateService),
            self::isRetourAvailable(),
            static function (int $entryId, string $siteHandle): ?string {
                $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);

                if ($site === null) {
                    return null;
                }

                $entry = Entry::find()->id($entryId)->siteId((int) $site->id)->status(null)->one();

                return $entry === null || $entry->uri === null ? null : '/' . ltrim($entry->uri, '/');
            },
            function (string $from, string $to, int $code, string $key, array $meta): array {
                $result = $this->importOne($from, $to, $code, $key, $meta);

                if (($result->counts['created'] ?? 0) > 0) {
                    return ['outcome' => 'created'];
                }

                if (($result->counts['updated'] ?? 0) > 0) {
                    return ['outcome' => 'updated'];
                }

                return ['outcome' => 'failed', 'message' => $result->warnings[0] ?? 'Retour refused to save the redirect.'];
            },
        );

        foreach (['created', 'updated', 'resolved', 'skipped'] as $bucket) {
            if (($outcome[$bucket] ?? 0) > 0) {
                $report->incr($bucket, (int) $outcome[$bucket]);
            }
        }

        // Only the rows that went wrong. A clean run of 156 redirects should not
        // push 156 lines through a summary.
        foreach ($outcome['report'] ?? [] as $row) {
            $status = (string) ($row['status'] ?? '');

            if ($status === '' || str_starts_with($status, 'OK')) {
                continue;
            }

            $report->incr('failed');
            $report->warn(sprintf(
                '%s -> %s (%s): %s',
                (string) ($row['from'] ?? '?'),
                (string) ($row['to'] ?? '?'),
                (string) ($row['siteHandle'] ?? '?'),
                (string) ($row['message'] ?? $status),
            ));
        }

        return $report;
    }

    public function migrateLegacyTables(MigrationOptions $opts, EnvironmentContext $context): MigrationReport
    {
        $report = new MigrationReport();

        if (!$this->isGateOpen($report)) {
            return $report;
        }

        $sites = $context->sites;

        // Secondary defensive check — the class-exists / $plugin-null guard
        // catches the rare case where the plugin is registered but Retour::$plugin
        // was never populated (e.g. manual uninstall mid-request).
        if (!class_exists(Retour::class) || Retour::$plugin === null) {
            $report->incr('failed');
            $report->warn(
                'Retour plugin not loaded (class/plugin null); redirect migration aborted.',
            );
            return $report;
        }

        // ----- 1. Direct legacy redirects import (~205 rows expected on dev) ---
        $this->importDirectRedirects($sites, $opts, $report);

        // ----- 2. Section-move 301s for team/news/cases entries ----------------
        $this->emitSectionMoveRedirects($sites, $opts, $report);

        return $report;
    }

    /**
     * Task 6 — canonical Retour-presence predicate, reused by
     * `LoadController::actionRedirects()` (payload-driven `load/redirects`)
     * so there is exactly one place that decides whether Retour is actually
     * usable, rather than every caller re-deriving the same three-part check.
     *
     * `migrateAll()` deliberately keeps its own two-branch version of this
     * check (see above) — it needs to tell "plugin not installed" (soft
     * warn) apart from "plugin registered but not loaded" (hard failure)
     * for its own report semantics, a distinction this single boolean
     * collapses.
     */
    public static function isRetourAvailable(): bool
    {
        return Craft::$app->plugins->getPlugin('retour') !== null
            && class_exists(Retour::class)
            && Retour::$plugin !== null;
    }

    /**
     * Task 6 — payload-driven single-redirect import behind
     * `LoadController::actionRedirects()`. Reuses `upsertRetourRedirect()`
     * verbatim — the same idempotent lookup-by-srcUrl-then-save-or-update
     * logic (Pitfall 5 avoidance) and `self::STATE_SOURCE` state recording
     * the legacy `migrateAll()` pass already relies on — so this does not
     * duplicate any Retour-save or state-recording logic, only gives a
     * payload caller (which has already resolved `srcUrl`/`destUrl` itself,
     * e.g. via `RefResolver`) a public entry point into it for one
     * already-resolved pair.
     *
     * Caller MUST check `isRetourAvailable()` first — this method assumes
     * Retour is present and will fail via the underlying Retour calls if it
     * isn't installed.
     *
     * @param array<string, mixed> $extraMeta merged into the state row's meta
     */
    public function importOne(
        string $srcUrl,
        string $destUrl,
        int $httpCode,
        string $stateKey,
        array $extraMeta = [],
    ): MigrationReport {
        $report = new MigrationReport();
        $this->upsertRetourRedirect($srcUrl, $destUrl, $httpCode, $stateKey, new MigrationOptions(), $report, $extraMeta);

        return $report;
    }

    // --------------------------------------------------------------------------
    // Private — legacy redirects table direct import
    // --------------------------------------------------------------------------

    private function importDirectRedirects(SiteMap $sites, MigrationOptions $opts, MigrationReport $report): void
    {
        $rows = $this->legacyDb->queryAll(
            'SELECT id, origin, target, permanent FROM ' . $this->redirectsTable() . ' ORDER BY id',
        );

        foreach ($rows as $row) {
            try {
                $this->importOneKumaRedirect($row, $sites, $opts, $report);
            } catch (\Throwable $e) {
                $report->incr('failed');
                $report->warn(
                    sprintf(
                        'redirect import failed for legacy id=%d (%s → %s): %s',
                        (int) ($row['id'] ?? 0),
                        $row['origin'] ?? '?',
                        $row['target'] ?? '?',
                        $e->getMessage(),
                    ),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importOneKumaRedirect(array $row, SiteMap $sites, MigrationOptions $opts, MigrationReport $report): void
    {
        $origin = (string) ($row['origin'] ?? '');
        $target = (string) ($row['target'] ?? '');
        if ($origin === '' || $target === '') {
            $report->incr('skipped');
            return;
        }

        $srcUrl = $this->normalisePath($origin);
        $destUrl = $this->resolveDestUrl($target, $sites);
        $httpCode = ((int) ($row['permanent'] ?? 0) === 1) ? 301 : 302;
        $kumaId = (int) ($row['id'] ?? 0);
        $stateKey = 'kuma:' . $kumaId;

        $this->upsertRetourRedirect(
            srcUrl: $srcUrl,
            destUrl: $destUrl,
            httpCode: $httpCode,
            stateKey: $stateKey,
            opts: $opts,
            report: $report,
            extraMeta: [
                'kumaRedirectId' => $kumaId,
                'origin' => $origin,
                'targetRaw' => $target,
            ],
        );
    }

    /**
     * Re-resolve a legacy target URL to the new Craft entry URI when the
     * legacy path matches a migrated entry's old URL. External URLs (with
     * scheme) and non-matching internal paths pass through verbatim.
     */
    private function resolveDestUrl(string $rawTarget, SiteMap $sites): string
    {
        $target = trim($rawTarget);
        if ($target === '') {
            return '/';
        }

        // External target → leave verbatim
        if (preg_match('#^https?://#i', $target) === 1) {
            return $target;
        }

        $normalised = $this->normalisePath($target);
        $resolved = $this->lookupNewUrlByLegacyUrl($normalised, $sites);
        return $resolved ?? $normalised;
    }

    /**
     * Look up the new Craft entry URL by legacy URL. Returns null when no
     * migrated entry matches.
     *
     * Strategy: query the legacy DB to find the kuma_node whose
     * kuma_node_translations.url matches the supplied path, then query
     * state for the migrated entry, then resolve the entry's site URL.
     */
    private function lookupNewUrlByLegacyUrl(string $path, SiteMap $sites): ?string
    {
        // Strip a configured leading "/{legacy-locale}/" prefix from the URL
        // so it matches kuma_node_translations.url. Locale codes come from the
        // operator's locale map rather than hardcoded nl/en assumptions.
        [$stripped, $lang] = $this->stripLegacyLocalePrefix($path, $sites);

        if ($stripped === '') {
            return null;
        }

        try {
            $row = $this->legacyNodeRowForUrl($stripped, $lang, $sites);
        } catch (\Throwable) {
            return null;
        }

        if ($row === null || empty($row['kuma_node_id']) || empty($row['class'])) {
            return null;
        }

        $kumaNodeId = (int) $row['kuma_node_id'];
        $entryId = $this->resolveEntryIdForLegacyNode($kumaNodeId, (string) $row['class']);
        if ($entryId === null) {
            return null;
        }

        // v2 reshape: iterate $this->sites instead of hardcoded 'default'/'en' (PATTERNS flag #4).
        // Map kuma-locale → Craft-handle. If the URL carried a kuma-lang prefix, prefer that
        // site; otherwise walk all configured Craft sites and return the first non-null match.
        $sites = Craft::$app->sites;

        $candidateHandles = [];
        if ($lang !== null && $sites->handleForLocale($lang) !== null) {
            $candidateHandles[] = (string) $sites->handleForLocale($lang);
        }
        // Fallback: walk every configured Craft handle in the sites map.
        foreach ($sites->handles() as $handle) {
            if (!in_array($handle, $candidateHandles, true)) {
                $candidateHandles[] = $handle;
            }
        }

        foreach ($candidateHandles as $handle) {
            $site = $sites->getSiteByHandle((string) $handle);
            if ($site === null) {
                continue;
            }
            $entry = Entry::find()->id($entryId)->siteId((int) $site->id)->status(null)->one();
            if ($entry !== null && $entry->uri !== null) {
                return '/' . ltrim($entry->uri, '/');
            }
        }

        return null;
    }

    /**
     * Strip the configured legacy locale prefix from a path.
     *
     * @return array{0: string, 1: string|null} [language-relative path, locale]
     */
    private function stripLegacyLocalePrefix(string $path, SiteMap $sites): array
    {
        $stripped = ltrim($path, '/');
        foreach ($this->legacyLocales($sites) as $locale) {
            $prefix = $locale . '/';
            if ($stripped === $locale) {
                return ['', $locale];
            }
            if (str_starts_with($stripped, $prefix)) {
                return [substr($stripped, strlen($prefix)), $locale];
            }
        }

        return [$stripped, null];
    }

    /**
     * @return list<string>
     */
    private function legacyLocales(SiteMap $sites): array
    {
        $locales = [];
        foreach ($sites->locales() as $locale) {
            if (is_string($locale) && $locale !== '' && !in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    /**
     * @return array{kuma_node_id: mixed, class: mixed}|null
     */
    private function legacyNodeRowForUrl(string $strippedUrl, ?string $lang, SiteMap $sites): ?array
    {
        $versionCol = 'public_node_version_id';

        $whereParts = [
            'nt.url = :url',
            'n.deleted = 0',
            'nt.online = 1',
        ];
        $params = [
            ':url' => $strippedUrl,
        ];
        if ($lang !== null) {
            $whereParts[] = 'nt.lang = :lang';
            $params[':lang'] = $lang;
        } else {
            $localePlaceholders = [];
            foreach ($this->legacyLocales($sites) as $i => $locale) {
                $placeholder = ':locale' . $i;
                $localePlaceholders[] = $placeholder;
                $params[$placeholder] = $locale;
            }
            if ($localePlaceholders !== []) {
                $whereParts[] = 'nt.lang IN (' . implode(', ', $localePlaceholders) . ')';
            }
        }

        return $this->legacyDb->queryOne(
            'SELECT n.id AS kuma_node_id, nv.ref_entity_name AS class'
            . ' FROM kuma_nodes n'
            . ' INNER JOIN kuma_node_translations nt ON nt.node_id = n.id'
            . ' INNER JOIN kuma_node_versions nv ON nv.id = nt.' . $versionCol
            . ' WHERE ' . implode(' AND ', $whereParts)
            . ' ORDER BY ' . $this->legacyLocaleOrderSql($sites)
            . ' LIMIT 1',
            $params,
        );
    }

    private function legacyLocaleOrderSql(SiteMap $sites): string
    {
        $cases = [];
        foreach ($this->legacyLocales($sites) as $i => $locale) {
            $cases[] = "WHEN '" . str_replace("'", "''", $locale) . "' THEN " . $i;
        }
        if ($cases === []) {
            return 'nt.lang ASC';
        }

        return 'CASE nt.lang ' . implode(' ', $cases) . ' ELSE 999 END';
    }

    /**
     * Find the migrated Craft entry id for a legacy kuma_node_id.
     */
    private function resolveEntryIdForLegacyNode(int $kumaNodeId, ?string $legacyClass = null): ?int
    {
        $candidateSources = [];
        if ($legacyClass !== null && $legacyClass !== '') {
            $candidateSources[] = str_replace('\\', '_', trim($legacyClass, '\\'));
        }
        // Legacy compatibility for pre-FQCN state rows.
        array_push($candidateSources, 'news', 'cases', 'page', 'singleton');

        foreach (array_unique($candidateSources) as $source) {
            $id = $this->stateService->getTargetId((string) $source, (string) $kumaNodeId);
            if ($id !== null) {
                return $id;
            }
        }
        return null;
    }

    // --------------------------------------------------------------------------
    // Private — section-move 301s
    // --------------------------------------------------------------------------

    private function emitSectionMoveRedirects(SiteMap $sites, MigrationOptions $opts, MigrationReport $report): void
    {
        foreach ($this->stateService->entryRows() as $stateRow) {
            $entryId = (int) ($stateRow['targetId'] ?? 0);
            $source = (string) ($stateRow['source'] ?? '');
            $sourceKey = (string) ($stateRow['sourceKey'] ?? '');
            if ($entryId === 0 || $source === '' || $sourceKey === '') {
                continue;
            }

            // SEO writes also record targetType=entry state rows; redirects
            // should only consider rows produced by the entry migration stage.
            if ($source === 'seo_meta' || str_contains($sourceKey, ':')) {
                continue;
            }

            // state.sourceKey carries the refId (page-entity row id), NOT
            // the kuma_node_id. Recover the actual kumaNodeId from meta —
            // the entry save path must persist it specifically so this path
            // can pair the right source URLs to the right Craft entry.
            // Without this, every state row whose
            // sourceKey happens to equal some unrelated node's id pairs the
            // unrelated node's legacy URL with this entry's URI — visible
            // as `/nl/diensten` → `/personeels-dossier` after a clean
            // rebuild (sourceKey=1 hits ~9 different source nodes).
            $kumaNodeId = $this->kumaNodeIdFromStateMeta($stateRow);
            if ($kumaNodeId === null) {
                // Backwards compatibility: pre-meta state rows fell back to
                // treating sourceKey as the node id. Keep that path so an
                // operator with an old state table doesn't silently lose
                // section-move 301s — but log a hint that re-running
                // migrate will correct the pairings.
                if (ctype_digit($sourceKey)) {
                    $kumaNodeId = (int) $sourceKey;
                } else {
                    continue;
                }
            }
            if ($kumaNodeId <= 0) {
                continue;
            }

            try {
                $this->emitSectionMoveForOne($source, $kumaNodeId, $entryId, $sites, $opts, $report);
            } catch (\Throwable $e) {
                $report->incr('failed');
                $report->warn(
                    sprintf(
                        'section-move 301 failed for %s:%d entryId=%d — %s',
                        $source,
                        $kumaNodeId,
                        $entryId,
                        $e->getMessage(),
                    ),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $stateRow
     */
    private function kumaNodeIdFromStateMeta(array $stateRow): ?int
    {
        $meta = $stateRow['meta'] ?? null;
        if (is_string($meta) && $meta !== '') {
            try {
                $decoded = json_decode($meta, true, 16, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['kumaNodeId'])) {
                    return (int) $decoded['kumaNodeId'];
                }
            } catch (\Throwable) {
                // fall through to null — caller handles legacy fallback.
            }
        } elseif (is_array($meta) && isset($meta['kumaNodeId'])) {
            return (int) $meta['kumaNodeId'];
        }
        return null;
    }

    private function emitSectionMoveForOne(
        string $source,
        int $kumaNodeId,
        int $entryId,
        SiteMap $sites,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void {
        if ($kumaNodeId <= 0) {
            return;
        }

        $legacyUrls = $this->legacyUrlsForNode($kumaNodeId);
        if ($legacyUrls === []) {
            return;
        }

        // v2 reshape: iterate $this->sites instead of hardcoded 'default'/'en' (PATTERNS flag #4).
        // $this->sites is kuma-locale → Craft-handle; $legacyUrls is keyed by kuma-locale.
        // Walk every configured kuma-locale and emit a redirect for each Craft site that
        // has a corresponding legacy URL. Replaces v1's hardcoded $nlSite / $enSite pair
        // so this works on any client whose Craft handles aren't literally 'default'+'en'.
        $sites = Craft::$app->sites;

        foreach ($sites->configured() as $kumaLang => $craftHandle) {
            $legacyUrl = $legacyUrls[$kumaLang] ?? null;
            if ($legacyUrl === null) {
                continue;
            }
            $site = $sites->getSiteByHandle((string) $craftHandle);
            if ($site === null) {
                continue;
            }
            $entry = Entry::find()->id($entryId)->siteId((int) $site->id)->status(null)->one();
            if ($entry === null || $entry->uri === null) {
                continue;
            }

            $oldPath = '/' . $kumaLang . '/' . ltrim($legacyUrl, '/');
            // Resolve the destination as a SITE-AWARE path: parse the
            // entry's full URL through the site's baseUrl so a multi-site
            // setup with a `/en/` URL prefix renders the redirect to
            // `/en/services` instead of `/services`. Falls back to the
            // bare URI for single-site installs (and tolerates installs
            // where Site::baseUrl can't be resolved at this point).
            $entryUrl = (string) ($entry->getUrl() ?? '');
            $newPath = $entryUrl !== ''
                ? (parse_url($entryUrl, PHP_URL_PATH) ?: ('/' . ltrim($entry->uri, '/')))
                : '/' . ltrim($entry->uri, '/');
            if ($oldPath === $newPath) {
                continue;
            }

            $stateKey = sprintf('section_move:%s:%d:%s', $source, $kumaNodeId, $kumaLang);

            $this->upsertRetourRedirect(
                srcUrl: $oldPath,
                destUrl: $newPath,
                httpCode: 301,
                stateKey: $stateKey,
                opts: $opts,
                report: $report,
                extraMeta: [
                    'sectionMoveSource' => $source,
                    'kumaNodeId' => $kumaNodeId,
                    'lang' => $kumaLang,
                ],
                associatedElementId: $entryId,
            );
        }
    }

    /**
     * @return array<string, string> ['nl' => 'over-cqm/...', 'en' => 'about-us/...']
     */
    private function legacyUrlsForNode(int $kumaNodeId): array
    {
        try {
            $rows = $this->legacyDb->queryAll(
                'SELECT lang, url, online FROM kuma_node_translations WHERE node_id = :id',
                [':id' => $kumaNodeId],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $lang = (string) ($r['lang'] ?? '');
            $url = (string) ($r['url'] ?? '');
            if ($lang !== '' && $url !== '') {
                $out[$lang] = $url;
            }
        }
        return $out;
    }

    // --------------------------------------------------------------------------
    // Private — Retour upsert + state recording
    // --------------------------------------------------------------------------

    /**
     * Idempotent Retour saveRedirect — looks up an existing row by srcUrl,
     * passes its id back into saveRedirect to take the update branch
     * (Pitfall 5 avoidance per RESEARCH.md §3).
     *
     * @param array<string, mixed> $extraMeta merged into state row meta
     */
    private function upsertRetourRedirect(
        string $srcUrl,
        string $destUrl,
        int $httpCode,
        string $stateKey,
        MigrationOptions $opts,
        MigrationReport $report,
        array $extraMeta = [],
        ?int $associatedElementId = null,
    ): void {
        if ($srcUrl === '' || $destUrl === '') {
            $report->incr('skipped');
            return;
        }

        if ($opts->dryRun) {
            $report->incr('skipped');
            $report->warn(
                sprintf('[dry-run] would import redirect %s → %s [%d]', $srcUrl, $destUrl, $httpCode),
            );
            return;
        }

        $existing = Retour::$plugin->redirects->getRedirectByRedirectSrcUrl($srcUrl, null);

        // Retour's StaticRedirects model has typed properties (int associatedElementId,
        // string hitLastTime) and no `redirectEnabled` property. Passing null / unknown
        // keys triggers TypeError / UnknownPropertyException inside new StaticRedirectsModel($config)
        // BEFORE validate() can run, aborting saveRedirect() for every row. Stick to the
        // model's declared attributes and rely on its defaults (associatedElementId=0,
        // hitLastTime=''). enabled defaults to true on the model.
        $config = [
            'redirectSrcUrl' => $srcUrl,
            'redirectSrcUrlParsed' => $srcUrl,
            'redirectSrcMatch' => 'pathonly',
            'redirectMatchType' => 'exactmatch',
            'redirectDestUrl' => $destUrl,
            'redirectHttpCode' => $httpCode,
            'siteId' => null,
            'associatedElementId' => $associatedElementId ?? 0,
            'hitCount' => 0,
            'enabled' => true,
        ];
        if (!empty($existing) && !empty($existing['id'])) {
            $config['id'] = (int) $existing['id'];
        }

        // checkForRedirectLoop=false — the migrator imports verbatim and
        // doesn't want Retour deleting any pre-existing target rows that
        // happen to have srcUrl == this row's destUrl. Operators audit
        // loops manually in the CP after the import.
        if (!Retour::$plugin->redirects->saveRedirect($config, false)) {
            $report->incr('failed');
            $report->warn(
                sprintf('Retour saveRedirect refused %s → %s', $srcUrl, $destUrl),
            );
            return;
        }

        // After save, fetch the id (Retour's saveRedirect doesn't return it).
        $saved = Retour::$plugin->redirects->getRedirectByRedirectSrcUrl($srcUrl, null);
        $retourId = (int) ($saved['id'] ?? 0);

        if ($retourId > 0) {
            $this->stateService->record(
                source: self::STATE_SOURCE,
                key: $stateKey,
                targetType: 'retour_static_redirect',
                targetId: $retourId,
                meta: array_merge(
                    [
                        'srcUrl' => $srcUrl,
                        'destUrl' => $destUrl,
                        'httpCode' => $httpCode,
                        'wasUpdate' => !empty($existing),
                    ],
                    $extraMeta,
                ),
            );
        }

        $report->incr(!empty($existing) ? 'updated' : 'created');
    }

    /**
     * Normalise a legacy origin / target path into the leading-slash form
     * Retour expects. Defence in depth against path-traversal: leading
     * slashes are collapsed but inner `/../` is left for Retour's own
     * matcher to reject (Retour matches against request path strings, not
     * filesystem paths — no traversal surface).
     */
    private function normalisePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '/';
        }
        return '/' . ltrim($trimmed, '/');
    }


    /**
     * The legacy redirects table, for the pass that reads one. Kunstmaan
     * flavours differ; the constant is the canonical default.
     */
    private function redirectsTable(): string
    {
        $configured = (string) ($this->config()['sourceTable'] ?? '');

        return $configured !== '' ? $configured : self::REDIRECTS_TABLE;
    }
}
