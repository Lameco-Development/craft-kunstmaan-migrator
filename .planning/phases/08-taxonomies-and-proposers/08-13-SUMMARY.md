---
phase: 08-taxonomies-and-proposers
plan: 13
subsystem: ui
tags: [cp, settings-template, twig, lightswitch, ai-proposers, proposeLayout, proposeProviders, d-14, d-62]
requires:
  - 08-08-SUMMARY  # Settings::proposeLayout / proposeProviders booleans (D-14 ladder layer 1)
provides:
  - "_settings.twig 'AI' H2 group between Mapping and Fallback"
  - "lightswitchField bound to settings.proposeLayout (D-14 CP surface)"
  - "lightswitchField bound to settings.proposeProviders (D-14 CP surface)"
  - "Operator-facing instructions copy that names the CLI counterparts (--no-layout, --no-providers)"
affects:
  - .planning/REQUIREMENTS.md (closes PROP-04 — CP surface for the AI proposer scope ladder)
tech-stack:
  added: []
  patterns:
    - "D-62 grouped-section convention (Phase 4) — H2 group per concern; AI sits between Mapping and Fallback"
    - "D-14 ladder layer-1 (Settings persistence) ↔ layer-2 (CLI flag) — operator-facing parity copy in instructions"
    - "Phase 4.1 / D-19 CP fragment-shape preserved (no <form>, no layout-inheritance, no csrf)"
key-files:
  created: []
  modified:
    - src/templates/_settings.twig
key-decisions:
  - "AI H2 group placed between Mapping (line 75) and Fallback (now line 141) per D-14 + PATTERNS section recommendation. seoEnabled / retourEnabled relocation deferred (out of Phase 8 scope per PATTERNS gotcha)"
  - "Used standard Craft `forms.lightswitchField` macro idiom from `_includes/forms` (already imported at template line 17). No new macro import needed"
patterns-established:
  - "AI proposer-gate H2 group pattern: H2 'AI' header + N x lightswitchField, each with operator-facing instructions naming the CLI counterpart. Reusable for any future AI proposer scope additions"
requirements-completed: [PROP-04]
duration: ~1 min
completed: 2026-04-27
---

# Phase 8 Plan 13: `_settings.twig` AI H2 Group Summary

**Surfaced the two D-14 AI proposer gates (`proposeLayout`, `proposeProviders`) as lightswitches in a new "AI" H2 group between the existing Mapping and Fallback groups in the CP Settings template. PROP-04 ladder closure.**

## Performance

- **Duration:** ~1 min
- **Started:** 2026-04-27T15:19:01Z
- **Completed:** 2026-04-27T15:19:53Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments

- `_settings.twig` carries a new `<h2>{{ 'AI'|t('kunstmaan-migrator') }}</h2>` block at line 120, inserted between Mapping (line 75) and Fallback (now line 141).
- Two `forms.lightswitchField` invocations bind to `settings.proposeLayout` and `settings.proposeProviders` (the booleans Plan 08-08 added to `Settings::class`).
- Each lightswitch's `instructions` line names the CLI counterpart (`--no-layout` / `--no-providers`) so an operator hovering in the CP knows the per-run override exists.
- Existing Connectivity / Mapping / Fallback groups untouched — verified by re-grepping the H2 line numbers (19 / 75 / 141 still match the pre-existing field bodies).

## Task Commits

1. **Task 1: Add AI H2 group with two lightswitches** — `358b3c7` (feat)

## Files Created/Modified

- `src/templates/_settings.twig` — Inserted "AI" H2 group + 2 lightswitchFields (lines 120-140). 21 lines added, 0 removed. No relocation of seoEnabled / retourEnabled (out of Phase 8 scope).

## Decisions Made

- **H2 placement.** Plan + PATTERNS both specified between Mapping and Fallback. Followed verbatim — line 75 (Mapping H2) ends at line 118; new AI block runs lines 120-140; Fallback H2 now sits at line 141.
- **Macro idiom.** Used standard Craft `forms.lightswitchField`; `_includes/forms` is already imported at line 17 for the existing `forms.autosuggestField` / `forms.textField` / `forms.passwordField` / `forms.editableTableField` / `forms.selectField` calls. No new import needed.
- **Instructions copy.** Mirrors the plan's `<action>` block verbatim, including the "CLI --no-layout bypasses per-run" / "CLI --no-providers bypasses per-run" phrasing so the four-layer D-14 ladder (Settings ↔ CLI ↔ --no-ai ↔ API key) is discoverable from the CP without leaving the form.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## Acceptance-Gate Verification

