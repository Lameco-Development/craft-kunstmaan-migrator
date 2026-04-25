# Phase 2: Schema, Mapping & Filters - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in 02-CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-25
**Phase:** 02-schema-mapping-filters
**Areas discussed:** mapping.yaml shape & status semantics; `map` rubber-stamp UX; MigrationFilters scope & semantics; coverage gate, mapping-audit & locale preflight

---

## mapping.yaml shape & status semantics

### Q1: Top-level structure of mapping.yaml — how should rows be organized?

| Option | Description | Selected |
|---|---|---|
| Flat `proposals:` list | Single top-level array, identifying tuple `(table, column, targetEntryType)`. Matches v1 `MappingDraftWriter` byte-for-byte. Easiest PR diffs. | ✓ |
| Grouped by entryType | Top-level `entryTypes: { ... }`. Operator-friendly visual grouping. Harder atomic per-row writes. | |
| Two-level: meta + proposals | `meta: { generatedAt, sourceDb, schemaDumpHash }` + flat `proposals: [...]`. Adds provenance metadata. | |

**User's choice:** Flat `proposals:` list.
**Notes:** Operator preferred flat, deferred to recommendation if anything stronger surfaced.

### Q2: Confidence-tier → status mapping

| Option | Description | Selected |
|---|---|---|
| Heuristic-high → accepted; LLM-high → proposed; LLM-medium/low → needs-review; fillRate-0 → dropped | Heuristics get more trust (deterministic). LLM always requires rubber-stamp. `--auto-accept-high` promotes LLM-high. | ✓ |
| All → proposed; only fillRate-0 → dropped | Maximally conservative. Higher first-run operator burden. | |
| Two-tier no needs-review | Heuristic-high → accepted; everything else → proposed. Simpler state machine; loses LLM-uncertainty signal. | |

**User's choice:** Recommended four-tier mapping.

### Q3: How are dropped rows recorded?

| Option | Description | Selected |
|---|---|---|
| `status: dropped` + reason in `rationale` | Reuse existing field. Self-documenting. Rationale becomes overloaded. | ✓ |
| Separate `dropReason` field | Cleaner mental model; one extra field on every row. | |
| `dropSource` enum | Machine-readable; loses free-text nuance. Probably over-fit. | |

**User's choice:** Reason in rationale.

### Q4: Re-run merge semantics

| Option | Description | Selected |
|---|---|---|
| Skip-existing | Existing rows preserved verbatim; only new tuples appended as `proposed`. Operator decisions sacred (CLAUDE.md MAP-04). | ✓ |
| Surface conflicts via CONFLICTS.md sidecar | Diff fresh-proposals vs existing; emit sidecar when drift detected. | |
| `--force-reanalyze` flag | Regenerate non-`accepted` rows from scratch. | |

**User's choice:** Skip-existing.

---

## `map` rubber-stamp UX

### Q1: Row presentation

| Option | Description | Selected |
|---|---|---|
| Compact one-screen block | Progress counter + source identity + proposed target/handler/confidence + rationale + 3 truncated samples + [a/d/r/s/q] prompt. 80×24 fit. | ✓ |
| Two-line summary, expand on demand | Lower screen real estate; higher cognitive load. | |
| Multi-pane ANSI layout | Terminal-width sensitive; fragile. | |

**User's choice:** Compact one-screen block.

### Q2: `[r]emap` picker UX

| Option | Description | Selected |
|---|---|---|
| Two-step: handler enum, then filtered Craft field list | First handler picker, then numbered list filtered by classification. `[t]ype manually` fallback. | ✓ |
| Free-text handle, validated on submit | Faster for power-users; encourages handle memorization. | |
| Single-list flat picker (handler:handle pairs) | One keystroke per remap; long lists on entry types with many fields. | |

**User's choice:** Two-step picker.

### Q3: Persistence

