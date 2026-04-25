---
phase: 01-foundation-connectivity
plan: 02
subsystem: connectivity
tags: [settings, legacy-db, yii-component, env-fallback, plugin-bootstrap]

# Dependency graph
requires:
  - "01-01: PSR-4 autoload contract + Plugin entrypoint stub"
provides:
  - "Settings model (lameco\\kunstmaanmigrator\\models\\Settings) — full v2 surface (8 read-active + 8 declared)"
  - "LegacyDbService Yii Component (lameco\\kunstmaanmigrator\\db\\LegacyDbService) — 5 read-only methods"
  - "Plugin::init() — conditional Craft::\\$app->set('legacyDb', ...) registration"
  - "Plugin::config() — declares legacyDbService component"
  - "Plugin::createSettingsModel() / settingsHtml() — wired to Settings + placeholder template"
  - "src/templates/_settings.twig — D-16 placeholder, no secret echo"
  - "Plugin::getInstance()->legacyDbService accessor (consumed by Plan 04 doctor)"
  - "Plugin::getInstance()->getSettings() accessor (consumed by Plan 04 doctor + Plan 03 install via Craft::\\$app->set context)"
affects: [01-03-install-migration, 01-04-doctor-command, 01-05-tests-ci, all-phase-2-plus]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Yii Component for plugin services with Craft::\\$app->get('legacyDb') resolution per call (test-double-friendly)"
    - "Conditional Yii application-component registration via !Craft::\\$app->has('legacyDb', true) guard (D-11)"
    - "EnvAttributeParserBehavior on Settings model with App::env() fallback in init() using ??= (D-12, D-15)"
    - "@property-read docblock + Plugin::config() components array for IDE-autocompletable accessor"

key-files:
  created:
    - src/models/Settings.php
    - src/db/LegacyDbService.php
    - src/templates/_settings.twig
  modified:
    - src/Plugin.php

key-decisions:
  - "D-11 honored: legacyDb registered only when !Craft::\\$app->has('legacyDb', true). Swap-in hosts retain their config/app.php declaration; greenfield hosts get the plugin's env-driven Connection."
  - "D-12 honored: Settings sources legacyDb* from CRAFT_LEGACY_DB_* env vars; config/kunstmaan-migrator.php overrides win via ??= idiom."
  - "D-13 honored: LegacyDbService ships only db/queryOne/queryAll/queryScalar/streamQuery. Domain helpers (streamLiveNodes, translationsFor, etc.) deferred to Phases 2-4. Read-only discipline verified by grep — file contains zero write-op symbols."
  - "D-14 honored: anthropicApiKey resolves from ANTHROPIC_API_KEY env, Settings property override wins. Never echoed/logged from Settings class."
  - "D-15 honored: Settings declares full v2 surface upfront (8 read-active + 8 declared). Phase 4 / CFG-01 plugs in without refactor."
  - "D-16 honored: hasCpSettings = true preserved; createSettingsModel() returns new Settings(); settingsHtml() renders placeholder _settings.twig."
  - "D-03 honored: console controllerNamespace switched only on console requests — no web namespace yet (CP Settings save handler lands in Phase 4)."
  - "Component-name distinction kept: Plugin component is `legacyDbService` (the wrapper); Yii application component is `legacyDb` (the raw Connection). Resolves the swap-in compatibility window."

patterns-established:
  - "Plugin-owned Yii Connection registration with host-precedence guard: `if (!Craft::\\$app->has('legacyDb', true)) { Craft::\\$app->set(...); }`"
  - "Settings init() env-fallback using ??= so config-file overrides win without explicit precedence ordering"
  - "Yii Component services accessed via Plugin::getInstance()->serviceName + @property-read docblock"
  - "Placeholder CP Twig template that documents env vars by NAME only (no settings VALUE rendered) — discipline pattern that survives the Phase 4 form rewrite"

requirements-completed: [CONN-01, CONN-02]

# Metrics
duration: 12min
completed: 2026-04-25
---

# Phase 01 Plan 02: settings-legacy-db Summary