```
$ grep -c "<h2>{{ 'AI'|t" src/templates/_settings.twig
1
$ grep -c "settings.proposeLayout" src/templates/_settings.twig
1
$ grep -c "settings.proposeProviders" src/templates/_settings.twig
1
$ grep -c "forms.lightswitchField" src/templates/_settings.twig
2
$ grep -c "name: 'proposeLayout'" src/templates/_settings.twig
1
$ grep -c "name: 'proposeProviders'" src/templates/_settings.twig
1
$ grep -c -- "--no-layout" src/templates/_settings.twig
2
$ grep -c -- "--no-providers" src/templates/_settings.twig
2

$ grep -n "^<h2>" src/templates/_settings.twig
19:<h2>{{ 'Connectivity'|t('kunstmaan-migrator') }}</h2>
75:<h2>{{ 'Mapping'|t('kunstmaan-migrator') }}</h2>
120:<h2>{{ 'AI'|t('kunstmaan-migrator') }}</h2>
141:<h2>{{ 'Fallback'|t('kunstmaan-migrator') }}</h2>
```

Each plan-level acceptance criterion passes (counts at-or-above thresholds). H2 ordering preserved with AI inserted at the mandated position.

## Settings Binding Sanity Check

`src/models/Settings.php` (from Plan 08-08) declares the two booleans the new lightswitches bind to:

```
$ grep -n "proposeLayout\|proposeProviders" src/models/Settings.php
93:    public bool $proposeLayout = true;
94:    public bool $proposeProviders = true;
234:            // Phase 8 / D-14 — AI proposer scope gates (proposeLayout, proposeProviders).
235:            [['seoEnabled', 'retourEnabled', 'proposeLayout', 'proposeProviders'], 'boolean'],
```

Default ON (D-11 / D-14: proposers default-on with heuristic-trigger gating). Validation rule covers both as booleans. CP form submission will round-trip through `Settings::rules()` cleanly.

## TDD Gate Compliance

Plan-level TDD: this plan's frontmatter says `type: execute` (not `type: tdd`), so the strict RED → GREEN → REFACTOR plan-level gate doesn't apply. Per-task `tdd="true"` marker was present, but the plan's `<acceptance_criteria>` listed only grep-based checks (no test-file paths). Following the precedent set by 08-05 / 08-08 (no PHPUnit tests authored for AnalyzeController-adjacent CP-template work; CP rendering is exercised at the integration / UAT layer in later phases).

The single task did pass through its acceptance gate (8 grep checks + H2-ordering check) before commit.

## PROP-04 Closure (CP-side)

D-14's four-layer ladder is now fully visible to the operator:

| Layer | Surface | This plan? |
|---|---|---|
| 1 (innermost) | `Settings::proposeLayout` / `proposeProviders` | **CP form (THIS plan) + JSON settings (08-08)** |
| 2 | `--no-layout` / `--no-providers` CLI flags | named in CP instructions (THIS plan); declared in AnalyzeController (08-08) |
| 3 | `--no-ai` blanket flag | (existing — out of scope) |
| 4 | `ANTHROPIC_API_KEY` empty | (existing — out of scope) |
| 5 (outermost) | per-step input emptiness | (existing — out of scope) |

PROP-04 ladder layer 1 + layer 2 awareness now live on the CP. Operator can toggle the proposers without editing settings JSON or memorizing CLI flags.

## Next Phase Readiness

- Phase 8 Wave 4 progresses: 08-13 (this plan) is the operator-surface bookend for the proposer ladder shipped in 08-08.
- No blockers introduced for downstream Wave 4 plans (08-14, 08-15) — the Twig template change is leaf-of-graph and does not touch shared services, controllers, or compile pipelines.
- `_settings.twig` smoke-check via the CP can be folded into the eventual UAT round (out of scope for this plan; the form is a leaf template loaded by Craft's `settings/plugins/_settings.twig` parent — CFG-05 / D-19 fragment-shape contract preserved).

## Self-Check: PASSED

All claimed file and commit exist:

```
$ git log --oneline -2
358b3c7 feat(08-13): _settings.twig AI H2 group with proposeLayout + proposeProviders (D-14)
1ab7288 docs(phase-08): mark Wave 3 plans (08-08, 08-09) complete in ROADMAP

$ test -f src/templates/_settings.twig && echo FOUND
FOUND

$ git log --oneline --all | grep -q '358b3c7' && echo "FOUND: 358b3c7"
FOUND: 358b3c7
```

All claimed grep counts match (see "Acceptance-Gate Verification" above). Self-check status: **PASSED**.

---
*Phase: 08-taxonomies-and-proposers*
*Completed: 2026-04-27*
