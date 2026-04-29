---
phase: 12-cp-migration-console-queue-workflow
plan: 04
subsystem: ui
tags: [craft-cms, control-panel, mapping-review, twig, csrf, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: existing CP mapping utility, canonical mapping.yaml helpers, and workflow/run-record context from Plans 12-01 through 12-03
  - phase: 02-schema-mapping-filters
    provides: single canonical mapping.yaml with per-row status and MappingFile atomic update helpers
provides:
  - MappingReview pure helpers for status, kind, finding severity, and search filters
  - MappingController utility variables for CP filter state and options
  - Admin-only MappingController batch action using MappingFile::updateRow() for canonical mapping.yaml mutations
  - CP-native mapping Twig with filter controls, visible findings, and safe batch form controls
affects: [cp-console, mapping-review, operator-workflow, mapping-yaml-safety]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Mapping review filtering stays in pure MappingReview helpers so CP and tests remain deterministic.
    - CP mapping mutations use Craft POST/CSRF/actionInput forms and MappingFile::updateRow() only.
    - Batch destructive/high-risk actions require typed confirmation phrases before canonical mapping updates.

key-files:
  created:
    - .planning/phases/12-cp-migration-console-queue-workflow/12-04-SUMMARY.md
  modified:
    - src/mapping/MappingReview.php
    - src/controllers/MappingController.php
    - templates/_mapping/index.twig
    - tests/unit/mapping/MappingReviewTest.php

key-decisions:
  - "Keep `mapping.yaml` as the only mapping source by routing every batch update through `MappingFile::updateRow()`; no draft files or database-backed mapping state were introduced."
  - "Represent warning/unsupported acceptance as canonical row metadata (`status: accepted`, `warningAccepted: true`, rationale) rather than adding a separate acceptance store."
  - "Keep mapping filters URL-driven and preserve filter context through row and batch POST redirects."

patterns-established:
  - "MappingReview exposes exact option lists for status, kind, and finding filters and normalizes unknown query values back to `all`."
  - "MappingController batch actions are admin-only CP POST actions with `DROP SELECTED` and `ACCEPT WARNINGS` typed confirmations."
  - "Mapping Twig uses a non-nested batch form with row checkboxes assigned via the `form` attribute so existing per-row edit forms remain valid."

requirements-completed: []

# Metrics
duration: 8min
completed: 2026-04-29
---

# Phase 12 Plan 04: Mapping review UX Summary

**Filtered CP mapping review with visible unsafe findings and admin-only batch actions that update the canonical `mapping.yaml` atomically**

## Performance

- **Duration:** 8 min
- **Started:** 2026-04-29T10:17:49Z
- **Completed:** 2026-04-29T10:25:49Z
- **Tasks:** 3
- **Files modified:** 4 implementation/test files plus this summary and planning metadata

## Accomplishments

- Added deterministic `MappingReview` helpers for the exact planned status, kind, finding, and search filters, including row-level finding severity/rationale helpers for warning and unsupported visibility.
- Updated `MappingController::utilityVariables()` to read `entity`, `status`, `kind`, `finding`, and `q` query parameters, normalize values, filter indexed rows, and expose selected values/options to Twig.
- Added `MappingController::actionBatch()` as an admin-only CP POST action supporting exactly `accept`, `needs-review`, `drop`, and `accept-warnings`; destructive/high-risk actions require `DROP SELECTED` or `ACCEPT WARNINGS`.
- Updated the mapping Twig to include labeled GET filter controls, visible source/target/handler/finding/rationale metadata before edit controls, row selection checkboxes, and a CSRF/actionInput batch form.
- Extended source-level PHPUnit coverage for mapping review filters, controller query variables, and batch-action safety contracts.

## Task Commits

Each task was committed atomically:

1. **Task 1: Add filter and search support to MappingReview**
   - `da16e08` test(12-04): add failing mapping review filter tests
   - `ee4ffd4` feat(12-04): add mapping review filters
2. **Task 2: Add batch mapping actions with typed confirmations**
   - `32e2b10` test(12-04): add failing mapping batch action tests
   - `fbad346` feat(12-04): add batch mapping actions
3. **Task 3: Update mapping Twig for filters, findings, and batch forms**
   - `5151858` feat(12-04): update mapping review UI

**Plan metadata:** committed separately after state/roadmap updates.

_Note: TDD tasks used RED → GREEN commits as required._

## Files Created/Modified

- `src/mapping/MappingReview.php` - Adds exact filter option lists, normalization, filter application, finding severity/rationale extraction, and search haystack helpers for mapping review rows.
- `src/controllers/MappingController.php` - Reads filter query parameters, returns view-model filter state/options, preserves filter redirects, and adds admin-only `actionBatch()` using canonical `MappingFile::updateRow()` writes.
- `templates/_mapping/index.twig` - Adds CP-native filter controls, visible row metadata/finding reasons, batch row selection, and a CSRF/actionInput batch form with typed confirmation helper text.
- `tests/unit/mapping/MappingReviewTest.php` - Adds source-level coverage for filter option determinism, filter behavior, controller query variables, and batch action safety contracts.
- `.planning/phases/12-cp-migration-console-queue-workflow/12-04-SUMMARY.md` - Execution summary and verification record for this plan.

## Decisions Made

- Kept all mapping write paths canonical: batch actions call `MappingFile::updateRow()` per selected row and do not write YAML directly, create draft files, or add DB-backed mapping storage.
- Used URL query parameters for filter state so operators can reload/bookmark filtered mapping review views; row/batch POST redirects preserve the active filters.
- Used a separate batch form with checkbox `form="km-map-batch"` attributes to avoid invalid nested forms while retaining existing row-level edit/status forms.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Avoided unsupported warning flash call**
- **Found during:** Task 2 (Add batch mapping actions with typed confirmations)
- **Issue:** The initial partial-success branch used a `setWarningFlash()` helper that is not present in this project/Craft controller surface.
- **Fix:** Switched partial-success messaging to the existing `setFailFlash()` pattern used throughout `MappingController`.
- **Files modified:** `src/controllers/MappingController.php`
- **Verification:** `php -l src/controllers/MappingController.php`; `vendor/bin/phpunit tests/unit/mapping/MappingReviewTest.php --testdox`
- **Committed in:** `fbad346`

**2. [Rule 1 - Bug] Prevented filter hidden inputs from corrupting row mutation parameters**
- **Found during:** Task 3 (Update mapping Twig for filters, findings, and batch forms)
- **Issue:** Preserving `status` and `kind` filters inside row POST forms would conflict with row mutation fields that already use `status` and `kind` for the actual row action.
- **Fix:** Renamed row-form filter preservation fields to `filterStatus`, `filterKind`, `filterFinding`, and `filterQ`, and updated `redirectBackToUtility()` to read those fallback names.
- **Files modified:** `templates/_mapping/index.twig`, `src/controllers/MappingController.php`
- **Verification:** `php -l src/controllers/MappingController.php`; `vendor/bin/phpunit tests/unit/mapping/MappingReviewTest.php --testdox`; final acceptance greps
- **Committed in:** `5151858`

---

**Total deviations:** 2 auto-fixed (2 Rule 1 bugs)  
**Impact on plan:** Both fixes preserved the planned CP mapping UX and canonical mapping write contract without adding new architecture or scope.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect the targeted test pass.

## User Setup Required

None - no external service configuration required.

## Verification

- `vendor/bin/phpunit tests/unit/mapping/MappingReviewTest.php --testdox`
- `php -l src/mapping/MappingReview.php`
- `php -l src/controllers/MappingController.php`
- Plan acceptance greps:
  - Task 1 `statusFilter|kindFilter|findingFilter|searchQuery|unsupported|dataProvider` count: 63
  - Task 2 `function actionBatch|requireAdmin|DROP SELECTED|ACCEPT WARNINGS|updateRow` count: 9
  - Task 2 forbidden `mapping-draft|file_put_contents` count: 0 in `src/controllers/MappingController.php`
  - Task 3 Twig batch/filter controls count: 9

## Known Stubs

None. The stub scan found only intentional initialized arrays/null guards and user-facing placeholder attributes for form inputs; no hardcoded empty data sources or TODO/FIXME placeholders block the plan goal.

## Threat Flags

None - the batch mapping mutation surface is explicitly covered by the plan threat model and mitigated with admin-only CP POST validation, typed confirmations, and canonical `MappingFile::updateRow()` writes.

## Next Phase Readiness

- The Mapping tab can now support the broader Phase 12 console shell with URL-preserved filters and clearer unsafe-row visibility.
- Future queue/console plans can rely on warning/unsupported row acceptance being explicit in canonical `mapping.yaml` metadata rather than a draft file or database table.
- The existing mapping review tests now guard filter option values, controller query variables, and batch safety contracts against regressions.

## Self-Check: PASSED

- Found modified files:
  - `src/mapping/MappingReview.php`
  - `src/controllers/MappingController.php`
  - `templates/_mapping/index.twig`
  - `tests/unit/mapping/MappingReviewTest.php`
- Found created file:
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-04-SUMMARY.md`
- Found task commits: `da16e08`, `ee4ffd4`, `32e2b10`, `fbad346`, `5151858`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
