---
phase: 05
plan: 02
type: execute
wave: 2
depends_on: ["05-01"]
files_modified:
  - phpunit.xml.dist
  - composer.json
  - tools/check-coverage.php
autonomous: true
requirements: [TST-01]
must_haves:
  truths:
    - "phpunit.xml.dist has TWO <testsuite> blocks: 'Unit' (tests/unit) and 'Integration' (tests/integration)"
    - "phpunit.xml.dist has a <source> block scoping coverage to the 5 TST-01 modules verbatim (D-08)"
    - "phpunit.xml.dist has a <coverage> block emitting clover XML at build/coverage/clover.xml"
    - "composer.json scripts has 'test', 'test-unit', 'test-integration', 'test-coverage'"
    - "composer test-coverage fails fast (non-zero exit) when neither pcov nor xdebug is loaded — explicit message 'install pcov or xdebug to run coverage'"
    - "tools/check-coverage.php exists, parses build/coverage/clover.xml, exits 1 if any of the 5 TST-01 modules drops below 70.0% line coverage; exits 0 when all pass"
    - "tools/check-coverage.php auto-enrolls every file under src/fields/handlers/ (so future handler additions auto-gate)"
    - "Per-module gate, NOT aggregate (D-07 / TST-01 wording: '70% line coverage on those modules')"
    - "Composer chained-script syntax — test-coverage runs the driver-check step first; subsequent steps run only if it succeeds"
    - "PCOV is NOT added to composer.json require-dev (PATTERNS risk callout #3 — system extension installed via shivammathur/setup-php in CI; locals install via pecl)"
  artifacts:
    - path: "phpunit.xml.dist"
      provides: "Two testsuites (Unit + Integration) + <source> coverage scope on the 5 TST-01 modules + <coverage> clover output to build/coverage/clover.xml"
      contains: "<testsuite name=\"Integration\">"
    - path: "composer.json"
      provides: "scripts.test-unit, scripts.test-integration, scripts.test-coverage (chained-script with driver fail-fast)"
      contains: "test-coverage"
    - path: "tools/check-coverage.php"
      provides: "Per-module 70% line-coverage gate; reads build/coverage/clover.xml; exits 0 on pass, 1 on any module under threshold, 2 on missing/unparseable clover"
      contains: "THRESHOLD = 70.0"
  key_links:
    - from: "composer test-coverage (composer.json)"
      to: "tools/check-coverage.php"
      via: "@php tools/check-coverage.php (chained-script step 3)"
      pattern: "tools/check-coverage.php"
    - from: "tools/check-coverage.php"
      to: "build/coverage/clover.xml"
      via: "simplexml_load_file"
      pattern: "simplexml_load_file"
    - from: "phpunit.xml.dist <coverage>"
      to: "build/coverage/clover.xml"
      via: "<clover outputFile=...>"
      pattern: "build/coverage/clover.xml"
---

<objective>
**D-13 + D-06 + D-07 + D-08 — PHPUnit infrastructure for the v1.0 ship gate.**

Three deliverables that travel together because they share the same two config files (phpunit.xml.dist + composer.json), so they MUST land in one plan to avoid file-conflict thrash:

1. **PHPUnit testsuite split (D-13).** Add the `Integration` testsuite (path `tests/integration`); rename the per-suite invocations under composer scripts (`test-unit`, `test-integration`); the bare `composer test` keeps running both suites.

2. **Coverage scoping + driver (D-06, D-08).** PHPUnit 11 `<source>` block scopes coverage to the five TST-01 modules verbatim (`src/filter/MigrationFilters.php`, `src/mapping/MappingFile.php`, `src/finalize/CkeditorRewriterService.php`, `src/analyze/HeuristicProposer.php`, `src/fields/handlers/`). `<coverage>` block emits clover XML to `build/coverage/clover.xml`. PCOV is the CI driver (installed via `shivammathur/setup-php` in 05-07; not a composer dep).

