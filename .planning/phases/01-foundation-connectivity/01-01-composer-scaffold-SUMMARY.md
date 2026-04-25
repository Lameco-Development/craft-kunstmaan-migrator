---
phase: 01-foundation-connectivity
plan: 01
subsystem: infra
tags: [composer, craft-plugin, psr-4, autoload, php83]

# Dependency graph
requires: []
provides:
  - "Composer manifest type=craft-plugin (lameco/craft-kunstmaan-migrator)"
  - "PSR-4 autoload contract: lameco\\kunstmaanmigrator\\ -> src/"
  - "Plugin entrypoint stub at lameco\\kunstmaanmigrator\\Plugin (schemaVersion=1.0.0, hasCpSettings=true)"
  - "NeverProductionTrait at lameco\\kunstmaanmigrator\\NeverProductionTrait (verbatim v1 port)"
  - ".gitignore excluding /vendor/, /composer.lock, PHPUnit caches, /storage/"
affects: [01-02-settings-legacydb, 01-03-doctor-controller, 01-04-migrate-install, 01-05-tests-ci, all-phase-2-plus]

# Tech tracking
tech-stack:
  added: [composer/craftcms-cms ^5.0, symfony/yaml ^6|^7, guzzlehttp/guzzle ^7, phpunit/phpunit ^11]
  patterns:
    - "Optional adapters via composer suggest (SEOmatic / Retour) — not require"
    - "Plugin schemaVersion 1.0.0 (D-08 — v2 declares fresh, not v1's 2.0.0)"
    - "NeverProduction guard: enforceNeverProduction() returns ?int (ExitCode or null)"

key-files:
  created:
    - composer.json
    - src/Plugin.php
    - src/NeverProductionTrait.php
    - .gitignore
  modified: []

key-decisions:
  - "D-08 honored: schemaVersion declared as 1.0.0 (treat v2 as fresh plugin)"
  - "D-23 honored: NeverProductionTrait ported byte-for-byte from v1 (no declare(strict_types=1))"
  - "D-24 honored: SEOmatic + Retour moved from require to suggest; Deptrac + Rector dropped"
  - "D-25 honored: composer extra block uses handle=kunstmaan-migrator, schemaVersion=1.0.0"
  - "Plugin.php is properties-only stub — init() body deliberately deferred to Plan 02 per the plan's design"

patterns-established:
  - "Optional adapter pattern: composer suggest entries instead of hard requires (D-24)"
  - "Verbatim brownfield ports: NeverProductionTrait shipped byte-for-byte (D-23)"
  - "Properties-only Plugin stub at the FQCN every Wave 2/3 plan extends"

requirements-completed: [FND-01]

# Metrics
duration: 8min
completed: 2026-04-25
---

# Phase 01 Plan 01: composer-scaffold Summary

**Greenfield repo bootstrapped with valid craft-plugin composer manifest, properties-only Plugin entrypoint at the declared FQCN, and verbatim NeverProductionTrait port — autoload contract that every downstream Phase 1 plan depends on is verified working.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-04-25T15:40:00Z (approximate)
- **Completed:** 2026-04-25T15:48:33Z
- **Tasks:** 4 (3 code + 1 verification-only)
- **Files created:** 4 (composer.json, src/Plugin.php, src/NeverProductionTrait.php, .gitignore)
- **Files modified:** 0

## Accomplishments

- `composer.json` validates `--strict` and installs cleanly
- PSR-4 autoload resolves both `lameco\kunstmaanmigrator\Plugin` and `lameco\kunstmaanmigrator\NeverProductionTrait`
- `Plugin::$schemaVersion === '1.0.0'` and `Plugin::$hasCpSettings === true` confirmed via reflection
- SEOmatic / Retour are `suggest` entries, NOT `require` — the plugin installs cleanly on hosts that have neither (D-24)
- Deptrac + Rector dev deps dropped (D-24 — three-tier layout retired)
- NeverProductionTrait ported byte-for-byte from v1 (D-23) — identical method body, namespace, and use statements
- `.gitignore` correctly excludes `/vendor/` and `/composer.lock` (this is a library/plugin, not an app)

## Task Commits

Each task was committed atomically:

