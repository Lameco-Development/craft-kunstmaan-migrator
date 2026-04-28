---
phase: 05
plan: 04
type: execute
wave: 2
depends_on: ["05-01"]
files_modified:
  - src/console/RehearsalController.php
  - tests/unit/console/RehearsalControllerTest.php
  - .planning/rehearsal/v1.0/cqm/README.md
  - .planning/rehearsal/v1.0/simac/README.md
  - .planning/rehearsal/v1.0/enreach/README.md
autonomous: true
requirements: [TST-04]
must_haves:
  truths:
    - "src/console/RehearsalController.php DELIBERATELY OMITS NeverProductionTrait (D-22) — documented in class docblock"
    - "kunstmaan-migrator/rehearsal/check <dir> exit 0 when all three gates pass; non-zero with structured per-gate failure summary otherwise"
    - "Gate 1 (counts within tolerance): parses [1/2] Count-match gate block from <dir>/VERIFY.md; asserts every line is OK or SKIP — no WARN/FAIL"
    - "Gate 2 (zero unresolved CKEditor tokens): greps <dir>/REPORT.md for [NT<id>], [M<id>], asset:<n> patterns; fails on any hit not listed in optional <dir>/allow-tokens.txt"
    - "Gate 3 (all assets RCA-tagged): parses ## Asset RCA table from <dir>/REPORT.md; every row has a non-empty reason column"
    - "Three gate-parser methods are public static (PATTERNS Shared Patterns: static-helper-extraction) — testable via Reflection without Craft DI"
    - "Controller is read-only over committed artifacts; never opens legacy DB; never reads KUNSTMAAN_SOURCE_PATH; never touches kunstmaanmigrator_state"
    - "CQM rehearsal-check exit 0 is the v1.0 tag gate (D-19); simac/enreach are advisory"
    - "Three rehearsal directories committed under .planning/rehearsal/v1.0/{cqm,simac,enreach}/ with README.md placeholders documenting expected file shape (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt, optional allow-tokens.txt) — the operator captures real artifacts on a dev host (D-24)"
    - "rehearsal-check is registered as a console action via Craft's controllerNamespace auto-discovery (no manual controllerMap edit needed; mirrors every other src/console/ controller)"
  artifacts:
    - path: "src/console/RehearsalController.php"
      provides: "actionCheck(dir) + three public static gate-parser helpers; first statement is NOT enforceNeverProduction (D-22)"
      contains: "actionCheck"
    - path: "tests/unit/console/RehearsalControllerTest.php"
      provides: "Reflection-based unit tests for the three gate parsers; fixture strings inline (small) or under tests/unit/console/fixtures/rehearsal/ (if larger)"
      contains: "RehearsalControllerTest"
    - path: ".planning/rehearsal/v1.0/cqm/README.md"
      provides: "Operator instructions: which files to commit, where to copy them from, when to run rehearsal-check"
      contains: "REPORT.md"
    - path: ".planning/rehearsal/v1.0/simac/README.md"
      provides: "Same shape; advisory only (D-19)"
      contains: "advisory"
    - path: ".planning/rehearsal/v1.0/enreach/README.md"
      provides: "Same shape; advisory only (D-19)"
      contains: "advisory"
  key_links:
    - from: "src/console/RehearsalController.php (actionCheck)"
      to: ".planning/rehearsal/v1.0/<dir>/{REPORT,VERIFY}.md"
      via: "file_get_contents (read-only)"
      pattern: "file_get_contents"
    - from: "tests/unit/console/RehearsalControllerTest.php"
      to: "RehearsalController public static parsers"
      via: "ReflectionMethod or direct call"
      pattern: "RehearsalController::"
    - from: ".planning/rehearsal/v1.0/cqm/"
      to: "Plan 05-08 RELEASE-CHECKLIST.md step 5"
      via: "kunstmaan-migrator/rehearsal/check exit 0 gate"
      pattern: "rehearsal-check"
---

<objective>
**TST-04 / D-19..D-24 — Rehearsal log mechanical gate + directory shape.**

Two artifacts ship in this plan:

1. **`src/console/RehearsalController.php`** — new console controller (D-23 picked over adding actions to MigrateController, which is already 1700+ LOC per PATTERNS callout 6). Single action `actionCheck($dir)` that asserts the three ROADMAP success criterion #4 gates mechanically against committed rehearsal artifacts. **Deliberately omits `NeverProductionTrait`** (D-22, PATTERNS callout 6) — the controller is read-only over committed artifacts on disk and never touches the legacy DB / state table / source path. The class docblock prominently documents this departure so a future maintainer doesn't reflexively re-add the trait.

2. **`.planning/rehearsal/v1.0/{cqm,simac,enreach}/README.md`** — directory-shape placeholders. The actual rehearsal artifacts (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt, optional allow-tokens.txt) are operator-captured on a dev host (D-24) and committed separately — typically alongside the v1.0 tag. The README in each dir documents what the operator copies and when. The directories ship now so the directory shape commits alongside the controller that consumes them.

**The three gates, mechanically (D-21):**

