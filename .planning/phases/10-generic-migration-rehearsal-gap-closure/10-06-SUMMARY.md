---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10-06
subsystem: migration-finalize-audit
tags: [ckeditor, finalize, page-rooted-coverage, relation-fk, rehearsal-gate, phpunit]

requires:
  - phase: 10-generic-migration-rehearsal-gap-closure
    provides: "Plans 10-01..10-05: compile/load safeguards, taxonomy lazy resolver, transform blocking sentinel, CQM rehearsal runbook"
provides:
  - "Reasoned unresolved CKEditor token diagnostics for `[NT...]`, encoded `%5BNT...%5D`, `[M...]`, and encoded `%5BM...%5D` finalize paths"
  - "Live full migrate and standalone finalize gate that fails when `finalize.unresolvable` is nonzero"
  - "Evidence-based Page-rooted coverage discovery that removes no-input synthetic blockers while preserving evidence-backed FK/asset/relation gaps"
  - "Runbook gate requiring zero unresolved finalize tokens and zero blocking Page-rooted warning/unsupported rows unless release-owner accepted"
affects: [phase-10-release-gate, cqm-rehearsal, page-rooted-audit, finalize-reporting]

tech-stack:
  added: []
  patterns:
    - "Structural diagnostics only: token family/id/site/field/reason without CKEditor bodies or samples"
    - "Coverage warnings require source/mapping/relation evidence; scanner absence is not page-owned evidence"
    - "Accepted column rows with empty target handles are actionable warnings, not migrated coverage"

key-files:
  created:
    - ".planning/phases/10-generic-migration-rehearsal-gap-closure/10-06-SUMMARY.md"
  modified:
    - "src/finalize/CkeditorRewriterService.php"
    - "src/finalize/FinalizeWalker.php"
    - "src/console/MigrateController.php"
    - "src/load/MigrationReport.php"
    - "src/audit/PageRootedSurfaceDiscovery.php"
    - "src/audit/PageRootedCoverageAuditor.php"
    - "tests/unit/finalize/CkeditorRewriterServiceTest.php"
    - "tests/unit/console/MigrateControllerFailureExitTest.php"
    - "tests/unit/audit/PageRootedSurfaceDiscoveryTest.php"
    - "tests/unit/audit/PageRootedCoverageAuditorTest.php"
    - ".planning/rehearsal/v1.0/cqm/README.md"

key-decisions:
  - "Do not implement lazy find/create for generic page-owned entry relations in this plan; existing architecture supports lazy taxonomy and JIT assets, while arbitrary entry relations require operator mapping and, often, upfront/promoted entry creation/state rows."
  - "Do not downgrade evidence-backed FK/asset mapping gaps merely because no scanner service metadata exists; accepted empty-handle source columns remain blocking/actionable warnings."
  - "Do not regenerate external CQM compile artifacts during this execution; current external artifacts were inspected read-only and code behavior is proven by targeted tests."

patterns-established:
  - "Finalize diagnostics are buffered in the rewriter and consumed per field by FinalizeWalker to avoid stale diagnostic leakage."
  - "REPORT.md renders at most 100 finalize diagnostic rows while aggregate counts remain complete."
  - "Page-rooted taxonomy/dataProvider rows require page-owned linkage through fields, relations, structure, or explicit parent/page proposal metadata."

requirements-completed: [PH10-04, PH10-07, PH10-08]

duration: 6m14s
completed: 2026-04-28T18:15:27Z
---

# Phase 10 Plan 10-06: Release Blocker Closure Summary

**Generic finalize-token diagnostics and evidence-based Page-rooted coverage gates now block release on real unresolved content instead of synthetic no-input placeholders.**

## Performance

- **Duration:** 6m14s
- **Started:** 2026-04-28T18:09:13Z
- **Completed:** 2026-04-28T18:15:27Z
- **Tasks:** 3/3
- **Files modified:** 11 plus this summary

## Accomplishments