| Option | Description | Selected |
|---|---|---|
| Atomic per-decision write after every keypress | tmp+rename per [a]/[d]/[r] press. Aligns with CLAUDE.md atomic-always-on. | ✓ |
| Batch write on [q]uit only | Catastrophic if process crashes mid-session. | |
| Periodic checkpoint + final write | Tunable nobody will tune. | |

**User's choice:** Atomic per-decision.

### Q4: Resume semantics

| Option | Description | Selected |
|---|---|---|
| Stateless: re-read mapping.yaml each launch | mapping.yaml IS the state. [s]kip resurfaces next session. | ✓ |
| Session state file with skip tracking | More machinery for marginal benefit. | |
| Persistent skip-list across sessions | Wrong incentive — silently masks needs-review rows. | |

**User's choice:** Stateless.

---

## MigrationFilters scope & semantics

### Q1: `--entities` allow-list filters on what?

| Option | Description | Selected |
|---|---|---|
| Kunstmaan source classes | Operator thinks in legacy terms. Granular when multiple sources collapse to one Craft type. | ✓ |
| Craft target entry types | Cleaner Craft integration; obscures legacy reality. | |
| Either form, auto-resolved | More flexible; ambiguous when names overlap. | |

**User's choice:** Kunstmaan source classes.

### Q2: Which legacy column drives `--since`?

| Option | Description | Selected |
|---|---|---|
| `kuma_node.updated` (recommended) | Single column, predictable. | |
| `kuma_node_translations.updated` (per-translation) | Per-locale precision; complicates page-driven model. | |
| Configurable per entity in mapping.yaml meta | Maximum flexibility; overkill for v1. | |

**User's choice (free text):** "I thought about this only working for Entities that extend the AbstractArticlePage since those always have a post date or something."

**Notes:** Operator domain insight — Kunstmaan article-style pages (news/blog/events) extend `AbstractArticlePage` and have a `date` property; other page types don't have a meaningful publish date. This reshaped the filter from "row-level timestamp on every source" to "selective by source class via column-presence detection." Three follow-up questions locked the implementation (see below).

### Q3: `--max-per-entity=N` cap

| Option | Description | Selected |
|---|---|---|
| Per Kunstmaan source class (recommended) | Matches entity allow-list semantics. | |
| Per Craft target entry type | Confusing when multiple sources map to one target. | |
| Per source class with deterministic ordering | Same as recommended, with stable ORDER BY. | |

**User's choice (free text):** "Dont think we need this."

**Notes:** Scope shrinkage — operator instructed to drop `--max-per-entity` from v1.0. FILT-01 and ROADMAP Phase 2 success criterion 5 require patching when this phase ships. `MigrationFilters` is now a three-property value object.

### Q4: How do CLI flags combine with Settings::default*?

| Option | Description | Selected |
|---|---|---|
| Per-filter override; unspecified flags fall through | Pin team-wide defaults in config; override per-invocation. | ✓ |
| Any flag wipes all defaults | Simpler mental model; higher friction. | |
| Settings always wins, CLI only adds | Conservative; wrong shape for a dev tool. | |

**User's choice:** Per-filter override.

---

### Follow-up Q1: How does the filter detect 'this source has a meaningful publish date'?

| Option | Description | Selected |
|---|---|---|
| Column-presence: source has `date` column → apply | No PHP class introspection (we only have SQL dump). | ✓ |
| Per-mapping opt-in via `meta.sinceColumns` | More configurable; needs operator to maintain it. | |
| Both: column-presence default + per-mapping override | Best of both; premature for v1.0. | |

**User's choice:** Column-presence.

### Follow-up Q2: Drop `--max-per-entity` from v1.0 scope?

| Option | Description | Selected |
|---|---|---|
| Drop it (patches FILT-01 + ROADMAP) | --entities + --since cover smoke-test scoping. | ✓ |
| Keep in spec, defer wiring to Phase 3 | Stub field on value object. | |
| Keep on value object, no CLI flag | Settings-only access via config file. | |

