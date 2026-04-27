---
phase: 05
plan: 07
type: execute
wave: 3
depends_on: ["05-02"]
files_modified:
  - .github/workflows/ci.yml
autonomous: true
requirements: [TST-03]
must_haves:
  truths:
    - ".github/workflows/ci.yml has TWO jobs: 'unit' and 'smoke' (D-18)"
    - "unit job runs on push + PR; steps: checkout → setup-php with coverage:pcov → composer validate --strict --no-plugins → composer install → composer test → composer test-coverage → upload clover artifact"
    - "smoke job runs on push + PR with 'needs: unit' (gate on unit pass; saves CI minutes when unit broken)"
    - "smoke job bootstraps a scratch Craft 5 install via composer create-project craftcms/craft scratch-craft (D-15)"
    - "smoke job registers this repo as a path-type composer repository in the scratch site, then composer require lameco/craft-kunstmaan-migrator @dev (D-15)"
    - "Smoke assertion: ./craft kunstmaan-migrator/doctor exits 0 (D-17 — WARN exits 0, FAIL exits 1)"
    - "Single PHP version: 8.3 (D-16; no 8.4 matrix)"
    - "PCOV is installed via shivammathur/setup-php's coverage: pcov parameter — NOT a composer require-dev dep (PATTERNS risk callout 3)"
    - "Composer validate stays as the unit job's first composer step (D-18)"
    - "No automated migrate --live run in CI (D-24); CI verifies only the committed artifacts via rehearsal-check (Plan 05-08 wires this)"
  artifacts:
    - path: ".github/workflows/ci.yml"
      provides: "Two-job CI: unit (test + coverage) and smoke (scratch-Craft + doctor)"
      contains: "smoke:"
  key_links:
    - from: ".github/workflows/ci.yml unit job"
      to: "composer test-coverage (composer.json)"
      via: "scripts.test-coverage from 05-02"
      pattern: "composer test-coverage"
    - from: ".github/workflows/ci.yml unit job"
      to: "tools/check-coverage.php (per-module 70% gate)"
      via: "composer test-coverage's chained-script step 3"
      pattern: "tools/check-coverage.php"
    - from: ".github/workflows/ci.yml smoke job"
      to: "src/console/DoctorController.php (actionIndex)"
      via: "./craft kunstmaan-migrator/doctor"
      pattern: "kunstmaan-migrator/doctor"
    - from: ".github/workflows/ci.yml smoke job"
      to: "shivammathur/setup-php@v2"
      via: "GitHub Action"
      pattern: "shivammathur/setup-php"
---

<objective>
**TST-03 / D-15..D-18 — CI workflow split into `unit` + `smoke` jobs.**

The current `.github/workflows/ci.yml` has a single `test` job that runs composer-validate + composer-install + composer-test on PHP 8.3. This plan extends it into two jobs:

1. **`unit`** — the existing job, renamed from `test`. Adds `coverage: pcov` to setup-php (D-06) so the per-module 70% gate from `composer test-coverage` (Plan 05-02) actually runs with a coverage driver. Adds `composer test-coverage` as a step after `composer test`. Uploads the clover XML as an artifact (D-09 — planner-discretion; chose upload for diff visibility on PRs).

2. **`smoke`** — new job. Runs `composer create-project craftcms/craft scratch-craft`, registers this repo as a path-type composer repository inside the scratch site, runs `composer require lameco/craft-kunstmaan-migrator @dev`, then asserts `./craft kunstmaan-migrator/doctor` exits 0 (D-17). Gated on `needs: unit` so CI minutes don't get spent on smoke when unit is already broken (D-18).