- **Gate 1 — Counts within tolerance.** Parse the `[1/2] Count-match gate` block out of `<dir>/VERIFY.md` (markdown report VerifyController writes via MappingFile::writeAtomic — the format is established Phase 4 / Plan 04 surface). Assert every line is `OK` or `SKIP`. Any `WARN` or `FAIL` line counts the gate as failed. CI-only mode (D-21 second clause): we do NOT re-run CountGateService against live Craft state in `rehearsal-check`; we trust the committed VERIFY.md output. Plans needing live re-run can extend later.

- **Gate 2 — Zero unresolved CKEditor tokens.** Grep `<dir>/REPORT.md` for `[NT<id>]`, `[M<id>]`, `asset:<n>` literal patterns. Fail on any hit not present in optional `<dir>/allow-tokens.txt` (one allow-listed token per line; comments via `#`).

- **Gate 3 — All assets RCA-tagged.** Parse the `## Asset RCA` table from `<dir>/REPORT.md` (Phase 4 / D-68 + Phase 4.1 / CFG-07: section always emits, with placeholder copy when empty). Assert every row has a non-empty reason column. Empty section (placeholder copy from CFG-07) → gate passes (no rows = nothing to check).

**Exit codes:** 0 when all three gates pass; 1 when any gate fails (with per-gate failure summary on stderr); 2 when the directory or required files are missing.

**Why this is Wave 2:** No file overlap with 05-02 (composer.json/phpunit.xml.dist) or 05-03 (tools/, tests/integration/transform/). Parallel runs cleanly.

Output:
- New controller with actionCheck + 3 public static gate-parser helpers
- Unit test exercising each gate parser with embedded fixture strings via Reflection
- Three operator-facing README placeholders under `.planning/rehearsal/v1.0/{cqm,simac,enreach}/`
- `composer test-unit` discovers the new test; full corpus passes
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
@.planning/phases/05-tests-rehearsal-release/05-01-SUMMARY.md
@CLAUDE.md

<interfaces>
VerifyController writes VERIFY-{ts}.md via MappingFile::writeAtomic (verified at src/console/VerifyController.php:265 per CONTEXT). The markdown shape contains a `[1/2] Count-match gate` section with per-entity rows like `OK PageNode 142 (delta -1 within tolerance 5)` or `WARN PageNode 142 (delta -7 outside tolerance 5)`. Gate 1 parses these lines.

REPORT.md is written by MigrateController (Phase 4 / Plan 12 + Phase 4.1 / CFG-07). It always contains `## Skipped stages` and `## Asset RCA` sections (CFG-07 placeholder copy). The Asset RCA table format is established Phase 4 / D-68 — typical row shape: `| asset:<n> | <reason> | <count> |` with reason possibly empty if a row is malformed (this gate catches that).

The CKEditor token patterns are emitted by CkeditorRewriterService when token rewrites fail (Phase 3 / Plan 06). Patterns: `[NT<id>]` (entry token), `[M<id>]` (media/matrix token), `asset:<n>` (deferred asset reference). These appear in REPORT.md under sections that finalize-stage emits.

VerifyController.php:282 first statement is `if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }`. RehearsalController's actionCheck DOES NOT do this — first statement is the cyan stdout banner instead. PATTERNS callout 6 + D-22.

Console controller auto-discovery: src/console/ controllers register via Plugin::getInstance()'s `$controllerNamespace` (Yii's mechanism). MigrateController, VerifyController, DoctorController, AnalyzeController, MapController are all auto-discovered. RehearsalController dropped into src/console/ + namespace `lameco\kunstmaanmigrator\console` registers automatically at `kunstmaan-migrator/rehearsal/...`.

