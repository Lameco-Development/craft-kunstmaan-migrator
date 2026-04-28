---
phase: 10-generic-migration-rehearsal-gap-closure
verified: 2026-04-28T17:14:30Z
status: gaps_found
score: 18/21 must-haves verified
overrides_applied: 0
gaps:
  - truth: "Missing taxonomy locale values use default-language values and are visibly reported."
    status: failed
    reason: "The empty ext_translations fallback iterates mapping.sites keys as Craft site handles, but compiled mapping convention is legacy locale => Craft site handle. For mappings such as ['nl' => 'default', 'en' => 'enUs'], fallback saves are skipped for non-primary sites and translated taxonomy values can remain incomplete without the intended fallback/report rows."
    artifacts:
      - path: "src/load/TaxonomyMigrationService.php"
        issue: "The empty-translation fallback uses foreach ($sites as $siteHandle => $_siteCfg) and getSiteByHandle((string) $siteHandle), resolving legacy locale keys instead of Craft handles."
      - path: "tests/integration/load/TaxonomyMigrationTest.php"
        issue: "No regression test covers compiled-shape mapping.sites such as ['nl' => 'default', 'en' => 'enUs']."
    missing:
      - "Resolve the Craft handle from mapping.sites value, preserving array-shaped compatibility: siteHandle = is_array($siteCfg) ? ($siteCfg['siteHandle'] ?? $legacyLocale) : $siteCfg."
      - "Add regression coverage for compiled-shape mapping.sites where locale keys differ from Craft handles."
  - truth: "Page-owned taxonomy/relation handler failures are operator-visible and cannot silently lose relation content."
    status: failed
    reason: "Transform handler exceptions, including taxonomy lazy resolver failures, are caught into TransformService's local __report sentinel, but MigrateController discards that sentinel. A live migration can therefore omit a relation field after a resolver/handler exception while the main REPORT.md remains clean."
    artifacts:
      - path: "src/transform/TransformService.php"
        issue: "Handler exceptions are caught and appended only to local $report['warnings']; the report is yielded as a __report sentinel."
      - path: "src/console/MigrateController.php"
        issue: "Payloads with __report are skipped without merging warnings/counters into MigrationReport."
    missing:
      - "Merge __report warnings into the main MigrationReport in MigrateController."
      - "Add regression test proving transform handler warnings appear in REPORT.md."
      - "Consider making live relation/taxonomy handler failures blocking to prevent saving pages with missing relations."
  - truth: "Restored-backup CQM rerun instructions are executable against the current CLI."
    status: partial
    reason: "The runbook documents php craft kunstmaan-migrator/migrate --dry-run, but MigrateController exposes default dry-run behavior through absence of --live; no dry-run option is registered. The 10-04 summary confirms the proof used the actual supported default migrate command."
    artifacts:
      - path: ".planning/rehearsal/v1.0/cqm/README.md"
        issue: "Runbook lists php craft kunstmaan-migrator/migrate --dry-run."
      - path: "src/console/MigrateController.php"
        issue: "options() registers live/confirm/preloadAssets/force/filter options, and comments/behavior show default dry-run; no dry-run option exists."
    missing:
      - "Replace runbook dry-run command with php craft kunstmaan-migrator/migrate."
      - "Optionally document that dry-run is the default and --live is the write mode."
---

# Phase 10: Generic Migration Rehearsal Gap Closure Verification Report

**Phase Goal:** Convert the CQM release-rehearsal findings into generic migration hardening so a clean rerun can complete without the three known entry failures and without silent loss of page-owned relations or content.
**Verified:** 2026-04-28T17:14:30Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Verification Context

- Previous verification checked: no existing `10-VERIFICATION.md` found.
- Required files read: all Phase 10 plans, summaries, context/research/pattern/review files, ROADMAP, REQUIREMENTS, STATE, and `CLAUDE.md`.
- `composer test` spot-check run during verification: exited `0`.
- `gsd-sdk` was not available in this repository environment, so roadmap/plan must-haves were extracted directly from checked-in planning files.

