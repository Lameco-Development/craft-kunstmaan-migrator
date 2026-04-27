# Phase 8: Taxonomies & AI Proposer Coverage - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-27
**Phase:** 08-taxonomies-and-proposers
**Areas discussed:** Taxonomy load target, Detection signal for taxonomies, Translatable fields wiring, Layout/dataProvider proposer scope

---

## Taxonomy load target

### Q1: What does a Kunstmaan taxonomy entity become in Craft?

| Option | Description | Selected |
|--------|-------------|----------|
| Craft Categories (group + tree) | Each FQCN → Craft category group; rows become categories. Closer semantic fit; fewer fields available. | |
| Craft Sections / Entry Types (v1's choice) | Each FQCN → section + entry type, mirroring nodeClasses. Full Entry feature set. | ✓ |
| Operator-decided per-row in mapping.yaml | targetCraftKind: section \| category. Max flexibility; doubles load code paths. | |

**User's choice:** Craft Sections / Entry Types
**Notes:** "I think 2 since in Craft 5+ everything is an entry really."

### Q2: Section-handle resolution

| Option | Description | Selected |
|--------|-------------|----------|
| Same rule as nodeClasses (LLM proposes) | Reuse Phase 6 entity-level proposer + closed-set validation. Operator override via mapping.yaml. | ✓ |
| Heuristic-only (basename → lowercase plural) | NewsCategory → newsCategories. No LLM. Breaks on non-default Craft handles. | |
| Operator-required (status: needs-review until filled) | Analyze emits empty handles; operator must fill via map loop. | |

**User's choice:** Same rule as nodeClasses (LLM proposes targetSection + targetEntryType)

### Q3: Run order for relation resolution

| Option | Description | Selected |
|--------|-------------|----------|
| Taxonomies BEFORE pages, regular RelationHandler | Topological. Matches v1's wave ordering. | ✓ |
| Taxonomies AFTER pages, finalize-pass deferred | Like v1's SEO-runs-LAST. Adds deferred-state surface. | |
| Mixed run order (whichever first) | Atomic per-entry retry. Higher complexity. | |

**User's choice:** Run taxonomies BEFORE pages, regular relation handler does the rest

### Q4: Filter integration

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-include taxonomies referenced by allowed entities | Reachability analysis in MigrationFilters. Sane scoping for free. | ✓ |
| Independent --taxonomies= flag | Separate scoping. Would be a 4th flag (Phase 2 / D-12 cap is 3). | |
| Taxonomies always migrate (no filter) | Smallest scope; tables are tiny anyway. | |

**User's choice:** Auto-include taxonomies referenced by allowed entities

---

## Detection signal for taxonomies

### Q1: How does analyze decide a Doctrine entity is a taxonomy candidate?

| Option | Description | Selected |
|--------|-------------|----------|
| Heuristic: no node FK + has name/slug + has ext_translations row | Pure deterministic. Cheap, no LLM call. Misses non-standard column names. | |
| LLM-proposed (extend pagePart proposer) | Catches edge cases. Adds tokens + latency at analyze. | ✓ |
| Heuristic + LLM (heuristic emits proposed, LLM upgrades) | Best precision/recall. Most code paths. | |

**User's choice:** LLM-proposed (extend pagePart proposer with taxonomy class)

### Q2: Output format for the new proposer

| Option | Description | Selected |
|--------|-------------|----------|
| Three-class output: taxonomy / supporting / skip | Most descriptive. | |
| Binary output: taxonomy or not | Simpler prompt. Silent data-loss risk. | |
| Confidence-tiered output (high/medium/low/dropped) | Reuses Phase 2 / D-02 status ladder. Unified surface. | ✓ |

**User's choice:** "Going with your recommendation."
**Claude's recommendation:** Confidence-tiered output. Reuses existing v2 pattern; entities that are neither pages nor taxonomies emit as `dropped` with reason="not-taxonomy-likely-supporting".

### Q3: New mapping row kind

| Option | Description | Selected |
|--------|-------------|----------|
| kind=taxonomy with one row per FQCN | Mirrors kind=nodeClass. Field-mapping inferred from kind=column rows. | ✓ |
| kind=taxonomy with nested fields[] | Mirrors kind=pagePart. Self-contained but third row schema. | |
| Reuse kind=nodeClass + taxonomy:true flag | Smallest schema change. Conflates concepts. | |

**User's choice:** "Make sure it's consistent with the rest."
**Claude's interpretation:** Option 1 (kind=taxonomy, mirrors kind=nodeClass exactly — no nested fields[]). Identity tuple = (kind, fqcn). Field-level mapping inferred from matching kind=column rows on the same source table.

---

## Translatable fields wiring

### Q1: How does v2 handle Gedmo ext_translations?

| Option | Description | Selected |
|--------|-------------|----------|
| Verbatim port of v1 (legacyDb->extTranslationsFor + per-locale entry copy) | Restore the dropped helper method. Phase 4 / D-54 verbatim-port discipline. | ✓ |
| Reshape to use existing translationsFor() | Won't work — taxonomies aren't in kuma_nodes. NOT recommended. | |
| v1.0 source-locale only; defer to v1.1 | Smallest scope. Regression vs v1 baseline. | |

**User's choice:** Verbatim port of v1

### Q2: Behavior when ext_translations is missing/empty

| Option | Description | Selected |
|--------|-------------|----------|
| Treat source-locale row as only locale; copy across all sites | Pragmatic for monolingual installs. Doctor WARNs. | ✓ |
| Treat source-locale as locale-specific; only matching site | Defensive; risks broken FK relations on non-source sites. | |
| Doctor FAILs when ext_translations missing AND mapping.sites > 1 | Strictest. Surprising on monolingual sites. | |

**User's choice:** Treat source-locale row as the only locale; copy across all configured Craft sites

### Q3: Default translatable-field detection

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-detect from ext_translations rows | Source-truth driven. Depends on table being populated. | |
| Fixed list: name, slug, title, label, description | Hardcoded. Works without ext_translations. False positives. | |
| Operator-required (taxonomies[fqcn].translatableFields[]) | Most explicit. Biggest operator burden. | |

**User's choice (free-text):** "Detect either via ext_translations or from the Entity attribute in Kunstmaan, whatever makes sense."
**Claude's interpretation:** Union of (a) #[Gedmo\Translatable] source attributes (extend DoctrineEntityParser to scan the Gedmo namespace alongside Doctrine\ORM\Mapping) and (b) actual ext_translations rows for any entity instance. Operator override always wins.

---

## Layout/dataProvider proposer scope

### Q1: How aggressive should the new proposers be?

| Option | Description | Selected |
|--------|-------------|----------|
| Always on, propose for every nodeClass with a Matrix field | Maximum coverage. Bigger token bill. | |
| Always on with heuristic-trigger gating | Same pattern Phase 7 uses for implicit-content. | ✓ |
| Off by default; --propose-layout / --propose-providers opt-in | Smallest default surface. Operator-burden until flag discovery. | |

**User's choice:** "Not sure, going with your recommendation here."
**Claude's recommendation:** Heuristic-trigger gating, on by default. Matches Phase 7's implicit-content pattern; layout/dataProvider gaps are convenience-class not correctness-class so conservative invocation > maximum coverage.

### Q2: Layout proposer trigger

| Option | Description | Selected |
|--------|-------------|----------|
| Hero-shape detection (image-FK + long-text columns + header block in catalog) | Both sides agree. Misses unconventional column naming. | |
| Any nodeClass with a pageBuilder Matrix gets all three proposers | Simple. More tokens; broader coverage. | |
| Matrix catalog signal only (header-shaped block in catalog) | Source-side agnostic. Resilient to project-specific column names. | ✓ |

**User's choice:** "Going with your recommendation here."
**Claude's recommendation:** Matrix catalog signal only. Phase 6's closed-set validation pattern (Craft side as canonical truth for what slots exist). Resilient to project-specific column naming.

### Q3: Operator escape hatch

| Option | Description | Selected |
|--------|-------------|----------|
| Existing --no-ai flag covers everything | Simplest. No granular opt-out. | |
| --no-ai blanket + granular --skip-proposers=layout,providers | One new CLI surface. Comma-list parsing. | |
| Settings::proposeLayout/proposeProviders booleans + --no-layout/--no-providers | Mirrors Phase 4.1 ADP-04 seoEnabled/retourEnabled exactly. | ✓ |

**User's choice:** "Not sure. Going with your recommendation."
**Claude's recommendation:** Option 3. Mirrors the existing Phase 4.1 ADP-04 pattern exactly; permanent project-level decision via CP Settings; per-run override via CLI; cost is small.

---

## Claude's Discretion

The user explicitly deferred to Claude's recommendation on five sub-questions: (1) confidence-tier output for the taxonomy proposer, (2) translatable-field detection blending source attributes + runtime signal, (3) heuristic-trigger gating for the new proposers, (4) Matrix catalog signal as the layout-proposer trigger, (5) Settings + CLI mirror pattern for the operator escape hatch.

In all five cases, the recommendation defaulted to "match the closest existing v2 pattern" rather than "introduce something new." Downstream agents should re-litigate any of these via this CONTEXT.md if research surfaces a stronger signal.

## Deferred Ideas

- Asset folder hierarchy preservation (kuma_media_folder parent_id chain) — listed in CHANGELOG known-omissions; potential v1.1 phase.
- Asset metadata (alt text, copyright, focal point) — listed but deferred.
- Slug history mining beyond kuma_redirects — deferred.
- Page drafts / non-public versions — explicitly out of scope (carryover from v1).
- Form / Search / Menu / User migration — never been in v1 OR v2 scope.
- relation:deferred finalize-pass — Phase 4.1 / REC-02 left unimplemented; Phase 8 / D-03 confirms taxonomies don't need it.
- CHANGELOG-section authorship details — deferred to plan-phase per Specific Ideas in CONTEXT.md.
