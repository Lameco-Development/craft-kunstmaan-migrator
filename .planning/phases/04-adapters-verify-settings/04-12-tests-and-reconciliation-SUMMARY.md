---
phase: 04-adapters-verify-settings
plan: 12
subsystem: testing

tags: [phpunit, characterization-tests, reconciliation, adp-03, composer-suggest, b1-fix, d-66, rca-classifier]

# Dependency graph
requires:
  - phase: 04-adapters-verify-settings
    provides: SeomaticPayloadBuilder, SnapshotDiffer, SpotCheckUrlFetcher, CountGateService, CaptureBaselineHtmlService, AssetMigrationService.classifyAssetFailureReason
provides:
  - PHPUnit 11 unit-test coverage for 6 pure-function services
  - Phase 4 aggregate v1↔v2 RECONCILIATION.md (per D-54)
  - ADP-03 invariant guard (composer suggest, not require)
affects: [phase-05-tests-rehearsal-release, future-verify-capture-baseline-deep, future-doctor-checks]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pure-function-first testing — services with public test seams (setResolver, public ?fetcher) and Reflection-only access for private classifiers (PHP 8.1+ no setAccessible)"
    - "Characterization tests over a literal-HTML diff path that bypasses the Craft/Guzzle fetch branch"
    - "Phase-level RECONCILIATION as an INDEX over per-plan primary records (cross-cutting reshapes lifted out)"

key-files:
  created:
    - tests/verify/SnapshotDifferTest.php
    - tests/verify/SpotCheckUrlFetcherTest.php
    - tests/verify/CaptureBaselineHtmlServiceTest.php
    - tests/verify/CountGateServiceTest.php
    - tests/load/SeomaticPayloadBuilderTest.php
    - tests/load/AssetMigrationServiceRcaTest.php
    - tests/ComposerSuggestTest.php
    - .planning/phases/04-adapters-verify-settings/RECONCILIATION.md
  modified: []

key-decisions:
  - "Reflection-only access for private classifyAssetFailureReason — no production-code surface change for testability (PHP 8.1+ private access is default; setAccessible deprecated since PHP 8.5)"
  - "Test the line-level diff path of SpotCheckUrlFetcher::diff() with literal HTML (not URL-prefixed) so Craft::createGuzzleClient is never reached in unit context"
  - "CountGateService unit tests cover only the pure delta arithmetic + sentinel handling — full DB-coupled run() integration deferred to Phase 5 / TST-02"
  - "RECONCILIATION.md is an INDEX over per-plan primary records (Plans 04-02..04-09 SUMMARY sections) — cross-cutting reshapes (namespace flatten, sites source, tolerance source, D-59 SHA drop) lifted to a single section"

patterns-established:
  - "Pattern: characterization-style PHPUnit tests for ported services — assert observable behavior of v1 contracts (CLAUDE.md test discipline; v1 shipped 1.0 with no tests)"
  - "Pattern: Reflection without setAccessible — PHP 8.1+ private access is default; ReflectionMethod::setAccessible() emits a deprecation warning in PHP 8.5"
  - "Pattern: Phase-level aggregate RECONCILIATION mirroring Phase 02.1 precedent — per-plan primary records remain load-bearing"

requirements-completed: [ADP-01, ADP-02, ADP-03, VER-01, VER-02, VER-03, CFG-02]

# Metrics
duration: 5min
completed: 2026-04-26
---

# Phase 4 Plan 12: PHPUnit tests + Phase 4 RECONCILIATION + composer suggest audit

**7 PHPUnit characterization-test files covering 6 pure-function services + ADP-03 invariant guard, plus the phase-level v1↔v2 RECONCILIATION index. Test count grew 60 → 83 (+23), assertions grew 137 → 210 (+73). composer test exits 0.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-04-26T19:54:41Z
- **Completed:** 2026-04-26T19:59:50Z
- **Tasks:** 9
- **Files created:** 8

## Accomplishments

- Six new PHPUnit test files cover the six pure-function Phase 4 services
  (SnapshotDiffer, SpotCheckUrlFetcher B1 fix, CountGateService delta,
  CaptureBaselineHtmlService URL-list filter, SeomaticPayloadBuilder
  column→payload contract, AssetMigrationService RCA classifier).
- ComposerSuggestTest locks the ADP-03 invariant — SEOmatic + Retour stay as
  `suggest`, never `require` — so a future maintainer can't silently flip
  the runtime-detection contract from PROJECT.md.
- Phase 4 aggregate RECONCILIATION.md ships at the phase root, mirroring
  the Phase 02.1 precedent. It indexes the per-plan dispositions and lifts
  cross-cutting reshapes (namespace flatten, `$sites` source, tolerance
  source, D-59 SHA-path drop, hardcoded site-handle removal) into one
  section so they're stated once.
