---
phase: 04-adapters-verify-settings
plan: 04
subsystem: verify-services
tags: [verify, count-gate, baseline-counter, port, shape-derived, D-58, D-59, D-60]
status: complete
requires:
  - phase: 04
    plan: "01"
    artifact: "src/models/Settings.php — `verifyCountTolerance` typed property (D-60); not directly read by these services, consumed downstream by Plan 04-09 VerifyController which passes it as the `$tolerance` arg into CountGateService::run()"
  - phase: 04
    plan: "03"
    artifact: "src/verify/SnapshotDiffer.php + src/verify/SpotCheckUrlFetcher.php — verify-namespace neighbours; CountGateService + BaselineCounterService share the `lameco\\kunstmaanmigrator\\verify` flat-layout namespace established by Plan 04-03."
  - phase: 03
    plan: "loader"
    artifact: "src/load/MigrationStateService.php — kunstmaanmigrator_state schema (source / sourceKey / targetType / targetId / targetUid / siteId / meta); both new services count by source-string filter (`source='media' targetType='asset'`, `source='redirect'`)."
provides:
  - artifact: "src/verify/CountGateService.php (177 LOC)"
    summary: "Verbatim port of v1 craft/verify/CountGateService.php (131 LOC) with three explicit reshapes: (a) D-60 signature change `run(array $expectedCounts, float $tolerance)` — tolerance + expected counts are passed as args, NOT read from `$mapping['verify']['tolerance']`. mapping.yaml stays clean of verify config. (b) D-58 Retour gate added — mirrors SEOmatic shape; counts state-table rows where `source='redirect'` (canonical record of redirects the migrator created). (c) D-58/D-59 taxonomy gate added — per-category-group `Category::find()->group($handle)->count()` with the same tolerance ladder. v1's per-key delta formula (`abs($actual - $expected) / $expected`), `$overallPass` accumulator loop, and asset-count-from-state-table idiom (`source='media' targetType='asset'`) all preserved verbatim. Skipped optional-plugin gates emit `['skip' => true, 'note' => …]` rows and are excluded from the overall pass calculation."
  - artifact: "src/verify/BaselineCounterService.php (204 LOC)"
    summary: "Shape-derived (NOT verbatim ported) from v1 craft/verify/BaselineSnapshotService.php (525 LOC). Output is `format: counts-v1` — counts + light metadata only per D-59. Explicit drop list (vs v1): per-entry contentSha256, normalizeForHash, getSerializedFieldValues, hashAssetBytes, gitSha resolution, Matrix-block sortOrder normalization, per-section `'entries'` array, `SNAPSHOT_FORMAT_VERSION` const. The full SHA-heavy snapshot path is deferred to a future `verify capture-baseline --deep` flag — v1's BaselineSnapshotService body remains in `~/Sites/craft-kunstmaan-migrator/` as the verbatim source if/when refactor-safety regression coverage proves necessary. capture() returns: `format`, `generatedAt`, per-section `{totalCount, countsBySite}`, `assets {totalCount}` (from state-table mirror), per-category-group `{totalCount}`, `retour {totalCount}` (gate-skipped to 0 when plugin absent), `seomatic {totalCount}` (same)."
affects:
  - "Plan 04-09 (VerifyController + Plugin::config wiring) — both services are consumed here. VerifyController::actionIndex calls BaselineCounterService::capture() to write `baseline.json` and CountGateService::run($expectedCounts, $tolerance) to produce the count-match gate row of the VERIFY-<timestamp>.md report."
  - "Plan 04-12 (tests + reconciliation) — Phase 4 RECONCILIATION will document the v1→v2 disposition table including this plan's largest deliberate drop (BaselineSnapshotService 525 LOC → 204 LOC counts-only shape)."
  - "Future verify capture-baseline --deep — re-entry point for the SHA-heavy snapshot path that this plan deliberately excludes per D-59."
