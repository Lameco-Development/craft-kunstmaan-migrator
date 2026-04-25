---
phase: 02-schema-mapping-filters
plan: 05
subsystem: mapping-audit
tags: [coverage-gate, mapping-audit, field-layout, doctor, structural-ignore, audit-strict]

# Dependency graph
requires:
  - phase: 01-foundation-connectivity
    provides: NeverProductionTrait, console controllerNamespace, DoctorController (3 checks), Plugin component DI
  - phase: 02-schema-mapping-filters/01
    provides: FilterFactory, MigrationFilters, LocalePreflight
  - phase: 02-schema-mapping-filters/02
    provides: MappingFile (writeAtomic / resolvePath / load)
  - phase: 02-schema-mapping-filters/03
    provides: AnalyzeController (Step 6 ends with mapping.yaml write — Step 7/8 inserted here)
provides:
  - CoverageAuditor service (verdict producer for Phase 3 migrate --live hard-fail)
  - MappingAuditor service (drift detector consumed by AnalyzeController + future Phase 3 migrate)
  - AnalyzeController Step 7 (mapping audit) + MAPPING-AUDIT.md write site
  - DoctorController 4th check (checkMappingFile) — closes the deferred Phase 1 / D-17 gap
  - DoctorController FILT-03 flag declarations (--entities / --locales / --since accepted but ignored)
  - Plugin.php components map: 8 → 10 (coverageAuditor, mappingAuditor)
