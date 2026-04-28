# Phase 10: Generic Migration Rehearsal Gap Closure - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `10-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-04-28
**Phase:** 10 - Generic Migration Rehearsal Gap Closure
**Areas discussed:** Relation timing, Fallback content semantics, Verify/rehearsal success bar, Failure handling strictness

---

## Relation timing

| Option | Description | Selected |
|--------|-------------|----------|
| Move taxonomies before transform now | Ensure taxonomy state rows exist before relation handlers resolve FK values. | |
| Introduce deferred relation tokens now | Emit unresolved relation tokens during transform and resolve during load/finalize. | |
| Do both now | Combine stage-order fix with token architecture. | |
| Let the agent decide | Planner chooses. | |
| Page-rooted lazy find/create | Find or create taxonomy terms when migrating a page that references them. | yes |

**User's choice:** Prefer page-rooted taxonomy "find or create" during page migration. Default should create/link only taxonomies referenced by migrated pages.

**Follow-up decision:** Add a flag/settings path to include unreferenced taxonomy rows when the operator wants the full vocabulary migrated.

**Notes:** Dry-run behavior and whether to introduce broader relation-token architecture are left to the planner.

---

## Fallback content semantics

| Option | Description | Selected |
|--------|-------------|----------|
| Synthesize Matrix block titles | Generate validation-required native block titles from generic context. | planner discretion |
| Hard-fail missing Matrix title | Require source/mapping to provide a native block title. | |
| Seed sparse-locale primary save | Save Craft primary site from first available locale while preserving enablement truth. | planner discretion |
| Hard-fail missing primary locale | Require primary locale data to exist. | |
| Fallback taxonomy locale values | Missing locale title/fields use default-language value. | yes |
| Report fallback usage | Surface synthesized/fallback values in report/logs. | yes |

**User's choice:** Taxonomy locale values fall back to default-language values. Fallback usage must be reported. Matrix title fallback and sparse-locale entry mechanics are left to planner discretion within generic/source-truth constraints.

---

## Verify/rehearsal success bar

| Option | Description | Selected |
|--------|-------------|----------|
| Zero entry/stage failures required | `migrate --live` is not release-clean if any entry or stage fails. | yes |
| Zero unexplained failures | Accepted omissions may remain if explicitly documented. | |
| Small failure tolerance | Allow a limited number of failed entries if report is explicit. | |
| Full page-owned accounting | Every referenced page-owned surface migrates or is explicitly out-of-scope/dropped. | yes |
| Restore backup and rerun full workflow | Use a clean restored CQM backup as the closing gate. | yes |
| Verify semantics left to planner | Planner may redesign counts as long as they are honest. | yes |

**User's choice:** Strict closing gate: zero entry/stage failures, every referenced page-owned content surface accounted for, restore the pre-live backup and rerun the full workflow. Verify count design is planner discretion, constrained by honest like-for-like comparison.

---

## Failure handling strictness

| Option | Description | Selected |
|--------|-------------|----------|
| Hard-block compile | Invalid section/entry-type mappings fail compile until fixed. | planner discretion |
| Auto-route unambiguous mappings | Route to the section's only allowed entry type when source-preserving and unambiguous. | planner discretion |
| Warn at compile, block migrate | Compile can warn, but live migration preflight fails. | planner discretion |
| Promote load-fatal warnings | Load-fatal warnings become failures before live load. | planner discretion |
| Classify unknown fields by data-loss risk | Planner chooses hard-fail vs visible drop based on whether content has another mapped home. | planner discretion |

**User's choice:** Let the planner decide exact strictness mechanics. Constraint from the discussion: live load-fatal mappings must not reach `migrate --live` as runtime surprises, and silent data loss is unacceptable.

---

## the agent's Discretion

- Matrix block title fallback format.
- Sparse-locale primary save implementation.
- Dry-run handling for lazy taxonomy creation.
- Whether a small relation-token seam is needed.
- Verify count model.
- Compile failure vs migrate preflight failure vs visible drop split.

## Deferred Ideas

- Full generic relation `mode: embed | promote` architecture unless needed for Phase 10.
- Full orphan media import.
- Full unreferenced taxonomy import as default behavior; it should be opt-in.
