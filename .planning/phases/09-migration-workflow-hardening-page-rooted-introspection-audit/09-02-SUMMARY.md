---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 02
subsystem: filtering
tags: [php, phpunit, migration-filters, source-entities, relation-graph]

requires:
  - phase: 08-taxonomies-and-ai-proposer-coverage
    provides: relationGraph reachability contract on MigrationFilters
  - phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
    provides: D-14/D-15 source-domain filter decisions
provides:
  - Source-entity normalization for --entities values with exact FQCN and basename forms
  - MigrationFilters matching that treats FQCNs and basenames as Kunstmaan source identities
  - Backward-compatible FilterFactory::fromCli relationGraph injection seam
affects: [09-02B, 09-02C, finalize-filter-translation, verify-filter-translation]

tech-stack:
  added: []
  patterns:
    - Source-domain filters normalize at CLI/settings boundary
    - Reachability DFS stores exact source identity plus basename aliases

key-files:
  created:
    - .planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-02-SUMMARY.md
  modified:
    - src/filter/FilterFactory.php
    - src/filter/MigrationFilters.php
    - tests/unit/filter/FilterFactoryTest.php
    - tests/unit/filter/MigrationFiltersReachabilityTest.php

key-decisions:
  - "D-14 is implemented by preserving exact source FQCN spellings and adding basename aliases; no Craft handle inference is performed."
  - "D-15 is implemented by appending an optional relationGraph argument to FilterFactory::fromCli, preserving existing call sites."

patterns-established:
  - "FilterFactory::normalizeEntityFilters() is the source-identity boundary for entities from CLI flags and settings."
  - "MigrationFilters::allows() matches exact source identity or basename and expands relationGraph reachability by both forms."

requirements-completed: [PH9-04, PH9-05]

duration: 5min
completed: 2026-04-28
---

# Phase 9 Plan 02: Source-domain Filter Core Summary

**Kunstmaan source-entity filtering now normalizes FQCN/basename identities and wires relationGraph reachability into MigrationFilters.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-04-28T11:05:14Z
- **Completed:** 2026-04-28T11:10:11Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- Added deterministic `FilterFactory::normalizeEntityFilters()` behavior for source entity filters.
- Updated `MigrationFilters::allows()` so explicit filters match source FQCN and basename forms without Craft-handle semantics.
- Appended an optional `relationGraph` argument to `FilterFactory::fromCli()` and passed it through to `MigrationFilters`.
- Expanded reachability to seed FQCN graph roots from basename filters and to keep basename aliases for reachable dependencies.

## Task Commits

1. **Task 1: Normalize source entity filters**
   - `5178393` test(09-02): add failing source entity normalization coverage
   - `fbf3996` feat(09-02): normalize source entity filters
2. **Task 2: Wire relationGraph reachability into filters**
   - `0ac7c6b` test(09-02): add failing relation graph factory coverage
   - `f8b5f1a` feat(09-02): wire relation graph into filters

## Files Created/Modified

- `src/filter/FilterFactory.php` - Normalizes source entity filters and accepts a backward-compatible `relationGraph` seam.
- `src/filter/MigrationFilters.php` - Matches exact source FQCN/basename forms and expands DFS reachability over graph dependencies.
- `tests/unit/filter/FilterFactoryTest.php` - Covers source identity normalization and no Craft-handle inference.
- `tests/unit/filter/MigrationFiltersReachabilityTest.php` - Covers factory relationGraph signature and basename-rooted graph reachability.
- `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-02-SUMMARY.md` - Execution record.

## Decisions Made

- Preserve FQCN values exactly and add basename aliases, rather than canonicalizing to one namespace that cannot be inferred generically.
- Keep `fromCli()` source-compatible by appending `array $relationGraph = []` after existing arguments.
- Store basename aliases in the reachability set so downstream callers can check either source FQCN or basename forms.

## Deviations from Plan

None - plan executed as written.

## Issues Encountered

- The stub-pattern scan matched intentional empty-array/null defaults in filter value objects and tests. These are not UI stubs and do not prevent the plan goal.

## Known Stubs

None.

## Threat Flags

None - no new network endpoints, auth paths, file access patterns, schema changes, or trust-boundary surfaces were introduced.

## Verification

- `vendor/bin/phpunit tests/unit/filter/FilterFactoryTest.php --testdox` — passed during Task 1 GREEN.
- `vendor/bin/phpunit tests/unit/filter/MigrationFiltersReachabilityTest.php --testdox` — passed during Task 2 GREEN via combined targeted verification.
- `vendor/bin/phpunit tests/unit/filter/FilterFactoryTest.php tests/unit/filter/MigrationFiltersReachabilityTest.php --testdox` — passed, 18 tests / 57 assertions.
- `composer test-unit` — passed, 406 tests / 1160 assertions; PHPUnit warning: no code coverage driver; Composer/PHP deprecation notice from Composer's bundled json-schema retriever.

## User Setup Required

None - no external services or credentials required.

## Next Phase Readiness

- Plan 09-02B can translate normalized source filters into Craft query surfaces.
- Plan 09-02C can rely on relationGraph-aware source filters for cross-stage consistency.

## Self-Check

PASSED.

- Found all modified source/test files and this summary file.
- Found all task commits: `5178393`, `fbf3996`, `0ac7c6b`, `f8b5f1a`.

---
*Phase: 09-migration-workflow-hardening-page-rooted-introspection-audit*
*Completed: 2026-04-28*
