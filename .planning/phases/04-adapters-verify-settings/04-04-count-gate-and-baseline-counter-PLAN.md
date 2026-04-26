---
plan: 04
phase: 04
title: "CountGateService verbatim port + BaselineCounterService shape-derived"
wave: 2
depends_on: ["04-01"]
files_modified:
  - src/verify/CountGateService.php
  - src/verify/BaselineCounterService.php
autonomous: true
requirements_addressed: [VER-01, VER-03]
---

# Plan 04-04: CountGateService + BaselineCounterService

## Objective

Ship the two verify services that produce + consume `baseline.json`:
- `src/verify/CountGateService.php` — verbatim port of v1's 131-LOC count-gate, reshaped per D-60 (tolerance from Settings, not mapping.yaml) and D-58 (extended to cover Retour + taxonomy gates beyond v1's SEOmatic-only surface).
- `src/verify/BaselineCounterService.php` — **shape-derived, NOT verbatim** from v1's 525-LOC `BaselineSnapshotService`. Per D-59 we ship counts + light metadata only; the SHA-heavy path stays dropped (deferred to a future `--deep` flag).

Both are consumed by Plan 04-09 (`VerifyController`).

## Context

- D-54: verbatim port discipline applies to CountGateService body.
- D-58: ship full v1 verify shape (count gate + URL diff gate); B1 fix preserved.
- D-59: counts + light metadata only. NO per-entry contentSha256, NO Matrix sortOrder hash, NO asset hash_file SHA. The drop list lives in PATTERNS.md and is repeated in this plan's RECONCILIATION.
- D-60: tolerance from `Settings::$verifyCountTolerance` (default 0.01) + CLI `--count-tolerance=` override. NOT from mapping.yaml.
- The state-table-as-canonical pattern (`source = 'media'` + `targetType = 'asset'` count) is load-bearing because assets share a volume with pre-existing assets — count from state, not from `Asset::find()`.
- Plugin::config + init wiring lives in Plan 04-09.

## Tasks

<task id="01">
  <action>
Create `src/verify/CountGateService.php`. Port v1's `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CountGateService.php` body, with these explicit reshapes:

1. **Namespace:** `craft\verify` → `verify`.
2. **`run()` signature change (D-60):** v1 reads tolerance from `$mapping['verify']['tolerance']` (lines 48-52). v2 reads tolerance + expected counts as separate args. Change signature to:
   ```php
   public function run(array $expectedCounts, float $tolerance): array
   ```
   Drop the `$mapping['verify']['tolerance']` ladder. Keep the per-key delta calculation (lines 76-82) byte-for-byte.
3. **Asset count from state table (port verbatim — v1 lines 89-99):** the `(new Query())->from('{{%kunstmaanmigrator_state}}')->where(['source' => 'media', 'targetType' => 'asset'])->count()` pattern is load-bearing.
4. **SEOmatic optional-plugin gate (port verbatim — v1 lines 109-127):** `Craft::$app->plugins->getPlugin('seomatic') === null` short-circuits; emit a `'plugins:seomatic' => ['skip' => true, ...]` gate row.
5. **Add Retour count gate (D-58 extension):** mirror the SEOmatic gate — `Craft::$app->plugins->getPlugin('retour') === null` short-circuits with `'plugins:retour' => ['skip' => true, ...]`. When present, count from `kunstmaanmigrator_state` rows with `source='redirect'` (RedirectMigrationService writes these in Plan 04-07).
6. **Add taxonomy count gate (D-58 + D-59 extension):** for each category-group handle in `$expectedCounts['taxonomies']`, count via `Category::find()->group($handle)->count()` and apply the same tolerance.
7. **Return shape:** `['pass' => bool, 'gates' => ['<key>' => ['expected' => int, 'actual' => int, 'delta' => float, 'pass' => bool]]]` (v1 line 41 docblock contract preserved). For skipped gates, include `'skip' => true` and exclude from `pass` calculation.

Keep every other line verbatim from v1 — the per-key delta formula, the `$overallPass` accumulator, the loop shapes.
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/verify/CountGateService.php (entire file — verbatim source for body)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (CountGateService section — exact reshape list, lines 270-329)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-58, D-59, D-60)
    - src/load/MigrationStateService.php (confirm the v2 state-table column names and row source values used by RedirectMigrationService — `source = 'redirect'`)
  </read_first>
  <acceptance_criteria>
    - `test -f src/verify/CountGateService.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\verify;' src/verify/CountGateService.php` returns `1`
    - `grep -c 'class CountGateService extends Component' src/verify/CountGateService.php` returns `1`
    - `grep -E 'public function run\(array \$expectedCounts, float \$tolerance\)' src/verify/CountGateService.php` returns at least `1` (D-60 reshape signature)
    - `grep -c "verify.*tolerance" src/verify/CountGateService.php` returns `0` (no leftover mapping.yaml read; D-60 cleans this)
    - `grep -E "getPlugin\('seomatic'\)" src/verify/CountGateService.php` returns at least `1` (SEOmatic optional gate)
    - `grep -E "getPlugin\('retour'\)" src/verify/CountGateService.php` returns at least `1` (D-58 Retour gate extension)
    - `grep -c "'source' => 'media'" src/verify/CountGateService.php` returns at least `1` (state-table asset count)
    - `grep -c "'source' => 'redirect'" src/verify/CountGateService.php` returns at least `1` (state-table retour count)
    - `grep -c "'plugins:seomatic'" src/verify/CountGateService.php` returns at least `1`
    - `grep -c "'plugins:retour'" src/verify/CountGateService.php` returns at least `1` (D-58 extension)
    - `grep -c 'Category::find()' src/verify/CountGateService.php` returns at least `1` (taxonomy gate)
    - `grep -c 'craft.verify' src/verify/CountGateService.php` returns `0` (no leftover namespace references)
    - `php -l src/verify/CountGateService.php` outputs `No syntax errors detected`
  </acceptance_criteria>
