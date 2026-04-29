---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-05
subsystem: migration-gap-closure
tags: [php, craft-cms, taxonomies, transform, migration-report, runbook, phpunit]

requires:
  - phase: 10-generic-migration-rehearsal-gap-closure
    provides: "10-03 taxonomy resolver and 10-04 restored CQM proof/runbook baseline"
provides:
  - "Compiled mapping.sites taxonomy fallback resolves Craft site handles from mapping values"
  - "TransformService __report warnings are merged into MigrationReport/REPORT.md"
  - "Live relation/taxonomy transform handler failures block before load saves entries"
  - "CQM runbook documents default dry-run migrate command and --live write mode"
affects: [phase-10-verification, migrate-workflow, taxonomy-fallback, cqm-runbook]

tech-stack:
  added: []
  patterns:
    - "Private pure helper seam for mapping.sites site-handle extraction"
    - "Transform sentinel warning merge returns a live-mode blocking signal while dry-run remains warning-only"
    - "Synthetic TransformService failure records live transform relation/taxonomy omissions before load"

key-files:
  created:
    - ".planning/phases/10-generic-migration-rehearsal-gap-closure/10-05-SUMMARY.md"
  modified:
    - "src/load/TaxonomyMigrationService.php"
    - "tests/integration/load/TaxonomyMigrationTest.php"
    - "src/console/MigrateController.php"
    - "tests/unit/console/MigrateControllerFailureExitTest.php"
    - ".planning/rehearsal/v1.0/cqm/README.md"

key-decisions:
  - "Taxonomy fallback treats scalar mapping.sites values as Craft handles and array mappings as siteHandle-with-legacy-locale fallback for compatibility."
  - "Transform handler failures remain caught by TransformService, but the sentinel report is now promoted into the main migration report."
  - "Dry-run relation/taxonomy handler failures stay visible as warnings; live mode records a synthetic TransformService failure and stops before taxonomy/load/finalize/SEO/Retour stages."
  - "The CQM runbook uses default `migrate` for dry-run because no `--dry-run` option exists."

patterns-established:
  - "Compiled mapping shape `['nl' => 'default', 'en' => 'enUs']` is locked by regression coverage."
  - "Main migration report warning prefix `Transform:` identifies transform-stage sentinel warnings."
  - "Live blocking transform failures point operators to `Transform:` warnings in REPORT.md rather than dumping source payloads."

requirements-completed: [PH10-04, PH10-08]

duration: 4min
completed: 2026-04-28
---

# Phase 10 Plan 10-05: Verification Gap Closure Summary

**Taxonomy fallback, transform handler failure reporting, and the CQM runbook now close the three Phase 10 verifier gaps without adding CQM-specific production logic.**

## Performance

- **Duration:** 4 min
- **Started:** 2026-04-28T17:27:18Z
- **Completed:** 2026-04-28T17:31:12Z
- **Tasks:** 3
- **Files modified:** 5 code/test/doc files + this summary

## Accomplishments

- Fixed taxonomy empty-Gedmo fallback so compiled `mapping.sites` values such as `['nl' => 'default', 'en' => 'enUs']` resolve Craft handles from mapping values instead of legacy locale keys.
- Preserved array-shaped compatibility for older tests/configs: array mappings use `siteHandle` when present and otherwise fall back to the legacy locale key.
- Merged TransformService `__report` sentinel warnings into the main `MigrationReport` with `Transform:` prefixes and `transform.warning` counts.
- Added live write-mode blocking semantics for relation/taxonomy handler failures before load can save entries with omitted page-owned relation fields.
- Corrected the CQM restored-backup runbook to use `php craft kunstmaan-migrator/migrate` for dry-run/default mode and document `--live` as write mode.

## Gap-Closure Evidence