- Finalize now records unresolved-token diagnostics by token family (`nt`/`media`), legacy id, literal token, site id, and reason; `FinalizeWalker` attaches entry id and field handle without including body HTML or samples.
- Live full migrate and standalone `migrate/finalize --live` now record a blocking `MigrationReport` failure when `finalize.unresolvable > 0`, render a bounded diagnostics table, and return a nonzero report exit.
- Page-rooted discovery no longer creates blocking `asset:not-discovered`, `ckeditor_ref:not-discovered`, or `many_to_*:not-discovered` rows solely from absent scanner/relation metadata.
- Evidence-backed FK/asset source columns with empty target handles remain visible as warning rows, so NewsPage-like `employee_id`, `image_id`, and `preview_image_id` gaps cannot be silently treated as migrated.
- Global taxonomy/dataProvider proposals are no longer repeated across every page root unless page-owned evidence links the proposal to that page.
- CQM runbook now states the release gate for zero unresolved finalize tokens and zero unaccepted blocking Page-rooted coverage rows.

## Task Commits

1. **Task 1: Broaden finalize token resolution and expose unresolved diagnostics** — `2ddca4a` (`feat(10-06): add finalize token diagnostics`)
2. **Task 2: Make nonzero finalize unresolved counts release-blocking and documented** — `cd7e371` (`feat(10-06): block unresolved finalize output`)
3. **Task 3: Reclassify Page-rooted coverage absence rows using evidence-based discovery** — `41cb413` (`feat(10-06): make page-rooted coverage evidence-based`)

## Files Created/Modified

- `src/finalize/CkeditorRewriterService.php` — adds diagnostics buffer/consumer, unresolved NT/media reason rows, and a pure NT cache builder that maps through sourceKey/ref rows and `meta.kumaNodeId`.
- `src/finalize/FinalizeWalker.php` — returns `unresolvedDiagnostics` and attaches entry/site/field context per rewritten CKEditor field.
- `src/load/MigrationReport.php` — carries `finalizeUnresolvedDiagnostics` for REPORT.md rendering.
- `src/console/MigrateController.php` — records blocking finalize failures, writes finalize-only reports when needed, and renders bounded diagnostics.
- `src/audit/PageRootedSurfaceDiscovery.php` — removes synthetic no-input blockers, scopes taxonomy/dataProvider rows to page-owned evidence, and keeps empty-handle FK/asset columns actionable.
- `src/audit/PageRootedCoverageAuditor.php` — prevents accepted column rows with empty target handles from being force-classified as migrated.
- `tests/unit/finalize/CkeditorRewriterServiceTest.php` — covers raw/encoded token replacement, diagnostics consumption/reset, and pure NT source/meta mapping.
- `tests/unit/console/MigrateControllerFailureExitTest.php` — covers nonzero finalize blocking, diagnostics rendering, and zero-unresolved no-failure behavior.
- `tests/unit/audit/PageRootedSurfaceDiscoveryTest.php` — covers no-input cleanup, FK/asset evidence warnings, and taxonomy/dataProvider page-owned scoping.
- `tests/unit/audit/PageRootedCoverageAuditorTest.php` — updates no-input CKEditor classification expectations.
- `.planning/rehearsal/v1.0/cqm/README.md` — documents zero-unresolved and Page-rooted coverage release gates.

## Decisions Made

1. **Arbitrary related entries should not be lazy find/create in `RelationHandler` for this plugin’s generic architecture yet.** Lazy creation is safe today only where the target type has a dedicated generic resolver contract: assets (`AssetHandler`/JIT `AssetMigrationService`) and taxonomy-backed relations (`TaxonomyMigrationService::resolveReferenced`). A generic page-owned FK to an arbitrary entry type needs a known target entry type/section, field handle, state source, localization semantics, and ordering/topology. Creating those “on the go” from a page transform would risk partial entries, wrong sections, and duplicated state. The current generic architecture should prefer **upfront/promoted creation for real entry relations** so state rows exist before relation fields resolve, with lazy resolver support added only per explicitly-modeled target family.
2. **For this plan, NewsPage-like FK/asset evidence is made blocking/actionable rather than transformed automatically.** The root cause in the provided CQM evidence is mapping/compiler shape: extract correctly exposes `employee_id`, `image_id`, `preview_image_id`, and `_rel:employee.*`, but active `nodeClasses.<Page>.fields` is empty and several proposals were accepted with empty handles. Transform can only emit Craft field values for compiled `nodeClasses.fields`; it has no generic relation/asset field target contract for those empty rows. The fix here is to make such evidence-backed gaps visible as warning coverage until operators map/drop/out_of_scope them or a follow-up relation architecture adds safe promoted-entry creation.
3. **External CQM artifacts were inspected read-only; no full restored workflow or compile regeneration was run.** Compile semantics changed, but targeted unit tests prove the generic behavior and external artifacts remain useful baseline evidence. Regeneration should occur in the CQM project during release verification with operator awareness because it writes `mapping.yaml`/coverage artifacts.