**Plugin now owns the legacy MySQL connection internally — `Plugin::init()` registers `legacyDb` as a Yii application component conditionally (host's existing declaration wins on v1.x swap-in, env-driven Connection fills the gap on greenfield), with `Settings` and `LegacyDbService` providing the seam every Phase 1+ stage consumes.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-04-25T16:00:00Z (approximate)
- **Completed:** 2026-04-25T16:12:00Z
- **Tasks:** 3 (all `type=auto`)
- **Files created:** 3 (`src/models/Settings.php`, `src/db/LegacyDbService.php`, `src/templates/_settings.twig`)
- **Files modified:** 1 (`src/Plugin.php` — promoted from properties-only stub to full Phase 1 form)

## Accomplishments

- `Settings::class` autoloads via PSR-4. All 16 properties (8 Phase 1 read-active + 8 Phase 2-4 declared) reflect successfully.
- `Settings::init()` resolves `CRAFT_LEGACY_DB_SERVER/DATABASE/USER/PASSWORD/PORT/CHARSET/TABLE_PREFIX` and `ANTHROPIC_API_KEY` via `App::env()`. The `??=` idiom ensures `config/kunstmaan-migrator.php` overrides win when present.
- `EnvAttributeParserBehavior` wired for `$ENV_VAR` syntax on string fields (legacyDb*, anthropicApiKey, llmModel, mappingPath, defaultSince).
- `LegacyDbService::class` autoloads as a `yii\base\Component`. Five read-only methods (`db`, `queryOne`, `queryAll`, `queryScalar`, `streamQuery`) match the v1 brownfield API surface. Domain helpers from v1 deliberately omitted — they ship in Phases 2-4 with their callers.
- `LegacyDbService` resolves `Craft::$app->get('legacyDb')` on every call (test-double-friendly per the established v1 pattern).
- `streamQuery` is a generator with `try/finally` to close the PDO reader on exit.
- `Plugin::init()` body filled: conditional `Craft::$app->set('legacyDb', [...])` block guarded by `!Craft::$app->has('legacyDb', true)`. DSN built from `Settings`; `PDO::ATTR_EMULATE_PREPARES => false` preserved from v1.
- Console `controllerNamespace` switched to `lameco\kunstmaanmigrator\console` only on console requests (Plan 03 / Plan 04 will land console controllers there).
- `Plugin::config()` declares the `legacyDbService` component, exposing `Plugin::getInstance()->legacyDbService` with IDE autocomplete via the `@property-read LegacyDbService $legacyDbService` docblock.
- `createSettingsModel()` returns `new Settings()`; `settingsHtml()` renders `kunstmaan-migrator/_settings.twig`.
- Placeholder Twig template ships under `src/templates/_settings.twig` — documents env-var names (no values rendered, no secrets echoed). Phase 4 / CFG-01 will replace it with the real form.
- Read-only discipline (D-13) verified by `grep -E '->insert\(|->update\(|->delete\(|->batchInsert\(' src/db/LegacyDbService.php` returning exit 1 (no matches).
- Information-disclosure mitigation (T-1-02, T-1-03) verified: no `echo`/`var_dump`/`print_r`/`error_log` of `legacyDbPassword` or `anthropicApiKey` anywhere in the new code. Twig template references env-var NAMES only.

## Task Commits

Each task was committed atomically:

1. **Task 1: Settings model with env-fallback resolution** — `e27e375` (feat)
2. **Task 2: LegacyDbService Yii Component (read-only)** — `09911ea` (feat)
3. **Task 3: Plugin::init() body + _settings.twig placeholder** — `9c05e3b` (feat)

## Files Created/Modified

- `src/models/Settings.php` (created) — 97 lines. `class Settings extends Model` with all 16 properties, `behaviors()` (EnvAttributeParserBehavior), `init()` (env fallback), `rules()` (type validation).
- `src/db/LegacyDbService.php` (created) — 70 lines. `class LegacyDbService extends Component` with 5 read-only methods. Resolves `Craft::$app->get('legacyDb')` per call.
- `src/Plugin.php` (modified) — promoted from 27-line stub to 89-line full Phase 1 form. Added: `@property-read` + `@method` docblocks, `use` imports for LegacyDbService/Settings/PDO/Connection, `Plugin::config()`, full `init()` body (legacyDb registration + console namespace switch), `createSettingsModel()`, `settingsHtml()`. `schemaVersion` and `hasCpSettings` properties preserved unchanged from Plan 01.
- `src/templates/_settings.twig` (created) — 16 lines. Extends `_layouts/cp`. Documents the 8 env vars operators set in `.env`. Renders zero settings values (placeholder discipline).

## Decisions Made

None beyond the ones already locked in `01-CONTEXT.md` (D-03, D-08, D-11, D-12, D-13, D-14, D-15, D-16). Plan executed exactly as written.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Discipline gate enforcement] Reworded LegacyDbService docblock to satisfy literal acceptance-criteria grep**

