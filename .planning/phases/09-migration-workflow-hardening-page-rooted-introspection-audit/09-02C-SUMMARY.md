---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 02C
subsystem: cross-stage-filter-consistency
tags: [php, phpunit, filters, relation-graph, sidecars, runtime-pipeline]
requires:
  - "09-02 source-domain filter core"
  - "09-02B Craft query filter translation"
  - "09-04 Page-rooted coverage audit"
  - "09-05 referenced-only asset preload"
provides:
  - "runtime relationGraph handoff through analyze/map/migrate/verify controllers"
  - "source-domain reachability consumption in extract/transform/load/taxonomy stages"
  - "translated Craft scope for verify baseline capture"
  - "Page-scoped sidecar closure coverage"
affects:
  - src/filter/FilterFactory.php
  - src/console/AnalyzeController.php
  - src/console/MapController.php
  - src/console/MigrateController.php
  - src/console/VerifyController.php
  - src/extract/ExtractService.php
  - src/transform/TransformService.php
  - src/load/TaxonomyMigrationService.php
  - src/verify/BaselineCounterService.php
tech_stack:
  added: []
  patterns:
    - "FilterFactory relation-graph artifact normalization"
    - "Controller-level runtime filter construction"
    - "Craft handle translation only at Craft query surfaces"
key_files:
  created:
    - tests/integration/filter/CrossStageFilterConsistencyTest.php
  modified:
    - src/filter/FilterFactory.php
    - src/console/AnalyzeController.php
    - src/console/MapController.php
    - src/console/MigrateController.php
    - src/console/VerifyController.php
    - src/extract/ExtractService.php
    - src/transform/TransformService.php
    - src/load/TaxonomyMigrationService.php
    - src/verify/BaselineCounterService.php
    - tests/unit/verify/BaselineCounterServiceFiltersTest.php
    - tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php
decisions:
  - "Use the existing storage/migration/relation-graph.json artifact as the runtime relationGraph source for map/migrate/verify."
  - "Keep missing relation-graph artifacts non-fatal so first-run/unscoped paths retain backward compatibility."
  - "Let BaselineCounterService accept an optional translated Craft scope instead of comparing source entity filters to Craft section handles."
metrics:
  duration: "00:25:00"
  completed_at: "2026-04-28T12:21:00Z"
  tasks_completed: 2
  files_changed: 12
---

# Phase 9 Plan 02C: Cross-stage Filter Consistency Summary

Source-domain entity filters now flow consistently through the runtime migration pipeline, with relationGraph closure applied before source-stage filtering and Craft handle translation reserved for verify/finalize query surfaces.

## Tasks Completed

| Task | Name | Status | Commit |
|---|---|---:|---|
| 1 RED | Inventory and normalize filter handoff coverage | Complete | `c4f1681` |
| 1 GREEN | Wire source filters across runtime stages | Complete | `afee318` |
| 2 | Page-owned sidecar closure coverage | Complete | `19f5a84` |
| Follow-up | Align referenced preload regression | Complete | `4d48d5c` |

## Implementation Notes

- Added `FilterFactory::relationGraphFromArtifact()` to normalize the analyzer's `relation-graph.json` shape into the `MigrationFilters` reachability map.
- Updated `AnalyzeController`, `MapController`, `MigrateController`, and `VerifyController` so runtime filters are constructed with normalized relationGraph data.
- Updated `ExtractService`, `TransformService`, `MigrateController::runLoadFromDisk()`, and `TaxonomyMigrationService` to use `MigrationFilters::allows()` rather than reparsing source FQCN/basename lists.
- Scoped referenced-only asset preload collection to the same filtered transformed payload set that load consumes, preventing stale transformed payloads from widening `--preload-assets`.
- Passed the active source filters into taxonomy, SEO, and Retour sidecar stages from `MigrateController`.
- Extended `BaselineCounterService::capture()` with an optional translated Craft scope and used `CountGateService::isSectionFilteredOut()` so baseline capture does not compare Kunstmaan source filters directly to Craft section handles.
- Added `CrossStageFilterConsistencyTest` under integration tests to lock relationGraph handoff, source-domain stage consumption, sidecar classification, and Page-owned dependency closure.

## Files Created/Modified