The Yii action-name convention: actionCheck() is invoked as `kunstmaan-migrator/rehearsal/check <dir>`. The dir parameter binds positional (Yii's defaultAction binds method args to positional CLI args).
</interfaces>

<reference_files>
- src/console/VerifyController.php — closest analog (multi-action controller, atomic-write artifact emission, similar parameter shape)
- src/console/MigrateController.php — currently 1700+ LOC; explicitly NOT extending it (PATTERNS callout 6)
- src/console/DoctorController.php — used by Plan 05-07's smoke job; not modified here
- .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: src/console/RehearsalController.php section, lines 575-665; Shared Patterns: NeverProductionTrait OMITTED + static-helper-extraction)
- .planning/phases/04-adapters-verify-settings/ — Phase 4 SUMMARYs documenting REPORT.md / VERIFY.md shape
- .planning/phases/04.1-polish-recovery-and-env-defaults/ — CFG-07 placeholder copy contract
</reference_files>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Write src/console/RehearsalController.php (actionCheck + 3 public static gate parsers)</name>
  <files>
    src/console/RehearsalController.php,
    tests/unit/console/RehearsalControllerTest.php
  </files>
  <read_first>
    - src/console/VerifyController.php (whole file — closest analog: multi-action controller with public bool flags, options() override, file-reading actions, MappingFile::writeAtomic for output if controller emits its own report)
    - src/console/MigrateController.php (lines 1-100 — confirm class shell + use NeverProductionTrait pattern; lines 1127-1170 renderSyncAssetsReport static-helper for testability via Reflection)
    - src/Plugin.php (whole file — confirm controllerNamespace auto-discovery; verify no manual controllerMap edit is needed for src/console/RehearsalController.php to register)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: src/console/RehearsalController.php section, lines 575-665 — verbatim target shell)
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-19..D-24 — controller scope, gate spec, deliberate trait omission)
    - tests/unit/load/AssetMigrationServiceRcaTest.php (Reflection-on-private-helper template; static-helper-extraction shape — though here we make helpers public static so tests skip Reflection)
  </read_first>
  <behavior>
    Tests in `tests/unit/console/RehearsalControllerTest.php` (NO Craft bootstrap; NO Reflection needed since gate parsers are public static):

    - **testParseCountMatchBlockAcceptsAllOk** — input: a VERIFY.md fragment with `[1/2] Count-match gate` block where every line begins with `OK`. Output: empty array (no failures).
    - **testParseCountMatchBlockAcceptsSkip** — input: block with one `SKIP entity (no baseline)` line. Output: empty array.
    - **testParseCountMatchBlockFailsOnWarn** — input: block with one `WARN PageNode 142 (delta -7 outside tolerance 5)`. Output: `['Count gate WARN: PageNode 142 (delta -7 outside tolerance 5)']`.
    - **testParseCountMatchBlockFailsOnFail** — input: block with one `FAIL ...`. Output: includes the failure description.
    - **testParseCountMatchBlockMissingSection** — input: VERIFY.md without a `[1/2] Count-match gate` heading. Output: `['Count gate section not found in VERIFY.md']`.

    - **testFindUnresolvedCkeditorTokensZero** — input: REPORT.md with no [NT/M/asset:] patterns. Output: `[]`.
    - **testFindUnresolvedCkeditorTokensFindsNt** — input: REPORT.md with `Some prose [NT123] more prose`. Output: `['[NT123]']`.
    - **testFindUnresolvedCkeditorTokensFindsM** — `[M456]` → returned.
    - **testFindUnresolvedCkeditorTokensFindsAsset** — `asset:789` → returned.
    - **testFindUnresolvedCkeditorTokensRespectsAllowList** — input REPORT.md `[NT123]` + allow-tokens text `[NT123]`. Output: `[]` (allow-listed).
    - **testFindUnresolvedCkeditorTokensIgnoresCommentLinesInAllowList** — allow-tokens `# comment\n[NT123]\n` → `[NT123]` allowed; lines starting with `#` ignored.

    - **testParseAssetRcaTableAllRowsHaveReason** — input: REPORT.md with `## Asset RCA` table where every data row has non-empty reason cell. Output: `[]`.
    - **testParseAssetRcaTableEmptySectionPasses** — input: `## Asset RCA` followed by placeholder copy (CFG-07: e.g. "_No asset RCA entries — every referenced asset migrated successfully._"). Output: `[]` (empty section passes).
    - **testParseAssetRcaTableEmptyReasonFails** — input: row with empty reason cell `| asset:42 | | 1 |`. Output: `['Asset RCA row missing reason: asset:42']`.
    - **testParseAssetRcaTableMissingSection** — input: REPORT.md without `## Asset RCA`. Output: `['Asset RCA section not found in REPORT.md']` (Phase 4 / Phase 4.1 contract requires the section to always emit).

    Total: ~14 tests across the three parsers. Direct calls (helpers are public static — no Reflection) keep the test file simple. Fixture strings are short enough to inline as PHP heredocs in the test file; only a couple longer fixtures may live under `tests/unit/console/fixtures/rehearsal/<name>.md` if inlining hurts readability — executor decides.
  </behavior>
  <action>
    **1a. Create `src/console/RehearsalController.php`:**

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\console;

    use Craft;
    use craft\console\Controller;
    use craft\helpers\Console;
    use yii\console\ExitCode;

    /**
     * `kunstmaan-migrator/rehearsal/check` console controller — Phase 5 / TST-04 / D-21..D-23.
     *
     * Three mechanical gates over a committed rehearsal directory:
     *   1. Counts within tolerance — parses [1/2] Count-match block from VERIFY.md
     *   2. Zero unresolved CKEditor tokens — greps REPORT.md for [NT<id>] / [M<id>] /
     *      asset:<n> patterns (allow-list at <dir>/allow-tokens.txt, optional)
     *   3. All assets RCA-tagged — every row in REPORT.md ## Asset RCA has a non-
     *      empty reason
     *
     * IMPORTANT: This controller does NOT carry NeverProductionTrait (D-22, Phase 5
     * PATTERNS callout 6). The command is read-only over committed artifacts on disk
     * — never opens the legacy DB connection, never reads KUNSTMAAN_SOURCE_PATH,
     * never touches kunstmaanmigrator_state. Applying the trait would gate a CI step
     * on CRAFT_ENVIRONMENT != production for no purposeful reason. Deliberate
     * departure from the "every CLI carries the trait" pattern; documented here so
     * a future maintainer doesn't reflexively add it.
     */
    class RehearsalController extends Controller
    {
        // INTENTIONALLY not importing the trait — see class docblock (D-22).

        public bool $verbose = false;

        public function options($actionID): array
        {
            return array_merge(parent::options($actionID), ['verbose']);
        }

        /**
         * Run the three rehearsal gates against a committed rehearsal directory.
         *
         * @param string $dir  Path to the rehearsal directory (e.g.
         *                     `.planning/rehearsal/v1.0/cqm`). Required files:
         *                     REPORT.md, VERIFY.md. Optional: allow-tokens.txt.
         */
        public function actionCheck(string $dir): int
        {
            $this->stdout("Rehearsal check: {$dir}\n", Console::FG_CYAN);

            if (!is_dir($dir)) {
                $this->stderr("FAIL: directory not found: {$dir}\n", Console::FG_RED);
                return 2;
            }

            $reportPath = rtrim($dir, '/') . '/REPORT.md';
            $verifyPath = rtrim($dir, '/') . '/VERIFY.md';
            $allowPath  = rtrim($dir, '/') . '/allow-tokens.txt';

            if (!is_file($reportPath)) {
                $this->stderr("FAIL: missing {$reportPath}\n", Console::FG_RED);
                return 2;
            }
            if (!is_file($verifyPath)) {
                $this->stderr("FAIL: missing {$verifyPath}\n", Console::FG_RED);
                return 2;
            }

            $reportMd = (string) file_get_contents($reportPath);
            $verifyMd = (string) file_get_contents($verifyPath);
            $allowed  = is_file($allowPath) ? self::parseAllowTokens((string) file_get_contents($allowPath)) : [];

            $failures = [];

            $g1 = self::parseCountMatchBlock($verifyMd);
            if ($g1 === []) {
                $this->stdout("  OK   Gate 1 — counts within tolerance\n", Console::FG_GREEN);
            } else {
                $this->stderr("  FAIL Gate 1 — counts within tolerance:\n", Console::FG_RED);
                foreach ($g1 as $f) {
                    $this->stderr("       - {$f}\n", Console::FG_RED);
                }
                $failures = array_merge($failures, $g1);
            }

            $tokens = self::findUnresolvedCkeditorTokens($reportMd, $allowed);
            if ($tokens === []) {
                $this->stdout("  OK   Gate 2 — zero unresolved CKEditor tokens\n", Console::FG_GREEN);
            } else {
                $this->stderr("  FAIL Gate 2 — unresolved CKEditor tokens (" . count($tokens) . "):\n", Console::FG_RED);
                foreach ($tokens as $t) {
                    $this->stderr("       - {$t}\n", Console::FG_RED);
                }
                $failures = array_merge($failures, array_map(static fn(string $t) => "Unresolved CKEditor token: {$t}", $tokens));
            }

            $g3 = self::parseAssetRcaTable($reportMd);
            if ($g3 === []) {
                $this->stdout("  OK   Gate 3 — all assets RCA-tagged\n", Console::FG_GREEN);
            } else {
                $this->stderr("  FAIL Gate 3 — asset RCA gaps:\n", Console::FG_RED);
                foreach ($g3 as $f) {
                    $this->stderr("       - {$f}\n", Console::FG_RED);
                }
                $failures = array_merge($failures, $g3);
            }

            if ($failures !== []) {
                $this->stderr(sprintf("\nFAIL: %d gate failure(s).\n", count($failures)), Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("\nAll three rehearsal gates passed.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        // -----------------------------------------------------------------
        //  Gate parsers — public static so tests can call directly.
        //  PATTERNS Shared Patterns "static-helper-extraction" — these were
        //  intentionally extracted to avoid Reflection-on-private noise in
        //  tests. The mechanical-gate signal lives entirely in pure functions.
        // -----------------------------------------------------------------

        /**
         * Gate 1 — parse the [1/2] Count-match gate block from VERIFY.md.
         * Returns failure messages (empty array = pass).
         *
         * @return list<string>
         */
        public static function parseCountMatchBlock(string $verifyMd): array
        {
            // Expected heading shape (Phase 4 / Plan 04 VerifyController output):
            //   "[1/2] Count-match gate" or "## [1/2] Count-match gate" — match either.
            $lines = preg_split('/\R/', $verifyMd) ?: [];
            $inBlock = false;
            $sawBlock = false;
            $failures = [];
            foreach ($lines as $line) {
                if (preg_match('/\[1\/2\][\s\-]*Count-match gate/i', $line)) {
                    $inBlock = true;
                    $sawBlock = true;
                    continue;
                }
                if ($inBlock) {
                    // Block ends at the next markdown heading or `[2/2]` marker.
                    if (preg_match('/^(#{1,6}\s|\[2\/2\])/', $line)) {
                        break;
                    }
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }
                    // Match status prefix: OK / SKIP / WARN / FAIL.
                    if (preg_match('/^(WARN|FAIL)\b\s*(.*)$/', $trimmed, $m)) {
                        $failures[] = "Count gate {$m[1]}: {$m[2]}";
                    }
                    // OK / SKIP / non-status lines: ignore (pass).
                }
            }
            if (!$sawBlock) {
                $failures[] = 'Count gate section not found in VERIFY.md';
            }
            return $failures;
        }

        /**
         * Gate 2 — find unresolved CKEditor token literals in REPORT.md.
         * Patterns: [NT<id>], [M<id>], asset:<n>. Allow-listed tokens excluded.
         *
         * @param list<string> $allowedTokens
         * @return list<string>  Distinct unresolved tokens, sorted.
         */
        public static function findUnresolvedCkeditorTokens(string $reportMd, array $allowedTokens = []): array
        {
            $found = [];
            // [NT123], [M456]
            if (preg_match_all('/\[(?:NT|M)\d+\]/', $reportMd, $m1)) {
                $found = array_merge($found, $m1[0]);
            }
            // asset:789 (followed by non-digit boundary)
            if (preg_match_all('/\basset:\d+\b/', $reportMd, $m2)) {
                $found = array_merge($found, $m2[0]);
            }
            $found = array_values(array_unique($found));
            sort($found);
            $allowed = array_flip($allowedTokens);
            return array_values(array_filter($found, static fn(string $t) => !isset($allowed[$t])));
        }

        /**
         * Gate 3 — parse `## Asset RCA` table from REPORT.md; return failure
         * messages for rows with empty reason cell. Empty section (placeholder
         * copy from Phase 4.1 / CFG-07) passes.
         *
         * @return list<string>
         */
        public static function parseAssetRcaTable(string $reportMd): array
        {
            $lines = preg_split('/\R/', $reportMd) ?: [];
            $failures = [];
            $inSection = false;
            $sawSection = false;
            $headerSeen = false;
            $separatorSeen = false;
            foreach ($lines as $line) {
                if (preg_match('/^##\s+Asset RCA\b/', $line)) {
                    $inSection = true;
                    $sawSection = true;
                    continue;
                }
                if ($inSection) {
                    // Section ends at next ## heading.
                    if (preg_match('/^##\s+/', $line)) {
                        break;
                    }
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '_')) {
                        // Empty line or italicized placeholder copy (CFG-07): not a row.
                        continue;
                    }
                    if (!str_starts_with($trimmed, '|')) {
                        continue;
                    }
                    if (!$headerSeen) {
                        $headerSeen = true;  // The | Asset | Reason | ... | header line.
                        continue;
                    }
                    if (!$separatorSeen) {
                        $separatorSeen = true;  // The |---|---|...| separator.
                        continue;
                    }
                    // Data row: split on |, trim each cell.
                    $cells = array_map('trim', array_slice(explode('|', $trimmed), 1, -1));
                    if (count($cells) < 2) {
                        continue;
                    }
                    $assetCell = $cells[0];
                    $reasonCell = $cells[1] ?? '';
                    if ($reasonCell === '') {
                        $failures[] = "Asset RCA row missing reason: {$assetCell}";
                    }
                }
            }
            if (!$sawSection) {
                $failures[] = 'Asset RCA section not found in REPORT.md';
            }
            return $failures;
        }

        /**
         * Parse <dir>/allow-tokens.txt. One token per line; lines starting with `#` are comments.
         *
         * @return list<string>
         */
        public static function parseAllowTokens(string $contents): array
        {
            $out = [];
            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                $out[] = $trimmed;
            }
            return $out;
        }
    }
    ```

    Lint:
    ```bash
    php -l src/console/RehearsalController.php
    ```
    Must print "No syntax errors detected".

    **1b. Create `tests/unit/console/RehearsalControllerTest.php`** with the ~14 test methods specified in `<behavior>`. Tests directly call the public static parsers (no Reflection needed). Embed fixture strings as PHP heredocs at the top of each test method.

    Class shell:
    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\tests\unit\console;

    use lameco\kunstmaanmigrator\console\RehearsalController;
    use PHPUnit\Framework\TestCase;

    /**
     * Phase 5 / TST-04 / D-21..D-22 — direct unit tests for the three rehearsal
     * gate parsers. The parsers are public static helpers on RehearsalController;
     * the tests call them directly without Reflection or Craft bootstrap.
     *
     * The actionCheck() integration path (read directory + run all three gates
     * + emit cyan/green/red console output) is exercised once end-to-end by the
     * v1.0 ship gate (Plan 05-08 RELEASE-CHECKLIST step 5: CQM rehearsal-check
     * exits 0). The unit-tier here covers the deterministic parsing surface.
     */
    final class RehearsalControllerTest extends TestCase
    {
        // ~14 test methods per <behavior>
    }
    ```

    Example test (the rest follow the same shape):
    ```php
    public function testParseCountMatchBlockAcceptsAllOk(): void
    {
        $verify = <<<MD
        # Verify report
        Some prose.

        ## [1/2] Count-match gate
        OK PageNode 142 (delta -1 within tolerance 5)
        OK NewsPage 36 (delta 0 within tolerance 5)
        SKIP ContactPage (no baseline)

        ## [2/2] URL diff gate
        ...
        MD;
        self::assertSame([], RehearsalController::parseCountMatchBlock($verify));
    }

    public function testParseCountMatchBlockFailsOnWarn(): void
    {
        $verify = <<<MD
        ## [1/2] Count-match gate
        WARN PageNode 142 (delta -7 outside tolerance 5)
        OK NewsPage 36 (delta 0 within tolerance 5)
        MD;
        $failures = RehearsalController::parseCountMatchBlock($verify);
        self::assertCount(1, $failures);
        self::assertStringContainsString('PageNode 142', $failures[0]);
        self::assertStringContainsString('outside tolerance', $failures[0]);
    }
    ```

    Run the test:
    ```bash
    composer test-unit -- --filter RehearsalControllerTest
    ```
    Must exit 0 with all ~14 tests passing.

    **1c. Verify auto-discovery via Plugin's controllerNamespace.** Run the action against a non-existent dir to confirm registration:

    ```bash
    ./craft kunstmaan-migrator/rehearsal/check /tmp/no-such-dir 2>&1 | head -3
    # Expected output: cyan "Rehearsal check: /tmp/no-such-dir" + red "FAIL: directory not found"
    # Exit code: 2
    ```

    If the action is not discovered (Yii returns "Unknown command"), inspect `src/Plugin.php` for `$controllerNamespace` and confirm it's set to `lameco\kunstmaanmigrator\console`. If not auto-set, manually add `$controllerNamespace = 'lameco\\kunstmaanmigrator\\console';` to the plugin's `init()` or to the controllerMap. Document the change in the SUMMARY.

    **DO NOT** add NeverProductionTrait to RehearsalController. **DO NOT** add a `use NeverProductionTrait;` line. The class docblock explicitly documents why; an executor adding it "for safety" is reverting D-22.
  </action>
  <verify>
    <automated>php -l src/console/RehearsalController.php | grep -q "No syntax errors" &amp;&amp; grep -c "NeverProductionTrait" src/console/RehearsalController.php | grep -q '^0$\|^1$' &amp;&amp; composer test-unit -- --filter RehearsalControllerTest 2>&amp;1 | tail -5 | grep -E "OK \(|Tests:"</automated>
  </verify>
  <acceptance_criteria>
    - `test -f src/console/RehearsalController.php` returns 0
    - `php -l src/console/RehearsalController.php` exits 0
    - `grep -c "use NeverProductionTrait;" src/console/RehearsalController.php` returns 0 (D-22: deliberate omission, NOT used)
    - `grep -c "NeverProductionTrait" src/console/RehearsalController.php` returns at most 1 (the docblock mention is OK; an actual `use` line is not — the previous criterion catches the use line)
    - `grep -c "public function actionCheck" src/console/RehearsalController.php` returns 1
    - `grep -c "public static function parseCountMatchBlock" src/console/RehearsalController.php` returns 1
    - `grep -c "public static function findUnresolvedCkeditorTokens" src/console/RehearsalController.php` returns 1
    - `grep -c "public static function parseAssetRcaTable" src/console/RehearsalController.php` returns 1
    - `grep -c "enforceNeverProduction" src/console/RehearsalController.php` returns 0 (does not call the gate)
    - `test -f tests/unit/console/RehearsalControllerTest.php` returns 0
    - `grep -c "final class RehearsalControllerTest" tests/unit/console/RehearsalControllerTest.php` returns 1
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit\\\\console" tests/unit/console/RehearsalControllerTest.php` returns 1
    - `grep -c "public function test" tests/unit/console/RehearsalControllerTest.php` returns at least 12 (target ~14, allow ±2)
    - `composer test-unit` exits 0; the new test file's count ≥ 12 added to the corpus
    - `composer test` exits 0
    - Auto-discovery smoke (best-effort; OK if Craft scratch site is not at hand): `./craft kunstmaan-migrator/rehearsal/check /tmp/no-such-dir` exits 2 with cyan+red output OR is documented in SUMMARY as untested-without-Craft-scratch
    - `git diff src/Plugin.php` is empty UNLESS the auto-discovery smoke required adding `$controllerNamespace` (in which case the diff is exactly that addition + a one-line comment)
  </acceptance_criteria>
  <done>RehearsalController ships with 3 mechanical gate parsers + ~14 unit tests + the deliberate trait omission documented. Plan 05-08 RELEASE-CHECKLIST step 5 can invoke `kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm` once the operator commits CQM artifacts.</done>
