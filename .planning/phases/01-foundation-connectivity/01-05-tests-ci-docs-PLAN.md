---
phase: 01-foundation-connectivity
plan: 05
type: execute
wave: 3
depends_on: [01, 02]
files_modified:
  - phpunit.xml.dist
  - tests/bootstrap.php
  - tests/PluginBootstrapTest.php
  - .github/workflows/ci.yml
  - README.md
  - .planning/REQUIREMENTS.md
  - .planning/PROJECT.md
autonomous: true
requirements: [FND-05]
must_haves:
  truths:
    - "PHPUnit 11 is wired under tests/ and runs via composer test (FND-05)."
    - "The test suite is non-empty on day one — PluginBootstrapTest asserts Plugin / Settings / LegacyDbService autoload (D-21)."
    - "GitHub Actions runs validate + install + test on every push and pull_request (D-22)."
    - "README.md gives operators the minimum they need: install + env vars + doctor invocation."
    - "Project documentation is internally consistent: REQUIREMENTS.md FND-02 lists v1's actual schema; CONN-03 acknowledges the mapping-file check ships in Phase 2; PROJECT.md Key Decisions row matches."
  artifacts:
    - path: phpunit.xml.dist
      provides: "PHPUnit 11 configuration — bootstraps tests/bootstrap.php, scans tests/ directory"
      contains: 'bootstrap="tests/bootstrap.php"'
    - path: tests/bootstrap.php
      provides: "Minimal autoloader bootstrap for unit tests"
      contains: "vendor/autoload.php"
    - path: tests/PluginBootstrapTest.php
      provides: "Non-empty smoke test asserting Plugin + key services autoload"
      contains: "final class PluginBootstrapTest"
    - path: .github/workflows/ci.yml
      provides: "Single-job CI workflow — PHP 8.3, ubuntu-latest, validate + install + test"
      contains: "composer test"
    - path: README.md
      provides: "Operator-facing minimum doc — install + env vars + doctor invocation"
      contains: "kunstmaan-migrator/doctor"
  key_links:
    - from: phpunit.xml.dist
      to: tests/bootstrap.php
      via: 'bootstrap="tests/bootstrap.php" attribute'
      pattern: 'bootstrap="tests/bootstrap.php"'
    - from: phpunit.xml.dist
      to: tests/
      via: '<directory>tests</directory> testsuite'
      pattern: '<directory>tests</directory>'
    - from: composer.json scripts.test
      to: phpunit.xml.dist
      via: "vendor/bin/phpunit auto-discovers phpunit.xml.dist"
      pattern: "vendor/bin/phpunit"
    - from: .github/workflows/ci.yml
      to: composer.json scripts.test
      via: "run: composer test step"
      pattern: "composer test"
---

<objective>
Wire the PHPUnit 11 suite, GitHub Actions CI workflow, and operator-facing README — closing FND-05 — and
patch three load-bearing documentation inconsistencies surfaced in CONTEXT.md `<specifics>`. After this
plan, `composer test` runs green on a fresh `composer install`, and the same runs on every push via CI.

Documentation patches owed by Phase 1 (CONTEXT.md `<specifics>`):
1. REQUIREMENTS.md FND-02 — replace the wrong column list (`legacy_class, legacy_id, craft_id, migrated_at, status`) with v1.x's actual schema (`source, sourceKey, targetType, targetId, targetUid, siteId, meta, dateCreated, dateUpdated`) per D-06.
2. REQUIREMENTS.md CONN-03 — acknowledge the mapping-file check ships in Phase 2 alongside the loader (D-17). Phase 1 ships 3 doctor checks, not 4.
3. PROJECT.md Key Decisions row "Keep v1's `kunstmaanmigrator_state` schema verbatim" — same column-name correction.

These doc edits are bundled into this plan because they are doc-only and removing the inconsistencies
prevents the verifier from flagging false positives in the rehearsal.

