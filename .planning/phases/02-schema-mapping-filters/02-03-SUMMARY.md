---
phase: 02-schema-mapping-filters
plan: 03
subsystem: analyze-pipeline
tags: [analyze, heuristics, llm, anthropic, schema-dump, report, controller]
requires:
  - Phase 1 / Plugin::config() (existing — extended in Plans 01-02)
  - Phase 1 / Settings::anthropicApiKey + llmModel + llmTimeout (existing — D-15)
  - Phase 1 / NeverProductionTrait + LegacyDbService (existing)
  - Phase 2 / Plan 01 / FilterFactory + LocalePreflight (existing — Wave 1)
  - Phase 2 / Plan 02 / MappingFile (existing — Wave 2)
  - Symfony YAML 6+ (already in composer per Phase 1)
  - guzzlehttp/guzzle 7+ (already in composer per Phase 1)
provides:
  - lameco\kunstmaanmigrator\analyze\SchemaDumper (Yii Component, dump(MigrationFilters))
  - lameco\kunstmaanmigrator\analyze\HeuristicProposer (Yii Component, autoMatch(violations, fieldIndex))
  - lameco\kunstmaanmigrator\analyze\LlmClassifier (Yii Component, batchPropose(...))
  - lameco\kunstmaanmigrator\analyze\ReportBuilder (Yii Component, render(schemaDump, proposals))
  - lameco\kunstmaanmigrator\analyze\MappingProposalException (RuntimeException marker)
  - lameco\kunstmaanmigrator\console\AnalyzeController (single actionIndex orchestration)
  - Plugin::getInstance()->{schemaDumper,heuristicProposer,llmClassifier,reportBuilder} (component access)
affects:
  - src/Plugin.php (components map expanded from 4 → 8)
tech-stack:
  added: []
  patterns:
    - "Yii Component init() lifecycle for Settings::llmModel + llmTimeout overrides (single-shot wiring at boot)"
    - "Anthropic Messages API call shape preserved verbatim from v1: chunk=10, sleep(20), 3-retry on 429 with 15s/30s/45s backoff + retry-after honor"
    - "Generator-based streamQuery with LIMIT cap for sample collection (T-2-09 DoS mitigation)"
    - "Single-actionIndex orchestration replacing v1's 9-sub-action AnalyzeController"
    - "Settings-then-env API key resolution via Plugin::getInstance()->getSettings()->anthropicApiKey"
key-files:
  created:
    - src/analyze/HeuristicProposer.php
    - src/analyze/LlmClassifier.php
    - src/analyze/MappingProposalException.php
    - src/analyze/SchemaDumper.php
    - src/analyze/ReportBuilder.php
    - src/console/AnalyzeController.php
  modified:
    - src/Plugin.php