**Constraints honored:**
- **D-15**: scratch-Craft via `composer create-project`. No Docker. Mirrors a real consumer-site install.
- **D-16**: PHP 8.3 only. No matrix.
- **D-17**: doctor exit 0 is the smoke assertion. WARN-as-exit-0 is correct (host with no `KUNSTMAAN_SOURCE_PATH` will WARN, not FAIL — that's a "did it boot" signal, not "is it configured").
- **D-18**: unit's first composer step stays `composer validate --strict`. Smoke is a separate job, not interleaved.
- **D-24**: CI never runs `migrate --live`. The smoke is doctor-only.
- **PATTERNS risk callout 3**: PCOV is NOT in composer.json require-dev. It's a system extension installed via `shivammathur/setup-php`'s `coverage: pcov`.

**Why Wave 3:** `composer test-coverage` is a 05-02 deliverable; the unit job's coverage step depends on it. `tools/check-coverage.php` is also a 05-02 deliverable. No file overlap with 05-05 / 05-06 (those touch tests/unit/, this one touches .github/workflows/ci.yml).

Output:
- `.github/workflows/ci.yml` shape: `name: CI` + `on: [push, pull_request]` + 2 jobs
- A push-or-PR triggers both jobs; smoke gates on unit pass
- The next push to main after this plan lands runs the new pipeline; both jobs go green
- Plan 05-08 RELEASE-CHECKLIST step 4 references "CI smoke job green on a recent commit"
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
@.planning/phases/05-tests-rehearsal-release/05-02-SUMMARY.md
@CLAUDE.md

<interfaces>
Current `.github/workflows/ci.yml` (verified — 13 lines):
```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer validate --strict --no-plugins
      - run: composer install --no-interaction --no-progress
      - run: composer test
```

The `shivammathur/setup-php@v2` action (verified pattern, used by tens of thousands of PHP projects on GitHub Actions) accepts a `coverage` parameter that installs PCOV / Xdebug / none at the system level. Setting `coverage: pcov` runs `pecl install pcov` (or pre-built equivalent) and adds `extension=pcov.so` to the PHP ini for the runner — no composer changes needed.

`composer test-coverage` (from Plan 05-02) is a chained script:
1. `@php -r "if (!extension_loaded('pcov') && !extension_loaded('xdebug')) { exit(1); }"`
2. `vendor/bin/phpunit --coverage-clover=build/coverage/clover.xml`
3. `@php tools/check-coverage.php`

In CI with `coverage: pcov`, step 1 passes, step 2 emits clover, step 3 enforces the per-module gate. Non-zero exit → unit job fails → smoke job (gated on `needs: unit`) is skipped.

Scratch-Craft consumer site shape (verified pattern from `composer create-project craftcms/craft`):
- Creates a Craft 5 project at `scratch-craft/`
- Has its own `composer.json` requiring `craftcms/cms: ^5.0`
- Has its own `craft` CLI entry script at `scratch-craft/craft`
- After registering the path repository + `composer require`, the plugin's autoloader is wired and `./craft kunstmaan-migrator/doctor` is callable

Doctor exit codes (verified at src/console/DoctorController.php — Phase 1 + Phase 4.1 deepening):
- `OK` (every check passes) — exit 0
- `WARN` (one or more checks WARN, none FAIL) — exit 0 (D-69 / D-17 contract)
- `FAIL` (one or more checks FAIL) — exit 1
The smoke wants exit 0 — accepts WARN. Exit 1 = plugin failed to boot or a check returned FAIL → smoke job fails.
</interfaces>

<reference_files>
- .github/workflows/ci.yml — current single-job shape (13 lines; verified)
- ~/Sites/craft-kunstmaan-migrator/.github/workflows/ — v1.x CI reference if relevant; v1 might not have a CI workflow at all
- src/console/DoctorController.php — implements actionIndex; the smoke target
- .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (MOD: .github/workflows/ci.yml section, lines 179-247 — verbatim target shape)
- .planning/phases/05-tests-rehearsal-release/05-CONTEXT.md (D-15..D-18 — full scope)
</reference_files>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Rewrite .github/workflows/ci.yml — add coverage to unit job + add smoke job (D-15..D-18)</name>
  <files>
    .github/workflows/ci.yml
  </files>
  <read_first>
    - .github/workflows/ci.yml (whole file — current 13-line single-job shape; verify before editing that no other in-flight changes are present)
    - composer.json (verify scripts.test-coverage exists from 05-02; smoke depends on `kunstmaan-migrator` plugin handle which is set in composer.json's `extra.handle`)
    - phpunit.xml.dist (verify `<coverage><report><clover outputFile="build/coverage/clover.xml"/></report></coverage>` from 05-02; the unit job's `actions/upload-artifact` references `build/coverage/clover.xml`)
    - tools/check-coverage.php (verify present from 05-02; the unit job's `composer test-coverage` invokes it)
    - src/console/DoctorController.php (verify `actionIndex` exists and matches doctor command alias `kunstmaan-migrator/doctor`)
    - .planning/phases/05-tests-rehearsal-release/05-PATTERNS.md (MOD: .github/workflows/ci.yml section, lines 179-247 — verbatim target shape)
  </read_first>
  <action>
    Replace `.github/workflows/ci.yml` with this content. The existing `test` job is renamed to `unit` and extended; a new `smoke` job is added.

    ```yaml
    name: CI
    on: [push, pull_request]

    jobs:
      unit:
        name: Unit + Integration tests + coverage gate
        runs-on: ubuntu-latest
        steps:
          - uses: actions/checkout@v4
          - uses: shivammathur/setup-php@v2
            with:
              php-version: '8.3'
              coverage: pcov          # D-06: PCOV installed at system level; no composer dep
          - run: composer validate --strict --no-plugins
          - run: composer install --no-interaction --no-progress
          - run: composer test
          - run: composer test-coverage    # D-07: per-module 70% gate via tools/check-coverage.php
          - uses: actions/upload-artifact@v4
            if: always()
            with:
              name: coverage-clover
              path: build/coverage/clover.xml
              if-no-files-found: warn

      smoke:
        name: Plugin-load smoke (scratch-Craft → doctor exit 0)
        runs-on: ubuntu-latest
        needs: unit       # D-18: gate on unit pass; saves CI minutes on broken unit
        steps:
          - uses: actions/checkout@v4
            with:
              path: plugin

          - uses: shivammathur/setup-php@v2
            with:
              php-version: '8.3'

          - name: Bootstrap scratch Craft 5
            run: |
              composer create-project craftcms/craft scratch-craft \
                --no-interaction --prefer-dist --no-progress

          - name: Register plugin as path repository + install
            working-directory: scratch-craft
            run: |
              composer config repositories.plugin path ../plugin
              composer require lameco/craft-kunstmaan-migrator @dev \
                --no-interaction --no-progress

          - name: Plugin-load smoke (D-17 — doctor exit 0; WARN exits 0, FAIL exits 1)
            working-directory: scratch-craft
            env:
              CRAFT_ENVIRONMENT: dev
            run: ./craft kunstmaan-migrator/doctor
    ```

    Notes:

    - **Job names** (`unit`, `smoke`) are required for `needs: unit` referencing. `name:` keys give the GitHub UI a human label.
    - **`coverage: pcov`** on the unit job's setup-php step — this is the ONLY place PCOV gets installed. `composer test-coverage`'s extension-loaded check then passes; phpunit emits clover; check-coverage.php gates.
    - **`composer validate --strict --no-plugins`** is the unit job's FIRST `run` step (D-18). `--no-plugins` avoids running composer plugins during validate (which can have side effects).
    - **Coverage artifact upload** (D-09 planner-discretion): `actions/upload-artifact@v4` saves `build/coverage/clover.xml` as a downloadable artifact on every PR/push. `if: always()` ensures it uploads even when the job step fails (so an operator can inspect coverage drift on a failed run). `if-no-files-found: warn` surfaces a clear message if check-coverage's clover-missing branch fires.
    - **Smoke job structure** mirrors PATTERNS lines 222-244:
      - Checkout this repo into `plugin/` (so the path-repository registration in the next step finds it via `../plugin`).
      - Setup PHP 8.3 (no coverage driver needed for smoke).
      - `composer create-project craftcms/craft scratch-craft` — Craft's official scaffolding.
      - `composer config repositories.plugin path ../plugin` — registers this checkout as a Composer path repository inside the scratch site. Composer then resolves `lameco/craft-kunstmaan-migrator` from the local checkout instead of Packagist.
      - `composer require lameco/craft-kunstmaan-migrator @dev` — `@dev` matches the path-repo's branch state.
      - `./craft kunstmaan-migrator/doctor` runs the actual doctor controller. Exit 0 = pass; non-zero = smoke fails.
    - **`CRAFT_ENVIRONMENT: dev`** — sets the env var so doctor recognizes the runner as a non-production environment. (Not strictly required for the doctor command itself; included for clarity and because `NeverProductionTrait`-gated commands key off this var.)
    - **`needs: unit`** — D-18 explicit. Without this, smoke runs in parallel with unit; with it, smoke is skipped if unit fails. Saves CI minutes on broken unit.

    Validate the YAML:
    ```bash
    # Pure shape check — does not require a YAML linter, but if `yamllint` or `actionlint` are available, run them.
    python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))" && echo "OK YAML valid"

    # Verify GitHub Actions schema if `actionlint` is on PATH:
    actionlint .github/workflows/ci.yml 2>/dev/null && echo "OK actionlint" || echo "(actionlint not installed; skipped)"
    ```

    Do NOT add a `concurrency` block, a `permissions` block, or a `strategy.matrix` block. Those are noise for v1.0; revisit if a real driver appears.

    Do NOT add `release.yml` or any "ship" workflow (D-26 explicit out-of-scope).
  </action>
  <verify>
    <automated>python3 -c "import yaml; d=yaml.safe_load(open('.github/workflows/ci.yml')); assert 'unit' in d['jobs'] and 'smoke' in d['jobs']; assert d['jobs']['smoke']['needs'] == 'unit'; print('OK')"</automated>
  </verify>
  <acceptance_criteria>
    - `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"` exits 0 (YAML well-formed)
    - `grep -c '^  unit:' .github/workflows/ci.yml` returns 1 (job key present)
    - `grep -c '^  smoke:' .github/workflows/ci.yml` returns 1 (job key present)
    - `grep -c 'needs: unit' .github/workflows/ci.yml` returns 1 (D-18: smoke gated on unit pass)
    - `grep -c 'coverage: pcov' .github/workflows/ci.yml` returns 1 (D-06: PCOV installed at system level)
    - `grep -c "composer validate --strict" .github/workflows/ci.yml` returns 1 (D-18: stays in unit's first composer step)
    - `grep -c "composer test-coverage" .github/workflows/ci.yml` returns 1 (D-07 gate invoked in CI)
    - `grep -c "composer create-project craftcms/craft scratch-craft" .github/workflows/ci.yml` returns 1 (D-15: scratch Craft bootstrap)
    - `grep -c "composer config repositories.plugin path ../plugin" .github/workflows/ci.yml` returns 1 (D-15: path repository registration)
    - `grep -c "lameco/craft-kunstmaan-migrator @dev" .github/workflows/ci.yml` returns 1 (D-15: install via path repo)
    - `grep -c "kunstmaan-migrator/doctor" .github/workflows/ci.yml` returns 1 (D-17: smoke assertion)
    - `grep -c "php-version: '8.3'" .github/workflows/ci.yml` returns 2 (D-16: 8.3 only; both jobs)
    - `grep -c "actions/upload-artifact" .github/workflows/ci.yml` returns 1 (D-09: clover artifact upload — planner-discretion choice taken)
    - `grep -cE "matrix:" .github/workflows/ci.yml` returns 0 (D-16: no matrix)
    - `grep -cE "release\\.yml|ship\\.yml" .github/workflows/ci.yml` returns 0 (D-26: no ship workflow)
    - `grep -c "migrate --live\|migrate:live" .github/workflows/ci.yml` returns 0 (D-24: no automated rehearsal in CI)
    - If `actionlint` is on PATH locally: `actionlint .github/workflows/ci.yml` exits 0
    - If repo state allows: a push to a feature branch followed by `gh run list --workflow=ci.yml --limit 1` shows the run completed both jobs (or smoke skipped due to unit fail). Document outcome in SUMMARY.
  </acceptance_criteria>
  <done>CI splits into unit + smoke; PCOV installed at system level; per-module 70% gate runs in CI; smoke confirms scratch-Craft + plugin install + doctor exit 0. Plan 05-08 RELEASE-CHECKLIST step 4 references "CI smoke green on recent commit".</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|---|---|
| GitHub Actions runner → repo checkout | Trusted by CI policy; runs in isolated VM per job |
| composer create-project → public Packagist | Trusted; standard Craft bootstrap |
| smoke runner → ./craft doctor | Doctor only reads env vars + checks plugin DI; no destructive writes |
| coverage clover artifact → repo viewer | Anyone with repo read access can download; coverage XML contains file paths only |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|---|---|---|---|---|
| T-05-07-01 | Spoofing | malicious shivammathur/setup-php release | accept | Pinned to `@v2` (major version); supply-chain risk same as any well-known GHA. Lameco's other Craft repos use the same action — established trust. |
| T-05-07-02 | Tampering | composer.json scripts modified to skip coverage | mitigate | Coverage step is explicit `composer test-coverage` line in ci.yml; if `composer.json` scripts.test-coverage gets weakened, the unit job still invokes the script. The chained-script + tools/check-coverage.php are external to ci.yml; modifying them shows up as a diff on the same PR. |
| T-05-07-03 | Repudiation | smoke passes on a broken plugin | mitigate | Doctor's 10 checks (Phase 4.1 deepened) include adapter-presence, env-source DI, locale Rung 0. Exit 0 = every component DI'd + every adapter check resolved. Adding e2e migrate runs is out of scope (D-24). |
| T-05-07-04 | Information Disclosure | CI logs public on push | accept | Doctor stdout contains check names + statuses; no secrets. composer install logs package versions; standard public CI shape. |
| T-05-07-05 | DoS | smoke job consumes CI minutes on every push | mitigate | needs: unit gates the spend; broken unit skips smoke. The whole pipeline runs ~3-5 minutes (verify in SUMMARY) — well within free-tier budget. |
| T-05-07-06 | Elevation of Privilege | scratch-Craft install runs install scripts | accept | composer create-project + composer require run their normal lifecycle scripts; trust-boundary same as any developer running these commands. |
</threat_model>

<verification>
- `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"` exits 0
- All grep checks pass
- A push (or PR) triggers both jobs; both pass within ~5 minutes total wall time (document in SUMMARY)
- The coverage-clover artifact appears under the run's Artifacts tab
- `git diff src/` empty (workflow change only)
- `git diff composer.json` empty (composer.json untouched in this plan)
- `git diff phpunit.xml.dist` empty (phpunit untouched in this plan)
</verification>

<success_criteria>
- D-15: scratch-Craft via composer create-project; path-type composer repository for plugin install
- D-16: PHP 8.3 only; no matrix
- D-17: smoke assertion = `./craft kunstmaan-migrator/doctor` exit 0
- D-18: unit + smoke split; smoke `needs: unit`; composer validate stays in unit's first composer step
- D-09 (planner-discretion): clover artifact uploaded for PR-time inspection
- TST-03 closure: composer-validate + phpunit (with per-module gate) + plugin-load smoke all pass in CI
- Plan 05-08 RELEASE-CHECKLIST step 4 references the CI green state as a v1.0 ship gate
</success_criteria>

<output>
After completion, create `.planning/phases/05-tests-rehearsal-release/05-07-SUMMARY.md` documenting:
- Wall-clock time of unit + smoke jobs from a real CI run (push to a feature branch in the same PR; capture timing from the GitHub Actions UI or `gh run view`)
- Whether the coverage gate caught any regression on first run (paste the per-module table from the unit job's `composer test-coverage` step)
- Whether the smoke job's doctor output ended in OK or WARN (both are exit 0 — D-17). Paste the WARN line(s) if any (typically: no KUNSTMAAN_SOURCE_PATH, no legacy DB; expected for a scratch site)
- Confirmation no ship.yml / release.yml workflows exist (D-26 honored)
</output>
