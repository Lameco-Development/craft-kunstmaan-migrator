---
phase: 05-tests-rehearsal-release
plan: 06
subsystem: testing
tags: [phpunit, unit-tests, field-handlers, tst-01, d-10, refactor-abstinence]

# Dependency graph
requires:
  - phase: 05-tests-rehearsal-release
    provides: composer test-coverage chained-script + per-module 70% gate (Plan 05-02), tests/unit/ tier (Plan 05-01)
  - phase: 03-etl-pipeline-field-handlers
    provides: 5 FieldHandler implementations under src/fields/handlers/ (PlainText, SplitName, Relation, Matrix, Asset) + ResolverContext + MigrationStateReader interface (D-11)
provides:
  - tests/unit/fields/handlers/PlainTextHandlerTest.php — 18 tests, all 4 modes
  - tests/unit/fields/handlers/SplitNameHandlerTest.php — 15 tests, pure split() + resolve()
  - tests/unit/fields/handlers/RelationHandlerTest.php — 16 tests, direct + joinTable + joinTranslation paths
  - tests/unit/fields/handlers/MatrixHandlerTest.php — 16 tests, generic + page-part paths
  - tests/unit/fields/handlers/AssetHandlerTest.php — 10 tests, relation + imgTag deferred-token paths
affects: [05-08-release-checklist-changelog-reconciliation]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "createStub(MigrationStateReader::class) over createMock — narrower interface (D-11) than full MigrationStateService component"
    - "Anonymous-class stand-in for AssetHandler::\$assetResolver (?object slot — FH-03 JIT lazy resolve), avoids coupling tests to AssetMigrationService construction"
    - "Generator-from-list helper for LegacyDbService::streamQuery returns (matches Generator<int, array<string, mixed>> shape)"
    - "ResolverContext built directly with createStub for each readonly service slot — value-object construction stays in-test, no factory abstraction added"
    - "Refactor abstinence honoured: zero src/ changes (git diff src/ empty)"

key-files:
  created:
    - tests/unit/fields/handlers/PlainTextHandlerTest.php
    - tests/unit/fields/handlers/SplitNameHandlerTest.php
    - tests/unit/fields/handlers/RelationHandlerTest.php
    - tests/unit/fields/handlers/MatrixHandlerTest.php
    - tests/unit/fields/handlers/AssetHandlerTest.php
  modified: []

key-decisions:
  - "Stubbed MigrationStateReader (interface, 3 methods) rather than MigrationStateService (full Yii Component). The plan's acceptance-criteria text mentioned createStub(MigrationStateService::class), but the actual handler dependency is the narrower read-only interface per D-11; stubbing the wider class would couple tests to write-side surface the handlers never touch. Kept the spirit (stub-not-mock for state lookups) and the letter of D-11 (handlers see MigrationStateReader, not MigrationStateService)."
  - "AssetHandler::\$assetResolver stubbed via anonymous class (PHPUnit's createStub can't easily target a duck-typed ?object slot). The handler's docblock pins the surface (`resolveFromLegacyId(int): int`), so the anonymous class implements just that one method."
  - "Coverage gate (composer test-coverage exit 0) NOT verified locally — pcov + xdebug are both absent in this environment (same baseline as Plan 05-02). The CI pipeline (Plan 05-07, shivammathur/setup-php with PCOV) is the first place the gate will be executed end-to-end. Test count + per-handler branch coverage by inspection (every public-API branch reachable via stubs is exercised at least once) is the surrogate signal until then."
  - "AssetHandler `as=imgTag` SUCCESS path (state hit → Craft\\elements\\Asset::findOne) NOT exercised — needs Craft bootstrap, deferred to integration tier per Plan 05-03 characterization fixtures. Coverage on the imgTag branch comes from the deferred-token miss path (state miss → '[M{id}]'), which is reachable without Craft."

# Metrics
metrics:
  duration: ~30min
  completed: 2026-04-27
---

# Phase 5 Plan 06: Unit Tests for Field Handlers Summary

**One-liner:** 5 dedicated unit-test files (75 tests, 189 assertions) covering all 5 field handlers via createStub on the narrow MigrationStateReader interface — closes TST-01 / D-10 with refactor-abstinence honoured (zero src/ changes).

## What was built

### 1. `tests/unit/fields/handlers/PlainTextHandlerTest.php` (18 tests)

Covers all 4 dispatch modes plus constructor validation:
- **`plain` mode** — scalar→string cast, null→`''`
- **`ckeditor` mode** — null/empty short-circuit; non-empty routed through `ResolverContext::$ck->rewrite($html, $siteId)` (asserted via mock-with-`expects`)
- **`link` mode** — null→null; email classification + `mailto:` prefix preservation; slash-prefixed path → entry-id resolution via state lookup; URL fallback when entry lookup misses; HTTP scheme passthrough
- **`dropdown` mode** — value-in-allowed-list returns string; unknown returns null by default; `onUnknown=throw` raises `RuntimeException`; missing `allowed` option throws; null cast to `''` looked up in allowed list (covers both directions)
- **ctor** — known modes accepted, `'plain'` default, unknown mode throws at construction