- `composer test` is green at the end of Phase 4: 83 tests / 210 assertions
  / 0 failures (the lone deprecation is in `vendor/craftcms/cms` —
  pre-existing, out of scope).

## Task Commits

Each task was committed atomically on `main`:

1. **Task 01: SnapshotDifferTest** — `fdd87d6` (test)
2. **Task 02: SpotCheckUrlFetcherTest** — `d9e7c1f` (test)
3. **Task 03: CaptureBaselineHtmlServiceTest** — `be5a497` (test)
4. **Task 04: CountGateServiceTest** — `b50a092` (test)
5. **Task 05: SeomaticPayloadBuilderTest** — `9b8532e` (test)
6. **Task 06: AssetMigrationServiceRcaTest** — `98dc616` (test)
7. **Task 07: Phase 4 RECONCILIATION.md** — `c23bb92` (docs)
8. **Task 08: ComposerSuggestTest** — `71b5001` (test)
9. **Task 09: Final guard (composer test)** — no commit (verification only; suite green at exit 0)

**Plan metadata commit:** added below alongside SUMMARY.md / STATE.md / ROADMAP.md.

## Files Created

- `tests/verify/SnapshotDifferTest.php` — 4 tests: identical-empty, scalar diff path triple, META_IGNORE under meta, list bracket-index path
- `tests/verify/SpotCheckUrlFetcherTest.php` — 4 tests: identical-empty, B1 line-level diff invariant, CRSF strip, cache-busting query strip
- `tests/verify/CaptureBaselineHtmlServiceTest.php` — 2 tests: comments + blanks skipped (stub fetcher), missing list throws
- `tests/verify/CountGateServiceTest.php` — 4 tests: pass-within-tolerance, fail-exceeding-tolerance, zero-expected → delta=0.0, negative-actual sentinel fails
- `tests/load/SeomaticPayloadBuilderTest.php` — 4 tests: null row 6-key empty shape, populated row 6-key locked shape, og→meta fallback, metaBundleSettings always fromCustom
- `tests/load/AssetMigrationServiceRcaTest.php` — 4 tests via Reflection: filesystem_404 / mime_mismatch / too_large / deferred_unresolved (D-66 closed set)
- `tests/ComposerSuggestTest.php` — 1 test, 9 assertions: ADP-03 invariant guard
- `.planning/phases/04-adapters-verify-settings/RECONCILIATION.md` — Phase 4 aggregate v1↔v2 disposition index (per D-54, mirrors Phase 02.1)

## Decisions Made

- **Reflection without `setAccessible`** — PHP 8.5 deprecates
  `ReflectionMethod::setAccessible()` because PHP 8.1+ allows private-method
  invocation by default. The plan's example used `setAccessible(true)`; the
  actual implementation drops it (and adds a one-line comment explaining
  the deprecation).
- **Diff direction in SpotCheckUrlFetcherTest** — the source's `diff()`
  signature is `diff($current, $baseline)`. Lines unique to baseline get
  `-`, lines unique to current get `+`. Tests assert this orientation and
  spell it out in comments.
- **METADATA_IGNORE shape** — SnapshotDiffer's META_IGNORE applies only to
  keys nested under `meta`, not at top level. Test reshaped to put
  `generatedAt` / `gitSha` under a `meta` key, not at the root, matching
  the source contract.
- **CountGateService DB-path tests deferred** — `run()` calls
  `Entry::find()`, `Category::find()`, and `\craft\db\Query` against
  several tables; reliable invocation needs a Craft bootstrap that's
  prohibitively complex in unit context. The unit suite covers only the
  pure delta formula and the negative-sentinel branch; full DB coupling
  is Phase 5 / TST-02 territory.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Plan example used `ReflectionMethod::setAccessible(true)` — deprecated in PHP 8.5**
- **Found during:** Task 06 (AssetMigrationServiceRcaTest first run)
- **Issue:** PHP 8.5 emits a deprecation warning for `setAccessible()`
  because PHP 8.1+ already grants reflective access to private methods.
  First test run reported `Tests: 4, Deprecations: 1`.
- **Fix:** Dropped the `setAccessible(true)` call; added a one-line comment
  explaining the PHP 8.1+ default and the 8.5 deprecation.
- **Files modified:** tests/load/AssetMigrationServiceRcaTest.php
- **Verification:** Re-running the file shows `OK (4 tests, 10 assertions)`
  with zero deprecations from this test (the lone remaining deprecation
  is in vendor/craftcms/cms — pre-existing, scoped out).
- **Committed in:** `98dc616` (Task 06 commit)

