<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\adapters\GatedAdapter;
use Lameco\Kunstmaanmigrator\adapters\MigrationAdapter;
use Lameco\Kunstmaanmigrator\Compile\RedirectCompiler;
use Lameco\Kunstmaanmigrator\console\LoadController;
use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
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
 *   2. **Section-move 301s** — opt-in via the adapter's `sectionMoves`
 *      setting (issue #46): every migrated entry whose Craft URI differs
 *      from its legacy URL gets a 301 from old → new path, per site. Trees
 *      the structural placeholders preserve emit nothing — the pass only
 *      speaks on difference.
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

    /** The seam at Craft's element reads; read through elements() so an unwired slot is not a precondition. */
    public ?ElementWriter $elementWriter = null;

    private function elements(): ElementWriter
    {
        return $this->elementWriter ??= new CraftElementWriter();
    }



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

        // The admin-managed redirects table, imported verbatim. This pass has
        // existed since v1 (migrateLegacyTables) and nothing in the v2 pipeline
        // called it: 1,419 kuma_redirects rows on the Enreach corpus never
        // reached Retour while the class docblock said they would. It runs
        // before the RedirectPage lane so an environment with no redirect
        // *pages* still imports its redirect *table*.
        if (self::isRetourAvailable()) {
            $this->importDirectRedirects($context->sites, $context->name, $opts, $report);

            // Opt-in until measured per corpus: a computed 301 for every page
            // whose Craft URI differs from its legacy URL. Structural
            // placeholders preserve most trees byte-for-byte, so the emit-only-
            // on-difference rule below is what keeps this from flooding Retour.
            if ((bool) ($this->config()['sectionMoves'] ?? false)) {
                $this->emitSectionMoves($context, $opts, $report);
            }
        } else {
            $report->warn('Retour not available; kuma_redirects import skipped.');
        }

        $compiler = new RedirectCompiler($context->mapping, $context->only);
        $records = [];

        $compiler->compile($context->legacy, $context->name, static function(array $record) use (&$records): void {
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
            function(int $entryId, string $siteHandle) use ($context): ?string {
                $siteId = $context->sites->siteIdForHandle($siteHandle);

                if ($siteId === null) {
                    return null;
                }

                $entry = $this->elements()->findById($entryId, Entry::class, $siteId);

                return $entry === null || $entry->uri === null ? null : '/' . ltrim($entry->uri, '/');
            },
            function(string $from, string $to, int $code, string $key, array $meta): array {
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

    private function importDirectRedirects(SiteMap $sites, string $environment, MigrationOptions $opts, MigrationReport $report): void
    {
        $rows = $this->legacyDb->queryAll(
            'SELECT id, origin, target, permanent FROM ' . $this->redirectsTable() . ' ORDER BY id',
        );

        foreach ($rows as $row) {
            try {
                $this->importOneKumaRedirect($row, $sites, $environment, $opts, $report);
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
    private function importOneKumaRedirect(array $row, SiteMap $sites, string $environment, MigrationOptions $opts, MigrationReport $report): void
    {
        $origin = (string) ($row['origin'] ?? '');
        $target = (string) ($row['target'] ?? '');
        if ($origin === '' || $target === '') {
            $report->incr('skipped');
            return;
        }

        $srcUrl = $this->normalisePath($origin);
        $destUrl = $this->resolveDestUrl($target, $sites, $environment);
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
    private function resolveDestUrl(string $rawTarget, SiteMap $sites, string $environment): string
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
        $resolved = $this->lookupNewUrlByLegacyUrl($normalised, $sites, $environment);
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
    private function lookupNewUrlByLegacyUrl(string $path, SiteMap $sites, string $environment): ?string
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
        $entryId = $this->resolveEntryIdForLegacyNode($kumaNodeId, (string) $row['class'], $environment);
        if ($entryId === null) {
            return null;
        }

        // If the URL carried a kuma-lang prefix, prefer that site; otherwise walk
        // every configured site and return the first non-null match. The map is
        // the environment's SiteMap — this used to read `Craft::$app->sites`,
        // which has no `handleForLocale()`, so the lookup threw on every call.
        $candidateHandles = [];
        if ($lang !== null && $sites->handleForLocale($lang) !== null) {
            $candidateHandles[] = (string) $sites->handleForLocale($lang);
        }
        foreach ($sites->handles() as $handle) {
            if (!in_array($handle, $candidateHandles, true)) {
                $candidateHandles[] = $handle;
            }
        }

        foreach ($candidateHandles as $handle) {
            $siteId = $sites->siteIdForHandle((string) $handle);
            if ($siteId === null) {
                continue;
            }
            $entry = $this->elements()->findById($entryId, Entry::class, $siteId);
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
     *
     * Tries the environment-scoped `"<ENV>:kuma_nodes"` source first — `kuma_node_id`
     * is only unique WITHIN one legacy environment's own database, and COM/DE/LV each
     * restart their own numbering, so two different environments can (and do) each
     * have a node numbered, say, 62. The unscoped `$legacyClass`/legacy-compat sources
     * below have no environment in their key at all, so a redirect compiled for one
     * environment could resolve to an unrelated entry a DIFFERENT environment happened
     * to record under the same numeric id — measured: COM's `/en/products/enreach-
     * contact` redirect resolved to LV's own separate "enreach-contact" product page
     * instead of COM's, because both happened to share a legacy node id. Same fix as
     * `NavigationMigrationService::resolveEntryIdForNode()`, which already threads
     * `$environment` through for exactly this reason.
     */
    private function resolveEntryIdForLegacyNode(int $kumaNodeId, ?string $legacyClass, string $environment): ?int
    {
        if ($environment !== '' && $kumaNodeId > 0) {
            $id = $this->stateService->getTargetId(sprintf('%s:kuma_nodes', $environment), (string) $kumaNodeId);
            if ($id !== null) {
                return $id;
            }
        }

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




    /**
     * One 301 per (entry, site) whose legacy URL differs from its Craft URI.
     *
     * v2-native replacement for the v1 section-move pass (issue #46): entry
     * state rows are `source = "<ENV>:kuma_nodes"`, `sourceKey = <node id>`,
     * and the locale map comes from the environment's SiteMap rather than a
     * hardcoded site pair. Kunstmaan prefixes every URL with its locale on a
     * multilanguage install, which is what the corpus serves; a single-locale
     * environment emits unprefixed sources.
     *
     * A redirect must land on a live page, so entries disabled for the site —
     * structural placeholders included — emit nothing.
     */
    private function emitSectionMoves(EnvironmentContext $context, MigrationOptions $opts, MigrationReport $report): void
    {
        $source = $context->name . ':kuma_nodes';
        $locales = $context->sites->configured();
        $prefixLocale = count($locales) > 1;

        foreach ($this->stateService->entryRows() as $stateRow) {
            if ((string) ($stateRow['source'] ?? '') !== $source) {
                continue;
            }

            $sourceKey = (string) ($stateRow['sourceKey'] ?? '');
            $entryId = (int) ($stateRow['targetId'] ?? 0);

            if ($entryId <= 0 || !ctype_digit($sourceKey)) {
                continue;
            }

            try {
                $this->emitSectionMoveForNode((int) $sourceKey, $entryId, $context->sites, $prefixLocale, $context->name, $opts, $report);
            } catch (\Throwable $e) {
                $report->incr('failed');
                $report->warn(sprintf('section move failed for %s:%s — %s', $source, $sourceKey, $e->getMessage()));
            }
        }
    }

    private function emitSectionMoveForNode(
        int $nodeId,
        int $entryId,
        SiteMap $sites,
        bool $prefixLocale,
        string $environment,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void {
        $legacyUrls = $this->legacyUrlsForNode($nodeId);

        if ($legacyUrls === []) {
            return;
        }

        foreach ($sites->configured() as $lang => $handle) {
            $legacyUrl = $legacyUrls[$lang] ?? null;

            if ($legacyUrl === null) {
                continue;
            }

            $siteId = $sites->siteIdForHandle((string) $handle);

            if ($siteId === null) {
                continue;
            }

            $entry = $this->elements()->findById($entryId, Entry::class, $siteId);

            if ($entry === null || $entry->uri === null || !$entry->enabled || !$entry->getEnabledForSite()) {
                continue;
            }

            $oldPath = $prefixLocale
                ? '/' . $lang . '/' . ltrim($legacyUrl, '/')
                : '/' . ltrim($legacyUrl, '/');

            $uri = $entry->uri === Element::HOMEPAGE_URI ? '' : $entry->uri;
            $entryUrl = (string) ($entry->getUrl() ?? '');
            $newPath = $entryUrl !== ''
                ? ((string) (parse_url($entryUrl, PHP_URL_PATH) ?: '/' . ltrim($uri, '/')))
                : '/' . ltrim($uri, '/');
            $newPath = '/' . ltrim($newPath, '/');

            if (rtrim($oldPath, '/') === rtrim($newPath, '/')) {
                continue;
            }

            $this->upsertRetourRedirect(
                srcUrl: $oldPath,
                destUrl: $newPath,
                httpCode: 301,
                stateKey: sprintf('section_move:%s:%d:%s', $environment, $nodeId, $lang),
                opts: $opts,
                report: $report,
                extraMeta: [
                    'kind' => 'section-move',
                    'environment' => $environment,
                    'kumaNodeId' => $nodeId,
                    'lang' => $lang,
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
            // An offline translation never served its URL; there is nothing to 301.
            if ($lang !== '' && $url !== '' && (int) ($r['online'] ?? 0) === 1) {
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
