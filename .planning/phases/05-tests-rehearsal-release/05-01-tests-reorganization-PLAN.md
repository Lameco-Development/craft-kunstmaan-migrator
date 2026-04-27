---
phase: 05
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - tests/console/
  - tests/filter/
  - tests/load/
  - tests/locale/
  - tests/mapping/
  - tests/models/
  - tests/Plugin/
  - tests/source/
  - tests/verify/
  - tests/ComposerSuggestTest.php
  - tests/NeverProductionTraitTest.php
  - tests/PluginBootstrapTest.php
autonomous: true
requirements: [TST-01]
must_haves:
  truths:
    - "Every existing tests/<area>/ subdirectory is moved to tests/unit/<area>/ via git mv (preserves history)"
    - "tests/Plugin/ keeps its capital P after the move (tests/unit/Plugin/)"
    - "tests/PluginBootstrapTest.php moves to tests/integration/PluginBootstrapTest.php (Craft-bootstrapped → integration tier)"
    - "tests/ComposerSuggestTest.php and tests/NeverProductionTraitTest.php move to tests/unit/ (pure unit, no Craft boot)"
    - "tests/bootstrap.php stays at tests/bootstrap.php (root) — both testsuites share it"
    - "Every moved file's PSR-4 namespace is rewritten to match its new directory (lameco\\kunstmaanmigrator\\tests\\<area> → lameco\\kunstmaanmigrator\\tests\\unit\\<area>)"
    - "phpunit.xml.dist still has a single Unit testsuite at this point — Wave 2 splits it; this plan only retargets the path from `tests` → `tests/unit`"
    - "All 179 existing tests pass post-move (composer test exits 0)"
    - "No plan in Phase 5 may interleave between this reorganization and itself — D-14 invariant"
  artifacts:
    - path: "tests/unit/"
      provides: "New unit-tier root containing every previously-rooted test except PluginBootstrapTest"
      contains: "console"
    - path: "tests/integration/PluginBootstrapTest.php"
      provides: "Integration-tier scaffold (was tests/PluginBootstrapTest.php)"
      contains: "namespace lameco\\kunstmaanmigrator\\tests\\integration"
    - path: "phpunit.xml.dist"
      provides: "Single Unit testsuite retargeted to tests/unit (split into Unit+Integration happens in 05-02)"
      contains: "<directory>tests/unit</directory>"
  key_links:
    - from: "phpunit.xml.dist"
      to: "tests/unit + tests/integration"
      via: "<testsuite> directory"
      pattern: "tests/unit"
    - from: "moved test files"
      to: "PSR-4 autoload (composer.json autoload-dev)"
      via: "namespace rewrite + composer dump-autoload"
      pattern: "namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit"
---

<objective>
**D-12 + D-14: Reorganize the test corpus into a `tests/unit/` + `tests/integration/` split, as the FIRST plan in Phase 5.**

Every subsequent Phase 5 plan (characterization fixtures, unit-test gap-fill, coverage tooling, CI changes) writes into the new layout natively. No characterization-tier or unit-tier additions are allowed before this plan ships — the merge churn from a late `git mv` is brutal.

Why this lands first (D-14): the move rewrites every existing test path. If any in-flight branch has new tests under the old layout, it merges painfully. Land it on `main` clean before Phase 5's other plans branch off.

Output:
- `tests/unit/` directory containing 9 area subdirs + 2 root-level test files
- `tests/integration/PluginBootstrapTest.php` (the only integration-tier file at end of this plan)
- `phpunit.xml.dist` `<directory>` retargeted from `tests` → `tests/unit` (single Unit suite preserved; split into Unit+Integration ships in 05-02)
- All 179 existing tests still pass (`composer test` exit 0)
- `composer dump-autoload` runs cleanly (no PSR-4 violations)

This plan does NOT add any new tests, does NOT add any new testsuite, does NOT touch source code. It is a pure mechanical move + namespace rewrite + phpunit.xml.dist `<directory>` retarget.
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
@CLAUDE.md

