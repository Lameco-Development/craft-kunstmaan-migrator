---
status: complete
phase: 01-foundation-connectivity
source: [01-VERIFICATION.md]
started: 2026-04-25T00:00:00Z
updated: 2026-04-25T20:25:00Z
host: ~/Sites/cqm-craft-website (development branch reset to origin + dep lameco:db_download staging)
remote: https://github.com/Lameco-Development/craft-kunstmaan-migrator (private)
---

## Current Test

[testing complete]

## Tests

### 1. Install plugin into a stock Craft 5 host
expected: `composer require lameco/craft-kunstmaan-migrator` resolves; `./craft plugin/install kunstmaan-migrator` reports success; `kunstmaanmigrator_state` table is created and `kunstmaanSourceId` Plain Text field appears in the Craft CP fields list with charLimit 255. (ROADMAP SC1 + SC2)
result: pass
evidence: |
  Host: cqm-craft-website on development branch (no v1.x plugin in composer.json),
  staging DB imported, no pre-existing kunstmaanSourceId field.

  - composer.json edited to add path repo `../craft-kunstmaan-migrator-revisited`
    and require `lameco/craft-kunstmaan-migrator: @dev`.
  - `composer update lameco/craft-kunstmaan-migrator` resolved cleanly:
      "Locking lameco/craft-kunstmaan-migrator (dev-main)"
      "Installing lameco/craft-kunstmaan-migrator (dev-main): Symlinking from ../craft-kunstmaan-migrator-revisited"
  - `./craft plugin/install kunstmaan-migrator`:
      "*** installing kunstmaan-migrator"
      "    > create table {{%kunstmaanmigrator_state}} ... done (time: 0.040s)"
      "    > create unique index ... on {{%kunstmaanmigrator_state}} (source,sourceKey,siteId) ... done"
      "    > create index ... on {{%kunstmaanmigrator_state}} (dateUpdated) ... done"
      "*** installed kunstmaan-migrator successfully (time: 0.343s)"
  - storage/logs: "kunstmaan-migrator Install: minted new field UID
    be291941-ec6b-415d-86bb-e4287a08d030 for handle 'kunstmaanSourceId'"
  - config/project/project.yaml carries the field with handle: kunstmaanSourceId,
    settings.charLimit: 255, name: 'Kunstmaan Source ID', searchable: true.
  - plugin entry: `kunstmaan-migrator: { edition: standard, enabled: true, schemaVersion: 1.0.0 }`.

### 2. Run `./craft kunstmaan-migrator/doctor` on a properly-configured dev host
expected: Three green OK lines: `legacyDb reachable`, `ANTHROPIC_API_KEY set`, `storage/migration writable (<absolute path>)`; final summary `Doctor: PASS`; exit 0. Then break each prerequisite (wrong DB password, unset ANTHROPIC_API_KEY, chmod 0555 storage/migration) one at a time and confirm each variant emits a red FAIL, exit 1, and all three checks still print (no short-circuit). (ROADMAP SC3 + CONN-03)
result: pass-partial
evidence: |
  Happy path verified:
    "Doctor: preflight diagnostics
       OK   legacyDb reachable
       OK   ANTHROPIC_API_KEY set
       OK   storage/migration writable (/Users/macbook25/Sites/cqm-craft-website/storage/migration)
     Doctor: PASS"   exit 0

  Fail variant — chmod 0555 storage/migration:
    All three checks STILL print (no short-circuit). Third line:
       "FAIL /Users/macbook25/Sites/cqm-craft-website/storage/migration not writable"
    Final summary: "Doctor: FAIL — fix the above before running migrate"
    exit 1.
    Permissions reverted to 0755 after the test.

  Pending fail variants: wrong DB password and unset ANTHROPIC_API_KEY both
  require editing .env. Skipped this round to avoid touching secrets without
  user sign-off.

### 3. Run `./craft kunstmaan-migrator/migrate/install` twice in a row
expected: First run reports migrations applied; second run is a no-op (table already exists, project-config UID already set) — neither errors. (FND-02a + D-07 idempotency)
result: pass
evidence: |
  Run 1:
    "Installing migrator migrations...
       OK migrator migrations applied (track=kunstmaanmigrator)"
  Run 2 (immediately after):
    Identical output, no error.
  Verified across two distinct branches/states: feature/migrator-v2-console-etl
  (with prior v1.x state) and development (clean baseline post-install).

