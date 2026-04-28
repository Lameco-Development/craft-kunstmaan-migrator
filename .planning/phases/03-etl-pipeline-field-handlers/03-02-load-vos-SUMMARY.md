---
phase: 03-etl-pipeline-field-handlers
plan: 02
subsystem: etl
tags: [load, vos, taxonomy, asset-paths, security, t-04-11, firewall-interface]

# Dependency graph
requires:
  - phase: 02-mapping-foundation
    provides: PSR-4 src/ namespace + verbatim-port discipline (D-46)
provides:
  - "MigrationStateReader narrow read interface (firewall pattern from PATTERNS §5)"
  - "MigrationOptions public-r/w VO (6 args; not readonly per PATTERNS §7)"
  - "AssetPathResolver path-traversal-safe local resolver (T-04-11 mitigation)"
  - "TaxonomyResolver abstract base + BulkNameMatchTaxonomyResolver default impl"
affects:
  - 03-03-migration-state-service (implements MigrationStateReader)
  - 03-04 / 03-05 / 03-06 / 03-07 / 03-08 (handlers consume MigrationStateReader, MigrationOptions)
  - 03-09 transform service (uses AssetPathResolver static helpers)
  - 03-13 asset-migration-service (uses AssetPathResolver)

# Tech tracking
tech-stack:
  added: []  # no new libraries
  patterns:
    - "Narrow read-only interface firewall (PATTERNS §5)"
    - "Public-r/w VO convention for inter-stage state (PATTERNS §7)"
    - "T-04-11 realpath-on-both-sides + prefix-match path-traversal mitigation (PATTERNS §8)"
    - "Fail-fast preflight bulk taxonomy resolution with 30-miss truncation (PATTERNS §9)"
    - "MigrationConfigError → \\RuntimeException reshape recipe (PATTERNS §3)"

key-files:
  created:
    - "src/load/MigrationStateReader.php (45 LOC) — narrow read interface"
    - "src/load/MigrationOptions.php (47 LOC) — per-run flags VO"
    - "src/load/AssetPathResolver.php (107 LOC) — path-traversal-safe local resolver + helpers"
    - "src/load/TaxonomyResolver.php (46 LOC) — abstract base"
    - "src/load/BulkNameMatchTaxonomyResolver.php (146 LOC) — default name-match impl"
  modified: []

key-decisions:
  - "MigrationStateReader relocated from v1's bridge/fields/ to v2's load/ — co-located with sole implementer per PATTERNS §5; handlers receive narrow type via ResolverContext."
  - "MigrationOptions kept mutable (no readonly modifiers) — operator code mutates verbosity etc. between stages; preserves v1 behaviour per PATTERNS §7."
  - "MigrationOptions + BulkNameMatchTaxonomyResolver marked final (v2 convention); v1 lacked the modifier but functional behaviour is unchanged."
  - "MigrationConfigError typed-error class dropped — both throw sites in BulkNameMatchTaxonomyResolver rewritten to \\RuntimeException(sprintf(...)) carrying v1 message bodies byte-for-byte; operator-facing message preserved."
  - "AssetPathResolver kept verbatim including the realpath-on-both-sides + prefix-match safety logic — load-bearing for T-04-11; added a 2-line traceability comment to flag any future modification needs threat-model re-evaluation."

patterns-established:
  - "Narrow read interface co-located with implementer (firewall): handlers receive MigrationStateReader, never the wide MigrationStateService write surface."
  - "Verbatim port discipline (D-46) supersedes plan acceptance-criterion grep counts when v1 source contradicts the criterion."

requirements-completed: [ETL-04, ETL-05]

# Metrics
duration: ~25min
completed: 2026-04-26
---

# Phase 03 Plan 02: Load-namespace VOs Summary

**Five load/ files landed: MigrationStateReader firewall interface + MigrationOptions VO + AssetPathResolver T-04-11 path-traversal mitigation + TaxonomyResolver abstract base + BulkNameMatchTaxonomyResolver default impl. All verbatim-ported from v1 with namespace flatten + the MigrationConfigError → \\RuntimeException reshape (PATTERNS §3).**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-26T13:00:00Z (approx — wave 1 dispatch)
- **Completed:** 2026-04-26T13:24:54Z
- **Tasks:** 2 / 2
- **Files created:** 5

## Accomplishments

