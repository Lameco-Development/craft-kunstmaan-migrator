---
phase: 01-foundation-connectivity
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - composer.json
  - src/Plugin.php
  - src/NeverProductionTrait.php
  - .gitignore
autonomous: true
requirements: [FND-01]
must_haves:
  truths:
    - "Greenfield repo has a runnable composer manifest declaring the Craft plugin."
    - "Plugin entrypoint class exists at the declared FQCN with the correct schemaVersion."
    - "NeverProductionTrait is available for downstream controllers (Plans 03 and 04) to consume."
  artifacts:
    - path: composer.json
      provides: "Composer manifest, type=craft-plugin, PSR-4 autoload, extra block"
      contains: '"lameco/craft-kunstmaan-migrator"'
    - path: src/Plugin.php
      provides: "Plugin entrypoint stub (props only — init() body lands in Plan 02)"
      contains: "class Plugin extends BasePlugin"
    - path: src/NeverProductionTrait.php
      provides: "Production-environment guard ported byte-for-byte from v1"
      contains: "trait NeverProductionTrait"
  key_links:
    - from: composer.json
      to: src/Plugin.php
      via: "extra.class -> lameco\\\\kunstmaanmigrator\\\\Plugin and PSR-4 autoload"
      pattern: 'lameco\\\\\\\\kunstmaanmigrator\\\\\\\\Plugin'
    - from: composer.json
      to: src/
      via: "autoload psr-4 lameco\\\\kunstmaanmigrator\\\\ -> src/"
      pattern: '"lameco\\\\\\\\kunstmaanmigrator\\\\\\\\": "src/"'
---

<objective>
Bootstrap the greenfield repo with a valid `craft-plugin` composer manifest, a minimal `Plugin.php` stub
(properties only — no `init()` body yet), and the verbatim `NeverProductionTrait` port. After this plan,
`composer install` succeeds and `composer validate --strict` is green. The plugin does NOT yet wire its
legacy DB component or controllers — those land in Plan 02 (Settings + legacy DB) and Plans 03/04
(controllers). This plan establishes the autoload contract every downstream plan depends on.

Purpose: Without this skeleton, no other Phase 1 plan can run — Plans 02-05 all depend on the autoload
target and the Plugin FQCN.
Output: A composer-installable plugin skeleton.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/STATE.md
@.planning/phases/01-foundation-connectivity/01-CONTEXT.md
@.planning/phases/01-foundation-connectivity/01-PATTERNS.md
@CLAUDE.md
</context>

<interfaces>
<!-- This plan creates the Plugin FQCN that Plans 02-05 will extend or import. -->
<!-- Stub shape only; Plan 02 fills init(), createSettingsModel(), settingsHtml(). -->

```php
namespace lameco\kunstmaanmigrator;

use craft\base\Plugin as BasePlugin;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';   // D-08
    public bool $hasCpSettings = true;         // D-16
}
```

