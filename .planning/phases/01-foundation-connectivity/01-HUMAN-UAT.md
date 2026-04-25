---
status: partial
phase: 01-foundation-connectivity
source: [01-VERIFICATION.md]
started: 2026-04-25T00:00:00Z
updated: 2026-04-25T00:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Install plugin into a stock Craft 5 host
expected: `composer require lameco/craft-kunstmaan-migrator` resolves; `./craft plugin/install kunstmaan-migrator` reports success; `kunstmaanmigrator_state` table is created and `kunstmaanSourceId` Plain Text field appears in the Craft CP fields list with charLimit 255. (ROADMAP SC1 + SC2)
result: [pending]

### 2. Run `./craft kunstmaan-migrator/doctor` on a properly-configured dev host
expected: Three green OK lines: `legacyDb reachable`, `ANTHROPIC_API_KEY set`, `storage/migration writable (<absolute path>)`; final summary `Doctor: PASS`; exit 0. Then break each prerequisite (wrong DB password, unset ANTHROPIC_API_KEY, chmod 0555 storage/migration) one at a time and confirm each variant emits a red FAIL, exit 1, and all three checks still print (no short-circuit). (ROADMAP SC3 + CONN-03)
result: [pending]

### 3. Run `./craft kunstmaan-migrator/migrate/install` twice in a row
expected: First run reports migrations applied; second run is a no-op (table already exists, project-config UID already set) — neither errors. (FND-02a + D-07 idempotency)
result: [pending]

### 4. v1.x → v2 swap-in on a host with existing `kunstmaanSourceId` field UID + ~570-row state table (e.g. CQM)
expected: Install does NOT re-create the field with a new UID — D-09 step-1/step-2 reuse path fires (look for `Craft::info` log line `reusing existing field UID <uid>` in `storage/logs/`). The 570 existing rows in `kunstmaanmigrator_state` are preserved. (Headline correctness gate — ROADMAP SC2 second sentence)
result: [pending]

### 5. `CRAFT_ENVIRONMENT=production` refusal across both controllers
expected: `./craft kunstmaan-migrator/doctor` and `./craft kunstmaan-migrator/migrate/install` both refuse with `Refusing to run against CRAFT_ENVIRONMENT=production` printed in red on stderr; exit `ExitCode::UNSPECIFIED_ERROR` (1). No checks or migrations execute. (ROADMAP SC4 + FND-04)
result: [pending]

### 6. GitHub Actions CI run on the next push
expected: Workflow `CI / test` runs on PHP 8.3 / ubuntu-latest; executes `composer validate --strict --no-plugins`, `composer install`, `composer test`; all three steps green; total < 2 min. (ROADMAP SC5 second clause)
result: [pending]

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0
blocked: 0

## Gaps