</task>

<task type="auto">
  <name>Task 2: Create .planning/rehearsal/v1.0/{cqm,simac,enreach}/README.md placeholders</name>
  <files>
    .planning/rehearsal/v1.0/cqm/README.md,
    .planning/rehearsal/v1.0/simac/README.md,
    .planning/rehearsal/v1.0/enreach/README.md
  </files>
  <read_first>
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-19, D-20, D-23 — directory shape, blocking-vs-advisory split, expected files)
    - .planning/phases/04.1-polish-recovery-and-env-defaults/ — operator artifact directory shape precedent
  </read_first>
  <action>
    Create the three rehearsal directories with operator-facing README placeholders. The README is the only file each dir ships in this plan; the operator commits real artifacts (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt, optional allow-tokens.txt) separately, typically in the same PR as the v1.0 tag.

    ```bash
    mkdir -p .planning/rehearsal/v1.0/cqm
    mkdir -p .planning/rehearsal/v1.0/simac
    mkdir -p .planning/rehearsal/v1.0/enreach
    ```

    **CQM README (`.planning/rehearsal/v1.0/cqm/README.md`):**

    ```markdown
    # CQM Rehearsal — v1.0 Ship Gate

    **Status:** BLOCKING. CQM `kunstmaan-migrator/rehearsal/check` exit 0 is the v1.0 tag gate (Phase 5 / D-19, D-23).

    ## Operator capture procedure

    Run the migration against ~/Sites/cqm-website/ on a dev host (not production — `NeverProductionTrait` enforces). Then commit these files into this directory:

    | File | Source | Purpose |
    |---|---|---|
    | `REPORT.md` | `storage/migration/REPORT.md` (after `migrate --live`) | Rehearsal summary + skipped stages + asset RCA (Phase 4 / Plan 12 + Phase 4.1 / CFG-07) |
    | `VERIFY.md` | `storage/migration/VERIFY-<ts>.md` (after `verify`; drop the timestamp suffix) | Count-match gate + URL diff gate output (Phase 4 / Plan 04) |
    | `baseline.json` | `storage/migration/baseline.json` (after `verify capture-baseline`) | Light entity-count snapshot |
    | `doctor-output.txt` | Captured stdout/stderr of `./craft kunstmaan-migrator/doctor` | All 10 doctor checks passing |
    | `mapping-summary.txt` | Counts of accepted/dropped/needs-review/proposed rows from CQM's `mapping.yaml` | Operator-side script |
    | `allow-tokens.txt` | Optional, operator-curated | One CKEditor token literal per line; `#` comments OK |

    ## Mechanical gate

    Once the artifacts are committed:

    ```bash
    ./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm
    ```

    Exit 0 = all three gates pass:
    1. Counts within tolerance — every line in VERIFY.md `[1/2] Count-match gate` is OK or SKIP
    2. Zero unresolved CKEditor tokens — no `[NT<id>]` / `[M<id>]` / `asset:<n>` in REPORT.md unless allow-listed
    3. All assets RCA-tagged — every row in REPORT.md `## Asset RCA` has a non-empty reason

    Exit 1 = at least one gate failed (per-gate failure summary on stderr).
    Exit 2 = directory or required file missing.

    ## Privacy note

    CQM data ships verbatim — no anonymization (Phase 5 / D-04). This is acceptable while the repo stays under `lameco/`. If the repo ever goes public, run a scrub pass on these files first (RELEASE-CHECKLIST.md flags this as a pre-publish gate, not a v1.0 ship gate).
    ```

    **Simac README (`.planning/rehearsal/v1.0/simac/README.md`):**

    ```markdown
    # Simac Rehearsal — Advisory Only

    **Status:** ADVISORY. Failures here do NOT block the v1.0 tag (Phase 5 / D-19). Captured for cross-client matrix signal; informs Phase 5.1 / NEXT-04 if cross-client correctness blocks adoption.

    ## Operator capture procedure

    Same shape as `.planning/rehearsal/v1.0/cqm/README.md`, but against ~/Sites/simac-website/ (multi-locale corpus).

    Required files: `REPORT.md`, `VERIFY.md`, `baseline.json`, `doctor-output.txt`, `mapping-summary.txt`. Optional: `allow-tokens.txt`.

    ## Mechanical gate

    ```bash
    ./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/simac
    ```

    Exit 1 here = a Phase 5.1 / NEXT-04 input. Document the failure mode in the v1.0 RELEASE-CHECKLIST.md "Simac advisory" section, but do not block the tag.
    ```

    **Enreach README (`.planning/rehearsal/v1.0/enreach/README.md`):**

    ```markdown
    # Enreach Rehearsal — Advisory Only

    **Status:** ADVISORY. Failures here do NOT block the v1.0 tag (Phase 5 / D-19). 7-locale stress target — captured for cross-client matrix signal.

    ## Operator capture procedure

    Same shape as `.planning/rehearsal/v1.0/cqm/README.md`, but against ~/Sites/enreach-website/.

    Required files: `REPORT.md`, `VERIFY.md`, `baseline.json`, `doctor-output.txt`, `mapping-summary.txt`. Optional: `allow-tokens.txt`.

    ## Mechanical gate

    ```bash
    ./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/enreach
    ```

    Exit 1 here = a Phase 5.1 / NEXT-04 input.
    ```

    Verify directory shape:
    ```bash
    find .planning/rehearsal/ -type f | sort
    # Expected:
    #   .planning/rehearsal/v1.0/cqm/README.md
    #   .planning/rehearsal/v1.0/enreach/README.md
    #   .planning/rehearsal/v1.0/simac/README.md
    ```

    Do NOT commit empty REPORT.md / VERIFY.md / baseline.json placeholders. The operator captures real ones; ghost files would cause `rehearsal-check` to misreport (e.g. empty VERIFY.md fails Gate 1 with "Count gate section not found"). If a `.gitkeep` is needed for any reason, prefer the `README.md` to be the keep-file.
  </action>
  <verify>
    <automated>test -f .planning/rehearsal/v1.0/cqm/README.md &amp;&amp; test -f .planning/rehearsal/v1.0/simac/README.md &amp;&amp; test -f .planning/rehearsal/v1.0/enreach/README.md &amp;&amp; grep -q "BLOCKING" .planning/rehearsal/v1.0/cqm/README.md &amp;&amp; grep -q "ADVISORY" .planning/rehearsal/v1.0/simac/README.md</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .planning/rehearsal/v1.0/cqm/README.md` returns 0
    - `test -f .planning/rehearsal/v1.0/simac/README.md` returns 0
    - `test -f .planning/rehearsal/v1.0/enreach/README.md` returns 0
    - `grep -c "BLOCKING" .planning/rehearsal/v1.0/cqm/README.md` returns at least 1 (CQM is the ship-gate)
    - `grep -c "ADVISORY" .planning/rehearsal/v1.0/simac/README.md` returns at least 1
    - `grep -c "ADVISORY" .planning/rehearsal/v1.0/enreach/README.md` returns at least 1
    - `grep -c "kunstmaan-migrator/rehearsal/check" .planning/rehearsal/v1.0/cqm/README.md` returns at least 1
    - `grep -c "REPORT.md" .planning/rehearsal/v1.0/cqm/README.md` returns at least 1
    - `grep -c "VERIFY.md" .planning/rehearsal/v1.0/cqm/README.md` returns at least 1
    - `grep -c "baseline.json" .planning/rehearsal/v1.0/cqm/README.md` returns at least 1
    - `grep -c "allow-tokens.txt" .planning/rehearsal/v1.0/cqm/README.md` returns at least 1
    - `find .planning/rehearsal/v1.0/ -name 'REPORT.md' -o -name 'VERIFY.md' -o -name 'baseline.json' | wc -l` returns 0 (no ghost placeholder artifacts — only operator-captured real ones go in)
  </acceptance_criteria>
  <done>Three rehearsal directories committed with operator-facing instructions. Operator captures real artifacts later (typically v1.0 tag PR). Plan 05-08 RELEASE-CHECKLIST step 5 references these dirs.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| committed REPORT.md/VERIFY.md → RehearsalController parsers | Read-only file access; no DB / no source path |
