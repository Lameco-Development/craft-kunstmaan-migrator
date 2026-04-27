---
phase: 05
plan: 06
type: tdd
wave: 3
depends_on: ["05-02"]
files_modified:
  - tests/unit/fields/handlers/PlainTextHandlerTest.php
  - tests/unit/fields/handlers/SplitNameHandlerTest.php
  - tests/unit/fields/handlers/RelationHandlerTest.php
  - tests/unit/fields/handlers/MatrixHandlerTest.php
  - tests/unit/fields/handlers/AssetHandlerTest.php
autonomous: true
requirements: [TST-01]
must_haves:
  truths:
    - "Five new unit-test files cover every handler under src/fields/handlers/ — one dedicated test file per handler (D-10 list)"
    - "PlainTextHandlerTest covers the 4 modes: plain / ckeditor / link / dropdown"
    - "SplitNameHandlerTest covers first-name + last-name split logic and edge cases (empty, single-token, multi-token names)"
    - "RelationHandlerTest covers legacy-id resolution + deferred-token emission for unresolved refs (uses createStub for MigrationStateService)"
    - "MatrixHandlerTest covers block-translation per matrix-block type + ordering preservation"
    - "AssetHandlerTest covers legacy-id → asset-ref-token rewrite + deferred token for unresolved (uses createStub for MigrationStateService)"
    - "Aggregate per-module 70% line coverage on every file under src/fields/handlers/ — verified via tools/check-coverage.php (D-08 directory prefix auto-enroll)"
    - "Tests follow the established pure-PHPUnit shell (final class, declare strict_types=1, namespace tests\\unit\\fields\\handlers); use createStub() over full mocks for external deps"
    - "BASELINE coverage from 05-02 SUMMARY drives test prioritization — handlers below 70% get more tests than handlers already above"
    - "Zero source-code refactors (refactor abstinence — same rule as 05-05)"
  artifacts:
    - path: "tests/unit/fields/handlers/PlainTextHandlerTest.php"
      provides: "4-mode coverage + null/empty input handling; ~6-10 tests"
      contains: "final class PlainTextHandlerTest"
    - path: "tests/unit/fields/handlers/SplitNameHandlerTest.php"
      provides: "Name-splitting logic; ~4-6 tests"
      contains: "final class SplitNameHandlerTest"
    - path: "tests/unit/fields/handlers/RelationHandlerTest.php"
      provides: "Legacy-id lookup + deferred token emission; ~6-8 tests"
      contains: "final class RelationHandlerTest"
    - path: "tests/unit/fields/handlers/MatrixHandlerTest.php"
      provides: "Per-block translation + ordering; ~6-10 tests"
      contains: "final class MatrixHandlerTest"
    - path: "tests/unit/fields/handlers/AssetHandlerTest.php"
      provides: "Legacy asset-id resolution + deferred-asset emission; ~5-8 tests"
      contains: "final class AssetHandlerTest"
  key_links:
    - from: "tests/unit/fields/handlers/*.php"
      to: "src/fields/handlers/{PlainText,SplitName,Relation,Matrix,Asset}Handler.php"
      via: "direct instantiation OR createStub of injected services"
      pattern: "new \\w+Handler"
    - from: "composer test-coverage"
      to: "tools/check-coverage.php (HANDLERS_PREFIX directory auto-enroll)"
      via: "exit 0 = all 5 handlers ≥ 70% line coverage"
      pattern: "src/fields/handlers/"
---

<objective>
**TST-01 / D-10 — Direct unit tests for the 5 field handlers, one dedicated test file per handler.**

The five handlers under `src/fields/handlers/` are explicitly named in TST-01 + D-08 + D-10. They auto-enroll into `tools/check-coverage.php` via the `src/fields/handlers/` directory prefix (Plan 05-02 Task 3). Each handler gets its own test file because their concerns are distinct enough that a combined file would dilute test naming and obscure which mode/path is exercised.

**Per handler — what each test file covers:**

| Handler | Concern | Test count target | Reflection needed? |
|---|---|---|---|
| `PlainTextHandler` | 4 modes: plain / ckeditor / link / dropdown; null + empty inputs | 6-10 | No — pure switch on mode |
| `SplitNameHandler` | First/last name split; whitespace, single-token, multi-token, accented | 4-6 | No — pure string |
| `RelationHandler` | Legacy entry-id → Craft entry-id resolve; deferred-token emit on miss | 6-8 | createStub MigrationStateService |
| `MatrixHandler` | Block-type translation; ordering preservation; nested block groups | 6-10 | createStub if internal lookup needed |
| `AssetHandler` | Legacy media-id → Craft asset resolve; deferred token on miss | 5-8 | createStub MigrationStateService |