### 2. `tests/unit/fields/handlers/SplitNameHandlerTest.php` (15 tests)

Splits via the public `split()` helper (pure function, no I/O) plus `resolve()` options dispatch:
- Two-token / three-token (no infix) / single-token names
- Whitespace trim + inner-collapse
- Accented characters preserved (D-04 — CQM has NL diacritics)
- Leading academic prefix (`Dr.`)
- Single-token Dutch infix (`de`) + multi-token (`van der`)
- Trailing suffix (`Jr.`)
- Defensive fallback: infix-only tail (`Jan van`) promotes last infix to lastName so entry never saves with empty lastName
- `resolve()` returns requested `part`; null/empty input returns `''`; invalid `part` throws

### 3. `tests/unit/fields/handlers/RelationHandlerTest.php` (16 tests)

Three dispatch paths exercised in full:
- **Direct path** — scalar / array input; site-scoped lookup with site-agnostic fallback; unresolved-id silent drop; insertion-order-preserved dedup; null/empty/empty-array input; non-positive id filter
- **joinTable path** — `joinLocalColumn` / `joinForeignColumn` requirement; identifier whitelist (`/^[A-Za-z0-9_]+$/`) — SQL injection mitigation T-06-02-01; `queryAll` fan-out + per-foreign-id state lookup; **deferred `'asset:N'` token emission for `media` source + `kuma_media:` prefix misses** (Phase 10.1-08 reduction); missing-`legacyDb` guard; non-positive ref-id short-circuit (no DB call)
- **joinTranslation path** — option-shape validation; `queryOne` translation-table lookup → state resolution

### 4. `tests/unit/fields/handlers/MatrixHandlerTest.php` (16 tests)

Two dispatch paths:
- **Generic path (v1 verbatim)** — `legacyDb`-required guard; `itemTable` / `fkCol` / `blockType` requirement; `valueCol` OR `bodyCol` requirement; non-positive fk short-circuit (no `streamQuery` call); `streamQuery` fan-out → `'new1' / 'new2' / ...` block keying; **`bodyCol` routes through `$ctx->ck->rewrite`** (asserted via mock); blank `bodyCol` skips rewriter; `orderCols` builds multi-column ORDER BY; custom `handle` renames target field; empty stream returns `[]`
- **Page-part path (D-49)** — full tuple-option requirement (`pagePartClass` / `parentPageClass` / `context` / `targetMatrixField` / `targetBlockType` / `fields`); empty/null/empty-array input → `[]`; pre-resolved row hashes wrapped in `'new1' => ['type' => ..., 'enabled' => true, 'fields' => [...]]` shape; malformed-row guard

`Generator`-from-list helper covers `LegacyDbService::streamQuery`'s `Generator<int, array<string, mixed>>` return.

### 5. `tests/unit/fields/handlers/AssetHandlerTest.php` (10 tests)

Both consumer modes for the non-Craft-coupled branches:
- **Empty-input handling** — null / `''` / `0` / `'0'` for both `as=relation` (returns `[]`) and `as=imgTag` (returns `''`)
- **Relation-mode hit** — state lookup returns id → `[$id]`; custom `stateSource` + `keyFormat` honoured
- **Relation-mode miss → JIT lazy resolve via `$assetResolver`** — anonymous-class stand-in implementing `resolveFromLegacyId(int): int`; positive return wraps to `[$id]`, zero return falls back to deferred `'asset:N'` token
- **Relation-mode miss + no resolver → deferred `'asset:N'` token**
- **Non-default `keyFormat` skips JIT** (resolver's hardcoded `kuma_media:{id}` contract wouldn't match; defensive)
- **`as=imgTag` miss → bracket-form `[M{id}]` deferred token** (CKEditor inline-asset consumer at finalize time)

The `as=imgTag` SUCCESS path (state hit → `Asset::findOne`) is NOT covered — needs Craft bootstrap, deferred to integration tier (Plan 05-03 characterization fixtures).

## Per-handler test count

| Handler | Tests | Assertions (approx) |
|---|---|---|
| PlainText | 18 | ~50 |
| SplitName | 15 | ~28 |
| Relation | 16 | ~35 |
| Matrix | 16 | ~45 |
| Asset | 10 | ~31 |
| **Total** | **75** | **~189** |

Plan target was 27-42 — the actual figure is well above the floor because each branch (mode + edge-case) got its own one-assertion test for diagnostic clarity rather than parameterised batches.

## Coverage gate status

**Coverage NOT verified locally** — neither pcov nor xdebug is loaded in this environment (`php -r "echo extension_loaded('pcov').extension_loaded('xdebug');"` → `NONE`). This matches Plan 05-02's BASELINE-coverage limitation; the chained `composer test-coverage` script fails fast in step 1 with `install pcov or xdebug to run coverage`.

**End-to-end gate execution:** Plan 05-07 (`ci-smoke-job`) wires PCOV via `shivammathur/setup-php` — that's where the first real `composer test-coverage` exit-0 lands.

