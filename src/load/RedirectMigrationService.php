<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use Craft;
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
 *      `$redirectsTableName`) is imported verbatim into Retour via
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
 * section-move 301s. truncate() iterates these state rows and deletes the
 * matching retour_static_redirects rows by id.
 *
 * Threat T-04-09-02 (open-redirect via attacker-supplied target URL):
 * legacy targets are NOT validated against an allowed-host list because
 * Retour itself accepts external destinations and the legacy DB is the
 * editorial source-of-truth. Operators audit the redirects table manually
 * before each migration run. Internal targets (starting with `/`) are
 * re-resolved through the state map without external network egress.
 */
class RedirectMigrationService extends Component
{
    public LegacyDbService $legacyDb;
    public MigrationStateService $stateService;

    /**
     * Filter state wired in Plugin::init() per D-13. When null, the service
     * behaves as if all filters are disabled (legacy behavior).
     *
     * v2 reshape: MigrationFilters is {entities, locales, since} only —
     * v1's includeDrafts / includeDeleted / includeOffline / cutoffAfter /
     * cutoffBefore are dropped per D-09..D-13. Defaults hardcoded:
     * published versions only (public_node_version_id), exclude deleted
     * nodes, require either-language online, single since floor.
     */
    public ?MigrationFilters $filters = null;

    /**
     * Kuma-locale → Craft-site-handle map. Wired in Plugin::init() from
     * Plugin::resolveSitesMap() (Plan 04-09). Empty map means no sites
     * configured — the service degrades gracefully (section-move emission
     * short-circuits, lookupNewUrlByLegacyUrl returns null).
     *
     * @var array<string, string>
     */
    public array $sites = [];

    /**
     * Legacy redirects table name (unwrapped — passed verbatim into raw SQL).
     * Defaults to the canonical Kunstmaan `kuma_redirects`; host projects
     * with a non-standard schema override via setComponents. Plan 04-09 config
     * loader will populate from `Settings::$redirectsTableName`.
     */
    public string $redirectsTableName = 'kuma_redirects';

    private const STATE_SOURCE = 'redirect';

    /** Sources whose entries trigger a section-move 301 audit. */
    private const SECTION_MOVE_SOURCES = ['team', 'news', 'cases'];

    /**
     * Import every legacy redirects row into Retour and compute section-move
     * 301s for migrated entries whose URL changed.
     *
     * D-56: if Retour is not installed the pass is skipped with a
     * warning — never a hard error.
     */
    public function migrateAll(MigrationOptions $opts): MigrationReport
    {
        $report = new MigrationReport();

        // Phase 4.1 / D-25 — settings-disabled gate runs FIRST, BEFORE the
        // plugin-presence check. D-27: warn-line copy is distinct from
        // plugin-not-installed so REPORT.md skipped-stages aggregation can
        // distinguish "operator opted out" from "plugin unavailable".
        if (!Plugin::getInstance()->getSettings()->retourEnabled) {
            Craft::info(
                'Retour adapter explicitly disabled via Settings::retourEnabled; skipping redirect migration pass.',
                'kunstmaanmigrator',
            );
            $report->warn(self::disabledWarnLine());
            return $report;
        }

        // D-56: Retour is optional. If the plugin is not installed,
        // skip the entire redirect migration pass with a warning.
        if (Craft::$app->plugins->getPlugin('retour') === null) {
            Craft::warning(
                'Retour plugin not installed; skipping redirect migration pass.',
                'kunstmaanmigrator',
            );
            $report->warn(
                'Retour plugin not installed; redirect migration skipped.',
            );
            return $report;
        }

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
        $this->importDirectRedirects($opts, $report);

        // ----- 2. Section-move 301s for team/news/cases entries ----------------
        $this->emitSectionMoveRedirects($opts, $report);

        return $report;
    }

    /**
     * Delete every Retour row this migrator owns (per state.source='redirect')
     * and forget the matching state rows. Manually-created Retour redirects
     * are unaffected.
     *
     * D-56: returns 0 silently when Retour is absent.
     */
    public function truncate(): int
    {
        if (Craft::$app->plugins->getPlugin('retour') === null
            || !class_exists(Retour::class)
            || Retour::$plugin === null
        ) {
            return 0;
        }

        $deleted = 0;
        $db = Craft::$app->db;
        foreach ($this->stateService->all(self::STATE_SOURCE) as $row) {
            $retourId = (int) ($row['targetId'] ?? 0);
            $sourceKey = (string) ($row['sourceKey'] ?? '');
            if ($retourId > 0) {
                try {
                    $db->createCommand()
                        ->delete('{{%retour_static_redirects}}', ['id' => $retourId])
                        ->execute();
                    $deleted++;
                } catch (\Throwable $e) {
                    Craft::warning(
                        sprintf(
                            'RedirectMigrationService::truncate: could not delete retour id=%d — %s',
                            $retourId,
                            $e->getMessage(),
                        ),
                        __METHOD__,
                    );
                }
            }
            if ($sourceKey !== '') {
                $this->stateService->forget(self::STATE_SOURCE, $sourceKey);
            }
        }
        return $deleted;
    }

