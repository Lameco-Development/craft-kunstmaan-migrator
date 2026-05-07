---
phase: 12-cp-migration-console-queue-workflow
plan: 05
subsystem: settings
tags: [craft-cms, control-panel, settings, queue-safety, phpunit]

# Dependency graph
requires:
  - phase: 12-cp-migration-console-queue-workflow
    provides: Phase 12 context decisions for stable CP settings versus config-only project-shape hints
  - phase: 04-adapters-verify-settings
    provides: existing Craft CP settings fragment and settingsHtml pattern
provides:
  - stable Settings model fields for CP queue allowance, live queue allowance, run/artifact retention, default filters, and adapter toggles
  - concise Craft CP settings fragment grouped into Connectivity, Mapping, Execution, Adapters, and Retention
  - read-only masked Anthropic key presence display in CP settings
  - source-level PHPUnit coverage for the settings boundary
affects: [cp-console, settings, queue-workflow, migration-safety]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - CP Settings expose stable site-safe options only; advanced project-shape hints remain config-only.
    - Live CP queue execution defaults disabled with `allowCpLiveQueueAction = false`.
    - CP secrets render as masked/read-only presence where raw values should not be echoed.

key-files:
  created:
    - .planning/phases/12-cp-migration-console-queue-workflow/12-05-SUMMARY.md
  modified:
    - src/models/Settings.php
    - src/templates/_settings.twig
    - tests/unit/models/SettingsBeforeValidateTest.php
    - tests/unit/Plugin/SettingsHtmlTest.php

key-decisions:
  - "Keep `genericContentBlockOverrides` and `relationMirrorRules` as Settings/config properties but remove them from CP Twig so project-shape policy remains config-only."
  - "Default `allowCpQueueActions` to true for safe queued CP stages, but default `allowCpLiveQueueAction` to false so live queue execution requires explicit operator opt-in and downstream gates."
  - "Render Anthropic API key presence through a masked read-only CP field instead of echoing the raw settings value."

patterns-established:
  - "Settings Twig groups are exactly Connectivity, Mapping, Execution, Adapters, and Retention."
  - "Stable CP settings fields are asserted statically by `SettingsHtmlTest` to prevent advanced mapping/project-shape fields from reappearing in Twig."

requirements-completed: []

# Metrics
duration: 3min
completed: 2026-04-29
---

# Phase 12 Plan 05: Stable CP Settings boundary Summary

**Concise Craft CP settings for site-safe queue, adapter, filter, and retention options while keeping project-shape mapping policy in config**

## Performance

- **Duration:** 3 min
- **Started:** 2026-04-29T10:24:50Z
- **Completed:** 2026-04-29T10:27:28Z
- **Tasks:** 2
- **Files modified:** 4 implementation/test files plus this summary and planning metadata

## Accomplishments

- Added stable Settings model fields: `allowCpQueueActions`, `allowCpLiveQueueAction`, `runRecordRetentionDays`, `artifactRetentionDays`, and `defaultFilters`.
- Preserved `genericContentBlockOverrides` and `relationMirrorRules` as Settings/config properties while keeping them absent from the CP settings Twig.
- Reworked `_settings.twig` into the exact planned groups: `Connectivity`, `Mapping`, `Execution`, `Adapters`, and `Retention`.
- Added CP controls for default filters, `joinFkRelations`, SEOmatic/Retour toggles, CP queue allowance, CP live queue allowance, and retention defaults.
- Updated PHPUnit source-level settings tests to cover safe defaults, validation rules, concise group shape, stripped advanced fields, and masked/read-only Anthropic display.

## Task Commits

Each task was committed atomically:

1. **Task 1: Add stable execution and retention settings fields**
   - `daeb2e1` test(12-05): add failing settings boundary tests
   - `f4f74ef` feat(12-05): add stable CP execution settings
2. **Task 2: Slim CP Settings Twig to stable site-safe groups**
   - `ee7d272` feat(12-05): slim CP settings to stable groups

**Plan metadata:** committed separately after state/roadmap updates.

_Note: Task 1 used the required RED → GREEN TDD commits._

## Files Created/Modified

