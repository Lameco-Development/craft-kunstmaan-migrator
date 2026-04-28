---
phase: 03-etl-pipeline-field-handlers
plan: 10
subsystem: etl-field-handlers
tags: [field-handlers, splitname, dutch, tussenvoegsel, verbatim-port, wave-3]
requirements: [FH-01]
dependency-graph:
  requires:
    - 03-01 (FieldHandler interface, ResolverContext)
  provides:
    - FH-01 (Dutch composite-name splitter handler)
  affects: []
tech-stack:
  added: []
  patterns:
    - verbatim-port-namespace-flatten
    - per-part-dispatcher
    - pure-function-tokenizer
key-files:
  created:
    - src/fields/handlers/SplitNameHandler.php
  modified: []
decisions:
  - "D-08-22 honored: Dutch tussenvoegsel handling ported byte-for-byte; PREFIX_TOKENS / INFIX_TOKENS / SUFFIX_TOKENS const lists preserved verbatim from v1 lines 45-63."
  - "D-46 honored: verbatim port discipline — no behavioral changes, only namespace flatten + import retarget."
  - "Defensive infix→lastName fallback (v1 lines 152-157) preserved unmodified — prevents empty-lastName saves on inputs like 'Jan van'."
metrics:
  duration: ~3min
  completed: 2026-04-26
  tasks: 1
  files: 1
---

# Phase 3 Plan 10: SplitNameHandler (Dutch Composite-Name Splitter) Summary

Verbatim port of v1's 176-LOC SplitNameHandler — the Dutch-aware composite-name splitter producing 5 parts (firstName / infix / lastName / prefix / suffix), driven by three const token lists and a defensive infix→lastName fallback.

## What Was Built

`src/fields/handlers/SplitNameHandler.php` (176 LOC) — final class implementing `FieldHandler`. Splits composite legacy `real_name` strings (e.g. "Dr. Jan van der Meer") into five fields via a `handlerOptions.part` dispatcher that picks one of `firstName|infix|lastName|prefix|suffix` from the pure-function `split()` result.

Three Dutch-specific const token lists drive tokenization:
- **PREFIX_TOKENS** — academic/professional titles (`dr`, `dr.`, `ir`, `ir.`, `drs`, `drs.`, `prof`, `prof.`, `mr`, `mr.`, `mw`, `mw.`, `ing`, `ing.`, `mrs`, `mrs.`, `ms`, `ms.`)
- **INFIX_TOKENS** — Dutch tussenvoegsels (`van`, `de`, `der`, `den`, `ten`, `ter`, `het`, `'t`, `op`, `aan`, `bij`, `in`, `uit`, `over`, `onder`, `achter`, `la`, `le`, `du`, `des`, `del`, `da`, `di`, `von`, `zu`)
- **SUFFIX_TOKENS** — generation markers (`jr`, `jr.`, `sr`, `sr.`, `i`, `ii`, `iii`, `iv`, `v`)

The defensive infix→lastName fallback (when "Jan van" tokenizes to firstName=Jan, infix=van, lastName='') promotes the last infix token to lastName so saves never violate non-empty lastName constraints.

## Tasks Completed

| Task | Name | Commit | Files |
| ---- | ---- | ------ | ----- |
| 1 | Verbatim port SplitNameHandler with Dutch token const lists preserved byte-for-byte | e568628 | src/fields/handlers/SplitNameHandler.php |

## Reshape Applied (vs v1)

| v1 location | Change | v2 location |
|---|---|---|
| `namespace lameco\kunstmaanmigrator\bridge\fields\handlers` | namespace flatten | `namespace lameco\kunstmaanmigrator\fields\handlers` |
| `use lameco\kunstmaanmigrator\bridge\fields\FieldHandler` | import retarget | `use lameco\kunstmaanmigrator\fields\FieldHandler` |
| `use lameco\kunstmaanmigrator\bridge\fields\ResolverContext` | import retarget | `use lameco\kunstmaanmigrator\fields\ResolverContext` |

**Preserved unmodified:** `final` modifier, `declare(strict_types=1)`, `RuntimeException` on invalid `part`, the three token const lists, the per-part dispatcher (`resolve()`), the pure-function `split()` 5-part tokenizer, the defensive infix→lastName fallback, the apostrophe-aware `'t` handling, the case-insensitive `mb_strtolower` token matching, the early-empty-value guards.

**Note:** v1 already used `RuntimeException` directly (no `MigrationConfigError`), so no exception-class swap was needed.

## Reconciliation

| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| SplitNameHandler | 4 | 0 (v1 already used RuntimeException) | 0 |

The plan reconciliation predicted "1 dropped intentionally (MigrationConfigError if present)" but v1 already used `RuntimeException`, so nothing was dropped. Net: 4 ported / 0 dropped intentionally / 0 dropped accidentally.

## Verification

- `php -l src/fields/handlers/SplitNameHandler.php` → "No syntax errors detected".
- File: 176 lines (≥ 160 ✓).
- Namespace declaration: `namespace lameco\kunstmaanmigrator\fields\handlers;` ✓.
- Implements `FieldHandler` ✓.
- All three const token lists present (PREFIX_TOKENS, INFIX_TOKENS, SUFFIX_TOKENS) ✓.
- `'van'` and `'der'` Dutch tussenvoegsels preserved ✓.
- `function split` present ✓.
- Zero `MigrationConfigError` references ✓.
- Zero stale `bridge\` / `craft\` / `kunstmaan\` namespace references ✓.

## Deviations from Plan

None — plan executed exactly as written, modulo the reconciliation note above (v1 already used `RuntimeException`, so no exception-class swap was needed). No bugs found, no missing critical functionality, no blocking issues.

## Decisions Made

- Used the v1 v1.x file `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` as canonical source. The plan's quoted const lists matched v1 byte-for-byte; verified before writing.
- Per-task commit on `main` branch as directed by the executor protocol context (`Branch: main. No worktree.`). Project's GSD workflow deviates from global GIT_FLOW.md for plan execution.

## Self-Check: PASSED

- File exists: `src/fields/handlers/SplitNameHandler.php` ✓ (FOUND)
- Commit exists: `e568628` ✓ (FOUND in `git log`)
- All `<done>` predicates from Task 1 verified ✓
