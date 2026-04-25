---
phase: 02-schema-mapping-filters
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - src/filter/MigrationFilters.php
  - src/filter/FilterFactory.php
  - src/locale/LocalePreflight.php
  - src/Plugin.php
autonomous: true
requirements:
  - FILT-01
  - FILT-02
  - FILT-03
  - LOC-01
  - LOC-02
requirements_addressed:
  - FILT-01
  - FILT-02
  - FILT-03
  - LOC-01
  - LOC-02
must_haves:
  truths:
    - "MigrationFilters value object exists with three properties (entities, locales, since) — no max-per-entity"
    - "FilterFactory builds MigrationFilters from CLI args + Settings::default* per D-10 merge rules"
    - "LocalePreflight detects Kunstmaan locales from kuma_node_translations.lang"
    - "LocalePreflight::ensure() returns list of unmapped locales (or null) per D-17 LOC-02"
    - "Plugin.php registers filterFactory + localePreflight in components map"
  artifacts:
    - path: "src/filter/MigrationFilters.php"
      provides: "Immutable VO: entities, locales, since (readonly)"
      contains: "final class MigrationFilters"
    - path: "src/filter/FilterFactory.php"
      provides: "Settings + CLI arg → MigrationFilters merge"
      contains: "public function fromCli"
    - path: "src/locale/LocalePreflight.php"
      provides: "Locale detect + preflight gate"
      contains: "public function detect"
      also_contains: "public function ensure"
    - path: "src/Plugin.php"
      provides: "Component registration for filterFactory + localePreflight"
      contains: "'filterFactory'"
  key_links:
    - from: "src/filter/FilterFactory.php"
      to: "src/models/Settings.php"
      via: "Plugin::getInstance()->getSettings()->defaultEntities/defaultLocales/defaultSince"
      pattern: "getSettings\\(\\)->default"
    - from: "src/locale/LocalePreflight.php"
      to: "src/db/LegacyDbService.php"
      via: "Plugin::getInstance()->legacyDbService->queryAll('SELECT DISTINCT lang FROM kuma_node_translations ORDER BY lang')"
      pattern: "legacyDbService->queryAll"
    - from: "src/Plugin.php"
      to: "src/filter/FilterFactory.php"
      via: "config() components map"
      pattern: "'filterFactory'\\s*=>\\s*FilterFactory::class"
---

<objective>
Ship the cross-cutting filter + locale primitives every other Phase 2 plan depends on.

Purpose: Establish `MigrationFilters` (3-property VO per D-12), `FilterFactory` (Settings+CLI merge per D-10), and `LocalePreflight` (detect + LOC-02 hard-fail gate per D-17). Wire all three into `Plugin::config()` so downstream plans (02..06) can resolve them via `Plugin::getInstance()->filterFactory` etc.

Output: 3 new files under `src/filter/` and `src/locale/`, plus `Plugin.php` modified to register the two services. `MigrationFilters` is a pure value object — not a Yii Component, not registered.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/02-schema-mapping-filters/02-CONTEXT.md
@.planning/phases/02-schema-mapping-filters/02-PATTERNS.md

@src/Plugin.php
@src/models/Settings.php
@src/db/LegacyDbService.php
@src/console/DoctorController.php

<interfaces>
<!-- From src/db/LegacyDbService.php (Phase 1) -->
```php
public function queryAll(string $sql, array $params = []): array;
public function streamQuery(string $sql, array $params = []): Generator;
```

<!-- From src/models/Settings.php (Phase 1, D-15) — already declares Phase 2 fields -->
```php
public ?string $mappingPath          = null;
public array   $defaultEntities      = [];
public array   $defaultLocales       = [];
public ?string $defaultSince         = null;
```

