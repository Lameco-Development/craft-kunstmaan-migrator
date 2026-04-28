---
phase: 05
plan: 08
type: execute
wave: 4
depends_on: ["05-03", "05-04", "05-05", "05-06", "05-07"]
files_modified:
  - .planning/RELEASE-CHECKLIST.md
  - CHANGELOG.md
  - .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md
  - .planning/REQUIREMENTS.md
autonomous: true
requirements: [TST-04]
must_haves:
  truths:
    - ".planning/RELEASE-CHECKLIST.md exists with the 8 mandatory pre-tag steps from D-25 (step 8 — composer.json version bump — is OMITTED because Lameco convention does not pin versions in composer.json; verified against ~/Sites/craft-kunstmaan-migrator/composer.json + ~/Sites/craft-seo-import/composer.json + ~/Sites/craft-entry-optimizer/composer.json)"
    - "CHANGELOG.md exists at repo root in Keep-a-Changelog format (verified Lameco convention: ~/Sites/craft-kunstmaan-migrator/CHANGELOG.md uses Keep-a-Changelog with 'All notable changes...' header)"
    - ".planning/phases/05-tests-rehearsal-release/RECONCILIATION.md aggregates the four TST requirement closures (TST-01, TST-02, TST-03, TST-04)"
    - ".planning/REQUIREMENTS.md has all four TST IDs (TST-01..04) flipped from [ ] to [x] with phase + plan reference italicized"
    - "RELEASE-CHECKLIST.md step 5 references CQM rehearsal-check exit 0 against .planning/rehearsal/v1.0/cqm/ (the v1.0 ship gate — D-19, D-23)"
    - "RELEASE-CHECKLIST.md flags pre-publish anonymization gate (NOT part of v1.0 ship; conditional on repo going public — D-04)"
    - "No ship.yml workflow created (D-26)"
    - "RECONCILIATION.md cross-references Phase 5 plans 01-07 outcomes; documents any TST module that finished below 70% with rationale + Phase 5.1/NEXT input"
    - "CHANGELOG.md v1.0.0 entry covers the major capabilities introduced by the v2 rewrite vs v1.x: single mapping.yaml + per-row status, optional SEOmatic/Retour adapters, MigrationFilters, plugin-owned legacy DB, CLI-only operator surface, atomic-always-on, JIT assets, runtime-zero-AI"
  artifacts:
    - path: ".planning/RELEASE-CHECKLIST.md"
      provides: "Operator-driven pre-tag gate; 8 numbered steps; CQM rehearsal-check exit 0 is the binding gate"
      contains: "rehearsal-check"
    - path: "CHANGELOG.md"
      provides: "Keep-a-Changelog format; v1.0.0 entry summarizing the v2 rewrite scope"
      contains: "Keep a Changelog"
    - path: ".planning/phases/05-tests-rehearsal-release/RECONCILIATION.md"
      provides: "Phase 5 closure: every TST requirement closed; cross-plan outcome aggregation; carry-overs to Phase 5.1/NEXT"
      contains: "TST-01"
    - path: ".planning/REQUIREMENTS.md"
      provides: "TST-01..04 flipped to [x] with phase + plan italicized references"
      contains: "TST-01"
  key_links:
    - from: ".planning/RELEASE-CHECKLIST.md"
      to: "src/console/RehearsalController.php (Plan 05-04)"
      via: "step 5: kunstmaan-migrator/rehearsal/check exit 0 against .planning/rehearsal/v1.0/cqm/"
      pattern: "rehearsal-check"
    - from: ".planning/RELEASE-CHECKLIST.md"
      to: "composer scripts (Plan 05-02)"
      via: "steps 1-3: composer validate --strict, composer test, composer test-coverage"
      pattern: "composer test-coverage"
    - from: ".planning/RELEASE-CHECKLIST.md"
      to: ".github/workflows/ci.yml (Plan 05-07)"
      via: "step 4: CI smoke green on recent commit"
      pattern: "CI smoke"
    - from: ".planning/REQUIREMENTS.md"
      to: ".planning/phases/05-tests-rehearsal-release/RECONCILIATION.md"
      via: "italicized phase reference per flipped TST ID"
      pattern: "Phase 5"
---

<objective>
**TST-04 / D-25, D-26 — Phase 5 closure artifacts.**

Four documents land in this final plan, in Wave 4 because they aggregate outcomes from every prior Phase 5 plan:

1. **`.planning/RELEASE-CHECKLIST.md`** — operator-driven pre-tag gate. 8 numbered steps (D-25 originally listed 9; step 8 — `composer.json` version bump — is verified-against Lameco convention and OMITTED because none of the three Lameco Craft plugins pin a `version` field in composer.json; Composer derives the version from the git tag).

