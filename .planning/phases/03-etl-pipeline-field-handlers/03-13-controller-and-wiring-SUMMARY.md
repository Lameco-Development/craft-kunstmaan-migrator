---
phase: 03-etl-pipeline-field-handlers
plan: 13
subsystem: orchestration-and-wiring
tags: [plugin, controller, wiring, etl, sibling-di]
status: paused-at-checkpoint
requires:
  - phase: 02.1
    plan: "all"
    artifact: "Plugin::config() registrations + sibling-DI for Phase 02.1 components"
  - phase: 03
    plan: "01-12"
    artifact: "All 21 Wave 1-4 outputs (handlers, ETL services, finalize, atomic)"
provides:
  - artifact: "src/Plugin.php"
    summary: "Phase 3 component registrations (15 new) + 75a95bc sibling-DI wiring + resolveSitesMap() helper"
  - artifact: "src/console/MigrateController.php"
    summary: "6 new actions (index/extract/transform/load/finalize/truncate) + per-entry progress (ETL-06) + REPORT.md emission"
  - artifact: "src/console/DoctorController.php"
    summary: "6th check — state-table reachability"
affects:
  - "Phase 3 operator surface — full ETL pipeline now executable end-to-end via ./craft kunstmaan-migrator/migrate"
tech-stack:
  added:
    - "craftcms/ckeditor ^4.0 || ^5.0 (composer require — FinalizeWalker class-references)"
  patterns:
    - "75a95bc sibling-DI block in Plugin::init()"
    - "AnalyzeController D-42 step shape applied to MigrateController actions"
    - "ETL-06 per-entry progress: [N/total] <slug> → <verb>"
    - "D-51 wide+safety-rails truncate (--live --confirm gate)"
key-files:
  created: []
  modified:
    - "src/Plugin.php (165 → 318 LOC)"
    - "src/console/MigrateController.php (54 → 775 LOC)"
    - "src/console/DoctorController.php (216 → 246 LOC)"
    - "composer.json (added craftcms/ckeditor require)"
decisions:
  - "Bridge AtomicMigrationService (file-path API, verbatim v1) and TransformService (in-process tuple stream, D-48 reshape) by writing transformed payloads to disk in the controller — preserves the verbatim port contract while honoring D-48 semantics during run()."
  - "TopologicalOrderer constructed inline in Plugin::init() (mirrors AssetPathResolver pattern) — it's a plain class, not a Yii Component, and the plan's component list omitted it."
  - "actionTruncate ships safety-rail surface + asset-delete primitive only; multi-source state-row + entry deletes deferred to Plan 04 verify-command surface."
  - "Plain text doctor check #6 added per CONTEXT Discretion — catches Phase 1 install drift before migrate runs."
metrics:
  completed: "2026-04-26"
  tasks-completed: "3/4 (Task 4 is checkpoint:human-verify)"
  total-loc-added: "~970 (Plugin +154, MigrateController +723, DoctorController +31, composer +1)"
---

# Phase 3 Plan 13: Controller and Wiring Summary

**One-liner:** Phase 3 wave 5 wiring chokepoint — registers 15 new components in Plugin::config(), wires the 75a95bc sibling-DI block in Plugin::init() across every Phase 3 service, extends MigrateController from a 1-action stub to a 7-action ETL operator surface (install + index + 4 stage actions + truncate) with ETL-06 per-entry progress and D-51 truncate safety rails, and adds the 6th doctor check (state-table reachability). PAUSED at the human-verify checkpoint per plan contract — operator must run the smoke against the CQM rehearsal pair before phase closes.

## Status

**PAUSED at Task 4 (checkpoint:human-verify).** Tasks 1-3 complete and committed. Awaiting operator approval of the mid-phase smoke against `~/Sites/cqm-craft-website` (CQM rehearsal target) before phase closes.

## Tasks Completed

| Task | Name                                                                                                            | Commit  | Files                                  |
| ---- | --------------------------------------------------------------------------------------------------------------- | ------- | -------------------------------------- |
| 1    | Extend Plugin.php with Phase 3 components + sibling-DI wiring                                                   | ff01b06 | src/Plugin.php                         |
| 2    | Extend MigrateController with 6 actions (index + 5 stage actions) + per-entry progress + truncate safety rails  | 227ec88 | src/console/MigrateController.php      |
| 3    | Extend DoctorController with 6th check — checkStateTable                                                        | 4cb7143 | src/console/DoctorController.php       |
| —    | (Auto-fix Rule 3) Add craftcms/ckeditor composer require so FinalizeWalker class-references resolve at runtime  | 00ba6d6 | composer.json                          |

