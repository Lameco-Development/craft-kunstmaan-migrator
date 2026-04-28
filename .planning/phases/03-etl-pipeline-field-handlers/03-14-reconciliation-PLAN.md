---
phase: 03-etl-pipeline-field-handlers
plan: 14
type: execute
wave: 5
depends_on: ['03-01', '03-02', '03-03', '03-04', '03-05', '03-06', '03-07', '03-08', '03-09', '03-10', '03-11', '03-12', '03-13']
files_modified:
  - .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md
  - .planning/REQUIREMENTS.md
  - .planning/ROADMAP.md
autonomous: true
requirements: [ETL-07, FIN-02]
must_haves:
  truths:
    - "Phase-level RECONCILIATION.md aggregates every per-plan reconciliation table from Plans 03-01..03-13 into a single audit-able artifact (D-46 closing discipline — same shape as Phase 02.1's RECONCILIATION.md)."
    - "Phase 3 ETL-01..07, FH-01..04, FIN-01..02 are checked off in REQUIREMENTS.md with traceability comments pointing at the plans that landed each."
    - "ROADMAP.md Phase 3 entry transitions from open to a per-plan checkmark list mirroring Phase 02.1's pattern."
    - "Page-part ordering drift entry (CONTEXT D-49 vs v1 ExtractService:433) is the single most important RECONCILIATION fact — surfaced at top of phase-summary section."
  artifacts:
    - path: ".planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md"
      provides: "Phase 3 v1↔v2 rule-by-rule disposition aggregate. Mirrors Phase 02.1 RECONCILIATION.md structure."
      min_lines: 250
  key_links:
    - from: ".planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md"
      to: "Plans 03-01..03-13 RECONCILIATION sections"
      via: "aggregation of per-plan tables"
      pattern: "aggregated"
---

<objective>
Phase-closing artifact. Aggregates every per-plan RECONCILIATION section from Plans 03-01..03-13 into a single phase-level artifact, mirroring Phase 02.1's RECONCILIATION.md (which produced 4 ported / 23 dropped intentionally / 2 dropped accidentally → patched).

Three deliverables:
1. Write `.planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` aggregating all 13 plans' tables.
2. Patch `.planning/REQUIREMENTS.md` checkmarks for ETL-01..07, FH-01..04, FIN-01..02 with traceability comments.
3. Patch `.planning/ROADMAP.md` Phase 3 entry to mirror Phase 02.1's per-plan checkmark list.

Wave 5 — depends on every prior Phase 3 plan. The phase-closing plan.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/02.1-source-introspection/RECONCILIATION.md

<interfaces>
**RECONCILIATION.md structure (mirrors Phase 02.1):**

```markdown
# RECONCILIATION.md — v1↔v2 rule-by-rule disposition

**Phase:** 03 ETL Pipeline & Field Handlers · **Plan:** 14 (closing)
**Methodology:** D-46 — Verbatim port + per-plan disposition.

**v1 brownfield reference root:** `~/Sites/craft-kunstmaan-migrator/src/`
**v2 fresh-write reference root:** `src/{extract,transform,load,finalize,fields}/`

LOC delta context: ~3000 LOC v1 ETL (TransformService 940 + AssetMigrationService 617 +
EntryMigrationService 662 + CkeditorRewriterService 529 + ExtractService 533 +
MigrationStateService 356 + AtomicMigrationService 255 + 5 handlers ~880 + AttachService 178 +
support VOs ~250) ports to v2 at ~2900 LOC after intentional drops + reshape adds.

## DRIFT NOTE — page-part ordering (CRITICAL)

CONTEXT.md D-49 says page-parts are "ordered by `kuma_page_part_refs.weight`". v1 source-of-truth
at `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php:433` uses
`ORDER BY context, sequencenumber`. **D-46 verbatim discipline overrides — v1 wins.**
Adopted v1 ordering verbatim in Plan 03-04. CONTEXT.md wording to be corrected at next
phase-doc update.

---

## Per-plan reconciliation aggregates

(13 sections — one per Plan 03-01..03-13, copied from each plan's RECONCILIATION block)

---

## Summary

### Counts table (one row per (Plan, file) pair)

| Plan | File | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---|---:|---:|---:|---:|---:|
| 03-01 | FieldHandler | 2 | 0 | 0 | 0 | 0 |
... (one row per ported file) ...
| **Phase 3 totals** | | NN | NN | 0 | NN | NN |

### Phase 4 follow-up backlog (from "partially ported" rows)

1. **KunstmaanSerializedDecoder** — Phase 4 reinstates typed property in ExtractService + AssetMigrationService.
2. **MigrationReport VO** — Phase 4 may extend with additional fields if Phase 3 rehearsal surfaces gaps; baseline VO landed in Plan 03-12.
3. **AttachService::attachAllFromSettings** — Phase 4 / CFG-01 reinstates with v1 body once Settings::$entryTypeUids declared.
4. **AtomicMigrationService SEOmatic closure call** — Phase 4 / ADP-01 reinstates `$module->seoMigrationService->migrateForEntry(...)` inside the existing transaction closure.
5. **PlainTextHandler 'seomatic' mode** — Phase 4 / ADP-01 reinstates the 5th match arm + writeSeomatic() + SeomaticPayloadBuilder constructor parameter.
```

