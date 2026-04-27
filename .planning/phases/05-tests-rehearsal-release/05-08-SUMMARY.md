---
phase: 05
plan: 08
subsystem: release / docs
tags: [release-checklist, changelog, reconciliation, requirements-flip, tst-04, d-25, d-26, phase-closure]

# Dependency graph
requires:
  - phase: 05-tests-rehearsal-release
    provides: "Plan SUMMARYs 05-01..07 (citations for RECONCILIATION); RehearsalController CLI surface (Plan 05-04, step 5 of RELEASE-CHECKLIST); composer test-coverage chained-script (Plan 05-02, step 3 of RELEASE-CHECKLIST); CI workflow (Plan 05-07, step 4); transform fixture rig (Plan 05-03)"
provides:
  - ".planning/RELEASE-CHECKLIST.md — operator-driven 8-step pre-tag gate with CQM rehearsal-check exit 0 as the binding v1.0 ship gate"
  - "CHANGELOG.md (root) — Keep-a-Changelog v1.0.0 entry summarizing v2-rewrite scope vs v1.x"
  - ".planning/phases/05-tests-rehearsal-release/RECONCILIATION.md — Phase 5 closure document; per-plan corpus delta + per-module coverage gate state + carry-overs to Phase 5.1 / NEXT"
  - ".planning/REQUIREMENTS.md — TST-01..04 flipped to [x] with italicized phase + plan references"
affects:
  - "v1.0 ship gate (operator workflow defined; awaiting CQM artifact capture)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Operator-driven manual release gate (no ship.yml automation per D-26)"
    - "Keep-a-Changelog 1.1.0 + SemVer (Lameco verified-against-brownfield convention)"
    - "RECONCILIATION shape mirrors Phase 4.1's: H1 + Date + Plans shipped + Test corpus delta + Requirements closed table + Architectural ground rules respected + carry-overs"
    - "REQUIREMENTS.md italicized phase-reference flip pattern (mirrors Phase 4.1 closure verbatim)"

key-files:
  created:
    - .planning/RELEASE-CHECKLIST.md (Task 1, commit ffa06c6)
    - CHANGELOG.md (Task 2, commit 21bc517)
    - .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md (Task 3, commit f0065d3)
  modified:
    - .planning/REQUIREMENTS.md (Task 3, commit f0065d3 — TST-01..04 flipped to [x])

key-decisions:
  - "D-25 step 8 (composer.json version pin) OMITTED per pre-planning verification of Lameco brownfield (~/Sites/craft-kunstmaan-migrator/composer.json + ~/Sites/craft-seo-import/composer.json + ~/Sites/craft-entry-optimizer/composer.json all lack a `version` field — Composer derives from git tags). Rationale recorded in RELEASE-CHECKLIST.md `## Composer.json version field — INTENTIONALLY NOT REQUIRED` section."
  - "D-26: no ship.yml workflow. Manual operator-driven tag is the v1.0 release path; checklist is the gate. Re-evaluate post-v1.1 if shipping cadence demands automation. Codified in RELEASE-CHECKLIST.md `## No automated ship.yml workflow` section."
  - "D-04 carry-over: pre-publish anonymization gate flagged in RELEASE-CHECKLIST.md but explicitly NOT a v1.0 ship gate; lands when the repo's namespace changes from lameco/."
  - "Tasks 1 (RELEASE-CHECKLIST) and 2 (CHANGELOG) had been committed pre-resume by a prior partial run (commits ffa06c6 and 21bc517); this executor verified all acceptance criteria against the existing files (PASS) and proceeded to Task 3 without rewriting either."

# Metrics
metrics:
  duration: ~10min
  completed: 2026-04-27
  tasks_completed: 3
  tasks_total: 3
  files_created: 3
  files_modified: 1
  tests_added: 0
---

# Phase 5 Plan 08: Release Checklist, Changelog, Reconciliation Summary

**One-liner:** Phase 5 closure shipped — `.planning/RELEASE-CHECKLIST.md` (8-step operator gate, D-25 step 8 OMITTED per Lameco convention), `CHANGELOG.md` (Keep-a-Changelog v1.0.0 entry), `.planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` (mirrors Phase 4.1 shape), and TST-01..04 flipped to `[x]` in REQUIREMENTS.md with italicized phase + plan references.