## Goal Achievement

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | ContactPage-style Matrix payloads with required block titles save generically without losing block field content. | VERIFIED | `EntryMigrationService::stripSourcePartRefs()` synthesizes deterministic titles when native `title` is missing and tests cover deterministic title and preserved fields. |
| 2 | TextPage-style sparse-locale payloads save to Craft without blank primary-site title/slug failures and keep site enablement/source truth truthful. | VERIFIED | `primarySiteDataForSave()` borrows best available payload/title/slug only for primary save without mutating `perSite`; tests assert borrowed title/slug, original `perSite` unchanged, fallback warning/counter, and zero failures. |
| 3 | VacancyFormPage-style invalid section/entry-type mappings are blocked or routed before load. | VERIFIED | `CraftTargetIntrospector::validateWithSeverity()` classifies missing section, section/type incompatibility, and missing entry types as fatal; compile and migrate preflight block fatal mappings. |
| 4 | Advisory target warnings remain warning-only when they cannot cause live load failure. | VERIFIED | Missing fields/adapters remain warnings, not fatal, and compile prints warnings separately from fatal validation. |
| 5 | PageBuilder handles propagate only when the parent entry type owns the target Matrix field. | VERIFIED | `MappingCompiler` checks parent Matrix ownership before propagation and tests cover valid/invalid propagation. |
| 6 | Invalid PageBuilder ownership is blocked or explicitly reported with source-preserving fallback. | VERIFIED | Invalid ownership with flat fallback emits warning and preserves `flatPagePartContent`; without fallback emits operator-review warning. |
| 7 | Matrix/sparse fallback usage is visible in operator reports or logs. | VERIFIED | `recordFallback()` writes report warning + `fallback.*` counter and Craft warning; `MigrateController::renderFallbacksSection()` renders fallback counts/warnings. |
| 8 | Successful fallbacks do not increment failure counts. | VERIFIED | Fallback tests assert `failed` remains zero. |
| 9 | A page with a non-empty taxonomy FK creates/reuses the referenced Craft taxonomy entry during page migration. | VERIFIED WITH CAVEAT | Normal lazy resolver path is implemented and tested. Exception visibility gap is tracked separately. |
| 10 | Default taxonomy migration is referenced-only. | VERIFIED | `Settings::$includeUnreferencedTaxonomies = false`; `MigrateController` computes referenced-only unless CLI/settings opt in. |
| 11 | Unreferenced taxonomy rows migrate only when explicit CLI/settings path is enabled. | VERIFIED | `migrateAll()` runs only when `$includeUnreferencedTaxonomies` is true or through explicit sub-action. |
| 12 | Missing taxonomy locale values use default-language values and are visibly reported. | FAILED | Fallback/reporting exists for some branches, but the empty-translation branch resolves mapping keys as Craft site handles. Compiled-shape maps like `['nl' => 'default', 'en' => 'enUs']` can skip non-primary fallback saves. |
| 13 | Dry-run creates no Craft entries and no state rows for lazy taxonomy creation. | VERIFIED | `resolveReferenced()` returns before Craft/state writes in dry-run and reports `taxonomy.wouldCreate`/`taxonomy.wouldLink`; tests assert state `record()` is not called. |
| 14 | Verify output compares Craft baseline/current drift separately from migration-created/source-parity counts. | VERIFIED | `VerifyController` keeps baseline as Craft drift, reports migration-created state counts separately, and only runs source parity when expected source counts exist. |
| 15 | Report labels make compared count domains explicit. | VERIFIED | Domain constants exist in `CountGateService` and are rendered by `VerifyController`. |
| 16 | Verify no longer reports false failures caused by mismatched baseline semantics. | VERIFIED | Craft drift is informational and tests assert overall pass despite drift gate false. |
| 17 | Regression tests cover the known generic failure categories without proprietary source content. | VERIFIED | Tests cover fallback, preflight, PageBuilder, taxonomy, and verify; full `composer test` exited `0`. |
| 18 | Restored-backup CQM full workflow reaches zero entry failures and zero stage failures. | VERIFIED | `10-SUMMARY.md` records restored backup path, workflow, `REPORT.md` failed `0`, `Total failed: 0`, `_No per-entry failures._`, and verify domain labels. |
| 19 | Page-owned referenced surfaces are migrated or classified as explicitly dropped/out-of-scope without counting as entry/stage failures. | HUMAN / PARTIAL | `10-SUMMARY.md` records zero failures and visible `out_of_scope`, `dropped`, `unsupported`, and `warning` rows. Dropped/out_of_scope rows are acceptable classified rows; `warning`/`unsupported` rows require release-owner acceptance or follow-up classification before tagging. |
| 20 | No production code contains new CQM-specific page IDs, block handles, or class-name conditionals. | VERIFIED WITH NOTES | No new executable CQM-only Phase 10 production branch found. Matches are comments, historical examples, generic Kunstmaan class names, or tests. |
| 21 | Restored-backup rerun instructions include restore, full rerun, and inspection gates and are executable. | PARTIAL | Runbook includes restore path and inspection gates, but documents unsupported `migrate --dry-run`; current CLI dry-run is default and exposes `--live`, not `--dry-run`. |