**REQUIREMENTS.md patches** (one entry each):

```markdown
- [x] **ETL-01**: ... _(Phase 3 / Plan 13 — MigrateController::actionIndex orchestrates extract → transform → load → finalize; --live default-dry-run flag.)_
- [x] **ETL-02**: ... _(Phase 3 / Plan 13 — actionExtract / actionTransform / actionLoad / actionFinalize / actionTruncate sub-actions.)_
- [x] **ETL-03**: ... _(Phase 3 / Plan 04 — ExtractService consumes TopologicalOrderer for kuma_nodes parent-first ordering.)_
- [x] **ETL-04**: ... _(Phase 3 / Plan 12 — AtomicMigrationService Phase B Craft::$app->db->transaction wrapping saveEntryForSites; atomic-always-on per-entry.)_
- [x] **ETL-05**: ... _(Phase 3 / Plans 03 + 12 — MigrationStateService CRUD + AtomicMigrationService idempotency gate skips state-table-present entries unless --force.)_
- [x] **ETL-06**: ... _(Phase 3 / Plan 13 — MigrateController per-entry progress emission `[N/total] slug → verb`.)_
- [x] **ETL-07**: ... _(Phase 3 / Plan 13 — actionTruncate with D-51 wide+safety-rails.)_
- [x] **FH-01**: ... _(Phase 3 / Plans 01, 08, 09, 10 — FieldHandler interface + 5 handlers PlainText/Asset/Matrix/Relation/SplitName.)_
- [x] **FH-02**: ... _(Phase 3 / Plan 01 — FieldHandlerRegistry; Plan 13 — Plugin::init() registers 4 PlainText modes + 4 other handlers.)_
- [x] **FH-03**: ... _(Phase 3 / Plan 05 — AssetMigrationService JIT default + ingestReferenced opt-in; Plan 13 — --preload-assets flag.)_
- [x] **FH-04**: ... _(Phase 3 / Plans 01 + 06 + 08 + 12 — DeferredAssetToken emit + CkeditorRewriterService [M<id>]/[NT<id>] regex + AssetHandler dual-token emission + AtomicMigrationService asset:N regex resolver.)_
- [x] **FIN-01**: ... _(Phase 3 / Plan 06 — CkeditorRewriterService + FinalizeWalker; KUMA_MEDIA_PLACEHOLDER_REGEX + KUMA_NT_PLACEHOLDER_REGEX with URL-encoded variants.)_
- [x] **FIN-02**: ... _(Phase 3 / Plan 06 — `<!-- MIGRATION:UNRESOLVED -->` annotation policy preserved verbatim.)_
```

**ROADMAP.md patches** (Phase 3 section):

