# Phase 4 Pattern Map

**Mapped:** 2026-04-26
**Phase:** 04 — Adapters, Verify & Settings
**Files analyzed:** 13 (8 NEW + 5 MODIFIED + 1 NEW-fresh + helpers documented inline)
**Analogs found:** 13 / 13 — every Phase 4 file has either a verbatim-port v1 analog or a v2 prior to extend.

**Conventions used below:**
- v1 paths are absolute under `~/Sites/craft-kunstmaan-migrator/src/...` (the brownfield checkout).
- v2 paths are repo-relative under `src/...` (this checkout, working dir `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited`).
- Excerpts are quoted verbatim; line numbers cite the analog file at the time of mapping.
- "Reshape for v2" notes call out v2 architectural decisions that diverge from v1's surface. Verbatim-port discipline (D-54) governs the body; v2 reshapes touch the shell (namespace, DI seams, hardcoded site handles).

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality | Decision |
|-------------------|------|-----------|----------------|---------------|----------|
| `src/load/SeoMigrationService.php` | service (adapter) | CRUD over state-table + per-site Element write | v1 `bridge/load/SeoMigrationService.php` (600 LOC) | exact (verbatim port) | D-54, D-55, D-56, D-57 |
| `src/load/SeomaticPayloadBuilder.php` | service (pure transform) | request-response (legacy seo-row → SEOmatic field payload) | v1 `bridge/load/SeomaticPayloadBuilder.php` (165 LOC) | exact (verbatim port) | D-54 |
| `src/load/RedirectMigrationService.php` | service (adapter) | batch (kuma_redirects → Retour upsert + section-move 301s) | v1 `bridge/load/RedirectMigrationService.php` (692 LOC) | exact (verbatim port) | D-54, D-55, D-56, D-57 |
| `src/verify/CountGateService.php` | service | request-response (mapping + Craft → gates verdict) | v1 `craft/verify/CountGateService.php` (131 LOC) | exact (verbatim port) | D-54, D-58, D-60 |
| `src/verify/SnapshotDiffer.php` | service (pure compare) | request-response | v1 `craft/verify/SnapshotDiffer.php` (128 LOC) | exact (verbatim port) | D-54 |
| `src/verify/SpotCheckUrlFetcher.php` | service | streaming (HTTP fetch + DOM normalize + diff) | v1 `craft/verify/SpotCheckUrlFetcher.php` (234 LOC) | exact (verbatim port — B1 fix preserved) | D-54, D-58 |
| `src/verify/CaptureBaselineHtmlService.php` | service | file I/O (fetch URL list → write `<slug>.html`) | v1 `craft/verify/CaptureBaselineHtmlService.php` (73 LOC) | exact (verbatim port) | D-54, D-58 |
| `src/verify/BaselineCounterService.php` | service | request-response (Craft → counts JSON) | v1 `craft/verify/BaselineSnapshotService.php` (525 LOC) — **SHAPE-DERIVED, NOT VERBATIM** | shape-derived (light-counts cross-cut) | D-59 |
| `src/console/VerifyController.php` | console controller (top-level) | command (3 actions: index / capture-baseline / capture-baseline-html) | v1 `bridge/console/controllers/VerifyController.php` (343 LOC) + v2 `src/console/MigrateController.php` (multi-action shape) | exact (verbatim port for body; v2 reshapes for action surface + DI) | D-54, D-58, D-60, D-61 |
| `src/Plugin.php` (MODIFY) | bootstrap | DI registration | v2 `src/Plugin.php` lines 98-261 (existing config + init pattern) | role-match | discretion (Phase 4 component additions) |
| `src/models/Settings.php` (MODIFY) | model | property declaration | v2 `src/models/Settings.php` (Phase 1 / D-15 base) | role-match | D-60, D-57, D-64, discretion |
| `src/templates/_settings.twig` (REPLACE) | view template | form render | v2 placeholder + standard Craft `_includes/forms.twig` macros | role-match (template fresh) | D-62, D-63, D-64 |
| `src/console/MigrateController.php` (MODIFY) | console controller | command (add `actionSeo` + `actionRetour` sub-actions per D-55) | v2 `src/console/MigrateController.php` (Phase 3 / Plan 13 base — `actionExtract`/`actionTransform`/`actionLoad`/`actionFinalize`) | role-match (extend pattern) | D-55 |
| `src/console/DoctorController.php` (MODIFY) | console controller | command (add 7th + 8th checks) | v2 `src/console/DoctorController.php` (6-check base) | role-match (extend pattern) | D-69 |
| `src/load/AssetMigrationService.php` (MODIFY) | service | event-driven (RCA emission point) | v2 lines 219-249 (existing `Craft::error('cqm-migrator:asset-failure', ...)`) | exact (extend existing seam) | D-66 |

---

## NEW v2 files — verbatim port (D-54)

### `src/load/SeoMigrationService.php`

- **Role:** Adapter service — kuma_seo → SEOmatic MetaBundles per Craft site.
- **Decision:** D-54 (verbatim port), D-55 (last-stage ordering — runs after entries+assets exist), D-56 (in-service detection — short-circuit when SEOmatic absent), D-57 (table-name override via `Settings::$seoTableName`).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeoMigrationService.php` (600 LOC).

**Class signature + namespace reshape:**

```php
// v1 (analog) — line 3:
namespace lameco\kunstmaanmigrator\bridge\load;

// v2 (target):
namespace lameco\kunstmaanmigrator\load;
```

Drop the `bridge\` segment — v2 dropped the three-tier `kunstmaan/`/`craft/`/`bridge/` layout per `PROJECT.md` Key Decisions ("Drop the three-tier ... layout + Deptrac").

**CONFIG-08 optional-plugin gate (port verbatim — D-56)** — v1 lines 124-133:

```php
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
```

This idiom appears twice in v1 (`migrateAll` line 126 + `migrateForEntry` line 255) — port both call sites.

**Public DI surface (port verbatim with one v2 reshape — D-57 + advisor reconcile):**

```php
// v1 lines 42-58:
public LegacyDbService $legacyDb;
public MigrationStateService $stateService;
public SeomaticPayloadBuilder $seoPayload;
public ?MigrationFilters $filters = null;
public string $seoTableName = 'kuma_seo';
public array $sites = [];
```

**v2 reshape — `$sites` source.** v1 wires `$sites` from a mapping.yaml top-level `sites:` block (line 67-74). v2 already has the canonical kuma-locale → Craft-site-handle map: `Plugin::resolveSitesMap()` at `src/Plugin.php:280-304`, fed into `EntryMigrationService::$sites` at line 260. **Wire `SeoMigrationService::$sites` from the same map** in `Plugin::init()` — no new mapping.yaml block.

**State-driven source discovery (port verbatim — D-55):** v1 lines 162-172:

```php
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
```

This is the load-bearing reason this stage MUST run last (D-55): it walks the state-table for FQCN-derived source names that EntryMigrationService wrote in earlier stages.

**Per-site fan-out (port verbatim with `propagateChanges=false` invariant — Pitfall 2):** v1 lines 442-446:

```php
try {
    $saved = Craft::$app->elements->saveElement($entry, true, false);
} finally {
    Craft::$app->sites->setCurrentSite($previousSite);
}
```

The third arg `false` to `saveElement` is `$propagate` — load-bearing per RESEARCH.md §2 (cited verbatim in v1 docblock line 27-30). Don't drop it.

**What to port verbatim:** the per-locale loop, the optional-plugin gate (both call sites), the state-lookup-driven asset reference resolution via `MigrationStateService::getTargetId('media', 'kuma_media:<id>')`, the per-site `setCurrentSite`/`previousSite` swap, the `STATE_SOURCE = 'seo_meta'` self-exclusion in the source-distinct query.

**What to reshape for v2:**
- Namespace `bridge\load` → `load`.
- `$sites` source → `Plugin::resolveSitesMap()` (already wires `EntryMigrationService::$sites`).
- DI registration via `Plugin::config()` + `Plugin::init()` sibling-wiring (Phase 02.1 / commit 75a95bc pattern shown in v2 Plugin.php lines 199-260).
- `MigrationOptions` import path: v1 `lameco\kunstmaanmigrator\craft\load\MigrationOptions` → v2 `lameco\kunstmaanmigrator\load\MigrationOptions` (already lives at `src/load/MigrationOptions.php` per Phase 3 / Plan 03-02).
- `MigrationReport` import path: v1 `lameco\kunstmaanmigrator\models\MigrationReport` → v2 `lameco\kunstmaanmigrator\load\MigrationReport` (already at `src/load/MigrationReport.php` per Phase 3 / Plan 03-12).

---

### `src/load/SeomaticPayloadBuilder.php`

- **Role:** Pure-function helper — converts a kuma_seo row into the SEOmatic `seo` field payload (`metaGlobalVars` + `metaBundleSettings`).
- **Decision:** D-54 (verbatim port).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeomaticPayloadBuilder.php` (165 LOC).