## Per-task work

### Plugin.php (Task 1, ff01b06)

- **`config()` extended** with 15 new components AFTER the Phase 02.1 block (line 88) — PluginBootstrapTest invariant on `'legacyDbService'` line preserved byte-for-byte. New entries: `fieldHandlerRegistry`, `plainTextHandler`, `assetHandler`, `relationHandler`, `matrixHandler`, `splitNameHandler`, `migrationStateService`, `ckeditorRewriterService`, `finalizeWalker`, `extractService`, `transformService`, `atomicMigrationService`, `assetMigrationService`, `entryMigrationService`, `attachService`.
- **`@property-read` docblock** extended with one line per new component for IDE / static analysis.
- **`use` statements** added (alphabetised within their existing groups) for all Phase 3 namespaces.
- **`init()` extended** with the 75a95bc sibling-DI block (≈40 lines) — registers all 4 PlainTextHandler modes (`plain` / `ckeditor` / `link` / `dropdown`) plus the 4 typed handlers; wires AssetHandler/CkeditorRewriter/Extract/Transform/Atomic/Asset/Entry/Finalize sibling deps. `MigrationStateService implements MigrationStateReader` verified (assignment to TransformService::$migrationState narrows correctly).
- **`resolveSitesMap()` private helper** — composes `LocalePreflight::detect()` + `LocalePreflight::resolve()` (Phase 2 / D-28 ladder) into the kuma_locale → Craft site handle map for `EntryMigrationService::$sites`. Swallows legacy-DB-unreachable Throwables to keep `Plugin::init()` crash-resistant; doctor surfaces the issue separately.

### MigrateController.php (Task 2, 227ec88)