- `MigrationStateReader` narrow read interface ported with all 3 method signatures byte-for-byte (`getTargetId` / `getTargetUid` / `get`); relocated from v1's `bridge/fields/` to v2's `load/` per PATTERNS §5 (co-located with sole implementer landing in 03-03).
- `MigrationOptions` 6-arg public-r/w VO ported verbatim (defaults: dryRun=false, force=false, verbosity=0, batchSize=50, legacyClassFilter=null, skipAssets=false); marked `final` per v2 convention; `readonly` deliberately NOT added per PATTERNS §7.
- `AssetPathResolver::resolveLocal()` ported with the realpath-on-both-sides + prefix-match safety check intact (T-04-11 mitigation). Added a 2-line traceability comment flagging that the realpath logic must not be modified without re-evaluating the threat model.
- `TaxonomyResolver` abstract base ported — `MigrationConfigError` import dropped, docblocks updated to declare `\RuntimeException` throw type; abstract method signatures unchanged.
- `BulkNameMatchTaxonomyResolver` default impl ported with both throw sites (`resolve()` single-miss + `resolveAll()` bulk) rewritten to `\RuntimeException(sprintf(...))` carrying v1 message bodies byte-for-byte. Lazy-cache pattern (`Entry::find()->section($handle)->site('*')->unique()->status(null)`), 30-miss `array_slice` truncation, first-write-wins collision handling, and `normaliseFn` callback all preserved verbatim.

## Task Commits

1. **Task 1: Port MigrationStateReader + MigrationOptions + AssetPathResolver** — `4808be9` (feat) — see Issue 1 below.
2. **Task 2: Port TaxonomyResolver + BulkNameMatchTaxonomyResolver** — `fb8466d` (feat).

## Files Created

- `src/load/MigrationStateReader.php` (45 LOC) — 3-method narrow read firewall interface; v1 `bridge/fields/MigrationStateReader.php` ported verbatim, namespace flattened to `lameco\kunstmaanmigrator\load`.
- `src/load/MigrationOptions.php` (47 LOC) — public-r/w VO; v1 `craft/load/MigrationOptions.php` ported verbatim plus `final` modifier (v2 convention).
- `src/load/AssetPathResolver.php` (107 LOC) — path-safety helpers; v1 `craft/load/AssetPathResolver.php` ported verbatim plus T-04-11 traceability comment block.
- `src/load/TaxonomyResolver.php` (46 LOC) — abstract base; `MigrationConfigError` import dropped, docblocks updated to `\RuntimeException`.
- `src/load/BulkNameMatchTaxonomyResolver.php` (146 LOC) — default name-match impl; both throw sites rewritten to `\RuntimeException(sprintf(...))` per PATTERNS §3 reshape recipe.

## Decisions Made

All pre-recorded in plan frontmatter / PATTERNS.md — no new decisions required during execution. See `key-decisions` block above.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Acceptance-criterion bug] Verbatim port count mismatches in plan acceptance criteria**
- **Found during:** Task 1 verification (`AssetPathResolver` realpath count) and Task 2 verification (`BulkNameMatchTaxonomyResolver` throw count + `site('*')` count).
- **Issue:** Plan acceptance criteria specified literal grep counts that contradict the v1 source under verbatim-port discipline (D-46):
  - `grep -c 'realpath' src/load/AssetPathResolver.php` returns 6 (plan said 2). The `=2` was authored against the load-bearing call sites only, but the plan's own `<action>` instructed adding a 2-line comment containing "realpath" twice, plus the v1 docblock already mentions realpath twice. v1 file: 4 mentions; v2 file with the plan-mandated comment block: 6.
  - `grep -c 'throw new \\RuntimeException' src/load/BulkNameMatchTaxonomyResolver.php` returns 2 (plan said 1). v1 has 2 `MigrationConfigError::accumulated` throw sites — one in `resolve()` (single-value) and one in `resolveAll()` (bulk). Plan's reshape recipe (PATTERNS §3) replaces each typed-error throw with `\RuntimeException(sprintf(...))`, yielding 2 throws. Plan's `=1` only accounted for the bulk site.
  - `grep -c \"site('*')\" src/load/BulkNameMatchTaxonomyResolver.php` returns 3 (plan said 1). v1 has 3: 1 builder call + 2 docblock mentions. Verbatim port preserves all 3.
