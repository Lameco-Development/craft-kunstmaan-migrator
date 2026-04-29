---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-03
subsystem: migration-taxonomy-resolver
tags: [php, craft-cms, taxonomies, relations, migration-report, phpunit]

requires:
  - phase: 10-generic-migration-rehearsal-gap-closure
    provides: "10-01 compile/preflight guards and 10-02 visible load fallback reporting"
provides:
  - "Page-rooted lazy taxonomy resolver owned by TaxonomyMigrationService"
  - "RelationHandler delegation for taxonomy-backed non-empty state misses"
  - "Referenced-only default taxonomy mode with explicit full unreferenced import path"
  - "Dry-run would-create/would-link taxonomy reporting without Craft/state writes"
affects: [10-04-verify-rehearsal-proof, migrate-workflow, relation-handlers]

tech-stack:
  added: []
  patterns:
    - "TaxonomyMigrationService::resolveReferenced is the single write/upsert owner for lazy taxonomy resolution"
    - "ResolverContext transports optional taxonomy resolver, dry-run state, and MigrationReport visibility into field handlers"
    - "MigrateController reports taxonomyMode=referenced-only by default; full import requires CLI/settings opt-in or the explicit sub-action"

key-files:
  created:
    - ".planning/phases/10-generic-migration-rehearsal-gap-closure/10-03-SUMMARY.md"
    - "tests/unit/fields/RelationHandlerTaxonomyResolverTest.php"
  modified:
    - "src/load/TaxonomyMigrationService.php"
    - "src/fields/handlers/RelationHandler.php"
    - "src/fields/ResolverContext.php"
    - "src/transform/TransformService.php"
    - "src/console/MigrateController.php"
    - "src/models/Settings.php"
    - "src/Plugin.php"
    - "tests/integration/load/TaxonomyMigrationTest.php"
    - "tests/unit/console/MigrateControllerTaxonomiesWiringTest.php"

key-decisions:
  - "Lazy taxonomy creation is live-only through TaxonomyMigrationService; dry-run reports would-create/would-link and returns unresolved for new terms."
  - "RelationHandler recognizes taxonomy-backed relations only through explicit handler options (`taxonomySource`, `taxonomyFqcn`, `taxonomy`, or `taxonomyBacked`); non-taxonomy misses keep existing unresolved semantics."
  - "Full unreferenced taxonomy import is no longer the default full-pipeline pre-load stage; operators must set `--include-unreferenced-taxonomies`, `Settings::includeUnreferencedTaxonomies`, or run `migrate/taxonomies` explicitly."

patterns-established:
  - "Handler delegation stays thin: state lookup first, then optional taxonomy resolver, with no taxonomy write logic in RelationHandler."
  - "Taxonomy fallback visibility uses `fallback.taxonomy_locale` counters plus `fallback:` warnings so existing REPORT.md fallback rendering picks it up."
  - "Generic test fixtures use neutral topic taxonomy names rather than rehearsal-specific handles."

requirements-completed: [PH10-04, PH10-07]

duration: 9min
completed: 2026-04-28
---

# Phase 10 Plan 10-03: Page-Rooted Taxonomy Resolver Summary

**Page-rooted taxonomy relations now lazily reuse or create referenced terms through TaxonomyMigrationService while full unreferenced vocabulary import remains explicit opt-in.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-04-28T16:08:52Z
- **Completed:** 2026-04-28T16:17:30Z
- **Tasks:** 3
- **Files modified:** 9 code/test files + 1 summary

## Accomplishments

- Added `TaxonomyMigrationService::resolveReferenced(...)` so page-rooted taxonomy relation misses can reuse existing site-agnostic state rows, load the referenced source row, upsert the Craft taxonomy entry, record state, and return the Craft element ID in live mode.
- Preserved dry-run safety: lazy taxonomy resolver reads enough to report `taxonomy.wouldCreate` / `taxonomy.wouldLink`, but does not save Craft entries or write migration state for new terms.
- Made missing taxonomy locale fallback operator-visible through `fallback.taxonomy_locale` counters and `fallback:` warnings.
- Extended `ResolverContext` and `TransformService` so `RelationHandler` can delegate taxonomy-backed misses with the active dry-run state and `MigrationReport`.
- Kept `RelationHandler` thin: state lookup remains first, empty values do not invoke the resolver, non-taxonomy misses are unchanged, and unresolved taxonomy misses are visible in the report.
- Changed full-pipeline taxonomy behavior to referenced-only by default; full unreferenced import now requires `--include-unreferenced-taxonomies`, `Settings::includeUnreferencedTaxonomies`, or the explicit `migrate/taxonomies` sub-action.
- Added generic unit/integration coverage for lazy resolver dry-run/live delegation, unresolved visibility, and full-import opt-in wiring.