</task>

<task id="02">
  <action>
Create `src/verify/BaselineCounterService.php` from scratch (NOT a verbatim port — D-59 explicitly drops v1's SHA-heavy snapshot). The shape derives from v1's `~/Sites/craft-kunstmaan-migrator/src/craft/verify/BaselineSnapshotService.php` `captureSections()` body but strips:
- `contentSha256` per entry
- Matrix-block sortOrder normalization
- `getSerializedFieldValues` + `normalizeForHash` calls
- `hashAssetBytes`
- `gitSha` resolution helper
- The `'entries'` per-section array

Class signature:
```php
namespace lameco\kunstmaanmigrator\verify;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use yii\base\Component;

class BaselineCounterService extends Component
{
    public function capture(): array
}
```

The `capture()` method walks all sections and returns the D-59 enumerated shape:
```php
[
    'format' => 'counts-v1',
    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'sections' => [
        '<sectionHandle>' => [
            'totalCount' => int,
            'countsBySite' => ['<siteHandle>' => int, ...],
        ],
        ...
    ],
    'assets' => ['totalCount' => int],
    'taxonomies' => ['<categoryGroupHandle>' => ['totalCount' => int], ...],
    'retour' => ['totalCount' => int],   // 0 if Retour absent (gate-skipped)
    'seomatic' => ['totalCount' => int], // 0 if SEOmatic absent
]
```

Implementation specifics:
- Sections: iterate `Craft::$app->entries->getAllSections()`, for each call `Entry::find()->section($section)->site('*')->status(null)->drafts(null)->revisions(false)->all()`, count by `$entry->getSite()->handle`, sort with `ksort`.
- Assets: `(new Query())->from('{{%kunstmaanmigrator_state}}')->where(['source' => 'media', 'targetType' => 'asset'])->count()` (state-table-as-truth seam, mirrors CountGateService).
- Taxonomies: iterate `Craft::$app->categories->getAllGroups()`, for each `Category::find()->group($group)->count()`.
- Retour: gate `Craft::$app->plugins->getPlugin('retour') === null` → `0`; otherwise `(new Query())->from('{{%retour_static_redirects}}')->count()`.
- SEOmatic: gate `Craft::$app->plugins->getPlugin('seomatic') === null` → `0`; otherwise `(new Query())->from('{{%seomatic_metabundles}}')->where(['sourceBundleType' => 'section'])->count()`.

NO `gitSha`, NO entry SHA, NO asset hash. The `'format' => 'counts-v1'` string replaces v1's `SNAPSHOT_FORMAT_VERSION` const.
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/verify/BaselineSnapshotService.php (entire file — shape source, especially captureSections lines 184-233)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (BaselineCounterService SHAPE-DERIVED section, lines 487-570)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-59 explicit drop list)
    - src/verify/CountGateService.php (just-created in Task 01 — confirm asset/Retour/SEOmatic count idioms match)
  </read_first>
  <acceptance_criteria>
    - `test -f src/verify/BaselineCounterService.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\verify;' src/verify/BaselineCounterService.php` returns `1`
    - `grep -c 'class BaselineCounterService extends Component' src/verify/BaselineCounterService.php` returns `1`
    - `grep -c 'public function capture(' src/verify/BaselineCounterService.php` returns `1`
    - `grep -c "'format' => 'counts-v1'" src/verify/BaselineCounterService.php` returns `1`
    - `grep -c "'generatedAt'" src/verify/BaselineCounterService.php` returns at least `1`
    - `grep -c "'totalCount'" src/verify/BaselineCounterService.php` returns at least `4` (sections / assets / taxonomies / retour / seomatic)
    - `grep -c "'countsBySite'" src/verify/BaselineCounterService.php` returns at least `1`
    - `grep -c 'getAllSections' src/verify/BaselineCounterService.php` returns at least `1`
    - `grep -c 'getAllGroups' src/verify/BaselineCounterService.php` returns at least `1`
    - `grep -E "getPlugin\('retour'\)" src/verify/BaselineCounterService.php` returns at least `1`
    - `grep -E "getPlugin\('seomatic'\)" src/verify/BaselineCounterService.php` returns at least `1`
    - `grep -c 'contentSha256' src/verify/BaselineCounterService.php` returns `0` (D-59 drop verified)
    - `grep -c 'hash_file' src/verify/BaselineCounterService.php` returns `0` (D-59 drop verified)
    - `grep -c 'gitSha' src/verify/BaselineCounterService.php` returns `0` (D-59 drop verified)
    - `grep -c 'normalizeForHash' src/verify/BaselineCounterService.php` returns `0` (D-59 drop verified)
    - `grep -c 'getSerializedFieldValues' src/verify/BaselineCounterService.php` returns `0` (D-59 drop verified)
    - `php -l src/verify/BaselineCounterService.php` outputs `No syntax errors detected`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