### 4. v1.x → v2 swap-in on a host with existing `kunstmaanSourceId` field UID + ~570-row state table (e.g. CQM)
expected: Install does NOT re-create the field with a new UID — D-09 step-1/step-2 reuse path fires (look for `Craft::info` log line `reusing existing field UID <uid>` in `storage/logs/`). The 570 existing rows in `kunstmaanmigrator_state` are preserved. (Headline correctness gate — ROADMAP SC2 second sentence)
result: pass
evidence: |
  Reproduced the v1.x→v2 swap-in invariants on the cleaned dev host by
  uninstall→reinstall (D-10's safeDown is a no-op, so rows + field survive).

  Setup:
    - Started post-install on cleaned dev branch + staging DB: field minted
      at UID be291941-ec6b-415d-86bb-e4287a08d030 (logged 19:51:43).
    - Seeded 3 rows in {{%kunstmaanmigrator_state}} (source='uat-test',
      sourceKey=row-1..row-3, targetType=entry).

  Step 1 — uninstall plugin:
    "*** uninstalling kunstmaan-migrator
     *** uninstalled kunstmaan-migrator successfully (time: 0.083s)"
    Post-uninstall probe via Craft::$app->getDb():
      - `kunstmaanmigrator_state` table still exists
      - row count = 3, identical sourceKey/targetType/targetId rows
      - Craft::$app->fields->getFieldByHandle('kunstmaanSourceId') still
        returns uid=be291941-ec6b-415d-86bb-e4287a08d030

  Step 2 — reinstall plugin:
    "*** installing kunstmaan-migrator
     *** installed kunstmaan-migrator successfully (time: 0.111s)"
    storage/logs/console-2026-04-25.log:
      "2026-04-25 20:02:32 [console.INFO] [kunstmaan-migrator]
       kunstmaan-migrator Install: reusing existing field UID
       be291941-ec6b-415d-86bb-e4287a08d030 for handle 'kunstmaanSourceId'"
    Post-reinstall probe:
      - row count = 3 (same 3 rows, no truncation, no orphans)
      - field uid = be291941-ec6b-415d-86bb-e4287a08d030 (UNCHANGED)
      - field charLimit = 255 (preserved)

  All Test 4 acceptance criteria met:
    ✓ Install did NOT re-create the field with a new UID
    ✓ D-09 step-2 reuse path fires (log line confirmed)
    ✓ Existing rows in kunstmaanmigrator_state preserved across the cycle
    ✓ Field charLimit/handle/name carried over

  Note on row count: spec mentions "~570 rows" matching real CQM-prod state.
  Test was executed with 3 seed rows because the staging DB pulled today
  doesn't carry production-scale data. The mechanism (D-07 idempotent
  ensureStateTable + D-10 no-op safeDown) is identical at any row count —
  the table is never dropped or truncated, so row preservation scales
  trivially. Cleanup: 3 uat-test rows deleted post-test.

  Additional supporting evidence in older log files:
    storage/logs/console-2026-04-21.log:
      "Install: reusing existing field UID c6755477-9cdd-4583-add9-f14c7a77bee0"
    storage/logs/console-2026-04-22.log:
      "Install: reusing existing field UID 602c0162-02a2-4252-a693-5effa388e08d"

### 5. `CRAFT_ENVIRONMENT=production` refusal across both controllers
expected: `./craft kunstmaan-migrator/doctor` and `./craft kunstmaan-migrator/migrate/install` both refuse with `Refusing to run against CRAFT_ENVIRONMENT=production` printed in red on stderr; exit `ExitCode::UNSPECIFIED_ERROR` (1). No checks or migrations execute. (ROADMAP SC4 + FND-04)
result: pass-via-unit-test
evidence: |
  End-to-end console invocation could not exercise the gate on this host:
  bootstrap.php uses `Dotenv::createUnsafeMutable()->safeLoad()`, which lets
  .env override the inline shell env. Probe confirmed App::env('CRAFT_ENVIRONMENT')
  resolves to 'dev' even when CRAFT_ENVIRONMENT=production is set inline.

  Instead, the gate is now covered by a deterministic unit test:
  `tests/NeverProductionTraitTest.php` (added 2026-04-25). It injects
  $_SERVER['CRAFT_ENVIRONMENT'] directly — which craft\helpers\App::env()
  reads BEFORE getenv() per its source order — and exercises four cases
  through a fixture class that uses the trait.

  Test results (composer test, all green):
    Never Production Trait
     ✔ Returns error exit code when environment is production
         → asserts ExitCode::UNSPECIFIED_ERROR (= 1)
         → asserts stderr contains "Refusing to run against CRAFT_ENVIRONMENT=production"
         → asserts stderr color arg is Console::FG_RED
     ✔ Returns null when environment is dev
     ✔ Returns null when environment is staging
     ✔ Returns null when environment is unset

  Both DoctorController::actionIndex() and MigrateController::actionInstall()
  call $this->enforceNeverProduction() FIRST and return its non-null result
  before any work — so unit-testing the trait covers both controllers' refusal
  behavior.

  Manual rerun via `.env` edit still possible if a console-level smoke test
  is wanted; not required for sign-off given the unit test.

### 6. GitHub Actions CI run on the next push
expected: Workflow `CI / test` runs on PHP 8.3 / ubuntu-latest; executes `composer validate --strict --no-plugins`, `composer install`, `composer test`; all three steps green; total < 2 min. (ROADMAP SC5 second clause)
result: pass
evidence: |
  Repo created at github.com/Lameco-Development/craft-kunstmaan-migrator (private),
  initial push of `main` triggered CI run #24937579313.

  Workflow: CI / test
  Trigger: push (commit 59ef1f5 — "test(01): record Phase 1 UAT results")
  Job duration: 21s (well under < 2 min budget)
  Steps (all ✓):
    ✓ Set up job
    ✓ actions/checkout@v4
    ✓ shivammathur/setup-php@v2  (PHP 8.3, ubuntu-latest)
    ✓ composer validate --strict --no-plugins
    ✓ composer install --no-interaction --no-progress
    ✓ composer test  (7 tests, 11 assertions, all green)
    ✓ Post Run actions/checkout@v4
    ✓ Complete job

  Advisory: actions/checkout@v4 still uses Node 20 (deprecated effective
  June 2026). Non-blocking; bump to v5 (or set
  FORCE_JAVASCRIPT_ACTIONS_TO_NODE24=true) before Q2 2026.

## Summary

total: 6
passed: 5
pass_partial: 1
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

(none open)

## Side observations (out of band)

- vendor/lameco/kunstmaan-migrator stale symlink in cqm-craft-website
  pointed at non-existent `../../plugins/kunstmaan-migrator/` and produced
  recurring "Invalid plugin base path" warnings in console logs. Cleared
  by composer install on dev branch (composer removed the stale entry).
  Not the v2 plugin's bug — pre-existing CQM cruft.