- **Found during:** Task 2 verification.
- **Issue:** The PATTERNS.md model docblock for `LegacyDbService` reads "...no `->insert(`, `->update(`, or `->delete(` ever appears in this file...". Embedding the literal `->insert(` / `->update(` / `->delete(` patterns inside the comment causes the acceptance-criteria grep `! grep -E '->insert\(|->update\(|->delete\(|->batchInsert\(' src/db/LegacyDbService.php` to FAIL on the docblock (the literal patterns ARE in the file — just inside a comment). The grep is intentionally pattern-blind (it doesn't parse PHP) precisely because that's a fast, line-based discipline gate.
- **Fix:** Reworded the docblock to "no insert / update / delete call ever appears" — same intent, no literal patterns that trip the gate. Behavior is identical (it's a comment); the discipline now passes both the literal grep and the spirit of D-13.
- **Files modified:** `src/db/LegacyDbService.php` (docblock comment text only).
- **Verification:** `/usr/bin/grep -E -- '->insert\(|->update\(|->delete\(|->batchInsert\(' src/db/LegacyDbService.php` returns exit 1 (no matches).
- **Committed in:** `09911ea` (squashed into the Task 2 commit before push — never landed in a separate commit).

---

**Total deviations:** 1 auto-fixed (Rule 1, discipline-gate self-consistency)
**Impact on plan:** None. Comment text is incidental to the actual contract (zero write-ops in code paths). The reworded comment carries identical meaning and now passes the strict file-level discipline grep.

## Issues Encountered

- One environmental quirk: the shell environment aliases `grep` to `ugrep`, which doesn't accept the same `-E -- pattern` flag ordering as BSD/GNU grep. Worked around by invoking `/usr/bin/grep` explicitly for the discipline-gate verification. Doesn't affect the codebase.

## User Setup Required

None — no external service configuration required at this stage. The Settings model defaults gracefully when `CRAFT_LEGACY_DB_*` and `ANTHROPIC_API_KEY` env vars are absent (fields stay `null`); Plan 04's `doctor` will surface the missing values as FAIL rows once it ships.

## Next Phase Readiness

**Ready for Plan 03 (Install migration) and Plan 04 (Doctor command):**

- `Plugin::getInstance()->legacyDbService` is wired and IDE-autocompletable. Plan 04's `checkLegacyDb()` calls `legacyDbService->queryOne('SELECT 1')` against this exact accessor.
- `Plugin::getInstance()->getSettings()` returns the populated Settings instance. Plan 04's `checkApiKey()` reads `getSettings()->anthropicApiKey` (then env fallback).
- The conditional `legacyDb` registration ensures `Craft::$app->get('legacyDb')` resolves whether the host declares it in `config/app.php` or not — the swap-in story works.
- `Plugin::config()['components']` pattern is established. Phases 2-4 add their own services to that array without touching `Plugin::init()`.
- `src/templates/_settings.twig` is in place; Phase 4 / CFG-01 just edits the template (no Plugin.php changes needed for the form rewrite).

**No blockers.**

## Threat Flags

None. The plan's `<threat_model>` register (T-1-02, T-1-03, T-1-05) covers everything this plan introduces. Mitigations were applied as specified:

- T-1-02 (DB credentials leak): no `Craft::info()`/`var_dump`/`error_log`/`print_r` of `$settings` inside the `Craft::$app->set('legacyDb', [...])` block. `Settings` class has no `__toString`/`__debugInfo`/`serialize` overrides.
- T-1-03 (Anthropic key leak): same discipline applied to `anthropicApiKey`. Twig template renders env-var NAMES only, never VALUES.
- T-1-05 (Yii component tampering): accepted per plan — risk is bounded by repo write access; mitigation is git review + filesystem perms, not in-code.

No new security-relevant surface introduced beyond the plan's threat register.

## Self-Check: PASSED

- src/models/Settings.php: FOUND
- src/db/LegacyDbService.php: FOUND
- src/templates/_settings.twig: FOUND
- src/Plugin.php (modified): FOUND
- Commit e27e375: FOUND
- Commit 09911ea: FOUND
- Commit 9c05e3b: FOUND

---
*Phase: 01-foundation-connectivity*
*Completed: 2026-04-25*