2. **`CHANGELOG.md`** — root-level Keep-a-Changelog file. Format verified against `~/Sites/craft-kunstmaan-migrator/CHANGELOG.md` (Lameco's existing v1.x changelog uses Keep-a-Changelog 1.1.0 + SemVer). The v1.0.0 entry summarizes the major-rewrite scope.

3. **`.planning/phases/05-tests-rehearsal-release/RECONCILIATION.md`** — Phase 5 closure document. Cross-references plans 01-07 outcomes; aggregates TST-01..04 closures; documents any module that finished below the 70% gate (with rationale + Phase 5.1/NEXT input); flags carry-overs.

4. **`.planning/REQUIREMENTS.md`** — TST-01..04 flipped from `[ ]` to `[x]` with italicized phase + plan references mirroring Phase 4.1's pattern.

**No `ship.yml` (D-26).** v1.0 ships via manual operator-driven tag. The checklist is the gate.

**Lameco CHANGELOG convention** (verified pre-planning):
- `~/Sites/craft-kunstmaan-migrator/CHANGELOG.md` exists; uses Keep-a-Changelog 1.1.0 + SemVer header. Format: `## X.Y.Z — YYYY-MM-DD` followed by ### Breaking Changes / ### Added / ### Changed / ### Fixed sections.
- None of the three Lameco Craft plugins inspected (`craft-kunstmaan-migrator`, `craft-seo-import`, `craft-entry-optimizer`) pin a `version` field in their composer.json; Composer derives from git tags. Therefore D-25 step 8 is OMITTED (the conditional "only if Lameco's release process pins versions" branch resolved to NO).

**Why Wave 4:** depends on outcomes from 05-03 (TransformCharacterizationTest), 05-04 (RehearsalController), 05-05 + 05-06 (TST-01 coverage gate state), 05-07 (CI green). Cannot land before those plans' SUMMARYs exist, because RECONCILIATION cites them and RELEASE-CHECKLIST references their artifacts.

Output:
- 4 documents committed
- TST-01..04 closed in REQUIREMENTS.md
- v1.0 ship-gate operator workflow defined
- Phase 5 closure documented; ready for `/gsd-complete-milestone` once operator captures CQM artifacts and runs the checklist
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/05-tests-rehearsal-release/05-CONTEXT.md
@.planning/phases/05-tests-rehearsal-release/05-PATTERNS.md
@.planning/phases/05-tests-rehearsal-release/05-01-SUMMARY.md
@.planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md
@.planning/phases/05-tests-rehearsal-release/05-03-SUMMARY.md
@.planning/phases/05-tests-rehearsal-release/05-04-SUMMARY.md
@.planning/phases/05-tests-rehearsal-release/05-05-SUMMARY.md
@.planning/phases/05-tests-rehearsal-release/05-06-SUMMARY.md
@.planning/phases/05-tests-rehearsal-release/05-07-SUMMARY.md
@.planning/phases/04.1-polish-recovery-and-env-defaults/RECONCILIATION.md
@CLAUDE.md

<interfaces>
Lameco CHANGELOG convention (verified pre-planning at ~/Sites/craft-kunstmaan-migrator/CHANGELOG.md):
```markdown
# Changelog

All notable changes to `lameco/craft-kunstmaan-migrator` are documented in this
file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 — 2026-04-22

### Breaking Changes
- ...

### Added
- ...
```

REQUIREMENTS.md flipping pattern (verified at .planning/REQUIREMENTS.md lines 116-117 — Phase 4.1 closure pattern):
```markdown
- [x] **REC-01**: <verbatim text> _(Phase 4.1 / Plan 04.1-07 — <one-line outcome summary>.)_
```

Phase 4.1 RECONCILIATION shape (verified at .planning/phases/04.1-polish-recovery-and-env-defaults/RECONCILIATION.md):
- H1 + Date + Plans shipped + Test corpus delta header
- Section: Retired plan-NN-MM acceptance greps (per CONTEXT D-XX) — only when applicable
- Section: Requirements closed in Phase X.Y — table mapping ID → Plan → one-line outcome
- Section: Architectural ground rules respected — bullet list confirming no decisions reversed
- Section: Phase X ↔ Phase X.Y commit references — git SHAs cited
- Section: Phase X+1 carry-overs — what's deferred / what the next phase owns

This plan mirrors that shape for Phase 5.
</interfaces>

<reference_files>
- ~/Sites/craft-kunstmaan-migrator/CHANGELOG.md (verified pre-planning — Keep-a-Changelog format with SemVer)
- ~/Sites/craft-kunstmaan-migrator/composer.json (verified pre-planning — no `version` field; Composer derives from git tag)
- ~/Sites/craft-seo-import/composer.json (verified pre-planning — no `version` field)
- ~/Sites/craft-entry-optimizer/composer.json (verified pre-planning — no `version` field)
- .planning/phases/04.1-polish-recovery-and-env-defaults/RECONCILIATION.md — RECONCILIATION shape template
- .planning/REQUIREMENTS.md (lines 116-117 + 121-124) — REQUIREMENTS.md flipping pattern + the four TST rows verbatim
- .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: .planning/RELEASE-CHECKLIST.md section, lines 666-695; NEW: CHANGELOG.md section, lines 714-740)
</reference_files>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Write .planning/RELEASE-CHECKLIST.md (8 steps; D-25 with step 8 OMITTED per Lameco convention)</name>
  <files>
    .planning/RELEASE-CHECKLIST.md
  </files>
  <read_first>
    - .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-25 — the 9-step list verbatim; step 8 conditional)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (NEW: .planning/RELEASE-CHECKLIST.md section, lines 666-695)
    - .planning/phases/05-tests-rehearsal-release/05-07-SUMMARY.md (CI shape from Plan 05-07; step 4 references this)
    - .planning/phases/05-tests-rehearsal-release/05-04-SUMMARY.md (rehearsal-check command from Plan 05-04; step 5 references this)
    - .planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md (composer scripts from Plan 05-02; steps 1-3 reference these)
    - .planning/phases/05-tests-rehearsal-release/05-03-SUMMARY.md (operator-side fixture capture; flagged as a v1.0 ship-gate dependency)
  </read_first>
  <action>
    Write `.planning/RELEASE-CHECKLIST.md` with the verified-against-Lameco-convention 8-step list. The original D-25 numbered 9 steps; the 8th was conditional ("only if Lameco's release process pins versions in composer.json"). PRE-PLANNING VERIFICATION: `~/Sites/craft-kunstmaan-migrator/composer.json` (Lameco's own v1.x reference plugin) does NOT pin a `version` field; nor do `~/Sites/craft-seo-import/composer.json` or `~/Sites/craft-entry-optimizer/composer.json`. Composer derives version from the git tag. Therefore the conditional resolves to NO; step 8 is OMITTED.

    Use this exact content:

    ```markdown
    # v1.0 Release Checklist

    **Pre-tag gate.** Every step must be green before pushing the v1.0 tag.

    Manual + mechanical: every step has a pass/fail script behind it; no automated `ship.yml` workflow (Phase 5 / D-26 — re-evaluate post-v1.1 if shipping cadence demands it).

    ## Steps

    1. [ ] **`composer validate --strict --no-plugins`** green.
       _Pass criterion:_ exit 0; output ends with `composer.json is valid`.

    2. [ ] **`composer test`** green (Unit + Integration suites).
       _Pass criterion:_ exit 0; trailing `OK (N tests, M assertions)` line.

    3. [ ] **`composer test-coverage`** green (per-module 70% line-coverage gate on every TST-01 module).
       _Pass criterion:_ exit 0; per-module table reports `OK` on every line; final line `Coverage gate OK — all modules ≥ 70%`.
       _Driver requirement:_ pcov OR xdebug installed locally (operator side); CI uses pcov via shivammathur/setup-php.

    4. [ ] **CI smoke job green** on a recent commit (HEAD-of-main or the v1.0 release commit).
       _Pass criterion:_ both `unit` and `smoke` jobs pass on `.github/workflows/ci.yml`. Verify via `gh run list --workflow=ci.yml --limit 1` or the GitHub UI.

    5. [ ] **CQM `kunstmaan-migrator/rehearsal/check`** exits 0 against `.planning/rehearsal/v1.0/cqm/`.
       _Pre-requisite:_ operator has captured CQM rehearsal artifacts (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt) under that directory per `.planning/rehearsal/v1.0/cqm/README.md`.
       _Pass criterion:_ `./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm` exits 0; trailing line `All three rehearsal gates passed.`
       _This is the binding v1.0 ship gate (Phase 5 / D-19, D-23)._

    6. [ ] **Simac + enreach rehearsal logs captured** under `.planning/rehearsal/v1.0/{simac,enreach}/`.
       _Pass criterion:_ both directories contain the 5 required artifact files. Failures of `rehearsal-check` against simac/enreach do NOT block the v1.0 tag — they are Phase 5.1 / NEXT-04 inputs (Phase 5 / D-19).

    7. [ ] **`CHANGELOG.md` rewritten for v1.0.**
       _Pass criterion:_ `## 1.0.0 — <date>` heading present at the top of the unreleased / latest entry; Breaking Changes / Added sections describe v2-vs-v1.x scope.

    8. [ ] **Tag pushed; `STATE.md` updated; milestone closed via `/gsd-complete-milestone`.**
       _Pass criterion:_ `git tag v1.0.0` pushed to origin; `.planning/STATE.md` reflects "v1.0 milestone closed"; `/gsd-complete-milestone` ran.

    ## Composer.json `version` field — INTENTIONALLY NOT REQUIRED

    Phase 5 / D-25 originally listed a step 8 conditional on Lameco's release-process convention. Pre-planning verification:

    - `~/Sites/craft-kunstmaan-migrator/composer.json` (v1.x reference plugin) — no `version` field
    - `~/Sites/craft-seo-import/composer.json` — no `version` field
    - `~/Sites/craft-entry-optimizer/composer.json` — no `version` field

    Composer derives the version from the git tag (`v1.0.0` → `1.0.0`); the `extra.schemaVersion` Craft uses for plugin migrations is separate and unrelated to release version. The conditional therefore resolves to "do not add a version step." This decision is recorded here so a future plugin maintainer can see the rationale.

    ## Pre-publish gate — NOT part of v1.0 ship

    The repo currently lives under `lameco/` and is private. CQM rehearsal fixtures (`.planning/rehearsal/v1.0/cqm/REPORT.md` etc., and `tests/fixtures/transform/input/*.json`) commit verbatim with NL-diacritic data, image references, user names — operator-grade realism per Phase 5 / D-04.

    **If/when** the repo goes public under any non-`lameco/` namespace:

    - Anonymize all CQM-derived fixtures (rehearsal artifacts + transform fixtures) via a scrub pass before publishing
    - Verify no embedded credentials / API keys / private URLs in any committed file
    - Re-run `composer test` after the scrub to confirm the corpus still passes

    The pre-publish gate is **NOT** a v1.0 ship gate; it is a future concern that lands when the repo's namespace changes.
    ```

    Notes for the executor:
    - The 8 steps map to D-25's original 9 steps with step 8 (composer.json version) omitted. The numbering is renumbered cleanly so the document doesn't have a gap.
    - Step 5's pass criterion (`All three rehearsal gates passed.`) matches the trailing line that `RehearsalController::actionCheck` emits per Plan 05-04. Verify the exact string in the controller; if it's slightly different ("All gates passed" vs "All three rehearsal gates passed"), update either the controller or this document so they match.
    - The "INTENTIONALLY NOT REQUIRED" section is required: it documents why D-25 step 8 was dropped, so the next maintainer doesn't re-add it without context.
  </action>
  <verify>
    <automated>test -f .planning/RELEASE-CHECKLIST.md &amp;&amp; grep -c "rehearsal-check\|rehearsal/check" .planning/RELEASE-CHECKLIST.md | grep -q '^[1-9]' &amp;&amp; grep -c "composer test-coverage" .planning/RELEASE-CHECKLIST.md | grep -q '^[1-9]' &amp;&amp; grep -c "CI smoke" .planning/RELEASE-CHECKLIST.md | grep -q '^[1-9]' &amp;&amp; grep -c "INTENTIONALLY NOT REQUIRED" .planning/RELEASE-CHECKLIST.md | grep -q '^1$'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .planning/RELEASE-CHECKLIST.md` returns 0
    - `grep -c '^## Steps' .planning/RELEASE-CHECKLIST.md` returns 1
    - `grep -cE '^[0-9]+\. \[ \]' .planning/RELEASE-CHECKLIST.md` returns 8 (eight numbered steps; D-25 step 8 omitted per Lameco convention)
    - `grep -c "composer validate --strict" .planning/RELEASE-CHECKLIST.md` returns at least 1 (step 1)
    - `grep -c "composer test-coverage" .planning/RELEASE-CHECKLIST.md` returns at least 1 (step 3)
    - `grep -c "rehearsal/check\|rehearsal-check" .planning/RELEASE-CHECKLIST.md` returns at least 1 (step 5)
    - `grep -c "CQM" .planning/RELEASE-CHECKLIST.md` returns at least 2 (step 5 + section reference)
    - `grep -c "v1.0 ship gate" .planning/RELEASE-CHECKLIST.md` returns at least 1
    - `grep -c "advisory" .planning/RELEASE-CHECKLIST.md` returns at least 1 (step 6 references simac/enreach as advisory)
    - `grep -c "INTENTIONALLY NOT REQUIRED\|composer.json.*version" .planning/RELEASE-CHECKLIST.md` returns at least 1 (D-25 step 8 omission documented)
    - `grep -c "Pre-publish gate" .planning/RELEASE-CHECKLIST.md` returns at least 1 (D-04 carry-over)
    - `grep -c "ship.yml\|release.yml" .planning/RELEASE-CHECKLIST.md` returns 0 OR returns 1 in the explicit "no automated workflow" reference (D-26 honored either way)
    - `grep -c "/gsd-complete-milestone" .planning/RELEASE-CHECKLIST.md` returns at least 1 (step 8)
  </acceptance_criteria>
  <done>RELEASE-CHECKLIST.md committed. Operator workflow for tagging v1.0 is documented and mechanically verifiable.</done>
</task>

<task type="auto">
  <name>Task 2: Write CHANGELOG.md (v1.0.0 entry, Keep-a-Changelog format)</name>
  <files>
    CHANGELOG.md
  </files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/CHANGELOG.md (whole file — verify the Keep-a-Changelog 1.1.0 / SemVer header pattern; mirror the heading shape for the v1.0.0 entry)
    - .planning/PROJECT.md (Key Decisions table — what's locked vs what changed vs v1.x; the v1.0.0 changelog entry summarizes this)
    - .planning/REQUIREMENTS.md (sample the requirement IDs by category — FND, CONN, MAP, FILT, LOC, SRC, ETL, FH, FIN, ADP, VER, CFG, REC — to anchor the Added section)
    - CLAUDE.md (project-level architectural ground rules — single mapping.yaml, optional adapters, MigrationFilters, plugin-owned legacy DB, CLI-only, atomic-always-on, JIT assets, runtime-zero-AI, NeverProductionTrait)
  </read_first>
  <action>
    Write `CHANGELOG.md` at repo root. Format follows Lameco's existing convention (Keep-a-Changelog 1.1.0 + SemVer). The v1.0.0 entry summarizes the v2-rewrite scope as a series of named capabilities; "Changed (vs v1.x)" calls out the deliberate departures from the v1.x plugin.

    Date placeholder: leave as `<release-date>` for now. Step 8 of RELEASE-CHECKLIST instructs the operator to substitute the actual date when tagging.

    ```markdown
    # Changelog

    All notable changes to `lameco/craft-kunstmaan-migrator` are documented in this
    file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
    versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

    ## 1.0.0 — <release-date>

    Clean rewrite of the v1.x plugin. The migration pipeline retains v1.x's five
    stages (extract → transform → load → finalize → verify) but resharps the
    operator surface, mapping persistence, and adapter strategy based on lessons
    from cqm/simac/enreach pilots.

    ### Added

    - **CLI-only operator surface** — `kunstmaan-migrator/migrate`, `analyze`,
      `verify`, `doctor`, `map`, and `rehearsal/check` console controllers cover
      every operator workflow. No CP "Migration Pipeline" runner utility; no
      inline mapping authoring in the CP.
    - **Single `mapping.yaml` with per-row `status:`** — `proposed` /
      `accepted` / `dropped` / `needs-review`. Replaces v1.x's three-file scheme
      (`mapping.yaml.draft` + `mapping-drops-{ts}.yaml`).
    - **`MigrationFilters` value object** — entity allow-list, locale subset,
      `--since=YYYY-MM-DD`, `--max-per-entity=N`. Piped through every stage.
    - **Plugin-owned legacy DB connection** — host site does NOT need a
      `legacyDb` Yii component in `config/app.php`. Connection comes from env
      vars (`KUMA_DB_*` or `DATABASE_URL`) + plugin Settings.
    - **Optional SEOmatic + Retour adapters** — runtime detection via
      `Craft::$app->plugins->getPlugin(...)`. Neither plugin is in composer
      `require`. Settings flags (`seoEnabled`, `retourEnabled`) and CLI flags
      (`--no-seo`, `--no-retour`) override.
    - **Atomic-always-on** — per-entry atomic load is the only mode. No
      `--atomic` flag.
    - **JIT asset ingestion** — opt-in `--preload-assets` for stakeholders who
      want every legacy asset preloaded; default is per-entry JIT.
    - **`migrate sync-assets` recovery command** — re-ingests every `kuma_media`
      row a prior atomic run referenced but skipped (filesystem_404 /
      mime_mismatch / too_large / etc.). Idempotent. Permanently-failed assets
      get a terminal-state marker (`meta.terminalState='permanently_failed'`)
      that prevents retry loops.
    - **`kunstmaan-migrator/doctor`** — 10 deterministic boot checks: every Yii
      Component DI, every adapter presence check, env source detection, locale
      Rung 0 alignment. Used as the CI smoke gate (`./craft kunstmaan-migrator/doctor`
      exit 0 in `.github/workflows/ci.yml smoke` job).
    - **`kunstmaan-migrator/rehearsal/check`** — read-only mechanical gate
      against committed rehearsal artifacts under
      `.planning/rehearsal/v1.0/{cqm,simac,enreach}/`. Three gates: counts
      within tolerance, zero unresolved CKEditor tokens, all assets RCA-tagged.
    - **`MigrationStateService::markTerminal()` + `isTerminal()`** — terminal-
      state contract for permanently-failed rows. Reuses the existing `meta`
      JSON column; no schema migration.
    - **AI-assisted mapping (analyze stage)** — Anthropic Haiku via the
      `analyze` CLI. 9 deterministic heuristics first; LLM only for residuals.
      Confidence tiers (`high` / `medium` / `low`) route proposals to
      `mapping.yaml` directly, the draft section, or drops with rationale.
      Runtime-zero-AI: every other stage is deterministic.
    - **Characterization tests on the Transform stage** — per-row JSON fixtures
      under `tests/fixtures/transform/{input,golden}/`. Comparator JSON-
      canonicalizes (recursive ksort + JSON_PRETTY_PRINT) before diff to survive
      PHP version bumps. Refresh via `UPDATE_SNAPSHOTS=1`.
    - **PHPUnit unit + integration testsuites** with per-module 70% line-
      coverage gate on `MigrationFilters`, `MappingFile`, every field handler,
      `CkeditorRewriterService`, and `HeuristicProposer`. Enforced in CI via
      `tools/check-coverage.php`.
    - **CI workflow** — `.github/workflows/ci.yml` splits into `unit`
      (composer validate + phpunit + coverage gate) and `smoke` (scratch-Craft
      install + plugin path-repo + doctor exit 0). PHP 8.3 only.
    - **Configuration via `config/kunstmaan-migrator.php`** — full operator
      example shipped at `config/kunstmaan-migrator.example.php`. Settings
      auto-fill blank `legacyDb*` from `DATABASE_URL` when present.
    - **`NeverProductionTrait`** — every legacy-reading and destructive command
      hard-blocks `CRAFT_ENVIRONMENT=production`. Rehearsal-check command
      DELIBERATELY OMITS the trait (read-only over committed artifacts).

    ### Changed (vs v1.x)

    - **Mapping persistence:** single `mapping.yaml` with `status:` per row
      (was: `mapping.yaml` + `mapping.yaml.draft` + `mapping-drops-{ts}.yaml`).
    - **Adapter integration:** SEOmatic + Retour are runtime-detected and
      optional (was: hard composer pins on specific versions).
    - **Legacy DB wiring:** plugin owns the connection from env vars (was:
      consumer site had to declare a Yii `legacyDb` component in `config/app.php`).
    - **Atomic mode:** always-on, no flag (was: `--atomic` opt-in).
    - **Operator surface:** CLI-canonical (was: CP "Migration Pipeline"
      utility + inline mapping editor; both removed).
    - **Source layout:** flat `src/<concern>/` (was: three-tier
      `kunstmaan/` + `craft/` + `bridge/` + Deptrac).
    - **AI provider:** Anthropic-only (was: multi-provider abstraction; v1.x
      shipped only Anthropic too, but the abstraction added complexity for
      no driver).
    - **Test discipline:** PHPUnit 11 corpus from day one with per-module
      coverage gate (was: v1.x shipped 1.0 with no tests).

    ### Removed (vs v1.x)

    - `--atomic` CLI flag (atomic is always-on)
    - `mapping.yaml.draft` and `mapping-drops-{ts}.yaml` files (consolidated
      into `mapping.yaml` with per-row status)
    - CP "Migration Pipeline" runner utility
    - Inline mapping editor in the CP
    - `.claude/skills/` skill bundle
    - Three-tier `kunstmaan/` + `craft/` + `bridge/` source layout + Deptrac
    - Hard composer requires on SEOmatic / Retour
    - Yii `legacyDb` component requirement in consumer site `config/app.php`

    ### Security

    - **`NeverProductionTrait`** gates every legacy-reading and destructive
      command on `CRAFT_ENVIRONMENT != production`. Plugin is dev/staging
      only by design.
    - **Anthropic API calls only during `analyze`** — no runtime AI in the
      ETL path; no API key required to run `migrate`.

    [1.0.0]: https://github.com/lameco/kunstmaan-migrator/releases/tag/v1.0.0
    ```

    Notes:
    - The `<release-date>` placeholder is intentional. RELEASE-CHECKLIST step 7 instructs the operator to substitute it.
    - The bottom link reference (`[1.0.0]: https://...`) follows Keep-a-Changelog convention. The actual GitHub URL once the repo is public; for the lameco/-private state, leave the placeholder URL — it just needs to be a valid-looking link target. Adjust to actual release URL when the repo namespace clarifies (Phase 5.1+ if/when public).
    - The "Added" section is long because v1.0 IS the first stable release and bundles everything. Subsequent entries (v1.0.1, v1.1, etc.) will be much shorter.
    - Section ordering follows Keep-a-Changelog: Added → Changed → Removed → (Deprecated, omitted because nothing is) → (Fixed, omitted because v1.0 has no fixes-vs-v0.x) → Security.
  </action>
  <verify>
    <automated>test -f CHANGELOG.md &amp;&amp; grep -c "Keep a Changelog" CHANGELOG.md | grep -q '^[1-9]' &amp;&amp; grep -c "## 1.0.0" CHANGELOG.md | grep -q '^1$' &amp;&amp; grep -c "### Added" CHANGELOG.md | grep -q '^1$' &amp;&amp; grep -c "### Changed" CHANGELOG.md | grep -q '^1$' &amp;&amp; grep -c "### Removed" CHANGELOG.md | grep -q '^1$'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f CHANGELOG.md` returns 0 (file at repo root)
    - `grep -c "Keep a Changelog" CHANGELOG.md` returns at least 1 (Lameco convention verified pre-planning)
    - `grep -c "Semantic Versioning" CHANGELOG.md` returns at least 1
    - `grep -c "## 1.0.0" CHANGELOG.md` returns 1 (the v1.0 entry heading)
    - `grep -c "### Added" CHANGELOG.md` returns at least 1
    - `grep -c "### Changed" CHANGELOG.md` returns at least 1
    - `grep -c "### Removed" CHANGELOG.md` returns at least 1
    - `grep -c "### Security" CHANGELOG.md` returns at least 1
    - `grep -c "single .mapping.yaml" CHANGELOG.md` returns at least 1 (key decision called out)
    - `grep -c "atomic-always-on\|atomic .*always.on\|always-on" CHANGELOG.md` returns at least 1
    - `grep -c "CLI-only" CHANGELOG.md` returns at least 1
    - `grep -c "runtime-zero-AI\|runtime.zero.AI\|NeverProductionTrait" CHANGELOG.md` returns at least 2 (one per concept)
    - `grep -c "<release-date>" CHANGELOG.md` returns 1 (placeholder kept for operator to substitute at tag time)
    - File length is reasonable for a v1.0 ship-time changelog: `wc -l CHANGELOG.md` returns 50-200 lines
  </acceptance_criteria>
  <done>CHANGELOG.md committed in Lameco's verified Keep-a-Changelog format. RELEASE-CHECKLIST step 7 references it.</done>
</task>

<task type="auto">
  <name>Task 3: Write Phase 5 RECONCILIATION.md + flip TST-01..04 to [x] in REQUIREMENTS.md</name>
  <files>
    .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md,
    .planning/REQUIREMENTS.md
  </files>
  <read_first>
    - .planning/phases/04.1-polish-recovery-and-env-defaults/RECONCILIATION.md (whole file — verbatim shape template; mirror its sections for Phase 5)
    - .planning/REQUIREMENTS.md (whole file — verify current `[ ]` state on TST-01..04 at lines 121-124; verify the italicized phase-reference convention used by Phase 4.1 closures at lines 116-117)
    - All Phase 5 plan SUMMARYs:
      - .planning/phases/05-tests-rehearsal-release/05-01-SUMMARY.md (tests/ reorg)
      - .planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md (PHPUnit infra + check-coverage)
      - .planning/phases/05-tests-rehearsal-release/05-03-SUMMARY.md (transform characterization)
      - .planning/phases/05-tests-rehearsal-release/05-04-SUMMARY.md (RehearsalController + dirs)
      - .planning/phases/05-tests-rehearsal-release/05-05-SUMMARY.md (analyze + finalize unit tests)
      - .planning/phases/05-tests-rehearsal-release/05-06-SUMMARY.md (5 handler unit tests)
      - .planning/phases/05-tests-rehearsal-release/05-07-SUMMARY.md (CI smoke job)
  </read_first>
  <action>
    **3a. Create `.planning/phases/05-tests-rehearsal-release/RECONCILIATION.md`** mirroring Phase 4.1's RECONCILIATION shape:

    ```markdown
    # Phase 5: Tests, Rehearsal & Release — Reconciliation

    **Date:** <gmdate Y-m-d at execution time>
    **Plans shipped:** 05-01 through 05-08
    **Test corpus delta:** Phase 4.1 close (~110-120 tests) → Phase 5 close (~<final-count> tests)

    ## Phase outcome summary

    Phase 5 closes the v1.0 ship gate. Four TST requirements + the CQM rehearsal-log success criterion #4 plus a complete CI workflow + release checklist + changelog.

    ## Requirements closed in Phase 5

    | ID | Plans | One-line outcome |
    |---|---|---|
    | TST-01 | 05-01, 05-02, 05-05, 05-06 | tests/unit + tests/integration split (D-12); per-module 70% line-coverage gate on MigrationFilters, MappingFile, every field handler, CkeditorRewriterService, HeuristicProposer; +<delta> unit tests across 7 new D-10 test files |
    | TST-02 | 05-03 | TransformCharacterizationTest with @dataProvider over per-row JSON fixtures; UPDATE_SNAPSHOTS=1 refresh; canonicalize-then-diff comparator (recursive ksort + JSON_PRETTY_PRINT) |
    | TST-03 | 05-07 | .github/workflows/ci.yml splits into `unit` + `smoke` jobs; smoke gates on `./craft kunstmaan-migrator/doctor` exit 0 in a scratch Craft 5 install via path-repo |
    | TST-04 | 05-04, 05-08 | RehearsalController + three mechanical gate parsers; .planning/rehearsal/v1.0/{cqm,simac,enreach}/ directory shape; RELEASE-CHECKLIST.md operator workflow with CQM rehearsal-check exit 0 as the binding tag gate |

    ## Architectural ground rules respected

    - **Single mapping.yaml + per-row status:** untouched.
    - **Optional SEOmatic + Retour adapters:** untouched.
    - **Filter spec from day one:** untouched.
    - **CLI-only operator surface:** new RehearsalController is a CLI-only addition (Phase 5 / D-23). No CP touch.
    - **Atomic-always-on:** untouched.
    - **JIT assets:** untouched.
    - **Runtime-zero-AI:** every Phase 5 deliverable is deterministic; no AI calls outside the existing analyze stage.
    - **No `.claude/skills/` bundle:** untouched.
    - **NeverProductionTrait gate:** RehearsalController DELIBERATELY OMITS the trait (Phase 5 / D-22) — documented in class docblock; deliberate departure from the every-other-controller pattern. Plan 05-04's must_haves codify the omission.
    - **Tests required from day one (PROJECT.md Key Decisions):** Phase 5 IS this requirement satisfied. v1.0 ships with characterization tests, per-module coverage gate, and a CQM rehearsal log.

    ## Coverage gate state at v1.0

    From Plan 05-06 SUMMARY (final per-module coverage table):

    | Module | Coverage | Gate state |
    |---|---|---|
    | src/filter/MigrationFilters.php | <X.X>% | <OK|FAIL> |
    | src/mapping/MappingFile.php | <X.X>% | <OK|FAIL> |
    | src/finalize/CkeditorRewriterService.php | <X.X>% | <OK|FAIL> |
    | src/analyze/HeuristicProposer.php | <X.X>% | <OK|FAIL> |
    | src/fields/handlers/PlainTextHandler.php | <X.X>% | <OK|FAIL> |
    | src/fields/handlers/SplitNameHandler.php | <X.X>% | <OK|FAIL> |
    | src/fields/handlers/RelationHandler.php | <X.X>% | <OK|FAIL> |
    | src/fields/handlers/MatrixHandler.php | <X.X>% | <OK|FAIL> |
    | src/fields/handlers/AssetHandler.php | <X.X>% | <OK|FAIL> |

    <!-- Replace placeholders with actual numbers from 05-06 SUMMARY at execute time. -->
    <!-- If any module finished below 70%, document the limitation here + cite the SUMMARY. -->

    ## Phase 5 carry-overs

    - **Operator-side capture of CQM artifacts** — Plan 05-03's `tools/capture-transform-fixtures.php` and Plan 05-04's `.planning/rehearsal/v1.0/{cqm,simac,enreach}/` directories ship empty (the latter with operator-facing READMEs). The operator runs the capture script + commits the rehearsal artifacts on a dev host before invoking the RELEASE-CHECKLIST. This is a manual operator-side action explicitly out of CI scope (D-24).
    - **Pre-publish anonymization** — RELEASE-CHECKLIST.md flags the scrub gate if/when the repo goes public. Not part of v1.0 ship (D-04).
    - **Simac + enreach rehearsals** — captured but advisory (D-19). Failures inform Phase 5.1 / NEXT-04.
    - **`MigrateController` 1700+ LOC + `SeoMigrationService` 606 LOC structural review** — Phase 5 covered this surface via the test corpus + characterization fixtures + rehearsal log (per CONTEXT.md `## Out of Scope`). Whole-plugin code review / structural refactor is NOT a Phase 5 deliverable. If Phase 5.1 surfaces issues, file under deferred.
    - **Mutation testing (Infection PHP), property-based tests for CkeditorRewriter, multi-provider AI, ship.yml workflow, PHP 8.4 matrix** — all explicitly deferred per CONTEXT.md `## Deferred Ideas`.

    ## Phase 5 ↔ prior-phase commit references

    <!-- Operator/executor: list the v1.0 release commit SHA + any Phase 5 in-band fix commits here. -->
    ```

    Replace placeholders (`<X.X>%`, `<final-count>`, etc.) with concrete values from the per-plan SUMMARYs at execute time. If any TST module finished below 70% coverage, the table reflects the actual percentage and the gate-state column reads `FAIL` — flag in the carry-overs section as a Phase 5.1 input.

    **3b. Edit `.planning/REQUIREMENTS.md`** to flip TST-01..04 from `[ ]` to `[x]`. Mirror the Phase 4.1 closure pattern (verified at lines 116-117 verbatim):

    ```diff
    -- [ ] **TST-01**: PHPUnit suite runs `tests/unit` and `tests/integration`. Unit suite covers `MigrationFilters`, `MappingLoader`, every field handler, `CkeditorRewriter`, and the heuristic proposer.
    +- [x] **TST-01**: PHPUnit suite runs `tests/unit` and `tests/integration`. Unit suite covers `MigrationFilters`, `MappingLoader`, every field handler, `CkeditorRewriter`, and the heuristic proposer. _(Phase 5 / Plans 05-01 + 05-02 + 05-05 + 05-06 — tests/ split into unit + integration; per-module 70% line-coverage gate enforced by tools/check-coverage.php; +<delta> unit tests across 7 new D-10 test files.)_

    -- [ ] **TST-02**: Characterization fixtures captured from a real Kunstmaan dump exercise the Transform stage end-to-end (golden-file diffs).
    +- [x] **TST-02**: Characterization fixtures captured from a real Kunstmaan dump exercise the Transform stage end-to-end (golden-file diffs). _(Phase 5 / Plan 05-03 — TransformCharacterizationTest @dataProvider over per-row JSON fixtures; canonicalize-then-diff comparator with recursive ksort; UPDATE_SNAPSHOTS=1 refresh.)_

    -- [ ] **TST-03**: CI workflow runs `composer validate --strict`, PHPUnit, and a smoke test that the plugin loads in a scratch Craft install.
    +- [x] **TST-03**: CI workflow runs `composer validate --strict`, PHPUnit, and a smoke test that the plugin loads in a scratch Craft install. _(Phase 5 / Plan 05-07 — .github/workflows/ci.yml splits into unit + smoke jobs; smoke gates on `./craft kunstmaan-migrator/doctor` exit 0 in a scratch Craft 5 install via path-repo; PHP 8.3 only.)_

    -- [ ] **TST-04**: `kunstmaan-migrator/doctor` and the rehearsal smoke check are part of the release checklist before any tag.
    +- [x] **TST-04**: `kunstmaan-migrator/doctor` and the rehearsal smoke check are part of the release checklist before any tag. _(Phase 5 / Plans 05-04 + 05-08 — RehearsalController with three mechanical gate parsers; .planning/RELEASE-CHECKLIST.md with CQM rehearsal-check exit 0 as the binding v1.0 tag gate.)_
    ```

    Replace `<delta>` in TST-01's italicized note with the concrete number from 05-05 SUMMARY + 05-06 SUMMARY combined.

    Also update the Traceability table at the bottom of REQUIREMENTS.md (if a per-phase row pattern exists per Phase 4.1's RECONCILIATION):
    ```markdown
    | TST-01..04 | 5 |
    ```
  </action>
  <verify>
    <automated>test -f .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md &amp;&amp; grep -c "TST-01" .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md | grep -q '^[1-9]' &amp;&amp; grep -c '^- \[x\] \*\*TST-0[1-4]' .planning/REQUIREMENTS.md | grep -q '^4$'</automated>
  </verify>
  <acceptance_criteria>
    - `test -f .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns 0
    - `grep -c "TST-01" .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns at least 1
    - `grep -c "TST-04" .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns at least 1
    - `grep -c "Architectural ground rules respected" .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns 1
    - `grep -c "Coverage gate state" .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns 1
    - `grep -c "Phase 5 carry-overs\|carry-over" .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns at least 1
    - `grep -c "NeverProductionTrait.*OMIT\|DELIBERATELY OMIT" .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns at least 1 (D-22 omission acknowledged in the ground-rules section)
    - In `.planning/REQUIREMENTS.md`:
      - `grep -c '^- \[x\] \*\*TST-01' .planning/REQUIREMENTS.md` returns 1
      - `grep -c '^- \[x\] \*\*TST-02' .planning/REQUIREMENTS.md` returns 1
      - `grep -c '^- \[x\] \*\*TST-03' .planning/REQUIREMENTS.md` returns 1
      - `grep -c '^- \[x\] \*\*TST-04' .planning/REQUIREMENTS.md` returns 1
      - `grep -c '^- \[ \] \*\*TST-0' .planning/REQUIREMENTS.md` returns 0 (no TST-x left unchecked)
      - `grep -c "Phase 5 / Plan" .planning/REQUIREMENTS.md` returns at least 4 (italicized references on each TST line)
  </acceptance_criteria>
  <done>Phase 5 RECONCILIATION committed; REQUIREMENTS.md reflects Phase 5 closure (4 of 4 TST IDs flipped to [x]); ready for `/gsd-complete-milestone` once operator captures CQM artifacts and runs the RELEASE-CHECKLIST.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|---|---|
| operator → RELEASE-CHECKLIST.md | Manual workflow; every step has a mechanical pass/fail script |
| changelog → public consumers | When repo goes public, changelog content is the first thing readers see; v1.0 entry must accurately describe scope |
| REQUIREMENTS.md → audit trail | Italicized phase references provide commit-audit traceability |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|---|---|---|---|---|
| T-05-08-01 | Tampering | RELEASE-CHECKLIST steps weakened by edit | mitigate | Each step's pass criterion cites a specific shell command or grep; ambiguity invites tampering. The "INTENTIONALLY NOT REQUIRED" section locks the omission rationale so a future maintainer doesn't reverse it. |
| T-05-08-02 | Repudiation | changelog drifts from actual scope | accept | v1.0 changelog is reviewer-validated at PR time. Subsequent entries (v1.0.1, v1.1) maintained by operator per the Keep-a-Changelog convention. |
| T-05-08-03 | Information Disclosure | changelog references private CQM details | accept | The changelog Added/Changed sections describe capabilities, not data. CQM rehearsal data lives under .planning/ (also private until repo opens). Pre-publish gate flagged in RELEASE-CHECKLIST. |
| T-05-08-04 | DoS | RECONCILIATION too long to maintain | mitigate | Document is closure-only; no ongoing edit churn. Phase 5.1+ would write its own RECONCILIATION rather than extend this one. |
| T-05-08-05 | Spoofing | tag pushed without checklist completion | mitigate | RELEASE-CHECKLIST step 8 explicitly is "Tag pushed" — not the first step but the last. Each prior step's pass criterion is mechanically verifiable. Operator discipline is the binding constraint; CI smoke + per-module coverage gate provide automated backstops. |
</threat_model>

<verification>
- `test -f .planning/RELEASE-CHECKLIST.md && test -f CHANGELOG.md && test -f .planning/phases/05-tests-rehearsal-release/RECONCILIATION.md` returns 0
- All four TST IDs flipped to `[x]` in REQUIREMENTS.md (per-line grep checks pass)
- `composer validate --strict --no-plugins` exits 0 (regression check — composer.json untouched)
- `composer test` exits 0 (regression check — no new tests in this plan; corpus unchanged from 05-06)
- `git diff src/` empty (zero source-code changes in this plan)
- `git diff .github/` empty (no ship.yml — D-26 honored)
- `find . -name 'ship.yml' -o -name 'release.yml' | grep -v vendor | wc -l` returns 0
</verification>

<success_criteria>
- D-25: RELEASE-CHECKLIST.md committed with 8 mandatory pre-tag steps (D-25 step 8 omitted per verified Lameco convention; rationale documented in the file)
- D-26: no ship.yml workflow (manual operator-driven tag for v1.0)
- D-04 carry-over: pre-publish anonymization gate flagged in RELEASE-CHECKLIST.md
- TST-01..04: all four requirements flipped to [x] in REQUIREMENTS.md with italicized phase + plan references
- Phase 5 RECONCILIATION.md mirrors Phase 4.1's structure; aggregates all plan SUMMARYs; documents any below-70% module + Phase 5.1/NEXT inputs
- CHANGELOG.md at repo root in Lameco's verified Keep-a-Changelog format with the v1.0.0 entry summarizing v2-rewrite scope
- Phase 5 closure complete; ready for operator to capture CQM artifacts + run the checklist + tag v1.0
</success_criteria>

<output>
After completion, create `.planning/phases/05-tests-rehearsal-release/05-08-SUMMARY.md` documenting:
- Final test corpus count: Phase 4.1 close (~110-120) → Phase 5 close (X)
- Per-module coverage gate state at Phase 5 close (table); any module below 70% with rationale + Phase 5.1/NEXT input
- Whether the v1.0.0 entry in CHANGELOG.md cites the right scope (cross-check against PROJECT.md Key Decisions)
- Confirmation D-26 honored: no `ship.yml` / `release.yml` workflow files
- Phase 5 closure announcement: "Phase 5 closes; ready for operator to capture CQM rehearsal artifacts + run RELEASE-CHECKLIST + tag v1.0; ready for /gsd-complete-milestone"
- Carry-overs handed off to Phase 5.1 / NEXT (if any TST module finished below 70%, or if simac/enreach surfaces blocking issues at operator-side rehearsal capture)
</output>