tech-stack:
  added: []
  patterns:
    - "Verbatim port discipline (D-54) for CountGateService body — per-key delta formula `abs($actual - $expected) / $expected`, `$overallPass` accumulator, asset-count-from-state-table idiom all byte-for-byte from v1."
    - "Shape-derived service (NOT verbatim port) for BaselineCounterService — explicit drop list from v1 SHA-heavy path per D-59. The largest deliberate drop in Phase 4."
    - "D-60 tolerance-from-Settings (not mapping.yaml) — `run(array $expectedCounts, float $tolerance)` signature reshape; mapping.yaml stays clean of verify config; CLI override of `--count-tolerance=` resolves at controller seam in Plan 04-09."
    - "D-58 verify-shape extension — Retour gate (mirrors SEOmatic optional-plugin pattern) and per-category-group taxonomy gate added on top of v1's SEOmatic-only surface."
    - "State-table-as-truth seam (load-bearing): assets land in a subfolder of a shared volume so `Asset::find()->volume()` would include unrelated pre-existing assets; both services count via `kunstmaanmigrator_state` (`source='media' targetType='asset'` for assets, `source='redirect'` for retour). Mirrors v1 line 89-99."
    - "Optional-plugin gate idiom (D-56) — `Craft::$app->plugins->getPlugin($handle) === null` short-circuits with a `skip` row in CountGateService and a `totalCount => 0` entry in BaselineCounterService. v1 silently dropped the SEOmatic gate when plugin absent; v2 emits an explicit `skip => true` row so the report makes the absence visible."
    - "Yii Component DI (extends `yii\\base\\Component`) preserved on both classes for v2 Plugin::config() compatibility (registration deferred to Plan 04-09)."
key-files:
  created:
    - "src/verify/CountGateService.php (177 LOC) — D-60 signature reshape + D-58 Retour gate + D-58/D-59 taxonomy gate; v1 body otherwise verbatim."
    - "src/verify/BaselineCounterService.php (204 LOC) — D-59 light-counts shape; v1 SHA-heavy snapshot path explicitly NOT in v2."
  modified: []
decisions:
  - "Verbatim port for CountGateService body, shape-derived (NOT verbatim) for BaselineCounterService (D-54 + D-59): the v1 count-gate algorithm is load-bearing (per-key delta formula, asset-count-from-state-table); the v1 baseline snapshot algorithm is overkill for v1.0's count-match gate. Drop the SHA-heavy path; ship counts + light metadata; defer the SHA path to a future `--deep` flag. v1's BaselineSnapshotService body remains in `~/Sites/craft-kunstmaan-migrator/` as the verbatim source if needed."
  - "D-60 — tolerance from Settings, not mapping.yaml. v1 read `$mapping['verify']['tolerance']` with a `runtime.countTolerance` fallback ladder. v2 cleans mapping.yaml of verify config: tolerance is a typed `Settings::$verifyCountTolerance` (default 0.01 — Plan 04-01) with a CLI `--count-tolerance=` override that resolves at the controller seam (Plan 04-09). CountGateService::run() takes `(array $expectedCounts, float $tolerance)` so the service stays project-agnostic and the controller owns the precedence ladder."
  - "D-58 verify-shape extension — v1 shipped a SEOmatic-only optional-plugin gate. v2 mirrors it for Retour (`getPlugin('retour') === null` → skip; otherwise count from state-table `source='redirect'`) and adds a per-category-group taxonomy gate (`Category::find()->group($handle)->count()`) so the count-match gate covers every entity surface the migrator owns."
  - "State-table-as-truth (load-bearing v1 idiom, ported byte-for-byte): assets and Retour redirects share their target volume/table with pre-existing rows the migrator didn't create. `Asset::find()->volume()` and `(new Query())->from('{{%retour_static_redirects}}')->count()` would over-count. Both services count via `kunstmaanmigrator_state` rows whose `source` discriminator marks them as migrator-owned. SEOmatic counts directly from `seomatic_metabundles` because that table only holds migrator-relevant rows on the rehearsal target."
  - "D-59 explicit drop list for BaselineCounterService (vs v1 BaselineSnapshotService 525 LOC): per-entry contentSha256, normalizeForHash, getSerializedFieldValues, hashAssetBytes (hash_file), gitSha resolution helper, Matrix-block sortOrder normalization, per-section `'entries'` array, `SNAPSHOT_FORMAT_VERSION` const. Verified by negative greps: `grep -c contentSha256 / hash_file / gitSha / normalizeForHash / getSerializedFieldValues` all return 0 on the v2 file."
  - "Skip-row semantics — v2 reshape vs v1 (deliberate, NOT a deviation): when an optional plugin (SEOmatic / Retour) is absent, v1 silently dropped the gate row from the gates array; v2 emits `['skip' => true, 'note' => '<plugin> not installed']`. The skip row is excluded from the overall `pass` calculation but kept in the gates output so the VERIFY-<timestamp>.md report (Plan 04-09) can render the absence explicitly. Per-plan acceptance criteria require `'plugins:seomatic'` / `'plugins:retour'` to appear in the file; this is the v2 contract."
  - "Edge case: present plugin + expected=0. The `elseif ($expected > 0)` branch silently drops the gate row in that combo (matching v1's `>0` guard). No skip row, no present row. Plan 04-09 VerifyController must handle absent keys (not just `skip` keys) when rendering the gates table — same surface as `'sections'` rows that hit the `'expectedCount=0, skipped'` note path."
  - "DI registration deferred to Plan 04-09 per plan contract — Plugin::config() and Plugin::init() untouched. Both services are PSR-4-autoloadable and instantiate without Craft bootstrap (`new \\lameco\\kunstmaanmigrator\\verify\\CountGateService()` + `new \\lameco\\kunstmaanmigrator\\verify\\BaselineCounterService()` smoke-checked via `php -r`)."