```markdown
**Plans:** 14 plans

Plans:
- [x] 03-01-fields-scaffold-PLAN.md — FieldHandler interface + Registry + DeferredAssetToken + ResolverContext (FH-01, FH-02) — completed YYYY-MM-DD
- [x] 03-02-load-vos-PLAN.md — MigrationStateReader + MigrationOptions + AssetPathResolver + 2 TaxonomyResolvers (ETL-04, ETL-05) — completed YYYY-MM-DD
- [x] 03-03-state-service-PLAN.md — MigrationStateService verbatim port (ETL-05) — completed YYYY-MM-DD
- [x] 03-04-extract-service-PLAN.md — ExtractService 533 LOC + page-part-ordering drift documented (ETL-03) — completed YYYY-MM-DD
- [x] 03-05-asset-migration-PLAN.md — AssetMigrationService 617 LOC + JIT/preload reshape (FH-03, ETL-05) — completed YYYY-MM-DD
- [x] 03-06-ckeditor-rewriter-PLAN.md — CkeditorRewriterService 529 LOC + FinalizeWalker greenfield (FIN-01, FIN-02, FH-04) — completed YYYY-MM-DD
- [x] 03-07-attach-and-entry-migration-PLAN.md — AttachService partial + EntryMigrationService 662 LOC (ETL-04) — completed YYYY-MM-DD
- [x] 03-08-handlers-text-asset-PLAN.md — PlainTextHandler (4 modes; seomatic stripped) + AssetHandler (FH-01, FH-04) — completed YYYY-MM-DD
- [x] 03-09-handlers-relation-matrix-PLAN.md — RelationHandler + MatrixHandler with D-49 dispatch (FH-01) — completed YYYY-MM-DD
- [x] 03-10-handlers-splitname-PLAN.md — SplitNameHandler with Dutch tokens preserved (FH-01) — completed YYYY-MM-DD
- [x] 03-11-transform-service-PLAN.md — TransformService 940 LOC with D-48 in-process pipeline reshape (ETL-01, FH-02) — completed YYYY-MM-DD
- [x] 03-12-atomic-migration-PLAN.md — MigrationReport VO + AtomicMigrationService 255 LOC (ETL-01, ETL-04, ETL-05, FH-04) — completed YYYY-MM-DD
- [x] 03-13-controller-and-wiring-PLAN.md — Plugin::config/init Phase 3 wiring + MigrateController 6 actions + Doctor 6th check (ETL-01..07, FH-02, FH-03, FIN-01) — completed YYYY-MM-DD
- [x] 03-14-reconciliation-PLAN.md — Phase RECONCILIATION.md + REQUIREMENTS/ROADMAP patches (ETL-07, FIN-02) — completed YYYY-MM-DD
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Write phase-level RECONCILIATION.md aggregating Plans 03-01..03-13</name>
  <files>.planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md</files>
  <read_first>
    - .planning/phases/02.1-source-introspection/RECONCILIATION.md (template — full structure)
    - .planning/phases/03-etl-pipeline-field-handlers/03-01-fields-scaffold-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-02-load-vos-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-03-state-service-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-04-extract-service-PLAN.md (extract <reconciliation> — page-part ordering drift)
    - .planning/phases/03-etl-pipeline-field-handlers/03-05-asset-migration-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-06-ckeditor-rewriter-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-07-attach-and-entry-migration-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-08-handlers-text-asset-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-09-handlers-relation-matrix-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-10-handlers-splitname-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-11-transform-service-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-12-atomic-migration-PLAN.md (extract <reconciliation> block)
    - .planning/phases/03-etl-pipeline-field-handlers/03-13-controller-and-wiring-PLAN.md (extract <reconciliation> block)
  </read_first>
  <action>
    Create `.planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` mirroring the Phase 02.1 RECONCILIATION.md structure.

    **Top-level sections:**
    1. Header (Phase + Plan ID + methodology pointer to D-46).
    2. **DRIFT NOTE — page-part ordering (CRITICAL).** Surface this at the top because it's the single most important reconciliation fact in Phase 3.
    3. Per-plan aggregates — copy each plan's `<reconciliation>` block under its own H2 heading (`## Plan 03-NN: <plan name>`).
    4. **Summary counts table** — one row per (Plan, ported-file) pair with columns: ported / dropped intentionally / dropped accidentally / partially ported / new in v2. Roll up totals at the bottom.
    5. **Phase 4 follow-up backlog** — list of every "partially ported" item across the phase, each pointing at the Phase 4 plan that reinstates it.

    **Drift note section content (verbatim):**

    ```markdown
    ## DRIFT NOTE — page-part ordering (CRITICAL)

    CONTEXT.md D-49 says page-parts are "ordered by `kuma_page_part_refs.weight`". v1 source-of-truth at `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php:433` uses `ORDER BY context, sequencenumber`. D-46 verbatim discipline overrides — v1 wins. Adopted v1 ordering verbatim in Plan 03-04. CONTEXT.md wording to be corrected at next phase-doc update.

    The full SQL fragment preserved verbatim (Plan 03-04 acceptance grep enforces):

    ```sql
    SELECT pp.* FROM kuma_page_part_refs ppr
    JOIN <pagePartTable> pp ON pp.id = ppr.pagepart_id
    WHERE ppr.pagepartable_id = :nv AND ppr.pagepartable_type = :ptype
    ORDER BY context, sequencenumber
    ```
    ```

    **Phase 4 follow-up backlog content** — list these 5 items with cross-refs:
    1. KunstmaanSerializedDecoder — ExtractService + AssetMigrationService both have `?object = null` slot; Phase 4 reinstates typed.
    2. AttachService::attachAllFromSettings — stub throws; Phase 4 / CFG-01 reinstates with v1 body once Settings::$entryTypeUids declared.
    3. AtomicMigrationService SEOmatic closure-call — comment marker preserved; Phase 4 / ADP-01 reinstates `$module->seoMigrationService->migrateForEntry(...)` inside the same transaction closure.
    4. PlainTextHandler 'seomatic' mode — match arm + writeSeomatic + SeomaticPayloadBuilder ctor param all dropped; Phase 4 / ADP-01 reinstates.
    5. Source-scan caching across invocations (`source-scan.json` artifact + `--rescan-source` flag) — carryover from Phase 02.1; profile-driven.

    **Aggregate counts table.** After copying every plan's reconciliation block, build a single rolled-up table at the file's bottom. Format:

    ```markdown
    | Plan | File | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
    |---|---|---:|---:|---:|---:|---:|
    | 03-01 | FieldHandler | 2 | 0 | 0 | 0 | 0 |
    | 03-01 | FieldHandlerRegistry | 1 | 1 | 0 | 0 | 0 |
    | 03-01 | DeferredAssetToken | 1 | 0 | 0 | 0 | 0 |
    | 03-01 | ResolverContext | 2 | 0 | 0 | 0 | 0 |
    | ... (one row per ported file across all 13 plans) ... |
    | **Phase 3 totals** | — | NN | NN | 0 | NN | NN |
    ```

    The roll-up totals are derived from each plan's `### Counts` subtable. Sum across all plans. Expected ballpark per the per-plan totals: ~40 ported / ~20 dropped intentionally / 0 dropped accidentally / ~10 partially ported / ~5 new-in-v2 (the exact numbers will be computed by the executor walking each plan's tables; treat the ballpark as a sanity check).

    Mark "0 dropped accidentally" as the headline outcome — Phase 3's verbatim discipline (D-46) succeeded if no v1 rules slipped through unintentionally.

    **Use the MappingFile::writeAtomic pattern** (Shared Pattern 5 from PATTERNS.md — applies to any artifact under .planning/) to write the file atomically. Or simply `file_put_contents` with rename — this is a doc artifact, not an operational one.
  </action>
  <verify>
    <automated>test -f .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md && wc -l .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md</automated>
  </verify>
  <done>
    - `.planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` exists.
    - File has at least 250 lines.
    - `grep -c "DRIFT NOTE — page-part ordering" .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` returns 1.
    - `grep -c "ORDER BY context, sequencenumber" .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` >= 1.
    - `grep -c "Plan 03-01\\|Plan 03-02\\|Plan 03-03\\|Plan 03-04\\|Plan 03-05\\|Plan 03-06\\|Plan 03-07\\|Plan 03-08\\|Plan 03-09\\|Plan 03-10\\|Plan 03-11\\|Plan 03-12\\|Plan 03-13" .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` >= 13 (every plan section present).
    - `grep -c "Phase 4 follow-up" .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` >= 1.
    - `grep -c "KunstmaanSerializedDecoder\\|attachAllFromSettings\\|seoMigrationService\\|seomatic mode" .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` >= 4 (4 of the 5 follow-up backlog items mentioned).
    - `grep -c "Phase 3 totals" .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` returns 1.
  </done>