- `tests/integration/filter/CrossStageFilterConsistencyTest.php` — new cross-stage filter/sidecar regression coverage.
- `src/filter/FilterFactory.php` — relation-graph artifact normalization helper.
- `src/console/AnalyzeController.php` — rebuilds filters with the in-memory relationGraph after analysis discovers it.
- `src/console/MapController.php` — loads normalized relationGraph for map-stage filtering.
- `src/console/MigrateController.php` — central runtime filter builder, relationGraph loader, filtered payload preload collection, source-domain load filtering, and sidecar filter handoff.
- `src/console/VerifyController.php` — central runtime filter builder and translated baseline scope handoff.
- `src/extract/ExtractService.php` — source-domain reachability filtering for precount and extraction.
- `src/transform/TransformService.php` — source-domain reachability filtering for transform dispatch.
- `src/load/TaxonomyMigrationService.php` — optional `MigrationFilters` handoff and source-domain taxonomy scoping.
- `src/verify/BaselineCounterService.php` — translated Craft scope support for filtered section baseline capture.
- `tests/unit/verify/BaselineCounterServiceFiltersTest.php` — updated signature coverage for translated baseline scope.
- `tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php` — updated regression assertion for filtered payload preload collection.

## Decisions Made

- RelationGraph loading is best-effort at runtime. A missing artifact preserves previous behavior rather than blocking unscoped/first-run commands.
- `AnalyzeController` uses an empty relationGraph for early locale/source preflight, then rebuilds filters with the freshly discovered graph before downstream schema/proposal work needs reachability.
- `BaselineCounterService` receives translated Craft scope from `VerifyController`; translation stays outside the service so source-domain filter semantics remain consistent.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Updated referenced-only preload regression for filtered payload collection**
- **Found during:** Overall `composer test-unit` verification.
- **Issue:** Existing Plan 09-05 test expected the old `collectReferencedAssetIdsFromPayloadDirectory($transformedDir)` call shape after Plan 09-02C added the required filter argument.
- **Fix:** Updated the assertion to require `collectReferencedAssetIdsFromPayloadDirectory($transformedDir, $filters)`.
- **Files modified:** `tests/unit/load/AssetMigrationServiceReferencedOnlyPreloadTest.php`
- **Commit:** `4d48d5c`

## Auth Gates

None.

## Known Stubs

None. Stub-pattern scan found only normal empty-array/null/default guard code and pre-existing comments; no UI/data stubs were introduced.

## Threat Flags

None. No new network endpoints, auth paths, file access patterns, schema changes, or new trust-boundary surfaces were introduced.

## Verification

| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/integration/filter/CrossStageFilterConsistencyTest.php --filter 'stage' --testdox` | Passed: 4 tests / 34 assertions; PHPUnit warning: no code coverage driver |
| `vendor/bin/phpunit tests/integration/filter/CrossStageFilterConsistencyTest.php --filter 'sidecar' --testdox` | Passed: 2 tests / 17 assertions; PHPUnit warning: no code coverage driver |
| `vendor/bin/phpunit tests/integration/filter/CrossStageFilterConsistencyTest.php --testdox` | Passed: 5 tests / 39 assertions; PHPUnit warning: no code coverage driver |
| `vendor/bin/phpunit tests/unit/verify/BaselineCounterServiceFiltersTest.php tests/unit/verify/CountGateServiceFiltersTest.php tests/unit/filter/FilterFactoryTest.php --testdox` | Passed: 19 tests / 69 assertions; PHPUnit warning: no code coverage driver |
| `composer test-unit` | Passed: 441 tests / 1405 assertions; PHPUnit warning: no code coverage driver; 1 pre-existing deprecation |
| `php -l` changed PHP files | Passed: no syntax errors |

## Issues Encountered

- RED coverage failed as expected before implementation because `FilterFactory::relationGraphFromArtifact()` and controller relationGraph handoff did not yet exist.
- PHPUnit consistently reports the environment's standard "No code coverage driver available" warning.
- `composer test-unit` passes with 1 pre-existing deprecation from Craft console-controller autoload in the broader suite.

## User Setup Required

None. No credentials, external services, or manual verification steps are required.

## Next Phase Readiness

Plan 09-07 can rely on source-domain entity scope, relationGraph closure, translated Craft query scopes, and Page-owned sidecar visibility being wired through the hardened runtime pipeline.

## Commits

- `c4f1681` — `test(09-02C): add cross-stage filter consistency coverage`
- `afee318` — `feat(09-02C): wire source filters across runtime stages`
- `19f5a84` — `test(09-02C): prove page-owned sidecar closure`
- `4d48d5c` — `test(09-02C): align preload scope regression`

## Self-Check: PASSED

- Found created/modified files:
  - `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-02C-SUMMARY.md`
  - `tests/integration/filter/CrossStageFilterConsistencyTest.php`
  - `src/filter/FilterFactory.php`
  - `src/console/MigrateController.php`
  - `src/verify/BaselineCounterService.php`
- Found task commits in git history:
  - `c4f1681`
  - `afee318`
  - `19f5a84`
  - `4d48d5c`
