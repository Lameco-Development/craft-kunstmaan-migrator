# Phase 5: Tests, Rehearsal & Release — Discussion Log

**Captured:** 2026-04-27
**Audience:** Human reference only. Downstream agents (researcher, planner, executor) read `05-CONTEXT.md`, not this file.

## Domain framing

> Lock the v1.0 ship gate — characterization tests on the Transform stage, ~70% unit coverage on the named modules, CI gating every PR (including a plugin-load smoke), and a green rehearsal log against the CQM corpus. Four requirements (TST-01..04). Existing footing: 179 tests / 488 assertions, basic CI (`composer validate --strict` + `composer test` on PHP 8.3), single flat `tests/` tree with no unit/integration split, no coverage tooling, no fixtures captured yet.

## Areas selected

User selected all four:
1. Characterization fixtures (TST-02)
2. Coverage + test gaps + dir layout (TST-01)
3. CI plugin-load smoke (TST-03)
4. Rehearsal + release checklist (TST-04)

---

## Area 1: Characterization fixtures (TST-02)

### Q1: Where do we cut the characterization fixture's INPUT boundary?
- **Pre-extracted PHP/JSON rows** (Recommended) — capture once via Extract, snapshot, feed into TransformService. ✅ **Selected**
- Sanitized SQL dump fragment — Extract+Transform together, but slower CI and sanitization burden.
- Both — layered, most coverage but more maintenance.

**Decision:** Pre-extracted PHP/JSON rows. → D-01.

### Q2: Corpus scope and golden granularity?
- **Narrow + per-row goldens** (Recommended) — 4-5 entity types covering the field handlers, ~10-15 rows total, one golden per row. ✅ **Selected**
- Broad + per-entity-type aggregate goldens — every entity type, noisier diffs.
- Narrow + per-row, locale-doubled — would need to pull simac in.

**Decision:** Narrow + per-row goldens. → D-02.

### Q3: How do we regenerate goldens when Transform output legitimately changes?
- **Env-var `UPDATE_SNAPSHOTS=1`** (Recommended) — standard PHPUnit pattern, golden diff lands in same PR. ✅ **Selected (deferred to recommendation)**
- Dedicated console command — separate from test runner, drift risk.
- Manual JSON edits only — too tedious.

**User note:** "Actually, not sure what this is used for so going with your recommendation." Mid-flow context: characterization tests snapshot output as JSON and assert byte-equality on every run; when the transform legitimately changes, the env-var rerun overwrites the goldens and the diff becomes the review surface.

**Decision:** Env-var `UPDATE_SNAPSHOTS=1`. → D-03.

### Q4: Anonymize CQM content before committing fixture rows?
- **No — commit verbatim** (Recommended) — internal Lameco rehearsal site, repo is private, realism earns its keep. ✅ **Selected**
- Lightweight scrub — defensible if repo ever goes public.
- Aggressive scrub + synthetic content — defeats characterization value.

**Decision:** No anonymization. → D-04. Conditional on repo staying private; flagged in `<specifics>`.

---

## Area 2: Coverage + test gaps + dir layout (TST-01)

### Q1: Coverage driver and where the 70%-per-module gate is enforced?
- **PCOV + phpunit.xml gate** (Recommended) — fast, local-dev parity, gate visible everywhere. ✅ **Selected (deferred to recommendation)**
- Xdebug + CI script gate — slower, lower setup friction.
- PCOV + CI-only gate — gate invisible until CI runs.

**User note:** "Going with your recommendation."

**Decision:** PCOV + phpunit.xml gate (with `tools/check-coverage.php` doing per-module clover parsing). → D-06..D-09.

### Q2: How do we fill the unit-test gaps for HeuristicProposer, CkeditorRewriter, and the 5 FieldHandlers?
- **Direct unit tests per module** (Recommended) — one test file per module, isolated with mocks/stubs. ✅ **Selected (deferred to recommendation)**
- Indirect via Transform characterization + targeted top-ups — coverage tied to fixture corpus.
- Hybrid — direct for proposer + handlers, characterization for rewriter.

**User note:** "Going with your recommendation."

**Decision:** Direct unit tests per module. ~7 new test files. → D-10..D-11.