| 10-VERIFICATION.md gap | Closure evidence | Verification |
|---|---|---|
| Taxonomy fallback resolved mapping.sites keys instead of Craft handles | `TaxonomyMigrationService::siteHandleFromMappingSite()` resolves scalar values to Craft handles, array `siteHandle` values to Craft handles, and legacy-locale fallback for older array shape; fallback warning now includes `site=<resolved Craft handle> locale=<source legacy locale>`. | Filtered taxonomy PHPUnit passed: 4 tests, 4 assertions, 1 existing incomplete Craft-bootstrap fallback test, no coverage-driver warning. |
| Transform sentinel warnings were discarded and live relation/taxonomy failures could silently omit relation values | `MigrateController::mergeTransformReportSentinel()` prefixes warnings with `Transform:`, increments `transform.warning`, detects relation/taxonomy handler failures, and `recordBlockingTransformFailure()` records a synthetic `TransformService` failure in live mode before load/taxonomy/finalize/SEO/Retour. | Filtered controller PHPUnit passed: 3 tests, 11 assertions, no coverage-driver warning, 1 pre-existing Craft controller PHP 8.5 deprecation. |
| CQM runbook documented unsupported `migrate --dry-run` | README command block now uses `php craft kunstmaan-migrator/migrate` and states dry-run is default while `php craft kunstmaan-migrator/migrate --live` is write mode. | Grep absence/presence checks passed; unsupported command absent and supported migrate commands present. |

## Task Commits

Each task was committed atomically:

1. **Task 1 RED: Taxonomy fallback regression tests** - `1c207be` (test)
2. **Task 1 GREEN: Resolve taxonomy fallback Craft handles from mapping values** - `47b9b4e` (feat)
3. **Task 2 RED: Transform sentinel report tests** - `eff2ae6` (test)
4. **Task 2 GREEN: Merge transform sentinel warnings into MigrationReport** - `f19d513` (feat)
5. **Task 3: Correct CQM migrate dry-run command** - `54e804b` (docs)

**Plan metadata:** pending final metadata commit.

## Files Created/Modified

- `src/load/TaxonomyMigrationService.php` - Adds `siteHandleFromMappingSite()` and uses resolved Craft handles plus legacy-locale traceability in empty-translation taxonomy fallback warnings.
- `tests/integration/load/TaxonomyMigrationTest.php` - Adds compiled mapping-shape and array-compatibility regression tests using a reflection seam that does not require Craft bootstrap.
- `src/console/MigrateController.php` - Merges TransformService sentinel warnings into the main report, writes transform-only report warnings, and blocks live relation/taxonomy transform failures before load.
- `tests/unit/console/MigrateControllerFailureExitTest.php` - Covers `Transform:` warning merge, `transform.warning` count, dry-run warning-only behavior, and live synthetic `TransformService` failure exit.
- `.planning/rehearsal/v1.0/cqm/README.md` - Replaces unsupported `migrate --dry-run` with supported default `migrate` and documents `--live` write mode.

## Decisions Made

- **Taxonomy mapping shape:** The compiled convention is locale key to Craft site handle; scalar site config values are therefore authoritative Craft handles. Array-shaped site configs remain supported for older fixtures/configs.
- **Transform failure semantics:** Keeping TransformService catch behavior avoids reshaping the transform pipeline, but MigrateController now owns the cross-stage accountability and live-mode stop decision.
- **Live vs dry-run:** Dry-run preserves all merged transform warnings without recording failures. Live mode records a synthetic `TransformService` failure and returns non-zero after writing REPORT.md because saving entries with known missing relation/taxonomy values would violate the Page-rooted fidelity contract.
- **CQM proof scope:** The full restored CQM rehearsal was not rerun; targeted code/doc tests prove the verifier gaps addressed by this plan.

## Verification

Commands run:

```bash
vendor/bin/phpunit tests/integration/load/TaxonomyMigrationTest.php --filter 'Compiled|Locale|Fallback|MappingSite|SiteHandle' --testdox
vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php --filter 'Transform|Sentinel|Handler|Relation|Taxonomy' --testdox
php -l src/load/TaxonomyMigrationService.php
php -l src/console/MigrateController.php
composer test
grep absence of "kunstmaan-migrator/migrate --dry-run" in .planning/rehearsal/v1.0/cqm/README.md
grep presence of "php craft kunstmaan-migrator/migrate" in .planning/rehearsal/v1.0/cqm/README.md
```

Results:

- Taxonomy filtered suite: **4 tests, 4 assertions, exit 0**; PHPUnit reported the existing no-code-coverage warning and the pre-existing incomplete Craft-bootstrap fallback test.
- Transform/report filtered suite: **3 tests, 11 assertions, exit 0**; PHPUnit reported the existing no-code-coverage warning and a PHP 8.5 deprecation in Craft's console controller signature.
- PHP lint passed for both modified production files.
- Full `composer test`: **491 tests, 1638 assertions, exit 0**; existing issues reported: no code coverage driver, one PHP deprecation, one PHPUnit deprecation, one skipped test, and one incomplete Craft-bootstrap fallback test.
- Runbook grep checks passed: no unsupported `kunstmaan-migrator/migrate --dry-run` remains; supported `php craft kunstmaan-migrator/migrate` and `php craft kunstmaan-migrator/migrate --live` commands are present.

## Deviations from Plan

None - plan executed as written. The only minor implementation extension was applying the same transform sentinel merge helper to standalone `actionTransform()` so sentinel payloads are not discarded there either; this is directly coupled to the planned `MigrateController` sentinel merge and preserves the same dry-run/live decision.

## Issues Encountered

- `gsd-sdk` is not installed in this environment, so state/roadmap updates and final metadata commit are performed manually with normal git commands.
- PHPUnit consistently reports "No code coverage driver available"; this is an environment warning and did not fail verification.
- PHP 8.5 reports a pre-existing Craft CMS console controller deprecation during controller reflection tests.
- Composer reports pre-existing PHAR dependency deprecations before running PHPUnit.
- `TaxonomyMigrationTest::testD09FallbackCopiesSourceLocaleAcrossSitesWhenExtTranslationsEmpty` remains the existing incomplete Craft-bootstrap test; this plan added pure helper regression coverage for the previously missing compiled mapping-shape behavior.

## Known Stubs

None introduced. Stub-pattern scan found intentional nullable dependency-injection properties, empty-array accumulators, placeholder-section strings in report rendering, and the pre-existing incomplete Craft-bootstrap taxonomy fallback note; none are new hollow UI/data stubs and none prevent this plan's goals.

## Threat Flags

None. The plan touched mapping-to-Craft-site lookup and migration report control flow already identified in the plan threat model, but introduced no new network endpoints, auth paths, file access surfaces, schema changes, or trust-boundary categories beyond the planned mitigations.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- The three `10-VERIFICATION.md` gaps now have code/test/doc evidence for re-verification.
- PH10-04 is satisfied for normal taxonomy resolver behavior plus visible/blocking relation/taxonomy handler failure paths.
- PH10-08 is satisfied for executable CQM dry-run/live runbook commands.
- Remaining release-owner review from 10-04 still applies: visible CQM Page-rooted `warning` / `unsupported` classifications should be accepted or further classified before tagging v1.0.

## Self-Check: PASSED

- Summary exists at `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-05-SUMMARY.md`.
- Modified files exist: `src/load/TaxonomyMigrationService.php`, `tests/integration/load/TaxonomyMigrationTest.php`, `src/console/MigrateController.php`, `tests/unit/console/MigrateControllerFailureExitTest.php`, and `.planning/rehearsal/v1.0/cqm/README.md`.
- Task commits exist: `1c207be`, `47b9b4e`, `eff2ae6`, `f19d513`, `54e804b`.
- Required verification commands exited 0.

---
*Phase: 10-generic-migration-rehearsal-gap-closure*
*Completed: 2026-04-28*
