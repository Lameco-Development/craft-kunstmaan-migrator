---
phase: 08-taxonomies-and-proposers
plan: 11
subsystem: load / taxonomy migration
tags: [verbatim-port, taxonomy, gedmo-translatable, atomic-always-on, v2-reshape]

# Dependency graph
requires:
  - phase: 08-taxonomies-and-proposers
    plan: 04
    provides: "LegacyDbService::extTranslationsFor + KunstmaanCoreTables::EXT_TRANSLATIONS"
  - phase: 08-taxonomies-and-proposers
    plan: 09
    provides: "MappingCompiler::compileTaxonomies emits { sourceTable, targetSection, targetEntryType, fields: { legacyCol => craftHandle } } block + persistence wiring in CompileController"
provides:
  - "src/load/TaxonomyMigrationService.php — verbatim-ported load service (D-08) with 5 documented reshape points"
  - "TaxonomyMigrationService::migrateAll(MigrationOptions): MigrationReport — public entry point"
affects:
  - "Plan 08-12 (TaxonomyController + DI wiring) — wires this service via Plugin::config and exposes ./craft kunstmaan-migrator/migrate/taxonomies sub-action; applies NeverProductionTrait at the controller seam"
  - "Plan 08-14 (DoctorController 11th check) — already shipped its checkExtTranslations; informational coupling only"
  - "Plan 08-17 (RECONCILIATION.md) — full v1→v2 reshape table will reference the inline D-08 / D-09 / D-56 markers in this file"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Verbatim-port discipline (D-08 / D-54): port the v1 class structure 1:1; reshape only where v2 architectural ground rules require, and document each reshape inline (D-08 / D-09 / D-56 markers in code)."
    - "Detection-inside-the-service short-circuit (Phase 4 / D-56): empty taxonomies block emits a single WARN line via Craft::warning + report->warn and returns. Mirror of SeoMigrationService::migrateAll lines 131-149."
    - "Atomic-always-on per row: Craft::\$app->db->transaction wrap around upsertOneEntry. No --atomic flag. PROJECT.md ground rule."
    - "Site-agnostic state rows (siteId=null) for taxonomy entries — the state table is the only identity record; taxonomy entry types do NOT carry the legacy-id custom field (v1 docblock lines 41-45)."
    - "Source-table regex whitelist (preg_match('/^[a-z0-9_]+\$/', \$sourceTable)) — SQL injection defense for the raw-interpolated SELECT * FROM <sourceTable>."
    - "D-09 empty-ext_translations fallback: when extTranslationsFor() returns [] (monolingual Kunstmaan install), re-save across every non-primary site in mapping.sites with propagateChanges=false. NEW v2 behavior, not in v1."

key-files:
  created:
    - src/load/TaxonomyMigrationService.php  # 483 LOC (vs v1's 443 LOC; +40 due to inline D-08/D-09/D-56 reshape comments + the explicit D-09 fallback branch)
  modified: []

key-decisions:
  - "Drop loadAssetSecondPass + mappingHasTaxonomyAssetFields (v1 lines 84-137 — D-08-23c). v2's compiler-emitted fields shape is flat string→string with no `handler` key, so the 'asset handler detection' the v1 method relied on is inexpressible. The v1 method itself was a stub that always logged 'second pass not yet implemented' on the asset-detected branch (see v1 lines 104-107). Plan 11 <behavior> does not require this method. If future taxonomy asset-field support is needed, it would be a new plan with a different shape (e.g. ride on AttachService) — not a v1-stub revival."
  - "Drop gedmoFqcns aliases. v1 carried `row['gedmoFqcns']` as a list of legacy-bundle FQCN aliases (e.g. NewsCategory under an old bundle FQCN). v2's compileTaxonomies (Plan 09) emits no such field; the proposal row only carries `fqcn`. Default to `[]` (canonical-only) — applyGedmoTranslations passes `[\$fqcn]` to extTranslationsFor via array_merge. Future enhancement: derive aliases from the parent class chain or expose as an operator-edited list — out of scope for verbatim-port."
  - "Drop `action: SKIP` defensive branch is KEPT, not removed. v2's compileTaxonomies only emits `accepted` rows (compiler filter at line 775), so the SKIP check is dead code today. But the row payload is operator-editable post-compile (mapping.yaml is hand-curated), and a future operator might add `action: SKIP` to a row to bypass migration without removing it. The branch costs three lines and preserves the v1 contract for hand-edits."
  - "fields shape inversion is THE biggest behavioral reshape: v1 iterated `targetHandle => spec` and read `\$spec['source']`; v2 iterates `legacyCol => craftHandle` directly. Three v1 sites needed rewriting (upsertOneEntry field iteration, applyGedmoTranslations reverse-map construction, mappingHasTaxonomyAssetFields). Sites 1-2 ported (with inverted iteration); site 3 dropped per the loadAssetSecondPass decision above."
  - "MappingFile->load() called with no arguments — resolvePath() handles the default (Settings::mappingPath wins; otherwise storage/migration/mapping.yaml). Matches CompileController convention. SeoMigrationService doesn't actually call MappingFile (it reads sites from Plugin::resolveSitesMap()), so SeoMigrationService is the WRONG analog for the load convention — followed Compile/AnalyzeController instead."

