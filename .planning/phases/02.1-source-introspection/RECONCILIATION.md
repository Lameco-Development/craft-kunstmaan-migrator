# RECONCILIATION.md — v1↔v2 rule-by-rule disposition

**Phase:** 02.1 source-introspection · **Plan:** 09 (closing)
**Methodology:** D-37 / D-38 / D-39 — feature-parity by default. Every distinct
v1 rule is classified `ported` / `dropped intentionally` / `dropped accidentally`.
Accidentally-dropped rules get patched into v2 in this same plan (Task 2).

**v1 brownfield reference root:** `~/Sites/craft-kunstmaan-migrator/src/`
**v2 fresh-write reference root:** `src/mapping/`

LOC delta context: v2 fresh writes shipped at 102 (CoverageAuditor) + 172
(MappingAuditor) = 274 LOC against v1's 463 + 647 = 1110 LOC. Most of v1's
delta lives in scoping helpers + DB-side coverage probing that the v2
producer-vs-consumer split (D-15) deliberately moves out of the auditor (the
schema dump pre-computes fillRates; the auditor is pure-function over the
artifact). After this reconciliation lands, v2 grows modestly (10–25 LOC) by
adding the empty-table guard + drop-rationale-length finding kind. Final
expected LOC: ~290 vs v1's 1110. The delta is justified by architecture
(D-15 split + flat `proposals[]` per D-01), NOT by accidentally-dropped rules.

---

## CoverageAuditor reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php` (463 LOC)
**v2 file:** `src/mapping/CoverageAuditor.php` (102 LOC pre-Plan-08, 217 LOC after Plan 08 added the page-part coverage block per D-36)