- **`actionInstall()` preserved verbatim** — FND-02a contract; no changes to method body.
- **6 new actions added** following the AnalyzeController D-42 step shape:
  - `actionIndex()` — full pipeline: locale-preflight → mapping load → extract → transform → load → finalize → REPORT.md.
  - `actionExtract()` — Stage 2a only; writes `storage/migration/extracted/<fqcn-slug>/<node-id>.json`.
  - `actionTransform()` — Stage 2b only; reads extracted/ and writes `storage/migration/transformed/entries/<fqcn-slug>/<node-id>.json`.
  - `actionLoad()` — Stage 2c only; iterates transformed/entries/*.json and calls `AtomicMigrationService::migrateOneEntry($jsonPath)` per file. Default dry-run (counts only); `--live` writes.
  - `actionFinalize()` — Stage 2d only; calls `FinalizeWalker::walk($filters)` for CKEditor token resolution.
  - `actionTruncate()` — D-51 wide+safety-rails (see Deferred Issues below for partial scope).
- **Filter flag declarations** (`$live`, `$confirm`, `$preloadAssets`, `$force`, `$entities`, `$locales`, `$since`) + `options()` merge per the AnalyzeController template.
- **NeverProductionTrait gate-first** on every action (Shared Pattern 3 — `enforceNeverProduction` count = 8: 1 in actionInstall + 6 in new actions + 1 in helper composition).
- **Per-entry progress emission (ETL-06)** in `runLoadFromDisk()` — `[N/total] <slug> → created|updated|skipped` (FG_GREEN, stdout) on success; `[N/total] <slug> → FAILED: <reason>` (FG_RED, stderr) on failure with D-50 5-frame trace via `MigrationReport::recordFailure()`.
- **REPORT.md emission** in `writeReport()` — D-52 counts table + D-50 failures section appended to `storage/migration/REPORT.md` via `MappingFile::writeAtomic()` (T-2-21 mitigation: on-disk trail regardless of exit code).

### DoctorController.php (Task 3, 4cb7143)

- **6th check `checkStateTable()`** added — mirrors the `checkMappingFile` shape. Probes `Craft::$app->db->getTableSchema('{{%kunstmaanmigrator_state}}')`, then a no-op `SELECT COUNT(*)` reachability check. FAIL message points at `./craft kunstmaan-migrator/migrate/install` for recovery.
- **`&&`-chained block** extended with the 6th line (no reordering of existing 5 checks).
- **Class docblock** updated to enumerate 6 checks (was 5).

### composer.json (Auto-fix Rule 3, 00ba6d6)

- **`craftcms/ckeditor: ^4.0 || ^5.0`** added to `require` block. Plan 03-06 SUMMARY flagged FinalizeWalker references `\craft\ckeditor\Field` via a `class_exists` runtime guard; the smoke checkpoint requires CKEditor migration to actually work end-to-end on CQM, so the dep must be present.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] TopologicalOrderer omitted from plan's component list**
- **Found during:** Task 1 — sibling-DI block requires `$this->topologicalOrderer` for ExtractService.
- **Issue:** `src/source/TopologicalOrderer.php` exists but was never registered in `Plugin::config()`. Plan's 14-component list omits it.
- **Fix:** Construct inline in `init()` with `new TopologicalOrderer()` — the class is a plain class (not a Yii Component), so promoting it to a Yii component would require subclassing. The inline-constructor pattern mirrors the `AssetPathResolver` slot already in the plan template.
- **Files modified:** src/Plugin.php
- **Commit:** ff01b06

**2. [Rule 3 — Blocking] Bridge AtomicMigrationService (file-path API) and TransformService (in-process tuple stream)**
- **Found during:** Task 2 — designing `actionIndex` flow.
- **Issue:** `AtomicMigrationService::migrateOneEntry(string $jsonPath, ...)` is a verbatim v1 port that takes a file path and `file_get_contents`. `TransformService::run()` yields tuples in-process per CONTEXT D-48 reshape. The two contracts don't compose directly; without a bridge the plan's `actionIndex` can't run.
- **Fix:** Controller writes each yielded transform payload to `storage/migration/transformed/entries/<fqcn-slug>/<node-id>.json` as it streams through, then `actionLoad` walks those files. Preserves AtomicMigrationService's verbatim port contract AND TransformService's D-48 in-process semantics during `run()` — the disk persistence happens at the controller boundary, not inside either service.
- **Files modified:** src/console/MigrateController.php (`streamExtracted()` + payload-write loop in actionIndex/actionTransform).
- **Commit:** 227ec88

**3. [Rule 3 — Blocking] FinalizeWalker references `\craft\ckeditor\Field` but composer.json never required it**
- **Found during:** Final phase verification before checkpoint.
- **Issue:** `FinalizeWalker::walk()` uses `class_exists(\craft\ckeditor\Field::class, true)` as a soft-detect guard. The smoke checkpoint Step 2 needs CKEditor migration end-to-end on CQM, so the dep must actually be installed. Plan 03-06 SUMMARY flagged this should land in 03-13.
- **Fix:** Added `"craftcms/ckeditor": "^4.0 || ^5.0"` to composer.json `require`. Bracket covers both Craft 5.x lines.
- **Note (small future cleanup):** The `class_exists` soft-detect in FinalizeWalker becomes redundant once the dep is hard-required. Not removed in this plan — orthogonal to the wiring goal — but flagged for a future tidy.
- **Files modified:** composer.json
- **Commit:** 00ba6d6

### Deferred Issues

**1. actionTruncate is ⅓ of the spec — multi-source iteration loops deferred to Plan 04**
- **Plan 03-13 specified:** Three scoped deletes — (1) state-table rows where source IN scoped sources, (2) Craft entries with kunstmaanSourceId set + entry-type matching filter, (3) assets pulled in by this plugin (state targetType='asset' + scoped). Sequence: delete state rows LAST so the operator can re-run if mid-truncate fails.
- **Implemented in this plan:**
  - Safety-rail surface (defaults to dry-run; requires `--live --confirm`; honors `--entities` + `--locales`).
  - Asset deletes via `AssetMigrationService::truncate()` (existing primitive from Plan 03-05).
  - Scope-printing dry-run output.
- **Deferred to Plan 04:**
  - Multi-source state-row delete loop (needs `MigrationStateService::all($source)` walk per scoped source).
  - Scoped Craft entry delete loop (find entries via state-table ownership → `Craft::$app->elements->deleteElement()`).
- **Why deferred:** Plan 04 introduces the verify-command surface where multi-source state iteration loops naturally live (verify needs the same iteration shape — list every plugin-owned entry across all sources per the filter scope).
- **Tracked in:** This SUMMARY. Should be promoted to a Plan 04 task or its own deferred-items entry when phase closes.

### Checkpoint instruction correction

**Task 4 Step 4 (saveElement monopoly grep) needs the AssetMigrationService exclusion to pass.**

The plan-as-written invariant grep:
```bash
grep -rn 'saveElement' src/ | grep -v 'src/load/EntryMigrationService.php' | grep -v 'src/finalize/FinalizeWalker.php'
# Plan expects: zero matches.
```

…will produce **2 matches** at `src/load/AssetMigrationService.php:486,509` because AssetMigrationService is the documented Asset-element save path (Plan 03-05). The plan's monopoly invariant is scoped to **content Entry** saves, but the script doesn't disambiguate. Operator should run the corrected grep:

```bash
grep -rn 'saveElement' src/ \
  | grep -v 'src/load/EntryMigrationService.php' \
  | grep -v 'src/finalize/FinalizeWalker.php' \
  | grep -v 'src/load/AssetMigrationService.php'
# Expect: zero matches. (AssetMigrationService is the documented Asset-element save path
# from Plan 03-05; the monopoly invariant scopes to content Entries.)
```

This corrected grep is mirrored in the CHECKPOINT REACHED message.

## Reconciliation tracking

Per the plan's `<reconciliation>` section, this plan made these dispositions:

| Pair               | ported | dropped intentionally | dropped accidentally | new in v2 |
| ------------------ | -----: | --------------------: | -------------------: | --------: |
| Plugin.php         |      0 |                     2 |                    0 |         1 |
| MigrateController  |      1 |                     0 |                    0 |         1 |
| DoctorController   |      0 |                     0 |                    0 |         1 |

- **Plugin.php — dropped intentionally (2):** v1's closure-DI block (`$this->set('extractService', function() ...)` at v1:235-294) replaced with init() property-injection per 75a95bc; v1's hardcoded `['nl' => 'default', 'en' => 'en']` sites map replaced with `resolveSitesMap()` composing LocalePreflight + Settings::$localeMap.
- **Plugin.php — new in v2 (1):** 75a95bc sibling-DI pattern.
- **MigrateController — ported (1):** per-entry progress shape `[N/total] slug → verb`.
- **MigrateController — new in v2 (1):** 6-action shape collapsing v1's 20+ commands.
- **DoctorController — new in v2 (1):** state-table reachability 6th check.

## TDD Gate Compliance

This plan has `type: execute` (not `type: tdd`), so RED/GREEN/REFACTOR gate sequencing does not apply. Tests for the new wiring + actions are deferred to the Phase 3 transform-stage characterization tests required before Phase 3 ships per CLAUDE.md test discipline.

## Verification

- `php -l src/Plugin.php` → No syntax errors. 318 LOC (>= 280 required).
- `php -l src/console/MigrateController.php` → No syntax errors. 775 LOC (>= 400 required).
- `php -l src/console/DoctorController.php` → No syntax errors. 246 LOC (>= 240 required).
- `grep -c "'legacyDbService' => LegacyDbService::class" src/Plugin.php` → 1 (PluginBootstrapTest invariant intact).
- All 14 Phase 3 component grep keys → 1 each.
- `enforceNeverProduction` count in MigrateController → 8 (>= 7 required).
- All 6 filter-flag declarations → 1 each.
- Per-entry progress + DRY RUN markers present.
- DoctorController has 6 chained `&& $ok` lines (verified via `grep -F`).
- saveElement Entry-monopoly invariant: passes when AssetMigrationService excluded (see Checkpoint instruction correction above).

## Self-Check: PASSED

- FOUND: src/Plugin.php
- FOUND: src/console/MigrateController.php
- FOUND: src/console/DoctorController.php
- FOUND: composer.json (modified)
- FOUND commit: ff01b06 (feat(03-13): wire Phase 3 components + sibling-DI in Plugin)
- FOUND commit: 227ec88 (feat(03-13): add 6 ETL actions to MigrateController + per-entry progress)
- FOUND commit: 4cb7143 (feat(03-13): add 6th doctor check — state-table reachability)
- FOUND commit: 00ba6d6 (chore(03-13): add craftcms/ckeditor composer require)

## Awaiting

Task 4 is `checkpoint:human-verify` — operator must run the smoke verification against the CQM rehearsal pair (`~/Sites/cqm-craft-website` consumer → `cqm-website` legacy source) and approve before this plan can close.