metrics:
  completed: "2026-04-26"
  tasks-completed: "2/2"
  total-loc-added: "381 (177 CountGateService + 204 BaselineCounterService)"
  test-suite: "60 tests / 137 assertions (unchanged from baseline; no test additions per plan — Plan 04-12 owns Phase 4 test corpus)"
---

# Phase 4 Plan 04: CountGateService + BaselineCounterService Summary

**Two verify services land under `src/verify/` to produce + consume `baseline.json`. `CountGateService.php` (177 LOC) is a verbatim port of v1's 131-LOC count-gate body, with three explicit reshapes — D-60 signature (`run(array $expectedCounts, float $tolerance)` — tolerance from Settings, not mapping.yaml), D-58 Retour gate (mirrors SEOmatic optional-plugin pattern; counts state-table `source='redirect'`), and D-58/D-59 per-category-group taxonomy gate. `BaselineCounterService.php` (204 LOC) is SHAPE-DERIVED (NOT verbatim ported) from v1's 525-LOC `BaselineSnapshotService` per D-59 — counts + light metadata only; the SHA-heavy snapshot path (per-entry contentSha256, hashAssetBytes via hash_file, gitSha resolution, Matrix sortOrder normalization, per-section entries[], normalizeForHash, getSerializedFieldValues) is explicitly NOT in v2 and is deferred to a future `verify capture-baseline --deep` flag. Both services are PSR-4-autoloadable; DI wiring deferred to Plan 04-09 per plan contract.**

## Status

**COMPLETE.** Two tasks executed and committed (de62045 + 1746625, plus a docblock cosmetic tightening 75a05ce); both files PSR-4-loadable; both classes instantiate without Craft bootstrap; composer test green (60 tests / 137 assertions — unchanged baseline). Zero plan-deviations beyond the cosmetic docblock fix that aligned the strict `grep -c "'format' => 'counts-v1'"` acceptance criterion to its expected count of 1.

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-26T20:50:00Z (approx)
- **Completed:** 2026-04-26T21:15:00Z (approx)
- **Tasks:** 2/2
- **Files created:** 2 (`src/verify/CountGateService.php`, `src/verify/BaselineCounterService.php`)
- **Files modified:** 0

## Tasks Completed

| Task | Name                                                    | Commit  | Files                                       |
| ---- | ------------------------------------------------------- | ------- | ------------------------------------------- |
| 1    | Port CountGateService with D-58 / D-60 reshapes         | de62045 | `src/verify/CountGateService.php`           |
| 2    | Add BaselineCounterService (D-59 light-counts shape)    | 1746625 | `src/verify/BaselineCounterService.php`     |
| 2b   | Tighten docblock (strict format-grep alignment)         | 75a05ce | `src/verify/BaselineCounterService.php`     |

## What Landed