**Total target:** ~27-42 tests across 5 files.

This plan is the parallel sibling of 05-05 in Wave 3. Both depend only on 05-02 (composer test-coverage available). No file overlap with 05-05.

**Refactor abstinence (same rule as 05-05):** zero source-code changes. If a handler's surface is genuinely untestable in isolation (e.g. requires Craft container for asset queries), document the limitation in SUMMARY and accept the resulting coverage. The 70% gate is binding; if it fails on one specific handler with documented rationale, that's a Phase 5.1 / NEXT input — but `files_modified` does NOT extend into src/.

**BASELINE-driven prioritization:** read 05-02 SUMMARY (and 05-05 SUMMARY's hand-off note about which handlers need Reflection) before allocating test count. Handlers already at 80%+ from existing transitive coverage (e.g. via the load-stage tests that integration-cover them) need fewer dedicated tests; handlers at 30% need maximum coverage.

Output:
- 5 new test files (~27-42 tests)
- `composer test-coverage` reports every file under `src/fields/handlers/` ≥ 70% line coverage
- `composer test-unit` corpus grows by ~27-42
- TST-01 D-08 closure (combined with 05-05): all 5 modules + every handler at the 70% gate
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/05-tests-rehearsal-release/05-CONTEXT.md
@.planning/phases/05-tests-rehearsal-release/05-PATTERNS.md
@.planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md
@CLAUDE.md

<interfaces>
Each handler is in `src/fields/handlers/` and likely implements a common interface (probably `FieldHandlerInterface` or extends a `BaseFieldHandler`). The exact interface shape is verified in the execute step. Common public method: `resolve(mixed $value, ResolverContext $ctx): mixed` (PATTERNS line 357 hint), but this MUST be confirmed against current code.

Handler-specific dependencies (verified at execute time):
- `PlainTextHandler` — likely no DI (pure mode-switch); test directly with `new PlainTextHandler('plain')` etc.
- `SplitNameHandler` — likely no DI; pure string split.
- `RelationHandler` — likely takes `MigrationStateService` for legacy-id lookup; createStub.
- `MatrixHandler` — may take a registry of block-type handlers; createStub or build minimal real registry.
- `AssetHandler` — likely takes `MigrationStateService` + possibly `AssetMigrationService`; createStub for read-only test paths.

`ResolverContext` (probably `lameco\kunstmaanmigrator\fields\ResolverContext`) — value object; constructable directly with whatever fields it carries (entity type, locale, parent entry id, etc.). Read the file.

PATTERNS analog table (lines 339-346):
| Handler | Test analog |
|---|---|
| PlainTextHandler | tests/unit/locale/LocalePreflightTest.php (multiple input/output cases) |
| SplitNameHandler | same as PlainText |
| RelationHandler | tests/unit/load/MigrationStateServiceTerminalStateTest.php (mock-state pattern) |
| MatrixHandler | tests/unit/console/MigrateControllerSyncAssetsTest.php (helper-extract via Reflection) |
| AssetHandler | tests/unit/load/MigrationStateServiceTerminalStateTest.php |
</interfaces>

<reference_files>
- src/fields/handlers/PlainTextHandler.php — class under test (read in execute)
- src/fields/handlers/SplitNameHandler.php — class under test
- src/fields/handlers/RelationHandler.php — class under test
- src/fields/handlers/MatrixHandler.php — class under test
- src/fields/handlers/AssetHandler.php — class under test
- src/fields/ResolverContext.php (or wherever the context value object lives) — argument to handler resolve()
- tests/unit/locale/LocalePreflightTest.php (post-05-01 path) — pure-PHPUnit class shell analog
- tests/unit/load/MigrationStateServiceTerminalStateTest.php — mock-state-via-stub analog
- .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: handler tests section, lines 334-373; per-handler analog table at line 339)
- .planning/phases/05-tests-rehearsal-release/05-05-SUMMARY.md (hand-off note from 05-05 — which handlers most likely need Reflection)
</reference_files>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: PlainTextHandler + SplitNameHandler tests (pure, no DI)</name>
  <files>
    tests/unit/fields/handlers/PlainTextHandlerTest.php,
    tests/unit/fields/handlers/SplitNameHandlerTest.php
  </files>
  <read_first>
    - src/fields/handlers/PlainTextHandler.php (whole file — confirm constructor signature; identify the 4 modes; confirm public method name + return type)
    - src/fields/handlers/SplitNameHandler.php (whole file — confirm split logic + return shape)
    - src/fields/ResolverContext.php (or wherever the context value object lives — confirm public constructor surface for direct instantiation in tests)
    - tests/unit/locale/LocalePreflightTest.php (post-05-01 path — pure-PHPUnit class shell; multiple-cases-per-mode style)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: handler tests section, lines 334-373)
    - .planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md (baseline coverage on PlainTextHandler.php and SplitNameHandler.php — drives test count allocation)
  </read_first>
  <behavior>
    **`PlainTextHandlerTest.php`** — target 6-10 tests, one or two per mode:
    - testPlainModeCastsScalarToString — `new PlainTextHandler('plain')->resolve('hello', $ctx)` returns 'hello'; null returns ''.
    - testPlainModeStripsHtml (if applicable) — input `<b>foo</b>` → output `foo` (only if PlainTextHandler strips).
    - testCkeditorModePassesThroughHtml — `'ckeditor'` mode preserves HTML markup verbatim.
    - testLinkModeWrapsInAnchor — `'link'` mode wraps the value (or returns a structured array — confirm against actual return shape).
    - testDropdownModeMapsValueToOption — `'dropdown'` mode looks up the option label/value; verify mapping behavior.
    - testNullValueReturnsEmptyOrConfiguredDefault — every mode handles null without exception.
    - testEmptyStringReturnsEmpty — every mode handles `''` predictably.

    **`SplitNameHandlerTest.php`** — target 4-6 tests:
    - testSplitsTwoTokenName — `'John Smith'` → `['firstName' => 'John', 'lastName' => 'Smith']`.
    - testSplitsThreeTokenName — `'Mary Jane Watson'` → first='Mary', last='Jane Watson' (or `'Mary Jane'` + `'Watson'` — confirm the rule).
    - testSingleTokenIsFirstName — `'Madonna'` → first='Madonna', last=''.
    - testEmptyInputReturnsBlankPair — `''` → first='', last=''.
    - testTrimsLeadingAndTrailingWhitespace — `'  John   Smith  '` → first='John', last='Smith'.
    - testHandlesAccentedCharacters — `'Renée Müller'` → preserves diacritics in both halves (D-04 — CQM has NL diacritics; test catches utf-8 mishaps).
  </behavior>
  <action>
    **1a. Write `tests/unit/fields/handlers/PlainTextHandlerTest.php`:**

    First create the directory (does not exist post-05-01):
    ```bash
    mkdir -p tests/unit/fields/handlers
    ```

    Then write the test, confirming the actual signature of `PlainTextHandler::__construct` and `resolve()` first by reading the source. Class shell:

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

    use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
    use lameco\kunstmaanmigrator\fields\ResolverContext;
    use PHPUnit\Framework\TestCase;

    /**
     * Phase 5 / TST-01 / D-10 — direct unit tests for PlainTextHandler.
     *
     * 4 modes: plain / ckeditor / link / dropdown. Pure mode-switch; no DI.
     * Coverage target: ≥ 70.0% line coverage on src/fields/handlers/PlainTextHandler.php
     * (D-08 directory prefix gate via tools/check-coverage.php).
     */
    final class PlainTextHandlerTest extends TestCase
    {
        private function ctx(): ResolverContext
        {
            // Build minimal ResolverContext per its constructor signature — verified at execute time.
            // If ResolverContext is a value object with all-required fields, supply minimal stubs.
            return new ResolverContext(/* args per actual ctor */);
        }

        // 6-10 test methods per <behavior>; one mode-and-edge-case per method.
    }
    ```

    The exact `ResolverContext` constructor args are verified by reading `src/fields/ResolverContext.php`. If the context constructor has many required fields and they're all just data carriers (entity name, locale code, etc.), instantiate with minimal real values; if it has DI-coupled fields (e.g. takes a Site or Element instance), that's a refactor signal — skip those tests rather than mocking deeply.

    **1b. Write `tests/unit/fields/handlers/SplitNameHandlerTest.php`** following the same shell, with the 4-6 tests from `<behavior>`.

    **1c. Run targeted suite:**
    ```bash
    composer test-unit -- --filter "PlainTextHandlerTest|SplitNameHandlerTest"
    ```
    Must exit 0; both files' tests pass.

    **1d. Verify coverage on these two handlers:**
    ```bash
    composer test-coverage 2>&1 | grep -E "(PlainTextHandler|SplitNameHandler)\.php"
    ```
    Both should report `OK` ≥ 70.0%. If short, add tests for uncovered code paths from the clover XML.

    Refactor abstinence: do NOT modify any handler source file. If a private helper would make a path testable, leave it private; coverage on that path comes from integration-tier (Plan 05-03 characterization fixtures).
  </action>
  <verify>
    <automated>composer test-unit -- --filter "PlainTextHandlerTest|SplitNameHandlerTest" 2>&amp;1 | tail -3 | grep -E "OK \(|Tests:"</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tests/unit/fields/handlers/PlainTextHandlerTest.php` returns 0
    - `test -f tests/unit/fields/handlers/SplitNameHandlerTest.php` returns 0
    - `php -l tests/unit/fields/handlers/PlainTextHandlerTest.php` exits 0
    - `php -l tests/unit/fields/handlers/SplitNameHandlerTest.php` exits 0
    - `grep -c "final class PlainTextHandlerTest" tests/unit/fields/handlers/PlainTextHandlerTest.php` returns 1
    - `grep -c "final class SplitNameHandlerTest" tests/unit/fields/handlers/SplitNameHandlerTest.php` returns 1
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit\\\\fields\\\\handlers" tests/unit/fields/handlers/PlainTextHandlerTest.php` returns 1
    - `grep -c "public function test" tests/unit/fields/handlers/PlainTextHandlerTest.php` returns at least 6
    - `grep -c "public function test" tests/unit/fields/handlers/SplitNameHandlerTest.php` returns at least 4
    - `composer test-unit -- --filter "PlainTextHandlerTest|SplitNameHandlerTest"` exits 0
    - If pcov/xdebug available: `composer test-coverage 2>&1 | grep "PlainTextHandler\.php"` shows OK ≥ 70.0%
    - If pcov/xdebug available: `composer test-coverage 2>&1 | grep "SplitNameHandler\.php"` shows OK ≥ 70.0%
    - `git diff src/` empty (refactor abstinence)
  </acceptance_criteria>
  <done>Two of the five handlers covered. Three remaining (Relation, Matrix, Asset) — all need stub-injected MigrationStateService. Task 2.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: RelationHandler + MatrixHandler + AssetHandler tests (createStub for state service)</name>
  <files>
    tests/unit/fields/handlers/RelationHandlerTest.php,
    tests/unit/fields/handlers/MatrixHandlerTest.php,
    tests/unit/fields/handlers/AssetHandlerTest.php
  </files>
  <read_first>
    - src/fields/handlers/RelationHandler.php (whole file — confirm injected services; identify deferred-token emission path; identify legacy-id resolution path)
    - src/fields/handlers/MatrixHandler.php (whole file — confirm block-translation surface; identify per-block-type dispatch; identify ordering logic)
    - src/fields/handlers/AssetHandler.php (whole file — confirm legacy media-id resolution + deferred-token path; mirrors RelationHandler pattern)
    - src/load/MigrationStateService.php (verify the public methods the handlers consume — `get()`, `record()`, etc. — these are what `createStub()` mocks)
    - tests/unit/load/MigrationStateServiceTerminalStateTest.php (post-05-01 path — `createStub(MigrationStateService::class)` analog from Phase 4.1)
    - tests/unit/fields/handlers/PlainTextHandlerTest.php (just-written — class shell to mirror)
    - .planning/phases/05-tests-rehearsal-release/05-05-SUMMARY.md (hand-off: which handlers Plan 05-05 expected need Reflection vs direct construction)
  </read_first>
  <behavior>
    **`RelationHandlerTest.php`** — target 6-8 tests:
    - testResolvesKnownLegacyEntryIdToCraftEntryRef — stub returns a known migrated entry id; handler returns the Craft entry-ref shape.
    - testEmitsDeferredTokenForUnresolvedLegacyId — stub returns null; handler returns deferred token (e.g. `[NT<legacyId>]`) so finalize-stage rewrites later.
    - testHandlesNullInput — null → null or empty result, no exception.
    - testHandlesArrayOfLegacyIds — input `[1, 2, 3]` → array of resolved (or deferred) refs preserving order.
    - testNonScalarInputThrowsOrReturnsNull — invalid input shape handled per the actual contract.
    - testCrossLocaleResolutionUsesCorrectSiteId — siteId from ResolverContext flows through to the stub call.
    - testSelfReferencingEntryProducesDeferredToken — entry referring to itself by legacy id triggers deferred path (cycle handling).

    **`MatrixHandlerTest.php`** — target 6-10 tests:
    - testSingleBlockTranslates — input one block of known type → output single Craft matrix block.
    - testBlockOrderingPreserved — input `[A, B, C]` → output `[A', B', C']` in same order.
    - testUnknownBlockTypeIsDropped — input includes an unknown legacy block type → output omits it (or emits warning per actual contract).
    - testNestedBlockGroupsFlatten (if applicable) — nested group → flattened output per Craft's matrix shape.
    - testEmptyMatrixInputReturnsEmpty — `[]` → `[]`.
    - testFieldValuesWithinBlockResolveViaSubHandlers — block field values are routed through the appropriate sub-handler (e.g. PlainText, Relation). Test that the dispatch happens; mock the registry if needed.
    - testHandlesMissingFieldHandle — block contains a field with no matching handler → field skipped, block still emits.

    **`AssetHandlerTest.php`** — target 5-8 tests:
    - testResolvesKnownLegacyMediaIdToAssetRef — stub state returns a known migrated asset id; handler returns asset-ref shape.
    - testEmitsDeferredAssetTokenForUnresolved — stub returns null; handler returns `asset:<legacyId>` deferred token.
    - testNullInputReturnsNullOrEmpty — null → null or empty, no exception.
    - testArrayOfMediaIdsResolvesEachIndependently — `[1, 2]` → resolves both.
    - testCrossLocaleResolution — siteId-aware lookup.
    - testHandlesMissingMediaRow — media-id that's never been seen by ETL → deferred token.
  </behavior>
  <action>
    Write the three test files in parallel. Each follows the same class shell as 05-05's CkeditorRewriterServiceTest, with stubs:

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

    use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;  // or Matrix/Asset
    use lameco\kunstmaanmigrator\fields\ResolverContext;
    use lameco\kunstmaanmigrator\load\MigrationStateService;
    use PHPUnit\Framework\TestCase;

    /**
     * Phase 5 / TST-01 / D-10 — direct unit tests for {Relation|Matrix|Asset}Handler.
     *
     * Strategy: createStub for MigrationStateService (handlers consume it for
     * legacy-id → migrated-id lookup). PHPUnit createStub is lighter than full
     * mock; tests assert the handler's behavior given a stubbed lookup result,
     * not that the lookup itself happens.
     *
     * Coverage target: ≥ 70.0% line coverage on the handler file (D-08 prefix gate).
     */
    final class RelationHandlerTest extends TestCase
    {
        private function ctx(?int $siteId = null): ResolverContext
        {
            return new ResolverContext(/* args per actual ctor; siteId injected if context carries it */);
        }

        private function handler(?MigrationStateService $state = null): RelationHandler
        {
            $state ??= $this->createStub(MigrationStateService::class);
            return new RelationHandler($state /*, additional injected deps per actual ctor */);
        }

        // 6-8 test methods per <behavior>.
    }
    ```

    For each handler, after writing tests:
    ```bash
    composer test-unit -- --filter "RelationHandlerTest"
    composer test-unit -- --filter "MatrixHandlerTest"
    composer test-unit -- --filter "AssetHandlerTest"
    ```
    All three must exit 0.

    Then full coverage:
    ```bash
    composer test-coverage 2>&1 | grep -E "(RelationHandler|MatrixHandler|AssetHandler)\.php"
    ```
    Each must report OK ≥ 70.0%.

    If MatrixHandler is heavily DI-coupled (e.g. requires the full plugin's handler-registry to dispatch sub-handlers), use createStub for the registry too — return a fake registry that yields predictable handlers per block-type lookup. If even with stubs the coverage stays below 70%, log the limitation in SUMMARY (genuine Craft-coupling — Phase 5.1 / NEXT input). Do NOT refactor.

    **Refactor abstinence verified:** `git diff src/` must be empty at the end of this task.
  </action>
  <verify>
    <automated>composer test-unit -- --filter "RelationHandlerTest|MatrixHandlerTest|AssetHandlerTest" 2>&amp;1 | tail -3 | grep -E "OK \(|Tests:"</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tests/unit/fields/handlers/RelationHandlerTest.php` returns 0
    - `test -f tests/unit/fields/handlers/MatrixHandlerTest.php` returns 0
    - `test -f tests/unit/fields/handlers/AssetHandlerTest.php` returns 0
    - `php -l` exits 0 on all three
    - Each file's `final class ...Test` declaration present (3 grep checks return 1 each)
    - Each file's namespace ends with `tests\\unit\\fields\\handlers` (3 grep checks return 1 each)
    - `grep -c "public function test" tests/unit/fields/handlers/RelationHandlerTest.php` returns at least 6
    - `grep -c "public function test" tests/unit/fields/handlers/MatrixHandlerTest.php` returns at least 6
    - `grep -c "public function test" tests/unit/fields/handlers/AssetHandlerTest.php` returns at least 5
    - `grep -c "createStub(MigrationStateService::class)" tests/unit/fields/handlers/RelationHandlerTest.php` returns at least 1
    - `grep -c "createStub(MigrationStateService::class)" tests/unit/fields/handlers/AssetHandlerTest.php` returns at least 1
    - `composer test-unit -- --filter "RelationHandlerTest|MatrixHandlerTest|AssetHandlerTest"` exits 0
    - If pcov/xdebug available: `composer test-coverage 2>&1 | grep -E "(Relation|Matrix|Asset)Handler\.php"` shows three OK lines, each ≥ 70.0%
    - If pcov/xdebug available: full coverage gate (`composer test-coverage` exit code) returns 0 — every TST-01 module + every src/fields/handlers/*.php at ≥ 70%
    - `git diff src/` empty (refactor abstinence)
    - `composer test` exits 0 (full corpus regression — combined with 05-05 + 05-06 Task 1, expect +47-67 tests vs Wave 2 baseline)
  </acceptance_criteria>
  <done>All 5 field handlers covered. Combined with 05-05, every TST-01 module is at the per-module 70% gate (or limitations documented). Plan 05-08's RECONCILIATION can declare TST-01 closed.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|---|---|
| test inputs → handler resolve() | Test fixtures inline; no external input |
| createStub → MigrationStateService | Stub returns deterministic values; tests assert handler behavior given known lookups |
| coverage gate → CI | tools/check-coverage.php enforces 70% per file under src/fields/handlers/ |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|---|---|---|---|---|
| T-05-06-01 | Tampering | tests pass on a stub that doesn't reflect real state | mitigate | Stubs return values that match the documented MigrationStateService surface (post-Phase 4.1 markTerminal/isTerminal additions). Tests assert the handler's REACTION to lookup outcomes, not the lookup mechanism itself. |
| T-05-06-02 | Repudiation | handler private API change breaks unit tests | accept | Same trade-off as 05-05 (Reflection-on-private-helper brittleness). Phase 5 takes it explicitly. |
| T-05-06-03 | DoS | scope creep via handler refactors | mitigate | files_modified is test-only by construction. Refactor abstinence rule is binding. If 70% coverage isn't achievable without refactor, the limitation is logged + plan does NOT extend into src/. |
| T-05-06-04 | Information Disclosure | none | accept | Tests use synthetic data; no production fixtures. |
</threat_model>

<verification>
- `composer test-unit -- --filter "(PlainText|SplitName|Relation|Matrix|Asset)HandlerTest"` exits 0; total ≥27 tests
- `composer test` exits 0
- If pcov/xdebug available: `composer test-coverage` exits 0 (the full TST-01 D-08 gate clears — every named module + every handler)
- `git diff src/` empty (no refactors)
- 05-05 + 05-06 combined corpus delta: +47-67 tests
</verification>

<success_criteria>
- D-10: All 7 D-10 test files now exist (HeuristicProposer + CkeditorRewriter from 05-05; 5 handlers from 05-06)
- TST-01 D-08: per-module 70% gate clears on all 5 modules + every src/fields/handlers/*.php (or limitations logged)
- composer test corpus delta from this plan alone: +27-42
- Refactor abstinence: zero source-code changes
- 05-08 RECONCILIATION can mark TST-01 [x] in REQUIREMENTS.md
</success_criteria>

<output>
After completion, create `.planning/phases/05-tests-rehearsal-release/05-06-SUMMARY.md` documenting:
- Per-handler test count: PlainText (X) / SplitName (Y) / Relation (Z) / Matrix (W) / Asset (V); total
- Per-handler final coverage: each as percentage; OK or FAIL on the 70% gate
- Combined Phase 5 delta over Wave 2 baseline (05-05 + 05-06 totals)
- Any handler that finished below 70% — exact uncovered line ranges + the rationale (Craft DI? Yii Component init?) — flagged as Phase 5.1 / NEXT-XX input
- Refactor abstinence confirmation: `git diff src/` empty
- TST-01 closure status: [x] ready for 05-08 RECONCILIATION OR partial-with-limitations (specify which modules)
</output>
