# Phase 5: Tests, Rehearsal & Release — Context

**Gathered:** 2026-04-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Lock the v1.0 ship gate. Four requirements (TST-01..04) plus the ROADMAP success criterion #4 rehearsal log:

| ID | One-liner |
|---|---|
| TST-01 | PHPUnit unit suite with ≥70% per-module line coverage on `MigrationFilters`, `MappingLoader` (= `MappingFile`), every field handler, `CkeditorRewriterService`, and `HeuristicProposer`; tests live under `tests/unit` + `tests/integration` |
| TST-02 | Transform-stage characterization fixtures captured from a real CQM extract — golden-file-stable across runs |
| TST-03 | CI on `main` runs `composer validate --strict`, `phpunit` (with coverage gate), and a plugin-load smoke test on a scratch Craft 5 install |
| TST-04 | `kunstmaan-migrator/doctor` + the rehearsal smoke check are part of the release checklist; v1.0 ships green against the CQM rehearsal corpus |

The deliverable is the v1.0 ship gate itself: tests in place, CI green, rehearsal log committed, tag pushed. CP-runner / Craft queue / FeedMe-style remap utility stay deferred to Phase 4.2 — Phase 5 does not relitigate them.

</domain>

<decisions>
## Implementation Decisions

### Characterization fixtures (TST-02)

- **D-01:** Fixture INPUT cut at pre-extracted PHP/JSON rows. One-time capture via `ExtractService` against the live CQM dump, snapshot as `tests/fixtures/transform/input/<entity>/<id>.json`. Tests instantiate `TransformService` directly and feed rows in. No DB required at test time. Trade-off accepted: changes to `ExtractService` won't be caught by these characterization tests — those land in the integration tier (the rehearsal-check command, D-23..D-25).
- **D-02:** Corpus is **narrow + per-row goldens**. Cover the 4-5 entity types that exercise the field handlers most: `PageNode`, `NewsPage`, `ContactPage`, plus 1-2 entities with `MatrixHandler` blocks (planner picks from the CQM extract). ~10-15 input rows total. One golden file per row at `tests/fixtures/transform/golden/<entity>/<id>.json`.
- **D-03:** Refresh mechanism is `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`. When set, the test rewrites missing or differing goldens in place; without it, missing goldens fail loudly (no auto-create). The golden diff lands in the same PR as the transform code change so review can sanity-check the byte change.
- **D-04:** No anonymization. CQM is an internal Lameco rehearsal site, this repo is private under `lameco/`, and operator-grade realism (NL diacritics, embedded image references, cross-entry CKEditor tokens) earns its keep. Fixture rows commit verbatim.
- **D-05:** Fixtures live under `tests/fixtures/transform/{input,golden}/`. The capture path is a one-time dev script `tools/capture-transform-fixtures.php` (or a `Dev` console controller — planner picks); not part of the test runner. Re-run against a fresh CQM dump when the corpus needs to grow.

### Coverage tooling + 70% per-module gate (TST-01)

- **D-06:** Coverage driver is **PCOV** in CI. Roughly 2-3× faster than Xdebug coverage mode for coverage-only runs and reduces CI minute spend. Local devs aren't required to install PCOV; `composer test` runs uncovered by default, `composer test-coverage` opts into PCOV.
- **D-07:** Threshold gate lives in `phpunit.xml.dist` via a `<coverage>` block plus a small post-run script that parses clover XML and fails the build if any of the 5 named modules drops below 70% line coverage. Per-module, not aggregate — TST-01 wording is explicit ("70% line coverage on those modules"). Script lives at `tools/check-coverage.php`, invoked by `composer test-coverage`.
- **D-08:** Coverage scope is the 5 modules verbatim from TST-01: `src/filter/MigrationFilters.php`, `src/mapping/MappingFile.php` (= the "MappingLoader" referenced in TST-01 — the project ships one mapping persistence class, not a separate loader), every file under `src/fields/handlers/`, `src/finalize/CkeditorRewriterService.php`, `src/analyze/HeuristicProposer.php`. Other source files are out of the gate; their coverage may rise organically but doesn't block the build.
- **D-09:** clover output writes to `build/coverage/clover.xml`. CI artifact upload is a planner call (Codecov / GitHub artifact) — not load-bearing for the gate itself.

### Filling unit-test gaps