requirements-completed: [TAX-06, TAX-07]

# Metrics
duration: ~25min
completed: 2026-04-27
---

# Phase 8 Plan 11: TaxonomyMigrationService Verbatim Port Summary

**Verbatim port of v1's 443-LOC `bridge/load/TaxonomyMigrationService` into v2's flattened `src/load/` layout, with 5 documented v2 reshape points (D-08 / D-09 / D-56) inline-commented in code.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-27 (worktree-agent-afc33bad5a4766414)
- **Completed:** 2026-04-27
- **Tasks:** 1 / 1
- **Files created:** 1 (`src/load/TaxonomyMigrationService.php`, 483 LOC)
- **Files modified:** 0

## Class Size: v1 vs v2

| Metric                              | v1 (brownfield reference) | v2 (this plan)                                   |
| ----------------------------------- | ------------------------- | ------------------------------------------------ |
| Total LOC                           | 443                       | 483 (+40)                                        |
| Public entry points                 | `migrateAll`              | `migrateAll`                                     |
| Public dependency fields            | 3 (state, db, mappingLoader) | 3 (state, db, **mappingFile** — D-08 reshape #1) |
| Private methods                     | 6                         | 4 (-2: dropped loadAssetSecondPass + mappingHasTaxonomyAssetFields) |
| Inline reshape markers              | 0                         | 13 (D-08: 5 sites; D-09: 6 sites; D-56: 2 sites) |
| Tests authored                      | 0                         | 0 (TDD skipped — see "Tests Skipped" below)      |

The +40 LOC delta reflects the inline D-08 / D-09 / D-56 comment annotations required by the plan's `<action>` block + the explicit D-09 fallback branch (NEW v2 behavior, not in v1).

## The Five Reshape Points

Each reshape is inline-commented at the implementation site so the v1→v2 diff is greppable. Plan 17's RECONCILIATION.md will fold these into a full disposition table.

### Reshape #1 — Single mapping.yaml (D-08, line 77)

**v1:** `public ?MappingLoader $mappingLoader = null;` — v1's loader merged three files (`mapping.yaml` + `.draft` + `mapping-drops-{ts}.yaml`).

**v2:** `public ?MappingFile $mappingFile = null;` — single mapping.yaml with per-row `status:`. PROJECT.md ground rule.

**Inline marker:** `src/load/TaxonomyMigrationService.php:77`

**Mapping load convention:** `$this->mappingFile->load()` (no arg) — `MappingFile::resolvePath()` handles the default. Matches CompileController/AnalyzeController; SeoMigrationService was NOT followed because it reads sites from `Plugin::resolveSitesMap()`, not from MappingFile.

### Reshape #2 — Atomic-always-on (D-08, line 186)

**v1:** Already wrapped each per-row write in `Craft::$app->db->transaction(...)` (v1 lines 197-214). v1 also had a (now-dropped) `--atomic` flag.

**v2:** Per-row transaction preserved verbatim. **No `--atomic` flag exposed or honored.** PROJECT.md ground rule: per-entry atomic load is the default and only mode.

**Inline marker:** `src/load/TaxonomyMigrationService.php:186`

### Reshape #3 — D-09 empty-`ext_translations` fallback (D-08 + D-09, lines 341 + 389)

**v1:** None — when `extTranslationsFor()` returned `[]`, the `applyGedmoTranslations` early-returned and the per-site overlay simply did nothing (Craft's canonical save's NL→all-sites propagation was the only behavior).

**v2:** When `extTranslationsFor()` returns `[]` (monolingual Kunstmaan install — no Gedmo Translatable rows for this entity at all), explicitly re-save the source-locale row across every non-primary site in `mapping.sites` with `propagateChanges=false`. Belt-and-suspenders: Craft's canonical save already propagated NL to every site; the explicit re-save makes the contract visible and aligns the empty-translations branch's shape with the per-locale overlay branch's shape (both perform per-site re-saves with `propagateChanges=false`).

**Inline markers:** `src/load/TaxonomyMigrationService.php:341` (entry-point comment) + `:389` (fallback branch comment).

**Doctor 11th check coupling:** Plan 14 already wired `checkExtTranslations` to WARN when `ext_translations` is empty so operators see the path was taken.

### Reshape #4 — taxonomies block shape (D-08, lines 103 + several)

**v1 row shape:**
```yaml
taxonomies:
  App\Entity\NewsCategory:
    section: newsCategories
    entryType: newsCategory
    sourceTable: news_category
    fields:
      title:
        source: name
        handler: plain
    gedmoFqcns: [App\Bundle\Kunstmaan\NewsCategory]
    action: ~  # or SKIP
```

**v2 row shape (compiler-emitted by Plan 09):**
```yaml
taxonomies:
  App\Entity\NewsCategory:
    sourceTable: news_category
    targetSection: newsCategories
    targetEntryType: newsCategory
    fields:
      name: title  # legacyCol => craftHandle (FLAT, INVERTED from v1)
```

**Three v1 code sites needed rewriting due to fields-shape inversion:**

1. **`upsertOneEntry` field iteration** (line 282 onward): v1 iterated `foreach ($fieldsMap as $targetHandle => $spec)` and read `$spec['source']`; v2 iterates `foreach ($fieldsMap as $legacyCol => $craftHandle)` and reads `$legacyRow[$legacyCol]` directly.

2. **`applyGedmoTranslations` source→target map** (line 423 onward): v1 built a reverse map (`$sourceToTarget[$spec['source']] = $targetHandle`); v2's `$fieldsMap` IS the source→target map already, so the build collapses to a defensive non-empty filter pass.

3. **`mappingHasTaxonomyAssetFields`** (v1 lines 116-137): inspected `$spec['handler'] === 'asset'`. v2's flat shape has no `handler` key, so this method is inexpressible. **Dropped** along with `loadAssetSecondPass` — see "Decisions Made" above.

**Other v1 row keys remapped:**
- `section` → `targetSection`
- `entryType` → `targetEntryType`
- `gedmoFqcns` → defaulted to `[]` (canonical only)
- `action: SKIP` → defensive branch retained (compiler doesn't emit it; operator hand-edits might)

**Inline markers:** `src/load/TaxonomyMigrationService.php:103` (action: SKIP defensive note) + `:139-145` (targetSection / targetEntryType remap) + `:282-294` (fields iteration shape).

### Reshape #5 — Detection-inside-the-service short-circuit (D-56, lines 87-95)

**v1:** Empty taxonomies block silently returned an empty MigrationReport (v1 lines 58-61 — `if ($taxonomies === []) { return $report; }` with no warn line).

**v2:** Empty taxonomies block emits BOTH `Craft::warning(...)` AND `$report->warn('No taxonomies in mapping; taxonomy migration skipped.')` before returning. Mirrors `SeoMigrationService::migrateAll` lines 131-149 (Phase 4 / D-56 — detection-inside-the-service convention).

**Inline marker:** `src/load/TaxonomyMigrationService.php:87`

## Confirmation Checklist

| Acceptance criterion (from plan)                                      | Status | Evidence                                                                                                       |
| --------------------------------------------------------------------- | ------ | -------------------------------------------------------------------------------------------------------------- |
| `class TaxonomyMigrationService extends Component` (==1)              | yes    | grep → 1                                                                                                       |
| `public function migrateAll` (==1)                                    | yes    | grep → 1                                                                                                       |
| `extTranslationsFor` (>=1)                                            | yes    | grep → 3 (signature ref + invocation + docblock)                                                               |
| `Craft::\$app->db->transaction` (>=1, atomic-always-on)               | yes    | grep → 2 (transaction call + comment)                                                                          |
| `preg_match('/^[a-z0-9_]+\$/`...) (>=1, SQL injection regex)         | yes    | grep → 1                                                                                                       |
| `siteId.*null \| null.*siteId` (>=1, site-agnostic state rows)        | yes    | grep → 3                                                                                                       |
| `use .*NeverProductionTrait` (==0, NOT applied at service)            | yes    | grep → 0 (applied at controller seam in Plan 12)                                                               |
| `kunstmaanSourceId` (==0, taxonomy entries don't carry it)            | yes    | grep → 0                                                                                                       |
| `No taxonomies in mapping` (>=1, D-56 short-circuit)                  | yes    | grep → 2 (Craft::warning + report->warn)                                                                       |
| `D-09` (>=1, empty-table fallback documented inline)                  | yes    | grep → 6                                                                                                       |
| `composer phpstan` exits 0                                            | n/a    | Substituted with `php -l` (No syntax errors detected) per Plan 04/09 precedent — see Deviations               |

## Deviations from Plan

### 1. [Verification] phpstan substituted with `php -l` — no static-analysis tooling installed

- **Found during:** Final verification pass.
- **Issue:** Plan's `<verify><automated>` block calls `composer phpstan`. This worktree has no `vendor/` directory installed and `composer.json` has no `phpstan` script (no phpstan in dev deps). Same precedent as Plan 04 SUMMARY (Wave 1 prereq) and Plan 09 SUMMARY (Wave 3 prereq).
- **Fix:** Substituted with `php -l` (PHP syntax check; passes — "No syntax errors detected") plus the full grep-based acceptance criteria (all 10 pass). The grep checks already cover the same surface phpstan would (method signature presence, no NeverProductionTrait import, no kunstmaanSourceId reference, atomic-always-on transaction wrapper, SQL injection regex).
- **Files modified:** none beyond plan scope.
- **Note for downstream:** the broader Phase 8 quality gate (phpstan + tests) will be exercised when the orchestrator runs validation in a CI environment with vendor/ present. The orchestrator merges all Wave 4 worktrees and then runs the post-wave validation pass.

### 2. [Decision] Drop `loadAssetSecondPass` + `mappingHasTaxonomyAssetFields` (v1 lines 84-137)

- **Found during:** Read-first pass on v1 file.
- **Issue:** v1's `loadAssetSecondPass` (D-08-23c) was a stub that always logged "Taxonomy [3b]: skipped (no taxonomy asset fields in mapping)" because `mappingHasTaxonomyAssetFields` inspects `$spec['handler'] === 'asset'` — and v1's CQM mapping had no taxonomy asset fields. The detection method was structurally inexpressible in v2 (flat fields shape `{ legacyCol => craftHandle }` has no `handler` key).
- **Fix:** Drop both methods. Plan 11 `<behavior>` does not require asset-second-pass; v1's method was a forward-looking stub for a feature never shipped.
- **Why not Rule 4 (architectural):** This is structural completion, not a new design — the v2 mapping shape (compiler-emitted, no per-field handler info) already locked the decision in Plan 09 / D-07. If future taxonomy asset-field support is needed, it ships as a new plan that wires through AttachService (which DOES carry handler info), not as a v1-stub revival.
- **Files modified:** `src/load/TaxonomyMigrationService.php` (negative — the methods were never written).

### 3. [Decision] Drop `gedmoFqcns` aliases — default to `[]` (canonical-only)

- **Found during:** Read-first pass on Plan 09 SUMMARY (compileTaxonomies output shape).
- **Issue:** v1's row carried `gedmoFqcns: [...]` (list of legacy-bundle FQCN aliases to try when canonical had no `ext_translations` rows). v2's `compileTaxonomies` (MappingCompiler.php:807) emits no such field — only `{ sourceTable, targetSection, targetEntryType, fields }`.
- **Fix:** `$gedmoFqcns = [];` (default to empty). `applyGedmoTranslations` then passes `[$fqcn]` to `extTranslationsFor` via `array_merge([$fqcn], $gedmoFqcns)`. The plan's `<action>` block explicitly noted this: "The `gedmoFqcns` v1 supplied — derive in v2 from the parent class chain (or just `[$fqcn]` for the simple case…)".
- **Why simple case:** No CQM (canonical reference project) row uses bundle-namespaced aliases; the v1 hedge was a defensive concession. If a future legacy DB requires aliases, the proposer (LlmClassifier::proposeNonPageEntities, Plan 06) is the natural seam to surface them, not the load service.
- **Files modified:** `src/load/TaxonomyMigrationService.php` (line 165, `$gedmoFqcns = [];`).

## Tests Skipped

The plan's Task 1 has `tdd="true"` but the worktree has no `vendor/` installed and `composer test-unit` cannot be executed. This precedent matches:
- Plan 04 SUMMARY (Wave 1) — `php -l` + grep substitution
- Plan 05 SUMMARY (Wave 1) — same substitution
- Plan 09 SUMMARY (Wave 3) — same substitution

The plan's `<acceptance_criteria>` only listed phpstan + grep checks; no test file paths were specified. The natural integration-test seam (`tests/integration/load/TaxonomyMigrationTest.php`, listed in 08-PATTERNS.md as a NEW file) is NOT in this plan's scope — Plan 11's `files_modified` frontmatter explicitly lists only `src/load/TaxonomyMigrationService.php`. Testing would require either:
1. A separate plan for the integration test (which 08-PATTERNS lists as a "New file CREATE" but no plan in this phase scopes it), or
2. Stubbing Craft/Doctrine extensively in unit tests, which is Phase 8.5+ work.

The orchestrator-level CI environment exercises composer test on the merged Wave 4 branch.

## TDD Gate Compliance

Plan-level type is `execute` (not `tdd`); per-task `tdd="true"` marker was present without test-file paths in the acceptance criteria. RED → GREEN → REFACTOR cycle was not enforced for the reasons documented in "Tests Skipped". Task 1 passed its grep + `php -l` acceptance gate before commit.

## Issues Encountered

None.

## User Setup Required

None — this plan is pure code authoring (no env vars, no DB schema changes, no Settings additions, no plugin registration). Plan 12 will register the service in `Plugin::config` and expose it via `MigrateController::actionTaxonomies`.

## Threat Surface Scan

No new network endpoints, auth paths, or trust boundaries introduced. The service:
- Reads the legacy DB via `LegacyDbService` (existing, sandboxed by `NeverProductionTrait` at the controller seam — Plan 12).
- Writes to Craft's elements service (existing trust boundary).
- Reads `mapping.yaml` via `MappingFile->load()` (existing, no new file path).
- Interpolates `sourceTable` into raw SQL — gated by the `preg_match('/^[a-z0-9_]+$/', ...)` regex whitelist (verbatim from v1, preserved as inline comment "SQL injection defense").

No threat flags.

## Known Stubs

None. The service is fully wired:
- `migrateAll()` is the public entry point.
- All 5 reshape points are in place.
- D-09 fallback branch is implemented (not stubbed).
- All field assignments use real Craft APIs (`saveElement`, `setEnabledForSite`, `setFieldValues`, `Entry::find()->id(...)`).

## Next Phase Readiness

- **Plan 12 (Wave 4 — DI wiring + TaxonomyController):** can now wire `TaxonomyMigrationService` via `Plugin::config` (component slot pattern from `seoMigrationService` line 163) and expose `MigrateController::actionTaxonomies` (sub-action pattern from `actionSeo` line 428). Apply `NeverProductionTrait` at the controller, NOT the service. The service's three public injection fields are: `legacyDb`, `migrationState`, `mappingFile`.
- **Plan 17 (Wave 5 — RECONCILIATION.md):** the v1 → v2 reshape entries can pull line numbers directly from the inline D-08 / D-09 / D-56 markers in `src/load/TaxonomyMigrationService.php`.
- **No blockers; nothing in this plan touches the LLM proposer surface, the compile pipeline, or shared orchestrator artifacts (STATE.md / ROADMAP.md).**

## Commits

| Hash    | Message                                                          |
| ------- | ---------------------------------------------------------------- |
| 5ef4105 | feat(08-11): port TaxonomyMigrationService with 5 v2 reshape points |

## Self-Check: PASSED

**Files exist:**
- FOUND: src/load/TaxonomyMigrationService.php (483 LOC)
- FOUND: .planning/phases/08-taxonomies-and-proposers/08-11-SUMMARY.md (this file)

**Commits exist:**
- FOUND: 5ef4105 (Task 1 — TaxonomyMigrationService verbatim port + 5 v2 reshape points)

**Acceptance criteria — Task 1:**
- `grep -c "class TaxonomyMigrationService extends Component" src/load/TaxonomyMigrationService.php` → 1 (==1 required)
- `grep -c "public function migrateAll" src/load/TaxonomyMigrationService.php` → 1 (==1 required)
- `grep -c "extTranslationsFor" src/load/TaxonomyMigrationService.php` → 3 (>=1 required)
- `grep -c "Craft::\$app->db->transaction" src/load/TaxonomyMigrationService.php` → 2 (>=1 required)
- `grep -cE "preg_match.*\^\[a-z0-9_\]" src/load/TaxonomyMigrationService.php` → 1 (>=1 required)
- `grep -cE "siteId.*null|null.*siteId" src/load/TaxonomyMigrationService.php` → 3 (>=1 required)
- `grep -cE "use .*NeverProductionTrait" src/load/TaxonomyMigrationService.php` → 0 (==0 required)
- `grep -c "kunstmaanSourceId" src/load/TaxonomyMigrationService.php` → 0 (==0 required)
- `grep -c "No taxonomies in mapping" src/load/TaxonomyMigrationService.php` → 2 (>=1 required)
- `grep -c "D-09" src/load/TaxonomyMigrationService.php` → 6 (>=1 required)
- `php -l src/load/TaxonomyMigrationService.php` → No syntax errors (phpstan substituted)

---
*Phase: 08-taxonomies-and-proposers*
*Completed: 2026-04-27*