**Score:** 18/21 truths verified

## Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `src/compile/CraftTargetIntrospector.php` | Load-fatal target validation classification | VERIFIED | `validateWithSeverity()` returns fatal and warnings; section/type mismatch fatal. |
| `src/console/CompileController.php` | Compile command fatal validation output and non-zero exit | VERIFIED | Prints fatal validation separately and exits `ExitCode::CONFIG`. |
| `src/console/MigrateController.php` | Live migrate preflight, taxonomy flag wiring, report rendering, verify/runbook wiring | PARTIAL | Fatal preflight and taxonomy mode are wired. Gap: transform `__report` sentinel warnings are discarded; runbook dry-run mismatch is external doc issue. |
| `src/compile/MappingCompiler.php` | PageBuilder ownership validation | VERIFIED | Validates parent ownership before `pageBuilderHandle` propagation; preserves flat fallback/report. |
| `src/load/EntryMigrationService.php` | Matrix title fallback and sparse-locale primary fallback | VERIFIED | Substantive implementation and tests; fallback reporting wired via `MigrationReport`. |
| `src/load/MigrationReport.php` | Fallback/report surface | VERIFIED | Counts/warnings used by fallback renderer. |
| `src/load/TaxonomyMigrationService.php` | Shared taxonomy find/create resolver with locale fallback | PARTIAL | Resolver exists and is wired; mapping site-handle bug blocks full generic locale fallback truth. |
| `src/fields/handlers/RelationHandler.php` | Delegation from taxonomy-backed relation misses | PARTIAL | Delegates correctly in normal path and reports unresolved null results, but thrown handler/resolver failures can be swallowed upstream. |
| `src/fields/ResolverContext.php` | Optional resolver dependency transport | VERIFIED | Carries taxonomy resolver, dry-run flag, and `MigrationReport`. |
| `src/models/Settings.php` | Persistent default for explicit full taxonomy import | VERIFIED | `includeUnreferencedTaxonomies=false`. |
| `src/verify/BaselineCounterService.php` | Craft baseline/current drift count capture | VERIFIED | Required service exists and is consumed by `VerifyController`. |
| `src/verify/CountGateService.php` | Domain-aware count comparison | VERIFIED | Domain constants and non-blocking flat count compare exist. |
| `src/console/VerifyController.php` | Domain-labeled verify report rendering | VERIFIED | Renders Craft drift, migration-created state counts, and source parity separately. |
| `.planning/rehearsal/v1.0/cqm/README.md` | Restored-backup rehearsal runbook and closing proof gates | PARTIAL | Restore path and gates exist; dry-run command is not executable against current CLI. |

## Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `CompileController.php` | `CraftTargetIntrospector.php` | fatal validation result classification | WIRED | `validateWithSeverity()` called before compile finishes; fatal exits CONFIG. |
| `MigrateController.php` | `CraftTargetIntrospector.php` | compiled mapping preflight blocks load-fatal targets | WIRED | `preflightCompiledMapping()` validates target schema and returns fatal messages. |
| `MappingCompiler.php` | Craft target ownership data | pageBuilderHandle propagation checks parent field ownership | WIRED | `parentOwnsMatrixField()` called before propagation. |
| `EntryMigrationService.php` | `MigrationReport` / Craft logs | visible fallback reporting | WIRED | `recordFallback()` writes report warning + fallback counter and Craft warning. |
| `RelationHandler.php` | `TaxonomyMigrationService.php` | taxonomy-backed non-empty FK state miss delegates to resolver | WIRED | `resolveTaxonomyMiss()` calls `resolveReferenced()`. |
| `MigrateController.php` | `TaxonomyMigrationService.php` | `--include-unreferenced-taxonomies` controls full import path | WIRED | Full import runs only under CLI/settings opt-in. |
| `TransformService.php` | `MigrateController.php` | transform sentinel warning merge | NOT WIRED | `TransformService` yields `__report`; `MigrateController` skips it without merging warnings. |
| `VerifyController.php` | `CountGateService.php` | domain-specific count inputs | WIRED | `VerifyController` passes explicit domain labels and blocking flags. |
| `.planning/rehearsal/v1.0/cqm/README.md` | current CLI | closing proof commands | PARTIAL | README includes current restore/verify gates but one command uses unsupported `--dry-run`. |

## Data-Flow Trace

| Artifact | Data Variable | Source | Produces Real Data | Status |
|---|---|---|---|---|
| `EntryMigrationService.php` | Matrix block `title` | `normalizeMatrixPayload()` -> `stripSourcePartRefs()` before `setFieldValues()` | Yes | FLOWING |
| `EntryMigrationService.php` | Primary-site title/slug fallback | `primarySiteDataForSave()` picks best available source locale payload before primary save | Yes | FLOWING |
| `TaxonomyMigrationService.php` | Referenced taxonomy Craft ID | `resolveReferenced()` checks migration state, loads legacy row, upserts live, records state | Yes in normal live path | FLOWING with locale-fallback caveat |
| `RelationHandler.php` | Relation IDs | State lookup first; taxonomy-backed miss delegates to resolver | Yes in normal path | HOLLOW ERROR PATH — thrown resolver/handler exceptions can be discarded upstream |
| `VerifyController.php` | Count domains | BaselineCounterService + CountGateService + source parity artifact detection | Yes | FLOWING |

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Full repository tests pass | `composer test` | Exited `0` during verification; user also reported `composer test` exited `0` after review report commit. | PASS |
| Required artifacts exist and are substantive | `wc -l ...` over Phase 10 artifacts | All required artifacts exist; major production files range 50-2402 LOC; runbook 97 LOC. | PASS |
| Transform sentinel warnings reach main report | Code trace `TransformService::__report` + `MigrateController` sentinel handling | `MigrateController` skips sentinel without merge. | FAIL |
| Taxonomy locale fallback handles compiled mapping shape | Code trace in `TaxonomyMigrationService` | Empty translations branch uses mapping keys as Craft handles. | FAIL |

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| PH10-01 | `10-02-PLAN.md` / ROADMAP | Generic Matrix-block title fallback before Craft save, without CQM hardcoding | SATISFIED | Entry migration implementation and tests. |
| PH10-02 | `10-02-PLAN.md` / ROADMAP | Sparse-locale primary save fallback preserving locale truth | SATISFIED | Entry migration implementation and tests. |
| PH10-03 | `10-01-PLAN.md` / ROADMAP | Compile/load guard for section + entry-type compatibility | SATISFIED | Target introspector, compile controller, migrate preflight. |
| PH10-04 | `10-03-PLAN.md` / ROADMAP | Taxonomy-dependent relation resolution | PARTIAL | Normal lazy resolver path is implemented and tested, but handler/resolver exceptions can be hidden and relation data omitted. |
| PH10-05 | `10-01-PLAN.md` / ROADMAP | PageBuilder Matrix ownership validation and flat fallback preservation | SATISFIED | Mapping compiler implementation and tests. |
| PH10-06 | `10-04-PLAN.md` / ROADMAP | Verify count semantics distinguish domains | SATISFIED | Verify controller/count gate implementation and tests. |
| PH10-07 | `10-01/02/03-PLAN.md` / ROADMAP | Regression tests cover known failures generically | SATISFIED | Phase 10 tests cover fallback, preflight, PageBuilder, taxonomy, and verify; full `composer test` exited `0`. |
| PH10-08 | `10-04-PLAN.md` / ROADMAP | Rehearsal restore/rerun instructions and inspection gates | PARTIAL | Restore path and inspection gates exist, and proof summary records zero failures. README still contains unsupported `migrate --dry-run` command. |