## Relation-Shape Blocker Answer

The generic root cause is **not extract loss**: `ExtractService` already flattens ManyToOne evidence into `detail` keys (`employee_id`, `_rel:<prop>.<col>`, and information-schema alias keys). The transformed payload loses the employee/image relation because `TransformService` only iterates compiled `nodeClasses[fqcn].fields`; in the inspected CQM active mapping, `App\Entity\Pages\NewsPage.fields` is `{}` and accepted/proposed FK rows have empty `targetHandle`/`handler` or no safe generic relation options. Therefore no `AssetHandler` or `RelationHandler` invocation occurs.

For this plugin’s generic architecture:

- **Assets:** lazy/JIT resolution is already appropriate when a mapped asset field exists (`AssetHandler` emits deferred `asset:N` tokens and load resolves/materializes them). Page-owned asset FKs with no mapped Craft asset field must remain coverage blockers.
- **Taxonomies:** lazy find/create is appropriate because `TaxonomyMigrationService::resolveReferenced()` owns a generic taxonomy contract and records state rows.
- **Arbitrary related entries (employee/team/news-author-style):** prefer **upfront/promoted creation** before page relation fields resolve, or an explicit future relation plan that defines `mode: promote|embed`, target section/entry type, state source, locale/title fallback, and ordering. Lazy find/create inside generic `RelationHandler` is too under-specified for arbitrary entry classes.
- **Current 10-06 behavior:** evidence-backed FK/asset gaps now stay actionable in Page-rooted coverage instead of disappearing or being downgraded as no-input placeholders. When mapping exists, transform/handlers can emit field values; when mapping is empty/ambiguous, coverage blocks release with structural evidence and remediation expectations.

## External CQM Baseline Counts (Read-only)

Current external artifacts under `/Users/macbook25/Sites/cqm-craft-website/storage/migration` were inspected without regeneration:

| Metric | Baseline observed |
|---|---:|
| `REPORT.md` `finalize.unresolvable` | 435 |
| `REPORT.md` total failed | 0 |
| transformed files containing `MIGRATION:UNRESOLVED` | 217 |
| transformed raw `[NT...]` token count | 741 |
| transformed raw `[M...]` token count | 10 |
| `PAGE-ROOTED-COVERAGE.md` `asset:not-discovered` warning rows | 28 |
| `PAGE-ROOTED-COVERAGE.md` `ckeditor_ref:not-discovered` warning rows | 28 |
| `many_to_one:not-discovered` unsupported rows | 28 |
| `many_to_many:not-discovered` unsupported rows | 28 |
| `one_to_many:not-discovered` unsupported rows | 28 |
| implicit-content “No implicit content page-part mapping is configured” rows | 19 |
| total coverage warning rows | 439 |
| total coverage unsupported rows | 84 |

After counts from regenerated CQM artifacts are **not available** because compile/full workflow was not rerun in the external project during this plan. The expected after behavior is represented by the targeted PHPUnit assertions: absent scanner/relation metadata no longer emits blocking placeholder rows, while empty-mapped FK/asset evidence remains warning/actionable.

## Verification

- `vendor/bin/phpunit tests/unit/finalize/CkeditorRewriterServiceTest.php --filter 'Nt|NodeTranslation|KumaMedia|Placeholder|Unresolved|Diagnostic|Encoded' --testdox` — pass (18 tests, 63 assertions; only no-coverage runner warning).
- `vendor/bin/phpunit tests/unit/console/MigrateControllerFailureExitTest.php --filter 'Finalize|Unresolvable|Unresolved|Report|Blocking' --testdox` — pass (9 tests, 34 assertions; no-coverage runner warning plus one pre-existing PHP/PHPUnit deprecation in this test file path).
- `vendor/bin/phpunit tests/unit/audit/PageRootedSurfaceDiscoveryTest.php tests/unit/audit/PageRootedCoverageAuditorTest.php --filter 'PageRooted|Missing|Service|Relation|Taxonomy|DataProvider|Implicit|Coverage|Dropped|OutOfScope|Fk|Asset' --testdox` — pass (6 tests, 128 assertions; only no-coverage runner warning).
- `php -l` clean for:
  - `src/finalize/CkeditorRewriterService.php`
  - `src/finalize/FinalizeWalker.php`
  - `src/console/MigrateController.php`
  - `src/audit/PageRootedSurfaceDiscovery.php`
  - `src/audit/PageRootedCoverageAuditor.php`