Purpose: Without a green CI and a non-empty test suite on day one, Phase 1 isn't FND-05-complete. Without
the doc patches, the verifier and downstream readers see contradictions that take longer to diagnose than
to fix here.
Output: Green test suite locally + in CI, operator-facing README, internally-consistent project docs.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/01-foundation-connectivity/01-CONTEXT.md
@.planning/phases/01-foundation-connectivity/01-PATTERNS.md
@CLAUDE.md
@composer.json
@src/Plugin.php
@src/db/LegacyDbService.php
@src/models/Settings.php
</context>

<interfaces>
<!-- This plan does not create new code interfaces — it ships test/CI/docs around the surface from Plans 01-04. -->
<!-- The test asserts the FQCN contracts those plans already established. -->

```php
namespace lameco\kunstmaanmigrator\tests;

final class PluginBootstrapTest extends \PHPUnit\Framework\TestCase
{
    public function testPluginClassIsLoadable(): void;
    public function testKeyServiceClassesAreLoadable(): void;
    public function testPluginDeclaresLegacyDbServiceComponent(): void;
}
```
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Wire PHPUnit 11 — phpunit.xml.dist + tests/bootstrap.php + PluginBootstrapTest</name>
  <files>phpunit.xml.dist, tests/bootstrap.php, tests/PluginBootstrapTest.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/phpunit.xml (v1 reference — Phase 1 adjusts the testsuite directory)
    - ~/Sites/craft-kunstmaan-migrator/tests/bootstrap.php (port verbatim per PATTERNS.md)
    - ~/Sites/craft-kunstmaan-migrator/tests/unit/PluginBootstrapTest.php (lines 56-70 reflection-based shape lint; lines 130-138 markTestSkipped guard pattern — Phase 1 trims to 3 assertions)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (sections "tests/PluginBootstrapTest.php", "tests/bootstrap.php", "phpunit.xml" — lines 777-877)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-21)
    - composer.json (already has phpunit/phpunit ^11.0 in require-dev + scripts.test wired by Plan 01)
  </read_first>
  <action>
    Three files. Use `phpunit.xml.dist` (NOT `phpunit.xml`) so the file is committed and operators can override locally with `phpunit.xml` if needed — standard PHPUnit convention.

    **File 1: `phpunit.xml.dist`** (paste-ready, follows PATTERNS.md lines 859-873):

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
                <directory>tests</directory>
            </testsuite>
        </testsuites>
    </phpunit>
    ```

    **File 2: `tests/bootstrap.php`** (port verbatim per PATTERNS.md lines 843-846 — 4 lines):

    ```php
    <?php
    declare(strict_types=1);
    require __DIR__ . '/../vendor/autoload.php';
    ```

    Per PATTERNS.md `<discretion>` block (line 849): Phase 1 deliberately does NOT bootstrap Craft. v1's `PluginBootstrapTest::testFullCircularDiCheckIsDeferredToConsumerRehearsal()` documents this anti-pattern — half-bootstrapping Craft and fighting the framework is a known dead end. Plan 05 ships FQCN-loadable + reflection-based assertions only.

    **File 3: `tests/PluginBootstrapTest.php`** (paste-ready, follows PATTERNS.md lines 791-830 — 3 assertions: Plugin loads, Settings + LegacyDbService load, Plugin declares legacyDbService component):

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator\tests;

    use lameco\kunstmaanmigrator\Plugin;
    use lameco\kunstmaanmigrator\db\LegacyDbService;
    use lameco\kunstmaanmigrator\models\Settings;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    /**
     * D-21: non-empty smoke test on day one. Asserts the Plugin entrypoint and the
     * Phase 1 service / model classes autoload via PSR-4, and that the Plugin's
     * config() declares the legacyDbService component.
     *
     * Full Craft-bootstrapped tests are deliberately deferred — half-bootstrapping
     * Craft from a unit-test context is a known dead end (see v1's
     * testFullCircularDiCheckIsDeferredToConsumerRehearsal pattern). Phase 5 adds
     * the plugin-load smoke test in a real Craft install (TST-03).
     */
    final class PluginBootstrapTest extends TestCase
    {
        public function testPluginClassIsLoadable(): void
        {
            self::assertTrue(class_exists(Plugin::class, true), 'Plugin must autoload via PSR-4');
        }

        public function testKeyServiceClassesAreLoadable(): void
        {
            $missing = [];
            foreach ([LegacyDbService::class, Settings::class] as $fqcn) {
                if (!class_exists($fqcn, true)) {
                    $missing[] = $fqcn;
                }
            }
            self::assertSame([], $missing, 'Key Phase 1 service / model classes must autoload');
        }

        public function testPluginDeclaresLegacyDbServiceComponent(): void
        {
            // Source-level reflection — no Craft container in unit context.
            // We assert the literal config() declaration so a refactor that drops
            // the legacyDbService component fails this test loudly.
            $source = (string) file_get_contents((new ReflectionClass(Plugin::class))->getFileName());
            self::assertStringContainsString(
                "'legacyDbService' => LegacyDbService::class",
                $source,
                'Plugin::config() must declare legacyDbService component',
            );
        }
    }
    ```

    Run `composer dump-autoload --no-interaction` (the `tests/` PSR-4 prefix was declared by Plan 01) and then `vendor/bin/phpunit` to confirm green.
  </action>
  <acceptance_criteria>
    - `phpunit.xml.dist` exists.
    - `grep -q 'bootstrap="tests/bootstrap.php"' phpunit.xml.dist` exits 0.
    - `grep -q '<directory>tests</directory>' phpunit.xml.dist` exits 0.
    - `grep -q 'requireCoverageMetadata="false"' phpunit.xml.dist` exits 0.
    - `tests/bootstrap.php` exists.
    - `grep -q "require __DIR__ . '/../vendor/autoload.php';" tests/bootstrap.php` exits 0.
    - `tests/PluginBootstrapTest.php` exists.
    - `grep -q 'final class PluginBootstrapTest extends TestCase' tests/PluginBootstrapTest.php` exits 0.
    - All three test methods present: `for m in 'function testPluginClassIsLoadable' 'function testKeyServiceClassesAreLoadable' 'function testPluginDeclaresLegacyDbServiceComponent'; do grep -q "$m" tests/PluginBootstrapTest.php || exit 1; done`.
    - `php -l tests/bootstrap.php tests/PluginBootstrapTest.php` exits 0.
    - `composer dump-autoload --no-interaction` exits 0.
    - `vendor/bin/phpunit --no-coverage` exits 0 (suite is non-empty + green — D-21).
    - `composer test` exits 0 (script wiring from Plan 01).
    - Test count >= 3 (suite is non-empty per FND-05): `vendor/bin/phpunit --no-coverage --testdox 2>&1 | grep -E 'Tests: [3-9]|Tests: [0-9]{2,}'`.
  </acceptance_criteria>
  <verify>
    <automated>composer dump-autoload --no-interaction &amp;&amp; vendor/bin/phpunit --no-coverage</automated>
  </verify>
  <done>PHPUnit 11 wired, three smoke assertions pass, composer test exits 0, suite is non-empty per FND-05 / D-21.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Wire GitHub Actions CI workflow</name>
  <files>.github/workflows/ci.yml</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/.github/workflows/ci.yml (v1 reference — Phase 1 strips Deptrac + FQCN-lint)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section ".github/workflows/ci.yml", lines 880-911)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-22)
  </read_first>
  <action>
    Create `.github/workflows/ci.yml`. Single-job, `runs-on: ubuntu-latest`, PHP 8.3 only — matrix expansion (8.4, multiple OSes) deferred per D-22 until there's a real driver.

    The `composer validate --strict --no-plugins` step uses `--no-plugins` per PATTERNS.md note (line 891): the `craftcms/plugin-installer` composer plugin throws a Filesystem.php absolute-path error when validating the plugin's own repo. v1 hit this; v2 inherits the workaround.

    Concrete file content (paste-ready, follows PATTERNS.md lines 893-907):

    ```yaml
    name: CI
    on: [push, pull_request]
    jobs:
      test:
        runs-on: ubuntu-latest
        steps:
          - uses: actions/checkout@v4
          - uses: shivammathur/setup-php@v2
            with:
              php-version: '8.3'
          - run: composer validate --strict --no-plugins
          - run: composer install --no-interaction --no-progress
          - run: composer test
    ```

    DO NOT add (deferred per D-22 / PATTERNS.md "Drop from v1"):
    - Deptrac step (three-tier layout retired in v2).
    - `assert-fqcn-loadable.php` step (v1-specific brownfield diagnostic).
    - `--optimize-autoloader` flag (v1 needed it to mask classmap mismatches; v2 has none).
    - PHP version matrix (single 8.3 is enough today).
    - Multi-OS matrix.

    The plugin-load smoke test (TST-03 / Phase 5) is NOT in this workflow — D-22 explicitly defers it.

    The `.github/workflows/` directory must exist before writing the file. If absent, create it.
  </action>
  <acceptance_criteria>
    - `.github/workflows/ci.yml` exists.
    - `grep -q 'name: CI' .github/workflows/ci.yml` exits 0.
    - `grep -q 'on: \\[push, pull_request\\]' .github/workflows/ci.yml` exits 0.
    - `grep -q 'runs-on: ubuntu-latest' .github/workflows/ci.yml` exits 0.
    - `grep -q "php-version: '8.3'" .github/workflows/ci.yml` exits 0.
    - `grep -q 'composer validate --strict --no-plugins' .github/workflows/ci.yml` exits 0.
    - `grep -q 'composer install --no-interaction --no-progress' .github/workflows/ci.yml` exits 0.
    - `grep -q 'composer test' .github/workflows/ci.yml` exits 0.
    - Deferred items absent: `! grep -iE 'deptrac|fqcn|matrix' .github/workflows/ci.yml`.
    - YAML parses: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"` exits 0 (or use `php -r 'yaml_parse_file(".github/workflows/ci.yml") !== false or exit(1);'` if PHP yaml extension is present; else use `composer require --dev symfony/yaml` already loaded — `php -r 'require __DIR__ . "/vendor/autoload.php"; Symfony\\Component\\Yaml\\Yaml::parseFile(".github/workflows/ci.yml");'`).
  </acceptance_criteria>
  <verify>
    <automated>test -f .github/workflows/ci.yml &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; \Symfony\Component\Yaml\Yaml::parseFile(".github/workflows/ci.yml");'</automated>
  </verify>
  <done>CI workflow file exists, parses as valid YAML, declares the four-step PHP 8.3 ubuntu-latest job per D-22.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Write minimal operator-facing README.md</name>
  <files>README.md</files>
  <read_first>
    - .planning/PROJECT.md (intro paragraph — pull tone + scope statement)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-12 env var list, "Claude's Discretion" bullet on README scope)
    - CLAUDE.md (locked architectural ground rules — keep README aligned)
  </read_first>
  <action>
    Create `README.md` at repo root. Per CONTEXT.md "Claude's Discretion" and the deferred-ideas list, the Phase 1 README is MINIMAL: install + env vars + doctor invocation. The full UPGRADING.md (v1.x → v2 swap-in playbook) lands at release time in Phase 5 when there's a tested upgrade path to document.

    Concrete file content (paste-ready):

    ```markdown
    # Kunstmaan Migrator (revisited)

    A Craft CMS 5 plugin that migrates content from a legacy Kunstmaan (Symfony) site
    into an existing Craft CMS site. Craft is the source of truth for schema —
    Kunstmaan content gets mapped onto Craft sections / fields / entry types as
    they already exist.

    > **Status:** Phase 1 (Foundation & Connectivity). Plugin scaffolds, connects
    > to a legacy MySQL DB, attaches the `kunstmaanSourceId` field, and exposes a
    > working `doctor` command. The `analyze` / `map` / `migrate` / `verify`
    > commands land in Phases 2-4. See `.planning/ROADMAP.md` for the full plan.

    ## Requirements

    - PHP 8.3+
    - Craft CMS 5 (`^5.0`)
    - A reachable legacy Kunstmaan MySQL database
    - An Anthropic API key (for the `analyze` proposal stage in Phase 2)

    ## Installation

    ```bash
    composer require lameco/craft-kunstmaan-migrator
    ./craft plugin/install kunstmaan-migrator
    ```

    Re-running install (or applying future schema bumps):

    ```bash
    ./craft kunstmaan-migrator/migrate/install
    ```

    ## Configuration

    The plugin owns its legacy MySQL connection internally — you do **not** need
    to declare a `legacyDb` Yii component in `config/app.php`. Configure via env
    vars:

    ```
    CRAFT_LEGACY_DB_SERVER=localhost
    CRAFT_LEGACY_DB_DATABASE=kunstmaan_dump
    CRAFT_LEGACY_DB_USER=root
    CRAFT_LEGACY_DB_PASSWORD=secret
    CRAFT_LEGACY_DB_PORT=3306             # default 3306
    CRAFT_LEGACY_DB_CHARSET=utf8mb4       # default utf8mb4
    CRAFT_LEGACY_DB_TABLE_PREFIX=         # default empty

    ANTHROPIC_API_KEY=sk-ant-...          # required for Phase 2 analyze
    ```

    Plugin Settings (Settings → Plugins → Kunstmaan Migrator) override env vars
    when set. The Settings UI ships in Phase 4; until then, env vars are the
    canonical configuration surface.

    ## Doctor

    Verify configuration before running migration commands:

    ```bash
    ./craft kunstmaan-migrator/doctor
    ```

    Reports OK / FAIL on:

    1. Legacy DB reachability
    2. Anthropic API key presence (presence only — the value is never logged)
    3. `storage/migration/` writable (auto-created if missing)

    Exits 0 on full pass, 1 on any FAIL.

    ## Production safety

    The plugin **refuses to run** when `CRAFT_ENVIRONMENT=production`. It is a
    dev / staging tool only.

    ## Development

    ```bash
    composer install
    composer test
    ```

    CI runs `composer validate --strict` + `composer install` + `composer test` on
    PHP 8.3 / ubuntu-latest on every push and pull request.

    ## License

    MIT
    ```

    Note for the executor: include the literal `kunstmaan-migrator/doctor` invocation string verbatim — the acceptance criteria grep for it.
  </action>
  <acceptance_criteria>
    - `README.md` exists at repo root.
    - Top-level title: `grep -q '^# Kunstmaan Migrator' README.md`.
    - Install command shown: `grep -q 'composer require lameco/craft-kunstmaan-migrator' README.md`.
    - All 8 env vars listed: `for v in CRAFT_LEGACY_DB_SERVER CRAFT_LEGACY_DB_DATABASE CRAFT_LEGACY_DB_USER CRAFT_LEGACY_DB_PASSWORD CRAFT_LEGACY_DB_PORT CRAFT_LEGACY_DB_CHARSET CRAFT_LEGACY_DB_TABLE_PREFIX ANTHROPIC_API_KEY; do grep -q "$v" README.md || exit 1; done`.
    - Doctor invocation present: `grep -q './craft kunstmaan-migrator/doctor' README.md`.
    - Migrate/install invocation present: `grep -q './craft kunstmaan-migrator/migrate/install' README.md`.
    - Production-safety mention present: `grep -q 'CRAFT_ENVIRONMENT=production' README.md`.
    - The README does NOT yet promise Phase 2-5 features as available: `! grep -E 'kunstmaan-migrator/(analyze|map|verify)' README.md` (these are intentionally absent until their phases ship).
    - Word count is reasonable (operator-facing minimum, not Phase 5 long-form): `wc -w README.md` reports < 600 words.
  </acceptance_criteria>
  <verify>
    <automated>test -f README.md &amp;&amp; grep -q './craft kunstmaan-migrator/doctor' README.md &amp;&amp; grep -q 'composer require lameco/craft-kunstmaan-migrator' README.md</automated>
  </verify>
  <done>README.md ships the operator minimum (install + env vars + doctor + production-safety mention) without overpromising Phase 2-5 features.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 4: Patch project documentation inconsistencies (REQUIREMENTS.md FND-02 + CONN-03, PROJECT.md Key Decisions row)</name>
  <files>.planning/REQUIREMENTS.md, .planning/PROJECT.md</files>
  <read_first>
    - .planning/REQUIREMENTS.md (full file — locate FND-02 line 11 and CONN-03 line 21)
    - .planning/PROJECT.md (full file — locate "Keep v1's `kunstmaanmigrator_state` schema verbatim" Key Decisions row at line 124)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (`<specifics>` block — explicit list of doc patches owed)
    - src/migrations/Install.php (Plan 03 — confirms the actual schema columns)
  </read_first>
  <action>
    Three documentation edits. All are precise text replacements grep-verifiable in the acceptance criteria.

    **Edit 1: REQUIREMENTS.md FND-02 column list correction.**

    The current FND-02 (line 11) reads, mid-sentence:

    > Install creates a state table `kunstmaanmigrator_state` (schema kept compatible with v1.x: `legacy_class`, `legacy_id`, `craft_id`, `migrated_at`, `status`) and attaches a `kunstmaanSourceId` Plain Text field.

    Replace the parenthetical column list with v1.x's actual schema (per D-06 / Plan 03's Install.php). Use Edit tool with this old/new pair:

    OLD:
    ```
    (schema kept compatible with v1.x: `legacy_class`, `legacy_id`, `craft_id`, `migrated_at`, `status`)
    ```

    NEW:
    ```
    (schema kept compatible with v1.x: `id`, `source`, `sourceKey`, `targetType`, `targetId`, `targetUid`, `siteId`, `meta`, `dateCreated`, `dateUpdated`, with a UNIQUE index on `(source, sourceKey, siteId)` and an INDEX on `(dateUpdated)`)
    ```

    **Edit 2: REQUIREMENTS.md CONN-03 mapping-check deferral.**

    The current CONN-03 (line 21) reads:

    > **CONN-03**: `kunstmaan-migrator/doctor` command reports OK/FAIL on: legacy DB reachability, Anthropic key presence, mapping file validity (if present), write permissions on `storage/migration/`. No queue-worker check — v1's check was carried by v1's queue-heavy pipeline; v2 is CLI-inline by default.

    Replace with the Phase-1-shipped 3-check version + explicit Phase 2 deferral note. Use Edit tool with:

    OLD:
    ```
    **CONN-03**: `kunstmaan-migrator/doctor` command reports OK/FAIL on: legacy DB reachability, Anthropic key presence, mapping file validity (if present), write permissions on `storage/migration/`. No queue-worker check — v1's check was carried by v1's queue-heavy pipeline; v2 is CLI-inline by default.
    ```

    NEW:
    ```
    **CONN-03**: `kunstmaan-migrator/doctor` command reports OK/FAIL on: legacy DB reachability, Anthropic key presence, write permissions on `storage/migration/`. The mapping-file validity check ships in Phase 2 alongside the mapping loader (deferred per D-17 — no point shipping a hollow stub before the loader exists). No queue-worker check — v1's check was carried by v1's queue-heavy pipeline; v2 is CLI-inline by default.
    ```

    **Edit 3: PROJECT.md Key Decisions row "Keep v1's `kunstmaanmigrator_state` schema verbatim".**

    The current row (line 124) contains the same wrong column list. Use Edit tool with:

    OLD:
    ```
    | Keep v1's `kunstmaanmigrator_state` schema verbatim | Lets a v2 install detect prior state on hosts already migrated under v1.x. Schema (`legacy_class`, `legacy_id`, `craft_id`, `migrated_at`, `status`) earns its keep over a field-only approach: a fast index for "have I seen this legacy id?" without loading every Craft entry, plus an audit trail. | — Pending |
    ```

    NEW:
    ```
    | Keep v1's `kunstmaanmigrator_state` schema verbatim | Lets a v2 install detect prior state on hosts already migrated under v1.x. Schema (`id`, `source`, `sourceKey`, `targetType`, `targetId`, `targetUid`, `siteId`, `meta`, `dateCreated`, `dateUpdated`, with UNIQUE on `(source, sourceKey, siteId)`) earns its keep over a field-only approach: a fast index for "have I seen this legacy id?" without loading every Craft entry, plus an audit trail. | — Pending |
    ```

    Use the `Edit` tool for each replacement (NOT Write, since these files exist and have content beyond the patches). Verify after each edit that `grep` finds the new string and does NOT find the old string.
  </action>
  <acceptance_criteria>
    - REQUIREMENTS.md FND-02 column-list patched:
      - `! grep -q 'legacy_class.*legacy_id.*craft_id.*migrated_at.*status' .planning/REQUIREMENTS.md` (old list absent).
      - `grep -q 'sourceKey.*targetType.*targetId.*targetUid.*siteId.*meta.*dateCreated.*dateUpdated' .planning/REQUIREMENTS.md` (new list present).
      - `grep -q 'UNIQUE index on .source, sourceKey, siteId.' .planning/REQUIREMENTS.md` (index annotation present).
    - REQUIREMENTS.md CONN-03 patched:
      - `! grep -q 'mapping file validity (if present)' .planning/REQUIREMENTS.md` (old wording absent).
      - `grep -q 'mapping-file validity check ships in Phase 2' .planning/REQUIREMENTS.md` (new wording present).
      - `grep -q 'deferred per D-17' .planning/REQUIREMENTS.md` (decision reference present).
    - PROJECT.md Key Decisions row patched:
      - `! grep -q 'legacy_class.*legacy_id.*craft_id.*migrated_at.*status' .planning/PROJECT.md` (old list absent in PROJECT.md too).
      - `grep -q 'sourceKey.*targetType.*targetId.*targetUid.*siteId.*meta.*dateCreated.*dateUpdated' .planning/PROJECT.md` (new list present).
    - Both files still parse as valid markdown (no broken table rows): line count of REQUIREMENTS.md and PROJECT.md must not differ by more than ~3 lines from the pre-edit baseline (the column-list expansion may add a few characters but should NOT add new lines unless absolutely necessary).
    - Cross-consistency between PROJECT.md, REQUIREMENTS.md, and src/migrations/Install.php — all three reference the same column set.
  </acceptance_criteria>
  <verify>
    <automated>! grep -q 'legacy_class.*legacy_id.*craft_id.*migrated_at.*status' .planning/REQUIREMENTS.md &amp;&amp; ! grep -q 'legacy_class.*legacy_id.*craft_id.*migrated_at.*status' .planning/PROJECT.md &amp;&amp; grep -q 'sourceKey.*targetType.*targetId.*targetUid' .planning/REQUIREMENTS.md &amp;&amp; grep -q 'sourceKey.*targetType.*targetId.*targetUid' .planning/PROJECT.md &amp;&amp; grep -q 'mapping-file validity check ships in Phase 2' .planning/REQUIREMENTS.md</automated>
  </verify>
  <done>Three documentation patches applied. REQUIREMENTS.md FND-02 + CONN-03 and PROJECT.md Key Decisions row are internally consistent with src/migrations/Install.php. The verifier (and any future reader) will see one schema description, not two contradictory ones.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| GitHub Actions runner → composer/phpunit | CI runs trusted scripts on a hosted runner. Standard supply-chain considerations apply (`actions/checkout@v4`, `shivammathur/setup-php@v2` are pinned major versions). |
