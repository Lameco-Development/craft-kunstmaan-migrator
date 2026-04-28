---
phase: 02-schema-mapping-filters
plan: 04
subsystem: cli-mapping
tags: [console, interactive, mapping, rubber-stamp, atomic-write, field-layout]

# Dependency graph
requires:
  - phase: 01-foundation-connectivity
    provides: NeverProductionTrait, console controllerNamespace, Settings, Plugin component DI
  - phase: 02-schema-mapping-filters/01
    provides: FilterFactory, MigrationFilters, LocalePreflight
  - phase: 02-schema-mapping-filters/02
    provides: MappingFile (loadProposed / load / setStatus / resolvePath / writeAtomic)
provides:
  - kunstmaan-migrator/map CLI command (interactive rubber-stamp loop)
  - --auto-accept-high non-interactive promotion path (mirrors analyze)
  - D-05 compact one-screen renderer for mapping rows
  - D-06 two-step [r]emap picker (handler enum → handle list) with [t]ype manually fallback validated against live FieldLayout
  - D-07 atomic-per-keypress mapping.yaml write (via MappingFile::setStatus)
  - D-08 stateless resume (mapping.yaml is the only state)
affects: [Phase 02 / Plan 05 (coverage-audit-doctor), Phase 02 / Plan 06 (tests-and-doc-patches), Phase 03 (ETL — consumes accepted mapping rows)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Interactive console flow via Yii Controller::select() and Controller::prompt() inherited from craft\\console\\Controller"
    - "Atomic-per-keypress file mutation: every operator action triggers one full mapping.yaml rewrite via tmp+rename"
    - "Live FieldLayout validation for free-text input (Craft::$app->entries->getEntryTypeByHandle + getFieldLayout + getCustomFields)"

key-files:
  created:
    - src/console/MapController.php
  modified: []

key-decisions:
  - "MapController is added without any Plugin.php change — services consumed (filterFactory, localePreflight, mappingFile) are already registered by Plans 01 + 02"
  - "Iteration order is locked at the top of runInteractiveLoop() via $walkOrder (computed from full file load), then each iteration re-reads the file to get the row's current state — protects against external file mutation between keypresses without losing the original walk order"
  - "[r]emap picker handler enum uses 'b' as the back sentinel; chosen handlers are stored as full strings ('asset', 'ckeditor', etc.) so persistence sees stable values regardless of the picker's letter mapping"
  - "[t]ype manually loops on invalid input (re-prompts with available-handles list) until the operator either types a valid handle or types 'back' — invalid handles never land in mapping.yaml (T-2-18 mitigation)"
  - "--since is captured in MigrationFilters and forwarded to LocalePreflight, but is a no-op at this surface (mapping rows have no dates) — Phase 3+ stages will consume it"
  - "applyEntitiesFilter uses a snake_case heuristic (NewsPage → kuma_news_page*) to match v1's identifier convention"

patterns-established:
  - "Console controller idiom (gate-first NeverProduction; options() declaring CLI flags; Console::FG_* output) — third Phase 2 controller following AnalyzeController + DoctorController"
  - "Two-step picker pattern for narrowing operator choice (handler classification → handle list) with free-text fallback that validates against the live runtime"
  - "Stateless interactive loop: file is the only state; restartable from any point because every mutation is persisted before the next prompt"

requirements-completed: [MAP-05, FILT-03]

# Metrics
duration: 2min
completed: 2026-04-25
---

# Phase 2 Plan 04: Map Rubber-Stamp Loop Summary

**Interactive `kunstmaan-migrator/map` rubber-stamp loop with two-step [r]emap picker, atomic-per-keypress writes, --auto-accept-high non-interactive path, and live-FieldLayout-validated free-text fallback.**

## Performance

- **Duration:** ~2 min (114 s)
- **Started:** 2026-04-25T20:47:58Z
- **Completed:** 2026-04-25T20:49:52Z
- **Tasks:** 1
- **Files created:** 1 (MapController.php, 379 LOC)
- **Files modified:** 0

## Accomplishments

- Shipped the highest-value greenfield element of Phase 2: the interactive `map` CLI rubber-stamp loop (no v1 analog).
- Compact one-screen-per-row UX (D-05) with `[N/total] table.column` header, separator, proposal summary, fillRate %, sqlType, and up to 3 samples truncated to 60 chars.
- a/d/r/s/q operator vocabulary persisting decisions atomically per keypress via `MappingFile::setStatus → writeAtomic` (D-07).
- Two-step `[r]emap` picker (D-06): handler enum → numbered handle list filtered to entry-type fields, with `[t]ype manually` fallback that re-prompts on invalid input.
- `--auto-accept-high` non-interactive promotion path (MAP-05) — mirrors `analyze --auto-accept-high` so operators get the same flag at both write sites.
- All three filter flags (`--entities`, `--locales`, `--since`) declared (FILT-03); `--entities` filters which rows the loop walks; `--locales` is forwarded to LocalePreflight; `--since` is captured (no-op at this surface).
- `NeverProduction` gate is the FIRST executable statement of `actionIndex` (D-20).

## Task Commits

1. **Task 1: Create MapController with rubber-stamp loop, two-step picker, and --auto-accept-high path** — `c5195df` (feat)

## Files Created/Modified

- `src/console/MapController.php` (379 LOC) — interactive rubber-stamp loop over mapping.yaml proposed/needs-review rows. Three execution paths in `actionIndex`: NeverProduction-gate-first → FilterFactory → LocalePreflight → mapping-path resolve → either `runAutoAcceptHigh()` (non-interactive MAP-05 promotion) or `runInteractiveLoop()` (D-05/D-06/D-07/D-08). Two private helpers: `runRemapPicker()` (D-06 two-step) and `typeManuallyHandle()` (D-06 free-text validation against live FieldLayout). Two filter helpers: `applyEntitiesFilter()` and `matchesEntitiesFilter()` (D-13 entity scope on table prefix).

## Decision → Code Mapping

| Decision | Code Block (src/console/MapController.php) |
|----------|--------------------------------------------|
| D-05 compact one-screen block | `renderRowBlock()` (lines 195-228) |
| D-06 two-step [r]emap picker (handler enum → handle list) | `runRemapPicker()` (lines 240-293) |
| D-06 [t]ype manually validated against live FieldLayout | `typeManuallyHandle()` (lines 299-314) + `fieldHandlesForEntryType()` (lines 320-343) |
| D-07 atomic write per keypress | `setStatus(...)` calls inside the `switch ($action)` cases (lines 167, 178, 184) — each delegates to `MappingFile::setStatus → writeAtomic` |
| D-07 [s]kip does NOT mutate | `case 's':` body emits status message only, no `setStatus` call (lines 192-195) |
| D-07 [q]uit exits cleanly | `case 'q':` returns `ExitCode::OK` with persisted-decisions reminder (lines 196-198) |
| D-08 stateless resume | `runInteractiveLoop()` re-reads `MappingFile::load($path)` each iteration (line 145) so external mutations don't corrupt the walk |
| D-13 --entities filter | `applyEntitiesFilter()` (lines 351-364) + `matchesEntitiesFilter()` (lines 369-382) |
| D-20 NeverProduction gate first | `enforceNeverProduction()` is line 54, immediately after `actionIndex(): int` declaration at line 51 |
| MAP-05 --auto-accept-high | `runAutoAcceptHigh()` (lines 96-110) — promotes proposed+high → accepted, then exits |

(Line numbers approximate; the structure matches the plan's action text verbatim.)

## Plugin.php Changes

**None.** Confirmed: MapController consumes only services already registered by prior plans:
- `Plugin::getInstance()->filterFactory` (registered by Plan 01 / 02-01)
- `Plugin::getInstance()->localePreflight` (registered by Plan 01 / 02-01)
- `Plugin::getInstance()->mappingFile` (registered by Plan 02 / 02-02)

`Plugin.php` still has 8 components (legacyDbService, filterFactory, localePreflight, mappingFile, schemaDumper, heuristicProposer, llmClassifier, reportBuilder). Console controller resolution is via the existing `controllerNamespace = 'lameco\\kunstmaanmigrator\\console'` switch in `Plugin::init()`.

## Decisions Made

- **No Plugin.php change.** The plan correctly anticipated this: services were registered by Plans 01 + 02, and console controllers resolve through the existing `controllerNamespace` switch. No new component or wiring needed.
- **`--since` is captured but unused at this surface.** Mapping rows have no dates; the flag is forwarded to `LocalePreflight::ensure()` as part of the `MigrationFilters` VO, but the row-walk doesn't consume it. Phase 3+ stages will use it when they touch real data with timestamps. Documented in plan action text and reproduced here for Phase 3+ context.
- **Iteration order is locked at the top.** `$walkOrder` is computed once from a full file load. Each iteration re-reads the file to get the row's current state (so external mutations are visible) but the walk order itself is immutable — prevents the loop from skipping or repeating rows if mapping.yaml is touched mid-walk.
- **Picker handler enum uses 'b' as back sentinel** (rather than 'q' for quit) — `[q]uit` semantics are reserved for the top-level loop prompt; the picker uses `[b]ack` to keep the verbs distinct.

## Deviations from Plan

None - plan executed exactly as written.

## Authentication Gates

None - this command is fully local (no API calls, no auth flow).

## Issues Encountered

None.

## Verification

- `php -l src/console/MapController.php` → "No syntax errors detected"
- `composer test` → OK (7 tests, 11 assertions) — Phase 1 PluginBootstrapTest + NeverProductionTrait tests still green
- All 19 acceptance-criteria greps pass:
  - `class MapController extends Controller` ×1
  - `use NeverProductionTrait;` ×1
  - `enforceNeverProduction` is gate-first (line 54, immediately after `actionIndex()` declaration at line 51)
  - All four CLI option declarations + `options()` array ×1 each
  - `mappingFile->setStatus` ×4 (≥2 required) — atomic per-keypress writes
  - `mappingFile->loadProposed|mappingFile->load` ×4 (≥2 required) — re-read on each iteration
  - `localePreflight->ensure` ×1
  - All five prompt vocabulary entries (`'a' => 'accept'` etc.) ×1 each
  - `getEntryTypeByHandle` ×1, `getCustomFields` ×1 — live FieldLayout walk

## Threat Mitigations Verified

- **T-2-15 (mapping.yaml row mutation per keypress)** — every `[a]/[d]/[r]` calls `MappingFile::setStatus → writeAtomic` (tmp+rename). Verified by `grep -c 'mappingFile->setStatus'` returning 4.
- **T-2-16 (production legacy DB)** — `enforceNeverProduction()` is line 54, first statement of `actionIndex` (line 51 declaration → line 54 gate, with no executable code in between).
- **T-2-17 (drop-reason audit trail)** — `[d]rop` branch prompts for rationale (with default fallback), passes it as 4th arg to `setStatus`, persisted via the existing MappingFile contract.
- **T-2-18 (free-text [t]ype manually injection)** — `typeManuallyHandle()` re-prompts in `while (true)` until operator types a handle present in `fieldHandlesForEntryType()` (which calls `getEntryTypeByHandle()->getFieldLayout()->getCustomFields()`) or types `back`. Invalid handles never land in mapping.yaml.

## Self-Check: PASSED

- File `src/console/MapController.php` exists (379 LOC).
- Commit `c5195df` exists in git log.
- All 19 acceptance criteria greps return expected counts.
- `composer test` exits 0 (7 tests, 11 assertions).

## Next Phase Readiness

Plan 02-04 closes the operator-facing rubber-stamp loop. Phase 2 has 2 plans remaining:
- **Plan 02-05 (coverage-audit-doctor):** CoverageAuditor + MappingAuditor services; replaces AnalyzeController's naive `buildViolationsFromSchema` and empty `buildCraftFieldIndex` stubs; adds 4th doctor check for mapping file health.
- **Plan 02-06 (tests-and-doc-patches):** characterization tests for the analyze pipeline; doc patches (REQUIREMENTS.md FILT-01 + ROADMAP.md Phase 2 success criterion 5 — drop `--max-per-entity` per D-12).

No blockers from this plan. Plugin.php component map is unchanged from Plan 02-03 (still 8 components).

---
*Phase: 02-schema-mapping-filters*
*Completed: 2026-04-25*