1. **Task 1: Write composer.json with craft-plugin manifest** — `0c8061e` (feat)
2. **Task 2: Port NeverProductionTrait verbatim** — `f8c1719` (feat)
3. **Task 3: Stub Plugin.php with properties + .gitignore** — `b608527` (feat)
4. **Task 4: Verify autoload + plugin discovery contract** — verification-only, no commit

## Files Created/Modified

- `composer.json` — craft-plugin manifest. Declares lameco/craft-kunstmaan-migrator, type=craft-plugin, PSR-4 autoload, extra block (handle, schemaVersion 1.0.0, class FQCN), composer test script
- `src/Plugin.php` — properties-only Plugin stub extending `craft\base\Plugin`. Declares `schemaVersion = '1.0.0'` (D-08) and `hasCpSettings = true` (D-16). `init()` and settings hooks deferred to Plan 02 by the plan's design
- `src/NeverProductionTrait.php` — verbatim port of v1's 19-line trait. `enforceNeverProduction()` returns `ExitCode::UNSPECIFIED_ERROR` on production, null otherwise
- `.gitignore` — excludes /vendor/, /composer.lock, /.phpunit.cache/, /.phpunit.result.cache, /storage/, .DS_Store

## Decisions Made

None beyond the ones already locked in `01-CONTEXT.md` (D-08, D-16, D-23, D-24, D-25). Plan executed exactly as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] First `composer install` invocation failed because Plugin.php did not yet exist**

- **Found during:** Task 1 (running `composer install --no-interaction --no-progress --no-scripts`)
- **Issue:** craftcms/plugin-installer activates as a Composer plugin during install and tries to resolve the package's class FQCN. With Plugin.php not yet on disk (it lands in Task 3), it errored: "Couldn't install lameco/craft-kunstmaan-migrator: Unable to determine the base path", uninstalled itself, and aborted the install. PATTERNS.md flags this exact gotcha for `composer validate`; it applies to `install` too.
- **Fix:** Re-ran with `composer install --no-interaction --no-progress --no-scripts --no-plugins` for Task 1's install gate. After Task 3 created `src/Plugin.php`, re-ran `composer install` WITHOUT `--no-plugins` — succeeded cleanly. The acceptance criterion (`vendor/autoload.php` exists, install succeeds) is fully satisfied as of post-Task-3 state.
- **Files modified:** None — this was a tooling-invocation fix, not a code change. `composer.json` content was correct on the first write.
- **Verification:** Final `composer install --no-interaction --no-progress --no-scripts` (no `--no-plugins`) exits 0, `vendor/autoload.php` exists, `composer dump-autoload --classmap-authoritative` resolves the FQCNs.
- **Committed in:** N/A — no code change; behaviour-only adjustment to invocation order.

---

**Total deviations:** 1 auto-fixed (1 blocking — composer install ordering)
**Impact on plan:** None. Bootstrap-order quirk only — once Plugin.php exists (Task 3), `composer install` works without flags. Acceptance criteria all met.

## Issues Encountered

- Composer 2.8.8 emits noisy deprecation notices on PHP 8.5 (case statements with `;`, `$http_response_header` variable). These are upstream Composer issues, not plugin issues — they don't affect exit codes or behaviour and were filtered out of acceptance-criterion checks via `tail`.

## User Setup Required

None — no external service configuration required at this stage.

## Next Phase Readiness

**Ready for Plan 02 (Settings + LegacyDb wiring) and downstream plans:**

- `lameco\kunstmaanmigrator\Plugin` is autoload-resolvable — Plan 02 fills `init()`, `createSettingsModel()`, `settingsHtml()`, and `config()`.
- `lameco\kunstmaanmigrator\NeverProductionTrait` is autoload-resolvable — Plans 03 and 04 (DoctorController, MigrateController) consume it.
- PSR-4 autoload + composer install work end-to-end — Plans 02-05 can add new files under `src/` and `tests/` without manifest changes.
- `composer test` script is wired (D-21) — Plan 05 just needs to drop a `phpunit.xml` and the suite will run.

**No blockers.**

## Self-Check: PASSED

- composer.json: FOUND
- src/Plugin.php: FOUND
- src/NeverProductionTrait.php: FOUND
- .gitignore: FOUND
- Commit 0c8061e: FOUND
- Commit f8c1719: FOUND
- Commit b608527: FOUND

---
*Phase: 01-foundation-connectivity*
*Completed: 2026-04-25*