## Task Commits

Each task was committed atomically:

1. **Task 1: Extract shared taxonomy find/create resolver** - `dad9dd7` (feat)
2. **Task 2: Delegate taxonomy-backed relation misses to the resolver** - `25da6c3` (feat)
3. **Task 3: Add explicit full taxonomy import path** - `8b2be56` (feat)

Additional corrective commit:

- `1e805fc` (test) - renamed taxonomy resolver fixtures to neutral topic taxonomy names after the genericity scan flagged rehearsal-specific handles in test data.

**Plan metadata:** pending final metadata commit.

## Files Created/Modified

- `src/load/TaxonomyMigrationService.php` - adds `resolveReferenced(...)`, shared taxonomy-row validation/source-row lookup helpers, dry-run would-create/would-link reporting, and visible taxonomy locale fallback reporting.
- `src/fields/handlers/RelationHandler.php` - delegates explicit taxonomy-backed direct relation misses to the taxonomy resolver after state lookup.
- `src/fields/ResolverContext.php` - carries optional taxonomy resolver, dry-run state, and `MigrationReport` into handlers.
- `src/transform/TransformService.php` - passes taxonomy resolver/report/dry-run context during transform.
- `src/Plugin.php` - wires `TransformService::$taxonomyResolver` to the plugin-owned `TaxonomyMigrationService`.
- `src/console/MigrateController.php` - adds `--include-unreferenced-taxonomies`, reports `taxonomyMode=referenced-only|full`, and runs pre-load full taxonomy import only when explicitly enabled.
- `src/models/Settings.php` - adds persistent `includeUnreferencedTaxonomies=false` setting.
- `tests/integration/load/TaxonomyMigrationTest.php` - covers lazy resolver state reuse, dry-run would-create/would-link reporting, missing source-row visibility, and preserves existing taxonomy migration characterization.
- `tests/unit/fields/RelationHandlerTaxonomyResolverTest.php` - covers taxonomy delegation, non-taxonomy misses, empty values, and unresolved reporting.
- `tests/unit/console/MigrateControllerTaxonomiesWiringTest.php` - covers referenced-only default and explicit full-import flag/settings path.

## Decisions Made

- **Live vs dry-run:** New referenced terms are not created during dry-run. Existing state rows may still return their Craft ID for safe would-link visibility, while new terms return unresolved after recording would-create/would-link counts.
- **Taxonomy-backed identification:** The resolver only runs when handler options explicitly mark the relation as taxonomy-backed; there is no broad class-name or state-source heuristic.
- **Default taxonomy mode:** Full-pipeline `migrate --live` is page-rooted/referenced-only by default. Full unreferenced import is opt-in via CLI/settings or the explicit taxonomy sub-action.
- **Fixture genericity:** Test fixtures use neutral `TopicTaxonomy` names so coverage remains structural and not tied to the rehearsal site.

## Verification

Commands run:

```bash
vendor/bin/phpunit tests/integration/load/TaxonomyMigrationTest.php --filter 'Lazy|Resolver|Locale|Fallback|Idempotent' --testdox
vendor/bin/phpunit tests/unit/fields/RelationHandlerTaxonomyResolverTest.php --testdox
vendor/bin/phpunit tests/unit/console/MigrateControllerTaxonomiesWiringTest.php tests/integration/load/TaxonomyMigrationTest.php --testdox
php -l src/load/TaxonomyMigrationService.php
php -l src/fields/handlers/RelationHandler.php
php -l src/fields/ResolverContext.php
php -l src/transform/TransformService.php
php -l src/console/MigrateController.php
php -l src/models/Settings.php
```

Results:

- Lazy/Resolver/Locale/Fallback/Idempotent filtered taxonomy suite: **4 tests, 17 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning and one existing incomplete Craft-bootstrap locale fallback test.
- RelationHandler taxonomy resolver suite: **4 tests, 11 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning.
- Combined taxonomy wiring + integration suite: **15 tests, 58 assertions, exit 0**; PHPUnit reported the pre-existing no-code-coverage warning, one deprecation, and the existing incomplete Craft-bootstrap locale fallback test.
- PHP syntax checks passed for all modified production files.
- Production-code genericity scan found no new CQM-specific page IDs, block handles, or class-name conditionals.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Wired taxonomy resolver through TransformService and Plugin**
- **Found during:** Task 2 (Delegate taxonomy-backed relation misses to the resolver)
- **Issue:** Adding optional resolver fields to `ResolverContext` alone would leave production transform calls without the `TaxonomyMigrationService` dependency, so live relation misses could not delegate.
- **Fix:** Added `TransformService::$taxonomyResolver`, passed resolver/report/dry-run into `ResolverContext`, and wired it from `Plugin::init()`.
- **Files modified:** `src/transform/TransformService.php`, `src/Plugin.php`, `src/fields/ResolverContext.php`
- **Verification:** `vendor/bin/phpunit tests/unit/fields/RelationHandlerTaxonomyResolverTest.php --testdox`
- **Committed in:** `25da6c3`

**2. [Rule 1 - Bug] Renamed rehearsal-specific taxonomy fixture handles**
- **Found during:** Overall genericity scan after Task 3
- **Issue:** New tests initially used rehearsal-shaped `CaseCategory` / `caseCategory` fixture names. Production code stayed generic, but the plan and project constraints require generic regression coverage wherever practical.
- **Fix:** Renamed the fixtures to neutral `TopicTaxonomy` / `topicTaxonomy` names while preserving the same taxonomy-backed relation shape.
- **Files modified:** `tests/integration/load/TaxonomyMigrationTest.php`, `tests/unit/fields/RelationHandlerTaxonomyResolverTest.php`
- **Verification:** Filtered taxonomy resolver suite and RelationHandler taxonomy resolver suite both passed after the rename; production-code genericity scan remained clean.
- **Committed in:** `1e805fc`

---

**Total deviations:** 2 auto-fixed (1 Rule 3 blocking, 1 Rule 1 bug).
**Impact on plan:** Both fixes enforce the planned behavior and genericity constraint; no architectural scope change.

## Issues Encountered

- `gsd-sdk` is not installed in this environment, so STATE/ROADMAP updates and final metadata commit are performed manually with normal git commands.
- PHPUnit consistently reports "No code coverage driver available"; this is an environment warning and did not fail verification commands.
- `TaxonomyMigrationTest::testD09FallbackCopiesSourceLocaleAcrossSitesWhenExtTranslationsEmpty` remains an existing incomplete Craft-bootstrap test from earlier taxonomy work; Plan 10-03 added report-visible fallback behavior and pure resolver coverage without changing that pre-existing bootstrap limitation.

## Known Stubs

None introduced. Stub-pattern scan found nullable dependency-injection properties, intentional empty-array accumulators, and the pre-existing incomplete Craft-bootstrap taxonomy locale test; none are new UI/data stubs and none prevent the plan goal from being achieved.

## Threat Flags

None. This plan did not introduce new network endpoints, auth paths, file access patterns, schema changes, or new trust-boundary surfaces.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 10-04 can verify taxonomy-backed relation fields through page-rooted lazy resolution instead of relying on a preloaded full taxonomy vocabulary.
- Verify/report work can consume visible `taxonomyMode=*`, `taxonomy.wouldCreate`, `taxonomy.wouldLink`, and `fallback.taxonomy_locale` evidence.
- The restored-backup rehearsal should run with default referenced-only taxonomy mode first; operators can use `--include-unreferenced-taxonomies` only if the full legacy vocabulary is intentionally required.

## Self-Check: PASSED

- Summary exists at `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-03-SUMMARY.md`.
- Task commits exist: `dad9dd7`, `25da6c3`, `8b2be56`, `1e805fc`.
- Verification commands listed above were run after the corrective commit and exited 0.
- Modified production code remains generic and does not add CQM-specific page IDs, block handles, or class-name conditionals.

---
*Phase: 10-generic-migration-rehearsal-gap-closure*
*Completed: 2026-04-28*