    // --------------------------------------------------------------------------
    // Private — legacy redirects table direct import
    // --------------------------------------------------------------------------

    private function importDirectRedirects(MigrationOptions $opts, MigrationReport $report): void
    {
        $rows = $this->legacyDb->queryAll(
            'SELECT id, origin, target, permanent FROM ' . $this->redirectsTableName . ' ORDER BY id',
        );

        foreach ($rows as $row) {
            try {
                $this->importOneKumaRedirect($row, $opts, $report);
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
    private function importOneKumaRedirect(array $row, MigrationOptions $opts, MigrationReport $report): void
    {
        $origin = (string) ($row['origin'] ?? '');
        $target = (string) ($row['target'] ?? '');
        if ($origin === '' || $target === '') {
            $report->incr('skipped');
            return;
        }

        $srcUrl = $this->normalisePath($origin);
        $destUrl = $this->resolveDestUrl($target);
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
    private function resolveDestUrl(string $rawTarget): string
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
        $resolved = $this->lookupNewUrlByLegacyUrl($normalised);
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
    private function lookupNewUrlByLegacyUrl(string $path): ?string
    {
        // Strip leading "/{lang}/" prefix from the legacy URL so it matches
        // the per-language `kuma_node_translations.url` value (which is
        // language-relative, e.g. "fields/data-science").
        $stripped = $path;
        $lang = null;
        if (preg_match('#^/(nl|en)/(.*)$#', $path, $m) === 1) {
            $lang = $m[1];
            $stripped = $m[2];
        } else {
            $stripped = ltrim($path, '/');
        }

        if ($stripped === '') {
            return null;
        }

        // v2 reshape: MigrationFilters is {entities, locales, since} only —
        // v1's includeDrafts / includeDeleted / includeOffline / cutoffAfter /
        // cutoffBefore are dropped per D-09..D-13. Defaults hardcoded.
        $versionCol = 'public_node_version_id';

        $whereParts = [
            'nt.url = :url',
            'n.deleted = 0',
            '(nt_nl.online = 1 OR nt_en.online = 1)',
        ];
        $params = [
            ':url' => $stripped,
            ':langNl' => 'nl',
            ':langEn' => 'en',
        ];
        if ($lang !== null) {
            $whereParts[] = 'nt.lang = :lang';
            $params[':lang'] = $lang;
        }
        if ($this->filters !== null && $this->filters->since !== null && $this->filters->since !== '') {
            // NL is canonical creation timestamp (CQM is NL-primary per Phase 5 D-03).
            $whereParts[] = 'nt_nl.created >= :since';
            $params[':since'] = $this->filters->since;
        }

        try {
            $row = $this->legacyDb->queryOne(
                'SELECT n.id AS kuma_node_id, COALESCE(nv_nl.ref_entity_name, nv_en.ref_entity_name) AS class'
                . ' FROM kuma_nodes n'
                . ' LEFT JOIN kuma_node_translations nt ON nt.node_id = n.id'
                . ' LEFT JOIN kuma_node_translations nt_nl ON nt_nl.node_id = n.id AND nt_nl.lang = :langNl'
                . ' LEFT JOIN kuma_node_versions nv_nl ON nv_nl.id = nt_nl.' . $versionCol
                . ' LEFT JOIN kuma_node_translations nt_en ON nt_en.node_id = n.id AND nt_en.lang = :langEn'
                . ' LEFT JOIN kuma_node_versions nv_en ON nv_en.id = nt_en.' . $versionCol
                . ' WHERE ' . implode(' AND ', $whereParts)
                . ' LIMIT 1',
                $params,
            );
        } catch (\Throwable) {
            return null;
        }

        if ($row === null || empty($row['kuma_node_id']) || empty($row['class'])) {
            return null;
        }

        $kumaNodeId = (int) $row['kuma_node_id'];
        $entryId = $this->resolveEntryIdForLegacyNode($kumaNodeId);
        if ($entryId === null) {
            return null;
        }

        // v2 reshape: iterate $this->sites instead of hardcoded 'default'/'en' (PATTERNS flag #4).
        // Map kuma-locale → Craft-handle. If the URL carried a kuma-lang prefix, prefer that
        // site; otherwise walk all configured Craft sites and return the first non-null match.
        $sites = Craft::$app->sites;

        $candidateHandles = [];
        if ($lang !== null && isset($this->sites[$lang])) {
            $candidateHandles[] = $this->sites[$lang];
        }
        // Fallback: walk every configured Craft handle in the sites map.
        foreach ($this->sites as $handle) {
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
     * Find the migrated Craft entry id for a legacy kuma_node_id by walking
     * each entry-producing state source.
     */
    private function resolveEntryIdForLegacyNode(int $kumaNodeId): ?int
    {
        foreach (['news', 'cases', 'page', 'singleton'] as $source) {
            $id = $this->stateService->getTargetId($source, (string) $kumaNodeId);
            if ($id !== null) {
                return $id;
            }
        }
        return null;
    }

    // --------------------------------------------------------------------------
    // Private — section-move 301s
    // --------------------------------------------------------------------------

    private function emitSectionMoveRedirects(MigrationOptions $opts, MigrationReport $report): void
    {
        foreach (self::SECTION_MOVE_SOURCES as $source) {
            foreach ($this->stateService->all($source) as $stateRow) {
                $entryId = (int) ($stateRow['targetId'] ?? 0);
                $sourceKey = (string) ($stateRow['sourceKey'] ?? '');
                if ($entryId === 0 || ($stateRow['targetType'] ?? '') !== 'entry') {
                    continue;
                }

                try {
                    $this->emitSectionMoveForOne($source, $sourceKey, $entryId, $opts, $report);
                } catch (\Throwable $e) {
                    $report->incr('failed');
                    $report->warn(
                        sprintf(
                            'section-move 301 failed for %s:%s entryId=%d — %s',
                            $source,
                            $sourceKey,
                            $entryId,
                            $e->getMessage(),
                        ),
                    );
                }
            }
        }
    }

    private function emitSectionMoveForOne(
        string $source,
        string $sourceKey,
        int $entryId,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void {
        // Team source-keys are the legacy employee_id; everything else is
        // the kuma_node_id. Only the kuma_node-keyed sources have legacy
        // URLs to compare against.
        if ($source === 'team') {
            $kumaNodeId = $this->kumaNodeIdForEmployee((int) $sourceKey);
            if ($kumaNodeId === null) {
                return;
            }
        } else {
            if (!ctype_digit($sourceKey)) {
                return;
            }
            $kumaNodeId = (int) $sourceKey;
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

        foreach ($this->sites as $kumaLang => $craftHandle) {
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
            $newPath = '/' . ltrim($entry->uri, '/');
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

    /**
     * Look up the kuma_node_id that hosts an EmployeePage with a given
     * legacy employee_id. Used to convert team state-keys (employee id)
     * back into a node id so we can fetch translations.
     */
    private function kumaNodeIdForEmployee(int $employeeId): ?int
    {
        // v2 reshape: MigrationFilters is {entities, locales, since} only —
        // v1's includeDrafts / includeDeleted / includeOffline / cutoffAfter /
        // cutoffBefore are dropped per D-09..D-13. Defaults hardcoded.
        $versionCol = 'public_node_version_id';

        $whereParts = [
            'COALESCE(nv_nl.ref_entity_name, nv_en.ref_entity_name) = :class',
            'COALESCE(nv_nl.ref_id, nv_en.ref_id) = :rid',
            'n.deleted = 0',
            '(nt_nl.online = 1 OR nt_en.online = 1)',
        ];
        $params = [
            ':langNl' => 'nl',
            ':langEn' => 'en',
            ':class' => 'App\\Entity\\Pages\\EmployeePage',
            ':rid' => $employeeId,
        ];
        if ($this->filters !== null && $this->filters->since !== null && $this->filters->since !== '') {
            $whereParts[] = 'nt_nl.created >= :since';
            $params[':since'] = $this->filters->since;
        }

        try {
            $row = $this->legacyDb->queryOne(
                'SELECT n.id AS kuma_node_id'
                . ' FROM kuma_nodes n'
                . ' LEFT JOIN kuma_node_translations nt_nl ON nt_nl.node_id = n.id AND nt_nl.lang = :langNl'
                . ' LEFT JOIN kuma_node_versions nv_nl ON nv_nl.id = nt_nl.' . $versionCol
                . ' LEFT JOIN kuma_node_translations nt_en ON nt_en.node_id = n.id AND nt_en.lang = :langEn'
                . ' LEFT JOIN kuma_node_versions nv_en ON nv_en.id = nt_en.' . $versionCol
                . ' WHERE ' . implode(' AND ', $whereParts)
                . ' LIMIT 1',
                $params,
            );
        } catch (\Throwable) {
            return null;
        }
        return $row !== null ? (int) ($row['kuma_node_id'] ?? 0) : null;
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
     * Phase 4.1 / D-25 + D-27 — testable warn-line for the Settings-disabled
     * gate. Distinct copy from the existing plugin-not-installed line ('Retour
     * plugin not installed; redirect migration skipped.') so REPORT.md
     * skipped-stages aggregation can pattern-match operator-opted-out vs
     * adapter-unavailable.
     *
     * @internal
     */
    private static function disabledWarnLine(): string
    {
        return 'Retour adapter disabled (explicitly via Settings::retourEnabled); redirect migration skipped.';
    }
}