| Test bootstrap → vendor/autoload.php | Trusted — composer-managed. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-1-09 | Tampering | `.github/workflows/ci.yml` action versions | accept | `actions/checkout@v4` and `shivammathur/setup-php@v2` are major-version-pinned. Full SHA pinning is a best-practice Phase 5 hardening item; major-version pin is the established v1 pattern (PATTERNS.md confirms this is what v1 used). Risk is bounded by GitHub's marketplace review of these well-known actions. |
| T-1-10 | Information Disclosure | `tests/PluginBootstrapTest.php` reflection | accept | The test reads `Plugin.php` source with `file_get_contents` to assert the literal `'legacyDbService' => LegacyDbService::class` declaration. No secrets or runtime values are read; only static source code in the same repo. Standard PHPUnit pattern. |
| T-1-11 | Information Disclosure | `README.md` env var documentation | accept | Documents env var NAMES only (e.g., `CRAFT_LEGACY_DB_PASSWORD`), never EXAMPLE values like `password=hunter2`. Acceptance criteria do not enforce this directly but the action template uses placeholder words like `secret` and `sk-ant-...` rather than real-looking values. |

This plan ships no runtime surface that handles user input. T-1-01..T-1-04 are fully covered by Plans 02-04;
this plan's threats are all CI / docs hygiene and accepted at low severity.
</threat_model>

