---
phase: 10-generic-migration-rehearsal-gap-closure
plan: 10
type: index
plans:
  - 10-01-PLAN.md
  - 10-02-PLAN.md
  - 10-03-PLAN.md
  - 10-04-PLAN.md
requirements:
  - PH10-01
  - PH10-02
  - PH10-03
  - PH10-04
  - PH10-05
  - PH10-06
  - PH10-07
  - PH10-08
---

# Phase 10 Plan Index: Generic Migration Rehearsal Gap Closure

## Overall Goal

Convert the CQM release-rehearsal findings into generic migration hardening so the canonical workflow can run after restoring the pre-live CQM backup with zero entry failures and zero stage failures.

Explicit accepted out-of-scope/dropped page-owned surfaces may appear only as classified report/coverage rows and must not increment entry or stage failure counts.

## Execution Order

| Plan | Scope | Depends on |
|---|---|---|
| `10-01-PLAN.md` | Compile/preflight safety, PageBuilder ownership validation, structural fixtures | none |
| `10-02-PLAN.md` | Matrix native-title fallback, sparse-locale primary-save fallback, visible fallback reporting | `10-01` |
| `10-03-PLAN.md` | Page-rooted taxonomy lazy resolver, referenced-only default, explicit full-taxonomy import path | `10-01`, `10-02` |
| `10-04-PLAN.md` | Verify count-domain semantics, restored-backup runbook, closing proof | `10-01`, `10-02`, `10-03` |

## Resolved Research Questions

1. Lazy taxonomy resolution runs through a load-safe resolver seam owned by `TaxonomyMigrationService`; `RelationHandler` delegates only on taxonomy-backed non-empty state misses.
2. Dry-run performs no Craft/state writes and reports would-create/would-link counts.
3. Verify domains are split into Craft baseline/current drift, migration-created state counts, and source/transformed parity where available.
4. Load-fatal target validation includes section + entry-type incompatibility; advisory-only warnings remain warnings.

## Context to Read First

- `.planning/PROJECT.md`
- `.planning/ROADMAP.md` Phase 10 section
- `.planning/STATE.md`
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-CONTEXT.md`
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-RESEARCH.md`
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-PATTERNS.md`
- `CLAUDE.md`

## Out of Scope

- Craft schema generation from Kunstmaan.
- Full orphan media sync.
- Full migration of unrelated known omissions: FormBundle, SearchBundle, MenuBundle, users/roles/ACLs, `kuma_translations`, media folder hierarchy, asset metadata, slug history, drafts.
- Full generic `mode: embed | promote` relation architecture unless a small taxonomy-specific seam is necessary.
- Making full unreferenced taxonomy import the default.
- Broad `defaultEntryType` or default-section fallback that hides bad mappings.
- CQM-specific production code paths keyed to page class names, page IDs, block handles, or taxonomy names.

## Requirement Coverage

| Requirement | Covered By |
|---|---|
| PH10-01 Matrix-block title fallback | `10-02-PLAN.md` |
| PH10-02 sparse-locale primary save fallback | `10-02-PLAN.md` |
| PH10-03 section + entry-type compatibility guard | `10-01-PLAN.md` |
| PH10-04 taxonomy-dependent relation resolution | `10-03-PLAN.md` |
| PH10-05 PageBuilder ownership validation | `10-01-PLAN.md` |
| PH10-06 verify count semantics | `10-04-PLAN.md` |
| PH10-07 regression coverage | `10-01-PLAN.md`, `10-02-PLAN.md`, `10-03-PLAN.md` |
| PH10-08 rehearsal restore/rerun instructions | `10-04-PLAN.md` |

## Phase Completion Gate

Phase 10 is complete only when:

- Compile/preflight blocks load-fatal section/type target mismatches.
- PageBuilder ownership is validated before runtime.
- Matrix native-title fallback works generically and visibly.
- Sparse-locale primary-save fallback works generically and visibly.
- Taxonomy-backed relations lazily find/create referenced terms and remain idempotent.
- Missing taxonomy locale values fallback to default-language values and are visible.
- Default taxonomy mode is referenced-only.
- Full unreferenced taxonomy import is only available through explicit CLI/settings path.
- Verify report compares like-for-like domains and labels each domain.
- Regression tests cover all known CQM rehearsal failure shapes generically.
- Restored-backup CQM full workflow reaches zero entry failures and zero stage failures.
- Every page-owned referenced content surface is migrated or classified as explicitly dropped/out-of-scope without counting as an entry/stage failure.
- No production code contains CQM-specific page IDs, block handles, or class-name conditionals.