No PH10 entries were found in `.planning/REQUIREMENTS.md`; PH10 requirements are declared in ROADMAP and Phase 10 plan frontmatter.

## Anti-Patterns Found

| File | Pattern | Severity | Impact |
|---|---|---|---|
| `src/load/TaxonomyMigrationService.php` | Site-handle mapping bug | Blocker | Compiled mapping locale keys can be mistaken for Craft handles, skipping non-primary taxonomy locale fallback saves. |
| `src/transform/TransformService.php` + `src/console/MigrateController.php` | Discarded error/warning channel | Blocker | Handler failures can be swallowed and relation values omitted without appearing in the main migration report. |
| `.planning/rehearsal/v1.0/cqm/README.md` | Unsupported CLI option in runbook | Warning / Gap | Clean rerun instructions are not fully executable as written. |

## Human Verification Required / Release-Owner Decision

These do not override the code gaps above, but they remain release-gate decisions:

### 1. Page-rooted coverage `warning` / `unsupported` classifications

**Test:** Review the external CQM `PAGE-ROOTED-COVERAGE.md` rows classified as `warning` or `unsupported`.
**Expected:** Each row is either accepted by the release owner with documented rationale or reclassified/fixed before tagging v1.0.
**Why human:** The repository summary records counts (`warning`: 439, `unsupported`: 84), but whether those rows are acceptable release omissions depends on product/release-owner intent and external CQM artifacts not committed to this repo.

### 2. External restored CQM artifacts

**Test:** Re-open the latest external `~/Sites/cqm-craft-website/storage/migration/REPORT.md`, `VERIFY-*.md`, and `PAGE-ROOTED-COVERAGE.md`.
**Expected:** Zero entry failures, zero stage failures, domain-labeled verify output, no unaccepted warning/unsupported page-rooted rows.
**Why human:** The repository contains summary evidence, not the external migration artifacts themselves.

## Advisory Code Review Warning Disposition

### WR-01: Taxonomy fallback site-handle mapping

**Disposition:** Verification gap.
**Rationale:** This directly affects the must-have "Missing taxonomy locale values use default-language values and are visibly reported." The code uses legacy locale keys as Craft site handles in the empty-translation fallback branch. For generic Lameco sites where locale keys differ from Craft site handles, fallback values can be skipped silently.

### WR-02: Transform sentinel warning merge

**Disposition:** Verification gap.
**Rationale:** This directly affects the Phase 10 goal "without silent loss of page-owned relations or content." Relation/taxonomy handler failures can be caught, stored only in a local sentinel report, then discarded by `MigrateController`, allowing missing relation values without operator-visible report evidence.

### PAGE-ROOTED-COVERAGE warning/unsupported rows

**Disposition:** Human release-owner decision, not a code-level automated verification pass.
**Rationale:** The rows are visible rather than silent; however, Phase 10's strictest gate says warning/unsupported rows require explicit release acceptance or follow-up classification before tagging. Automated verification cannot decide product acceptance.

## Gaps Summary

Phase 10 implemented the main generic hardening work: Matrix native-title fallback, sparse-locale primary fallback, fatal target validation, PageBuilder ownership gating, lazy taxonomy resolver, referenced-only taxonomy default, explicit full taxonomy import path, verify count-domain separation, and restored CQM proof evidence with zero entry/stage failures.

However, the phase goal is not fully achieved because two verified code paths still permit generic silent or semi-silent content loss:

1. Taxonomy locale fallback can skip non-primary site saves when compiled mapping locale keys differ from Craft handles.
2. Transform handler failures can be discarded before reaching the main migration report, including taxonomy resolver failures that affect page-owned relations.

Additionally, the restored CQM runbook contains an unsupported dry-run command and should be corrected to match the actual CLI surface.

Until these are fixed or explicitly overridden, Phase 10 remains `gaps_found`.

---

_Verified: 2026-04-28T17:14:30Z_
_Verifier: the agent (gsd-verifier)_