**Branch-by-inspection signal (surrogate):** Every non-Craft-coupled public-API branch in all 5 handlers is exercised by at least one stubbed test path:
- `PlainTextHandler` — all 4 mode branches + every internal `if` in `writeLink` + `writeDropdown` + ctor validation
- `SplitNameHandler` — every branch in `split()` (prefix/suffix/infix collection + tail fallback) + `resolve()` validation
- `RelationHandler` — all 3 dispatch paths + every option-validation branch + every fallback in each path
- `MatrixHandler` — both dispatch paths + every option-validation branch + both `valueCol` + `bodyCol` field-population branches in the generic path + the rewrite-skip-on-blank branch
- `AssetHandler` — both `as=relation` + `as=imgTag` empty-input branches; the JIT-resolve branch (with + without resolver, with + without custom keyFormat); the bracket-form deferred-token branch

The single uncovered branch is `AssetHandler` `as=imgTag` SUCCESS path (`Asset::findOne($id)` + `getUrl()` + `htmlspecialchars` HTML emission). Reaching it requires either a Craft bootstrap (out of scope for Phase 5 unit tier per D-21) or refactoring `Asset::findOne` behind a seam (out of scope per refactor-abstinence rule).

**Realistic coverage projection (when CI runs the gate):** all 5 handlers should clear the 70% line-coverage gate; AssetHandler is the closest to the floor because the imgTag-success branch is uncovered. If it fails, that's a documented Phase 5.1 / NEXT input, not a gap to backfill in this plan.

## Combined Phase 5 corpus delta

| Stage | Total tests |
|---|---|
| Pre-Plan-05-06 baseline (`composer test-unit` after Plan 05-05 sibling, Wave 2 baseline) | 193 |
| Plan 05-06 corpus (new handler tests) | +75 |
| Post-Plan-05-06 (`composer test-unit`) | **268** |
| Full corpus (`composer test`, includes integration tier) | 272 |

Plan 05-05 runs in parallel in Wave 3; combined Wave 3 delta will be reported in 05-08 RECONCILIATION.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] RelationHandler test mis-modeled site-agnostic fallback**

- **Found during:** Task 2, first `composer test-unit` run.
- **Issue:** `testDirectPathFiltersOutNonPositiveIds` expected `getTargetId` to be called twice (site-scoped + site-agnostic fallback) for the single positive id. PHPUnit reported "expected 2 calls, actually 1 call".
- **Diagnosis:** Reading `RelationHandler::resolveDirect()` more carefully — the fallback only fires when the site-scoped call returns `null`. The test's stub returned `555` for the site-scoped call, so the fallback was correctly skipped. Test expectation was wrong, not handler behavior.
- **Fix:** Changed `expects($this->exactly(2))` to `expects($this->once())` and asserted the single call was site-scoped (`siteId === 1`).
- **Files modified:** `tests/unit/fields/handlers/RelationHandlerTest.php` (single test method)
- **Commit:** `21ff648` (fold-in — caught before commit)

### Rule 4 / Architectural deviations

None.

### Auth gates

None.

## Refactor abstinence confirmation

`git diff src/` empty after both task commits:
```
$ git diff --stat src/
$
```
Zero source-code changes. Only `tests/unit/fields/handlers/` directory and its 5 new files added. The plan's binding rule held.

## TST-01 closure status

**Ready for 05-08 RECONCILIATION** — all 5 dedicated handler test files exist and pass the unit suite. Plan 05-05 contributes the remaining 2 D-10 files (HeuristicProposer + CkeditorRewriter); when both Wave 3 plans land on `main`, every D-10 test artefact named in TST-01 exists.

The per-module 70% gate is best-effort verifiable in this branch (no coverage driver locally); the first authoritative gate run is in CI via Plan 05-07. If a handler comes in below 70% there:
- AssetHandler is the most-likely candidate (imgTag-success branch uncovered without Craft)
- The remediation path is a Phase 5.1 / NEXT-XX input — `files_modified` does NOT extend into `src/` from this plan

## Self-Check

**Files exist:**
- FOUND: `tests/unit/fields/handlers/PlainTextHandlerTest.php`
- FOUND: `tests/unit/fields/handlers/SplitNameHandlerTest.php`
- FOUND: `tests/unit/fields/handlers/RelationHandlerTest.php`
- FOUND: `tests/unit/fields/handlers/MatrixHandlerTest.php`
- FOUND: `tests/unit/fields/handlers/AssetHandlerTest.php`

**Commits exist:**
- FOUND: `35724f8` (Task 1 — test(05-06): add PlainText + SplitName handler unit tests)
- FOUND: `21ff648` (Task 2 — test(05-06): add Relation + Matrix + Asset handler unit tests)

**Test-suite contracts:**
- FOUND: `composer test-unit -- --filter "(PlainText|SplitName|Relation|Matrix|Asset)HandlerTest"` exits 0 (75/75 pass)
- FOUND: `composer test-unit` exits 0 (268/268 pass — +75 over Wave 2 baseline of 193)
- FOUND: `composer test` exits 0 (272/272 pass)
- FOUND: `git diff src/` empty (refactor abstinence)

## Self-Check: PASSED