**2. [Rule 1 - Bug] Plan example placed `generatedAt` / `gitSha` at top level for the META_IGNORE test**
- **Found during:** Task 01 (SnapshotDifferTest authoring; pre-test inspection of source)
- **Issue:** `SnapshotDiffer::diff()` only ignores those keys when nested
  under `meta` (lines 36-41 explicitly process `$baseline['meta']`). At
  top level, they would surface as a real diff and the assertion would fail.
- **Fix:** Reshaped the test fixture to nest both keys under `meta`,
  matching the source contract and the docstring.
- **Files modified:** tests/verify/SnapshotDifferTest.php
- **Verification:** All 4 SnapshotDifferTest tests pass.
- **Committed in:** `fdd87d6` (Task 01 commit)

**3. [Rule 2 - Missing critical] Plan example asserted `- <body>foo</body>` and `+ <body>bar</body>` with the diff direction swapped**
- **Found during:** Task 02 (SpotCheckUrlFetcherTest authoring; PHP one-liner probe before commit)
- **Issue:** The source's diff() emits `-` for baseline-only lines and `+`
  for current-only lines (lines 99-108). With `$current=foo` and
  `$baseline=bar`, the actual output is `- <body>bar</body>` and
  `+ <body>foo</body>`, NOT the plan's assertion order.
- **Fix:** Renamed the variables to `$current` and `$baseline` and
  documented the diff orientation in a docblock; assertions now match
  source semantics.
- **Files modified:** tests/verify/SpotCheckUrlFetcherTest.php
- **Verification:** All 4 SpotCheckUrlFetcherTest tests pass; B1 invariant
  (line-level, not byte-count) is asserted byte-for-byte.
- **Committed in:** `d9e7c1f` (Task 02 commit)

---

**Total deviations:** 3 auto-fixed (2 plan-example bugs in test fixtures,
1 PHP 8.5 deprecation cleanup).
**Impact on plan:** All three are characterization-test correctness fixes —
the v2 source files were correct; only the plan's illustrative test
fixtures had drifted from the source contract. No changes to production
files. No scope creep.

## Issues Encountered

None.

## Test Suite Growth

| Snapshot | Tests | Assertions |
|---|---|---|
| Pre-Plan-04-12 baseline | 60 | 137 |
| Post-Plan-04-12 | 83 | 210 |
| Delta | +23 | +73 |

The +23 test methods are split across 7 new test classes. The plan's
estimate was +17 — we shipped 6 extra tests because each new class added
1–2 invariants beyond the strict minimum (e.g. SpotCheckUrlFetcher's
cache-busting query-strip test, CountGateService's negative-sentinel test).

## Next Phase Readiness

- **Phase 4 is complete.** All four waves (Wave 1 settings + adapters,
  Wave 2 verify, Wave 3 doctor + migrate-controller extensions, Wave 4
  doctor 7th/8th checks + tests + reconciliation) have shipped.
- The plugin's pure-function test surface is now characterization-covered
  before Phase 5's full Craft-bootstrapped tests land.
- Phase 5 / TST-02 will cover the DB-coupled paths (CountGateService::run()
  against populated state, EntryMigrationService end-to-end, etc.) once
  a real Craft install is available.
- ROADMAP plan-progress: `completed_plans` advances 44 → 46 (Plan 11 was
  already counted; Plan 12 + the Phase 4 close mark adds 2 to reflect the
  correct phase-completion delta).
- `completed_phases` advances 4 → 5 only when Phase 5 ships; this plan
  closes Phase 4 itself, but the next phase ("Tests, Rehearsal & Release")
  has not started.

## Self-Check: PASSED

- `tests/verify/SnapshotDifferTest.php` — FOUND
- `tests/verify/SpotCheckUrlFetcherTest.php` — FOUND
- `tests/verify/CaptureBaselineHtmlServiceTest.php` — FOUND
- `tests/verify/CountGateServiceTest.php` — FOUND
- `tests/load/SeomaticPayloadBuilderTest.php` — FOUND
- `tests/load/AssetMigrationServiceRcaTest.php` — FOUND
- `tests/ComposerSuggestTest.php` — FOUND
- `.planning/phases/04-adapters-verify-settings/RECONCILIATION.md` — FOUND
- Commits `fdd87d6`, `d9e7c1f`, `be5a497`, `b50a092`, `9b8532e`, `98dc616`,
  `c23bb92`, `71b5001` — all FOUND in `git log`.
- `composer test` exits 0; 83 tests / 210 assertions; 1 deprecation in
  `vendor/craftcms/cms` (pre-existing, out of scope).

---
*Phase: 04-adapters-verify-settings*
*Completed: 2026-04-26*