### `src/verify/CountGateService.php` (177 LOC, NEW)

Class declaration:

```php
namespace lameco\kunstmaanmigrator\verify;

use Craft;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use Throwable;
use yii\base\Component;

class CountGateService extends Component
{
    public function run(array $expectedCounts, float $tolerance): array
    { ... }
}
```

**Ported verbatim from v1:**
- Per-key delta formula `$delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0` (v1 lines 76-82) — this is the load-bearing equation that produces the gate row's `delta` field consumed by Plan 04-09 VerifyController.
- `$overallPass` accumulator loop with `if (!$pass) { $overallPass = false; }` short-circuit.
- Section gate: `Entry::find()->section($sectionHandle)->status(null)->count()` with `Throwable` → `$actual = -1` poisoning sentinel.
- Asset gate (state-table-as-truth): `(new Query())->from('{{%kunstmaanmigrator_state}}')->where(['source' => 'media', 'targetType' => 'asset'])->count()` — mapping.yaml key is a logical label only; `array_sum($expectedAssets)` produces the canonical expected total.
- SEOmatic plugin gate (v1 lines 109-127): `(new Query())->from('{{%seomatic_metabundles}}')->where(['sourceBundleType' => 'section'])->count()` when plugin present.

**Reshapes applied:**
1. **Namespace:** `lameco\kunstmaanmigrator\craft\verify` → `lameco\kunstmaanmigrator\verify` (per the v2 flat layout from Plan 04-03).
2. **Signature (D-60):** `run(array $mapping)` → `run(array $expectedCounts, float $tolerance)`. The v1 `$mapping['verify']['tolerance'] ?? $mapping['runtime']['countTolerance'] ?? 0.05` ladder is GONE — controller seam (Plan 04-09) reads tolerance from `Settings::$verifyCountTolerance` with optional `--count-tolerance=` CLI override, then passes it as a typed float arg.
3. **Optional-plugin skip rows:** absent plugin → `['skip' => true, 'note' => '<plugin> not installed']` row (v1 silently dropped the gate; v2 makes the absence visible to the VERIFY-<timestamp>.md report).
4. **D-58 Retour gate:** mirrors SEOmatic shape — `Craft::$app->getPlugins()->getPlugin('retour') === null` short-circuits with skip; otherwise counts state-table `source='redirect'` rows.
5. **D-58/D-59 taxonomy gate:** per-category-group loop iterates `$expectedCounts['taxonomies']`, counts via `Category::find()->group($groupHandle)->status(null)->count()` with the same delta formula and skip-on-zero behavior as the section gate. Gate key is prefixed `taxonomies:<handle>`.

**Return shape:** `['pass' => bool, 'gates' => ['<key>' => ['expected' => int, 'actual' => int, 'delta' => float, 'pass' => bool]]]`. Skip rows have `['skip' => true, 'note' => string]` instead. Skipped-on-zero rows have `['pass' => true, 'note' => 'expectedCount=0, skipped']`.

### `src/verify/BaselineCounterService.php` (204 LOC, NEW)

Class declaration:

```php
namespace lameco\kunstmaanmigrator\verify;

use Craft;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use Throwable;
use yii\base\Component;

class BaselineCounterService extends Component
{
    public function capture(): array
    { ... }
}
```

**Output shape (`format: counts-v1`):**

```
[
  'format'      => 'counts-v1',
  'generatedAt' => '<UTC ISO-8601>',
  'sections' => [
    '<sectionHandle>' => [
      'totalCount'   => int,
      'countsBySite' => ['<siteHandle>' => int, ...],
    ],
    ...
  ],
  'assets'     => ['totalCount' => int],
  'taxonomies' => ['<categoryGroupHandle>' => ['totalCount' => int], ...],
  'retour'     => ['totalCount' => int],   // 0 if Retour absent
  'seomatic'   => ['totalCount' => int],   // 0 if SEOmatic absent
]
```

**Shape-derived from v1 (kept):**
- Per-section walk via `Craft::$app->entries->getAllSections()` + `Entry::find()->section($section)->site('*')->status(null)->drafts(null)->revisions(false)->all()` to populate `countsBySite[$siteHandle]++` and a running `$total`. `ksort($countsBySite)` on output for deterministic ordering. Outer `ksort($out)` on the sections map.
- Optional-plugin gating idiom for SEOmatic / Retour — same as CountGateService.