3. **Per-module 70% gate (D-07).** New `tools/check-coverage.php` parses clover XML and exits non-zero when any of the 5 modules drops below 70%. Per-module, not aggregate. `composer test-coverage` chains: pcov-or-xdebug fail-fast → phpunit clover run → check-coverage.

This is Wave 2 because it depends on 05-01's `tests/unit/` + `tests/integration/` directories existing. Plans 05-05, 05-06, 05-07 in Wave 3 all consume `composer test-coverage` and the per-module gate.

CONTEXT.md `## Risks` paragraph 1: run `composer test-coverage` once on `main` after this plan lands to baseline current coverage on the 5 modules. Plans 05-05 and 05-06 use that baseline to bias their D-10 gap-fill toward modules currently under 70%.

Output:
- `phpunit.xml.dist` with 2 testsuites + `<source>` + `<coverage>` blocks
- `composer.json` with 3 new scripts (`test-unit`, `test-integration`, `test-coverage` chained-script)
- `tools/check-coverage.php` (~50 LOC; pure PHP `simplexml_load_file`; no library)
- The phpunit.xml.dist comment from 05-01 ("Integration testsuite added in 05-02") removed
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
<!-- PHPUnit 11 coverage configuration (verified against PHPUnit 11.x XSD): -->
<!--   The PHPUnit 9 <filter><whitelist> shape is REMOVED in PHPUnit 11. -->
<!--   The replacement is a top-level <source> element with <include> children. -->
<!--   Coverage REPORT format is configured under <coverage><report>...</report></coverage>. -->
<!--   Both shapes are documented in the PATTERNS.md MOD: phpunit.xml.dist section, lines 122-148. -->

<!-- Composer chained-script syntax: -->
<!--   "scripts": { "name": ["@php -r '...'", "vendor/bin/phpunit ...", "@php tools/check-coverage.php"] } -->
<!--   Each item runs sequentially. If any returns non-zero, the chain aborts. -->
<!--   `@php` is composer's php-runner alias (handles `php` command discovery). -->

<!-- Clover XML structure (per phpunit/phpunit clover format, verified pattern from PCOV/Xdebug output): -->
<!--   <coverage>
<!--     <project>
<!--       <file name="/abs/path/to/Foo.php">
<!--         <metrics statements="N" coveredstatements="M" .../>
<!--       </file>
<!--     </project>
<!--   </coverage>
<!--   The metrics children carry @statements (loc-of-code) and @coveredstatements; ratio = coveredstatements / statements. -->
</interfaces>

<reference_files>
- ~/Sites/craft-kunstmaan-migrator/phpunit.xml — v1.x phpunit config (no coverage block; v1 had no test corpus); not a useful analog
- .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md sections "MOD: phpunit.xml.dist", "MOD: composer.json", "NEW: tools/check-coverage.php" (lines 105-176, 510-573)
</reference_files>
</context>

<tasks>