</task>

<task type="auto">
  <name>Task 2: Patch REQUIREMENTS.md ETL-01..07, FH-01..04, FIN-01..02 with completion + traceability</name>
  <files>.planning/REQUIREMENTS.md</files>
  <read_first>
    - .planning/REQUIREMENTS.md (current — find ETL/FH/FIN sections)
    - .planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md (Task 1 output)
    - .planning/REQUIREMENTS.md format reference: SRC-01..19 entries for traceability comment style (e.g. `_(Phase 02.1 / Plan 05.)_`)
  </read_first>
  <action>
    Patch `.planning/REQUIREMENTS.md`. For each of the 13 Phase 3 requirements (ETL-01..07, FH-01..04, FIN-01..02), change the leading `- [ ]` checkbox to `- [x]` and append a traceability italic-comment after the existing requirement description. Use the format established by Phase 1, 2, 02.1 entries (see SRC-* lines for canonical example).

    Apply these exact patch entries (the existing line text stays; only the checkbox flips and the traceability suffix is appended):

    1. `ETL-01`: append ` _(Phase 3 / Plan 13 — MigrateController::actionIndex orchestrates extract → transform → load → finalize sequence; --live writes, default dry-run.)_`
    2. `ETL-02`: append ` _(Phase 3 / Plan 13 — actionExtract / actionTransform / actionLoad / actionFinalize / actionTruncate sub-actions per CONTEXT D-48 in-process pipeline; standalone actionLoad re-runs extract+transform internally.)_`
    3. `ETL-03`: append ` _(Phase 3 / Plan 04 — ExtractService consumes Phase 02.1 / TopologicalOrderer for kuma_nodes parent-first hierarchical insertion; cycle-detection inherited.)_`
    4. `ETL-04`: append ` _(Phase 3 / Plan 12 — AtomicMigrationService Phase B Craft::$app->db->transaction wrapping saveEntryForSites + state-row record; atomic-always-on, no flag, per CONTEXT decision.)_`
    5. `ETL-05`: append ` _(Phase 3 / Plans 03 + 12 — MigrationStateService CRUD over kunstmaanmigrator_state + AtomicMigrationService idempotency gate (existingId !== null && !$overwrite → skip).)_`
    6. `ETL-06`: append ` _(Phase 3 / Plan 13 — MigrateController per-entry progress emission [N/total] slug → created|updated|skipped|FAILED:reason; FAILED to stderr, others to stdout per Shared Pattern 4.)_`
    7. `ETL-07`: append ` _(Phase 3 / Plan 13 — actionTruncate per CONTEXT D-51 wide+safety-rails: defaults to --dry-run, requires --live --confirm, honors --entities + --locales filters.)_`
    8. `FH-01`: append ` _(Phase 3 / Plans 01 + 08 + 09 + 10 — FieldHandler interface (verbatim port) + 5 handlers PlainText/Asset/Matrix/Relation/SplitName. CKEditor body-token rewrite is FH-04 inline path on PlainTextHandler 'ckeditor' mode.)_`
    9. `FH-02`: append ` _(Phase 3 / Plans 01 + 13 — FieldHandlerRegistry hash-keyed + Plugin::init() registers 4 PlainText modes via $registry->register(new PlainTextHandler('plain'/'ckeditor'/'link'/'dropdown')) + 4 other handlers.)_`
    10. `FH-03`: append ` _(Phase 3 / Plans 05 + 13 — AssetMigrationService::resolveFromLegacyId JIT default + ingestReferenced opt-in; MigrateController --preload-assets flag exposes the batch path. Orphan media deliberately not migrated (NEXT-05 deferred).)_`
    11. `FH-04`: append ` _(Phase 3 / Plans 01 + 06 + 08 + 12 — DeferredAssetToken::emit('asset:N') + CkeditorRewriterService [M<id>]/[NT<id>] regex constants + AssetHandler dual-token emission ([M<id>] for imgTag, asset:N for relation) + AtomicMigrationService /^asset:\\d+$/ resolver pair.)_`
    12. `FIN-01`: append ` _(Phase 3 / Plan 06 — CkeditorRewriterService 6-step rewrite() pipeline preserved verbatim including KUMA_MEDIA_PLACEHOLDER_REGEX + KUMA_NT_PLACEHOLDER_REGEX (URL-encoded %5B/%5D variants with case-insensitive flag). FinalizeWalker greenfield walks Entry::find()->siteId('*'); MigrateController::actionFinalize entry point.)_`
    13. `FIN-02`: append ` _(Phase 3 / Plan 06 — \`<!-- MIGRATION:UNRESOLVED source=... -->\` HTML comment annotation policy preserved byte-for-byte from v1; no silent drops; FinalizeWalker counts unresolvable fields for REPORT.md.)_`

    DO NOT change any other line in REQUIREMENTS.md. DO NOT modify ETL/FH/FIN section headers, the existing requirement description text, or any other phase's entries.
  </action>
  <verify>
    <automated>grep -c "^- \\[x\\] \\*\\*ETL-0\\|^- \\[x\\] \\*\\*FH-0\\|^- \\[x\\] \\*\\*FIN-0" .planning/REQUIREMENTS.md</automated>
  </verify>
  <done>
    - `.planning/REQUIREMENTS.md` has 13 newly-checked entries: 7 ETL + 4 FH + 2 FIN = 13.
    - `grep -c "^- \\[x\\] \\*\\*ETL-0[1-7]\\*\\*" .planning/REQUIREMENTS.md` returns 7.
    - `grep -c "^- \\[x\\] \\*\\*FH-0[1-4]\\*\\*" .planning/REQUIREMENTS.md` returns 4.
    - `grep -c "^- \\[x\\] \\*\\*FIN-0[12]\\*\\*" .planning/REQUIREMENTS.md` returns 2.
    - `grep -c "Phase 3 / Plan" .planning/REQUIREMENTS.md` >= 13 (one traceability suffix per requirement).
  </done>