<!-- From src/Plugin.php (Phase 1) — current components map shape -->
```php
public static function config(): array
{
    return [
        'components' => [
            'legacyDbService' => LegacyDbService::class,
        ],
    ];
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create MigrationFilters value object (3 readonly properties)</name>
  <files>src/filter/MigrationFilters.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-09 through D-13 — semantics; D-12 — three properties not four)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/filter/MigrationFilters.php" section, lines 590–626)
    - src/models/Settings.php (PHP 8.3 idioms reference; this is a new file but mirror declare(strict_types=1))
    - ~/Sites/craft-kunstmaan-migrator/src/models/MigrationFilters.php (v1 — REFERENCE ONLY, do NOT port semantics; v1 is post-Craft filtering, v2 is legacy-side scoping)
  </read_first>
  <action>
Create `src/filter/MigrationFilters.php` as a final class with three readonly constructor-promoted properties. Exact contents:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\filter;

/**
 * Legacy-side scoping for every Phase 3+ stage (extract / transform / load / verify).
 *
 * NOT v1's MigrationFilters — that's post-Craft (includeDeleted/Offline/Drafts).
 * v2 redesigns for Kunstmaan source filtering per CONTEXT.md D-09..D-13.
 *
 * Empty `entities` / `locales` mean unbounded; null `since` means no date floor.
 *
 * D-12: --max-per-entity is DROPPED from v1.0 scope. Three properties only.
 */
final class MigrationFilters
{
    /**
     * @param list<string> $entities Kunstmaan source class names (e.g. 'NewsPage'); empty = unbounded
     * @param list<string> $locales  Kunstmaan locale codes (e.g. ['nl', 'fr']); empty = unbounded
     * @param string|null  $since    YYYY-MM-DD date floor; column-presence detection per D-11
     */
    public function __construct(
        public readonly array $entities = [],
        public readonly array $locales = [],
        public readonly ?string $since = null,
    ) {
    }
}
```

No methods. No `forSection()` resolver (v1 had one; v2 has none per D-13). No max-per-entity property (D-12).
  </action>
  <verify>
    <automated>php -l src/filter/MigrationFilters.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/filter/MigrationFilters.php` exits 0
    - `grep -c 'final class MigrationFilters' src/filter/MigrationFilters.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\filter;' src/filter/MigrationFilters.php` equals 1
    - `grep -c 'public readonly array \$entities' src/filter/MigrationFilters.php` equals 1
    - `grep -c 'public readonly array \$locales' src/filter/MigrationFilters.php` equals 1
    - `grep -c 'public readonly ?string \$since' src/filter/MigrationFilters.php` equals 1
    - `grep -c 'maxPerEntity' src/filter/MigrationFilters.php` equals 0 (D-12 — must NOT be present)
    - `grep -c 'declare(strict_types=1);' src/filter/MigrationFilters.php` equals 1
  </acceptance_criteria>
  <done>MigrationFilters VO with exactly three readonly properties exists; no max-per-entity reference; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 2: Create FilterFactory service (Settings + CLI args → MigrationFilters)</name>
  <files>src/filter/FilterFactory.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-10 CLI override semantics; empty-string CLI clears default, null falls through)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/filter/FilterFactory.php" section, lines 630–676)
    - src/models/Settings.php (Settings::init() env-fallback pattern; defaultEntities/defaultLocales/defaultSince already declared)
    - src/Plugin.php (Plugin::getInstance() access pattern, getSettings() reference)
    - src/filter/MigrationFilters.php (created in Task 1 — consumes this VO)
  </read_first>
  <action>
Create `src/filter/FilterFactory.php` as a Yii Component that builds a `MigrationFilters` from per-CLI-flag string args plus Settings defaults, per D-10 merge rules. Exact contents:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\filter;

use lameco\kunstmaanmigrator\Plugin;
use yii\base\Component;

/**
 * Builds MigrationFilters from CLI flag values + Settings::default*.
 *
 * Per D-10:
 *   - null CLI arg     → fall through to Settings::default* for that filter
 *   - '' CLI arg       → clear the default (operator wants "no filter on this dimension")
 *   - non-empty string → comma-split (entities/locales) or use as-is (since)
 *
 * Each filter is independent — overriding --entities does not touch --locales.
 */
