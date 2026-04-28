---
phase: 05-tests-rehearsal-release
plan: 03
subsystem: testing
tags: [phpunit, characterization, golden-files, transform, fixtures, json-canonicalize]

# Dependency graph
requires:
  - phase: 05-tests-rehearsal-release
    provides: tests/integration/ tier (Plan 05-01) — empty-shell directory ready to host the first integration test
  - phase: 03-etl-pipeline-field-handlers
    provides: TransformService (yields per-row payloads via run()), ExtractService (writes per-node JSON files under @storage/migration/extracted/), FieldHandlerRegistry + 5 field handlers
  - phase: 04.1-polish-recovery-and-env-defaults
    provides: MigrationFilters readonly value object (5-arg constructor — no fromCli factory)
provides:
  - tools/capture-transform-fixtures.php — operator one-time script that drives ExtractService::run() and copies per-node JSON files into the fixture tree, plus snapshots the active mapping.yaml alongside
  - tests/integration/transform/TransformCharacterizationTest.php — TST-02 deliverable; @dataProvider drives every fixture, JSON-canonicalize-then-diff comparator, UPDATE_SNAPSHOTS=1 refresh
  - tests/fixtures/transform/{input,golden}/ directory shape with .gitkeep markers (corpus operator-fills separately)
affects: [05-04-rehearsal-controller-and-dirs, 05-08-release-checklist-changelog-reconciliation]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Characterization tests via @dataProvider over per-row JSON fixtures + canonicalized golden diff (TST-02 pattern)"
    - "UPDATE_SNAPSHOTS=1 env-var refresh with markTestSkipped on first capture; missing goldens fail loudly otherwise"
    - "Recursive ksort comparator that branches on array_is_list to preserve list ordering (matrix blocks / asset arrays) while ksort applies only to associative arrays"
    - "Operator capture script bootstraps Craft via CRAFT_BOOTSTRAP env, snapshots both the per-row inputs AND the active mapping.yaml so the test exercises real handler dispatch (not the empty-mapping short-circuit)"
    - "TransformService instantiated directly with stub field-handler registry; legacyDb / migrationState / assetPathResolver left null (TransformService tolerates absent slots)"

key-files:
  created:
    - tools/capture-transform-fixtures.php
    - tests/integration/transform/TransformCharacterizationTest.php
    - tests/fixtures/transform/input/.gitkeep
    - tests/fixtures/transform/golden/.gitkeep

key-decisions:
  - "Confirmed actual TransformService public surface is `run(iterable, array $mapping, MigrationFilters, array $options): iterable` — NOT the placeholder `transformRow($row)` from the plan template. Test wraps each input fixture in a one-element iterable, drives run(), collects yielded payloads, drops the trailing __report sentinel."
  - "Confirmed actual ExtractService public surface is `run(array $mapping, MigrationFilters, array $options): iterable` writing per-node JSON files to @storage/migration/extracted/<fqcnSlug>/<id>.json — NOT one of the plan template's hypothesised extractRowsByEntity / extractAll / forEntity methods. Capture script consumes the on-disk side-effect rather than re-querying the legacy DB."
  - "Confirmed MigrationFilters has NO fromCli factory method — it is a 5-arg readonly value object (entities, locales, since, noSeo, noRetour). Capture script + test both use the constructor directly."
  - "Mapping snapshot persisted as a one-shot tests/fixtures/transform/mapping.json carrier (vs per-fixture _mapping injection). Atomically committed alongside the per-row input tree by the capture script."
  - "TransformService constructor stub: zero-arg new TransformService() + property-set handlerRegistry (fully wired with all 5 production handlers) + ckeditorRewriter; everything else null. Mirrors Plugin::init() handler registration so any handler dispatch reached via mapping rules resolves to a real handler."

patterns-established:
  - "Wave 2 plan (depends_on=[05-01] only) ships the test file + capture script while staying compatible with the still-pending 05-02 phpunit.xml.dist Integration suite + composer test-integration script. Verification falls back to direct vendor/bin/phpunit invocation for the integration tier."
  - "Capture script writes both fixture corpus + mapping snapshot atomically. The test enforces this: when mapping.json is absent the data provider yields nothing, preventing accidental empty-mapping golden generation."

requirements-completed: [TST-02]

# Metrics
duration: ~25 min
completed: 2026-04-27
---

# Phase 05 Plan 03: Transform Characterization Fixtures Summary

**TST-02 infrastructure shipped: characterization test + JSON-canonicalize comparator + UPDATE_SNAPSHOTS refresh + operator capture script that snapshots both per-row inputs and the active mapping.yaml so goldens characterise real TransformService handler dispatch (not the empty-mapping warning short-circuit).**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-27T08:20:00Z (approximate)
- **Completed:** 2026-04-27T08:45:52Z
- **Tasks:** 2 (plan) + 1 deviation fix
- **Files modified:** 4 created (capture script, test class, 2 .gitkeep markers)