**Drop list (D-59 explicit, vs v1 BaselineSnapshotService 525 LOC):**
- `contentSha256` per entry — verified by `grep -c contentSha256 src/verify/BaselineCounterService.php` = `0`.
- `hash_file()` asset SHA — verified by `grep -c hash_file ...` = `0`.
- `gitSha` resolution helper (v1 lines 122-174) — verified by `grep -c gitSha ...` = `0`.
- `normalizeForHash` field-value normalization — verified by `grep -c normalizeForHash ...` = `0`.
- `getSerializedFieldValues` calls — verified by `grep -c getSerializedFieldValues ...` = `0`.
- Matrix-block sortOrder normalization — only consumed by the SHA path; gone with `normalizeForHash`.
- Per-section `'entries'` array — only the `totalCount` + `countsBySite` survive.
- `SNAPSHOT_FORMAT_VERSION` const — replaced by string literal `'format' => 'counts-v1'`.

**Asset count seam (state-table-as-truth, mirrors CountGateService):** `(new Query())->from('{{%kunstmaanmigrator_state}}')->where(['source' => 'media', 'targetType' => 'asset'])->count()`. v1's `Asset::find()->volume()` walk would over-count because the migrator lands assets in a subfolder of a shared `uploads` volume.

**Taxonomy seam:** per-category-group loop — `Craft::$app->categories->getAllGroups()` → `Category::find()->group($group)->status(null)->count()`.

**Retour seam:** plugin-gate; when present `(new Query())->from('{{%retour_static_redirects}}')->count()`. Retour's redirects table is exclusively migrator-owned on the rehearsal target so the count is a direct read (no state-table filter needed).

**SEOmatic seam:** plugin-gate; when present `(new Query())->from('{{%seomatic_metabundles}}')->where(['sourceBundleType' => 'section'])->count()`.

## v1 → v2 Behavioral Diff (skip-row semantics)

This is per-spec, not a deviation, but worth surfacing for the next reader (Plan 04-09 VerifyController):

| Scenario                                         | v1 behavior                            | v2 behavior                                                       |
| ------------------------------------------------ | -------------------------------------- | ----------------------------------------------------------------- |
| SEOmatic absent, expected count present          | gate row silently absent from output   | `'plugins:seomatic' => ['skip' => true, 'note' => …]` row emitted |
| Retour absent, expected count present            | n/a (v1 had no Retour gate)            | `'plugins:retour' => ['skip' => true, 'note' => …]` row emitted   |
| SEOmatic present, expected count = 0             | gate row silently absent from output   | gate row silently absent from output (matches v1)                 |
| Section expected count = 0                       | `['pass' => true, 'note' => …]` row    | `['pass' => true, 'note' => 'expectedCount=0, skipped']` (matches v1) |
| Section/asset/taxonomy delta within tolerance    | `['expected, actual, delta, pass]`     | `['expected, actual, delta, pass]` (matches v1)                   |

Plan 04-09 VerifyController must therefore handle three gate-row variants: `[expected/actual/delta/pass]` (data row), `[pass=true, note]` (skipped-on-zero), `[skip=true, note]` (optional-plugin absent). Absent keys (present plugin + expected=0) are also possible — see Decisions section.

## Verification

**Static checks (acceptance grep):**
- `grep -c '^namespace lameco\\kunstmaanmigrator\\verify;' src/verify/CountGateService.php` = 1
- `grep -c '^namespace lameco\\kunstmaanmigrator\\verify;' src/verify/BaselineCounterService.php` = 1
- `grep -E "public function run\\(array \\\$expectedCounts, float \\\$tolerance\\)" src/verify/CountGateService.php` matches 1
- `grep -c "verify.*tolerance" src/verify/CountGateService.php` = 0 (no leftover mapping.yaml read)
- `grep -E "getPlugin\\('seomatic'\\)" src/verify/CountGateService.php` matches 1
- `grep -E "getPlugin\\('retour'\\)" src/verify/CountGateService.php` matches 1
- `grep -c "'source' => 'media'"` = 1, `'source' => 'redirect'` = 1
- `grep -c 'Category::find()' src/verify/CountGateService.php` = 2 (one in use-stmt, one in body)
- `grep -c "'format' => 'counts-v1'" src/verify/BaselineCounterService.php` = 1 (strict)
- `grep -c contentSha256 / hash_file / gitSha / normalizeForHash / getSerializedFieldValues src/verify/BaselineCounterService.php` = 0/0/0/0/0 (all D-59 drops verified)