final class FilterFactory extends Component
{
    public function fromCli(?string $entitiesArg, ?string $localesArg, ?string $sinceArg): MigrationFilters
    {
        $settings = Plugin::getInstance()->getSettings();

        $entities = $entitiesArg !== null
            ? ($entitiesArg === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $entitiesArg)), static fn(string $s): bool => $s !== '')))
            : array_values((array) $settings->defaultEntities);

        $locales = $localesArg !== null
            ? ($localesArg === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $localesArg)), static fn(string $s): bool => $s !== '')))
            : array_values((array) $settings->defaultLocales);

        $since = $sinceArg !== null
            ? ($sinceArg === '' ? null : $sinceArg)
            : $settings->defaultSince;

        return new MigrationFilters(
            entities: $entities,
            locales:  $locales,
            since:    $since,
        );
    }
}
```

Notes:
- Use named arguments on the `MigrationFilters` constructor for readability (PHP 8.3 supports it everywhere).
- `array_values()` wraps the comma-split so the resulting list is always 0-indexed (the VO's `@var list<string>` annotation implies this).
- Trim each token; drop empties (so `--entities=,NewsPage,` doesn't yield ['', 'NewsPage', '']).
  </action>
  <verify>
    <automated>php -l src/filter/FilterFactory.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/filter/FilterFactory.php` exits 0
    - `grep -c 'final class FilterFactory extends Component' src/filter/FilterFactory.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\filter;' src/filter/FilterFactory.php` equals 1
    - `grep -c 'public function fromCli' src/filter/FilterFactory.php` equals 1
    - `grep -c 'Plugin::getInstance()->getSettings()' src/filter/FilterFactory.php` equals 1
    - `grep -c 'defaultEntities' src/filter/FilterFactory.php` equals 1
    - `grep -c 'defaultLocales' src/filter/FilterFactory.php` equals 1
    - `grep -c 'defaultSince' src/filter/FilterFactory.php` equals 1
    - `grep -c 'new MigrationFilters' src/filter/FilterFactory.php` equals 1
  </acceptance_criteria>
  <done>FilterFactory::fromCli() builds MigrationFilters from null/empty/comma-split per D-10; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 3: Create LocalePreflight service (detect + ensure-gate)</name>
  <files>src/locale/LocalePreflight.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-17 LOC-01 detection + LOC-02 hard-fail; --locales subset semantics)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/locale/LocalePreflight.php" section, lines 681–733)
    - src/db/LegacyDbService.php (queryAll signature; Phase 1)
    - src/models/Settings.php (defaultLocales array property)
    - src/filter/MigrationFilters.php (created in Task 1 — used as input to ensure())
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php (lines 352–419: actionDetectLocales reference for SQL shape)
  </read_first>
  <action>
Create `src/locale/LocalePreflight.php` as a Yii Component with two public methods: `detect()` (returns list of locales seen in `kuma_node_translations.lang`) and `ensure(MigrationFilters $filters)` (returns null on pass, or list of unmapped-locale strings on fail). Exact contents:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\locale;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use yii\base\Component;

/**
 * Locale detection (LOC-01) + preflight gate (LOC-02).
 *
 * Detection: SELECT DISTINCT lang FROM kuma_node_translations.
 * Preflight: every detected locale must be either a Craft site handle OR present in
 * Settings::defaultLocales. If --locales is explicitly set, the check is scoped to
 * that subset (operator-scoped run).
 *
 * Per CONTEXT.md D-17: NO silent default-locale fallthrough. If any detected locale
 * is unmapped, ensure() returns the list — caller MUST hard-FAIL.
 */
final class LocalePreflight extends Component
{
    /**
     * Distinct locale codes present in the legacy DB (kuma_node_translations.lang).
     *
     * @return list<string>
     */
    public function detect(): array
    {
        $rows = Plugin::getInstance()->legacyDbService->queryAll(
            'SELECT DISTINCT lang FROM kuma_node_translations ORDER BY lang',
        );
        $out = [];
        foreach ($rows as $r) {
            $lang = (string) ($r['lang'] ?? '');
            if ($lang !== '') {
                $out[] = $lang;
            }
        }
        return $out;
    }

    /**
     * Returns null on pass, or list of unmapped-locale strings on fail.
     *
     * Caller (AnalyzeController / MapController / future MigrateController / VerifyController)
     * is responsible for hard-failing on a non-null return per LOC-02 D-17.
     *
     * @return list<string>|null
     */
    public function ensure(MigrationFilters $filters): ?array
    {
        $detected = $this->detect();

        $craftHandles = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            $craftHandles[] = (string) $s->handle;
        }
        $settingsLocales = array_values((array) Plugin::getInstance()->getSettings()->defaultLocales);