**Class signature:**

```php
// v1 lines 40-46:
class SeomaticPayloadBuilder extends Component
{
    private ?Closure $resolver = null;
    public ?MigrationStateService $migrationState = null;

    public function build(?array $seoRow, int $siteId): array
```

**Core mapping — port verbatim** — v1 lines 81-88 (the locked column→payload contract per RESEARCH.md §2 + MIGRATION-PLAN.md §7):

```php
$metaGlobalVars = [
    'seoTitle' => $metaTitle,
    'seoDescription' => $metaDescription,
    'seoImage' => $ogImageId !== null ? (string) $ogImageId : '',
    'ogTitle' => $ogTitle,
    'ogDescription' => $ogDescription,
    'ogImage' => $ogImageId !== null ? (string) $ogImageId : '',
];
```

The `'fromCustom'` source-key idiom (v1 lines 97-102) is load-bearing for the EN-locale "no leakage of NL content" invariant — port verbatim with the docblock explanation intact.

**State-driven media resolution** — v1 lines 152-164:

```php
private function lookupCraftAssetId(int $kumaMediaId): ?int
{
    if ($this->resolver !== null) {
        $result = ($this->resolver)($kumaMediaId);
        return $result === null ? null : (int) $result;
    }
    if ($this->migrationState === null) {
        return null;
    }
    return $this->migrationState->getTargetId('media', 'kuma_media:' . $kumaMediaId);
}
```

The `setResolver()` test seam (v1 lines 126-129) is preserved — Phase 5 unit tests will use it without a Craft bootstrap.

**What to reshape for v2:** namespace `bridge\load` → `load`; `MigrationStateService` import path becomes `lameco\kunstmaanmigrator\load\MigrationStateService`.

---

### `src/load/RedirectMigrationService.php`