### Q3: TST-01 names "tests/unit and tests/integration". Reorganize the existing 179 tests, or keep flat?
- **Reorganize — `git mv tests/<area>/ tests/unit/<area>/` + add tests/integration/** (Recommended) — matches TST-01 wording, clean split. ✅ **Selected**
- Keep flat, add testsuite filters — TST-01 satisfied at testsuite level only.
- Reorganize partially — adds `tests/integration/` only; mixes "unit by default" convention.

**Decision:** Full reorganization. → D-12..D-14. Land FIRST plan in Phase 5 to avoid merge churn.

---

## Area 3: CI plugin-load smoke (TST-03)

### Q1: How does the CI smoke test bootstrap a scratch Craft 5 install?
- **`composer create-project craftcms/craft` + path repo** (Recommended) — pure composer, no Docker, mirrors real consumer install. ✅ **Selected (deferred to recommendation)**
- Docker `craftcms/craft` image — adds Docker dep + image-version drift.
- Minimal bootstrap stub — cheaper but less realistic.

**User note:** "Going with your recommendation."

**Decision:** `composer create-project` + path repo. → D-15.

### Q2: PHP matrix and smoke-assert depth?
- **PHP 8.3 only + doctor exits 0** (Recommended) — simplest signal, doctor is a deep boot check. ✅ **Selected (deferred to recommendation)**
- PHP 8.3 + 8.4 matrix + doctor exits 0 — 2x CI minutes for marginal signal pre-8.4 adoption.
- PHP 8.3 only + boot-only assertion — misses post-init wiring failures.

**User note:** "Going with your recommendation."

**Decision:** PHP 8.3 only + doctor exits 0. → D-16..D-18. WARN exits 0; only FAIL fails the smoke.

---

## Area 4: Rehearsal + release checklist (TST-04)

### Q1: v1.0 rehearsal scope — which Kunstmaan dumps does v1.0 ship green against?
- CQM only (per ROADMAP) — honest scope, simac/enreach as Phase 5.1.
- All three: cqm + simac + enreach — full matrix, pulls NEXT-04 forward.
- **CQM blocking + simac/enreach advisory** ✅ **Selected** — best-of-both: smoke-tests wider matrix without blocking.

**Decision:** CQM blocking + simac/enreach advisory. → D-19.

### Q2: Where does the rehearsal log commit, and how are the three success gates mechanically checked?
- **Commit log to `.planning/rehearsal/v1.0/` + new `migrate rehearsal-check` command** (Recommended) — mechanical, repeatable, re-verifiable from any tag. ✅ **Selected (deferred to recommendation)**
- Commit log + manual checklist review — fragile under repeat ship cycles.
- Don't commit live data — sanitized summary only — privacy-safer but loses reproduction property.

**User note:** "Going with your recommendation."

**Decision:** `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` directory + new `kunstmaan-migrator/rehearsal-check <dir>` CLI. → D-20..D-24.

### Q3: Where does the release-checklist artifact live?
- **`.planning/RELEASE-CHECKLIST.md`** (Recommended) — phase-aligned with the rest of `.planning/`, auditable. ✅ **Selected (deferred to recommendation)**
- `docs/RELEASING.md` (operator-facing) — discoverable but precedent shift.
- README + `.github/workflows/ship.yml` — mechanizes more, requires committed artifacts.

**User note:** "Going with your recommendation."

**Decision:** `.planning/RELEASE-CHECKLIST.md` with 9 numbered steps. → D-25..D-26. No `ship.yml` for v1.0.

---

## Patterns in user style this session

- User deferred to recommendations on 7 of 11 questions. Style here: pick a clear "first option = recommended" with explicit rationale, surface trade-offs in the description, expect deference when the recommendation is technically obvious (PCOV vs Xdebug, env-var snapshots).
- User pushed back on rehearsal scope (Q1 Area 4) — chose "blocking + advisory" hybrid over CQM-only. Pattern: when there's a way to capture more signal cheaply without committing to it as a gate, user takes it.
- One explicit "not sure what this is used for" (Q3 Area 1) — user asked for the recommendation when the trade-off was opaque from the option labels alone. Suggests: when an option's downstream consequences aren't obvious, surface them inline before asking.

## Deferred ideas captured

See `<deferred_ideas>` block in `05-CONTEXT.md`. Notable items:
- Mutation testing (Infection PHP) once line coverage hits 70%+.
- Cross-client rehearsal matrix automation (NEXT-04).
- `ship.yml` GitHub Actions workflow when cadence > quarterly.
- Property-based fuzz tests for `CkeditorRewriterService`.
- Public fixture scrub script (pre-publish gate if/when repo goes public).

## Pre-commit verification (advisor pass)

Three flags raised, all addressed in CONTEXT.md before commit:
- **D-12 didn't address top-level `tests/*.php` files.** Verified: `tests/PluginBootstrapTest.php`, `tests/ComposerSuggestTest.php`, `tests/NeverProductionTraitTest.php` exist at the top of `tests/`. D-12 now splits them explicitly: `PluginBootstrapTest` → `tests/integration/`, the other two → `tests/unit/`.
- **MappingLoader → MappingFile reconciliation.** Grepped `class MappingLoader|MappingLoader::` across `src/` and `tests/` — zero hits. Only `MappingAuditor` and `MappingFile` exist in `src/mapping/`. Reconciliation note in `<specifics>` stands; D-08 coverage scope is correct.
- **D-21 assumed `verify-output.json` exists — it doesn't.** Read `src/console/VerifyController.php`. `verify` writes `storage/migration/VERIFY-<ts>.md` (markdown via `MappingFile::writeAtomic` at line 265); `baseline.json` is a separate artifact from `verify capture-baseline`. D-20 + D-21 rewritten to reference `VERIFY.md` (the rehearsal-directory canonical name) + `baseline.json` separately, with parser shape adjusted.
- **D-22 NeverProductionTrait reconsidered.** Trait dropped from `rehearsal-check` — the command is read-only over committed artifacts and never opens the legacy DB or reads `KUNSTMAAN_SOURCE_PATH`. Applying the trait would gate CI on `CRAFT_ENVIRONMENT != production` for no purposeful reason. Departure from the "every CLI carries the trait" pattern is documented in D-22.

## Out of scope reaffirmed

- Reverse-of-locked-decisions (CP runner, Craft queue, FeedMe remap) — Phase 4.2 if pursued.
- Automated rehearsal runs in CI — operator captures manually, CI verifies committed artifacts.
- Whole-plugin code review / refactor (`MigrateController` 1189 LOC, `SeoMigrationService` 606 LOC) — not a Phase 5 structural deliverable.