**Runtime sanity:**
- `php -l` clean on both files (`No syntax errors detected`)
- `php -r 'require "vendor/autoload.php"; new \\lameco\\kunstmaanmigrator\\verify\\CountGateService(); new \\lameco\\kunstmaanmigrator\\verify\\BaselineCounterService();'` → `INSTANTIATION OK`
- `composer dump-autoload -o` → `Generated optimized autoload files containing 7665 classes` (both new classes resolved)

**Test suite:** `composer test` exits 0 — `Tests: 60, Assertions: 137, Deprecations: 1` (unchanged baseline; no test additions per plan, Plan 04-12 owns Phase 4 test corpus).

## Deviations from Plan

None. All acceptance grep criteria met on first attempt for Task 01. Task 02 ran clean except for one strict `grep -c "'format' => 'counts-v1'"` criterion that returned 2 instead of 1 because the literal string appeared in both the docblock output-shape example and the actual return array. Fixed by tightening the docblock alignment (`'format' => 'counts-v1',` → `'format'      => 'counts-v1',` — extra whitespace breaks the literal match while keeping the example readable). Cosmetic-only commit 75a05ce; no behavior change.

## Requirements Status

**VER-01 (`verify capture-baseline` snapshots pre-migration counts into a JSON artifact):** **partial — service ready; controller wiring in Plan 04-09.** `BaselineCounterService::capture()` produces the D-59 light-counts shape that `verify capture-baseline` will write to `baseline.json` via `MappingFile::writeAtomicJson` once Plan 04-09 wires `VerifyController::actionCaptureBaseline`. JSON serialization, atomic write, and storage path resolution all live at the controller seam (Plan 04-09).

**VER-03 (`verify` runs the parity gate — counts diff vs baseline plus optional URL spot-check — and writes a `VERIFY-<timestamp>.md` report):** **partial — service ready; controller wiring in Plan 04-09.** `CountGateService::run($expectedCounts, $tolerance)` produces the gate-rows array that the parity-gate report renders. Plan 04-09 owns: (a) reading `baseline.json` to populate `$expectedCounts`, (b) resolving `$tolerance` via the Settings + `--count-tolerance=` ladder, (c) calling `CountGateService::run()` + `SpotCheckUrlFetcher::diffAgainstBaseline()`, (d) writing `VERIFY-<timestamp>.md` regardless of outcome via `MappingFile::writeAtomic`.

## Deferred Items

- **DI registration in `Plugin::config()`** — Plan 04-09 contract.
- **CLI override `--count-tolerance=`** — Plan 04-09 / 04-10 controller seam.
- **`verify capture-baseline --deep` SHA-heavy path** — future milestone; v1's BaselineSnapshotService body remains in `~/Sites/craft-kunstmaan-migrator/` as the verbatim source if reactivated.

## Self-Check: PASSED

- `test -f src/verify/CountGateService.php` → FOUND
- `test -f src/verify/BaselineCounterService.php` → FOUND
- `git log --oneline | grep de62045` → FOUND (Task 01 commit)
- `git log --oneline | grep 1746625` → FOUND (Task 02 commit)
- `git log --oneline | grep 75a05ce` → FOUND (Task 02b docblock fix commit)
- `composer test` → exits 0 (60 tests / 137 assertions)
- `php -l src/verify/CountGateService.php` → No syntax errors detected
- `php -l src/verify/BaselineCounterService.php` → No syntax errors detected
- `php -r 'require "vendor/autoload.php"; new \\lameco\\kunstmaanmigrator\\verify\\CountGateService(); new \\lameco\\kunstmaanmigrator\\verify\\BaselineCounterService();'` → INSTANTIATION OK