## Verification

- `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\verify\CountGateService(); new \lameco\kunstmaanmigrator\verify\BaselineCounterService();'` runs without errors.
- The drop list (no `contentSha256` / `hash_file` / `gitSha` / `normalizeForHash` / `getSerializedFieldValues`) is verified by grep above and re-cited in RECONCILIATION below.
- DI wiring deferred to Plan 04-09.

## must_haves

- `src/verify/CountGateService.php` exists and ships with: D-60 signature reshape, D-58 Retour gate, D-58 taxonomy gate, state-table-as-truth asset count.
- `src/verify/BaselineCounterService.php` exists and produces the D-59 light-counts shape (no SHAs).
- v1's full SHA snapshot path is explicitly NOT in v2 — drop verified by negative greps.
- `composer test` stays green.

## RECONCILIATION

**This plan ports CountGateService verbatim and SHAPE-DERIVES (NOT verbatim ports) BaselineCounterService.** The latter case is the largest deliberate drop in Phase 4 — RECONCILIATION below documents the explicit drop list per D-59.

### CountGateService

| v1 rule | v2 disposition |
|---|---|
| Namespace `lameco\kunstmaanmigrator\craft\verify` | **reshaped** — flattened to `verify`. |
| `run(array $mapping)` signature reading `$mapping['verify']['tolerance']` | **reshaped** — D-60 — signature becomes `run(array $expectedCounts, float $tolerance)`. mapping.yaml stays clean of verify config. |
| Per-key delta formula `abs($actual - $expected) / $expected` (v1 lines 76-82) | **ported** — locked formula. |
| Asset count from state table `source='media' targetType='asset'` (v1 lines 89-99) | **ported** — load-bearing. |
| SEOmatic optional-plugin gate (v1 lines 109-127) | **ported** — D-56 idiom. |
| Retour count gate | **added (D-58 extension)** — v1 had SEOmatic only; v2 spec requires Retour parity. State-table source='redirect' is canonical count. |
| Taxonomy count gate | **added (D-58/D-59 extension)** — D-59 baseline shape includes per-category-group counts. |

### BaselineCounterService — SHAPE-DERIVED, NOT VERBATIM (D-59 drop list)

| v1 rule (BaselineSnapshotService 525 LOC) | v2 disposition |
|---|---|
| `captureSections()` per-entry loop with `contentSha256` (v1 lines 184-233) | **dropped intentionally (D-59)** — v2 captures `totalCount` + `countsBySite` only; the per-entry SHA path is overkill for the v1.0 operator workflow ("counts within ±1% of baseline"). |
| `normalizeForHash()` field-value normalization | **dropped intentionally (D-59)** — only consumed by the SHA path. |
| `hashAssetBytes()` asset SHA via `hash_file()` | **dropped intentionally (D-59)** — operators get count parity; byte-level integrity is a future `--deep` flag. |
| Matrix-block `sortOrder` normalization | **dropped intentionally (D-59)** — same rationale. |
| `gitSha` resolution helper (v1 lines 122-174) | **dropped intentionally (D-59)** — overkill for count-only baseline; `format: counts-v1` is enough metadata. |
| `'entries'` per-section array | **dropped intentionally (D-59)** — only the count survives; per-entry list is the SHA-path artifact. |
| `SNAPSHOT_FORMAT_VERSION` const | **dropped intentionally** — replaced with `'format' => 'counts-v1'` string literal in the output array. |
| Section count + countsBySite shape | **shape-derived** — kept; this is the headline operator artifact. |
| Asset count from state table | **shape-derived** — but sourced from `kunstmaanmigrator_state` (CountGateService idiom), not v1's `Asset::find()->volume()->count()`. State-table-as-truth seam is canonical in v2. |
| Taxonomy + Retour + SEOmatic gated counts | **added** — D-59 explicitly enumerates these; v1 BaselineSnapshotService didn't ship them as first-class baseline keys. |

**Future hook:** A `verify capture-baseline --deep` flag is the documented re-entry point for the SHA-heavy path. v1's `BaselineSnapshotService` body remains in `~/Sites/craft-kunstmaan-migrator/` as the verbatim source if/when refactor-safety regression coverage proves necessary.