- `src/models/Settings.php` - Adds stable CP queue/live/retention/filter settings with validation while preserving advanced project-shape properties for config use.
- `src/templates/_settings.twig` - Presents the concise CP settings fragment grouped into Connectivity, Mapping, Execution, Adapters, and Retention with no advanced project-shape controls.
- `tests/unit/models/SettingsBeforeValidateTest.php` - Adds Settings model default and validation coverage for CP queue, live, retention, and default filter fields.
- `tests/unit/Plugin/SettingsHtmlTest.php` - Updates static Twig contract tests for exact settings groups, stable fields, masked Anthropic display, and absence of advanced fields.
- `.planning/phases/12-cp-migration-console-queue-workflow/12-05-SUMMARY.md` - Execution summary and verification record for this plan.

## Decisions Made

- Kept queue-safe CP actions enabled by default through `allowCpQueueActions = true` so future safe stages can be queued from the CP when implemented.
- Kept live CP queue execution disabled by default through `allowCpLiveQueueAction = false`, matching the Phase 12 threat model and live-gate posture.
- Treated `defaultFilters` as a stable site-safe CP setting, while leaving mapping fallback defaults, AI proposer scope knobs, relation mirror rules, and generic content-block overrides out of CP Twig.
- Displayed Anthropic key presence in CP settings without rendering the raw value.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Masked Anthropic API key display**
- **Found during:** Task 2 (Slim CP Settings Twig to stable site-safe groups)
- **Issue:** The pre-existing settings fragment rendered `settings.anthropicApiKey` directly through an editable password field. For a CP settings boundary, raw secret rendering is not necessary and conflicts with the requested masked/read-only display behavior.
- **Fix:** Replaced the editable Anthropic key input with a read-only masked presence field named `anthropicApiKeyMasked`, with instructions to configure the raw key via `ANTHROPIC_API_KEY` or config.
- **Files modified:** `src/templates/_settings.twig`, `tests/unit/Plugin/SettingsHtmlTest.php`
- **Verification:** `vendor/bin/phpunit tests/unit/Plugin/SettingsHtmlTest.php --testdox`
- **Committed in:** `ee7d272`

---

**Total deviations:** 1 auto-fixed (1 Rule 2 missing critical functionality)  
**Impact on plan:** Strengthened CP settings security while preserving the planned concise settings boundary. No new architecture or mapping-authoring scope was introduced.

## Issues Encountered

- `gsd-sdk` was unavailable in this shell (`command not found`), so state and roadmap metadata updates were performed manually instead of through SDK query handlers.
- PHPUnit reported `No code coverage driver available`; this is a warning only and did not affect the targeted test pass.

## User Setup Required

None - no external service configuration required.

## Verification

- `vendor/bin/phpunit tests/unit/models/SettingsBeforeValidateTest.php --testdox`
- `vendor/bin/phpunit tests/unit/Plugin/SettingsHtmlTest.php --testdox`
- `php -l src/models/Settings.php`
- Plan acceptance greps:
  - `src/models/Settings.php` stable field count: 8
  - `src/models/Settings.php` advanced config field count: 5
  - `src/templates/_settings.twig` stable execution/retention field count: 16
  - `src/templates/_settings.twig` advanced project-shape field count: 0

## Known Stubs

None. The stub scan found no TODO/FIXME markers, placeholder copy, hardcoded empty data sources, or unwired mock settings surfaces in files modified by this plan.

## Threat Flags

None - this plan changed settings model/Twig surfaces only and introduced no new network endpoints, auth paths, file access patterns, schema changes, or trust-boundary storage.

## TDD Gate Compliance

- RED gate commit present: `daeb2e1`
- GREEN gate commit present after RED: `f4f74ef`
- Refactor gate: not needed

## Next Phase Readiness

- Future CP console controllers and queue jobs can read explicit queue/live allowances and retention defaults from Settings.
- The live queue action remains opt-in and can be combined with later strict live gate services.
- CP settings remain concise; advanced mapping/project-shape policy stays in config where downstream compile/analyze code already expects it.

## Self-Check: PASSED

- Found modified files:
  - `src/models/Settings.php`
  - `src/templates/_settings.twig`
  - `tests/unit/models/SettingsBeforeValidateTest.php`
  - `tests/unit/Plugin/SettingsHtmlTest.php`
- Found created file:
  - `.planning/phases/12-cp-migration-console-queue-workflow/12-05-SUMMARY.md`
- Found task commits: `daeb2e1`, `f4f74ef`, `ee7d272`

---
*Phase: 12-cp-migration-console-queue-workflow*
*Completed: 2026-04-29*