</task>

<task type="auto">
  <name>Task 3: Patch ROADMAP.md Phase 3 entry with per-plan checkmark list</name>
  <files>.planning/ROADMAP.md</files>
  <read_first>
    - .planning/ROADMAP.md (lines 148-160 — current Phase 3 section)
    - .planning/ROADMAP.md (lines 137-147 — Phase 02.1 section as the formatting precedent)
  </read_first>
  <action>
    Patch `.planning/ROADMAP.md`. Locate the Phase 3 section (`### Phase 3: ETL Pipeline & Field Handlers` — currently around line 148-160).

    Add the per-plan checkmark list following Phase 02.1's pattern. After the `**Success criteria:**` block (which ends with criterion 4 around line 158), append:

    ```markdown
    **Plans:** 14 plans

    Plans:
    - [x] 03-01-fields-scaffold-PLAN.md — FieldHandler interface + Registry + DeferredAssetToken + ResolverContext (FH-01, FH-02) — completed YYYY-MM-DD
    - [x] 03-02-load-vos-PLAN.md — MigrationStateReader + MigrationOptions + AssetPathResolver + 2 TaxonomyResolvers (ETL-04, ETL-05) — completed YYYY-MM-DD
    - [x] 03-03-state-service-PLAN.md — MigrationStateService verbatim port (ETL-05) — completed YYYY-MM-DD
    - [x] 03-04-extract-service-PLAN.md — ExtractService 533 LOC + page-part-ordering drift documented (ETL-03) — completed YYYY-MM-DD
    - [x] 03-05-asset-migration-PLAN.md — AssetMigrationService 617 LOC + JIT/preload reshape (FH-03, ETL-05) — completed YYYY-MM-DD
    - [x] 03-06-ckeditor-rewriter-PLAN.md — CkeditorRewriterService 529 LOC + FinalizeWalker greenfield (FIN-01, FIN-02, FH-04) — completed YYYY-MM-DD
    - [x] 03-07-attach-and-entry-migration-PLAN.md — AttachService partial + EntryMigrationService 662 LOC (ETL-04) — completed YYYY-MM-DD
    - [x] 03-08-handlers-text-asset-PLAN.md — PlainTextHandler (4 modes; seomatic stripped) + AssetHandler (FH-01, FH-04) — completed YYYY-MM-DD
    - [x] 03-09-handlers-relation-matrix-PLAN.md — RelationHandler + MatrixHandler with D-49 dispatch (FH-01) — completed YYYY-MM-DD
    - [x] 03-10-handlers-splitname-PLAN.md — SplitNameHandler with Dutch tokens preserved (FH-01) — completed YYYY-MM-DD
    - [x] 03-11-transform-service-PLAN.md — TransformService 940 LOC with D-48 in-process pipeline reshape (ETL-01, FH-02) — completed YYYY-MM-DD
    - [x] 03-12-atomic-migration-PLAN.md — MigrationReport VO + AtomicMigrationService 255 LOC (ETL-01, ETL-04, ETL-05, FH-04) — completed YYYY-MM-DD
    - [x] 03-13-controller-and-wiring-PLAN.md — Plugin::config/init Phase 3 wiring + MigrateController 6 actions + Doctor 6th check (ETL-01..07, FH-02, FH-03, FIN-01) — completed YYYY-MM-DD
    - [x] 03-14-reconciliation-PLAN.md — Phase RECONCILIATION.md + REQUIREMENTS/ROADMAP patches (ETL-07, FIN-02) — completed YYYY-MM-DD
    ```

    The executor running this plan replaces `YYYY-MM-DD` with today's date as recorded by `date +%Y-%m-%d`.

    Also update the table at the top of ROADMAP.md (line 14 area):
    - Change Phase 3's "Success Criteria" column from `4` to `4` (no change — already 4).
    - Optionally annotate phase 3's row with "(closed)" once the phase actually closes — but executor only marks plans, not the phase row; the orchestrator owns phase-row close.

    DO NOT change any other phase section's content. DO NOT modify Phase 1, 2, 02.1, 4, or 5 entries.
  </action>
  <verify>
    <automated>grep -c "03-01-fields-scaffold-PLAN" .planning/ROADMAP.md</automated>
  </verify>
  <done>
    - `.planning/ROADMAP.md` Phase 3 section has the per-plan checkmark list.
    - `grep -c "03-01-fields-scaffold-PLAN\\|03-02-load-vos-PLAN\\|03-13-controller-and-wiring-PLAN\\|03-14-reconciliation-PLAN" .planning/ROADMAP.md` >= 4.
    - `grep -c "**Plans:** 14 plans" .planning/ROADMAP.md` returns 1 (Phase 3 plan count present).
    - `grep -c "^- \\[x\\] 03-" .planning/ROADMAP.md` returns 14 (all 14 plans checked).
  </done>
</task>

</tasks>

<verification>
- RECONCILIATION.md exists with drift note + 13 per-plan aggregates + summary table.
- REQUIREMENTS.md has 13 Phase 3 requirements checked off with traceability comments.
- ROADMAP.md has 14-plan checkmark list under Phase 3.
- No prior phase entries modified.
</verification>

<success_criteria>
- Phase-level RECONCILIATION.md surfaces page-part ordering drift at top.
- All 13 per-plan reconciliation tables aggregated.
- Phase 4 follow-up backlog explicitly enumerates 5 partially-ported items.
- REQUIREMENTS.md ETL-01..07, FH-01..04, FIN-01..02 all checked with traceability suffixes.
- ROADMAP.md Phase 3 section has 14-plan checkmark list mirroring Phase 02.1 format.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-14-reconciliation-SUMMARY.md` recording phase-close totals.
</output>