decisions:
  - "D-02 honored: heuristic-high → status: accepted; heuristic-medium → proposed; heuristic-zero-fill (decision=drop) → dropped; LLM-high → proposed; LLM-medium/low → needs-review. Status assignment lives in AnalyzeController::statusForHeuristic + statusForLlm — heuristic and classifier services emit confidence only."
  - "D-04 honored: AnalyzeController calls MappingFile::merge with the existing mapping.yaml first; merge keys on (table, column, targetEntryType) and preserves every existing row verbatim — operator decisions are sacred."
  - "D-13 honored: MigrationFilters is built once via FilterFactory::fromCli at the top of actionIndex (after the NeverProduction gate) and threaded through SchemaDumper::dump + LocalePreflight::ensure."
  - "D-14 honored: LlmClassifier reads the API key via Plugin::getInstance()->getSettings()->anthropicApiKey (single chained access — no direct App::env() read). Settings::init() handles the env-merge once at boot."
  - "D-15 honored: LlmClassifier::init() applies Settings::llmModel + Settings::llmTimeout overrides at component boot — Yii's Component lifecycle wires them once."
  - "D-17 honored: ReportBuilder::renderLocales emits a paste-ready Craft sites: block when any detected Kunstmaan locale is unmapped (not in Craft sites or Settings::defaultLocales). Uses primary-site handle as fallback."
  - "D-20 honored: enforceNeverProduction is the FIRST executable statement of AnalyzeController::actionIndex (line 63 immediately after the method opener)."
  - "v1 defaults preserved verbatim in LlmClassifier: claude-haiku-4-5, maxTokens=4096, timeoutSeconds=60, anthropic-version 2023-06-01, batch=10, sleep(20), 3 retries on 429 with 15s/30s/45s backoff."
  - "v1 verbatim port: HeuristicProposer's 9-heuristic ordering (zero-fill → exact-name → *_id → TEXT → *_image → *_date → *_url → *_email → Dutch alias) and DUTCH_ALIASES const are byte-for-byte from v1."
  - "T-2-09 mitigated: SchemaDumper sample collection uses LegacyDbService::streamQuery with LIMIT scanLimit cap (default 1000 rows). The generator yields rows one at a time — never loads the full table into memory."
  - "T-2-12 mitigated: AnalyzeController gates on enforceNeverProduction first (D-20)."
  - "T-2-11 mitigated: LlmClassifier reads the key via Settings (never echoes the value); on missing-key, the exception message is the literal 'ANTHROPIC_API_KEY is not set...' — no value substitution. sanitiseErrorMessage() strips any in-message occurrence."
  - "Plan 03 design choice: AnalyzeController::buildViolationsFromSchema is naive (every column → violation row). Plan 05's CoverageAuditor will replace this with a coverage-aware violation set. AnalyzeController::buildCraftFieldIndex returns [] — Plan 05 wires the live FieldLayout walk. With an empty index, the heuristic only fires the zero-fill auto-drop rule and routes the rest to LLM residual (or to the needs-review skip-stub when LLM is disabled)."
  - "Plan 03 design choice: --audit-strict is declared on AnalyzeController now so Plan 05 (mapping-audit) does not need to re-touch this file. The flag is parsed and stored; its consumer (mappingAuditor->audit invocation + fail-state elevation) lands when Plan 05 ships."
metrics:
  duration_seconds: 510
  duration_human: "8m 30s"
  tasks_completed: 4
  files_created: 6
  files_modified: 1
  completed_date: "2026-04-25T20:40:10Z"
---

# Phase 02 Plan 03: Analyze Pipeline Summary

**One-liner:** Single-actionIndex AnalyzeController collapsing v1's 9-sub-action 2138-LOC controller, orchestrating the verbatim-ported HeuristicProposer (9 heuristics + DUTCH_ALIASES) + LlmClassifier (Anthropic Haiku batched, v1 defaults preserved) + new SchemaDumper (streamQuery for T-2-09 DoS mitigation) + new ReportBuilder (D-17 paste-ready locales block), with D-02 confidence-tier → status mapping applied at the orchestration layer and D-04 skip-existing merge through MappingFile.

## What Shipped

### Files Created

| File | Lines | Role |
|------|-------|------|
| `src/analyze/HeuristicProposer.php` | 406 | `final class HeuristicProposer extends Component`. v1 port — namespace flattened from `bridge\services` to `analyze`. 9 heuristics in v1 order + DUTCH_ALIASES const + handlerForClassification match expression all byte-for-byte from v1. `autoMatch(violations, craftFieldIndex): [matched, residual]`. |
| `src/analyze/LlmClassifier.php` | 502 | `final class LlmClassifier extends Component`. v1 port — namespace flattened. v1 defaults preserved (claude-haiku-4-5, 4096 tokens, 60s timeout, anthropic-version 2023-06-01, batch=10, sleep(20), 3-retry on 429 with 15s/30s/45s backoff + retry-after honor). API key reads via `Plugin::getInstance()->getSettings()->anthropicApiKey` (D-14). `init()` applies Settings::llmModel + llmTimeout overrides (D-15). `batchPropose(residual, fieldIndex, legacyKb, targetKb): list<proposals>`. |
| `src/analyze/MappingProposalException.php` | 22 | `final class MappingProposalException extends RuntimeException`. Marker type — no extra state. Thrown by LlmClassifier on missing key / HTTP error / parse error. T-2-11 invariant: callers MUST NOT pass key material into the exception message; the classifier strips key material via sanitiseErrorMessage(). |
| `src/analyze/SchemaDumper.php` | 201 | `final class SchemaDumper extends Component`. New service — replaces v1's 109-LOC `actionIndex` schema-dump code. Pure transform (no file I/O). `dump(MigrationFilters): array{generatedAt, driver, tables, columns, locales}`. T-2-09 mitigation: sample collection uses `streamQuery` with LIMIT scanLimit cap (default 1000 rows). Locale detection delegated to LocalePreflight::detect (single source of truth). Entity filter is snake-case prefix match (NewsPage → kuma_news_page). |
| `src/analyze/ReportBuilder.php` | 157 | `final class ReportBuilder extends Component`. New service — replaces v1's report markdown rendering. Pure transform (no file I/O). `render(schemaDump, mappingProposals): string`. Sections: Header / Locales (D-17 paste-ready Craft sites: block when unmapped) / Tables (top 25 by row count) / Mapping Summary (status counts). |
| `src/console/AnalyzeController.php` | 301 | `class AnalyzeController extends Controller` with `use NeverProductionTrait`. Single `actionIndex(): int` orchestrating the analyze pipeline (NeverProduction gate FIRST → FilterFactory → LocalePreflight → SchemaDumper → HeuristicProposer → LlmClassifier-or-skip → MappingFile::merge → ReportBuilder). Six CLI flags declared in `options()`: `--noAi --autoAcceptHigh --auditStrict --entities --locales --since`. ExitCode::CONFIG on locale-preflight FAIL; ExitCode::UNSPECIFIED_ERROR on any other FAIL. |