- **Role:** Adapter service — kuma_redirects → Retour `retour_static_redirects` import + section-move 301 emission for migrated entries.
- **Decision:** D-54 (verbatim port), D-55 (sub-action surface for `migrate/retour`), D-56 (in-service detection — short-circuit when Retour absent), D-57 (`Settings::$redirectsTableName` override).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/RedirectMigrationService.php` (692 LOC).

**Class signature:**

```php
// v1 lines 59-78:
class RedirectMigrationService extends Component
{
    public LegacyDbService $legacyDb;
    public MigrationStateService $stateService;
    public ?MigrationFilters $filters = null;
    public string $redirectsTableName = 'kuma_redirects';
    private const STATE_SOURCE = 'redirect';
    private const SECTION_MOVE_SOURCES = ['team', 'news', 'cases'];
```

**Optional-plugin double gate (port verbatim — D-56)** — v1 lines 96-118:

```php
if (Craft::$app->plugins->getPlugin('retour') === null) {
    Craft::warning('Retour plugin not installed; skipping redirect migration pass.', 'kunstmaanmigrator');
    $report->warn('Retour plugin not installed; redirect migration skipped.', ['retour_loaded' => false]);
    return $report;
}
// Secondary defensive — class-exists / $plugin-null guard
if (!class_exists(Retour::class) || Retour::$plugin === null) {
    $report->incr('failed');
    $report->warn('Retour plugin not loaded (class/plugin null); redirect migration aborted.', ['retour_loaded' => false]);
    return $report;
}
```

**Idempotent upsert (port verbatim — Pitfall 5):** v1 lines 615-637:

```php
$existing = Retour::$plugin->redirects->getRedirectByRedirectSrcUrl($srcUrl, null);

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
// checkForRedirectLoop=false — operator audits manually
if (!Retour::$plugin->redirects->saveRedirect($config, false)) {
    // ...
}
```

The lookup-then-pass-id-into-config sequence is load-bearing — Retour's `saveRedirect` takes the update branch only when `id` is present in the config.

**v2 RESHAPE — drop hardcoded `'default'` + `'en'` site handles (advisor flag):**

v1 hardcodes Craft site handles at two call sites:
- `lookupNewUrlByLegacyUrl` (lines 350-354) — `getSiteByHandle('en')` / `getSiteByHandle('default')`.
- `emitSectionMoveForOne` (lines 461-462) — `$nlSite = $sites->getSiteByHandle('default'); $enSite = $sites->getSiteByHandle('en');`.

v2 must replace these with iteration over `Plugin::resolveSitesMap()` (the same map fed to SeoMigrationService) so this service works on any client whose Craft site handles aren't `default`/`en`. Reshape calls out the legacy CQM-only assumption.

**What to port verbatim:** the upsert pattern + idempotency guard, the section-move 301 emitter, the legacy URL lookup with filter-aware WHERE clauses, the `truncate()` method that walks state rows by `STATE_SOURCE = 'redirect'`, the `normalisePath()` helper.

**What to reshape for v2:**
- Namespace + import paths (same as SeoMigrationService).
- Drop hardcoded `'default'` + `'en'` site handles → iterate `$this->sites` (kuma-locale → Craft-handle map).
- Inject `$sites` from `Plugin::resolveSitesMap()` in `Plugin::init()`.
- Mapping.yaml `verify.tolerance` reads (none — Retour service doesn't read mapping.yaml; SEO doesn't either; D-60 keeps mapping.yaml clean).

---

## NEW v2 files — verbatim port (verify cluster — D-54)

### `src/verify/CountGateService.php`

- **Role:** Pure-function gate — compares mapping-declared counts against live Craft counts within tolerance.
- **Decision:** D-54 (verbatim port), D-58 (Gate 1 of two), D-60 (tolerance via `Settings::$verifyCountTolerance` + CLI override, NOT mapping.yaml).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CountGateService.php` (131 LOC).

**Class signature + return shape:**

```php
// v1 lines 40-46:
class CountGateService extends Component
{
    /**
     * @param array<string, mixed> $mapping  Loaded mapping.yaml
     * @return array{pass: bool, gates: array<string, array<string, mixed>>}
     */
    public function run(array $mapping): array
```

**v2 reshape — D-60 (tolerance source):** v1 reads tolerance from mapping.yaml (lines 48-52):

```php
$tolerance = (float) (
    $mapping['verify']['tolerance']
    ?? $mapping['runtime']['countTolerance']
    ?? 0.05
);
```

v2 reshapes per D-60: tolerance comes from `Settings::$verifyCountTolerance` (default 0.01) with optional CLI `--count-tolerance=` override. The `run()` signature changes from `run(array $mapping)` to `run(array $expectedCounts, float $tolerance)` (or similar), with the controller doing the Settings+CLI merge before calling.

**Per-key delta calculation (port verbatim):** v1 lines 76-82:

```php
$delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
$pass = $actual >= 0 && $delta <= $tolerance;
if (!$pass) {
    $overallPass = false;
}
$gates[$sectionHandle] = ['expected' => $expected, 'actual' => $actual, 'delta' => $delta, 'pass' => $pass];
```

**Asset count from state table (port verbatim — load-bearing seam):** v1 lines 89-99:

```php
$actual = (int) (new Query())
    ->from('{{%kunstmaanmigrator_state}}')
    ->where(['source' => 'media', 'targetType' => 'asset'])
    ->count();
```

The state-table-as-canonical pattern (not `Asset::find()->volume()`) is load-bearing per the v1 docblock — assets share a volume with pre-existing assets.

**What to port verbatim:** the three count categories (sections / assets / plugins-seomatic), the per-key delta formula, the `'plugins:seomatic'` optional-plugin gate (v1 lines 109-127), the SEOmatic count via `seomatic_metabundles` + `sourceBundleType=section`.

**What to reshape for v2:**
- Tolerance source per D-60 (Settings + CLI, not mapping.yaml).
- Read expected counts from `baseline.json` (D-59 BaselineCounterService output), NOT mapping.yaml `verify.expectedCounts`.
- Add a Retour count gate (v1 has SEOmatic only; D-58 spec extends to retour redirect count). Use `kunstmaanmigrator_state` source=`redirect` rows as authoritative count source — already populated by RedirectMigrationService::upsertRetourRedirect.
- Add taxonomy count gate (D-59 enumerates taxonomy counts per category-group handle as part of baseline shape).

---

### `src/verify/SnapshotDiffer.php`

- **Role:** Pure deep-diff helper — emits `[{path, baseline, current}]` triples for any two snapshot arrays.
- **Decision:** D-54 (verbatim port).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SnapshotDiffer.php` (128 LOC).

**Class signature:**

```php
// v1 lines 19-31:
class SnapshotDiffer extends Component
{
    private const META_IGNORE = ['generatedAt', 'gitSha'];

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return array<int, array{path: string, baseline: mixed, current: mixed}>
     */
    public function diff(array $baseline, array $current): array
```

**Recursion shape — port verbatim:**
- `compareAssoc` for assoc arrays, `compareList` for list arrays, `compareValue` dispatcher.
- `META_IGNORE` for volatile keys (`generatedAt`, `gitSha`).

**What to port verbatim:** the entire 128-LOC body — pure-function, no Craft imports, zero refactor needed.

**What to reshape for v2:**
- Namespace `craft\verify` → `verify`.
- Use is bounded — Phase 4 `verify` Gate 1 ships count-match (CountGateService, scalar comparison), not the full deep-diff. SnapshotDiffer is ported but stays *unused* until a future `verify capture-baseline --deep` reintroduces the SHA path. Document this in RECONCILIATION.md as an "infrastructure ported in advance, not wired yet" note. (Alternative: defer port. Researcher chose to port — 128 LOC, no maintenance cost.)

---

### `src/verify/SpotCheckUrlFetcher.php`

- **Role:** HTTP fetch + DOM normalize + line-level diff — Gate 2 backbone (URL HTML diff against baseline).
- **Decision:** D-54 (verbatim port), D-58 (B1 fix preserved — real diff, not byte count).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SpotCheckUrlFetcher.php` (234 LOC).

**B1 fix surface (port verbatim — D-58 cites this verbatim):** v1 lines 78-111 (`diff()`):

```php
public function diff(string $urlOrHtml, string $otherHtml): string
{
    $liveHtml = preg_match('#^https?://#i', $urlOrHtml) === 1
        ? $this->fetchAndNormalize($urlOrHtml)
        : $this->normalize($urlOrHtml);

    $baseHtml = $this->normalize($otherHtml);

    if ($liveHtml === $baseHtml) {
        return '';
    }

    // Compact line-level diff — every line present on exactly one side
    // becomes a prefixed entry.
    $liveLines = preg_split("/\r\n|\r|\n/", $liveHtml) ?: [];
    $baseLines = preg_split("/\r\n|\r|\n/", $baseHtml) ?: [];
    $liveSet = array_flip($liveLines);
    $baseSet = array_flip($baseLines);

    $out = [];
    foreach ($baseLines as $line) {
        if (!array_key_exists($line, $liveSet)) {
            $out[] = '- ' . $line;
        }
    }
    foreach ($liveLines as $line) {
        if (!array_key_exists($line, $baseSet)) {
            $out[] = '+ ' . $line;
        }
    }
    return implode("\n", $out);
}
```

This replaced v1's earlier byte-count proxy that produced false-pass results. Port byte-for-byte; do not "improve."

**Volatile-markup strip list (port verbatim):** v1 lines 34-46:

```php
private const STRIP_PATTERNS = [
    '#<input[^>]*name=["\']CRAFT_CSRF_TOKEN["\'][^>]*>#si',
    '#<meta[^>]*name=["\']csrf-token["\'][^>]*>#si',
    '#<!--\s*Blitz[^>]*?-->#is',
    '#<script[^>]*src=["\']https?://localhost:3000/@vite/client["\'][^>]*></script>#si',
    '#\s+data-[a-z-]+="\d{4}-\d{2}-\d{2}T[^"]*"#i',
    '#\?(?:v|ts)=\d+#i',
];
```

**Fetch with Guzzle + streams fallback (port verbatim):** v1 lines 139-179. The dual-path fetch matters for environments where Guzzle isn't usable (sandboxed test runs).

**What to reshape for v2:**
- Namespace `craft\verify` → `verify`.
- v1's `diffAgainstBaseline` stub (lines 125-133) returns `[]` — v2 should either land the real implementation in this phase OR keep the stub and call it out in RECONCILIATION.md (planner's call). The actual baseline-diff loop lives inside VerifyController::actionIndex (v1 pattern).

---

### `src/verify/CaptureBaselineHtmlService.php`

- **Role:** File-I/O service — reads `spot-check-urls.txt`, fetches each URL via SpotCheckUrlFetcher, writes `<slug>.html`.
- **Decision:** D-54 (verbatim port).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CaptureBaselineHtmlService.php` (73 LOC).

**Class signature + capture loop (port verbatim):** v1 lines 20-67:

```php
class CaptureBaselineHtmlService extends Component
{
    public ?SpotCheckUrlFetcher $fetcher = null;