affects: [Phase 02 / Plan 06 (tests-and-doc-patches), Phase 03 (migrate --live consumes CoverageAuditor verdict for hard-fail wiring)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Verdict-producer-vs-consumer separation (D-15): CoverageAuditor returns the violation list; consumer (AnalyzeController warn-only / Phase 3 migrate hard-fail) decides disposition"
    - "Live FieldLayout walk via Craft::$app->entries->getEntryTypeByHandle + getFieldLayout->getCustomFields (third site after MapController and the future Phase 3 transformers)"
    - "EntryType cache inside MappingAuditor::audit avoids re-resolving same handle when many rows target the same entry type"
    - "Mode-agnostic auditor + flag-driven controller wiring: --audit-strict elevates non-empty findings to ExitCode::UNSPECIFIED_ERROR, MAPPING-AUDIT.md persisted regardless (T-2-21 audit-trail mitigation)"

key-files:
  created:
    - src/mapping/CoverageAuditor.php
    - src/mapping/MappingAuditor.php
  modified:
    - src/console/AnalyzeController.php
    - src/console/DoctorController.php
    - src/Plugin.php

key-decisions:
  - "STRUCTURAL_IGNORE seed list per D-14 plus 5 obvious additions (createdBy_id, updatedBy_id, deletedAt, version, kunstmaanSourceId) — the list can grow as the rehearsal corpus surfaces more bookkeeping columns; v1.0 ships a sensible default"
  - "CoverageAuditor::audit is pure (no I/O) — Phase 3 migrate decides hard-fail vs warn (D-15 verdict-producer-vs-consumer separation)"
  - "MappingAuditor handler-classification check uses substring match on the field class FQCN (false negatives tolerable; false positives would create alarm fatigue)"
  - "Dropped rows are skipped by MappingAuditor — only accepted/proposed rows are walked (a row marked dropped will never resolve to a Craft field, by definition)"
  - "MAPPING-AUDIT.md is written via MappingFile::writeAtomic BEFORE the --audit-strict short-circuit return — operator always has the audit trail even when the run aborts (T-2-21 mitigation)"
  - "DoctorController 4th check (checkMappingFile) WARN-only on missing file (analyze creates it, not doctor); FAIL on parse error or missing 'proposals:' key — matches v1's preflight philosophy of surfacing what's broken vs what's not yet built"
  - "DoctorController accepts --entities / --locales / --since for command-surface uniformity but is explicitly a no-op (FILT-03 doctor doesn't read legacy data)"

patterns-established:
  - "Verdict producer (auditor) + disposition consumer (controller) split — the auditor returns structured findings; mode-agnostic; the controller decides what to do with non-empty findings (warn / fail / artifact-only)"
  - "Atomic write of audit artifact BEFORE any return — operators always have the failure trail on disk even when the command short-circuits"
  - "EntryType resolution caching during a single audit pass to avoid N×M repeat lookups when many rows share entry-type handles"

requirements-completed: [MAP-06, MAP-07, CONN-03, FILT-03]

# Metrics
duration: 5min
completed: 2026-04-25
---

# Phase 2 Plan 05: Coverage Audit + Doctor Summary

**Coverage gate (CoverageAuditor) + FieldLayout drift detector (MappingAuditor) + 4th doctor check (mapping.yaml health). Plugin.php component map grows 8 → 10. AnalyzeController gains Step 7 mapping-audit + MAPPING-AUDIT.md write site, --audit-strict now consumed.**

## Performance

- **Duration:** ~5 min (288 s)
- **Started:** 2026-04-25T20:54:09Z
- **Completed:** 2026-04-25T20:58:57Z
- **Tasks:** 4
- **Files created:** 2 (CoverageAuditor.php 102 LOC; MappingAuditor.php 172 LOC)
- **Files modified:** 3 (AnalyzeController.php +29 LOC -3; DoctorController.php +43 LOC; Plugin.php +5 LOC)

## Accomplishments

- Shipped CoverageAuditor (D-14 / MAP-06): `final class extends Component` with STRUCTURAL_IGNORE constant + `audit($schemaDump, $mappingProposals): list` returning unmapped data-bearing columns + `renderViolations($violations): string` for v1-shaped stderr output. Verdict producer only — consumer wiring (Phase 3 migrate --live hard-fail) deferred per D-15 verdict-vs-disposition split.
- Shipped MappingAuditor (D-16 / MAP-07): walks every accepted/proposed `(targetEntryType, targetHandle)` row in mapping.yaml against live `Craft::$app->entries->getEntryTypeByHandle->getFieldLayout->getCustomFields`. Returns three structured finding kinds: `missing-entry-type`, `missing-handle`, `handler-classification-mismatch`. EXCLUDED_HANDLES + CANONICAL_HANDLERS + HANDLER_ALIASES ported verbatim from v1 MappingValidator (lines 56-70 + 1794-1802). EntryType caching avoids redundant lookups.
- Wired AnalyzeController Step 7 (mapping audit): calls `mappingAuditor->audit` after MappingFile::merge, writes MAPPING-AUDIT.md atomically, WARN-by-default; `--audit-strict` (declared in Plan 03) now elevates non-empty findings to `ExitCode::UNSPECIFIED_ERROR`. T-2-21 mitigation honored: MAPPING-AUDIT.md is persisted BEFORE the strict-mode short-circuit return so operators always have the drift trail. Step numbering: REPORT.md is now Step 8.
- Closed CONN-03 deferred gap: DoctorController gains 4th check (`checkMappingFile`) — Plan 1 / D-17 had explicitly deferred this to Phase 2 alongside MappingFile. WARN-only on missing file (analyze creates it, not doctor); FAIL on parse error or missing 'proposals:' key. T-2-19 mitigation: `Throwable` from `Yaml::parseFile` caught and reported.
- DoctorController FILT-03 flag declarations: `public ?string $entities/$locales/$since` + `options()` extension. Filters are no-ops on doctor (doesn't read legacy data); declared for command-surface uniformity per FILT-03.
- Plugin.php components map grows 8 → 10: `coverageAuditor => CoverageAuditor::class` + `mappingAuditor => MappingAuditor::class` registered with matching `@property-read` PHPDoc lines.

## Task Commits

1. **Task 1: Build CoverageAuditor with STRUCTURAL_IGNORE constant + data-bearing-column check** — `d81b660` (feat)
2. **Task 2: Build MappingAuditor with FieldLayout walk + handler-classification rules** — `243eb1b` (feat)
3. **Task 3: Wire CoverageAuditor + MappingAuditor into AnalyzeController and register in Plugin.php** — `743b53d` (feat)
4. **Task 4: Add 4th doctor check (checkMappingFile) + FILT-03 flag declarations to DoctorController** — `820f636` (feat)

## Files Created/Modified

### Created

- **src/mapping/CoverageAuditor.php** (102 LOC) — `final class CoverageAuditor extends Component`. STRUCTURAL_IGNORE constant (24 entries: id/parent_id/lft/rgt/lvl/tree_root + audit cols + Doctrine boilerplate + kunstmaanSourceId). `audit()` method indexes covered columns by `(table|column)` (covered = status ∈ {accepted, dropped}), then iterates schema-dump columns filtering by STRUCTURAL_IGNORE + fillRate>0 + not-already-covered. `renderViolations()` groups violations by table for stderr output.

- **src/mapping/MappingAuditor.php** (172 LOC) — `final class MappingAuditor extends Component`. Three constants: EXCLUDED_HANDLES (kunstmaanSourceId + 6 native Element props), CANONICAL_HANDLERS (12-entry vocabulary), HANDLER_ALIASES (plainText/PlainText → plain), HANDLER_FIELD_HINTS (7 substring hints for classification mismatch). `audit()` walks rows skipping dropped ones, resolving entry types through a cache, then checking three kinds of drift in order: missing-entry-type → missing-handle → handler-classification-mismatch. `renderMarkdown()` emits a clean message or a markdown table of findings.

### Modified

- **src/console/AnalyzeController.php** (+29 -3 LOC, now 326 total). Inserted Step 7 between mapping.yaml write (former Step 6) and REPORT.md write (now Step 8): `mappingAuditor->audit` + `mappingFile->writeAtomic($auditPath, $auditMd)` + WARN/FAIL branch on `$this->auditStrict`. Class docblock updated: --audit-strict description rewritten to reflect that the consumer is now wired here (not deferred).

- **src/console/DoctorController.php** (+43 LOC, now 163 total). FILT-03 block: `public ?string $entities/$locales/$since` + `options()` override extending parent options with `['entities', 'locales', 'since']`. `actionIndex` 4th check call site: `$ok = $this->checkMappingFile() && $ok;`. New private method `checkMappingFile()`: resolves path via `Plugin::getInstance()->mappingFile->resolvePath()`, soft-WARNs on missing file, FAILs on parse error or missing 'proposals:' key, OK-with-row-count on clean parse. Class docblock updated to describe 4 checks (was 3) + FILT-03 note.

- **src/Plugin.php** (+5 LOC, now 116 total). Two new `use` statements (CoverageAuditor + MappingAuditor next to MappingFile). Two new `@property-read` PHPDoc lines. Two new components in the `config()` map: `coverageAuditor => CoverageAuditor::class` (Phase 2 Plan 05 — D-14 MAP-06) and `mappingAuditor => MappingAuditor::class` (Phase 2 Plan 05 — D-16 MAP-07). Components map: 8 → 10.

## Decision → Code Mapping

| Decision | Code Block |
|----------|------------|
| D-14 STRUCTURAL_IGNORE seed list | `src/mapping/CoverageAuditor.php` lines 25-33 (constant) + line 64 (in_array check) |
| D-14 data-bearing definition | `src/mapping/CoverageAuditor.php` lines 60-68: name != '' && !in STRUCTURAL_IGNORE && fillRate > 0 |
| D-15 verdict-producer-vs-consumer split | `src/mapping/CoverageAuditor.php::audit` is pure (no I/O); consumer wiring (Phase 3 migrate --live hard-fail) intentionally absent |
| D-16 three drift kinds | `src/mapping/MappingAuditor.php` lines 96-103 (missing-entry-type), 113-121 (missing-handle), 128-141 (handler-classification-mismatch) |
| D-16 warn-only-default + --audit-strict elevation | `src/console/AnalyzeController.php` lines 222-237 |
| D-16 MAPPING-AUDIT.md persisted before strict abort (T-2-21) | `src/console/AnalyzeController.php` lines 213-220 (writeAtomic call before the if-strict branch at line 230) |
| D-17 deferred mapping check landed | `src/console/DoctorController.php` lines 138-163 (`checkMappingFile`) — uses `mappingFile->resolvePath` for path consistency with map/analyze |
| D-20 NeverProduction gate (inherited unchanged) | `src/console/DoctorController.php` lines 38-40 (existing — 4th check rides under the existing gate) |
| FILT-03 doctor flag declarations + ignored-by-design | `src/console/DoctorController.php` lines 30-37 |

## Plugin.php Changes

**8 → 10 components** (config map grew by 2):

```php
'legacyDbService'   => LegacyDbService::class,
'filterFactory'     => FilterFactory::class,
'localePreflight'   => LocalePreflight::class,
'mappingFile'       => MappingFile::class,
'schemaDumper'      => SchemaDumper::class,
'heuristicProposer' => HeuristicProposer::class,
'llmClassifier'     => LlmClassifier::class,
'reportBuilder'     => ReportBuilder::class,
'coverageAuditor'   => CoverageAuditor::class,    // Phase 2 (Plan 05) — D-14 MAP-06
'mappingAuditor'    => MappingAuditor::class,     // Phase 2 (Plan 05) — D-16 MAP-07
```

PluginBootstrapTest unaffected (the test's reflection assertion checks for `legacyDbService` only — adding components doesn't break it).

## Decisions Made

- **CoverageAuditor is shipped, but the consumer (Phase 3 migrate --live hard-fail) is deferred.** This is intentional per D-15 — the verdict producer lives in `audit()`; the disposition (hard-fail-on-migrate-live vs warn-on-dry-run) is the consumer's call. AnalyzeController doesn't currently call CoverageAuditor — the schema-dump → coverage-violation transform is best done at the migrate-time read site, not at analyze-time. AnalyzeController's existing `buildViolationsFromSchema` is a Plan-03-shaped naive transform documented as such.
- **MappingAuditor is the analyze-time drift detector; CoverageAuditor is the migrate-time gate.** Different consumers, different lifecycles. AnalyzeController writes MAPPING-AUDIT.md at analyze-time (warn-by-default + --audit-strict opt-in); CoverageAuditor's verdict will be wired at migrate-time in Phase 3.
- **MAPPING-AUDIT.md persists even when --audit-strict aborts the run.** Operators always have the audit trail. Without this, --audit-strict would be a footgun: the operator sees a FAIL line and has no markdown to consult. The atomic-write contract from Plan 02 ensures the file is fully written before the abort.
- **DoctorController's 4th check is WARN-only on missing file.** The mapping.yaml is created by `analyze`, not by `doctor`. A missing mapping.yaml on a fresh greenfield install is expected, not broken. FAIL is reserved for parse error or missing 'proposals:' key (the file exists but is broken in a way the operator needs to know about).
- **DoctorController accepts FILT-03 flags but ignores them.** Doctor doesn't read legacy data; filters have no semantic effect. Declared for command-surface uniformity (every Phase 2+ controller declares the same three flags).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Class docblock at AnalyzeController referenced --audit-strict as "deferred to Plan 05"**

- **Found during:** Task 3 verification (the acceptance criterion `grep -c 'mappingAuditor->audit' src/console/AnalyzeController.php` equals 1` was failing with count=2)
- **Issue:** The Plan 03 docblock contained the literal string "mappingAuditor->audit invocation" inside a comment describing where Plan 05's consumer would land. After Plan 05 wired the actual consumer call, the docblock comment was stale (referred to its own future-tense self) and matched the acceptance grep along with the real call site, breaking the equals-1 check.
- **Fix:** Updated the AnalyzeController class docblock to reflect that --audit-strict is now wired by Plan 05 (replaced the "lands when Plan 05 ships" sentence with one describing actual current behavior). Removes the stale forward reference and brings the grep count back to the expected 1.
- **Files modified:** src/console/AnalyzeController.php
- **Commit:** `743b53d` (rolled into Task 3 commit)

## Authentication Gates

None — no API calls in this plan.

## Issues Encountered

None blocking. The acceptance-criteria grep for `public ?string $entities` initially returned 0 because the unquoted `?` was glob-expanding in the shell; re-running with `grep -F` (fixed-string) returned the expected 1. Code was correct; only the verification command had a shell-escaping issue.

## Verification

- `php -l` exits 0 on all 5 affected files (`CoverageAuditor.php`, `MappingAuditor.php`, `AnalyzeController.php`, `DoctorController.php`, `Plugin.php`).
- `composer test` exits 0 (7 tests, 11 assertions — Phase 1 PluginBootstrapTest + NeverProductionTrait tests still green; Plugin::config() change is additive and test reflection still finds `legacyDbService`).
- All Task 1-4 acceptance-criteria greps pass (verified inline).
- `Plugin.php` registers exactly 10 components.
- `DoctorController` has exactly 4 checks (`grep -c '\$ok = \$this->check'` returns 4).
- `AnalyzeController` calls `mappingAuditor->audit` exactly 1× at the runtime call site (line 212) plus the docblock reference removed.
- `AnalyzeController` consumes `$this->auditStrict` (verified by `grep -c '\$this->auditStrict'` ≥ 1).

## Threat Mitigations Verified

- **T-2-19 (mapping.yaml YAML parse error from corruption)** — `DoctorController::checkMappingFile` wraps `\Symfony\Component\Yaml\Yaml::parseFile` in try/catch (Throwable) and reports a FAIL with the parse message. Atomic-write contract from Plan 02 means file is either complete-and-valid or unchanged-from-before-write — never partial.
- **T-2-20 (DoctorController against production)** — NeverProduction gate (Phase 1 / D-20) inherited unchanged. The 4th check rides under the existing first-statement guard at line 38-40 of `actionIndex`. Verified by inspection — no new gate-bypassing code paths added.
- **T-2-21 (MAPPING-AUDIT.md persists drift findings even when --audit-strict aborts)** — `AnalyzeController` Step 7: `writeAtomic($auditPath, $auditMd)` is called BEFORE the `if ($this->auditStrict)` branch returns `ExitCode::UNSPECIFIED_ERROR`. Operators always have the drift markdown on disk regardless of exit code. Verified by reading lines 213-237 of `AnalyzeController.php`.
- **T-2-22 (MAPPING-AUDIT.md exposes Craft schema details)** — accepted per threat register. The audit lists EntryType handles + field handles + Craft field FQCNs; these are not secrets. File lives under `storage/migration/` which is not web-accessible.

## Self-Check: PASSED

- Files exist:
  - `src/mapping/CoverageAuditor.php` — FOUND (102 LOC)
  - `src/mapping/MappingAuditor.php` — FOUND (172 LOC)
  - `src/console/AnalyzeController.php` — FOUND (modified, 326 LOC)
  - `src/console/DoctorController.php` — FOUND (modified, 163 LOC)
  - `src/Plugin.php` — FOUND (modified, 116 LOC)
- Commits exist:
  - `d81b660` — Task 1
  - `243eb1b` — Task 2
  - `743b53d` — Task 3
  - `820f636` — Task 4
- All acceptance-criteria greps pass (verified per task above).
- `composer test` exits 0 (7 tests, 11 assertions).

## Next Phase Readiness

Plan 02-05 closes the mapping-audit and coverage-gate verdict producers. Phase 2 has 1 plan remaining:

- **Plan 02-06 (tests-and-doc-patches):** characterization tests for the analyze pipeline; doc patches (REQUIREMENTS.md FILT-01 — drop `--max-per-entity` per D-12; ROADMAP.md Phase 2 success criterion 5 — three flags, not four; etc.).

Phase 3 readiness:
- CoverageAuditor verdict producer is ready for Phase 3 `migrate --live` to consume — pure function, no I/O, takes `(schemaDump, mappingProposals)` and returns the violation list. Phase 3 wraps it in a hard-fail branch.
- MappingAuditor is reusable in Phase 3 if `migrate` wants to re-check drift before extract/transform.
- Plugin.php components map is at 10. No more component additions expected in Phase 2.

No blockers from this plan.

---
*Phase: 02-schema-mapping-filters*
*Completed: 2026-04-25*