- `composer test` — pass (502 tests, 1690 assertions; existing no-coverage warning, one deprecation, one PHPUnit deprecation, one skipped, one incomplete).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Evidence-backed empty-handle accepted column rows no longer classify as migrated**
- **Found during:** Relation-shape blocker investigation coupled to Task 3
- **Issue:** CQM evidence showed `employee_id`, `image_id`, and `preview_image_id` were accepted with empty handles; the auditor’s mapping category override classified accepted column identifiers as `migrated` even when no compiled field could transform them.
- **Fix:** `PageRootedSurfaceDiscovery` emits accepted/proposed/needs-review empty-handle column evidence as `warning`, and `PageRootedCoverageAuditor::mappingCategories()` no longer overrides accepted column rows that lack a target handle.
- **Files modified:** `src/audit/PageRootedSurfaceDiscovery.php`, `src/audit/PageRootedCoverageAuditor.php`, audit tests.
- **Verification:** `testFkAndAssetColumnsWithEmptyMappingRemainEvidenceBackedWarnings()` and coverage/auditor targeted tests.
- **Committed in:** `41cb413`.

**2. [Rule 2 - Missing Critical Functionality] Standalone finalize now writes a report when unresolved tokens block release**
- **Found during:** Task 2
- **Issue:** The plan allowed standalone finalize to print diagnostics or wire report rendering. Without a report, the release audit trail would lack `finalize.unresolvable` and diagnostics for a standalone `migrate/finalize --live` run.
- **Fix:** Standalone live finalize now builds a finalize-only `MigrationReport`, writes `REPORT.md` when warnings/failures/diagnostics exist, and returns through `reportExitCode()`.
- **Files modified:** `src/console/MigrateController.php`, `src/load/MigrationReport.php`, console tests.
- **Verification:** `testFinalizeUnresolvedGateRecordsBlockingFailureAndDiagnostics()` and targeted console tests.
- **Committed in:** `cd7e371`.

**Total deviations:** 2 auto-fixed (Rule 2 missing critical functionality).
**Impact on plan:** Both fixes are directly required for truthful release gating and preventing silent page-owned content loss; no CQM-specific production logic was introduced.

## Known Stubs

None. Stub-pattern scan found only intentional empty-array/null initializers, existing placeholder-copy comments for report sections, and test literals; no created/modified stub prevents the plan goal.

## Threat Flags

None. Changes touch existing local console/report/audit/finalize surfaces only; no new network endpoint, auth path, file-access trust boundary, or schema change was introduced.

## Issues Encountered

- `gsd-sdk` was not available in this shell (`command not found`), so state/roadmap/requirements updates and final metadata commit were performed manually rather than through SDK handlers.
- External CQM grep output was large; counts were rerun with grouped read-only greps and recorded above.
- PHPUnit reports a no-code-coverage warning in this local environment and `composer test` still reports one skipped and one incomplete test from existing suite behavior.

## User Setup Required

None. No new external services or credentials are required.

## Remaining Blockers / Follow-up

- External CQM artifacts still show pre-plan baseline counts until compile/migrate/finalize are rerun in `/Users/macbook25/Sites/cqm-craft-website`.
- NewsPage-like employee/team relation promotion needs a follow-up generic relation architecture plan if operators want automatic upfront creation of arbitrary related entries instead of explicit mapping/drop/out_of_scope coverage rows.
- Release owner still must review any regenerated Page-rooted `warning`/`unsupported` rows backed by real page-owned evidence.

## Self-Check: PASSED

- Created summary path exists: `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-06-SUMMARY.md`.
- Task commits exist: `2ddca4a`, `cd7e371`, `41cb413`.
- No unexpected tracked-file deletions detected in task commits.
- Unrelated untracked `.claude/` was left untouched.

---
*Phase: 10-generic-migration-rehearsal-gap-closure*
*Completed: 2026-04-28*