    public function capture(string $urlListPath, string $outputDir): int
    {
        if (!is_file($urlListPath)) {
            throw new \RuntimeException("URL list not found: {$urlListPath}");
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Cannot create baseline dir: {$outputDir}");
        }

        $fetcher = $this->fetcher ?? new SpotCheckUrlFetcher();
        $lines = file($urlListPath);
        // ...
        $urls = array_filter(
            array_map('trim', $lines),
            static fn(string $l): bool => $l !== '' && !str_starts_with($l, '#'),
        );

        $count = 0;
        foreach ($urls as $url) {
            try {
                $html = $fetcher->fetchAndNormalize($url);
                $slug = $this->urlToSlug($url);
                $destination = rtrim($outputDir, '/') . '/' . $slug . '.html';
                if (file_put_contents($destination, $html) === false) {
                    throw new \RuntimeException("Write failed: {$destination}");
                }
                $count++;
            } catch (\Throwable $e) {
                Craft::warning("Baseline capture failed for {$url}: {$e->getMessage()}", __METHOD__);
            }
        }
        return $count;
    }
```

**The `#` comment + blank-line filter** is the canonical operator-curated URL-list shape (D-Discretion `spot-check-urls.txt`). Port verbatim.

**What to reshape for v2:** namespace + SpotCheckUrlFetcher import path only. Body is 73 LOC pure-PHP — no v2 reshape required.

---

## NEW v2 file — fresh-write (D-59)

### `src/verify/BaselineCounterService.php` — SHAPE-DERIVED, NOT VERBATIM PORT

- **Role:** Pure-read service — produces the count-only `baseline.json` shape per D-59 (per-entry-type counts + asset count + per-category-group counts + Retour count + SEOmatic bundle count).
- **Decision:** D-59 (light-counts, NOT v1's full SHA snapshot — explicit drop documented in RECONCILIATION).
- **Closest analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/verify/BaselineSnapshotService.php` (525 LOC) — **shape-derived, NOT verbatim**.

**What to derive from v1, NOT verbatim port:**

The v1 BaselineSnapshotService captures per-entry `contentSha256`, Matrix block sortOrder, asset `hash_file` SHA, normalized-for-hash field-value JSON. **Do not port these.** D-59 explicitly drops the SHA-heavy path; RECONCILIATION.md must document the drop with rationale + a "future `--deep` flag" hook.

**The shape to keep — strip the SHA loop:** v1 lines 184-233 (`captureSections`):

```php
foreach ($sections as $section) {
    $handle = (string) $section->handle;

    $entries = Entry::find()
        ->section($section)
        ->site('*')
        ->status(null)
        ->drafts(null)
        ->revisions(false)
        ->all();

    $countsBySite = [];
    // STRIP: $entryRows = []; $contentSha256; $normalizeForHash; $entry->getSerializedFieldValues()
    foreach ($entries as $entry) {
        $siteHandle = (string) $entry->getSite()->handle;
        $countsBySite[$siteHandle] = ($countsBySite[$siteHandle] ?? 0) + 1;
    }
    ksort($countsBySite);

    $out[$handle] = [
        'totalCount' => count($entries),
        'countsBySite' => $countsBySite,
        // STRIP: 'entries' => $entryRows,
    ];
}
```

**Asset count via state table** (v2 reshape — derive from CountGateService line 91-99 pattern, not v1 BaselineSnapshotService line 244-264):

```php
$assetCount = (int) (new Query())
    ->from('{{%kunstmaanmigrator_state}}')
    ->where(['source' => 'media', 'targetType' => 'asset'])
    ->count();
```

This mirrors the canonical state-table-as-truth seam — also used by CountGateService.

**Output shape (D-59 enumerated):**

```php
[
    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'sections' => [
        '<sectionHandle>' => [
            'totalCount' => N,
            'countsBySite' => ['<siteHandle>' => N, ...],
        ],
        // ...
    ],
    'assets' => [
        'totalCount' => N,
    ],
    'taxonomies' => [
        '<categoryGroupHandle>' => ['totalCount' => N],
        // ...
    ],
    'retour' => ['totalCount' => N],   // (new Query)->from('{{%retour_static_redirects}}')->count() behind plugin gate
    'seomatic' => ['totalCount' => N], // (new Query)->from('{{%seomatic_metabundles}}')->where(['sourceBundleType' => 'section'])->count() behind plugin gate
]
```

**What NOT to port (explicit drop list — for RECONCILIATION.md):**
- `captureMeta()` `gitSha` resolution helper (v1 lines 122-174) — overkill for count-only baseline.
- `normalizeForHash()` (v1) — entire method body.
- `hashAssetBytes()` (v1) — entire method body.
- The `'entries'` array on each section (v1 line 224-228).
- Matrix-block sortOrder normalization (v1 method).
- `SNAPSHOT_FORMAT_VERSION` const — replace with a simple `'format' => 'counts-v1'` string.

---

## NEW v2 file — console controller (D-58)

### `src/console/VerifyController.php`

- **Role:** Top-level console controller mirroring v1's three-action shape (`index` / `capture-baseline` / `capture-baseline-html`). Stays separate from MigrateController per `ROADMAP.md` "5 commands: doctor, analyze, map, migrate, verify".
- **Decision:** D-54 (verbatim port for body), D-58 (three actions), D-60 (tolerance Settings + CLI override), D-61 (markdown-only `VERIFY-<ts>.md`).
- **Closest analog (body):** `~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/VerifyController.php` (343 LOC).
- **Closest analog (v2 console shape):** `src/console/MigrateController.php` (multi-action with `enforceNeverProduction` first, optionAliases, `Plugin::getInstance()`-resolved DI).

**Class header + traits (v2 reshape pattern):**

```php
// v1 lines 40-43:
class VerifyController extends Controller
{
    use NeverProductionTrait;
    public bool $verbose = false;
    public ?string $baseline = null;
    public ?string $urlSpotCheck = null;
    public ?string $output = null;
    public ?string $outputDir = null;
    public ?string $baselineDir = null;
    public ?float $urlDiffThreshold = null;
```

In v2: change parent to `craft\console\Controller` (already used by every v2 controller), keep `use NeverProductionTrait` from `lameco\kunstmaanmigrator\NeverProductionTrait` (Phase 1).

**Per-action options (port verbatim shape):** v1 lines 52-69:

```php
public function options($actionID): array
{
    $base = ['verbose'];
    if ($actionID === 'index') {
        $base[] = 'baseline';
        $base[] = 'urlSpotCheck';
        $base[] = 'baselineDir';
        $base[] = 'urlDiffThreshold';
    }
    if ($actionID === 'capture-baseline') {
        $base[] = 'output';
    }
    if ($actionID === 'capture-baseline-html') {
        $base[] = 'outputDir';
        $base[] = 'urlSpotCheck';
    }
    return array_merge(parent::options($actionID), $base);
}

public function optionAliases(): array
{
    return array_merge(parent::optionAliases(), ['v' => 'verbose']);
}
```

D-65 verbosity (`-v..-vvv`) replaces the simple `bool $verbose` — count `-v` invocations per the git/ssh/rsync convention. Researcher's open question: implementing `-v` count via Yii's option parser. Planner should decide whether to land verbosity here or in a separate plan; the option-alias surface is the seam.

**`actionIndex` — Gate 1 + Gate 2 (port verbatim with Settings reshape):** v1 lines 84-226. Key reshape points:

1. **Tolerance source change** (D-60). v1 reads from mapping.yaml at lines 91-96. v2 reads from `Settings::$verifyCountTolerance` + `--count-tolerance` CLI override.
2. **Plugin::getInstance() DI** — v1 already uses this pattern (line 90: `$module = Plugin::getInstance();`); v2 keeps it. The v2 component names will be different (`$plugin->countGateService`, `$plugin->spotCheckUrlFetcher`, `$plugin->captureBaselineHtmlService`, `$plugin->baselineCounterService`).
3. **`baseline.json` loading** — v1 captures + diffs via `BaselineSnapshotService::capture()` (line 240); v2 reads `baseline.json` from disk via `MappingFile::writeAtomicJson` round-trip.
4. **Stdout color rendering** (port verbatim): v1 lines 119-134:

```php
$this->stdout(sprintf(
    "  %s %s: %d/%d (Delta=%.3f%%)\n",
    $pass ? 'PASS' : 'FAIL',
    $key,
    (int) ($g['actual'] ?? 0),
    (int) ($g['expected'] ?? 0),
    (float) ($g['delta'] ?? 0.0) * 100,
), $pass ? Console::FG_GREEN : Console::FG_RED);
```

**`renderReportMarkdown` (port nearly verbatim — D-61):** v1 lines 302-342:

```php
private function renderReportMarkdown(array $report): string
{
    $lines = [];
    $lines[] = '# Verify Report';
    $lines[] = '';
    $lines[] = '_Generated ' . ($report['timestamp'] ?? '(unknown)') . '_';
    $lines[] = '';
    $lines[] = 'Overall: ' . (($report['pass'] ?? false) ? '**PASS**' : '**FAIL**');
    $lines[] = '';
    $lines[] = '## Count gate (tolerance: ' . ((float) ($report['tolerance'] ?? 0) * 100) . '%)';
    $lines[] = '';
    $lines[] = '| Key | Expected | Actual | Delta | Pass |';
    $lines[] = '|-----|----------|--------|-------|------|';
    foreach ((array) ($report['countGate'] ?? []) as $key => $row) {
        // ...
        $lines[] = "| `{$key}` | {$expected} | {$actual} | {$delta} | {$pass} |";
    }
    // ## URL gate (threshold: ...)
    // | URL | Status | Diff ratio |
    // ...
}
```

**v2 add to `renderReportMarkdown` per D-61:** rows for skipped optional-plugin gates (`SKIP seomatic (plugin not installed)`, `SKIP retour (plugin not installed)`).

**Atomic write (v2 reshape — Phase 2 / D-07):** v1 line 222 uses raw `file_put_contents`:

```php
file_put_contents($path, $this->renderReportMarkdown($report));
```

v2 must use `Plugin::getInstance()->mappingFile->writeAtomic($path, $rendered)` — Phase 2's atomic-write seam (used by Phase 3 / Plan 13's MigrateController::writeReport at v2 src/console/MigrateController.php:769).

**`actionCaptureBaseline` (v2 reshape — D-59 light counts):** v1 lines 233-255 calls `BaselineSnapshotService::capture()` and writes JSON to `.planning/phases/.../BASELINE-<date>.json`. v2 calls `BaselineCounterService::capture()` (the new D-59 light-shape service) and writes to `storage/migration/baseline.json` — stable path, not timestamped, per D-59.

**`actionCaptureBaselineHtml` (port verbatim):** v1 lines 270-292 — straight delegation to `CaptureBaselineHtmlService::capture()`. v2 reshapes only the storage path (`storage/migration/baseline/`) and the URL list path (`storage/migration/spot-check-urls.txt` per Discretion) — both move from `.planning/phases/...` paths into the canonical `storage/migration/` tree.

**`urlToSlug` (port verbatim):** v1 lines 294-297:

```php
private function urlToSlug(string $url): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline';
}
```

**Three-action gate idiom (port verbatim across all 3 methods):**

```php
if (($gate = $this->enforceNeverProduction()) !== null) {
    return $gate;
}
```

This idiom is used identically in every v2 controller (DoctorController:48, AnalyzeController:71, MigrateController:83, MapController per Phase 2 / Plan 04).

---

## MODIFIED v2 files — extension

### `src/Plugin.php` — `config()` + `init()` growth

- **Role:** Bootstrap — register the new Phase 4 services + wire sibling-DI per Phase 02.1 / commit 75a95bc pattern.
- **Decision:** Discretion (component-registration ordering).
- **Base pattern:** v2 `src/Plugin.php` lines 98-137 (existing `config()`) + lines 141-261 (existing `init()` sibling-wiring).

**`config()` additions (insert before the closing of the components array at line 137):**

```php
// Phase 4 additions — adapter services + verify services.
'seoMigrationService'        => SeoMigrationService::class,
'seomaticPayloadBuilder'     => SeomaticPayloadBuilder::class,
'redirectMigrationService'   => RedirectMigrationService::class,
'baselineCounterService'     => BaselineCounterService::class,
'countGateService'           => CountGateService::class,
'snapshotDiffer'             => SnapshotDiffer::class,
'spotCheckUrlFetcher'        => SpotCheckUrlFetcher::class,
'captureBaselineHtmlService' => CaptureBaselineHtmlService::class,
```

**`@property-read` PHPDoc growth** — extend the docblock at lines 53-87 with the 8 new components for IDE autocomplete (mirrors Phase 3's expansion at lines 78-86).

**`init()` sibling-DI wiring (advisor-flagged dependency graph — append after line 260):**

```php
// Phase 4 Adapter wiring.

// SeomaticPayloadBuilder needs migrationState for kuma_media → Craft asset id resolution.
$this->seomaticPayloadBuilder->migrationState = $this->migrationStateService;

// SeoMigrationService — 5 sibling deps + sites map from resolveSitesMap().
$this->seoMigrationService->legacyDb     = $this->legacyDbService;
$this->seoMigrationService->stateService = $this->migrationStateService;
$this->seoMigrationService->seoPayload   = $this->seomaticPayloadBuilder;
$this->seoMigrationService->sites        = $this->resolveSitesMap();
// $filters wired via FilterFactory at command-invocation time, not init() (Phase 02.1 pattern).

// RedirectMigrationService — 3 sibling deps + sites map.
$this->redirectMigrationService->legacyDb     = $this->legacyDbService;
$this->redirectMigrationService->stateService = $this->migrationStateService;
$this->redirectMigrationService->sites        = $this->resolveSitesMap();
// $filters wired at invocation time.

// CaptureBaselineHtmlService → SpotCheckUrlFetcher.
$this->captureBaselineHtmlService->fetcher = $this->spotCheckUrlFetcher;

// BaselineCounterService — pure-read; no sibling deps in v2 light shape.
// CountGateService — pure-read; no sibling deps (mapping arg passed at call time).
// SnapshotDiffer — pure-function; zero deps.
// SpotCheckUrlFetcher — uses Craft::createGuzzleClient; zero plugin-internal deps.
```

**Settings table-name override wiring (D-57 — append after the SeoMigrationService block):**

```php
$settings = $this->getSettings();
if (is_string($settings->seoTableName) && $settings->seoTableName !== '') {
    $this->seoMigrationService->seoTableName = $settings->seoTableName;
}
if (is_string($settings->redirectsTableName) && $settings->redirectsTableName !== '') {
    $this->redirectMigrationService->redirectsTableName = $settings->redirectsTableName;
}
```

---

### `src/models/Settings.php` — 4 new properties + EnvAttributeParserBehavior extension

- **Role:** Settings model — add the 4 Phase 4 fields (D-60 + D-57).
- **Decision:** D-60 (verifyCountTolerance + verifyUrlDiffThreshold), D-57 (seoTableName + redirectsTableName), D-64 (anthropicApiKey EnvAttributeParserBehavior preserved).
- **Base pattern:** v2 `src/models/Settings.php` lines 17-114.

**Property additions (insert after line 52 `dryRunDefault`):**

```php
// Phase 4 / D-60 — verify gate thresholds. Defaults per ROADMAP success criterion 3
// (Phase 4 SC #2 "counts match baseline within tolerance"; D-60 fixes 0.01 / ±1%).
public float $verifyCountTolerance   = 0.01;
public float $verifyUrlDiffThreshold = 0.05;

// Phase 4 / D-57 — adapter source-table overrides for non-CQM Kunstmaan flavours.
// Defaults match the canonical Kunstmaan schema (kuma_seo + kuma_redirects).
public string $seoTableName       = 'kuma_seo';
public string $redirectsTableName = 'kuma_redirects';
```

**`rules()` additions (insert into the array at line 104-112) — ADVISOR FLAG:** the existing `rules()` has no `'number'` validator. Float fields use Yii's `'number'` rule:

```php
[['verifyCountTolerance', 'verifyUrlDiffThreshold'], 'number', 'min' => 0, 'max' => 1],
[['seoTableName', 'redirectsTableName'], 'string'],
```

**`EnvAttributeParserBehavior` additions (advisor flag — float fields are awkward to env-parse):**

```php
// Add to the 'attributes' array at line 60-64 of v2 Settings.php:
'seoTableName', 'redirectsTableName',
// Do NOT add verifyCountTolerance / verifyUrlDiffThreshold — float env-parse is fragile.
// Operators set these via .env as e.g. CRAFT_LEGACY_DB_PORT-style integer-or-string;
// here we keep them as float-typed properties driven from config/CP only.
```

**No `init()` env fallback** — D-60 spec doesn't enumerate env-var defaults for verify thresholds. seoTableName / redirectsTableName don't need env defaults either since their hardcoded defaults match the canonical Kunstmaan schema.

---

### `src/templates/_settings.twig` — full replacement

- **Role:** CP form template — replace Phase 1 placeholder with grouped-section form per D-62.
- **Decision:** D-62 (single page, H2-grouped sections), D-63 (`editableTable` for arrays), D-64 (masked `anthropicApiKey` with env hint).
- **Base pattern:** v2 placeholder at `src/templates/_settings.twig` (17 lines) + standard Craft `_includes/forms.twig` macros.

**Template extends pattern (port from placeholder line 2):**

```twig
{% extends "_layouts/cp" %}
{% set title = "Kunstmaan Migrator — Settings" %}
{% block content %}
    {{ csrfInput() }}
    {{ actionInput('plugins/save-plugin-settings') }}
    {{ hiddenInput('pluginHandle', 'kunstmaan-migrator') }}
    {# ... grouped sections ... #}
{% endblock %}
```

**Field render macros (Craft idiom):**

```twig
{% import "_includes/forms" as forms %}

{# textField — for legacyDbServer, legacyDbDatabase, legacyDbUser, etc. #}
{{ forms.textField({
    label: 'Legacy DB host'|t('kunstmaan-migrator'),
    id: 'legacyDbServer',
    name: 'settings[legacyDbServer]',
    value: settings.legacyDbServer,
    suggestEnvVars: true,
}) }}

{# autosuggestField — for env-var-aware string fields #}
{{ forms.autosuggestField({
    label: 'Anthropic API key'|t('kunstmaan-migrator'),
    id: 'anthropicApiKey',
    name: 'settings[anthropicApiKey]',
    value: settings.anthropicApiKey,
    type: 'password',
    instructions: 'Defaults to `ANTHROPIC_API_KEY` env var; setting here overrides env per Phase 1 / D-14.',
}) }}
```

**`editableTableField` for arrays (D-63):**

```twig
{# defaultEntities — single-column editable table #}
{{ forms.editableTableField({
    label: 'Default entity allow-list'|t('kunstmaan-migrator'),
    id: 'defaultEntities',
    name: 'settings[defaultEntities]',
    cols: {
        entity: { heading: 'Entity handle', type: 'singleline' },
    },
    rows: settings.defaultEntities|map(e => { entity: e }),
    addRowLabel: 'Add entity',
    allowAdd: true,
    allowDelete: true,
    allowReorder: true,
}) }}

{# localeMap — two-column editable table #}
{{ forms.editableTableField({
    label: 'Locale map'|t('kunstmaan-migrator'),
    id: 'localeMap',
    name: 'settings[localeMap]',
    cols: {
        legacy: { heading: 'Legacy locale', type: 'singleline' },
        craft: { heading: 'Craft site handle', type: 'singleline' },
    },
    rows: settings.localeMap,
    addRowLabel: 'Add locale mapping',
}) }}
```

**Section grouping (D-62):**

```twig
<h2>{{ 'Connectivity'|t('kunstmaan-migrator') }}</h2>
{# legacyDbServer, legacyDbPort, legacyDbDatabase, legacyDbUser, legacyDbPassword,
   legacyDbCharset, legacyDbTablePrefix, kunstmaanSourcePath #}

<h2>{{ 'AI'|t('kunstmaan-migrator') }}</h2>
{# anthropicApiKey (masked + env hint), llmModel, llmTimeout, llmInterChunkDelay #}

<h2>{{ 'Defaults'|t('kunstmaan-migrator') }}</h2>
{# defaultEntities, defaultLocales, localeMap, defaultSince, defaultMaxPerEntity, dryRunDefault #}

<h2>{{ 'Verify'|t('kunstmaan-migrator') }}</h2>
{# verifyCountTolerance, verifyUrlDiffThreshold #}

<h2>{{ 'Adapters'|t('kunstmaan-migrator') }}</h2>
{# seoTableName, redirectsTableName #}
```

**No top-level CP nav entry / no Utilities entry** — `Plugin::$hasCpSettings = true` already wired in Phase 1 / D-16; `Plugin::settingsHtml()` at line 311-317 of v2 Plugin.php already returns this template path. The form roundtrips through Craft's standard plugin-settings save handler — no new web controller required.

---

### `src/console/MigrateController.php` — extend with `actionSeo` + `actionRetour`

- **Role:** Console controller — add two sub-actions per D-55 ("standalone `migrate/seo` and `migrate/retour` for resume / debug; in-process pipeline runs them automatically when SEOmatic / Retour are installed").
- **Decision:** D-55 (sub-actions on existing MigrateController per Phase 02.1 / D-42 11-step shape; new SeoController/RetourController would add a controller surface for two methods each — overkill).
- **Base pattern:** v2 `src/console/MigrateController.php` lines 271-473 (`actionExtract`, `actionTransform`, `actionLoad`, `actionFinalize`).

**Pattern for new actions — copy `actionFinalize` (lines 440-473) shape:**

```php
/**
 * Sub-action: write SEOmatic SEO MetaBundles per migrated entry per site.
 * D-55: runs LAST in the in-process pipeline so kuma_seo image refs resolve
 * via state lookup. Standalone for resume / debug after a partial migrate.
 *
 * D-56: short-circuits with WARN when SEOmatic is not installed.
 */
public function actionSeo(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    $this->stdout("Migrate (seo): SEOmatic MetaBundles per migrated entry\n", Console::FG_CYAN);

    $plugin = Plugin::getInstance();
    $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

    if (!$this->live) {
        $this->stdout("  WARN seo skipped (dry-run; pass --live to write SEOmatic bundles)\n", Console::FG_YELLOW);
        return ExitCode::OK;
    }

    // D-13 / Phase 02.1: wire filters at invocation, mirroring v1's
    // setComponents-after-construct pattern.
    $plugin->seoMigrationService->filters = $filters;
    $opts = new MigrationOptions(dryRun: false, force: $this->force, skipAssets: false);

    try {
        $report = $plugin->seoMigrationService->migrateAll($opts);
    } catch (Throwable $e) {
        $this->stderr("  FAIL seo: {$e->getMessage()}\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
    $this->stdout(sprintf(
        "  OK   seo complete (created=%d updated=%d skipped=%d failed=%d)\n",
        (int) ($report->counts['created'] ?? 0),
        (int) ($report->counts['updated'] ?? 0),
        (int) ($report->counts['skipped'] ?? 0),
        (int) ($report->counts['failed'] ?? 0),
    ), Console::FG_GREEN);
    return ExitCode::OK;
}

// actionRetour — same shape, dispatches to $plugin->redirectMigrationService->migrateAll($opts).
```

**`actionIndex` extension (D-55 — bolt-on after `finalize`):** insert SEO + Retour stages between Step 6 (finalize) and Step 7 (REPORT.md) at v2 MigrateController.php lines 236-261:

```php
// Step 6.5 (D-55): SEO stage — runs AFTER finalize so all entries+assets exist.
if ($this->live) {
    $plugin->seoMigrationService->filters = $filters;
    $seoReport = $plugin->seoMigrationService->migrateAll($opts);
    $report->merge($seoReport); // MigrationReport::merge needs to exist or use ->incr/warn loop
    // Stdout summary, mirroring Step 6 finalize line.
}

// Step 6.6 (D-55): Retour stage — same shape.
if ($this->live) {
    $plugin->redirectMigrationService->filters = $filters;
    $retourReport = $plugin->redirectMigrationService->migrateAll($opts);
    $report->merge($retourReport);
}
```

**`writeReport` extension (D-68):** extend `src/console/MigrateController.php:715-774` with three new sections (`## Asset RCA`, `## Skipped stages`, `## Rehearsal summary`) — see "Shared Patterns" below.

---

### `src/console/DoctorController.php` — add 7th + 8th checks

- **Role:** Console controller — extend Phase 1's 6-check sequence with adapter health + verify baseline presence per D-69.
- **Decision:** D-69 (Doctor's 7th + 8th checks; both always exit OK — adapter absence + missing baseline are not FAIL conditions).
- **Base pattern:** v2 `src/console/DoctorController.php` lines 45-69 (orchestration) + lines 81-245 (per-check method shape).

**Orchestration extension (insert at line 62 between checkStateTable and the close):**

```php
// v2 lines 56-63 currently:
$ok = true;
$ok = $this->checkLegacyDb()             && $ok;
$ok = $this->checkApiKey()               && $ok;
$ok = $this->checkStorageDir()           && $ok;
$ok = $this->checkMappingFile()          && $ok;
$ok = $this->checkKunstmaanSourcePath()  && $ok;
$ok = $this->checkStateTable()           && $ok;

// Phase 4 extensions — D-69. Both always return true (INFO not FAIL):
$ok = $this->checkAdapterPlugins()       && $ok;
$ok = $this->checkVerifyBaseline()       && $ok;
```

**`checkAdapterPlugins` (7th check) — pattern derived from `checkApiKey` (lines 110-125):**

```php
/**
 * Check #7 (D-69): adapter plugin presence — informational only.
 * SEOmatic + Retour are optional per ADP-01..03; absence is not a FAIL.
 */
private function checkAdapterPlugins(): bool
{
    $seomatic = Craft::$app->plugins->getPlugin('seomatic');
    if ($seomatic !== null) {
        $version = (string) $seomatic->getVersion();
        $this->stdout("  OK   seomatic v{$version} installed\n", Console::FG_GREEN);
    } else {
        $this->stdout("  INFO seomatic not installed (adapter will skip)\n", Console::FG_YELLOW);
    }

    $retour = Craft::$app->plugins->getPlugin('retour');
    if ($retour !== null) {
        $version = (string) $retour->getVersion();
        $this->stdout("  OK   retour v{$version} installed\n", Console::FG_GREEN);
    } else {
        $this->stdout("  INFO retour not installed (adapter will skip)\n", Console::FG_YELLOW);
    }
    return true; // D-69: always OK — adapter absence is informational.
}
```

**`checkVerifyBaseline` (8th check) — pattern derived from `checkMappingFile` (lines 162-182):**

```php
/**
 * Check #8 (D-69): verify baseline presence — informational only.
 * Operators may run doctor before capturing baseline.
 */
private function checkVerifyBaseline(): bool
{
    $path = Craft::$app->path->getStoragePath() . '/migration/baseline.json';
    if (is_file($path)) {
        $this->stdout("  OK   baseline.json present at {$path}\n", Console::FG_GREEN);
    } else {
        $this->stdout(
            "  INFO baseline.json missing — run `verify capture-baseline` first if you want to gate migrate runs.\n",
            Console::FG_YELLOW,
        );
    }
    return true; // D-69: always OK.
}
```

---

### `src/load/AssetMigrationService.php` — D-66 RCA emission point

- **Role:** Existing service — emit a closed-set `RCA asset=... reason=... path=...` line per D-66.
- **Decision:** D-66 (asset RCA = structured per-asset failure line).
- **Base pattern:** v2 `src/load/AssetMigrationService.php` lines 219-249 (existing `Craft::error('cqm-migrator:asset-failure', ...)` call).

**Existing emission seam (already in place):** v2 lines 234-249 emit a structured `Craft::error` payload with `tag`, `kuma_media_id`, `location`, `file_name`, `mime`, `file_size`, `resolved_path`, `exception_class`, `exception_message`, `trace`. **This is the right seam — D-66 just adds a separate human-readable RCA line per the spec.**

**D-66 emission — append after the `Craft::error(...)` block at line 249:**

```php
// D-66: structured single-line RCA emission. Closed-set reason taxonomy:
//   filesystem_404 | mime_mismatch | too_large | deferred_unresolved
$reason = $this->classifyAssetFailureReason($e, $row);
$relativePath = (string) ($row['location'] ?? '');
Craft::info(
    sprintf(
        'RCA asset=%s reason=%s path=%s',
        $row['id'] ?? '?',
        $reason,
        $relativePath,
    ),
    'kunstmaanmigrator.rca',
);
```

**`classifyAssetFailureReason` (new private method)** — closed-set taxonomy. Researcher confirms the full list against v1's `AssetMigrationService` failure paths; planner specifies:

```php
private function classifyAssetFailureReason(\Throwable $e, array $row): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, 'No such file') || str_contains($msg, 'not found')) {
        return 'filesystem_404';
    }
    if (str_contains($msg, 'mime') || str_contains($msg, 'content_type')) {
        return 'mime_mismatch';
    }
    if (str_contains($msg, 'too large') || str_contains($msg, 'PostMaxSize')) {
        return 'too_large';
    }
    return 'deferred_unresolved';
}
```

**REPORT.md `## Asset RCA` section (D-68) wires through MigrateController::writeReport.** AssetMigrationService just emits — REPORT.md aggregation lives in MigrateController.

---

## Shared Patterns

### NeverProduction gate (Phase 1 / D-20)

**Source:** `src/NeverProductionTrait.php`
**Apply to:** Every Phase 4 console controller action — VerifyController::actionIndex / actionCaptureBaseline / actionCaptureBaselineHtml; MigrateController::actionSeo / actionRetour.

```php
use lameco\kunstmaanmigrator\NeverProductionTrait;

class VerifyController extends Controller
{
    use NeverProductionTrait;

    public function actionIndex(): int
    {
        // D-20: FIRST statement of every action.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }
        // ...
    }
}
```

This is the canonical first-statement gate idiom — verified across v2 DoctorController:48, AnalyzeController:71, MigrateController:83, MapController, and consistent with v1's verify controller line 86-88.

---

### Plugin DI access via `Plugin::getInstance()`

**Source:** v2 `src/console/MigrateController.php:121` (canonical idiom).
**Apply to:** Every Phase 4 console action.

```php
$plugin = Plugin::getInstance();
$filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);
$plugin->seoMigrationService->filters = $filters;
$report = $plugin->seoMigrationService->migrateAll($opts);
```

Component access is property-style after `Plugin::config()` registration. Filter wiring at invocation time mirrors Phase 02.1 patterns (filters not in init() because they're per-CLI-call).

---

### Atomic file writes (Phase 2 / D-07)

**Source:** v2 `src/mapping/MappingFile.php` `writeAtomic()` + `writeAtomicJson()`.
**Apply to:** `VERIFY-<ts>.md`, `baseline.json`, `REPORT.md` extensions, `RECONCILIATION.md`.

```php
$plugin = Plugin::getInstance();
$plugin->mappingFile->writeAtomic($path, $rendered);   // string content
$plugin->mappingFile->writeAtomicJson($path, $array);  // pretty JSON
```

v2 MigrateController:769 already uses this seam — VerifyController must too. **Do not** use raw `file_put_contents` for migrator artifacts (v1 line 222 — explicit reshape).

---

### Optional-plugin runtime detection (D-56)

**Source:** v1 SeoMigrationService:126-133 + v1 RedirectMigrationService:96-118.
**Apply to:** SeoMigrationService, RedirectMigrationService, CountGateService SEOmatic gate, BaselineCounterService SEOmatic + Retour count subtrees, DoctorController checkAdapterPlugins.

```php
if (Craft::$app->plugins->getPlugin('seomatic') === null) {
    Craft::warning('SEOmatic plugin not installed; skipping SEO migration pass.', 'kunstmaanmigrator');
    $report->warn('SEOmatic plugin not installed; SEO migration skipped.');
    return $report; // Or empty array / 0 / null per call site contract.
}
```

The `Craft::warning` log + `$report->warn` push gives operators both a stderr line during the run AND a record in the post-run REPORT.md `## Skipped stages` section (D-68).

---

### Sites map from `Plugin::resolveSitesMap()`

**Source:** v2 `src/Plugin.php:280-304`.
**Apply to:** SeoMigrationService::$sites, RedirectMigrationService::$sites (replaces v1's mapping.yaml `sites:` block + hardcoded `'default'`/`'en'` site handles).

```php
// In Plugin::init() (after the existing EntryMigrationService::sites wiring at line 260):
$this->seoMigrationService->sites      = $this->resolveSitesMap();
$this->redirectMigrationService->sites = $this->resolveSitesMap();
```

This is THE single source of truth for kuma-locale → Craft-site-handle mapping in v2.

---

### Stdout color rendering (Phase 1 / D-19)

**Source:** v2 `src/console/DoctorController.php` (canonical OK/WARN/FAIL/INFO color discipline).
**Apply to:** All Phase 4 controllers.

```php
$this->stdout("  OK   <text>\n", Console::FG_GREEN);
$this->stdout("  INFO <text>\n", Console::FG_YELLOW);    // D-69: adapter / baseline absence
$this->stdout("  WARN <text>\n", Console::FG_YELLOW);
$this->stderr("  FAIL <text>\n", Console::FG_RED);
```

The two-space indent + 5-char left-padded prefix (`OK   ` / `INFO ` / `WARN ` / `FAIL `) is a v2 convention for column alignment. Verbatim from v2 DoctorController.

---

### REPORT.md three new sections (D-68)

**Source:** v2 `src/console/MigrateController.php:715-774` (existing `writeReport`).
**Apply to:** Extension lives entirely in MigrateController::writeReport (the `## Asset RCA`, `## Skipped stages`, `## Rehearsal summary` rows).

**Section ordering proposed:**

1. `## Migration counts (D-52)` — existing.
2. `## Rehearsal summary` (D-68 new) — totals + wall-clock + filter scope + flag + log file path. Top of the new triplet so operators see the headline first.
3. `## Skipped stages` (D-68 new) — adapter absence WARNs.
4. `## Warnings` — existing.
5. `## Failures (D-50)` — existing.
6. `## Asset RCA` (D-68 new) — per-asset failure rows. End of the report so the long enumeration doesn't push the headline off-screen.

---

## No analog found

None — every Phase 4 file has either an exact v1 verbatim port analog (D-54), an exact v2 prior to extend, or a documented shape-derived analog (BaselineCounterService — explicit drop list documented above and to be repeated in RECONCILIATION.md).

---

## Metadata

**v1 brownfield root searched:** `~/Sites/craft-kunstmaan-migrator/src/{bridge,craft,kunstmaan,models}/`
**v2 codebase root searched:** `/Users/macbook25/Sites/craft-kunstmaan-migrator-revisited/src/`
**Pattern extraction date:** 2026-04-26

**Key advisor flags carried into this map:**

1. Settings.php currently has no `'number'` validator — verifyCountTolerance / verifyUrlDiffThreshold need it.
2. EnvAttributeParserBehavior — only seoTableName / redirectsTableName get env-parse; floats are awkward.
3. v2 reshape: `$sites` source = `Plugin::resolveSitesMap()`, NOT a mapping.yaml `sites:` block.
4. v1 RedirectMigrationService hardcodes `'default'` + `'en'` site handles — must be reshaped to iterate `$this->sites`.
5. VerifyController body ports verbatim; reshape only the action surface (Plugin::getInstance, atomic writes, baseline.json from disk not from BaselineSnapshotService).
6. BaselineCounterService is shape-derived (NOT verbatim) — explicit drop list lives in this PATTERNS.md and must be re-cited in Phase 4's RECONCILIATION.md.
7. Plugin::init() Phase 4 sibling-DI dep graph: SeomaticPayloadBuilder → migrationState; SeoMigrationService → legacyDb + stateService + seoPayload + sites; RedirectMigrationService → legacyDb + stateService + sites; CaptureBaselineHtmlService → fetcher.

## PATTERN MAPPING COMPLETE

Mapped 14 files across 3 categories (8 NEW verbatim-port + 1 NEW shape-derived + 5 MODIFIED extension).