### Files Modified

| File | Diff Summary |
|------|-------------|
| `src/Plugin.php` | +12 / -2 lines. (1) Added 4 `use` statements for the analyze classes (alphabetized at the top of the use block). (2) Added 4 `@property-read` lines to the class docblock. (3) Components map expanded from 4 to 8 entries (legacyDbService preserved with single-space `=> LegacyDbService::class` literal so Phase 1's PluginBootstrapTest reflection assertion stays green; new entries are aligned to the longest key for readability). `init()`, `createSettingsModel()`, `settingsHtml()` untouched. |

## Commits

| Hash | Task | Message |
|------|------|---------|
| `9d6a748` | 1 | feat(02-03): port HeuristicProposer + LlmClassifier under analyze namespace |
| `e83addd` | 2 | feat(02-03): add SchemaDumper service for legacy schema extraction |
| `d011aa4` | 3 | feat(02-03): add ReportBuilder service for REPORT.md rendering |
| `0b11d6f` | 4 | feat(02-03): add AnalyzeController + register 4 analyze components |

## Decisions Honored

- **D-02 (confidence-tier → status)**: AnalyzeController owns the assignment in two private helpers:
  - `statusForHeuristic`: `decision === 'drop'` → `dropped`; `confidence === 'high'` → `accepted`; else → `proposed`.
  - `statusForLlm`: `skipped` → `needs-review`; `confidence === 'high'` → `proposed`; else → `needs-review`.
  - `--auto-accept-high` post-pass promotes `(status: proposed, confidence: high)` rows to `accepted` (MAP-05).
- **D-04 (skip-existing merge)**: AnalyzeController loads existing mapping.yaml via `MappingFile::load(resolvePath())`, then calls `MappingFile::merge($existing, $rows)` — operator decisions are preserved verbatim. Re-running analyze appends only new (table, column, targetEntryType) tuples.
- **D-13 (filters apply uniformly)**: `FilterFactory::fromCli($this->entities, $this->locales, $this->since)` is called at the top of actionIndex (immediately after the NeverProduction gate) and the resulting `MigrationFilters` is threaded through `LocalePreflight::ensure($filters)` and `SchemaDumper::dump($filters)`.
- **D-14 (Settings-then-env API key)**: `LlmClassifier::batchPropose` reads `Plugin::getInstance()->getSettings()->anthropicApiKey` (single chained access — verified by acceptance grep). `Settings::init()` handles the `ANTHROPIC_API_KEY` env-merge once at boot. Direct `App::env('ANTHROPIC_API_KEY')` removed (acceptance grep returns 0).
- **D-15 (Settings::llmModel + llmTimeout overrides)**: `LlmClassifier::init()` applies the overrides to `$this->defaultModel` and `$this->timeoutSeconds` if set. The Yii Component init() lifecycle runs once per resolved service — the v1 per-call env reads are gone.
- **D-17 (paste-ready locales block)**: `ReportBuilder::renderLocales` emits a `## Locales` markdown section listing detected Kunstmaan locales, currently-mapped (Craft sites + Settings::defaultLocales), and unmapped. When unmapped is non-empty, a fenced `config/sites.php` block is emitted with one entry per detected locale and a `// suggested handle: {primaryHandle}` trailer. Re-run hint included.
- **D-20 (NeverProduction gate first)**: Verified by line ordering — `enforceNeverProduction()` call is at line 63, immediately after the `actionIndex` method opener at line 60. Nothing executable precedes the gate.

## Observed v1 Method Signatures (controller adaptation)

The plan body sketched `$plugin->heuristicProposer->autoMatch($schemaDump)` and `$plugin->llmClassifier->batchPropose($schemaDump, $heuristicProposals)`. The actual v1 signatures (preserved by the verbatim port) are:

- `HeuristicProposer::autoMatch(array $violations, array $craftFieldIndex): array{matched, residual}` — takes a list of violation rows + Craft field index keyed by entry type.
- `LlmClassifier::batchPropose(array $residual, array $craftFieldIndex, string $legacyKbMarkdown, string $targetKbMarkdown): list<array>` — takes the residual list + same field index + two KB markdown strings.

AnalyzeController adapts to these signatures with two intermediate builders:

- `buildViolationsFromSchema($schemaDump)`: walks the schema dump and emits one violation row per column. This is naive — Plan 05's CoverageAuditor will replace it with a coverage-aware violation set that knows which columns already have mapping rows.
- `buildCraftFieldIndex()`: returns `[]` for now. Plan 05 will wire the live `Craft::$app->fields->getLayoutByType(EntryType::class)` walk per CONTEXT.md MappingAuditor pattern. With an empty index, the heuristic fires only its zero-fill auto-drop rule (heuristic 1) and routes everything else to LLM residual (or to the `needs-review` skip-stub when LLM is disabled or no API key).

LlmClassifier is also called with empty KB markdown strings (`'', ''`) — Plan 05 (or a later plan) wires the Kunstmaan + Craft KB sources. The classifier tolerates empty strings (truncate is a no-op).

## --audit-strict Declared but Not Yet Wired

The `--audit-strict` flag is declared in AnalyzeController::options() and stored in `$this->auditStrict` per the plan's instruction so Plan 05 (mapping-audit) does not need to re-touch this file. Its semantics ("audit drift findings elevated to fail-state") activate when Plan 05 wires `mappingAuditor->audit($merged)` in and inspects `$this->auditStrict`. The current Plan 03 surface ships only the orchestrator: NeverProduction → preflight → schemaDumper → heuristics → LLM → merge → report.

## Verification

- `php -l` clean on all 6 new files + Plugin.php.
- `composer test` exits 0: 7 tests, 11 assertions. Phase 1 PluginBootstrapTest stays green — the `'legacyDbService' => LegacyDbService::class` literal substring is preserved in the now-8-entry components map (kept at single-space `=>` so the source-level reflection assertion in `testPluginDeclaresLegacyDbServiceComponent` continues to pass).
- All Task 1 acceptance greps pass: namespace flattened, final class declarations, DUTCH_ALIASES present, anthropic-version header preserved, claude-haiku-4-5 default model preserved, 4096 maxTokens preserved, endpoint URL preserved, getSettings()->anthropicApiKey chained access present (Settings-then-env), ->llmModel + ->llmTimeout references present (D-15 overrides wired), App::env('ANTHROPIC_API_KEY') count = 0, bridge\services count = 0.
- All Task 2 acceptance greps pass: final class SchemaDumper, namespace, dump signature, streamQuery (3 occurrences — one declaration in stream loop + two in docblock), information_schema (3 occurrences across two queries + docblock), localePreflight->detect, MigrationFilters use statement.
- All Task 3 acceptance greps pass: final class ReportBuilder, namespace, render method, ## Locales section, config/sites.php paste-ready block, getPrimarySite, defaultLocales reference.
- All Task 4 acceptance greps pass: AnalyzeController class, NeverProductionTrait use, gate-first ordering (line 63, immediately after method opener at line 60), localePreflight->ensure call, all 6 CLI flag property declarations (`public ?string $entities|locales|since` + `public bool $noAi|autoAcceptHigh|auditStrict`), options() flag list with `'noAi', 'autoAcceptHigh', 'auditStrict'` and `'entities', 'locales', 'since'`. Plugin.php registrations: schemaDumper, heuristicProposer, llmClassifier, reportBuilder all present with the aligned `=> Class::class` shape per plan acceptance criteria.
- Plugin.php declares exactly 8 components: legacyDbService (Phase 1), filterFactory + localePreflight (Phase 2 Plan 01), mappingFile (Phase 2 Plan 02), schemaDumper + heuristicProposer + llmClassifier + reportBuilder (Phase 2 Plan 03).

## v1 Defaults Preserved Confirmation

LlmClassifier preserves every v1 default verbatim:
- `defaultModel = 'claude-haiku-4-5'` (overridable via Settings::llmModel)
- `maxTokens = 4096`
- `timeoutSeconds = 60` (overridable via Settings::llmTimeout)
- `ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages'`
- `ANTHROPIC_VERSION_HEADER = '2023-06-01'`
- `array_chunk($etGroup, 10)` per-entry-type batching
- `sleep(20)` between batches (rate-limit hedge)
- 3 retries on 429 with `15 * $attempts` backoff (15s, 30s, 45s) honoring retry-after header
- Per-batch grouping by `targetEntryType` first, then chunked

HeuristicProposer 9-heuristic ordering and DUTCH_ALIASES const are byte-for-byte from v1 (`diff` between the two files would show only the `namespace` line and one comment block change).

## Streaming Confirmation (T-2-09)

SchemaDumper's sample-collection loop:

```php
foreach ($db->streamQuery("SELECT * FROM `{$t}` LIMIT {$scanLimit}") as $row) {
    $rowsSeen++;
    foreach ($row as $colName => $value) { ... }
    if ($rowsSeen >= $scanLimit) { break; }
}
```

`streamQuery` returns a `Generator` (per `LegacyDbService::streamQuery` signature — Phase 1). Each row is yielded one at a time and discarded after sample/fillRate accounting — never accumulated into a full result set. The `LIMIT {scanLimit}` clause caps the SQL-side scan at `min($rowCount, 1000)`. T-2-09 is mitigated.

## Deviations from Plan

**1. [Rule 3 - Blocking] AnalyzeController PluginBootstrapTest literal**

- **Found during:** Task 4 verification (composer test)
- **Issue:** Phase 1's `testPluginDeclaresLegacyDbServiceComponent` asserts the literal substring `'legacyDbService' => LegacyDbService::class` (single space around `=>`). My initial `=>` alignment of the components map (`'legacyDbService'   => LegacyDbService::class`) inserted extra spaces and broke the test.
- **Fix:** Reverted the `legacyDbService` entry to single-space `=>` while keeping the new entries aligned for readability. Documented the exception with a `// (literal preserved for PluginBootstrapTest)` trailing comment.
- **Files modified:** src/Plugin.php (1 entry restored to single-space alignment).
- **Commit:** Folded into 0b11d6f (Task 4 commit) — the issue surfaced during the same task's verification step before the commit landed.

**2. [Rule 1 - Bug] Property-declaration alignment broke acceptance greps**

- **Found during:** Task 4 verification (acceptance grep for property declarations)
- **Issue:** I aligned the 6 CLI-flag property declarations in AnalyzeController for visual parity (`public bool    $noAi`, `public ?string $entities`). The plan's acceptance criteria use the literal-string form (`public ?string $entities` / `public bool $noAi`) which `grep -F` couldn't match.
- **Fix:** Removed the alignment whitespace — single space between type and variable name across all 6 declarations.
- **Files modified:** src/console/AnalyzeController.php.
- **Commit:** Folded into 0b11d6f (Task 4 commit) — same task's verification step.

**3. [Rule 3 - Adaptation] HeuristicProposer / LlmClassifier method signatures**

- **Found during:** Task 4 (controller wiring)
- **Issue:** Plan body sketched `$plugin->heuristicProposer->autoMatch($schemaDump)` and `$plugin->llmClassifier->batchPropose($schemaDump, $heuristicProposals)` — but the v1 services (which we ported verbatim per Task 1's instruction) take `(violations, craftFieldIndex)` and `(residual, craftFieldIndex, legacyKb, targetKb)` respectively.
- **Fix:** The plan explicitly authorizes this adaptation: "If the v1 method names differ, adjust the calls in this controller — but verify by greppping the ported files first." AnalyzeController adapts via two intermediate builders (`buildViolationsFromSchema`, `buildCraftFieldIndex`) which return naive Plan 03 surfaces — Plan 05's CoverageAuditor + MappingAuditor will replace them with cross-referenced versions. LlmClassifier is called with empty KB markdown strings; the truncate helper tolerates them.
- **Files modified:** src/console/AnalyzeController.php (added two private helper methods, signed and documented).
- **Commit:** 0b11d6f.

## Authentication Gates

None — code-only work. The Anthropic API call surface is wired but only triggers when `analyze` is run against a real legacy DB with `--no-ai` unset. Plan 03 acceptance is `composer test` + lint + grep; no live API call is made during plan execution.

## Threat Surface Scan

No new threat surface beyond the plan's `<threat_model>`:
- T-2-09 (D, SchemaDumper OOM): mitigated by streamQuery + LIMIT cap. Verified.
- T-2-10 (I, LLM sample disclosure): accept (operator-awareness mitigation; same posture as v1).
- T-2-11 (I, API key in error messages): mitigated. LlmClassifier reads via Settings; missing-key exception is a literal string that does not echo the key value; HTTP request includes the key only in `x-api-key` header (never logged). Verified by Task 1 acceptance grep on `getSettings()->anthropicApiKey`.
- T-2-12 (E, production legacy DB read): mitigated by gate-first NeverProduction in actionIndex. Verified by line-ordering grep.
- T-2-13 (T, mapping.yaml mid-write): mitigated by MappingFile::writeAtomic (Plan 02). AnalyzeController writes via writeAtomic + writeAtomicJson only.
- T-2-14 (R, drop-reason context): mitigated. HeuristicProposer's zero-fill auto-drop sets `rationale = 'fill-rate is 0 — no data in source'`; AnalyzeController's `statusForHeuristic` returns `'dropped'` for those rows. Rationale is persisted in the mapping.yaml row via MappingFile::buildRow.

## Requirements

- **MAP-01 (analyze emits schema-dump.json + REPORT.md + mapping.yaml)**: ✅ Satisfied. AnalyzeController writes all three artifacts atomically.
- **MAP-02 (deterministic heuristics first, LLM for residuals)**: ✅ Satisfied. HeuristicProposer runs first; only its `residual` output is fed to LlmClassifier.
- **MAP-03 (per-row status with confidence-tier mapping)**: ✅ Satisfied. AnalyzeController applies D-02 status assignment via statusForHeuristic + statusForLlm.
- **MAP-04 (existing rows preserved on re-run)**: ✅ Satisfied. AnalyzeController calls MappingFile::merge with existing-first semantics.
- **MAP-05 (--auto-accept-high promotes LLM-high → accepted)**: ✅ Satisfied. Implemented as a post-build pass before merge.
- **LOC-01 (paste-ready Craft sites: block on unmapped locales)**: ✅ Satisfied. ReportBuilder::renderLocales emits the block.
- **FILT-03 (every CLI command accepts --entities --locales --since)**: ✅ Satisfied for analyze. The three flags are declared in options() and threaded through FilterFactory::fromCli.

## Downstream Hooks

The next Phase 2 plans can now wire to:

- **Plan 04 (map rubber-stamp)**: MappingFile::loadProposed (already exists) + MappingFile::setStatus (already exists) — Plan 03 produced the mapping.yaml that the rubber-stamp loop walks.
- **Plan 05 (coverage-audit-doctor)**: replace `AnalyzeController::buildViolationsFromSchema` with a real CoverageAuditor call; replace `AnalyzeController::buildCraftFieldIndex` with a live FieldLayout walk. Wire `mappingAuditor->audit($merged)` after the merge step and inspect `$this->auditStrict` for fail-state elevation.
- **Plan 06 (tests-and-doc-patches)**: characterization tests for HeuristicProposer's 9 heuristics + DUTCH_ALIASES; doc patches for FILT-01 (--max-per-entity drop) + ROADMAP Phase 2 success criterion 5 (three flags, not four).

## TDD Gate Compliance

Plan 03 is `type: execute`, not `type: tdd` — no plan-level RED/GREEN/REFACTOR gate applies. Phase 1's PluginBootstrapTest still passes; characterization tests for the heuristics are deferred to Plan 06 / Phase 5 TST-02.

## Self-Check: PASSED

**Files exist:**
- src/analyze/HeuristicProposer.php FOUND
- src/analyze/LlmClassifier.php FOUND
- src/analyze/MappingProposalException.php FOUND
- src/analyze/SchemaDumper.php FOUND
- src/analyze/ReportBuilder.php FOUND
- src/console/AnalyzeController.php FOUND
- src/Plugin.php FOUND (modified)

**Commits exist:**
- 9d6a748 FOUND
- e83addd FOUND
- d011aa4 FOUND
- 0b11d6f FOUND