**User's choice:** Drop.

### Follow-up Q3: Which column name(s) trigger `--since`?

| Option | Description | Selected |
|---|---|---|
| Just `date` (AbstractArticlePage convention) | Predictable; non-standard sites need the deferred per-mapping override. | ✓ |
| `date`, `publishDate`, `published_at`, `datum` | Broader match; ambiguity when row has multiple. | |
| `date` only + WARN when --since set on dateless source | Avoids silent no-op pitfall. | |

**User's choice:** Just `date`.

---

## Coverage gate, mapping-audit & locale preflight

### Q1: What counts as a 'data-bearing legacy column' for MAP-06?

| Option | Description | Selected |
|---|---|---|
| Schema-dump columns with fillRate>0, minus structural ignore list | File-based universe; predictable; ignore list reviewable in PR. | ✓ |
| Every column the heuristic-and-LLM proposed for | Coverage = mapping.yaml has final status everywhere. Risk: silent gap if analyze missed a column. | |
| Schema-dump columns minus operator-declared `coverageIgnore` block | More flexibility; redundant for v1.0. | |

**User's choice (free text):** "Not sure what this means. going with your recommendation."

**Notes:** Operator deferred to recommendation. Term "data-bearing" was unclear to them — captured in CONTEXT.md as the schema-dump-minus-structural-minus-zero-fill definition with a structural ignore list constant.

### Q2: How does the gate behave for `--live` vs `--dry-run`?

| Option | Description | Selected |
|---|---|---|
| Same check, different exit: --live fails hard, --dry-run warns | One coverage service, two entry-points. ROADMAP success criterion 4 alignment. | ✓ |
| Two checks: --live blocks on proposed/needs-review, --dry-run skips entirely | Misleading dry-run output. | |
| Single check + --skip-coverage escape hatch | Escape hatches get used by default. | |

**User's choice (free text):** "Going with your recommendation"

### Q3: MAP-07 mapping-audit drift findings

| Option | Description | Selected |
|---|---|---|
| Console WARN + MAPPING-AUDIT.md, default warn-only, --audit-strict opt-in | Operator-visible; doesn't block analyze; --live runs strict. | ✓ |
| Findings folded into REPORT.md (no separate file) | REPORT.md becomes multi-purpose. | |
| Hard-fail on first finding | Wrong shape mid-iteration. | |

**User's choice:** Console WARN + MAPPING-AUDIT.md.

### Q4: LOC-01 detection + LOC-02 preflight

| Option | Description | Selected |
|---|---|---|
| Auto-detect in analyze + paste-ready sites: in REPORT.md + preflight every legacy-reading command | No silent default-locale fallthrough. PROJECT.md hard rule. | ✓ |
| Detection in analyze; preflight only on --live | Operator surprise at --live time. | |
| Standalone analyze/preflight + per-command implicit check | Premature optimization. | |

**User's choice (free text):** "Going with your recommendation"

---

## Claude's Discretion

Areas where the operator deferred to Claude or the recommendation:
- The structural-ignore-list contents for the coverage gate (D-14)
- `schema-dump.json` exact format
- `REPORT.md` content section list
- CLI controller / service directory layout under `src/`
- Anthropic timeout / batch / sleep defaults (keep v1's unless reason to change)
- Heuristic ordering (port v1's order verbatim)
- `--no-ai` flag behavior (carry from v1)

## Deferred Ideas

- `--force-reanalyze` flag for analyze re-runs
- `CONFLICTS.md` sidecar for fresh-vs-existing proposal drift
- Per-mapping `meta.sinceColumns` override
- `--max-per-entity=N` filter cap (dropped from v1.0; roadmap as NEXT-*)
- Multiple `--since` column candidates (broaden beyond `date`)
- `--audit-strict` as analyze default
- LLM provider abstraction (PROJECT.md NEXT-03)
- `schema-dump.json` `formatVersion` field
- `--no-ai` short-form flag