        // If --locales explicitly set, scope check to that subset (operator-scoped run).
        $checkSet = $filters->locales !== [] ? $filters->locales : $detected;

        $unmapped = [];
        foreach ($checkSet as $locale) {
            if (!in_array($locale, $craftHandles, true) && !in_array($locale, $settingsLocales, true)) {
                $unmapped[] = $locale;
            }
        }
        return $unmapped === [] ? null : array_values($unmapped);
    }
}
```

Notes:
- `detect()` is a list, not an associative array — it's consumed for `in_array` checks and for ReportBuilder's locales block.
- `ensure()` short-circuits to null on the empty-unmapped case (every legacy-reading caller treats null as "preflight passed").
- Do NOT inject the locale-suggestion / paste-ready-block rendering here — that lives in `ReportBuilder` (Plan 03). LocalePreflight is detection + gate only.
  </action>
  <verify>
    <automated>php -l src/locale/LocalePreflight.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/locale/LocalePreflight.php` exits 0
    - `grep -c 'final class LocalePreflight extends Component' src/locale/LocalePreflight.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\locale;' src/locale/LocalePreflight.php` equals 1
    - `grep -c 'public function detect' src/locale/LocalePreflight.php` equals 1
    - `grep -c 'public function ensure(MigrationFilters \$filters)' src/locale/LocalePreflight.php` equals 1
    - `grep -c 'kuma_node_translations' src/locale/LocalePreflight.php` equals 1
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\filter\\\\MigrationFilters;' src/locale/LocalePreflight.php` equals 1
  </acceptance_criteria>
  <done>LocalePreflight detects locales from kuma_node_translations and ensure() returns unmapped list or null per LOC-02; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 4: Wire FilterFactory + LocalePreflight into Plugin::config()</name>
  <files>src/Plugin.php</files>
  <read_first>
    - src/Plugin.php (current shape — Phase 1 components map at lines 30–38; @property-read PHPDoc at line 18)
    - src/filter/FilterFactory.php (created in Task 2 — FQCN to register)
    - src/locale/LocalePreflight.php (created in Task 3 — FQCN to register)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/Plugin.php" section, lines 222–246: target shape including @property-read additions)
  </read_first>
  <action>
Modify `src/Plugin.php` to register the two new components in the `config()` components map and add matching `@property-read` docblock annotations. Two edits:

**Edit 1 — at the top of the file, expand the docblock above `class Plugin extends BasePlugin`:**

Find:
```php
/**
 * Kunstmaan → Craft Migrator plugin entrypoint.
 *
 * @property-read LegacyDbService $legacyDbService
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
```

Replace with:
```php
/**
 * Kunstmaan → Craft Migrator plugin entrypoint.
 *
 * @property-read LegacyDbService $legacyDbService
 * @property-read FilterFactory $filterFactory
 * @property-read LocalePreflight $localePreflight
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
```

**Edit 2 — add the two `use` statements next to the existing `use lameco\kunstmaanmigrator\db\LegacyDbService;`:**

Find:
```php
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\models\Settings;
```

Replace with:
```php
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\locale\LocalePreflight;
use lameco\kunstmaanmigrator\models\Settings;
```

**Edit 3 — modify the `config()` components map:**

Find:
```php
    public static function config(): array
    {
        return [
            'components' => [
                // D-15: only Phase-1 component. Phase 2-4 components land in later phases.
                'legacyDbService' => LegacyDbService::class,
            ],
        ];
    }
```

Replace with:
```php
    public static function config(): array
    {
        return [
            'components' => [
                'legacyDbService' => LegacyDbService::class,    // Phase 1
                'filterFactory'   => FilterFactory::class,      // Phase 2 (Plan 01) — D-10 Settings+CLI merge
                'localePreflight' => LocalePreflight::class,    // Phase 2 (Plan 01) — LOC-01 detect + LOC-02 ensure
            ],
        ];
    }
```