| operator host → committed rehearsal artifacts | Operator captures on dev host; commits files; `NeverProductionTrait` is irrelevant since the controller never re-runs migration |
| CI → rehearsal-check exit code | `rehearsal-check` exit 0 gates v1.0 tag (CQM); advisory for simac/enreach |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-04-01 | Spoofing | RehearsalController bypasses NeverProductionTrait | mitigate | D-22 deliberate omission documented in class docblock + this plan's must_haves. The controller is read-only over committed artifacts; never opens legacy DB / source path / state table. Adding the trait would gate a CI step on CRAFT_ENVIRONMENT for no purposeful reason. PATTERNS callout 6. |
| T-05-04-02 | Tampering | committed VERIFY.md modified to hide failures | accept | Reviewer-side concern. CI commits flow through PR review; operator doesn't post-edit committed VERIFY.md. If discovered post-hoc, baseline.json + doctor-output.txt cross-reference catches it. |
| T-05-04-03 | Information Disclosure | rehearsal artifacts contain CQM data verbatim | accept | D-04: repo is private. Pre-publish scrub flagged in RELEASE-CHECKLIST.md if repo ever opens up. |
| T-05-04-04 | Repudiation | gate parser flake on heading variations | mitigate | Regex tolerates `[1/2] Count-match gate` with or without `## ` heading prefix; `## Asset RCA` requires the `##` prefix (matches Phase 4 / Plan 12 emission shape). 14 unit tests cover the surface. |
| T-05-04-05 | Denial of Service | huge REPORT.md timing | accept | REPORT.md is ETL-output sized (kilobytes), not gigabytes. preg_match_all is linear; sub-second for any plausible REPORT. |
| T-05-04-06 | Elevation of Privilege | rehearsal-check on production | mitigate | Even though the trait is omitted (D-22), the controller has zero side effects on Craft state — read-only file access. Running it on production is harmless (just reads committed `.planning/rehearsal/...` paths). |
</threat_model>