- **Fix:** Did not modify any source — verbatim port discipline (D-46) supersedes the plan's grep counts. v1 byte-for-byte parity confirmed via `grep -c` against `~/Sites/craft-kunstmaan-migrator/src/craft/load/`.
- **Files modified:** None (no fix applied to source; this is a plan-criterion documentation correction).
- **Verification:** All other plan-level criteria pass: `php -l` clean for all 5 files; zero `MigrationConfigError` refs in `src/load/`; zero stale namespace refs; all `min_lines` thresholds satisfied; `extends TaxonomyResolver` link wired correctly.
- **Committed in:** N/A (no source change required).

**2. [Rule 1 — Cross-wave commit collision] Task 1 files swept into sibling Plan 03-01's commit**
- **Found during:** Task 1 commit step.
- **Issue:** Wave 1 sibling Plan 03-01 (executing in parallel against `src/fields/`) ran a `git commit` that swept up the 3 staged files from Plan 03-02 (`src/load/MigrationStateReader.php`, `MigrationOptions.php`, `AssetPathResolver.php`) into commit `4808be9` titled `feat(03-01): port FieldHandlerRegistry`. The sibling agent appears to have used a broad add (`git add .` or `git add -A`) rather than file-by-file staging despite the executor protocol's explicit "NEVER `git add .`" rule. Files-on-disk and tracked-state are correct; only the commit subject does not match the plan boundary.
- **Fix:** None applied — files are correctly tracked, content is correct, splitting the commit retroactively would require destructive history rewrite (`git rebase -i`) which the executor protocol prohibits without explicit user approval. Documenting the collision here so the verifier and reviewer have ground truth: Task 1's logical commit hash is `4808be9` even though the message belongs to 03-01.
- **Files modified:** None (the disk state is already correct).
- **Verification:** `git ls-files src/load/` shows all 5 files tracked; `git log --oneline -- src/load/` shows `4808be9` (Task 1 files) and `fb8466d` (Task 2 files); both have valid Phase 3 conventional-commit prefixes.
- **Committed in:** `4808be9` (sibling-mislabeled — actual content matches Task 1 verbatim port).

---

**Total deviations:** 2 auto-fixed (1 acceptance-criterion bug, 1 cross-wave commit collision).
**Impact on plan:** No code-correctness impact. Verbatim ports are byte-for-byte parity with v1 (modulo the documented namespace flatten + `\RuntimeException` reshape + `final` modifier). The commit-hash collision is a process artifact only; reviewers should treat `4808be9` as Task 1's commit for 03-02 alongside its labelled 03-01 work.

## Issues Encountered

- See Deviation 2 (cross-wave commit collision). Resolved without source modification by documenting the actual hash mapping.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 03-03 (`MigrationStateService`) can now `implements MigrationStateReader` against the interface declared here.
- Plans 03-04 through 03-08 (handlers) can construct `ResolverContext` with `MigrationStateReader` + `MigrationOptions` typed fields.
- Plan 03-09 (`TransformService`) and Plan 03-13 (`AssetMigrationService`) can call `AssetPathResolver::resolveLocal()` / `::targetYear()` / `::sanitizeFilename()` static helpers.
- Plan 03-XX (downstream taxonomy-using stages) can construct `BulkNameMatchTaxonomyResolver` with a `craftSectionHandle` + optional `normaliseFn`.

## Self-Check: PASSED

- `[FOUND] src/load/MigrationStateReader.php` (45 LOC, ≥ min_lines 25)
- `[FOUND] src/load/MigrationOptions.php` (47 LOC, ≥ min_lines 25)
- `[FOUND] src/load/AssetPathResolver.php` (107 LOC, ≥ min_lines 50)
- `[FOUND] src/load/TaxonomyResolver.php` (46 LOC, ≥ min_lines 30)
- `[FOUND] src/load/BulkNameMatchTaxonomyResolver.php` (146 LOC, ≥ min_lines 100)
- `[FOUND] commit 4808be9` (Task 1 — sibling-mislabeled per Deviation 2; content correct)
- `[FOUND] commit fb8466d` (Task 2)
- `[VERIFIED] php -l` clean for all 5 files
- `[VERIFIED] zero MigrationConfigError refs in src/load/`
- `[VERIFIED] zero stale namespace refs (bridge|craft|kunstmaan) in src/load/`
- `[VERIFIED] BulkNameMatchTaxonomyResolver extends TaxonomyResolver wiring (key_link)`
- `[VERIFIED] T-04-11 traceability comment present in AssetPathResolver`
- `[VERIFIED] MigrationOptions has zero readonly modifiers (PATTERNS §7 contract)`

---
*Phase: 03-etl-pipeline-field-handlers*
*Plan: 02 (Wave 1)*
*Completed: 2026-04-26*
