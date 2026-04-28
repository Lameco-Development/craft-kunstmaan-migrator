# Phase 11: Dual Schema Walkers & LLM-first Mapping - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md - this log preserves the alternatives considered.

**Date:** 2026-04-28
**Phase:** 11-relation-target-introspection-promotion
**Areas discussed:** original goal alignment, Kunstmaan walker, Craft walker, graph normalization, relation targets, pageparts, content-only exceptions, proof scope

---

## Original Goal Alignment

| Option | Description | Selected |
|--------|-------------|----------|
| Keep growing heuristic mapping | Add more special cases for each discovered source/target mismatch. | |
| Dual graph dumps plus LLM mapping | Dump full Kunstmaan page-rooted schema and full Craft entry-rooted schema, then let the LLM map between them with deterministic validation. | Yes |

**User's choice:** The plugin's original goal is "dump full Kunstmaan page schema + dump full Craft entry schema + LLM cleverness = mapping."
**Notes:** This became the anchor for Phase 11. Heuristics should not become the scalable mapping engine.

---

## Kunstmaan Walker

| Option | Description | Selected |
|--------|-------------|----------|
| Keep separate scanner artifacts | Continue with `pageStructure.json`, `relation-graph.json`, and schema dumps as separate partial views. | |
| Add a page-rooted Kunstmaan walker | Start from `Entity\Pages`, walk direct properties, assets, relations, pageparts, and pagepart relations. | Yes |

**User's choice:** Add a real Kunstmaan walker.
**Notes:** `NewsPage` is the immediate proof because it has a relation to `App\Entity\Employee` and exposes the difference between a raw FK, a related entity, and copied helper data.

---

## Craft Walker

| Option | Description | Selected |
|--------|-------------|----------|
| Keep Craft schema mostly in-memory | Continue using `CraftKnowledgeBase` markdown/index data as prompt context. | |
| Add a Craft entry-rooted walker | Start from candidate Craft entry types and walk fields, Matrix blocks, nested fields, relation targets, assets, and constraints. | Yes |

**User's choice:** Add the Craft-side equivalent walker.
**Notes:** The user explicitly wanted both sides consistent so analyze/mapping can use dumped structures rather than asymmetric source files plus target in-memory knowledge.

---

## Graph Shape

| Option | Description | Selected |
|--------|-------------|----------|
| Normalized registries with references | Shared entities/pageparts/fields are declared once and referenced from roots/usages. | Yes |
| Fully nested per page/entry | Every page/entry output repeats the complete nested structures it touches. | |
| Hybrid prompt view only | Keep normalized internals but emit nested prompt-specific summaries. | |

**User's choice:** Normalized registries with references.
**Notes:** This avoids redeclaring pageparts across every page and lets shared relation targets like `Employee` have one canonical identity.

---

## Recursion Boundary

| Option | Description | Selected |
|--------|-------------|----------|
| Recursive reachable graph with cycle protection and configurable max depth | Walk reachable structures from roots, prevent cycles, and expose a safety cap. | Yes |
| One relation/pagepart level only | Keep Phase 11 shallow. | |
| Unlimited recursion with cycle protection | Let the graph walk everything reachable without a depth cap. | |

**User's choice:** Recursive reachable graph with cycle protection and configurable max depth.
**Notes:** Taxonomies/classifier-like entities and pagepart relations need recursion; the max depth is a safety valve, not the main model.

---

## LLM Role

| Option | Description | Selected |
|--------|-------------|----------|
| LLM proposes graph-to-graph mappings; deterministic code validates/compiles | LLM handles ambiguous mapping decisions, code enforces safety. | Yes |
| Heuristics propose first; LLM fills residuals | Keep current heuristic-led flow as primary. | |
| LLM proposes and may auto-accept high-confidence mappings | Let high-confidence AI output bypass review/validation. | |

**User's choice:** LLM proposes graph-to-graph mappings; deterministic code validates/compiles.
**Notes:** The LLM should see as much structured data as possible, but compile/load safety stays deterministic.

---

## Relation Targets

| Option | Description | Selected |
|--------|-------------|----------|
| Treat non-page targets as taxonomy by default | Route related entities through taxonomy logic first. | |
| Classify target intent from graph evidence | Let targets be reference/promote/embed/drop/out_of_scope based on source and Craft evidence. | Yes |

**User's choice:** Classify target intent from graph evidence.
**Notes:** `Employee` is not `EmployeePage` and not necessarily a taxonomy. It is a rich related entity that can be shared by `NewsPage` and `EmployeePage`.

---

## Content-only Pages

| Option | Description | Selected |
|--------|-------------|----------|
| Expose in graph; choose mapping via explicit policy/config | Graph shows the source field and Craft possibilities; policy decides block/field/drop/override. | Yes |
| Keep current implicit-content heuristic as generic default | Automatically synthesize pagebuilder rows when a page has content-like columns and no pageparts. | |
| Out of Phase 11 entirely | Do not represent these pages in the graph. | |

**User's choice:** Expose content-only pages in the graph and handle mapping through policy/config.
**Notes:** The user gave the example of content WYSIWYG fields that may map to a PageBuilder block or to a flat `ckeditorSimple` field depending on the target entry type. That choice is project-specific and should not be hidden in generic logic.

---

## Proof Scope

| Option | Description | Selected |
|--------|-------------|----------|
| Prove only NewsPage | Relation-heavy, easier to validate, but misses pagepart/Matrix complexity. | |
| Prove NewsPage and HomePage | Covers both relation-heavy and pagepart/Matrix-heavy behavior. | Yes |
| Broaden immediately to all pages | More coverage but too much surface before the architecture is proven. | |

**User's choice:** Focus on `NewsPage` and `HomePage`.
**Notes:** `NewsPage` covers direct fields, media FKs, Employee relation, taxonomy/classifier-style relations, and shared target resolution. `HomePage` covers real pageparts, nested Matrix blocks, assets, ordering, and Matrix ownership.

---

## the agent's Discretion

- Planner may split implementation into multiple plans if needed.
- Exact graph DTO/value-object names are left to planning, with the naming rule that Kunstmaan-side classes/files include `Kunstmaan` and Craft-side classes/files include `Craft`.

## Deferred Ideas

- Project-specific mapping policy for content-only WYSIWYG pages should be supported, but the exact policy UI/config shape can be planned separately if it grows beyond the Phase 11 proof.
- Broad proof across additional page types should wait until `NewsPage` and `HomePage` work end-to-end.
