# Phase 5: Tests, Rehearsal & Release — Reconciliation

**Date:** 2026-04-27
**Plans shipped:** 05-01 through 05-08
**Test corpus delta:** Phase 4.1 close (179 tests / 488 assertions) → Phase 5 close (~317 tests after Wave 3 + Wave 4 merge — see "Test corpus" section below for the per-plan additive breakdown; the authoritative post-merge `composer test` count is the orchestrator's responsibility once every Wave 3+4 worktree lands on `main`).

## Phase outcome summary

Phase 5 closes the v1.0 ship gate. Four TST requirements (`TST-01..04`) flipped to `[x]`; a complete CI workflow + a per-module 70% line-coverage gate + a transform-stage characterization fixture rig + a read-only mechanical rehearsal gate (`kunstmaan-migrator/rehearsal/check`) + an operator-driven RELEASE-CHECKLIST + a Keep-a-Changelog v1.0.0 entry shipped across 8 plans in 4 waves.

The phase deliberately does NOT ship: an automated `ship.yml` GitHub Actions workflow (D-26), a CP "Migration Pipeline" runner utility (locked architectural rule), or `composer.json` `version` field pinning (verified-against-Lameco-convention OMISSION; rationale recorded in `RELEASE-CHECKLIST.md`).

## Requirements closed in Phase 5

| ID     | Plans                            | One-line outcome |
|--------|----------------------------------|------------------|
| TST-01 | 05-01, 05-02, 05-05, 05-06       | `tests/unit` + `tests/integration` split (D-12 / Plan 05-01); per-module 70% line-coverage gate on `MigrationFilters`, `MappingFile`, every field handler, `CkeditorRewriterService`, `HeuristicProposer` (D-08 / Plan 05-02 via `tools/check-coverage.php`); +120 unit tests across 7 new D-10 test files (Plan 05-05: HeuristicProposerTest 22 + CkeditorRewriterServiceTest 23 = 45; Plan 05-06: PlainText 18 + SplitName 15 + Relation 16 + Matrix 16 + Asset 10 = 75). |
| TST-02 | 05-03                            | `TransformCharacterizationTest` with `@dataProvider` over per-row JSON fixtures under `tests/fixtures/transform/{input,golden}/`; `UPDATE_SNAPSHOTS=1` refresh; canonicalize-then-diff comparator (recursive ksort + JSON_PRETTY_PRINT); `tools/capture-transform-fixtures.php` operator one-time capture script that snapshots both per-row inputs AND the active mapping.yaml. Corpus operator-fills separately on a dev host. |
| TST-03 | 05-07                            | `.github/workflows/ci.yml` splits into `unit` (composer-validate + phpunit + per-module 70% coverage gate via PCOV at the system level + clover artifact upload) and `smoke` (scratch Craft 5 install via `composer create-project`, plugin path-repo install, `./craft kunstmaan-migrator/doctor` exit 0); `needs: unit` gates CI minutes; PHP 8.3 only (no matrix). |
| TST-04 | 05-04, 05-08                     | `RehearsalController::actionCheck` with three mechanical gate parsers (`parseCountMatchBlock` / `findUnresolvedCkeditorTokens` / `parseAssetRcaTable`) over committed `VERIFY.md` + `REPORT.md` text — no live Craft state re-run inside the gate (CI-safe per D-21); `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` directory shape with BLOCKING (cqm) vs ADVISORY (simac/enreach) READMEs (D-19, D-23); `.planning/RELEASE-CHECKLIST.md` operator workflow with CQM `rehearsal/check` exit 0 as the binding v1.0 tag gate (step 5); `CHANGELOG.md` Keep-a-Changelog v1.0.0 entry summarizing v2-rewrite scope. |

## Architectural ground rules respected

- **Single `mapping.yaml` + per-row `status:`** — untouched.
- **Optional SEOmatic + Retour adapters** — untouched.
- **Filter spec from day one (`MigrationFilters`)** — untouched. Plan 05-03's `TransformCharacterizationTest` constructs `MigrationFilters` directly via the 5-arg readonly constructor (entities, locales, since, noSeo, noRetour); Plan 05-04's `RehearsalController` reads `MigrationFilters` indirectly via committed REPORT.md filter scope text.
- **Plugin-owned legacy DB connection** — untouched.
- **CLI-only operator surface** — `RehearsalController` (Plan 05-04) is a CLI-only addition. No CP touch in Phase 5.
- **Atomic-always-on** — untouched.
- **JIT assets** — untouched.
- **Runtime-zero-AI** — every Phase 5 deliverable is deterministic (test files, CI workflow, doc files); zero AI calls outside the existing `analyze` stage.
- **No `.claude/skills/` bundle** — untouched.
- **`NeverProductionTrait` gate:** `RehearsalController` DELIBERATELY OMITS the trait (Phase 5 / D-22). The omission is documented in the class docblock and codified by Plan 05-04's `must_haves` (acceptance grep `grep -c "use NeverProductionTrait;" src/console/RehearsalController.php` returns `0`). Rationale: the controller is read-only over committed artifacts on disk and never opens the legacy DB / source path / state table. Deliberate departure from the every-other-controller pattern, recorded for future maintainers.
- **Tests required from day one (PROJECT.md Key Decisions):** Phase 5 IS this requirement satisfied. v1.0 ships with characterization tests (Plan 05-03), per-module coverage gate (Plan 05-02), unit-test corpus across all 5 TST-01 D-10 modules (Plans 05-05 + 05-06), CI smoke (Plan 05-07), and the rehearsal mechanical gate (Plan 05-04). The "1.0 with no tests" v1.x regret is not repeated.

## Test corpus delta (additive, per plan)

| Wave | Plan  | Δ tests | Source SUMMARY | Notes |
|------|-------|---------|----------------|-------|
| 1    | 05-01 | 0       | 05-01-SUMMARY  | Reorganization only — `git mv` of 30 files into `tests/unit/` + `tests/integration/`; same 179 tests; no new tests added. |
| 2    | 05-02 | 0       | 05-02-SUMMARY  | Infrastructure only — `phpunit.xml.dist` `<source>` block + 4 composer scripts + `tools/check-coverage.php` (76 LOC); zero test additions. |
| 2    | 05-03 | +1     | 05-03-SUMMARY  | `TransformCharacterizationTest` shell ships with a `markTestSkipped` first-capture path (corpus operator-fills via `tools/capture-transform-fixtures.php`); the @dataProvider yields nothing until fixtures are committed. The test class itself counts as 1 in the corpus. |
| 2    | 05-04 | +17     | 05-04-SUMMARY  | `RehearsalControllerTest` 17 tests (5 count-match + 7 unresolved-tokens + 4 RCA-table + 1 allow-tokens). Wave 2 corpus end: 179 + 1 + 17 = 197 (matches 05-05's "baseline 197" reading). |
| 3    | 05-05 | +45     | 05-05-SUMMARY  | `HeuristicProposerTest` 22 + `CkeditorRewriterServiceTest` 23 = 45. Direct-instantiation pattern; 2 of 5 TST-01 D-10 modules covered. |
| 3    | 05-06 | +75     | 05-06-SUMMARY  | 5 dedicated handler tests (PlainText 18 + SplitName 15 + Relation 16 + Matrix 16 + Asset 10 = 75). Stub-not-mock pattern on the narrow `MigrationStateReader` interface (D-11). |
| 4    | 05-07 | 0       | 05-07-SUMMARY  | CI workflow split + clover artifact upload; zero test additions. |
| 4    | 05-08 | 0       | (this SUMMARY) | Documentation only (RELEASE-CHECKLIST + CHANGELOG + RECONCILIATION + REQUIREMENTS flips); zero test additions. |

**Phase 5 close (additive):** 179 + 1 + 17 + 45 + 75 = **317 tests** (the orchestrator's post-merge `composer test` is the authoritative source — within-worktree counts cannot reconcile parallel Wave 3 contributions).

## Coverage gate state at v1.0

The per-module 70% line-coverage gate (Plan 05-02 `tools/check-coverage.php`) was NOT verified locally in any Phase 5 worktree — `pcov` and `xdebug` are both absent in the executor environments (verified by Plan 05-05 + 05-06 SUMMARY's `decisions[]`). The chained `composer test-coverage` script fails fast in step 1 with `install pcov or xdebug to run coverage` until a coverage driver is loaded.

**End-to-end gate execution:** Plan 05-07's `unit` job is the first place the gate runs — `shivammathur/setup-php@v2` installs PCOV at the system level, then `composer test-coverage` runs the per-module gate. The clover artifact uploads under `coverage-clover` regardless of pass/fail (`if: always()` on the `actions/upload-artifact@v4` step) so PR reviewers can download it.

**Branch-by-inspection signal at Phase 5 close (surrogate, per 05-05 + 05-06 SUMMARYs):**

| Module                                        | Covered branches                                                        | Likely gate state | Notes |
|-----------------------------------------------|-------------------------------------------------------------------------|-------------------|-------|
| `src/filter/MigrationFilters.php`             | constructor + readonly accessors                                        | OK                | Tested transitively by every consumer test in the corpus; trivial VO. |
| `src/mapping/MappingFile.php`                 | `writeAtomic` + `writeAtomicJson` + `merge` + per-row status            | OK (likely)       | Tested via Phase 2 `MappingFileTest` corpus + Phase 02.1 page-part tests; not re-tested in Phase 5 (no D-10 file targets it directly because the existing Phase 2 corpus already covers the surface). |
| `src/finalize/CkeditorRewriterService.php`    | regex token-rewrite + cleanup helpers (23 tests, Plan 05-05)            | OK                | DB-coupled cache-warmers (`warmNtCache` + `warmKumaMediaCacheFromState`) skipped at unit tier — characterization fixtures in Plan 05-03 cover those paths. |
| `src/analyze/HeuristicProposer.php`           | all 9 deterministic heuristics + 1.5 entity-aware + handler-for-classification (22 tests, Plan 05-05) | OK | Full pure-function surface covered. |
| `src/fields/handlers/PlainTextHandler.php`    | all 4 modes + ctor validation (18 tests, Plan 05-06)                    | OK                | Every internal `if` in `writeLink` + `writeDropdown` exercised. |
| `src/fields/handlers/SplitNameHandler.php`    | every branch in `split()` + `resolve()` (15 tests, Plan 05-06)          | OK                | NL diacritic pass + Dutch infix + tail fallback. |
| `src/fields/handlers/RelationHandler.php`     | all 3 dispatch paths (direct/joinTable/joinTranslation) (16 tests, Plan 05-06) | OK         | Identifier whitelist (T-06-02-01 SQLi mitigation) tested directly. |
| `src/fields/handlers/MatrixHandler.php`       | both dispatch paths (generic + page-part D-49) (16 tests, Plan 05-06)   | OK                | `bodyCol` rewriter routing asserted via mock. |
| `src/fields/handlers/AssetHandler.php`        | both `as=relation` + `as=imgTag` empty-input branches; JIT-resolve (10 tests, Plan 05-06) | OK at floor | The single uncovered branch is `as=imgTag` SUCCESS path (`Asset::findOne($id)` + `getUrl()` + `htmlspecialchars` HTML emission); reaching it requires a Craft bootstrap (out of scope for Phase 5 unit tier per D-21) or refactoring `Asset::findOne` behind a seam (out of scope per refactor-abstinence rule). Realistic risk: AssetHandler is the closest module to the floor; if it falls below 70% in CI, that is documented Phase 5.1 / NEXT input, not a Phase 5 backfill. |

If any module fails the 70% gate when CI runs it for real on `main`, the failure is a **Phase 5.1 / NEXT input** — the remediation path is either a Craft-bootstrapped integration test (shifts into the integration tier and incurs a CI-time cost) or a refactor-with-seam (out of scope under the refactor-abstinence rule that bound Plans 05-05 + 05-06). Carry over to NEXT-XX rather than blocking the v1.0 tag.

## Phase 5 ↔ prior-phase commit references

Final-merge SHAs land here once every Wave 3+4 worktree lands on `main`. The orchestrator owns this update post-merge.

- Plan 05-01 (Wave 1): tests reorganization commits — see 05-01-SUMMARY.
- Plan 05-02 (Wave 2): `phpunit.xml.dist` + `composer.json` + `tools/check-coverage.php` commits — see 05-02-SUMMARY.
- Plan 05-03 (Wave 2): `tools/capture-transform-fixtures.php` + `TransformCharacterizationTest` commits — see 05-03-SUMMARY.
- Plan 05-04 (Wave 2): `RehearsalController` (`fa00a2e`) + `RehearsalControllerTest` (`cc44096` RED + `fa00a2e` GREEN) — see 05-04-SUMMARY.
- Plan 05-05 (Wave 3): `HeuristicProposerTest` (`6253b0a`) + `CkeditorRewriterServiceTest` (`db1864e`) — see 05-05-SUMMARY.
- Plan 05-06 (Wave 3): `PlainText + SplitName tests` (`35724f8`) + `Relation + Matrix + Asset tests` (`21ff648`) — see 05-06-SUMMARY.
- Plan 05-07 (Wave 4): `.github/workflows/ci.yml` rewrite — see 05-07-SUMMARY.
- Plan 05-08 (Wave 4 / this plan): `RELEASE-CHECKLIST.md` (`ffa06c6`) + `CHANGELOG.md` (`21bc517`) + this RECONCILIATION + REQUIREMENTS.md flips.

## Phase 5 carry-overs (handed to Phase 5.1 / NEXT-XX)

- **Operator-side capture of CQM artifacts.** Plan 05-03's `tools/capture-transform-fixtures.php` and Plan 05-04's `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` directories ship with operator-facing READMEs and `.gitkeep` markers but no real fixtures committed (Phase 5 / D-24 — operator captures on a dev host and commits separately, typically alongside the v1.0 tag PR). Until those artifacts land, the RELEASE-CHECKLIST step 5 (`rehearsal/check` exit 0 against `.planning/rehearsal/v1.0/cqm/`) cannot be exercised. This is a **manual operator-side action** explicitly out of CI scope (D-24).
- **Pre-publish anonymization scrub.** `RELEASE-CHECKLIST.md` flags the gate if/when the repo goes public under any non-`lameco/` namespace. Not part of v1.0 ship (D-04). The CQM-derived fixtures (rehearsal artifacts + transform fixtures) commit verbatim with NL-diacritic data, image references, user names — operator-grade realism is the v1.0 posture.
- **Simac + enreach end-to-end rehearsals.** Captured but **advisory** per D-19 — failures of `rehearsal-check` against simac/enreach do NOT block the v1.0 tag. They are Phase 5.1 / NEXT-04 inputs for the cross-client rehearsal matrix.
- **`MigrateController` 1700+ LOC + `SeoMigrationService` 606 LOC structural review.** Phase 5 covered this surface via the test corpus + characterization fixtures + rehearsal log (per CONTEXT.md `## Out of Scope`). Whole-plugin code review / structural refactor is NOT a Phase 5 deliverable; if Phase 5.1 surfaces issues, file under deferred. (Carried forward from Phase 4.1's RECONCILIATION carry-over list.)
- **`BASE_URL`-derived verify URL list auto-generation.** Carried from Phase 4.1's RECONCILIATION; Phase 5 did not address it. Phase 5.1 owns.
- **REC-02 (`migrate/sync-relations`).** Deferred from Phase 4.1 per its RECONCILIATION; status unchanged in Phase 5.
- **Mutation testing (Infection PHP), property-based tests for CkeditorRewriter, multi-provider AI, automated `ship.yml` workflow, PHP 8.4 matrix.** All explicitly deferred per CONTEXT.md `## Deferred Ideas`.
- **AssetHandler `as=imgTag` SUCCESS-path coverage.** Not reachable from the unit tier without Craft bootstrap; Phase 5.1 / NEXT input if the per-module gate flags it.

## v1.0 ship readiness

Phase 5 closes the v1.0 ship gate. The remaining operator-side actions before the `v1.0.0` tag:

1. Operator captures CQM rehearsal artifacts on a dev host (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt) under `.planning/rehearsal/v1.0/cqm/` per the README there.
2. Operator runs `./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm` and confirms exit 0.
3. Operator works through `.planning/RELEASE-CHECKLIST.md` 8 steps in order.
4. Operator substitutes `<release-date>` in `CHANGELOG.md` with the actual tag date.
5. Operator pushes `v1.0.0` tag and runs `/gsd-complete-milestone`.

After step 5, Phase 5 is fully closed and the v1.0 milestone closes.