- **D-10:** Direct unit tests per module — one dedicated test file each:
  - `tests/unit/analyze/HeuristicProposerTest.php` (the 9 deterministic heuristics + confidence routing)
  - `tests/unit/finalize/CkeditorRewriterServiceTest.php` (deferred token resolution + finalize-pass loop)
  - `tests/unit/fields/handlers/PlainTextHandlerTest.php`
  - `tests/unit/fields/handlers/SplitNameHandlerTest.php`
  - `tests/unit/fields/handlers/RelationHandlerTest.php`
  - `tests/unit/fields/handlers/MatrixHandlerTest.php`
  - `tests/unit/fields/handlers/AssetHandlerTest.php`
  - `tests/unit/mapping/MappingFileTest.php` already exists — top up if it's under 70%.
- **D-11:** Each new test file isolates its module with mocks/stubs; they do NOT depend on the Transform characterization fixtures (the fixtures are a separate signal, not the unit-coverage source). `MigrationFilters` already has direct tests — only top up if PCOV reports under 70%.

### Test directory layout (TST-01)

- **D-12:** Reorganize. Bootstrap (`tests/bootstrap.php`) stays at `tests/bootstrap.php`. Move every existing `tests/<area>/` subdir to `tests/unit/<area>/` (`git mv tests/verify/ tests/unit/verify/` and so on). Top-level test files split deliberately:
  - `tests/PluginBootstrapTest.php` → `tests/integration/PluginBootstrapTest.php` (it boots the plugin via Craft kernel — that's integration-tier; it also serves as the scaffolding template for the smoke job).
  - `tests/ComposerSuggestTest.php` → `tests/unit/ComposerSuggestTest.php` (pure-static composer.json assertion, no Craft boot).
  - `tests/NeverProductionTraitTest.php` → `tests/unit/NeverProductionTraitTest.php` (trait behavior in isolation).
  New `tests/integration/` also holds the rehearsal-tier integration test (if any) and the future smoke-equivalent.
- **D-13:** `phpunit.xml.dist` gains two `<testsuite>` blocks: `Unit` (`tests/unit`) and `Integration` (`tests/integration`). Default `composer test` runs both; `composer test-unit` and `composer test-integration` run them individually.
- **D-14:** This move touches every existing test path. Land it as its own plan early in Phase 5 so subsequent test-adding plans (D-10) write into the new layout natively. No characterization plans should land before the move.

### CI plugin-load smoke (TST-03)

- **D-15:** Bootstrap via `composer create-project craftcms/craft scratch-craft` in CI, register this repo as a path-type composer repository in the scratch site, then `composer require lameco/craft-kunstmaan-migrator @dev`. Pure composer, no Docker dependency, mirrors a real consumer-site install. The CI step is a separate job (`smoke`) downstream of the unit job in `.github/workflows/ci.yml`.
- **D-16:** PHP matrix: 8.3 only. Floor in `composer.json` is `^8.3` and the rehearsal targets (cqm/simac/enreach) all run 8.3+ — adding 8.4 doubles CI minutes for marginal signal until a host actually runs 8.4.
- **D-17:** Smoke assertion: `./craft kunstmaan-migrator/doctor` exits 0. Doctor runs every Yii Component DI + every adapter presence check, so a non-zero exit means the plugin failed to boot or a check went FAIL. (The doctor severity model treats WARN as exit 0; that's correct here — the smoke is a "did it boot" gate, not a "is the host configured" gate. A host with no `KUNSTMAAN_SOURCE_PATH` set will WARN, not FAIL.)
- **D-18:** Existing `.github/workflows/ci.yml` (composer validate + composer test) is the unit job. Smoke job is added as a second job; both run on push + PR. Composer validate stays in the unit job's first step.

### Rehearsal log + mechanical gate (TST-04)

- **D-19:** Rehearsal scope: **CQM blocking + simac/enreach advisory**. v1.0 ship gate is CQM green. Simac + enreach rehearsals run too and their logs commit, but failures there don't block the tag — they become Phase 5.1 / NEXT-04 inputs. Best-of-both: smoke-tests the wider matrix without making it a blocker.
- **D-20:** Rehearsal artifacts commit under `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` per dump. Each directory carries the operator-captured copies of the canonical run outputs:
  - `REPORT.md` — copied from `storage/migration/REPORT.md` after `migrate --live` (the rehearsal summary + skipped stages + asset RCA sections per CFG-03 / CFG-07).
  - `VERIFY.md` — copied from `storage/migration/VERIFY-<ts>.md` after `verify` (markdown report; the count-match gate + URL diff gate output that VerifyController writes via `MappingFile::writeAtomic` at `src/console/VerifyController.php:265`). Operator drops the timestamp suffix when committing — `VERIFY.md` is the canonical name in the rehearsal directory.
  - `baseline.json` — copied from `storage/migration/baseline.json` (light entity-count snapshot from `verify capture-baseline`). Required because `rehearsal-check` re-derives the count deltas instead of trusting the committed `VERIFY.md`'s rendered numbers.
  - `doctor-output.txt` — captured stdout/stderr of `kunstmaan-migrator/doctor` against the dump.
  - `mapping-summary.txt` — counts of `accepted` / `dropped` / `needs-review` / `proposed` rows from the dump's `mapping.yaml`. Generated by a small operator-side script (planner can ship a `mapping-status-counts` helper if it earns its keep).
- **D-21:** New CLI `kunstmaan-migrator/rehearsal-check <dir>` post-processes a committed rehearsal directory and asserts the three ROADMAP success criterion #4 gates mechanically:
  - **Counts within tolerance:** reads `<dir>/baseline.json` (entity-count snapshot) and re-runs the count-match gate logic from `CountGateService::run()` against the current Craft state when invoked locally; or, when invoked in CI against committed-only artifacts, parses the `[1/2] Count-match gate` block out of `<dir>/VERIFY.md` and asserts every line is `OK` or `SKIP` (no `WARN` / `FAIL`). Tolerance comes from `Settings::verifyCountTolerance`. Planner picks one mode (CI-only) or both (local + CI) depending on how `rehearsal-check` is wired.
  - **Zero unresolved CKEditor tokens:** greps `<dir>/REPORT.md` for `[NT<id>]`, `[M<id>]`, `asset:<n>` patterns; fails on any hit not listed in an explicit `<dir>/allow-tokens.txt` allow-list.
  - **All assets RCA-tagged:** parses the `## Asset RCA` table from `<dir>/REPORT.md`; asserts every row has a non-empty reason column. CFG-07 already guarantees the section emits even when empty.
- **D-22:** `rehearsal-check` does NOT carry `NeverProductionTrait`. The command is read-only over committed artifacts on disk — it never opens the legacy DB connection, never reads `KUNSTMAAN_SOURCE_PATH`, never touches `kunstmaanmigrator_state`. Applying the trait would gate a CI step on `CRAFT_ENVIRONMENT != production` for no purposeful reason. Deliberate departure from the "every CLI carries the trait" pattern; documented here so a future maintainer doesn't reflexively add it.
- **D-23:** `rehearsal-check` exits 0 when all three gates pass, non-zero with a structured per-gate failure summary otherwise. The CQM directory's `rehearsal-check` exit is the v1.0 tag gate (release checklist); simac/enreach exits are advisory only.
- **D-24:** Rehearsal capture is a manual operator step (run `migrate --live` against the dump on a dev host, then commit the artifacts). Phase 5 does NOT automate the rehearsal run itself; that's a NEXT-04 cross-client matrix concern. Phase 5 adds the artifact directory shape + the mechanical check command on top of artifacts the operator captures by hand.

### Release checklist artifact (TST-04)

- **D-25:** `.planning/RELEASE-CHECKLIST.md` is the canonical pre-tag gate. Lists every step required before a v1.0 tag:
  1. `composer validate --strict` green
  2. `composer test` green (unit + integration suites)
  3. `composer test-coverage` green (every TST-01 module ≥70%)
  4. CI smoke job green on a recent commit
  5. CQM `rehearsal-check` exits 0 against `.planning/rehearsal/v1.0/cqm/`
  6. Simac + enreach rehearsal logs captured under `.planning/rehearsal/v1.0/{simac,enreach}/` (advisory; failures don't block)
  7. `CHANGELOG.md` rewritten for v1.0
  8. `composer.json` version bumped to `1.0.0` (only if Lameco's release process pins versions in composer.json — most Craft plugins don't; planner verifies the convention used by other Lameco plugins before adding this step)
  9. Tag pushed; STATE.md updated; milestone closed via `/gsd-complete-milestone`
- **D-26:** No `ship.yml` GitHub workflow in Phase 5. The checklist is operator-driven for v1.0; if shipping cycles get frequent enough that automation earns its keep, it's a follow-up phase. Manual-but-mechanical (every step has a pass/fail script behind it) is the right shape for a single-tag-per-quarter cadence.

### Claude's discretion

- Exact entity selection for the narrow corpus (D-02) — planner picks the 4-5 entity types from the CQM extract that maximize field-handler coverage.
- `tools/capture-transform-fixtures.php` shape — script vs `Dev` console controller (D-05).
- `tools/check-coverage.php` shape — pure PHP parsing the clover XML, ~50 LOC. Planner can pull a tiny existing library if it's lighter than rolling.
- Exact `tests/integration/` test layout for the smoke job (D-15) — single test file vs split per concern.
- Whether `rehearsal-check` lives in `src/console/RehearsalController.php` (new controller) or as another action on `MigrateController`. Planner decides.
- The CHANGELOG.md format for v1.0 (Keep-a-Changelog vs Lameco convention) — planner verifies what other Lameco plugins ship with.

</decisions>

<specifics>
## Specific Ideas

- **TST-01 wording reconciled:** TST-01 names "MappingLoader" but the project ships `src/mapping/MappingFile.php` as the single mapping persistence class (Phase 2 / D-07). Treat them as synonyms; coverage gate is on `MappingFile`.
- **Doctor as smoke signal (D-17):** Doctor was deepened in Phase 4.1 (9th and 10th checks for env source + locale Rung 0). A doctor that exits 0 in CI means env reader DI'd, locale preflight DI'd, every adapter presence check DI'd. It's the most plugin-aware boot signal we have without running an actual `migrate --dry-run`.
- **CQM verbatim fixtures rationale (D-04):** The decision is conditional on the repo staying private. If the repo ever goes public, fixtures need a scrub pass — flag in RELEASE-CHECKLIST.md as a pre-publish gate (out of scope for v1.0 tag itself, since v1.0 stays under `lameco/`).
- **Rehearsal log committed per ship (D-20):** The `.planning/rehearsal/v1.0/` directory is for the v1.0 tag specifically. v1.0.1 / v1.1 ship cycles get their own subdirectories (`v1.0.1/`, `v1.1/`). Don't overwrite — historical rehearsal logs are part of the audit trail.
- **No `migrate --live` automation:** Phase 5 explicitly does NOT automate "run migrate against a live CQM dump in CI". The operator runs it on a dev host with a real Kunstmaan dump and commits the artifacts. CI verifies the artifacts via `rehearsal-check`. This separation is load-bearing for `NeverProductionTrait` semantics — CI never has a Kunstmaan source path or a Craft consumer site, only the plugin code.
- **Coverage scope is intentionally narrow (D-08):** The TST-01 5-module list is the floor. Other modules (e.g. `MigrateController` at 1189 LOC, `SeoMigrationService` at 606 LOC) accumulating coverage organically from the existing 179-test corpus is fine, but their coverage doesn't gate the build — the named modules do.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-level
- `.planning/PROJECT.md` — Locked Key Decisions. "Tests required from day one" (last row of Key Decisions table) is what TST-01..04 satisfy. "Page-driven migration" decision shapes what the rehearsal-check expects (orphan media is intentional, not a failure).
- `.planning/REQUIREMENTS.md` lines 119-124 — TST-01..04 verbatim. Re-read for the canonical statement.
- `.planning/ROADMAP.md` lines 255-265 — Phase 5 success criteria, including the rehearsal-log success criterion #4 that drives D-19..D-24.

### Prior phase outputs (artifacts Phase 5 builds on)
- `.planning/phases/04.1-polish-recovery-and-env-defaults/04.1-CONTEXT.md` D-41..D-43 — REPORT.md `## Skipped stages` + `## Asset RCA` always emit (CFG-07). The mechanical gate D-21 in this phase relies on the placeholder copy contract.
- `.planning/phases/04-adapters-verify-settings/04-CONTEXT.md` D-68 — REPORT.md three new sections (rehearsal summary, skipped stages, asset RCA). The rehearsal-log artifact directory (D-20) snapshots this exact REPORT.md.
- `.planning/phases/04-adapters-verify-settings/04-12-tests-and-reconciliation-SUMMARY.md` — Phase 4 test corpus growth pattern (60→83). Phase 5 follows the same characterization-at-boundaries shape.
- `.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md` — Transform stage decisions; the fixture INPUT/GOLDEN contract (D-01, D-02) preserves whatever input/output shape Phase 3 settled on.

### Existing test scaffolding
- `tests/bootstrap.php` — current test bootstrap. Reused by both unit + integration suites after the D-12 reorganization.
- `phpunit.xml.dist` — current single-suite config. D-13 extends this to two suites + a `<coverage>` block.
- `.github/workflows/ci.yml` — current CI (composer validate + composer test on PHP 8.3). D-15..D-18 extend this with a smoke job.

### Reference Kunstmaan dumps (for rehearsal D-19..D-21)
- `~/Sites/cqm-website/` — v1.0 ship-gate target. The rehearsal-check exit code on `.planning/rehearsal/v1.0/cqm/` is the gate.
- `~/Sites/simac-website/` — multi-locale advisory rehearsal target.
- `~/Sites/enreach-website/` — 7-locale stress advisory rehearsal target.

### Brownfield reference (for the "v1 had no tests" lesson)
- `~/Sites/craft-kunstmaan-migrator/` — v1.x. Shipped 1.0 with no test suite ("deliberately skipped"). Phase 5 is the deliberate counter-decision.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `tests/bootstrap.php` — current bootstrap for the 179-test corpus. Stays at `tests/bootstrap.php` after D-12 reorganization; both `Unit` and `Integration` testsuites point to it.
- `phpunit.xml.dist` — current single-testsuite config. D-13 extends with a second `<testsuite>` and a `<coverage>` block; existing `bootstrap`/`testdox`/`cacheDirectory` settings stay.
- `.github/workflows/ci.yml` (15 lines, single `test` job) — D-18 splits this into `unit` + `smoke` jobs; existing composer-validate step stays as the unit job's first step.
- `tests/PluginBootstrapTest.php` — partial scaffolding for Craft plugin boot in tests. The `tests/integration/` smoke job (D-15) can mirror this pattern for the scratch-Craft install assertion if D-15's `composer create-project` approach is extended into PHPUnit instead of being CI-step-only (planner call).
- `MappingFile::writeAtomic` (`src/mapping/MappingFile.php`) — used by every operator-facing artifact write. The new `rehearsal-check` command's failure summary (when one or more gates fail) writes via this primitive if it persists output; otherwise it just streams to stdout.
- `Settings::verifyCountTolerance` + `Settings::verifyUrlDiffThreshold` — already present (Phase 4 / Plan 04). The mechanical gate D-21 reads these for the count-within-tolerance assertion.
- `MigrationFilters` (`src/filter/MigrationFilters.php`) — already direct-tested (`tests/filter/MigrationFiltersTest.php`). Top-up only if PCOV reports under 70% post-D-10.
- `NeverProductionTrait` (`src/NeverProductionTrait.php`) — applied to every legacy-reading CLI. `rehearsal-check` (D-22) is read-only but still consumes legacy artifacts (the committed REPORT.md), so the trait applies for consistency.

### Patterns Already Established
- **Characterization tests at boundaries** (Phase 4 / Plan 12) — assert shape over byte-equality where the underlying data has timestamps / random IDs. Apply to the goldens (D-01..D-03): the comparator should normalize generated-at timestamps and similar non-deterministic fields before diff. (See `SnapshotDiffer` in Phase 4 verify code for the established normalization style.)
- **Doctor severity in prefix** (Phase 4 / Plan 11 / D-69) — doctor returns 0 even on WARN; only FAIL exits non-zero. D-17's smoke assertion accepts WARN, fails on FAIL. This is correct.
- **REPORT.md sections always emit** (Phase 4.1 / D-41..D-43, CFG-07) — the rehearsal-check D-21 grep relies on `## Asset RCA` always being present (with placeholder copy when empty). Without this contract, D-21's "asset RCA all rows have reasons" gate would need an "or section absent" branch; CFG-07 made the contract clean.
- **Yii Component + sibling-DI in Plugin::config()** — every new service follows this. `rehearsal-check` if implemented as a service registers the same way.

### Risks
- **Coverage drift on currently-passing modules.** Adding the per-module 70% gate may reveal that one of the 5 named modules sits at, say, 62% on the existing 179-test corpus. The D-10 direct-test additions are sized to comfortably exceed 70%, but planner should run PCOV BEFORE picking the test breakdown — bias the gap-fill toward the modules that need it most.
- **Reorganization-shaped merge churn.** D-12's `git mv tests/<area>/ tests/unit/<area>/` rewrites every existing test path. If any in-flight branch has new tests under the old layout, it merges painfully. Land D-12 as the FIRST plan in Phase 5; do not interleave with feature-test-writing plans on the same branch.
- **Fixture corpus fragility.** D-01's per-row goldens are byte-comparison-style. Any "harmless" change to TransformService output format (e.g. JSON key reordering, whitespace) breaks every golden. The comparator (D-stash above) needs to canonicalize JSON before diff or the goldens become a maintenance tax. Planner should pin a JSON-canonicalize step into the comparator.
- **CQM dump access in CI.** D-15..D-18 explicitly do NOT need the CQM dump in CI (smoke is a doctor-only boot signal; rehearsal runs locally). If a future plan slips toward "run migrate against CQM in CI" the privacy / NeverProductionTrait calculus changes — flag any such drift against D-24.
- **Rehearsal-check command scope creep.** The command is read-only and post-processes committed artifacts (D-22). Resist any instinct to make it run `verify` or `doctor` itself — that breaks the "CI verifies committed artifacts, operator runs the migration" separation that keeps `NeverProductionTrait` semantics clean.
- **PCOV vs Xdebug on local dev.** D-06 says PCOV in CI; locals can use whichever they have. If a developer runs `composer test-coverage` locally with Xdebug, the clover output should be identical (both are PHPUnit-driven). If they're not on either driver, `composer test-coverage` should fail-fast with "install pcov or xdebug to run coverage" rather than silently producing wrong numbers.
- **`composer.json` version field convention.** D-25 step 8 is conditional. Most Craft plugins don't set `version` in composer.json — Composer derives it from the git tag. Planner should verify Lameco's other Craft plugins before adding this step.

</code_context>

<out_of_scope>
## Out of Scope (Explicit)

- **Full simac / enreach ship-gating.** D-19 makes them advisory for v1.0. They become Phase 5.1 / NEXT-04 if cross-client correctness blocks adoption.
- **Automated rehearsal runs in CI.** D-24. Rehearsal capture stays manual; CI verifies committed artifacts via `rehearsal-check`. Reversing this is out of v1.0 scope.
- **GitHub Actions release workflow (`ship.yml`).** D-26. Manual operator-driven tag for v1.0. Re-evaluate post-v1.1 if cadence demands it.
- **CP-runner / Craft queue / FeedMe-style remap utility.** Carried over from Phase 4.1 out-of-scope. Phase 4.2 if pursued.
- **Codecov / coverage-report publishing.** Optional, planner-discretion (D-09). Not load-bearing for the TST-01 gate.
- **Multi-PHP matrix.** D-16. Single 8.3 job for v1.0. Add 8.4 when a target host actually runs it.
- **Anonymization / public-fixture readiness.** D-04. Conditional on repo privacy. If publication ever happens, scrub gate is a pre-publish concern, not a v1.0 ship gate.
- **Whole-plugin code review / scope reconsideration** (1189-LOC `MigrateController`, 606-LOC `SeoMigrationService`) — already noted in Phase 4.1 out-of-scope as Phase 5 work. We're treating "code review" as covered by the test corpus + characterization fixtures + rehearsal log; structural refactor is NOT a Phase 5 deliverable. If the rehearsal exposes structural issues that block ship, file under deferred.
- **`BASE_URL`-derived verify URL list auto-generation** — flagged in Phase 4.1 as Phase 5 work but explicitly deferred here too (depends on rehearsal data shape; cleaner as a Phase 5.1 / NEXT-04 input).

</out_of_scope>

<deferred_ideas>
## Deferred Ideas

- **Mutation testing (Infection PHP).** Once line coverage is at 70%+, mutation coverage is the next quality signal. Stand-alone phase if pursued.
- **Cross-client rehearsal matrix automation.** NEXT-04. CI orchestrates rehearsals against simac + enreach + (future) joulz dumps as part of every release candidate.
- **`ship.yml` GitHub Actions workflow.** Manual tag is right for v1.0 cadence. Automate when shipping more frequently than quarterly.
- **PHP 8.4 matrix.** Add when a Lameco host runs 8.4 in production.
- **Codecov / public coverage badge.** Defer until repo is public (which is itself out of v1.0 scope).
- **`migrate --live` CI automation.** Would require CQM credentials in CI + relaxing `NeverProductionTrait` for the CI environment. Both are non-trivial; not worth it for v1.0.
- **Property-based / fuzz tests for `CkeditorRewriterService`.** The token-rewrite logic is a natural fit for property-based testing (any `[NT<id>]` resolves to either a Craft entry URI or a known-fail marker, never a half-resolved string). Stand-alone follow-up phase.
- **Public fixture scrub script.** Pre-publish gate if/when the repo goes public.

</deferred_ideas>