## Accomplishments

- **tools/capture-transform-fixtures.php (180 lines).** Bootstraps Craft via `CRAFT_BOOTSTRAP` env, loads mapping.yaml via `Plugin::getInstance()->mappingFile->loadOrCreate()`, drives `ExtractService::run()` against a `MigrationFilters` scoped to the configured `TARGET_ENTITIES` allow-list. Then walks `@storage/migration/extracted/<fqcnSlug>/` and copies up to `PER_ENTITY_CAP=3` per-node JSON files into `tests/fixtures/transform/input/<EntitySimpleName>/<id>.json`. Also snapshots the active mapping to `tests/fixtures/transform/mapping.json`. Re-encodes everything through `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` so captures are byte-stable across PHP builds.
- **tests/integration/transform/TransformCharacterizationTest.php (203 lines).** `@dataProvider fixtureProvider` discovers every `tests/fixtures/transform/input/*/*.json`. Per fixture, the test loads the row + the mapping snapshot, drives `TransformService::run([$row], $mapping, new MigrationFilters(), [])`, collects yielded payloads (dropping the `__report` sentinel), and canonicalizes-then-diffs against the golden. `UPDATE_SNAPSHOTS=1` rewrites missing or differing goldens in place with `markTestSkipped`; without it, missing goldens fail loudly with the env-var instruction.
- **tests/fixtures/transform/{input,golden}/.gitkeep** ship the empty corpus shape so the operator's capture commit doesn't need to also create the directories.
- **No source code changed.** `git diff src/` is empty after every commit — the test interrogates Phase 3's TransformService surface as it stands.
- **No regression.** `composer test` (Unit suite, 176 tests / 485 assertions) still passes after every commit. The new integration test isn't yet wired into a phpunit.xml.dist testsuite (05-02, parallel in Wave 2, owns that wiring); direct invocation via `vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php` exits 0 with "no tests executed" (data provider empty until operator captures fixtures).

## Task Commits

Each task was committed atomically:

1. **Task 1: tools/capture-transform-fixtures.php** — `325f627` (feat)
2. **Task 2: tests/integration/transform/TransformCharacterizationTest.php + fixture .gitkeeps** — `d78a846` (feat)
3. **Deviation fix: persist mapping snapshot alongside input fixtures** — `59d3b49` (fix, Rule 1)

## Files Created/Modified

- `tools/capture-transform-fixtures.php` — operator one-time script. Reads ExtractService output, copies per-row JSON to fixture tree, snapshots mapping.yaml. NOT wired into composer scripts; CI never invokes it.
- `tests/integration/transform/TransformCharacterizationTest.php` — TST-02 deliverable. @dataProvider over fixtures, canonicalize-then-diff comparator, UPDATE_SNAPSHOTS=1 refresh.
- `tests/fixtures/transform/input/.gitkeep` — empty input directory marker.
- `tests/fixtures/transform/golden/.gitkeep` — empty golden directory marker.

## Decisions Made

- **TransformService surface adaptation.** Plan template assumed `transformRow($row): array`; actual surface is `run(iterable, array, MigrationFilters, array): iterable`. Adapted the test to wrap single-row inputs in one-element iterables, collect yielded payloads, drop the `__report` sentinel.
- **ExtractService surface adaptation.** Plan acceptance criteria checked for `extractRowsByEntity|extractAll|forEntity`; the actual surface is just `run(array $mapping, MigrationFilters, array $options): iterable` writing to disk under `@storage/migration/extracted/<fqcnSlug>/<id>.json`. The capture script consumes that on-disk side-effect (no need to re-query the legacy DB).
- **MigrationFilters constructor (no fromCli).** Plan template referenced `MigrationFilters::fromCli($entities, [], null, false, false)`; the actual VO has only a 5-arg readonly constructor. Both capture script and test use named-arg constructor calls.
- **Mapping snapshot carrier (mapping.json).** Without a mapping snapshot, every fixture would short-circuit at TransformService's `No nodeClasses mapping for {fqcn}` warning and goldens would degenerate to empty-array stubs. Capture script writes one `tests/fixtures/transform/mapping.json` per repo (atomically committed alongside the corpus); test reads it via private `loadMapping()` helper. When snapshot absent, test stays in the on-ship empty-corpus risky-no-tests state.
- **Stub registry mirrors Plugin::init().** TransformService's `createService()` helper wires the same 5 field handlers (4 PlainTextHandler modes + Asset/Relation/Matrix/SplitName) that production registers, so handler dispatch is faithful to the runtime path.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Empty mapping path produces useless goldens**
- **Found during:** Post-Task 2 advisor review (before SUMMARY)
- **Issue:** Test passed an empty `$mapping` to `TransformService::run()`, which causes every input fixture to short-circuit at the `No nodeClasses mapping for {fqcn}` warning + skip the foreach body, yielding only the `__report` sentinel. Result: every golden would be `"[]\n"` regardless of input, defeating TST-02's stated regression-signal goal entirely.
- **Fix:** Capture script now writes the active mapping to `tests/fixtures/transform/mapping.json` after `mappingFile->loadOrCreate()` and before per-row capture. Test gains a private `loadMapping()` helper that reads the snapshot if present (returns `[]` when absent so the empty-corpus on-ship state is unchanged).
- **Files modified:** `tools/capture-transform-fixtures.php`, `tests/integration/transform/TransformCharacterizationTest.php`
- **Verification:** `composer test` still passes (176 tests, no regression); `php -l` clean on both files; direct phpunit invocation against the integration test exits 0 ("no tests executed" — data provider yields nothing until operator runs capture).
- **Committed in:** `59d3b49` (separate atomic deviation fix commit)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Without this fix, the plan's stated goal — "any change to TransformService that alters output shows up as a fixture-diff in the same PR" — would not hold once operator captures fixtures. The `_mapping` per-fixture key approach mentioned briefly in the plan template was rejected in favour of a one-shot mapping.json carrier so input fixtures match Phase 3's ExtractService output byte-for-byte (no synthetic key injection that pollutes the captured-row shape).