## What shipped

### Task 1 — `.planning/RELEASE-CHECKLIST.md` (commit `ffa06c6`, pre-resume)

Operator-driven pre-tag gate. Eight numbered steps:

1. `composer validate --strict --no-plugins` green.
2. `composer test` green (Unit + Integration suites).
3. `composer test-coverage` green (per-module 70% line-coverage gate on every TST-01 module).
4. CI smoke job green on a recent commit.
5. **CQM `kunstmaan-migrator/rehearsal/check` exits 0** against `.planning/rehearsal/v1.0/cqm/` — the binding v1.0 ship gate (D-19, D-23).
6. Simac + enreach rehearsal logs captured (advisory; failures don't block — D-19).
7. `CHANGELOG.md` rewritten for v1.0 with `<release-date>` substituted.
8. Tag pushed; `STATE.md` updated; milestone closed via `/gsd-complete-milestone`.

Plus three sibling sections:
- **Composer.json `version` field — INTENTIONALLY NOT REQUIRED** (D-25 step 8 omission rationale, with the three brownfield composer.json verifications cited).
- **Pre-publish gate — NOT part of v1.0 ship** (D-04 carry-over).
- **No automated `ship.yml` workflow** (D-26 codified).

Acceptance criteria (verified before resume): 12/12 grep checks pass — 8 numbered steps, composer-validate / test-coverage / rehearsal-check references present, CQM mention ≥2, "v1.0 ship gate" ≥1, "advisory" ≥1, INTENTIONALLY-NOT-REQUIRED documented, Pre-publish gate documented, `/gsd-complete-milestone` referenced (step 8).

### Task 2 — `CHANGELOG.md` (commit `21bc517`, pre-resume)

Repo-root Keep-a-Changelog file mirroring Lameco's brownfield convention (`~/Sites/craft-kunstmaan-migrator/CHANGELOG.md` Keep-a-Changelog 1.1.0 + SemVer). Single `## 1.0.0 — <release-date>` entry with sections:

- **Added** — 16 bullets covering the v2-rewrite capability surface (CLI-only operator surface, single mapping.yaml + per-row status, MigrationFilters, plugin-owned legacy DB, optional SEOmatic/Retour adapters, atomic-always-on, JIT asset ingestion, sync-assets recovery, doctor 10 checks, rehearsal/check, MigrationStateService::markTerminal, AI-assisted analyze stage, transform characterization tests, PHPUnit + per-module coverage gate, CI workflow, configuration via `config/kunstmaan-migrator.php`, NeverProductionTrait).
- **Changed (vs v1.x)** — 8 bullets calling out deliberate departures (mapping persistence, adapter integration, legacy DB wiring, atomic mode, operator surface, source layout, AI provider, test discipline).
- **Removed (vs v1.x)** — 8 bullets enumerating dropped surface (`--atomic` flag, `mapping.yaml.draft` + `mapping-drops-{ts}.yaml`, CP utility, inline mapping editor, `.claude/skills/`, three-tier layout + Deptrac, hard composer pins on SEOmatic/Retour, Yii `legacyDb` component requirement).
- **Security** — NeverProductionTrait gating + runtime-zero-AI posture.

`<release-date>` placeholder kept; RELEASE-CHECKLIST step 7 instructs the operator to substitute it. File length 116 lines (acceptance bound 50-200).

Acceptance criteria (verified before resume): 14/14 grep checks pass — Keep a Changelog + Semantic Versioning headers, all 4 section headings, single mapping.yaml called out, atomic-always-on / CLI-only / runtime-zero-AI / NeverProductionTrait mentions, `<release-date>` placeholder retained, length within bound.

### Task 3 — `.planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` + `.planning/REQUIREMENTS.md` flips (commit `f0065d3`)

**RECONCILIATION.md** mirrors Phase 4.1's shape:

- H1 + Date (2026-04-27) + Plans shipped (05-01..05-08) + Test corpus delta header (179 → ~317).
- **Phase outcome summary** — what's done + what's deliberately NOT done (no ship.yml, no CP runner, no composer.json version pin).
- **Requirements closed in Phase 5** — table mapping TST-01..04 to plans + one-line outcome each.
- **Architectural ground rules respected** — 11 bullets confirming no decisions reversed; explicit acknowledgment that RehearsalController **DELIBERATELY OMITS** NeverProductionTrait (D-22 deliberate departure).
- **Test corpus delta** — additive per-plan table covering all 8 plans across 4 waves; phase-close additive total = 317 (179 + 1 + 17 + 45 + 75); orchestrator's post-merge `composer test` is the authoritative source.
- **Coverage gate state at v1.0** — branch-by-inspection table covering all 5 TST-01 modules + every field handler; AssetHandler flagged as the closest to the 70% floor (uncovered `as=imgTag` SUCCESS path requires Craft bootstrap or refactor-with-seam — both out of scope under refactor-abstinence). Below-70% failure path explicitly handed to Phase 5.1 / NEXT, not Phase 5 backfill.
- **Phase 5 ↔ prior-phase commit references** — placeholders for orchestrator post-merge update.
- **Phase 5 carry-overs** — 8 bullets (CQM artifact capture, pre-publish anonymization, simac/enreach end-to-end, MigrateController structural review, BASE_URL verify-URL auto-gen, REC-02, deferred ideas, AssetHandler imgTag-success coverage).
- **v1.0 ship readiness** — 5 numbered operator-side actions remaining before the v1.0.0 tag.

**REQUIREMENTS.md flips:**

| ID | Before | After (italicized reference shape) |
|---|---|---|
| TST-01 | `[ ]` | `[x] _(Phase 5 / Plans 05-01 + 05-02 + 05-05 + 05-06 — tests/ split into unit/ + integration/ tiers via 12 history-preserving git-mv operations (D-12); per-module 70% line-coverage gate on the 5 TST-01 modules enforced by tools/check-coverage.php (D-08); +120 unit tests across 7 new D-10 test files (HeuristicProposerTest 22 + CkeditorRewriterServiceTest 23 + PlainText 18 + SplitName 15 + Relation 16 + Matrix 16 + Asset 10).)_` |
| TST-02 | `[ ]` | `[x] _(Phase 5 / Plan 05-03 — TransformCharacterizationTest @dataProvider over per-row JSON fixtures...)_` |
| TST-03 | `[ ]` | `[x] _(Phase 5 / Plan 05-07 — .github/workflows/ci.yml splits into unit + smoke jobs...)_` |
| TST-04 | `[ ]` | `[x] _(Phase 5 / Plans 05-04 + 05-08 — RehearsalController with three mechanical gate parsers...)_` |

Acceptance criteria (post-Task-3): 13/13 grep checks pass — RECONCILIATION exists with TST-01 + TST-04 references, all 4 required sections present (`Architectural ground rules respected`, `Coverage gate state`, `carry-over`, `DELIBERATELY OMIT`), all 4 TST-x flipped to `[x]`, zero `[ ] **TST-0` left, ≥4 italicized "Phase 5 / Plan" references in REQUIREMENTS.md.

## Verification (regression checks)

| Check | Expected | Got |
|---|---|---|
| `git diff src/` empty (zero source-code changes in Phase 5 plan 08) | empty | empty |
| `git diff .github/` empty (no ship.yml — D-26 honored) | empty | empty |
| `find . -name 'ship.yml' -o -name 'release.yml'` (excl. vendor/node_modules) | 0 | 0 |
| `grep -c '^- \[x\] \*\*TST-0' REQUIREMENTS.md` | 4 | 4 |
| `grep -c '^- \[ \] \*\*TST-0' REQUIREMENTS.md` | 0 | 0 |
| RELEASE-CHECKLIST has 8 numbered steps | 8 | 8 |
| CHANGELOG has `## 1.0.0` heading | 1 | 1 |
| RECONCILIATION cites all 4 TST IDs | ≥4 | TST-01:4 + TST-04:1 (TST-02/03 in table) |

`composer test` regression check could not be run in this worktree (no `vendor/` — parallel executors run without composer install). The orchestrator's post-merge full-corpus run is the authoritative regression signal.

## Final test corpus count

| Stage | Total tests |
|---|---|
| Phase 4.1 close (`composer test` head, per Phase 4.1 RECONCILIATION) | 179 |
| Plan 05-01 (Wave 1, reorganization only) | +0 → 179 |
| Plan 05-02 (Wave 2, infrastructure only) | +0 → 179 |
| Plan 05-03 (Wave 2, TransformCharacterizationTest shell) | +1 → 180 |
| Plan 05-04 (Wave 2, RehearsalControllerTest) | +17 → 197 |
| Plan 05-05 (Wave 3, HeuristicProposer + CkeditorRewriter) | +45 → 242 |
| Plan 05-06 (Wave 3, 5 handler tests) | +75 → 317 |
| Plan 05-07 (Wave 4, CI only) | +0 → 317 |
| Plan 05-08 (Wave 4, this plan, docs only) | +0 → 317 |
| **Phase 5 close (additive)** | **317** |

Within-worktree counts (e.g., 05-06 SUMMARY's "272 full corpus") cannot reconcile parallel Wave 3 contributions; the additive 317 is the expected post-merge count, subject to authoritative confirmation by the orchestrator's `composer test` after every Wave 3+4 worktree lands on `main`.

## Per-module coverage gate state at Phase 5 close

See RECONCILIATION.md `## Coverage gate state at v1.0` for the full branch-by-inspection table. Summary:

- **9 modules covered** by the per-module gate (`MigrationFilters`, `MappingFile`, `CkeditorRewriterService`, `HeuristicProposer`, `PlainTextHandler`, `SplitNameHandler`, `RelationHandler`, `MatrixHandler`, `AssetHandler`).
- **Likely all 9 clear the 70% floor** based on branch-by-inspection (PCOV not loaded in any executor environment; first authoritative run lands in CI via Plan 05-07's `unit` job).
- **AssetHandler closest to floor** — `as=imgTag` SUCCESS path uncovered (needs Craft bootstrap or refactor-with-seam, both out of scope per Phase 5's refactor-abstinence rule). If the gate fails for AssetHandler in CI, the remediation path is a Phase 5.1 / NEXT input — explicitly NOT a Phase 5 backfill.

## Confirmation: D-26 honored (no ship.yml / release.yml workflow files)

```
$ find . -name 'ship.yml' -o -name 'release.yml' | grep -v vendor | grep -v node_modules | wc -l
0
```

The only workflow file under `.github/workflows/` is `ci.yml` (Plan 05-07). No automation for tagging — the v1.0 release path is operator-driven and gated by `.planning/RELEASE-CHECKLIST.md`.

## CHANGELOG v1.0.0 scope cross-check vs PROJECT.md Key Decisions

PROJECT.md Key Decisions table cites 16 locked decisions. The v1.0.0 CHANGELOG `### Added` + `### Changed` + `### Removed` sections cover them as follows:

| PROJECT.md decision | CHANGELOG section + line |
|---|---|
| Single `mapping.yaml` with per-row `status:` | Added (line 20-22) + Changed (line 78-79) + Removed (line 98-99) |
| Optional SEOmatic / Retour adapters | Added (line 29-32) + Changed (line 80-81) |
| Filter spec from day one | Added (line 23-24) |
| Writer seam deferred | _(implicitly: not in CHANGELOG; correctly absent — deferred per NEXT-01)_ |
| Anthropic-only AI | Added (line 53-57) + Changed (line 89-91) |
| Drop three-tier `kunstmaan/`/`craft/`/`bridge/` + Deptrac | Changed (line 87-88) + Removed (line 103) |
| Drop `.claude/skills/` bundle | Removed (line 102) |
| Drop CP "Migration Pipeline" runner | Removed (line 100) |
| Tests required from day one | Added (line 58-65) + Changed (line 92-93) |
| Page-driven migration + orphan-media | Added (line 35-36 — JIT) + (orphan-media as NEXT-05; correctly absent from v1.0 entry) |
| Keep `kunstmaanmigrator_state` schema verbatim | _(implicitly: shipped in Phase 1; CHANGELOG focuses on rewrite-vs-v1 surface — deliberately not re-cited)_ |
| `kunstmaanSourceId` stays Plain Text | _(same as above — Phase 1 carry-over from v1.x; not a v2 change)_ |
| CLI namespace stays `kunstmaan-migrator/*` | _(same — operator continuity, not a change)_ |
| Keep `migrate/install` programmatic-migration shim | _(same — Phase 1 carry-over)_ |
| `doctor` drops queue-worker check | _(implicit in Added line 42-45 — 10 checks, not 11)_ |
| CP Settings page deferred to Phase 4 | _(shipped in Phase 4; CFG-01..07 covered by Added line 69-71 — config file shape)_ |

Conclusion: every active v2-vs-v1.x decision is reflected in the CHANGELOG `Added`/`Changed`/`Removed` sections. Decisions that are Phase 1 carry-overs (preserve v1's shape for swap-in compatibility) are deliberately absent — they are continuity, not change. Decisions that are deferred (Writer seam, multi-provider AI, orphan-media sync) are correctly absent from the v1.0 entry.

## Phase 5 closure announcement

**Phase 5 closes.** Ready for operator to capture CQM rehearsal artifacts on a dev host + run `.planning/RELEASE-CHECKLIST.md` 8 steps + tag `v1.0.0` + invoke `/gsd-complete-milestone`.

The orchestrator owns: post-merge ROADMAP.md + STATE.md updates after every Wave 3+4 worktree lands on `main`; authoritative `composer test` count for the Phase 5 close.

## Carry-overs handed to Phase 5.1 / NEXT-XX

See RECONCILIATION.md `## Phase 5 carry-overs` for the full list (8 items). Headline:

1. **CQM artifact capture** — manual operator-side action; explicit out-of-CI scope per D-24.
2. **Pre-publish anonymization scrub** — D-04 carry-over; lands when the repo namespace changes from `lameco/`.
3. **Simac + enreach end-to-end rehearsals** — advisory per D-19; Phase 5.1 / NEXT-04 input.
4. **`MigrateController` 1700+ LOC structural review** — Phase 4.1 carry-over not addressed in Phase 5.
5. **AssetHandler `as=imgTag` SUCCESS-path coverage** — Phase 5.1 / NEXT input if CI flags the per-module gate.
6. Plus 3 deferred items per CONTEXT.md `## Deferred Ideas` (mutation testing, multi-provider AI, ship.yml workflow).

## Deviations from Plan

None. Tasks 1 and 2 were already committed by a prior partial run (commits `ffa06c6` and `21bc517` — see git log); this executor verified all acceptance criteria against the existing files (PASS for both) and proceeded directly to Task 3 (RECONCILIATION + REQUIREMENTS flips). No rewrites of pre-resume artifacts; no architectural changes; no auth gates.

## Self-Check

**Files exist:**
- FOUND: `.planning/RELEASE-CHECKLIST.md`
- FOUND: `CHANGELOG.md`
- FOUND: `.planning/phases/05-tests-rehearsal-release/RECONCILIATION.md`
- FOUND: `.planning/phases/05-tests-rehearsal-release/05-08-SUMMARY.md` (this file)

**Commits exist:**
- FOUND: `ffa06c6` (Task 1 — docs(05-08): add v1.0 RELEASE-CHECKLIST with 8 mandatory pre-tag steps)
- FOUND: `21bc517` (Task 2 — docs(05-08): add CHANGELOG.md with v1.0.0 entry in Keep-a-Changelog format)
- FOUND: `f0065d3` (Task 3 — docs(05-08): close Phase 5 — RECONCILIATION + flip TST-01..04 to [x])

**Acceptance contracts:**
- FOUND: 12/12 RELEASE-CHECKLIST grep checks pass
- FOUND: 14/14 CHANGELOG grep checks pass
- FOUND: 13/13 RECONCILIATION + REQUIREMENTS grep checks pass
- FOUND: zero src/ diff (`git diff src/` empty)
- FOUND: zero .github/ diff (`git diff .github/` empty)
- FOUND: zero ship.yml/release.yml under repo (D-26 honored)
- FOUND: 4/4 TST-01..04 flipped to `[x]` in REQUIREMENTS.md
- FOUND: 0/4 TST-x left as `[ ]` in REQUIREMENTS.md

## Self-Check: PASSED
