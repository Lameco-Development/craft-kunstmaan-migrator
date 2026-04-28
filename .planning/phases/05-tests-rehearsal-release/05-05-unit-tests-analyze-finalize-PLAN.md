---
phase: 05
plan: 05
type: tdd
wave: 3
depends_on: ["05-02"]
files_modified:
  - tests/unit/analyze/HeuristicProposerTest.php
  - tests/unit/finalize/CkeditorRewriterServiceTest.php
autonomous: true
requirements: [TST-01]
must_haves:
  truths:
    - "tests/unit/analyze/HeuristicProposerTest.php directly unit-tests the 9+ deterministic heuristics + confidence routing in HeuristicProposer (D-10)"
    - "tests/unit/finalize/CkeditorRewriterServiceTest.php unit-tests the deferred-token-resolution + finalize-pass logic in CkeditorRewriterService (D-10)"
    - "Tests use Reflection-on-private-helper for any service method that requires DB-touching deps (PATTERNS Shared Patterns; analog: tests/unit/load/AssetMigrationServiceRcaTest.php)"
    - "Final per-module coverage on src/analyze/HeuristicProposer.php is ≥ 70.0% line coverage (TST-01 D-08 gate)"
    - "Final per-module coverage on src/finalize/CkeditorRewriterService.php is ≥ 70.0% line coverage (TST-01 D-08 gate)"
    - "Plan reads BASELINE coverage from 05-02 SUMMARY first; biases test surface toward whichever module is currently below 70% (CONTEXT ## Risks paragraph 1)"
    - "No source code changes (no test-induced refactors). If a service's surface forces every test path through a DB-touching constructor, document the limitation in SUMMARY and accept the resulting coverage as-is for v1.0 — refactor is Phase 5.1 / NEXT input"
    - "Tests follow the established pure-PHPUnit class shell (final class, declare strict_types=1, namespace under tests\\unit\\<area>)"
    - "No Craft bootstrap; no integration-tier fallbacks for these unit tests"
  artifacts:
    - path: "tests/unit/analyze/HeuristicProposerTest.php"
      provides: "12+ direct unit tests for the heuristics; confidence-tier routing; residual handoff"
      contains: "final class HeuristicProposerTest"
    - path: "tests/unit/finalize/CkeditorRewriterServiceTest.php"
      provides: "8+ direct unit tests for deferred-token resolution; img→asset:n rewrite; orphan paragraph stripping"
      contains: "final class CkeditorRewriterServiceTest"
  key_links:
    - from: "tests/unit/analyze/HeuristicProposerTest.php"
      to: "src/analyze/HeuristicProposer.php"
      via: "direct instantiation (HeuristicProposer takes no constructor deps that need DB) OR Reflection on private helper"
      pattern: "new HeuristicProposer"
    - from: "tests/unit/finalize/CkeditorRewriterServiceTest.php"
      to: "src/finalize/CkeditorRewriterService.php"
      via: "Reflection on private helpers (LegacyDbService dep too heavy for unit tier; analog AssetMigrationServiceRcaTest)"
      pattern: "ReflectionMethod"
    - from: "composer test-coverage"
      to: "tools/check-coverage.php (per-module 70%)"
      via: "exit code (0 = HeuristicProposer + CkeditorRewriterService both ≥ 70%)"
      pattern: "test-coverage"
---

<objective>
**TST-01 / D-10 — Direct unit tests for the analyze/finalize duo.**

Two test files; one feature each. The split between this plan (analyze + finalize) and 05-06 (5 field handlers) is sized so each plan stays inside the 2-task / ~50% context budget; both consume the BASELINE coverage report from 05-02 SUMMARY to bias gap-fill toward whichever module currently sits below 70%.

**Why these two together:** they share a "service-with-private-helpers" testing strategy. Both classes are Yii Components but their core logic decomposes into pure functions (heuristic decision tables; regex-driven token rewriters) that are testable via Reflection. Bundling them into one plan amortizes the test-scaffolding overhead and keeps both inside the per-module 70% gate that 05-02 codified.

**HeuristicProposer (TST-01 list, line 1):** ~12-15 direct tests covering the 9 deterministic heuristics from CONTEXT D-10 + PATTERNS line 306:
1. fillRate=0 → drop, high confidence
2. entity-aware fill (heuristic 1.5)
3. exact name match
4. `*_id` → asset
5. TEXT + richtext field
6. `*_image` → asset
7. `*_date` → date
8. residual handoff to LLM
9. confidence-tier routing (high → mapping.yaml; medium → mapping.yaml.draft; low → drop with explanation)