<interfaces>
<!-- composer.json autoload-dev (verified in composer.json:33-37): -->
<!-- "lameco\\kunstmaanmigrator\\tests\\": "tests/" — PSR-4 prefix maps the whole tests/ tree. -->
<!-- After this plan, files under tests/unit/console/ resolve to lameco\kunstmaanmigrator\tests\unit\console\... -->
<!-- No composer.json change required — PSR-4 is recursive. composer dump-autoload refreshes the classmap. -->

<!-- phpunit.xml.dist current shape (verified in phpunit.xml.dist:1-15): -->
<!--   <testsuite name="Unit"><directory>tests</directory></testsuite> -->
<!-- This plan retargets the inner <directory> to `tests/unit` — keeps the single suite name "Unit" -->
<!-- so anyone scripted on `phpunit --testsuite Unit` still works. Plan 05-02 then ADDS an Integration suite. -->

<!-- tests/bootstrap.php: stays at tests/bootstrap.php (root). Reused by both testsuites after Plan 05-02 -->
<!-- adds the Integration testsuite. The <phpunit bootstrap="tests/bootstrap.php"> attribute is unchanged. -->
</interfaces>

<reference_files>
- ~/Sites/craft-kunstmaan-migrator/tests — v1.x reference layout (single flat tests/ — no precedent for split; this plan invents the unit/integration split for v2)
- .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md — "Test reorganization (D-12, D-14)" section lists the exact `git mv` commands
</reference_files>
</context>

<tasks>

