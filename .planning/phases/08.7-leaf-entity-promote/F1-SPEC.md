---
phase: 08.7-leaf-entity-promote
artifact: feature-spec
feature: F1 — page-wins auto-folding for ManyToOne 1:1 wrapping pairs
status: queued (designed, not yet implemented)
depends-on: D-39 (auto-detect flatPagePartContent), D-40 (targetHandle validation)
generated: 2026-04-28
---

# F1 — Page-wins auto-folding for ManyToOne 1:1 wrapping pairs

This is the third generalization feature from the 08.7 trio (F2 = D-39, F3 = D-40, F1 = this). F2 + F3 shipped. F1 is queued.

## Problem

When a Kunstmaan `*Page` entity wraps a standalone `Entity` 1:1 via a ManyToOne FK, the page entity often has only structural columns (id, title, page_title, summary, employee_id) while the **content fields live on the wrapped entity**. CQM's pattern:

- `App\Entity\Pages\EmployeePage` (table: `lameco_websitebundle_employee_pages`) has columns: `id, title, page_title, employee_id, date, summary`. The "interesting" columns (real_name, job_title, email, linked_in, image_id, quote, cta_image_id) live on the wrapped `App\Entity\Employee` (table: `lameco_websitebundle_employee_employees`).
- ExtractService's 8.5 D-21 ManyToOne embedding emits these as `_rel:employee.<col>` keys on the extracted page payload. They reach the loader.
- But analyze-stage column proposers only see the page table's columns. So none of the `_rel:employee.<col>` columns get proposals → no field map entries → migrated `teamMember` entries have only the page's structural data, missing the actual person fields.

The fix shipped this session was operator hand-curation — explicitly adding 7 column proposals targeting `_rel:employee.<col>` source paths with the right handlers. Repeats on every project that has a 1:1 wrapping pattern.

## Saved feedback memory governing this design

`feedback_pages_lead.md` — when a Page wraps a 1:1 standalone entity, the Page wins as the canonical Craft entry; the standalone entity contributes via `_rel:` embedding only. The standalone entity itself is set `status: dropped` so it doesn't migrate as a separate entry.

Independent taxonomies (CaseStudyCategory, NewsCategory) referenced 1:N from pages do NOT trigger this — those still migrate as their own entries. The trigger is specifically a 1:1 wrapping ManyToOne.

## Design

**Where it fires:** at analyze stage, after the regular column-residual list is built and BEFORE the LLM proposer runs.

**Detection (1:1 wrapping pair):** for each page FQCN in `pageStructure.json`, walk its Doctrine ManyToOne relations (already parsed by `DoctrineEntityParser`). For each ManyToOne whose target entity is also a project-defined entity (NOT a Kunstmaan vendor class), the pair qualifies. EmployeePage→Employee qualifies; CaseStudyPage→CaseStudyCategory does not because CaseStudyCategory is referenced 1:N (the FK is on the page, but the category is a taxonomy used across many pages, not a 1:1 wrap).

The 1:1 vs 1:N distinction is the tricky bit. Heuristic: a 1:1 wrap exists when the page entity's count is approximately equal to the target entity's count (e.g. CQM: 689 employee_pages × ~12 locales/versions ≈ 57 employees → roughly 1:1 per node). Or simpler: emit synthetic proposals for ALL ManyToOne targets and let the operator review/drop the wrong ones.

**What gets emitted:** for each qualifying ManyToOne, walk the target entity's columns and emit synthetic column proposals into the residual list before the LLM step. Shape:

```yaml
- kind: column
  table: <pageTable>
  column: '_rel:<prop>.<col>'    # e.g. _rel:employee.email
  targetEntryType: <pageEntryType>
  targetHandle: ''                # LLM fills
  handler: ''                     # LLM fills
  status: needs-review
  fillRate: <calculated from target table>
  sqlType: <from target column>
  samples: [...up to 3...]
```

The residual-column LLM then processes them like any other column and produces handler+targetHandle proposals. Compile picks them up and writes them to `nodeClasses[<pageFqcn>].fields[<targetHandle>] = {handler, source: '_rel:<prop>.<col>'}`.

**Drop the wrapped entity's standalone migration.** Per the pages-lead rule, the wrapped entity (e.g. `App\Entity\Employee`) gets `status: dropped, reason: superseded-by-page` automatically when a 1:1 wrap is detected. Operator can override.

## Implementation outline

1. **`AnalyzeController` (or a new helper)** — after `pageStructure.json` is built and BEFORE the LLM column-proposer fires:
   - For each page FQCN, walk its Doctrine ManyToOne relations via `DoctrineEntityParser`.
   - For each qualifying target entity, walk its columns (excluding `id`, FK columns).
   - Build synthetic proposal entries with `column: '_rel:<prop>.<col>'`.
   - Append to the residual-column list that the LLM proposer consumes.
   - Track which targets were folded so the next step can mark them dropped.
2. **Taxonomy proposer** — for each target entity that was folded into a parent page, force `status: dropped, reason: superseded-by-page` (unless the operator already set it to `accepted`).
3. **Compile** — no changes needed; existing column-row → nodeClass.fields[] pipeline handles `_rel:` source paths transparently. (Verified: `_rel:employee.<col>` source paths are correctly written by compile after operator hand-curation.)
4. **Tests** — unit test: feed a synthetic pageStructure with a known ManyToOne pair, assert that synthetic `_rel:` proposals appear in the residual list AND the target entity's taxonomy proposal gets dropped.

## Edge cases to handle

- **Page wraps multiple entities** (ManyToOne to several targets). Each gets folded independently. EmployeePage has only one (Employee), so this is theoretical for CQM.
- **Target entity has its own ManyToOne relations** — do NOT recurse. The fold is one level deep. Operator can hand-curate deeper chains if needed.
- **FK is to a vendor entity** (e.g. Kunstmaan core) — skip. Vendor entities don't typically have content the page would care about.
- **1:N pattern misdetected as 1:1** — operator can drop the wrong synthetic proposals via mapping.yaml (status: dropped).

## Ordering vs other features

- F2 (D-39, shipped) and F1 are independent.
- F3 (D-40, shipped) helps F1 indirectly: the LLM proposes `targetHandle` against an entry-type catalog, and F3 drops invalid picks at compile. So if the LLM hallucinates a target handle for a `_rel:` synthetic, F3 catches it.

## Estimated scope

~80-150 LoC across `AnalyzeController` (residual augmentation step) + `KunstmaanPageStructureScanner` or `DoctrineEntityParser` extension (column gathering for target entities) + a unit test. Smaller than F2 was — no Craft field-layout introspection needed; all source-side.

## Verification path

Once shipped, on CQM:
1. Run `analyze` (with credits) — observe synthetic `_rel:employee.<col>` proposals appearing under the EmployeePage residual list.
2. Run `compile --overwrite` — observe `nodeClasses[App\Entity\Pages\EmployeePage].fields` populated with the `_rel:employee.<col>` mappings (matching what we hand-curated this session).
3. Confirm `App\Entity\Employee` taxonomy has `status: dropped, reason: superseded-by-page`.
4. Re-migrate EmployeePage — confirm teamMember entries have firstName / role / email / linkedin / image / detailPhoto populated automatically with no operator hand-curation in mapping.yaml.

## Out of scope for this feature

- Splitting `_rel:employee.real_name` into firstName + lastName. That's a separate transformer concern (queued elsewhere).
- The analyze-stage `--entities` filter not propagating to SchemaDumper for non-`kuma_*` legacy schemas. Separate side-issue from earlier triage.