Do NOT touch `Plugin::init()`, `createSettingsModel()`, or `settingsHtml()` — those are Phase 1 idioms unaffected by this plan.
  </action>
  <verify>
    <automated>php -l src/Plugin.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/Plugin.php` exits 0
    - `grep -c "'filterFactory'   => FilterFactory::class" src/Plugin.php` equals 1
    - `grep -c "'localePreflight' => LocalePreflight::class" src/Plugin.php` equals 1
    - `grep -c '@property-read FilterFactory \$filterFactory' src/Plugin.php` equals 1
    - `grep -c '@property-read LocalePreflight \$localePreflight' src/Plugin.php` equals 1
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\filter\\\\FilterFactory;' src/Plugin.php` equals 1
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\locale\\\\LocalePreflight;' src/Plugin.php` equals 1
    - `composer test` exits 0 (Phase 1 PluginBootstrapTest still green; the test asserts components map content via reflection — this expansion does not break it)
  </acceptance_criteria>
  <done>Plugin.php registers both new components and PHPDoc reflects them; PHP lint clean; composer test still green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| CLI args → FilterFactory | Untrusted operator-supplied strings (--entities, --locales, --since) cross into a value object that downstream stages will use to scope DB queries. |
| Legacy DB → LocalePreflight | Read-only SELECT against kuma_node_translations. Plugin owns the connection (Phase 1 / D-11) and Phase 1's NeverProductionTrait gates every caller. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-2-01 | T (Tampering) | FilterFactory CLI parse | accept | CLI args are untrusted but the factory only splits on commas + trims. The strings flow into VO properties; downstream consumers (Phase 3+ extract) MUST use parameterized queries (Phase 1 LegacyDbService::queryAll already does this). No SQL is built from these strings in this plan. |
| T-2-02 | I (Information Disclosure) | LocalePreflight error path | mitigate | `ensure()` returns the unmapped-locale list as plain locale codes (e.g. 'fr'). Locale codes are not secrets. The caller renders the paste-ready block via ReportBuilder — no API keys / passwords flow through here. |
| T-2-03 | E (Elevation of Privilege) | LocalePreflight legacy-DB read | mitigate | LocalePreflight does NOT directly enforce NeverProduction — its callers (AnalyzeController in Plan 03, MapController in Plan 04) gate first via `enforceNeverProduction()` per Phase 1 / D-20 before invoking `ensure()`. This plan ships only the service; controllers ship in Plans 03/04 with the gate-first idiom verified there. |
| T-2-04 | S (Spoofing) | n/a | accept | Local CLI / single-operator surface. No meaningful spoofing vector. |
</threat_model>

<verification>
- `php -l` passes on all 4 files (3 new + 1 modified)
- `composer test` exits 0 (Phase 1 smoke test still green — components map expansion is backward-compatible)
- Plugin.php registers exactly 3 components: legacyDbService (Phase 1), filterFactory (new), localePreflight (new)
- MigrationFilters has exactly 3 readonly properties — no maxPerEntity reference anywhere in the file
</verification>

<success_criteria>
1. `MigrationFilters` is a final, immutable, 3-property value object — D-12 honored (no max-per-entity).
2. `FilterFactory::fromCli` correctly merges null/empty-string/comma-split CLI args with `Settings::default*` per D-10.
3. `LocalePreflight::detect` queries `kuma_node_translations.lang` via the Phase 1 `LegacyDbService`.
4. `LocalePreflight::ensure` returns null when every detected locale maps to a Craft site OR is in `Settings::defaultLocales`; returns the unmapped list otherwise.
5. `Plugin::config()` registers both new components and `@property-read` docblock allows IDE / static-analysis resolution.
6. Phase 1's `PluginBootstrapTest` still passes (asserts components map shape — backward-compatible expansion).
</success_criteria>

<output>
After completion, create `.planning/phases/02-schema-mapping-filters/02-01-SUMMARY.md` documenting:
- Files created (with line counts)
- Plugin.php diff summary
- Confirmation that D-12 (max-per-entity dropped) is honored
- Confirmation that LocalePreflight does not produce paste-ready block (deferred to ReportBuilder in Plan 03)
- Any deviation from the action text (should be none)
</output>