## Issues Encountered

- **`composer test-integration` doesn't exist yet.** This script is added by 05-02 (parallel in Wave 2). The plan's verification commands (`composer test-integration 2>&1 | tail -10`) couldn't be run in this worktree. Verified via direct `vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php` instead — exits 0 with "no tests executed", confirming the test class autoloads, the data provider returns empty, and PHPUnit's empty-data-provider categorisation is non-fatal under the current `phpunit.xml.dist` (no `failOnRisky`).
- **PHPUnit 11.5 categorises empty data provider as a "PHPUnit error".** Reported with severity but exit code remains 0. When 05-02 lands `composer test-integration`, it should NOT enable `failOnRisky` / `--fail-on-empty-test-suite` until operator captures fixtures, OR the test should grow a sentinel always-passing assertion to avoid the empty-class warning. Flagged for 05-02's reviewer.
- **Plan acceptance criterion `grep -E "(extractRowsByEntity|extractAll|forEntity)"` doesn't match.** The actual ExtractService surface is `run()`, not any of the three guesses. Documented in Decisions above; the capture script's call site (`$plugin->extractService->run($mapping, $filters, [])`) is correct against the live Phase 3 code.

## User Setup Required

None - no external service configuration required. The next operator action (capturing the fixture corpus) is documented in:

- The capture script's header comment block
- The `tests/integration/transform/TransformCharacterizationTest.php` class docblock
- Phase 5 / Plan 05-08 RELEASE-CHECKLIST (downstream — captures-committed is a v1.0 ship gate)

**Operator-side note (for the next operator who picks up this plan):**
Run `tools/capture-transform-fixtures.php` on a dev host with `KUNSTMAAN_SOURCE_PATH` + `CRAFT_LEGACY_DB_*` configured against `~/Sites/cqm-website/`, then `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php` to seed goldens. Commit input/ + golden/ + mapping.json together as a single fixture-corpus commit.

## Next Phase Readiness

- TST-02 infrastructure complete; downstream Phase 5 work unblocked.
- 05-02 (parallel in Wave 2) will wire the Integration testsuite into `phpunit.xml.dist` and add `composer test-integration` script. Once that lands, the new test joins the integration tier automatically (no further changes here required).
- 05-08 RELEASE-CHECKLIST should reference "Operator has captured CQM fixtures + goldens committed" as a v1.0 ship-gate signal.
- composer test totals: unchanged at 176/485 (no real fixtures yet; data-provider-empty risky test counts as 0 added, exactly as the plan predicted).

## Self-Check

Verifying claimed files + commits exist on disk and in git history:

- `tools/capture-transform-fixtures.php` → FOUND
- `tests/integration/transform/TransformCharacterizationTest.php` → FOUND
- `tests/fixtures/transform/input/.gitkeep` → FOUND
- `tests/fixtures/transform/golden/.gitkeep` → FOUND
- Commit `325f627` (Task 1 capture script) → FOUND
- Commit `d78a846` (Task 2 test + .gitkeeps) → FOUND
- Commit `59d3b49` (Rule 1 mapping snapshot fix) → FOUND
- `git diff src/` → empty (no source changes)
- `composer test` → 176 tests / 485 assertions, exit 0
- `php -l tools/capture-transform-fixtures.php` → No syntax errors
- `php -l tests/integration/transform/TransformCharacterizationTest.php` → No syntax errors

## Self-Check: PASSED

---
*Phase: 05-tests-rehearsal-release*
*Plan: 03*
*Completed: 2026-04-27*