<verification>
After all four tasks:

1. `composer dump-autoload --no-interaction` exits 0.
2. `composer validate --strict --no-plugins` exits 0.
3. `composer test` exits 0 (PHPUnit suite green, non-empty).
4. `vendor/bin/phpunit --no-coverage --testdox` reports 3 passing tests.
5. `.github/workflows/ci.yml` parses as valid YAML.
6. README.md exists and contains the operator-essential content.
7. The three doc patches are applied:
   - `! grep -q 'legacy_class' .planning/REQUIREMENTS.md` (old column name absent in REQUIREMENTS.md).
   - `! grep -q 'legacy_class' .planning/PROJECT.md` (old column name absent in PROJECT.md).
   - `grep -q 'mapping-file validity check ships in Phase 2' .planning/REQUIREMENTS.md` (CONN-03 patched).
8. Cross-doc consistency: the column sets in REQUIREMENTS.md, PROJECT.md, and src/migrations/Install.php all reference the same 10 columns.
</verification>

<success_criteria>
- FND-05 satisfied: PHPUnit 11 wired, `composer test` exits 0, suite is non-empty per D-21 (3 passing tests).
- D-22 honored: single-job GitHub Actions workflow on PHP 8.3 / ubuntu-latest, validate + install + test, no Deptrac, no FQCN-lint, no matrix expansion.
- README.md ships operator minimum (install + env vars + doctor) — does not overpromise Phase 2-5 features.
- REQUIREMENTS.md FND-02 column list matches src/migrations/Install.php (no more contradiction).
- REQUIREMENTS.md CONN-03 acknowledges the mapping check ships in Phase 2 (no more 4-vs-3 mismatch).
- PROJECT.md Key Decisions row "Keep v1's `kunstmaanmigrator_state` schema verbatim" matches the actual schema.
- All Phase 1 success criteria from ROADMAP.md are now provably met by the combined output of Plans 01-05:
  - SC1 (composer install + plugin install): Plans 01-04.
  - SC2 (state table + kunstmaanSourceId field, UID-reuse): Plan 03.
  - SC3 (doctor reports 3 checks, exits non-zero on FAIL): Plan 04 + CONN-03 patch in this plan.
  - SC4 (CRAFT_ENVIRONMENT=production refusal): Plans 03+04 NeverProduction guards.
  - SC5 (composer test green + CI runs same): this plan.
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation-connectivity/01-05-SUMMARY.md`.
</output>