```php
namespace lameco\kunstmaanmigrator;

trait NeverProductionTrait
{
    protected function enforceNeverProduction(): ?int;  // returns ExitCode::UNSPECIFIED_ERROR on prod, null otherwise
}
```
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: Write composer.json with craft-plugin manifest</name>
  <files>composer.json</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/composer.json (v1 reference — diff per D-24/D-25 in PATTERNS.md "composer.json" section)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (lines for "composer.json" — exact target shape, lines 707-774)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-24, D-25)
  </read_first>
  <action>
    Create `composer.json` at the repo root matching the target shape in PATTERNS.md (lines 725-770). Concrete values per D-24/D-25:

    - `"name": "lameco/craft-kunstmaan-migrator"`
    - `"description": "Kunstmaan → Craft CMS migration plugin — knowledge-first ETL with AI-assisted mapping proposals."`
    - `"type": "craft-plugin"`
    - `"license": "MIT"`
    - `"authors": [{ "name": "Lameco Development", "email": "development@lameco.nl" }]`
    - `require`:
      - `"php": "^8.3"`
      - `"craftcms/cms": "^5.0"`
      - `"symfony/yaml": "^6.0 || ^7.0"`
      - `"guzzlehttp/guzzle": "^7.0"`
    - `suggest` (NOT require — D-24):
      - `"nystudio107/craft-seomatic": "Enables the SEOmatic adapter (^5.1)."`
      - `"nystudio107/craft-retour": "Enables the Retour adapter (^5.0)."`
    - `require-dev`:
      - `"phpunit/phpunit": "^11.0"`
    - `autoload.psr-4`: `{ "lameco\\kunstmaanmigrator\\": "src/" }`
    - `autoload-dev.psr-4`: `{ "lameco\\kunstmaanmigrator\\tests\\": "tests/" }`
    - `scripts.test`: `"vendor/bin/phpunit"`  (D-21 — composer test runs the suite; Plan 05 wires phpunit.xml.dist)
    - `extra`:
      - `"handle": "kunstmaan-migrator"`
      - `"name": "Kunstmaan Migrator"`
      - `"class": "lameco\\kunstmaanmigrator\\Plugin"`
      - `"schemaVersion": "1.0.0"`  (D-08 — v2 declares 1.0.0, NOT v1's 2.0.0)
      - `"developer": "Lameco Development"`
    - `config`:
      - `"sort-packages": true`
      - `"allow-plugins": { "craftcms/plugin-installer": true, "yiisoft/yii2-composer": true }`

    EXPLICITLY DROP from v1 (do NOT include these — D-24):
    - `require-dev.deptrac/deptrac` (three-tier layout retired)
    - `require-dev.rector/rector` (re-add when there's a real refactor driver)
    - `scripts.post-autoload-dump` clear-caches block (was tied to v1's CP utility)
    - `scripts.lint-fqcn` (v1-specific brownfield-tier-mismatch tool)
    - `archive.exclude` (defer to release prep in Phase 5)
    - SEOmatic / Retour from `require` (now in `suggest` per D-24)

    Validate JSON syntax before declaring done.
  </action>
  <acceptance_criteria>
    - `composer.json` exists at repo root.
    - `grep -q '"name": "lameco/craft-kunstmaan-migrator"' composer.json` exits 0.
    - `grep -q '"type": "craft-plugin"' composer.json` exits 0.
    - `grep -q '"php": "\\^8.3"' composer.json` exits 0.
    - `grep -q '"craftcms/cms": "\\^5.0"' composer.json` exits 0.
    - `grep -q '"phpunit/phpunit": "\\^11.0"' composer.json` exits 0.
    - `grep -q '"schemaVersion": "1.0.0"' composer.json` exits 0.
    - `grep -q '"handle": "kunstmaan-migrator"' composer.json` exits 0.
    - `grep -q '"class": "lameco.\\\\kunstmaanmigrator.\\\\Plugin"' composer.json` exits 0  (escaped backslashes — match the JSON `lameco\\kunstmaanmigrator\\Plugin`).
    - `grep -q '"lameco.\\\\kunstmaanmigrator.\\\\": "src/"' composer.json` exits 0.
    - `grep -q '"test": "vendor/bin/phpunit"' composer.json` exits 0.
    - `grep -q '"nystudio107/craft-seomatic"' composer.json` exits 0 (in `suggest` block — NOT `require`).
    - `! grep -q '"deptrac/deptrac"' composer.json` (must be absent).
    - `! grep -q '"rector/rector"' composer.json` (must be absent).
    - `! grep -q '"post-autoload-dump"' composer.json` (must be absent).
    - In the `require` block, neither SEOmatic nor Retour appear: `! awk '/"require":/,/^    },?$/' composer.json | grep -q seomatic` and same for retour.
    - `php -r 'json_decode(file_get_contents("composer.json")); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);'` exits 0 (valid JSON).
    - `composer validate --strict --no-plugins composer.json` exits 0.
    - `composer install --no-interaction --no-progress --no-scripts` exits 0 and creates `vendor/autoload.php` (use `--no-scripts` to avoid running phpunit before Plan 05 ships it; the install itself must succeed).
  </acceptance_criteria>
  <verify>
    <automated>composer validate --strict --no-plugins composer.json &amp;&amp; composer install --no-interaction --no-progress --no-scripts</automated>
  </verify>
  <done>composer.json exists, validates strict, and `composer install --no-scripts` produces vendor/.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: Port NeverProductionTrait verbatim</name>
  <files>src/NeverProductionTrait.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/NeverProductionTrait.php (full file — port byte-for-byte per D-23)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/NeverProductionTrait.php", lines 128-167)
  </read_first>
  <action>
    Create `src/NeverProductionTrait.php` with the exact content shown in PATTERNS.md lines 137-156. Per D-23 the file is 39 lines and ports byte-for-byte from v1 — only the namespace declaration is reviewed (it must be `lameco\kunstmaanmigrator`, which matches v1).

    Concrete file content (this IS the file):

    ```php
    <?php

    namespace lameco\kunstmaanmigrator;

    use craft\helpers\App;
    use craft\helpers\Console;
    use yii\console\ExitCode;

    trait NeverProductionTrait
    {
        protected function enforceNeverProduction(): ?int
        {
            if (App::env('CRAFT_ENVIRONMENT') === 'production') {
                $this->stderr("Refusing to run against CRAFT_ENVIRONMENT=production\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            return null;
        }
    }
    ```

    Do NOT add `declare(strict_types=1)` — v1 doesn't have it, and consumers (controllers extending `craft\console\Controller`) inherit from a non-strict superclass. Mirror v1 exactly.

    The `$this->stderr()` call assumes the consuming class extends a Yii Controller (which provides `stderr()`). That's enforced by the caller pattern — not by this file.
  </action>
  <acceptance_criteria>
    - `src/NeverProductionTrait.php` exists.
    - `grep -q "namespace lameco\\\\kunstmaanmigrator;" src/NeverProductionTrait.php` exits 0.
    - `grep -q "trait NeverProductionTrait" src/NeverProductionTrait.php` exits 0.
    - `grep -q "protected function enforceNeverProduction(): ?int" src/NeverProductionTrait.php` exits 0.
    - `grep -q "App::env('CRAFT_ENVIRONMENT') === 'production'" src/NeverProductionTrait.php` exits 0.
    - `grep -q "ExitCode::UNSPECIFIED_ERROR" src/NeverProductionTrait.php` exits 0.
    - `php -l src/NeverProductionTrait.php` exits 0 (no syntax errors).
  </acceptance_criteria>
  <verify>
    <automated>php -l src/NeverProductionTrait.php</automated>
  </verify>
  <done>NeverProductionTrait file exists at the declared FQCN, lints clean, content matches D-23 byte-for-byte port intent.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 3: Stub Plugin.php with properties + .gitignore</name>
  <files>src/Plugin.php, .gitignore</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/Plugin.php (lines 107-118 — schemaVersion + hasCpSettings; line 64-93 docblock — but Phase 1 docblock is one line per PATTERNS.md)
    - .planning/phases/01-foundation-connectivity/01-PATTERNS.md (section "src/Plugin.php", lines 28-125 — but Plan 01 ONLY ships the class skeleton at lines 34-40; init() and settings hooks land in Plan 02)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-08, D-16)
  </read_first>
  <action>
    Create `src/Plugin.php` as a STUB — properties only. The `init()`, `createSettingsModel()`, `settingsHtml()`, and `config()` methods are deliberately deferred to Plan 02 (which depends on Settings + LegacyDbService classes that don't exist yet).

    Concrete file content:

    ```php
    <?php

    declare(strict_types=1);

    namespace lameco\kunstmaanmigrator;

    use craft\base\Plugin as BasePlugin;

    /**
     * Kunstmaan → Craft Migrator plugin entrypoint.
     *
     * Phase 1 / Plan 01 ships the class skeleton (properties only).
     * Plan 02 wires the legacyDb Yii component, controllerNamespace switch,
     * createSettingsModel(), and settingsHtml() once Settings + LegacyDbService land.
     */
    class Plugin extends BasePlugin
    {
        // D-08: v2 declares schemaVersion 1.0.0 (NOT v1.x's 2.0.0).
        // On v1.x→v2 swap-in hosts the declared version is below the installed version,
        // which is fine because Install.php's `tableExists` guard makes re-runs safe.
        public string $schemaVersion = '1.0.0';

        // D-16: enables the CP Settings page. The placeholder template ships in Plan 02;
        // the real form lives in Phase 4 / CFG-01.
        public bool $hasCpSettings = true;
    }
    ```

    Also create a minimal `.gitignore` at the repo root with:

    ```
    /vendor/
    /composer.lock
    /.phpunit.cache/
    /.phpunit.result.cache
    /storage/
    .DS_Store
    ```

    (Composer.lock is excluded because this is a library/plugin, not an application — Composer's official guidance.)
  </action>
  <acceptance_criteria>
    - `src/Plugin.php` exists.
    - `grep -q "namespace lameco\\\\kunstmaanmigrator;" src/Plugin.php` exits 0.
    - `grep -q "class Plugin extends BasePlugin" src/Plugin.php` exits 0.
    - `grep -q "public string \\\$schemaVersion = '1.0.0';" src/Plugin.php` exits 0.
    - `grep -q "public bool \\\$hasCpSettings = true;" src/Plugin.php` exits 0.
    - `grep -q "use craft\\\\base\\\\Plugin as BasePlugin;" src/Plugin.php` exits 0.
    - `php -l src/Plugin.php` exits 0.
    - `.gitignore` exists at repo root.
    - `grep -q "^/vendor/" .gitignore` exits 0.
    - `grep -q "^/composer.lock" .gitignore` exits 0.
    - `grep -q "^/.phpunit.cache/" .gitignore` exits 0.
  </acceptance_criteria>
  <verify>
    <automated>php -l src/Plugin.php &amp;&amp; test -f .gitignore</automated>
  </verify>
  <done>Plugin.php stub exists with the two declared properties; .gitignore committed; plugin FQCN is autoload-resolvable after composer dump-autoload.</done>
</task>

<task type="auto" tdd="false">
  <name>Task 4: Verify autoload + plugin discovery contract</name>
  <files>(no files modified — verification-only)</files>
  <read_first>
    - composer.json (just-written file)
    - src/Plugin.php (just-written stub)
  </read_first>
  <action>
    Run a one-liner verification script that proves PSR-4 autoload resolves both `Plugin` and `NeverProductionTrait` to the files just created. This catches namespace typos, autoload-prefix mismatches, and missing `composer dump-autoload` invocations BEFORE Plans 02-05 build on this base.

    Run, in order:

    1. `composer dump-autoload --no-interaction --classmap-authoritative` — regenerates the autoload map (already done by `composer install` but explicit).
    2. The verification script (paste-ready):

    ```bash
    php -r '
    require __DIR__ . "/vendor/autoload.php";
    $errors = [];
    if (!class_exists("lameco\\kunstmaanmigrator\\Plugin", true)) { $errors[] = "Plugin class not autoloadable"; }
    if (!trait_exists("lameco\\kunstmaanmigrator\\NeverProductionTrait", true)) { $errors[] = "NeverProductionTrait not autoloadable"; }
    $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\Plugin");
    $sv = $rc->getDefaultProperties()["schemaVersion"] ?? null;
    if ($sv !== "1.0.0") { $errors[] = "schemaVersion is " . var_export($sv, true) . ", expected 1.0.0"; }
    $hcs = $rc->getDefaultProperties()["hasCpSettings"] ?? null;
    if ($hcs !== true) { $errors[] = "hasCpSettings is " . var_export($hcs, true) . ", expected true"; }
    if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\n"); exit(1); }
    echo "OK: Plugin + NeverProductionTrait autoloadable, schemaVersion=1.0.0, hasCpSettings=true\n";
    '
    ```

    If this script exits 0, the autoload contract every downstream Phase 1 plan depends on is verified working.

    Do NOT attempt to instantiate `Plugin` here — that requires a Craft application bootstrap which Plan 01 does not set up. FQCN resolution + property reflection is the right depth for this gate.
  </action>
  <acceptance_criteria>
    - `composer dump-autoload --no-interaction --classmap-authoritative` exits 0.
    - The verification script above exits 0.
    - The verification script's stdout contains `OK: Plugin + NeverProductionTrait autoloadable`.
  </acceptance_criteria>
  <verify>
    <automated>composer dump-autoload --no-interaction --classmap-authoritative &amp;&amp; php -r 'require __DIR__ . "/vendor/autoload.php"; if (!class_exists("lameco\\kunstmaanmigrator\\Plugin", true)) { exit(1); } if (!trait_exists("lameco\\kunstmaanmigrator\\NeverProductionTrait", true)) { exit(1); } $rc = new ReflectionClass("lameco\\kunstmaanmigrator\\Plugin"); if (($rc->getDefaultProperties()["schemaVersion"] ?? null) !== "1.0.0") { exit(1); } echo "OK\n";'</automated>
  </verify>
  <done>PSR-4 autoload resolves Plugin + NeverProductionTrait FQCNs; schemaVersion + hasCpSettings reflect the declared values; downstream plans can rely on the autoload contract.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| (none in Plan 01) | This plan ships only static manifest + class skeleton. No I/O surfaces, no controllers, no DB connection. Trust boundaries appear in Plans 02 (legacy DB credentials) and 04 (CLI invocation surface). |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-1-00 | Tampering | composer.json | accept | Standard repo hygiene (git history, code review). No threat-specific mitigation owed at this layer — composer.json is a manifest, not a runtime entrypoint. |

Plan 01 ships no runtime surface. The Phase 1 threat register (T-1-01..T-1-04) is owned by Plans 02-04
where legacy DB credentials, NeverProduction enforcement, Anthropic key handling, and storage-dir creation
actually surface.
</threat_model>

<verification>
After all four tasks:

1. `composer validate --strict --no-plugins composer.json` exits 0.
2. `composer install --no-interaction --no-progress --no-scripts` exits 0 and `vendor/autoload.php` exists.
3. `php -l src/Plugin.php` and `php -l src/NeverProductionTrait.php` both exit 0.
4. The Task 4 reflection script confirms autoload contracts.
5. No file in `src/` outside `Plugin.php` and `NeverProductionTrait.php` exists yet (other plans own the rest).
</verification>

<success_criteria>
- Composer manifest passes `validate --strict`.
- Plugin FQCN resolves via PSR-4 autoload to a class declaring `schemaVersion = '1.0.0'` and `hasCpSettings = true`.
- NeverProductionTrait is autoloadable and ports v1's 39-line file byte-for-byte (D-23).
- Downstream plans can rely on `lameco\kunstmaanmigrator\Plugin` and `lameco\kunstmaanmigrator\NeverProductionTrait` being autoload-resolvable.
- `composer install` (without scripts — phpunit not yet wired) completes successfully.
- This plan does NOT yet claim "plugin loads in Craft" — that gate is Plan 02 (init() body wires the host integration).
</success_criteria>

<output>
After completion, create `.planning/phases/01-foundation-connectivity/01-01-SUMMARY.md`.
</output>