<task type="auto">
  <name>Task 1: git mv every tests/&lt;area&gt;/ to tests/unit/&lt;area&gt; + the three top-level files; create tests/integration/</name>
  <files>
    tests/console/ → tests/unit/console/,
    tests/filter/ → tests/unit/filter/,
    tests/load/ → tests/unit/load/,
    tests/locale/ → tests/unit/locale/,
    tests/mapping/ → tests/unit/mapping/,
    tests/models/ → tests/unit/models/,
    tests/Plugin/ → tests/unit/Plugin/,
    tests/source/ → tests/unit/source/,
    tests/verify/ → tests/unit/verify/,
    tests/ComposerSuggestTest.php → tests/unit/ComposerSuggestTest.php,
    tests/NeverProductionTraitTest.php → tests/unit/NeverProductionTraitTest.php,
    tests/PluginBootstrapTest.php → tests/integration/PluginBootstrapTest.php
  </files>
  <read_first>
    - tests/ (run `ls -la tests/` to confirm the 9 subdirs + 3 top-level test files match the move list verbatim; abort if anything else is present that this plan didn't anticipate)
    - tests/PluginBootstrapTest.php (lines 13-22 docblock — confirms the file self-identifies as Phase-5 integration-tier scaffold)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md ("Test reorganization (D-12, D-14)" section — the canonical `git mv` command list, lines 79-93)
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-12 + D-14 verbatim, plus PATTERNS risk callout #1: capital P preservation on tests/Plugin/)
  </read_first>
  <action>
    From the repo root, run these commands in order. Do NOT use `mv` — use `git mv` so history follows. Do NOT batch into a single rename; each subdir is a separate `git mv` so failures are isolable.

    ```bash
    # 1. Create the integration-tier root (does not exist yet — PATTERNS risk callout #2).
    mkdir -p tests/integration

    # 2. Move every area subdir to tests/unit/. Preserve case (PATTERNS risk callout #1: tests/Plugin → tests/unit/Plugin, capital P).
    #    macOS APFS is case-insensitive but case-preserving; `git mv` honors the requested case.
    mkdir -p tests/unit
    git mv tests/console        tests/unit/console
    git mv tests/filter         tests/unit/filter
    git mv tests/load           tests/unit/load
    git mv tests/locale         tests/unit/locale
    git mv tests/mapping        tests/unit/mapping
    git mv tests/models         tests/unit/models
    git mv tests/Plugin         tests/unit/Plugin     # capital P
    git mv tests/source         tests/unit/source
    git mv tests/verify         tests/unit/verify

    # 3. Move the two pure-unit top-level test files to tests/unit/.
    git mv tests/ComposerSuggestTest.php       tests/unit/ComposerSuggestTest.php
    git mv tests/NeverProductionTraitTest.php  tests/unit/NeverProductionTraitTest.php

    # 4. Move the one integration-tier top-level test file to tests/integration/.
    git mv tests/PluginBootstrapTest.php       tests/integration/PluginBootstrapTest.php
    ```

    After all moves, verify with:
    ```bash
    git ls-files tests/ | sort > /tmp/post-move-files.txt
    grep -E '^tests/(unit|integration)/' /tmp/post-move-files.txt | wc -l
    grep -vE '^tests/(unit|integration|bootstrap\.php)' /tmp/post-move-files.txt
    # The second grep must print NOTHING — every test file should now live under tests/unit/ or tests/integration/.
    # tests/bootstrap.php stays at tests/bootstrap.php (root); not moved.

    # PATTERNS callout #1 — confirm capital P preserved:
    git ls-files 'tests/unit/Plugin/*' | head
    # Must show entries under tests/unit/Plugin/ (capital P), not tests/unit/plugin/.
    ```

    Do not edit any file contents in this task. Do not run `composer test` yet — the namespace rewrite in Task 2 is required first.
  </action>
  <verify>
    <automated>git ls-files tests/ | grep -vE '^tests/(unit/|integration/|bootstrap\.php$)' | wc -l | tr -d ' ' | grep -q '^0$' &amp;&amp; git ls-files 'tests/unit/Plugin/' | head -1 | grep -q '^tests/unit/Plugin/'</automated>
  </verify>
  <acceptance_criteria>
    - `git ls-files tests/ | grep -vE '^tests/(unit/|integration/|bootstrap\.php$)' | wc -l` returns `0` (every test file lives under tests/unit/ or tests/integration/, plus the unchanged tests/bootstrap.php)
    - `git ls-files 'tests/unit/'` returns at least 14 paths (9 area subdirs each contain ≥1 file + 2 top-level moves)
    - `git ls-files 'tests/integration/'` returns exactly 1 path: `tests/integration/PluginBootstrapTest.php`
    - `git ls-files 'tests/unit/Plugin/'` returns at least 1 path under capital-P `Plugin/` (PATTERNS risk callout #1)
    - `test -f tests/bootstrap.php` returns 0 (bootstrap stayed at root)
    - `git status` shows the moves as renames (R), not adds + deletes — proves `git mv` was used
  </acceptance_criteria>
  <done>Filesystem layout matches D-12. Namespace rewrites in Task 2 next.</done>
</task>

<task type="auto">
  <name>Task 2: Rewrite PSR-4 namespaces in every moved file + retarget phpunit.xml.dist + composer dump-autoload + run tests</name>
  <files>
    tests/unit/**/*.php (every moved file),
    tests/integration/PluginBootstrapTest.php,
    phpunit.xml.dist
  </files>
  <read_first>
    - composer.json (lines 33-37 — verify autoload-dev PSR-4 prefix `lameco\kunstmaanmigrator\tests\` maps to `tests/` — confirms recursive PSR-4 will resolve `lameco\kunstmaanmigrator\tests\unit\console\X` to `tests/unit/console/X.php` without any composer.json change)
    - phpunit.xml.dist (whole file — current shape: single `<testsuite name="Unit"><directory>tests</directory></testsuite>`; this task only retargets the inner `<directory>`)
    - tests/unit/console/ (sample one of the moved files — confirm current namespace is `lameco\kunstmaanmigrator\tests\console`)
    - tests/unit/Plugin/SettingsHtmlTest.php (sample — capital-P case must reflect in namespace: `lameco\kunstmaanmigrator\tests\unit\Plugin`)
    - tests/integration/PluginBootstrapTest.php (current namespace `lameco\kunstmaanmigrator\tests`; rewrite to `lameco\kunstmaanmigrator\tests\integration`)
  </read_first>
  <action>
    **2a. Namespace rewrite per moved file.** For every PHP file under `tests/unit/` and `tests/integration/`, change its `namespace` declaration to match its new directory. Use this systematic approach (run from repo root):

    ```bash
    # Rewrite tests/unit/<area>/ files.
    # The pattern: namespace lameco\kunstmaanmigrator\tests\<area>;  →  namespace lameco\kunstmaanmigrator\tests\unit\<area>;
    # Implement via sed -i (BSD sed on macOS requires '' arg).

    for area in console filter load locale mapping models Plugin source verify; do
      find "tests/unit/${area}" -name '*.php' -type f | while read -r f; do
        # Replace `namespace lameco\kunstmaanmigrator\tests\<area>` with `namespace lameco\kunstmaanmigrator\tests\unit\<area>`.
        sed -i '' -E "s|^namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\${area};|namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit\\\\${area};|" "$f"
      done
    done

    # Rewrite the two top-level files moved into tests/unit/.
    sed -i '' -E "s|^namespace lameco\\\\kunstmaanmigrator\\\\tests;|namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit;|" tests/unit/ComposerSuggestTest.php tests/unit/NeverProductionTraitTest.php

    # Rewrite the integration file.
    sed -i '' -E "s|^namespace lameco\\\\kunstmaanmigrator\\\\tests;|namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\integration;|" tests/integration/PluginBootstrapTest.php
    ```

    Verify zero files still carry the OLD namespace shape:
    ```bash
    grep -rn 'namespace lameco\\kunstmaanmigrator\\tests\\\(console\|filter\|load\|locale\|mapping\|models\|Plugin\|source\|verify\);' tests/ && echo "FAIL: stale namespace found" || echo "OK: namespaces updated"
    grep -rn 'namespace lameco\\kunstmaanmigrator\\tests;' tests/ && echo "FAIL: stale top-level namespace" || echo "OK: top-level rewritten"
    ```

    **2b. Retarget phpunit.xml.dist.** Edit the single `<directory>` element from `tests` to `tests/unit`. The full diff against the current 14-line file:

    ```xml
    <!-- BEFORE (line 11): -->
            <directory>tests</directory>
    <!-- AFTER: -->
            <directory>tests/unit</directory>
    ```

    Do NOT add a second `<testsuite>` for Integration in this plan — that ships in 05-02. Do NOT add `<source>` or `<coverage>` blocks — also 05-02. Keep `bootstrap`, `colors`, `testdox`, `cacheDirectory`, `requireCoverageMetadata` attributes verbatim.

    **2c. Refresh autoloader.**
    ```bash
    composer dump-autoload
    ```

    **2d. Run the full test suite.**
    ```bash
    composer test
    ```
    Must exit 0. The expected suite name "Unit" + "tests/unit" directory will pick up everything that previously lived under `tests/`. The integration tier (`tests/integration/PluginBootstrapTest.php`) is currently NOT picked up by any testsuite — that's correct for this plan; 05-02 adds the Integration suite. Confirm by reviewing the phpunit output for total test count: should match the pre-move count MINUS whatever was in `tests/PluginBootstrapTest.php` (which is now under tests/integration/ and therefore unrun until 05-02 lands).

    **2e. Document the temporary integration gap.** Add a one-line comment to phpunit.xml.dist immediately above the `<testsuites>` element:

    ```xml
        <!-- Phase 5 / Plan 05-01: Integration testsuite added in Plan 05-02 (D-13). -->
    ```

    The comment is removed when 05-02 lands the Integration suite. It exists ONLY to make it obvious to anyone reading the file in the gap between 05-01 and 05-02 that the integration tier is intentionally unattached.
  </action>
  <verify>
    <automated>composer test 2>&amp;1 | tail -5 | grep -E "OK \(|Tests:.*Assertions"</automated>
  </verify>
  <acceptance_criteria>
    - `grep -rn 'namespace lameco\\kunstmaanmigrator\\tests\\\(console\|filter\|load\|locale\|mapping\|models\|Plugin\|source\|verify\);' tests/` returns zero matches (no stale per-area namespaces)
    - `grep -rn '^namespace lameco\\\\kunstmaanmigrator\\\\tests;$' tests/` returns zero matches (no stale top-level namespace; every file is now under `\tests\unit\...` or `\tests\integration\...`)
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\unit\\\\Plugin;' tests/unit/Plugin/SettingsHtmlTest.php` returns 1 (capital-P preserved in namespace)
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\tests\\\\integration;' tests/integration/PluginBootstrapTest.php` returns 1 (integration namespace correctly rewritten)
    - `grep -c '<directory>tests/unit</directory>' phpunit.xml.dist` returns 1 (path retargeted)
    - `grep -c '<directory>tests</directory>' phpunit.xml.dist` returns 0 (old path removed)
    - `grep -c '<testsuite name=' phpunit.xml.dist` returns 1 (still single testsuite — Integration ships in 05-02)
    - `grep -c '<source>\|<coverage>' phpunit.xml.dist` returns 0 (coverage scoping ships in 05-02)
    - `composer dump-autoload` exits 0 (verify with `composer dump-autoload 2>&1 | grep -v "^Generating" | grep -E "^[^:]" | wc -l` returns 0 — no warnings)
    - `composer test` exits 0; the printed "Tests: N" total is no less than the pre-move total minus the test count in `tests/PluginBootstrapTest.php` (PluginBootstrapTest is now under integration/ and unrun by the still-single Unit suite)
    - `git diff phpunit.xml.dist` shows exactly the `<directory>` retarget + the one-line comment addition; nothing else
  </acceptance_criteria>
  <done>All moved tests pass under their new namespaces. phpunit.xml.dist points at tests/unit. The Integration suite gap is explicitly documented inline. Phase 5's other plans can now write tests directly into the new layout.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| dev→git | Mechanical history-preserving moves; no input validation surface |
| autoloader→test runtime | PSR-4 namespace must match directory; mismatch = class-not-found at test time |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-01-01 | Tampering | git history loss | mitigate | Use `git mv` not `mv` — git records as renames so blame/log follow. Verify with `git status` showing R-prefix on every move. |
| T-05-01-02 | Repudiation | namespace drift between path and declaration | mitigate | Acceptance criteria grep for both stale-namespace shapes (per-area and top-level); zero matches required before plan closes. |
| T-05-01-03 | Information Disclosure | none — no secrets touched | accept | Test files don't carry secrets; bootstrap.php is ETL-test scaffold. |
| T-05-01-04 | Denial of Service | autoloader cache stale after move | mitigate | `composer dump-autoload` step explicitly invalidates and rebuilds the classmap. |
</threat_model>

<verification>
- `composer test` exits 0 with the same per-area test count as pre-move (modulo the one PluginBootstrapTest file now under tests/integration/ and unrun until 05-02)
- `git log --oneline --follow tests/unit/console/MigrateControllerSyncAssetsTest.php` shows pre-move history (proves rename detection worked)
- `phpunit.xml.dist` diff shows exactly: one `<directory>` retarget + one-line gap comment
- No source files (`src/**`) modified — `git diff src/` is empty
</verification>

<success_criteria>
- Filesystem reorganized per D-12; capital-P preserved on tests/unit/Plugin/
- Every moved file's namespace matches its new directory (PSR-4 valid)
- phpunit.xml.dist retargeted to tests/unit (single Unit suite preserved; Integration deferred to 05-02)
- composer test green; corpus delta = -N where N = number of test methods in the moved-out PluginBootstrapTest (acceptable until 05-02 lands the Integration suite)
- D-14 invariant honored: this is the only plan in Wave 1; every other Phase 5 plan branches off after this lands on main
</success_criteria>

<output>
After completion, create `.planning/phases/05-tests-rehearsal-release/05-01-SUMMARY.md` documenting:
- Total files moved (subdirs + top-level): expected 9 + 3 = 12 git-mv operations
- Pre-move corpus count (e.g. 179 tests) and post-move (e.g. 179 - PluginBootstrap_count = 17X tests under Unit suite; PluginBootstrap unrun until 05-02)
- Whether `composer dump-autoload` produced any warnings (should be none)
- Confirmation the capital-P preservation grep passed
- Note that phpunit.xml.dist gap-comment is intentional and removed by 05-02
</output>