**CkeditorRewriterService (TST-01 list, line 5):** ~8-10 direct tests covering:
- `[NT<id>]` → entry-uri rewrite
- `[M<id>]` → matrix-block reference rewrite
- `asset:<n>` → asset-ref-token rewrite
- Unresolved token preservation (no half-rewrites)
- Empty paragraph stripping
- Kunstmaan class-token cleanup
- Idempotence (rewriting an already-rewritten body is a no-op)
- Pass-through on a body with no tokens

**BASELINE-driven prioritization** (CONTEXT ## Risks paragraph 1): the executor reads `.planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md` first. If 05-02 captured a baseline (because the local dev host had pcov/xdebug), the report shows current per-module percentages. The executor biases test count toward the lower of the two modules. If 05-02 noted "no baseline captured locally," this plan runs `composer test-coverage` once at the start to capture its own baseline, then proceeds with allocation.

**Refactor abstinence (CONTEXT ## Risks variant of paragraph 5):** if the test scaffolding reveals that a service's public surface forces every test path through a Craft-DI-coupled construction (e.g. `CkeditorRewriterService::resolveTokens($body)` requires a live `LegacyDbService` injected via Plugin), do NOT refactor the service in this plan. Accept the coverage that pure-Reflection-on-private-helpers achieves; if that's below 70% even with thorough tests, document the limitation in SUMMARY as a Phase 5.1 / NEXT-XX input. The TST-01 gate is binding on this plan, but adding production-code refactors expands scope outside `files_modified`.

**files_modified is test-only** by design. If a refactor is required to hit 70%, the plan returns to the orchestrator for re-scoping rather than silently extending into src/.

Output:
- 2 new test files (~20-25 tests combined)
- `composer test-coverage` reports HeuristicProposer ≥ 70% AND CkeditorRewriterService ≥ 70%
- `composer test-unit` total grows by ~20-25 tests
- 05-06 in parallel covers the 5 field handlers; 05-08 RECONCILIATION aggregates the TST-01 closure
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
HeuristicProposer (Phase 3 / Plan 02 — verify against current src/analyze/HeuristicProposer.php). The class is a Yii Component; its public method (likely `propose(array $rows, array $craftFieldIndex, array $entityInfos): array` — confirm by reading the file) takes data structures and returns array results. No DB, no Craft container at runtime — direct instantiation works.

CkeditorRewriterService (Phase 3 / Plan 06 — verify against current src/finalize/CkeditorRewriterService.php). The class IS DB-touching: constructor likely injects LegacyDbService + MigrationStateService. The Reflection strategy targets the regex-driven private helpers (e.g. `rewriteAssetTokens(string $body): string`) that take strings in, return strings out — pure logic, no DB inside.

PATTERNS analog (verified at tests/load/AssetMigrationServiceRcaTest.php:24-36): the Reflection-on-private-helper shape:
```php
private function callPrivate(string $method, mixed ...$args): mixed
{
    $svc = new CkeditorRewriterService(/* minimal stubs for unused deps */);
    $rm = new ReflectionMethod($svc, $method);
    return $rm->invoke($svc, ...$args);
}
```
PHP 8.1+ — `setAccessible(true)` is no longer needed; default true since 8.1.

Per CONTEXT D-08 + 05-02 phpunit.xml.dist `<source>` block: only the 5 TST-01 modules + everything under `src/fields/handlers/` are scoped for coverage. CkeditorRewriterService is in the list; HeuristicProposer is in the list. Other tests touching these files indirectly count for coverage, but the per-module gate looks at the file itself.
</interfaces>

<reference_files>
- src/analyze/HeuristicProposer.php — class under test (read whole file in execute)
- src/finalize/CkeditorRewriterService.php — class under test (read whole file in execute)
- tests/unit/load/AssetMigrationServiceRcaTest.php (post-05-01 path) — Reflection-on-private-helper analog
- tests/unit/load/SeoMigrationServiceGateTest.php — alternate Reflection analog
- tests/unit/locale/LocalePreflightTest.php — pure-helper-no-Craft class shell analog
- .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: HeuristicProposerTest section, lines 262-307; NEW: CkeditorRewriterServiceTest section, lines 308-332)
- .planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md — BASELINE coverage report (or note that no baseline was captured locally)
</reference_files>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Capture coverage baseline (if 05-02 SUMMARY didn't); write tests/unit/analyze/HeuristicProposerTest.php</name>
  <files>
    tests/unit/analyze/HeuristicProposerTest.php
  </files>
  <read_first>
    - .planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md (extract per-module coverage table for HeuristicProposer + CkeditorRewriterService — drives test allocation per CONTEXT ## Risks paragraph 1)
    - src/analyze/HeuristicProposer.php (whole file — extract: every public method signature; every private helper; the heuristics list; the confidence-tier mapping; any DTO classes used in inputs/outputs)
    - tests/unit/locale/LocalePreflightTest.php (post-05-01 path — pure-PHPUnit class shell analog: final class, declare strict_types=1, namespace under tests\unit\<area>)
    - tests/unit/load/SeoMigrationServiceGateTest.php (post-05-01 path — Reflection-on-private-helper template if HeuristicProposer's surface needs it)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: HeuristicProposerTest section, lines 262-307 — heuristic-by-heuristic test list)
    - composer.json (verify the `test-coverage` script is present from 05-02 — required for the baseline run)
  </read_first>
  <behavior>
    Tests in `tests/unit/analyze/HeuristicProposerTest.php` (target ~12-15 tests; ~70%+ line coverage on src/analyze/HeuristicProposer.php):

    Heuristic-by-heuristic — name each test for the heuristic + outcome combination:

    - **testHeuristic1FillRateZeroDropsColumn** — column with `fillRate: 0.0` → matched as `decision: 'drop'`, `confidence: 'high'`. Residual empty.
    - **testHeuristic1aEntityAwareFillRateZero** — fillRate is per-entity (heuristic 1.5; PATTERNS line 306). Mock the entityInfos shape with one entity at zero fill but another at 100% → column NOT dropped (entity-aware preserves per-context use).
    - **testHeuristic3ExactNameMatchHigh** — column `slug` against craftFieldIndex containing field `slug` of type `Slug` → high-confidence map.
    - **testHeuristic4IdSuffixMapsToAsset** — column `header_image_id` → matched to an asset field if one exists in craftFieldIndex; high-confidence.
    - **testHeuristic5TextWithRichtextField** — column with sqlType TEXT, target field of type RichText → high-confidence ckeditor map.
    - **testHeuristic6ImageSuffixMapsToAsset** — column `*_image` (and `*_afbeelding` if Dutch alias map present) → asset map.
    - **testHeuristic7DateSuffixMapsToDate** — column `*_date` (and `*_datum` Dutch alias) → date field map.
    - **testHeuristic8UrlSuffix** (if present in current code) — `*_url` / `*_link` → url field.
    - **testHeuristic9EmailSuffix** (if present) — `*_email` → email field.
    - **testDutchSemanticAliasMap** — `titel` aliases to `title`, `tekst` to `text`, etc. Confirm the alias map is exercised.
    - **testResidualHandoffWhenNoHeuristicMatches** — column with a name no heuristic recognizes → ends up in the residual array (LLM hand-off candidate).
    - **testConfidenceRoutingHighGoesToMatched** — high-confidence proposal in the matched array; not in residual.
    - **testConfidenceRoutingMediumStillMatched** — medium-confidence proposal still in matched (LLM later decides accept/draft).
    - **testProposeReturnsArrayShapeMatchedAndResidual** — return type contract: `[matched, residual]` 2-tuple with predictable keys.

    The exact heuristic count + naming MUST match what's actually in `HeuristicProposer.php`. If the current code has only 7 heuristics (not 9), drop the 2 missing tests. If it has 11, add the extras. PATTERNS lists 9 + 1.5 = 10 heuristics; the executor verifies which are implemented in `propose()` or in helper methods called by `propose()`.

    Each test follows the shape:
    ```php
    public function testHeuristic1FillRateZeroDropsColumn(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->propose(
            rows: [['column' => 'unused_col', 'fillRate' => 0.0, 'tableName' => 'kuma_news_page', 'sqlType' => 'VARCHAR(255)']],
            craftFieldIndex: [],
            entityInfos: [],
        );
        self::assertCount(1, $matched);
        self::assertSame('drop', $matched[0]['decision']);
        self::assertSame('high', $matched[0]['confidence']);
        self::assertSame([], $residual);
    }
    ```

    Adjust the input row shape and method signature to match what's actually in HeuristicProposer.php — this is illustrative.
  </behavior>
  <action>
    **1a. Capture or extract baseline.** First check if 05-02 SUMMARY captured a per-module coverage baseline:

    ```bash
    grep -A 20 "BASELINE\|coverage gate\|Coverage Report" .planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md 2>/dev/null
    ```

    Two outcomes:

    - **Baseline present in 05-02 SUMMARY:** read the per-module percentages. Note `HeuristicProposer.php` and `CkeditorRewriterService.php` baseline numbers. Bias allocation: if HeuristicProposer is at, say, 35%, prioritize quantity (12-15 tests, all heuristics covered). If it's already at 75%, fewer tests are needed (~6-8 to keep it above 70% as the source surface evolves; 70% is a floor, not an aim).

    - **No baseline (05-02 noted 'driver not available locally'):** run a baseline now if a coverage driver is available locally:
      ```bash
      composer test-coverage 2>&1 | tee /tmp/05-05-baseline.txt
      grep -E "HeuristicProposer\.php|CkeditorRewriterService\.php" /tmp/05-05-baseline.txt
      ```
      Capture the numbers in 05-05 SUMMARY. If neither pcov nor xdebug is locally installed, proceed with the assumption that BOTH modules currently sit BELOW 70% (the conservative branch — write the maximum reasonable test set per module) and verify in CI later via the 05-07 PR.

    **1b. Write `tests/unit/analyze/HeuristicProposerTest.php`** following the spec in `<behavior>`:

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\tests\unit\analyze;

    use lameco\kunstmaanmigrator\analyze\HeuristicProposer;
    use PHPUnit\Framework\TestCase;

    /**
     * Phase 5 / TST-01 / D-10 — direct unit tests for the deterministic heuristics
     * + confidence routing in HeuristicProposer.
     *
     * Pure-PHPUnit: HeuristicProposer is a Yii Component but its propose() method
     * takes data structures (rows + craftFieldIndex + entityInfos) and returns
     * arrays — no Craft container needed. Direct instantiation; no Reflection
     * unless a private helper turns out to be more focused than the public
     * propose() entry point.
     *
     * Coverage target: ≥ 70.0% line coverage on src/analyze/HeuristicProposer.php
     * (TST-01 / D-08 gate enforced by tools/check-coverage.php in CI).
     */
    final class HeuristicProposerTest extends TestCase
    {
        // 12-15 test methods per <behavior>; one heuristic + outcome combination per method.
    }
    ```

    Walk down the heuristics list with one test per (heuristic, outcome) pair. For each test:
    - Construct a minimal `rows` input that triggers exactly that heuristic.
    - For heuristics that need `craftFieldIndex` populated (e.g. exact-name-match), build the smallest field-index entry that would match.
    - For entity-aware heuristic 1.5, build a 2-entity `entityInfos` map where one entity has fillRate=0 and the other has fillRate>0.
    - Assert the returned `matched` array contains exactly the expected entry; assert the returned `residual` array is empty (or contains only the column for residual-handoff tests).

    **1c. Verify coverage on the targeted module.**

    Run:
    ```bash
    composer test-unit -- --filter HeuristicProposerTest
    ```
    Must exit 0; ≥12 tests pass.

    Then full coverage:
    ```bash
    composer test-coverage 2>&1 | grep "HeuristicProposer\.php"
    ```
    Must show `OK` (≥ 70.0%). If it shows `FAIL X.X%`, add tests for whichever code path is uncovered (use the clover XML to identify uncovered lines: `grep -A 1 'HeuristicProposer.php' build/coverage/clover.xml | head -40`). DO NOT modify `src/analyze/HeuristicProposer.php` to make it more testable; if the surface is genuinely untestable in isolation (e.g. one private method requires a non-injectable dep), document the limitation in SUMMARY and accept the resulting percentage. Per the refactor-abstinence rule, this plan does NOT add to `files_modified` mid-execution.

    **DO NOT** add `src/analyze/HeuristicProposer.php` to `files_modified`. **DO NOT** refactor the proposer to expose private helpers as public-static. If reflection on existing privates yields enough coverage, fine; if not, the limitation is real and gets logged.
  </action>
  <verify>
    <automated>composer test-unit -- --filter HeuristicProposerTest 2>&amp;1 | tail -3 | grep -E "OK \(|Tests:" &amp;&amp; composer test-coverage 2>&amp;1 | grep "HeuristicProposer\.php" | grep -E "OK \s*[789][0-9]\.[0-9]%|OK \s*100\.0%"</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tests/unit/analyze/HeuristicProposerTest.php` returns 0
    - `php -l tests/unit/analyze/HeuristicProposerTest.php` exits 0
    - `grep -c "final class HeuristicProposerTest" tests/unit/analyze/HeuristicProposerTest.php` returns 1
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit\\\\analyze" tests/unit/analyze/HeuristicProposerTest.php` returns 1
    - `grep -c "public function test" tests/unit/analyze/HeuristicProposerTest.php` returns at least 10 (target 12-15; ≥10 is the floor for breadth)
    - `composer test-unit -- --filter HeuristicProposerTest` exits 0; the trailing line shows `OK` and a test count ≥10
    - If pcov/xdebug locally available: `composer test-coverage 2>&1 | grep "HeuristicProposer\.php"` shows `OK` with percentage ≥ 70.0% (D-08 gate)
    - `git diff src/` is empty (no source code changes — refactor-abstinence respected)
    - `composer test` exits 0 (full corpus regression)
  </acceptance_criteria>
  <done>HeuristicProposer at or above 70% line coverage. CkeditorRewriterService is next.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Write tests/unit/finalize/CkeditorRewriterServiceTest.php (Reflection on private helpers)</name>
  <files>
    tests/unit/finalize/CkeditorRewriterServiceTest.php
  </files>
  <read_first>
    - src/finalize/CkeditorRewriterService.php (whole file — identify every private helper that takes strings/arrays and returns strings/arrays without DB access; these are the Reflection targets. Examples to look for: rewriteAssetTokens, rewriteEntryTokens, stripKmaClasses, normalizeParagraphs, finalizePass, etc. Document each helper's signature in the SUMMARY.)
    - src/finalize/CkeditorRewriterService.php constructor (note which deps are injected — typically LegacyDbService + MigrationStateService; for Reflection-test purposes, build with `createStub()` for each)
    - tests/unit/load/AssetMigrationServiceRcaTest.php (post-05-01 path — verbatim Reflection-on-private-helper template)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: CkeditorRewriterServiceTest section, lines 308-332 — verbatim shape)
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-10 — module is in the per-module 70% gate list)
    - .planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md (or 05-05 Task 1 baseline — current coverage on CkeditorRewriterService.php)
  </read_first>
  <behavior>
    Tests in `tests/unit/finalize/CkeditorRewriterServiceTest.php` (target ~8-12 tests; ≥ 70% line coverage):

    Token-rewrite helpers (Reflection on `rewriteAssetTokens` / equivalent):

    - **testRewritesImgSrcWithLegacyAssetIdToAssetRefToken** — input `<img src="...legacy/asset?id=42">` (or whatever the legacy emit shape is) → output `<img src="" data-asset-id="42">` (or whatever the Craft-shaped output is — verify against the actual rewriter contract).
    - **testEmitsUnresolvedMarkerForMissingAsset** — input contains `[NT999]` for a non-existent legacy entry → output preserves `[NT999]` literal (so RehearsalController's Gate 2 catches it). NOT a half-resolved mangled string.
    - **testStripsKmaClassTokens** — input contains `class="kma-foo bar"` → output drops the `kma-foo` token, preserves `bar`.
    - **testRemovesEmptyParagraphs** — input contains `<p></p>` and `<p>&nbsp;</p>` → both removed.
    - **testIdempotenceRewriteIsStableAcrossMultiplePasses** — input that has been rewritten once → second pass produces byte-identical output (no double-stripping, no re-encoding).
    - **testPassesThroughBodyWithNoTokens** — input plain HTML with no tokens / kma-classes / empty paragraphs → output identical to input.
    - **testNtAndMTokensCoexistInBody** — input contains both `[NT5]` and `[M9]`; both unresolved → both preserved.
    - **testFinalizePassLoopTerminates** — if the public `finalizePass()` (or whatever the public entry point is) runs over a body that needs N rewrite cycles, it terminates and produces a deterministic output (no infinite loop). This is the smoke-tier confidence test.

    For each test, the Reflection helper:
    ```php
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $svc = new CkeditorRewriterService(
            $this->createStub(LegacyDbService::class),
            $this->createStub(MigrationStateService::class),
            // ... whatever else the constructor needs
        );
        $rm = new ReflectionMethod($svc, $method);
        return $rm->invoke($svc, ...$args);
    }
    ```

    If the rewriter has helpers that DO need DB lookups (e.g. `resolveLegacyEntryId` queries state), skip them in the unit tier. Their coverage comes from the integration-tier characterization fixtures (Plan 05-03's TransformCharacterizationTest). Document which helpers were skipped in SUMMARY.
  </behavior>
  <action>
    Inspect `src/finalize/CkeditorRewriterService.php` whole file and enumerate every private helper. Categorize:

    - **Pure (string/array in, string/array out, no DB):** target with Reflection.
    - **DB-touching (queries LegacyDbService or MigrationStateService):** skip from unit tier; characterization fixtures cover.
    - **Public entry points:** test the public surface IF the constructor stubs work; otherwise skip and use Reflection on private helpers.

    Write `tests/unit/finalize/CkeditorRewriterServiceTest.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\tests\unit\finalize;

    use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
    use lameco\kunstmaanmigrator\source\LegacyDbService;
    use lameco\kunstmaanmigrator\load\MigrationStateService;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * Phase 5 / TST-01 / D-10 — direct unit tests for CkeditorRewriterService's
     * deterministic helpers (regex-driven token rewriters, kma-class stripping,
     * paragraph normalization).
     *
     * Strategy: Reflection on private helpers (PATTERNS Shared Patterns analog —
     * tests/unit/load/AssetMigrationServiceRcaTest.php). The constructor injects
     * LegacyDbService + MigrationStateService; both are stubbed to avoid Craft
     * DI. Helpers that genuinely need DB access (e.g. resolveLegacyEntryId)
     * are NOT exercised here — characterization fixtures in Plan 05-03 cover
     * those paths.
     *
     * Coverage target: ≥ 70.0% line coverage on src/finalize/CkeditorRewriterService.php
     * (TST-01 / D-08 gate enforced by tools/check-coverage.php in CI).
     */
    final class CkeditorRewriterServiceTest extends TestCase
    {
        private function service(): CkeditorRewriterService
        {
            return new CkeditorRewriterService(
                $this->createStub(LegacyDbService::class),
                $this->createStub(MigrationStateService::class),
                // Add additional stubs if the constructor signature has more deps.
            );
        }

        private function callPrivate(string $method, mixed ...$args): mixed
        {
            $rm = new ReflectionMethod(CkeditorRewriterService::class, $method);
            return $rm->invoke($this->service(), ...$args);
        }

        // 8-12 test methods per <behavior>.
    }
    ```

    Run the targeted suite:
    ```bash
    composer test-unit -- --filter CkeditorRewriterServiceTest
    ```
    Must exit 0; ≥8 tests pass.

    Then verify coverage:
    ```bash
    composer test-coverage 2>&1 | grep "CkeditorRewriterService\.php"
    ```
    Must report `OK` ≥ 70.0%.

    If coverage falls short:
    1. Inspect clover XML for uncovered lines: `grep -B 2 -A 2 'CkeditorRewriterService.php' build/coverage/clover.xml | head -60` — identify which line numbers are uncovered.
    2. For each uncovered line range, decide: covered-by-Reflection-here (add a test), or DB-coupled (covered by characterization fixtures in 05-03).
    3. Add tests for the first category until the gate clears OR document in SUMMARY which lines remain uncovered + why.

    Refactor abstinence: do NOT make a private helper public for testability. The Reflection pattern works for any private-method-that-takes-strings shape.
  </action>
  <verify>
    <automated>composer test-unit -- --filter CkeditorRewriterServiceTest 2>&amp;1 | tail -3 | grep -E "OK \(|Tests:" &amp;&amp; composer test-coverage 2>&amp;1 | grep "CkeditorRewriterService\.php" | grep -E "OK\s+([789][0-9]|100)\.[0-9]%"</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tests/unit/finalize/CkeditorRewriterServiceTest.php` returns 0
    - `php -l tests/unit/finalize/CkeditorRewriterServiceTest.php` exits 0
    - `grep -c "final class CkeditorRewriterServiceTest" tests/unit/finalize/CkeditorRewriterServiceTest.php` returns 1
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit\\\\finalize" tests/unit/finalize/CkeditorRewriterServiceTest.php` returns 1
    - `grep -c "ReflectionMethod" tests/unit/finalize/CkeditorRewriterServiceTest.php` returns at least 1 (Reflection helper present)
    - `grep -c "public function test" tests/unit/finalize/CkeditorRewriterServiceTest.php` returns at least 8 (target 8-12)
    - `composer test-unit -- --filter CkeditorRewriterServiceTest` exits 0; OK count ≥ 8
    - If pcov/xdebug locally available: `composer test-coverage 2>&1 | grep "CkeditorRewriterService\.php"` shows `OK` with percentage ≥ 70.0%
    - `git diff src/` is empty (refactor-abstinence)
    - `composer test` exits 0 (full corpus regression — combined with Task 1, expect +20-25 tests)
  </acceptance_criteria>
  <done>Both modules in this plan ≥ 70% line coverage (or limitation documented). Plan 05-06 covers the 5 field handlers; together they close the TST-01 D-08 gate.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| test inputs → service helpers | Test fixtures inline in test methods; no external input crosses |
| Reflection → private API | Unit tier explicitly probes internal-only helpers; documented pattern |
| coverage gate → CI build | tools/check-coverage.php enforces 70% per-module; failure blocks PR |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-05-01 | Tampering | tests pass on the wrong surface | mitigate | Each test asserts a specific heuristic-by-outcome pair; the 70% per-module gate (05-02 Task 3) ensures coverage breadth tracks the module's actual size. If the module grows past test coverage, the gate fails. |
| T-05-05-02 | Repudiation | tests target private API; refactor breaks them | accept | Reflection-on-private-helper is a known-fragile testing pattern. The trade-off is unit-tier signal vs. test brittleness. Phase 5 takes the trade-off explicitly. PATTERNS Shared Patterns documents this. |
| T-05-05-03 | DoS | Reflection scope creep | mitigate | This plan touches ONLY tests/unit/{analyze,finalize}/. No source-code surfaces are added to files_modified. If a refactor is required for testability, the plan returns to the orchestrator. |
| T-05-05-04 | Information Disclosure | none | accept | Tests use synthetic inputs; no production data. |
</threat_model>

<verification>
- `composer test-unit -- --filter HeuristicProposerTest` exits 0; ≥10 tests
- `composer test-unit -- --filter CkeditorRewriterServiceTest` exits 0; ≥8 tests
- `composer test` exits 0 (full corpus regression)
- If a coverage driver is locally available: `composer test-coverage 2>&1 | grep -E "(HeuristicProposer|CkeditorRewriterService)\.php"` shows OK ≥ 70.0% on both modules
- `git diff src/` empty (no source-code refactors)
- 05-06 (parallel in Wave 3) must independently land per-handler tests; together they close the TST-01 5-module gate
</verification>

<success_criteria>
- D-10: Two new unit-test files at the prescribed paths
- TST-01 D-08 partial closure: 2 of the 5 modules verified ≥ 70% locally OR documented gap (with Phase 5.1 / NEXT input) if a refactor would have been required
- composer test corpus delta: +20-25 tests
- BASELINE coverage report from 05-02 (or freshly captured here) drove allocation; documented in SUMMARY
- Refactor abstinence: zero source-code changes in src/
</success_criteria>

<output>
After completion, create `.planning/phases/05-tests-rehearsal-release/05-05-SUMMARY.md` documenting:
- Final test counts: HeuristicProposerTest (target 12-15) + CkeditorRewriterServiceTest (target 8-12); total +20-27 to corpus
- Final coverage on each: HeuristicProposer.php = X.X%; CkeditorRewriterService.php = Y.Y% (per-module gate; ≥ 70.0% required for v1.0)
- Which CkeditorRewriterService private helpers were targeted via Reflection vs skipped (DB-touching, covered by 05-03 characterization fixtures)
- If either module finished below 70%, document the specific uncovered line ranges + the rationale (DB coupling? Yii Component init coupling?) and flag as Phase 5.1 / NEXT input
- Refactor-abstinence confirmation: `git diff src/` empty
- Hand-off note for 05-06: which of the 5 field handlers (PlainText, SplitName, Relation, Matrix, Asset) are most likely to need similar Reflection treatment vs direct construction
</output>