| Rule (v1 file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:39-44` — service injections (LegacyDbService + MappingLoader + KnowledgeBase) | v1 audits the DB directly (counts non-NULL non-empty rows per column at audit time) using KnowledgeBase to scope. | dropped intentionally | v2 architectural decision per D-15 producer-vs-consumer split: SchemaDumper writes fillRate into `schema-dump.json` once at analyze time; CoverageAuditor is pure-function over the artifact. Removes the audit-time DB round-trip surface. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:44` — `legacyTablePrefix = 'lameco_websitebundle_'` field | v1 prefix-scopes the column-load query. | dropped intentionally | v2 SchemaDumper already scopes its own table list (Plan 02-05 + Plan 07's `$sourceTableList` from KunstmaanSourceScanner per D-42 step 6). Auditor consumes whatever the dumper wrote — prefix is upstream. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:46-47` — `IDENT_RX` + `DOTTED_RX` const SQL-safety regexes | v1 uses these to reject untrusted column names before string-interpolating into SQL. | dropped intentionally | v2 has no SQL-execution path — auditor never builds SQL. Identifiers come pre-validated from SchemaDumper's bound-parameter queries. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:66` — `reachableTables()` scoping (delegates to KnowledgeBase) | v1 restricts the audit set to tables actually referenced by an accepted page-class row. Tables never seeded by reachability are never audited. | dropped intentionally | v2 substitutes the explicit STRUCTURAL_IGNORE list (`src/mapping/CoverageAuditor.php:25-33`, 24 entries) + per-fillRate gating. Equivalent gate behavior via different architecture: STRUCTURAL_IGNORE filters bookkeeping columns finer-grained than v1's wholesale-table scoping; per-fillRate=0 skip handles "table present but empty" case. KnowledgeBase::reachableTables port deferred per Plan 03 B3 anti-pattern guard (excluded from partial port). |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:82-85` — empty-table guard (`if ($rowCount === 0) continue`) | v1 short-circuits the per-column walk when the legacy table has zero rows. No data → no possible coverage gap. | **dropped accidentally** → patched in Task 2 of this plan. | v2 reads `$rowCount` at `src/mapping/CoverageAuditor.php:67` but does NOT skip the table when count is 0; instead relies on per-column fillRate. Patch: insert `if ($rowCount === 0) continue;` immediately after the `$rowCount` read, before the inner `foreach ($cols as $col)` loop. Idempotent w.r.t. fillRate semantics (when count=0, fillRate is also 0 by definition, so existing per-column skip catches it — but the explicit early-continue matches v1 exactly and dodges any future `$cols` walk that doesn't gate on fillRate). |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:91-97` — non-NULL non-empty live-DB count (`SELECT COUNT(*) FROM table WHERE col IS NOT NULL AND col != ''`) | v1 recomputes data-bearing-ness at audit time per column. | dropped intentionally | Per D-15: SchemaDumper computes fillRate once and persists in schema-dump.json. Auditor consumes the persisted value at `src/mapping/CoverageAuditor.php:70`. No audit-time SQL. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:95-97` — fillRate=0 short-circuit (`if ($nonNull === 0) continue`) | v1 never violates on empty columns. | ported at `src/mapping/CoverageAuditor.php:73` (`if ($fillRate <= 0) continue;`). | Same guard, sourced from persisted artifact instead of live count. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:98-104` — fillRate threshold (round to 1dp) + samples in violation tuple | v1 emits `{column, fillRate, rows, samples}` tuple shape. | ported (partial — samples omitted) at `src/mapping/CoverageAuditor.php:76-83`. | v2 emits `{kind, table, column, fillRate, rows}` — `samples` field intentionally not carried because schema-dump already contains samples and the auditor's job is verdict-only (consumer can join back to schema-dump for samples). Adds `kind` discriminator for D-36 page-part coverage co-existence. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:115-143` — `loadColumnsForTables()` private helper | v1 reads information_schema.COLUMNS at audit time to enumerate columns per table. | dropped intentionally | Same D-15 driver — SchemaDumper already enumerates `$schemaDump['columns'][$table]`. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:152-253` — `buildCoveredSet()` — walks v1's nested mapping shape (nodeClasses / pageParts / taxonomies + headerBlock + bodyColumn + assets.fkColumns + assets.bodyScanColumns + nodeClass joins) | v1 mapping.yaml is a deeply nested structure with multiple coverage signals. | dropped intentionally | Per Phase 2 / D-01: v2 mapping.yaml is a flat `proposals:` list with explicit `status:` per row. Coverage = exact-tuple match keyed on `(table|column)` for status ∈ {accepted, dropped} — see `src/mapping/CoverageAuditor.php:53-63`. Nested-shape walking is unrepresentable in the v2 shape and unnecessary. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:158-160` — auto-cover PK `id` per reachable table | v1 implicitly covers the primary key on every audited table. | dropped intentionally (replaced by STRUCTURAL_IGNORE `id` entry at `src/mapping/CoverageAuditor.php:26`). | Equivalent gate behavior: v1 marks `id` covered; v2 filters `id` out of the violation candidate set. Net result identical. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:261-273` — `collectJoinAliasFields()` — alias.col → actual_join_table.col resolution | v1 nodeClass joins resolve `employee.email` → `lameco_..._employees.email`. | dropped intentionally | v2 mapping.yaml has no `joins:` block; column rows reference target by `(table, column, targetEntryType)` directly. Alias resolution unrepresentable + unnecessary. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:282-340` — `collectFromFieldsBlock()` — recursive deep-walk of nested fields/handlerOptions/matrix/relation specs | v1 walks arbitrary nesting depth to discover covered sources. | dropped intentionally | Same flat-shape driver — v2 has no nested fields blocks at the column level; per-row coverage is exact-tuple match. Plan 06 D-34's `kind: pagePart` rows DO carry inline `fields[]` but coverage at THAT level is the page-part class match (see Plan 08 / D-36 `auditPagePartCoverage` at `src/mapping/CoverageAuditor.php:112-153`), not per-property. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:406-435` — `collectInlineDrops()` — handles nested `{drop: true, rationale: ...}` shapes | v1 inline drops live nested inside fields blocks. | dropped intentionally | Per D-01: v2 drops are top-level rows with `status: dropped`. Inline-drop shape unrepresentable; the v2 idiom replaces it. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:fetchSamples` — sample fetch via SQL | v1 audit emits sample values for unmapped columns to aid operator triage. | dropped intentionally | Samples already live in `schema-dump.json` (SchemaDumper writes them under `columns[*].samples`). Operator joins back at consumer time. Avoids audit-time SQL per D-15. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php:isSafeIdent` — identifier whitelist regex | v1 needs this to safely interpolate column names into SQL. | dropped intentionally | v2 builds no SQL. Same dropped-rationale as IDENT_RX above. |
| `~/Sites/craft-kunstmaan-migrator/src/bridge/schema/knowledgebase/CoverageAuditor.php` — STRUCTURAL_IGNORE list | v1 has NO equivalent — relied wholly on reachableTables scoping. | new in v2 (greenfield) at `src/mapping/CoverageAuditor.php:25-33` (24 entries seed list per Phase 2 / D-14). | Finer-grained than v1's wholesale-table scoping; lets v2 audit shared tables (e.g. `kuma_news_page`) while still ignoring `id/parent_id/lft/rgt/lvl/...` bookkeeping columns. |
| (D-36 v2-only) — page-part coverage block at `src/mapping/CoverageAuditor.php:112-153` (Plan 08) | Every page-part class declared in pageStructure.json's `contexts[*].allowedPagePartClasses` must have a `(kind=pagePart)` row with status ∈ {accepted, dropped}. | new in v2 (greenfield — D-36) | v1 had no page-part coverage gate (v1's pagePart coverage was indirect via the per-class mapper system — `AbstractPagePartMapper`). v2 makes it an explicit gate as part of D-14 + D-36. |

---

## MappingValidator reconciliation

(also: ## MappingAuditor reconciliation — v2's class is named MappingAuditor; same content, named here for grep-traceability across both v1↔v2 names.)

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php` (647 LOC)
**v2 file:** `src/mapping/MappingAuditor.php` (172 LOC pre-Plan-08, 275 LOC after Plan 08 added the block-availability finding kind + Drift section per D-36 + D-32)

PATTERNS section 13 enumerated 13 distinct v1 rules — the table below confirms each plus walks the file end-to-end for any rule the 13-rule checklist missed.

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Rule 1 — `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:130-141` `validateTopLevelKeys` (10 keys: meta/sites/sections/nodeClasses/pageParts/dataProviders/assets/redirects/seo/runtime) | Required top-level structure of v1 mapping.yaml. | dropped intentionally | Per Phase 2 / D-01: v2 mapping.yaml is a flat `proposals:` list. No top-level `meta`/`runtime`/etc. The v1 rule is meaningless under v2's shape. |
| Rule 2 — `runtime.neverProduction === true` cross-ref | v1 demanded an explicit safety opt-in stored in mapping.yaml. | dropped intentionally | Per Phase 1 / D-20: v2 enforces NeverProduction via `NeverProductionTrait` gate-first idiom in every controller action. Not a mapping.yaml concern. |
| Rule 3 — `runtime.sourceIdField === 'kunstmaanSourceId'` cross-ref | v1 required mapping.yaml to declare the state-tracking field handle. | dropped intentionally | Per Phase 1 / D-09: v2 hard-codes `kunstmaanSourceId` in `Install.php` with the UID-reuse chain. Not a mapping.yaml concern. |
| Rule 4 — nodeClasses SKIP/required-shape validation | v1 nodeClasses block declared per-Page-FQCN actions (SKIP / required handler list / etc.). | dropped intentionally | Per D-34: v2 has no `nodeClasses` block. Page-level rows live in `proposals[]` as `kind: pagePart` (parent page identity captured by `parentPageClass` field). |
| Rule 5 — pageParts SKIP/required-shape validation | v1 pageParts block declared per-PagePart-FQCN actions + target block handle. | ported (reshaped) | Patched into v2 via Plan 06 D-34 (`kind: pagePart` row schema) + Plan 08 D-36 (`BlockAvailabilityValidator` finding kind in MappingAuditor at `src/mapping/MappingAuditor.php:166-185`). v1's SKIP action becomes v2's `status: dropped`. |
| Rule 6 — dataProviders SKIP/required-shape validation | v1 declared content-source providers (CSV / API / etc.). | dropped intentionally | Out of scope for Phase 02.1; not part of the v2 v1.0 surface. May surface in Phase 3 if non-DB content sources land. |
| Rule 7 — `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:56-66` `CANONICAL_HANDLERS` cross-ref (9 entries) | v1 validates every fieldSpec.handler is one of: asset/ckeditor/dropdown/link/matrix/plain/relation/seomatic/splitName. | ported + intentionally expanded at `src/mapping/MappingAuditor.php:42-46` (12 entries). | v2 adds `date`, `email`, `url` (3 new entries) as part of paired `HANDLER_FIELD_HINTS` for handler-classification-mismatch (Phase 2 / Plan 05 D-16). The expansion is intentional v2 vocabulary growth, not an accidental drop or accidental add. v2 also ports HANDLER_ALIASES (lines 49-52) preserving v1's `plainText → plain` rule plus a `PlainText` capitalization variant. |
| Rule 8 — nodeClasses[*].section cross-refs sections[] | v1 cross-references every per-Page section assignment against the sections[] block. | dropped intentionally — see rule 4 (no nodeClasses block in v2). | n/a |
| Rule 9 — sites shape (locale/handle non-empty per entry) | v1 validated mapping.yaml sites: block. | dropped intentionally | Per Phase 2 / D-17: v2 handles locales via `LocalePreflight` (separate concern, separate gate). Not a mapping.yaml concern. |
| Rule 10 — taxonomies SKIP/required-shape validation | v1 taxonomies block schema. | dropped intentionally | Out of scope for Phase 02.1; Phase 3+ feature. |
| Rule 11 — taxonomies[*].section cross-refs sections[] | Same scope as Rule 10. | dropped intentionally | n/a |
| Rule 12 — `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:460-578` `validateRelationTables` — every joinTable / joinTranslation.table identifier cross-refs against information_schema.tables on the legacy DB | v1 catches typos in relation-table identifiers at preflight time. | dropped intentionally — covered by Phase 02.1 / D-32 drift detection | `KunstmaanSourceScanner::detectDrift` (per Plan 05 SUMMARY) emits `dbHasButScanMissing` and `scanHasButDbMissing` lists; MappingAuditor `renderMarkdown` writes the Drift section at `src/mapping/MappingAuditor.php:255-271`. The cross-ref direction is different (v2 is bidirectional source↔DB, v1 was mapping↔DB) but the operator-facing outcome — surfacing identifier typos — is equivalent or stronger. v2 mapping.yaml has no `joins:` block, so the v1-shape cross-ref is unrepresentable anyway. Documented disposition closes PATTERNS section 13's "possibly accidentally dropped" hedge. |
| Rule 13 — `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:580-617` fieldSpec drop-shape (`drop: true` + non-empty `source` + `rationale` ≥ 10 chars + additionalProperties:false) | v1 enforces a structured shape on dropped-column entries — the rationale length is the mechanism that prevents lazy "TODO" / "n/a" drops from sneaking past review. | **partially dropped accidentally** → patched in Task 2 of this plan (rationale-length check). | v2's per-row `status: dropped` model already covers the `drop: true` boolean (status=dropped is exact). The rationale-length minimum (≥ 10 chars) was NOT carried forward. Patch: extend `MappingAuditor::audit()` to flag any `status: dropped` row whose `rationale` is < 10 chars as a new finding kind `drop-rationale-missing`. Placement-critical: must run BEFORE the existing `if ($status === 'dropped') continue;` early-skip at `src/mapping/MappingAuditor.php:98`. |

**Walk of remaining v1 lines** (lines 50–647 for any rule the 13-rule checklist missed):

- `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:79` `handlerRegistry` runtime cross-check — v1 optionally cross-checks against the live FieldHandlerRegistry. **dropped intentionally** — v2 has no FieldHandlerRegistry runtime injection at the auditor seam (Phase 3 will register handlers; Phase 02.1 stays pure-static).
- `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:91` `legacyDb` injection — v1 optionally cross-checks relation tables against information_schema. **dropped intentionally** — see Rule 12 above (drift detection covers).
- `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:541` `SELECT 1 AS ok FROM information_schema.tables WHERE ... AND table_name = :t LIMIT 1` — bound-parameter SQL safety. **dropped intentionally** — v2 auditor builds no SQL.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php:639-641` Per-fieldSpec handler-cross-ref-against-canonical-list error message wording. **ported** — v2's `handler-classification-mismatch` finding kind at `src/mapping/MappingAuditor.php:152-162` plays the equivalent role with substring-hint matching against `HANDLER_FIELD_HINTS` (more conservative than v1's exact-list rejection: false positives are minimized at the cost of false negatives — D-16 deliberate trade-off).

---

## Summary

### Counts

| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| CoverageAuditor (v1 463 LOC vs v2 217 LOC post-Plan-08) | 2 | 14 | 1 |
| MappingValidator → MappingAuditor (v1 647 LOC vs v2 275 LOC post-Plan-08) | 2 (rule 5 reshaped, rule 7 expanded) | 9 | 1 (rule 13 partial — rationale-length) |
| **Totals** | **4** | **23** | **2** |

### Accidentally-dropped rules — Task 2 patch list

1. **CoverageAuditor empty-table guard** (PATTERNS section 14 #5): insert `if ($rowCount === 0) { continue; }` at `src/mapping/CoverageAuditor.php` immediately after the `$rowCount` read on line 67, before the inner per-column foreach. Comment: `// Patched in Phase 02.1 / Plan 09 from v1 CoverageAuditor.php:82-85 per RECONCILIATION.md.`

2. **MappingAuditor drop-rationale length** (PATTERNS section 13 rule 13): extend `MappingAuditor::audit()` to flag `status: dropped` rows whose `rationale` is < 10 chars as new finding kind `drop-rationale-missing`. Must run BEFORE the existing `if ($status === 'dropped') continue;` early-skip on line 98. Comment: `// Patched in Phase 02.1 / Plan 09 from v1 MappingValidator.php:580-617 per RECONCILIATION.md.`

### Non-patches (documented for verifier traceability)

- **CANONICAL_HANDLERS reconcile** (PATTERNS section 13 rule 7): v2 has 12 entries vs v1's 9. The 3-entry delta (`date`, `email`, `url`) is intentional v2 vocabulary growth paired with `HANDLER_FIELD_HINTS`. No patch needed.
- **CoverageAuditor reachableTables scoping** (PATTERNS section 14 #1): closed as `dropped intentionally` (not "possibly accidentally dropped" as PATTERNS hedged). v2 substitutes STRUCTURAL_IGNORE + per-fillRate gating; equivalent gate behavior via different architecture. No patch.
- **MappingValidator rule 12 (relation-table info_schema cross-ref)**: closed as `dropped intentionally` (not "possibly accidentally dropped"). Covered by Phase 02.1 / D-32 drift detection (`KunstmaanSourceScanner::detectDrift` + MappingAuditor Drift section). Operator-facing outcome equivalent or stronger. No patch.

### LOC delta projection

- v2 fresh-write: 102 (CoverageAuditor) + 172 (MappingAuditor) = **274 LOC**.
- v2 after Plan 08 (D-36 + D-32 page-part coverage + block-availability + drift section): 217 + 275 = **492 LOC**.
- v2 after Plan 09 patches (this plan, +empty-table guard, +drop-rationale-length, +traceability comments): **~510 LOC** projected.
- v1 originals: 463 + 647 = **1110 LOC**.
- Final delta: ~600 LOC (mostly v1's audit-time DB-introspection helpers + nested-mapping-shape walkers, both irrelevant under v2's D-15 + D-01 architecture).

The narrowing from 274 → ~510 LOC tracks the load-bearing parity work (page-part coverage + block-availability + drift detection + rationale-length). Remaining 600 LOC delta is justified architectural deletion, not regression risk.

### Threat-model traceability

D-37 / D-38 / D-39 satisfied: every distinct v1 rule classified, every accidental-drop has a Task 2 patch reference, every "possibly accidentally dropped" PATTERNS hedge resolved with explicit rationale. T-02.1-09-02 (Repudiation: RECONCILIATION.md as audit trail) mitigated by committing this artifact and citing it from each Task 2 patch comment.