<task type="auto">
  <name>Task 1: phpunit.xml.dist — split into 2 testsuites + add &lt;source&gt; + add &lt;coverage&gt; block</name>
  <files>
    phpunit.xml.dist
  </files>
  <read_first>
    - phpunit.xml.dist (whole file — post-05-01 shape: single Unit testsuite at tests/unit; the placeholder comment "Integration testsuite added in Plan 05-02 (D-13)" sits above &lt;testsuites&gt; and is removed in this task)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (MOD: phpunit.xml.dist section, lines 105-148 — verbatim target shape)
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-08 — the verbatim 5-module list for the &lt;source&gt; block)
  </read_first>
  <action>
    Replace the body of `phpunit.xml.dist` with this exact content. Preserve the existing top-level attributes (`bootstrap`, `colors`, `testdox`, `cacheDirectory`, `requireCoverageMetadata`) verbatim. Remove the placeholder gap-comment from 05-01.

    ```xml
    <?xml version="1.0" encoding="UTF-8"?>
    <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
             xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
             bootstrap="tests/bootstrap.php"
             colors="true"
             testdox="true"
             cacheDirectory=".phpunit.cache"
             requireCoverageMetadata="false">
        <testsuites>
            <testsuite name="Unit">
                <directory>tests/unit</directory>
            </testsuite>
            <testsuite name="Integration">
                <directory>tests/integration</directory>
            </testsuite>
        </testsuites>
        <source>
            <include>
                <file>src/filter/MigrationFilters.php</file>
                <file>src/mapping/MappingFile.php</file>
                <file>src/finalize/CkeditorRewriterService.php</file>
                <file>src/analyze/HeuristicProposer.php</file>
                <directory>src/fields/handlers</directory>
            </include>
        </source>
        <coverage>
            <report>
                <clover outputFile="build/coverage/clover.xml"/>
            </report>
        </coverage>
    </phpunit>
    ```

    Notes:
    - The 5 paths in `<source><include>` are verbatim from CONTEXT D-08. `MappingLoader` from TST-01 wording is the same as `MappingFile.php` (CONTEXT `## Specific Ideas` first bullet); only `MappingFile.php` is included.
    - `<directory>src/fields/handlers</directory>` auto-enrolls every PHP file under that path, including future handler additions (matches `tools/check-coverage.php`'s prefix check in Task 3).
    - The PHPUnit 11 way to scope coverage is `<source>`, NOT `<filter><whitelist>` (deprecated and removed in PHPUnit 10+). PATTERNS callout: "<source> is the PHPUnit 11 way to scope coverage (replaces <filter><whitelist> from PHPUnit 9)."
    - The `<coverage>` block has only `<report><clover .../></report>` — no `<html>` or `<text>` reports needed for v1.0 (D-09 marks those planner-discretion; not load-bearing).
    - `requireCoverageMetadata="false"` stays — true would force every test class to declare `@covers` annotations, which the existing 179-test corpus doesn't.

    After editing, validate the XML:
    ```bash
    xmllint --noout phpunit.xml.dist && echo "OK XML valid" || echo "FAIL XML invalid"
    ```

    Do NOT add `<php>` elements, env-var configuration, or `<extensions>` — the existing bootstrap covers everything the suites need. Do NOT add `failOnRisky` / `failOnWarning` — surfacing those is a follow-up if needed.
  </action>
  <verify>
    <automated>xmllint --noout phpunit.xml.dist &amp;&amp; grep -c '<testsuite name=' phpunit.xml.dist | grep -q '^2$' &amp;&amp; grep -q '<source>' phpunit.xml.dist &amp;&amp; grep -q 'build/coverage/clover.xml' phpunit.xml.dist</automated>
  </verify>
  <acceptance_criteria>
    - `xmllint --noout phpunit.xml.dist` exits 0 (XML well-formed)
    - `grep -c '<testsuite name=' phpunit.xml.dist` returns 2
    - `grep -c 'name="Unit"' phpunit.xml.dist` returns 1
    - `grep -c 'name="Integration"' phpunit.xml.dist` returns 1
    - `grep -c '<directory>tests/unit</directory>' phpunit.xml.dist` returns 1
    - `grep -c '<directory>tests/integration</directory>' phpunit.xml.dist` returns 1
    - `grep -c '<source>' phpunit.xml.dist` returns 1
    - `grep -c 'src/filter/MigrationFilters.php' phpunit.xml.dist` returns 1
    - `grep -c 'src/mapping/MappingFile.php' phpunit.xml.dist` returns 1
    - `grep -c 'src/finalize/CkeditorRewriterService.php' phpunit.xml.dist` returns 1
    - `grep -c 'src/analyze/HeuristicProposer.php' phpunit.xml.dist` returns 1
    - `grep -c '<directory>src/fields/handlers</directory>' phpunit.xml.dist` returns 1
    - `grep -c '<coverage>' phpunit.xml.dist` returns 1
    - `grep -c 'build/coverage/clover.xml' phpunit.xml.dist` returns 1
    - `grep -c 'Integration testsuite added in Plan 05-02' phpunit.xml.dist` returns 0 (the 05-01 placeholder comment is removed)
    - `composer test` exits 0 (suites + coverage scope don't break the existing corpus; coverage report only generates when `--coverage-clover` flag is passed by `test-coverage` script — verified in Task 2)
  </acceptance_criteria>
  <done>phpunit.xml.dist carries the two-suite shape + coverage scope. Composer scripts and check-coverage tool are next.</done>
</task>

<task type="auto">
  <name>Task 2: composer.json — add test-unit, test-integration, test-coverage scripts (chained, with driver fail-fast)</name>
  <files>
    composer.json
  </files>
  <read_first>
    - composer.json (whole file — current `scripts` block at lines 38-40 has only `"test": "vendor/bin/phpunit"`)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (MOD: composer.json section, lines 150-176 — verbatim target shape)
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-06 — PCOV in CI; locals install via pecl; D-13 per-suite scripts; ## Risks paragraph 6 — the pcov-or-xdebug fail-fast)
  </read_first>
  <action>
    Replace the `scripts` block in `composer.json` (currently at lines 38-40) with the expanded form. Preserve all other blocks (require, require-dev, autoload, autoload-dev, extra, config) verbatim.

    Target `scripts` block:
    ```json
        "scripts": {
            "test": "vendor/bin/phpunit",
            "test-unit": "vendor/bin/phpunit --testsuite Unit",
            "test-integration": "vendor/bin/phpunit --testsuite Integration",
            "test-coverage": [
                "@php -r \"if (!extension_loaded('pcov') && !extension_loaded('xdebug')) { fwrite(STDERR, 'install pcov or xdebug to run coverage' . PHP_EOL); exit(1); }\"",
                "vendor/bin/phpunit --coverage-clover=build/coverage/clover.xml",
                "@php tools/check-coverage.php"
            ]
        }
    ```

    Notes:
    - `test` stays unchanged — runs both suites by default (no `--testsuite` arg picks up every `<testsuite>` in phpunit.xml.dist).
    - `test-unit` / `test-integration` are convenience scripts; CI uses `composer test` to run both at once.
    - `test-coverage` is a 3-step chained script:
      1. **PCOV-or-Xdebug fail-fast** (CONTEXT ## Risks paragraph 6): if neither extension is loaded, write `install pcov or xdebug to run coverage` to stderr and exit 1. Without this, `phpunit --coverage-clover` would silently emit an empty clover (or fail with cryptic message), and `tools/check-coverage.php` would fail in step 3 with "modules not found" — confusing the operator.
      2. **PHPUnit with clover output** to `build/coverage/clover.xml` (matches the `<coverage>` block in phpunit.xml.dist). The CLI flag is redundant with the config but keeps the script self-describing for anyone reading composer.json.
      3. **`tools/check-coverage.php`** — the per-module 70% gate (Task 3 below).
    - Composer chained-script semantics: each item runs sequentially; if any returns non-zero, the chain aborts immediately. So failed step-1 skips steps 2-3.
    - **No new `require-dev` package.** PCOV is a system extension. PATTERNS risk callout #3: "PCOV is NOT in composer.json require-dev today — verified — only phpunit/phpunit: ^11.0. The planner should NOT add PCOV to require-dev."

    Validate composer.json:
    ```bash
    composer validate --strict --no-plugins && echo "OK valid" || echo "FAIL composer validate"
    ```

    Run a smoke check (does NOT require pcov/xdebug to be installed locally — just confirms the script entries parse):
    ```bash
    composer test --dry-run 2>&1 | head -3   # should announce running phpunit
    composer test-unit --dry-run 2>&1 | head -3   # should announce phpunit --testsuite Unit
    composer test-integration --dry-run 2>&1 | head -3   # should announce phpunit --testsuite Integration
    ```

    Note `--dry-run` is not a real composer-script flag; this command just exercises the script parse path. The real validation is the per-script grep in acceptance criteria.

    Real coverage smoke (run only if pcov or xdebug is installed locally — operator picks):
    ```bash
    composer test-coverage 2>&1 | tail -10
    ```
    Without a coverage driver, this MUST exit 1 with stderr containing `install pcov or xdebug to run coverage`. With one of those drivers installed, this runs end-to-end and exits 0 OR 1 depending on whether the current corpus already meets per-module 70%.

    Do NOT manually create `build/coverage/`; PHPUnit creates it on demand. Do NOT add `build/` to .gitignore in this plan — that's a noise-free housekeeping item the operator addresses separately if needed.
  </action>
  <verify>
    <automated>composer validate --strict --no-plugins 2>&amp;1 | grep -E "valid|is valid" &amp;&amp; grep -c '"test-unit"' composer.json | grep -q '^1$' &amp;&amp; grep -c '"test-integration"' composer.json | grep -q '^1$' &amp;&amp; grep -c '"test-coverage"' composer.json | grep -q '^1$' &amp;&amp; grep -c 'install pcov or xdebug to run coverage' composer.json | grep -q '^1$'</automated>
  </verify>
  <acceptance_criteria>
    - `composer validate --strict --no-plugins` exits 0
    - `grep -c '"test-unit"' composer.json` returns 1
    - `grep -c '"test-integration"' composer.json` returns 1
    - `grep -c '"test-coverage"' composer.json` returns 1
    - `grep -c '"test": "vendor/bin/phpunit"' composer.json` returns 1 (default `test` preserved)
    - `grep -c 'install pcov or xdebug to run coverage' composer.json` returns 1 (driver fail-fast message present)
    - `grep -c 'tools/check-coverage.php' composer.json` returns 1 (chained-script step 3 wired)
    - `grep -c 'build/coverage/clover.xml' composer.json` returns 1 (matches phpunit.xml.dist target)
    - `grep -c '"pcov"' composer.json` returns 0 (PCOV NOT in require-dev — PATTERNS risk callout #3)
    - `composer test` still exits 0 (regression check — the `test` script is unchanged in behavior)
    - `composer test-unit` exits 0 (Unit suite still passes; tests/unit/* corpus from 05-01)
    - `composer test-integration` exits 0 (Integration suite — currently only `tests/integration/PluginBootstrapTest.php`; if this file's existing tests previously passed under the old single-suite, they pass here too)
  </acceptance_criteria>
  <done>Three new composer scripts live. PCOV-or-Xdebug fail-fast guards the coverage path. tools/check-coverage.php is the only piece left.</done>
</task>

<task type="auto">
  <name>Task 3: tools/check-coverage.php — per-module 70% line-coverage gate</name>
  <files>
    tools/check-coverage.php
  </files>
  <read_first>
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: tools/check-coverage.php section, lines 510-573 — verbatim target script)
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-07 — per-module not aggregate; D-08 — the 5 modules verbatim)
  </read_first>
  <action>
    Create `tools/check-coverage.php` with the exact content below. The directory `tools/` does NOT exist yet (PATTERNS risk callout #5), so create it first:

    ```bash
    mkdir -p tools
    ```

    Then write `tools/check-coverage.php`:

    ```php
    #!/usr/bin/env php
    <?php
    // tools/check-coverage.php
    // Phase 5 / TST-01 / D-07 — per-module 70% line-coverage gate.
    // Reads build/coverage/clover.xml; exits non-zero if ANY of the 5 named
    // modules drops below 70%. Per-module, NOT aggregate (TST-01 wording).
    //
    // Invoked as the final step of `composer test-coverage`.
    // Exit codes: 0 = all modules ≥ 70%, 1 = at least one module under threshold,
    //             2 = clover.xml missing or unparseable.

    declare(strict_types=1);

    const THRESHOLD = 70.0;
    const MODULES = [
        'src/filter/MigrationFilters.php',
        'src/mapping/MappingFile.php',
        'src/finalize/CkeditorRewriterService.php',
        'src/analyze/HeuristicProposer.php',
        // src/fields/handlers/ — every .php under this directory auto-enrolls
        // via the str_starts_with check below.
    ];
    const HANDLERS_PREFIX = 'src/fields/handlers/';

    $cloverPath = __DIR__ . '/../build/coverage/clover.xml';
    if (!is_file($cloverPath)) {
        fwrite(STDERR, "FAIL: clover.xml not found at {$cloverPath}\n");
        fwrite(STDERR, "  Run `composer test-coverage` first; it generates the clover XML before invoking this script.\n");
        exit(2);
    }
    $xml = @simplexml_load_file($cloverPath);
    if ($xml === false) {
        fwrite(STDERR, "FAIL: could not parse {$cloverPath}\n");
        exit(2);
    }

    $repoRoot = realpath(__DIR__ . '/..');
    $failures = [];
    $rowsPrinted = 0;

    foreach ($xml->project->file as $file) {
        $absPath = (string) $file['name'];
        $rel = $absPath;
        if ($repoRoot !== false && str_starts_with($absPath, $repoRoot . '/')) {
            $rel = substr($absPath, strlen($repoRoot) + 1);
        }
        $isModule = in_array($rel, MODULES, true) || str_starts_with($rel, HANDLERS_PREFIX);
        if (!$isModule) {
            continue;
        }

        $metrics = $file->metrics;
        $statements = (int) $metrics['statements'];
        $covered    = (int) $metrics['coveredstatements'];
        $pct        = $statements === 0 ? 100.0 : ($covered / $statements) * 100.0;
        $marker     = $pct >= THRESHOLD ? 'OK  ' : 'FAIL';
        fwrite(STDOUT, sprintf("  %s %5.1f%%  %s\n", $marker, $pct, $rel));
        $rowsPrinted++;
        if ($pct < THRESHOLD) {
            $failures[] = sprintf('%s: %.1f%% < %.1f%%', $rel, $pct, THRESHOLD);
        }
    }

    if ($rowsPrinted === 0) {
        fwrite(STDERR, "FAIL: no TST-01 modules found in {$cloverPath}\n");
        fwrite(STDERR, "  Verify phpunit.xml.dist <source><include> matches the module paths in this script.\n");
        exit(2);
    }

    if ($failures !== []) {
        fwrite(STDERR, "\nCoverage gate FAILED:\n  - " . implode("\n  - ", $failures) . "\n");
        fwrite(STDERR, "  Per-module threshold (TST-01 / D-07): " . THRESHOLD . "%\n");
        exit(1);
    }
    fwrite(STDOUT, "\nCoverage gate OK — all modules ≥ " . THRESHOLD . "%\n");
    exit(0);
    ```

    Notes:
    - **THRESHOLD = 70.0** (D-07 wording matches TST-01 "70% line coverage").
    - **MODULES list** is the 5-module set verbatim from D-08 minus the directory entry. The `HANDLERS_PREFIX` constant covers the `src/fields/handlers/` directory and auto-enrolls future handlers (e.g. when a new handler ships, no edit to this script needed).
    - **Per-module, not aggregate.** Each file is checked independently; one failure → script fails. This matches TST-01 wording ("70% line coverage on those modules").
    - **Exit codes**: 0 = all pass, 1 = at least one module under, 2 = clover missing/unparseable. The composer chained-script semantic on a non-zero exit is "abort the chain"; for a CI step invoked via `composer test-coverage`, all three exit codes propagate as build failures (which is correct).
    - **Output format**: human-readable table with `OK` / `FAIL` markers + percentage + path. Mirrors the doctor output style (Phase 4 / D-69) — green markers on stdout, failures on stderr.
    - **`rowsPrinted === 0` guard**: the only way zero TST-01 files appear in clover is if the `<source><include>` path list in phpunit.xml.dist drifts from the MODULES list here. Surfacing this as a fatal exit-2 prevents silent passes when the gate is mis-wired (e.g. someone removes a `<file>` from phpunit.xml.dist without updating this script).
    - **No external library.** PHP's `simplexml_load_file` handles clover XML cleanly; ~50 LOC total (verified: this script is 60 LOC including comments and the new `rowsPrinted` guard).
    - **Make executable**: `chmod +x tools/check-coverage.php` is OPTIONAL — composer's `@php tools/check-coverage.php` runs it via the PHP interpreter regardless. If you do chmod, the shebang at line 1 makes it directly invokable.

    Smoke test (only if pcov or xdebug is installed locally; otherwise `composer test-coverage` exits 1 in step 1 before reaching this script):
    ```bash
    composer test-coverage 2>&1 | tail -25
    # Expected: 5+ rows of "OK XX.X%  src/..." OR "FAIL XX.X%  src/..."
    # Followed by either "Coverage gate OK" (exit 0) or "Coverage gate FAILED" (exit 1).
    ```

    The smoke also serves as the BASELINE coverage measurement (CONTEXT ## Risks paragraph 1) that Plans 05-05 and 05-06 read to bias their D-10 gap-fill priorities. If `composer test-coverage` reports e.g. `HeuristicProposer.php: 45.2%`, that file gets first attention in Plan 05-05. Capture the output snapshot in this plan's SUMMARY.

    Lint the file:
    ```bash
    php -l tools/check-coverage.php
    ```
    Must print `No syntax errors detected`.
  </action>
  <verify>
    <automated>php -l tools/check-coverage.php | grep -q "No syntax errors" &amp;&amp; grep -c "THRESHOLD = 70.0" tools/check-coverage.php | grep -q '^1$' &amp;&amp; grep -c "src/filter/MigrationFilters.php" tools/check-coverage.php | grep -q '^1$' &amp;&amp; grep -c "src/fields/handlers/" tools/check-coverage.php | grep -q '^1$' &amp;&amp; grep -c "simplexml_load_file" tools/check-coverage.php | grep -q '^1$'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tools/check-coverage.php` returns 0
    - `php -l tools/check-coverage.php` exits 0 (no syntax errors)
    - `grep -c "THRESHOLD = 70.0" tools/check-coverage.php` returns 1
    - `grep -c "src/filter/MigrationFilters.php" tools/check-coverage.php` returns 1
    - `grep -c "src/mapping/MappingFile.php" tools/check-coverage.php` returns 1
    - `grep -c "src/finalize/CkeditorRewriterService.php" tools/check-coverage.php` returns 1
    - `grep -c "src/analyze/HeuristicProposer.php" tools/check-coverage.php` returns 1
    - `grep -c "src/fields/handlers/" tools/check-coverage.php` returns 1 (HANDLERS_PREFIX constant present)
    - `grep -c "simplexml_load_file" tools/check-coverage.php` returns 1
    - `grep -c "exit(2)" tools/check-coverage.php` returns at least 2 (clover-missing + clover-unparseable + rowsPrinted-zero exits — all use exit code 2)
    - `grep -c "exit(1)" tools/check-coverage.php` returns 1 (coverage-gate-failed exit)
    - `grep -c "exit(0)" tools/check-coverage.php` returns 1 (success exit)
    - `wc -l tools/check-coverage.php` returns ≤ 80 lines (~50 LOC target per CONTEXT D-07; allowance for the rowsPrinted guard added beyond PATTERNS shape)
    - If pcov OR xdebug installed locally: `composer test-coverage` runs end-to-end and prints either "Coverage gate OK" (exit 0) or "Coverage gate FAILED" with per-module percentages on stderr (exit 1). If neither installed: `composer test-coverage` exits 1 in chained-script step 1 with the "install pcov or xdebug" message.
  </acceptance_criteria>
  <done>Per-module 70% gate is mechanical and runnable. Plans 05-05 and 05-06 will read the SUMMARY's baseline output to prioritize gap-fill.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| CI→test runner | `composer test-coverage` is invoked unattended in CI (Plan 05-07); failures must exit non-zero |
| clover XML→gate parser | `tools/check-coverage.php` reads `build/coverage/clover.xml` produced by phpunit; mis-shape → mis-gate |
| operator→composer scripts | Driver fail-fast prevents silent wrong-numbers (CONTEXT ## Risks paragraph 6) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-02-01 | Tampering | clover.xml content | accept | Generated by phpunit immediately before the gate runs; CI runs in a clean checkout per job. No persistent tamper surface. |
| T-05-02-02 | Spoofing | gate-pass with wrong scope | mitigate | `rowsPrinted === 0` guard in `check-coverage.php` exits 2 if no TST-01 files appear in clover (e.g. phpunit.xml.dist `<source><include>` drifts from MODULES list). Catches mis-wired gate. |
| T-05-02-03 | Denial of Service | local dev runs `test-coverage` without pcov/xdebug → silent zero-coverage clover | mitigate | Step 1 of chained `test-coverage` script: `extension_loaded('pcov') \|\| extension_loaded('xdebug')` else exit 1 with explicit message. CONTEXT ## Risks paragraph 6 documents this. |
| T-05-02-04 | Information Disclosure | clover.xml in CI artifact | accept | Build artifacts retained per repo policy; clover XML contains file paths + statement counts but no secrets. Same shape as Codecov-published reports. |
| T-05-02-05 | Repudiation | flaky gate masks coverage regression | mitigate | Per-module gate is deterministic (no shared state, no random sampling); pcov in CI vs xdebug locally produces identical clover (CONTEXT ## Risks paragraph 6 second clause). |
</threat_model>

<verification>
- `composer validate --strict --no-plugins` exits 0
- `composer test` exits 0 (regression — full corpus still passes)
- `composer test-unit` exits 0
- `composer test-integration` exits 0
- `xmllint --noout phpunit.xml.dist` exits 0
- `php -l tools/check-coverage.php` exits 0
- If a coverage driver is locally installed: `composer test-coverage` runs end-to-end and produces a structured per-module report. The exit code (0 or 1) is recorded in the SUMMARY as the BASELINE for Plans 05-05 and 05-06 to bias D-10 gap-fill toward.
- `git diff src/` is empty — no source code touched in this plan
</verification>

<success_criteria>
- D-13: PHPUnit testsuite split shipped (Unit + Integration)
- D-06: PCOV-in-CI assumption codified (no composer dep; activated in 05-07's setup-php step)
- D-07 + D-08: Per-module 70% gate operational on the 5 TST-01 modules verbatim
- CONTEXT ## Risks paragraph 6: PCOV/Xdebug fail-fast guards local + CI runs against silent zero-coverage clover
- CONTEXT ## Risks paragraph 1: BASELINE coverage measurement captured in SUMMARY for Plans 05-05/05-06 to consume
- Plans 05-05, 05-06, 05-07 in Wave 3 can now invoke `composer test-coverage` and the per-module gate
</success_criteria>

<output>
After completion, create `.planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md` documenting:
- Final shape of phpunit.xml.dist (line count, both testsuite names)
- Final composer.json scripts list (4 entries)
- BASELINE coverage report from a local `composer test-coverage` run if a driver was available — paste the per-module table verbatim. If no driver was available locally, note that and mark Plans 05-05/05-06 as needing to capture the baseline themselves before starting D-10 gap-fill.
- Confirmation `composer test` regression-passed (no break vs 05-01 corpus count)
</output>
