---
phase: 02-schema-mapping-filters
plan: 02
subsystem: mapping-file
tags: [mapping, yaml, atomic-io, plugin-components]
requires:
  - Phase 1 / Plugin::config() components map (existing — extended in Plan 01)
  - Phase 1 / Settings::mappingPath (existing — D-15)
  - Symfony YAML 6+ (already in composer per Phase 1)
provides:
  - lameco\kunstmaanmigrator\mapping\MappingFile (Yii Component, 8 public methods)
  - Plugin::getInstance()->mappingFile (component access)
  - writeAtomicJson sibling helper (downstream Plan 03 SchemaDumper consumer)
affects:
  - src/Plugin.php (components map expanded from 3 → 4)
tech-stack:
  added: []
  patterns:
    - "Atomic write via tmp + rename (random_bytes(4) suffix; v1 MappingDraftWriter::writeAtomic ported verbatim)"
    - "Symfony YAML dump with Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK for diff-friendly long sample strings"
    - "Skip-existing merge keyed on (table, column, targetEntryType) tuple — operator decisions are sacred"
    - "@property-read PHPDoc for IDE / static-analysis resolution of magic Yii component getters"
key-files:
  created:
    - src/mapping/MappingFile.php
  modified:
    - src/Plugin.php
decisions:
  - "D-01 honored: single mapping.yaml with per-row status field; v1's four-bucket layout (mapping.yaml + .draft + .drops + DESIGN-GAPS.md) is collapsed to one file"
  - "D-04 honored: merge() keys on (table, column, targetEntryType) tuple and preserves every existing row verbatim — incoming proposals only land if their tuple is absent"
  - "D-07 honored: writeAtomic uses tmp file + rename(); operator Ctrl+C never corrupts state. Per-keypress write site (setStatus) wraps writeAtomic, so the Plan 04 map loop gets atomic-always-on for free"
  - "D-02 partial honor: buildRow accepts an `initialStatus` argument so the caller (HeuristicProposer / LlmClassifier orchestration in Plan 03) applies the confidence-tier table; MappingFile itself is status-agnostic"
  - "MappingFile consolidates v1's MappingDraftReader (303 LOC) + MappingDraftWriter (384 LOC) into ~196 LOC — the four sidecar files and the bucket-routing logic are gone, not ported"
  - "writeAtomicJson sibling helper added now (not in v1) so Plan 03's SchemaDumper has the same atomic write contract for schema-dump.json without duplicating the tmp+rename idiom"
metrics:
  duration_seconds: 145
  duration_human: "2m 25s"
  tasks_completed: 2
  files_created: 1
  files_modified: 1
  completed_date: "2026-04-25T20:26:42Z"
---

# Phase 02 Plan 02: Mapping File Summary

**One-liner:** Single-source-of-truth mapping.yaml reader+writer (`MappingFile`) consolidating v1's MappingDraftReader+MappingDraftWriter into a final Yii Component with 8 methods — atomic tmp+rename writes (D-07), skip-existing merge on (table,column,targetEntryType) (D-04), per-row setStatus mutation for the Plan 04 rubber-stamp loop, and a writeAtomicJson sibling for Plan 03's schema-dump.json.

## What Shipped

### Files Created

| File | Lines | Role |
|------|-------|------|
| `src/mapping/MappingFile.php` | 196 | `final class MappingFile extends Component`. 8 public methods: `resolvePath()`, `load(?string $path)`, `loadProposed(?string $path)`, `buildRow(array $proposal, string $initialStatus)`, `merge(array $existing, array $incoming)`, `setStatus(string $path, int $rowIndex, string $status, ?string $rationale, ?string $targetHandle, ?string $handler)`, `writeAtomic(string $path, string $contents)`, `writeAtomicJson(string $path, array $data)`. |

### Files Modified

| File | Diff Summary |
|------|-------------|
| `src/Plugin.php` | +3 / -0 lines. (1) Added `use lameco\kunstmaanmigrator\mapping\MappingFile;` (alphabetized between `locale` and `models`). (2) Added `@property-read MappingFile $mappingFile` to the class docblock. (3) Added `'mappingFile' => MappingFile::class` to the components map (now 4 entries). `init()`, `createSettingsModel()`, `settingsHtml()` untouched. |