<verification>
- `composer test` exits 0
- `composer test-unit` exits 0; new RehearsalControllerTest passes ≥12 cases
- `php -l src/console/RehearsalController.php` exits 0
- `grep -c "use NeverProductionTrait;" src/console/RehearsalController.php` returns 0 (D-22 verified)
- `find .planning/rehearsal/ -type f -name 'README.md' | wc -l` returns 3
- `git diff src/Plugin.php` empty (auto-discovery works without manual controllerMap edit) OR shows only a controllerNamespace addition with comment
</verification>

<success_criteria>
- D-19: CQM rehearsal-check is the v1.0 ship gate; simac/enreach advisory — codified in README placeholders
- D-20: Directory shape committed at `.planning/rehearsal/v1.0/{cqm,simac,enreach}/`
- D-21: Three mechanical gates implemented as public static parsers
- D-22: NeverProductionTrait deliberately omitted; documented in class docblock
- D-23: New controller (RehearsalController), not added to MigrateController (avoids 1700+ LOC controller bloat — PATTERNS callout 6)
- D-24: Rehearsal capture stays manual (operator on dev host); CI verifies committed artifacts only
- Plan 05-08 RELEASE-CHECKLIST.md step 5 references this controller's exit code as the v1.0 tag gate
</success_criteria>

<output>
After completion, create `.planning/phases/05-tests-rehearsal-release/05-04-SUMMARY.md` documenting:
- Final test count for RehearsalControllerTest (target ~14)
- Whether `./craft kunstmaan-migrator/rehearsal/check` smoke worked against `/tmp/no-such-dir` returning exit 2 (proof of auto-discovery), or whether `$controllerNamespace` had to be set manually in `src/Plugin.php`
- Confirmation D-22 omission grep-check passed (0 matches for `use NeverProductionTrait;` in RehearsalController.php)
- Phase 5 / Plan 05-08 carry-over: operator must capture CQM artifacts before v1.0 tag (RELEASE-CHECKLIST step 5)
</output>