## Commits

| Hash | Task | Message |
|------|------|---------|
| `00aa2d3` | 1 | feat(02-02): add MappingFile service for status-on-row mapping.yaml IO |
| `15acd89` | 2 | feat(02-02): register mappingFile in Plugin::config() components map |

## Decisions Honored

- **D-01 (single mapping.yaml with per-row status)**: `MappingFile` only knows about a flat `proposals:` list. There is no concept of `.draft`, no `mapping-drops-{ts}.yaml` writer, no `DESIGN-GAPS.md` artifact, and no `nodeClasses:` / `pageParts:` grouping. `buildRow()` emits a single dict with `status` as one of its 11 keys.
- **D-04 (skip-existing merge — operator decisions sacred)**: `merge()` walks `existing['proposals']` first, copying every row verbatim into the output and recording its `(table|column|targetEntryType)` key in a `$seen` set. It then walks `$incoming` and appends only rows whose key is absent. There is no overwrite path; there is no "smarter" diff logic. MAP-04 is satisfied byte-for-byte.
- **D-07 (atomic per-keypress write)**: `writeAtomic()` is the v1 pattern — `mkdir -p`, write to `${path}.tmp.${bin2hex(random_bytes(4))}`, `rename($tmp, $path)`. `setStatus()` wraps it: re-parse the YAML, mutate the indexed row in place, dump with `Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK`, write atomically. Plan 04's map loop will call `setStatus` after every keypress and inherit atomic-always-on for free.
- **D-02 (confidence-tier → status)**: `buildRow()` accepts an `initialStatus` string argument. The caller (Plan 03's analyze orchestration) applies the D-02 confidence-tier table outside this class. Keeping MappingFile status-agnostic means the same `buildRow` is reused by both the heuristic and LLM paths, and the rubber-stamp loop's `setStatus` mutates the same field independently.

## Design Notes

- **`writeAtomicJson` is new (not a v1 port).** v1 had separate write paths for YAML and JSON — `MappingDraftWriter` for mapping.yaml and inline `file_put_contents` calls in `AnalyzeController` for schema-dump.json. v2 collapses both behind one atomic-write contract: `writeAtomicJson` json-encodes with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, then delegates to `writeAtomic`. Plan 03's `SchemaDumper` will use this; no duplicate tmp+rename idiom.
- **`load()` re-keys the proposals list.** `Yaml::parseFile` may produce string-keyed maps if the source YAML used keyed entries; we re-walk and re-emit as a list to satisfy the `list<array<string, mixed>>` contract callers depend on. Order is preserved (PHP's `foreach` over an associative array preserves insertion order).
- **`loadProposed()` filters to `{proposed, needs-review}` only.** This is the rubber-stamp loop's denominator. Rows with `accepted` or `dropped` status are operator-final and are not re-prompted. Plan 04 will call this in a `foreach` loop without any sorting.
- **`merge()` skips non-array rows defensively.** A malformed YAML row (e.g. a stray scalar in the `proposals:` list) is silently dropped from both branches rather than crashing the merge. The downstream coverage gate will flag any column that loses its row, so the operator notices.
- **`setStatus` returns `bool`, not `void`.** A `false` return means the row index was out of range (caller bug — likely the file changed between `loadProposed` and the keypress). Plan 04 should treat false as a fatal — refresh and retry.
- **Path resolution mirrors `DoctorController::checkStorageDir`** — Settings::mappingPath wins; otherwise `Craft::$app->path->getStoragePath() . '/migration/mapping.yaml'`. Same fallback shape Phase 1 / D-17 established.

## Verification

- `php -l src/mapping/MappingFile.php` — clean.
- `php -l src/Plugin.php` — clean.
- All 12 task acceptance greps pass (final class, namespace, 8 method signatures, `Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK`, `Yaml::parseFile` ≥2, `random_bytes(4)`, `rename($tmp, $path)`).
- All 4 Task 2 acceptance greps pass (Plugin lint, mappingFile registration, @property-read, use statement).
- `composer test` exits 0: 7 tests, 11 assertions. Phase 1 PluginBootstrapTest stays green — the literal `'legacyDbService' => LegacyDbService::class` substring is preserved in the now-4-line components map, so the source-level reflection assertion in `testPluginDeclaresLegacyDbServiceComponent` continues to pass.
- Plugin.php now declares exactly 4 components: legacyDbService (Phase 1), filterFactory (Phase 2 Plan 01), localePreflight (Phase 2 Plan 01), mappingFile (Phase 2 Plan 02).
- `MappingFile` ships all 8 planned public methods.

## Deviations from Plan

None — plan executed exactly as written. The action text was mechanical (verbatim code blueprint copied into the file) and no auto-fixes (Rules 1-3) were triggered. No architectural decisions (Rule 4) needed.

## Authentication Gates

None — all work was code-only, no external services were called.

## Threat Surface Scan

No new threat surface beyond the plan's `<threat_model>`:
- T-2-05 (mapping.yaml mid-write corruption): mitigated by `writeAtomic`. Verified — the `rename($tmp, $path)` line is present once, the tmp filename includes `bin2hex(random_bytes(4))` for unguessability, and `setStatus` always rewrites via `writeAtomic` (no in-place fseek games).
- T-2-06 (concurrent map invocation race): accepted per plan. Single-operator workflow; atomic rename ensures last-writer-wins without corruption.
- T-2-07 (drop-reason audit persistence): mitigated. `buildRow` always serializes `rationale`; `setStatus` always rewrites the whole file when `rationale` is non-null. Drop reasons land in mapping.yaml on the same atomic write that flips status to `dropped` — no separate audit log surface.
- T-2-08 (tmp file briefly visible): accepted. mapping.yaml has no secrets; the unguessable hex suffix prevents predictable tmp paths.

## Requirements

- **MAP-04 (single mapping.yaml with per-row status; existing rows never overwritten without operator action)**: ✅ Satisfied. `buildRow` emits the per-row `status` field; `merge` is skip-existing keyed on `(table, column, targetEntryType)`; `setStatus` is the only mutation surface and is per-row, in-place.
- **MAP-01 (analyze emits schema-dump.json + REPORT.md + mapping.yaml)**: ⚠️ Partial — this plan ships the mapping.yaml IO (and the `writeAtomicJson` helper for schema-dump.json), but the analyze pipeline that actually produces schema-dump.json and REPORT.md is Plan 03 (`AnalyzeController` + `SchemaDumper` + `ReportBuilder`). MAP-01 will close when Plan 03 ships.

## Downstream Hooks

The next Phase 2 plans can now wire to:
- `Plugin::getInstance()->mappingFile->load($path)` — Plan 03 (analyze) reads existing mapping.yaml; Plan 04 (map) reads via `loadProposed()` for the rubber-stamp loop denominator.
- `Plugin::getInstance()->mappingFile->merge($existing, $incoming)` — Plan 03 calls this after HeuristicProposer + LlmClassifier produce the incoming list.
- `Plugin::getInstance()->mappingFile->writeAtomic($path, $yaml)` — Plan 03 writes the merged mapping.yaml.
- `Plugin::getInstance()->mappingFile->writeAtomicJson($path, $schemaDump)` — Plan 03's `SchemaDumper` writes schema-dump.json without re-implementing the tmp+rename idiom.
- `Plugin::getInstance()->mappingFile->setStatus($path, $idx, 'accepted', ...)` — Plan 04's map loop calls this after every `[a]`/`[d]`/`[r]` keypress.
- `Plugin::getInstance()->mappingFile->buildRow($proposal, $initialStatus)` — Plan 03 wraps each heuristic/LLM proposal into the canonical row shape before merge.

## Self-Check: PASSED

**Files exist:**
- src/mapping/MappingFile.php FOUND
- src/Plugin.php FOUND (modified)

**Commits exist:**
- 00aa2d3 FOUND
- 15acd89 FOUND
