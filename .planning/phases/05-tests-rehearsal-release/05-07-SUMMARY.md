---
phase: 05
plan: 07
subsystem: ci
tags: [ci, github-actions, smoke-test, coverage, doctor]
status: complete
requires:
  - 05-02 (composer test-coverage script + tools/check-coverage.php + phpunit clover config)
  - 04.1 (doctor controller deepening — adapter checks, env-source DI, locale Rung 0)
provides:
  - "Two-job CI pipeline: unit (composer-validate + tests + per-module coverage gate) and smoke (scratch-Craft + plugin install + doctor exit 0)"
  - "Coverage clover artifact uploaded on every push/PR (downloadable from the Actions run)"
  - "Plan 05-08 RELEASE-CHECKLIST step 4 reference target: 'CI smoke green on a recent commit'"
affects:
  - .github/workflows/ci.yml (rewritten — single test job → two jobs, unit + smoke)
tech-stack:
  added: []
  patterns:
    - "shivammathur/setup-php@v2 with coverage: pcov — system-level PCOV install (no composer dep; PATTERNS callout 3)"
    - "composer create-project craftcms/craft scratch-craft — official Craft 5 scaffolding for plugin-load smoke (D-15)"
    - "composer config repositories.plugin path ../plugin — register checkout as path-type repo for @dev install (D-15)"
    - "needs: unit on smoke job — gates CI minutes; smoke skipped when unit broken (D-18)"
    - "actions/upload-artifact@v4 with if: always() + if-no-files-found: warn — coverage clover survives failed runs"
key-files:
  created: []
  modified:
    - .github/workflows/ci.yml
decisions:
  - "Renamed the original 'test' job to 'unit' rather than keeping the old name — the plan-frontmatter must_haves explicitly require job name 'unit' for needs: unit gating"
  - "Coverage clover artifact uploaded under a single name 'coverage-clover' (no per-PR suffix) — D-09 planner-discretion; consistent name simplifies download in PR review"
  - "CRAFT_ENVIRONMENT: dev set on the smoke step for clarity (NeverProductionTrait keys off this, even though doctor itself is not gated by it)"
metrics:
  tasks_completed: 1
  tasks_total: 1
  files_modified: 1
  files_created: 0
  duration_minutes: ~5
  completed: 2026-04-27
---

# Phase 05 Plan 07: CI Smoke Job Summary

Split `.github/workflows/ci.yml` into a `unit` job (existing test pipeline + per-module 70% coverage gate via PCOV at the system level + clover artifact upload) and a new `smoke` job that bootstraps a scratch Craft 5 install via `composer create-project`, registers this repo as a path-type composer repository, installs the plugin via `composer require lameco/craft-kunstmaan-migrator @dev`, and asserts `./craft kunstmaan-migrator/doctor` exits 0 — gated on `needs: unit` to skip smoke when unit is broken (D-18).

## What was built

**One file modified:** `.github/workflows/ci.yml`

**Before** (13 lines, 1 job):
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

**After** (55 lines, 2 jobs):

- **`unit` job** — renamed from `test`. Adds `coverage: pcov` to setup-php (D-06: system-level PCOV, no composer dep). Adds `composer test-coverage` step (D-07: invokes the per-module 70% gate from Plan 05-02 via `tools/check-coverage.php`). Adds `actions/upload-artifact@v4` step that uploads `build/coverage/clover.xml` as a `coverage-clover` artifact with `if: always()` so the clover survives a failed gate (D-09 — planner-discretion taken: artifact is downloadable for PR review).
- **`smoke` job** — `needs: unit` (D-18). Checks out the repo into `plugin/`, runs `composer create-project craftcms/craft scratch-craft` (D-15: official Craft 5 scaffolding, no Docker), registers `../plugin` as a `path` composer repository inside the scratch site, runs `composer require lameco/craft-kunstmaan-migrator @dev` (D-15: `@dev` matches the path-repo's branch), then runs `./craft kunstmaan-migrator/doctor` (D-17: exit 0 = pass; WARN exits 0; FAIL exits 1).

## Acceptance check results (all 16 PASS)

Run from the worktree HEAD before commit:

| Check                                                            | Expected | Got |
| ---------------------------------------------------------------- | -------- | --- |
| YAML well-formed (`ruby -ryaml -e ...`)                          | OK       | OK  |
| `unit` job key present (`grep -c '^  unit:'`)                    | 1        | 1   |
| `smoke` job key present (`grep -c '^  smoke:'`)                  | 1        | 1   |
| `needs: unit` (D-18)                                             | 1        | 1   |
| `coverage: pcov` (D-06)                                          | 1        | 1   |
| `composer validate --strict` (D-18)                              | 1        | 1   |
| `composer test-coverage` (D-07)                                  | 1        | 1   |
| `composer create-project craftcms/craft scratch-craft` (D-15)    | 1        | 1   |
| `composer config repositories.plugin path ../plugin` (D-15)      | 1        | 1   |
| `lameco/craft-kunstmaan-migrator @dev` (D-15)                    | 1        | 1   |
| `kunstmaan-migrator/doctor` (D-17)                               | 1        | 1   |
| `php-version: '8.3'` count on both jobs (D-16, no matrix)        | 2        | 2   |
| `actions/upload-artifact` (D-09)                                 | 1        | 1   |
| `matrix:` (D-16: no matrix)                                      | 0        | 0   |
| `release.yml` / `ship.yml` (D-26: no ship workflow)              | 0        | 0   |
| `migrate --live` / `migrate:live` (D-24: no automated rehearsal) | 0        | 0   |

## Structural assertions (Ruby YAML parser)

```ruby
d['jobs']['unit']                # truthy
d['jobs']['smoke']               # truthy
d['jobs']['smoke']['needs']      # == 'unit'
```

All three pass — verified during execution.

## CI run timing — DEFERRED

The plan's `<output>` section asks for "wall-clock time of unit + smoke jobs from a real CI run." This worktree has not yet been pushed to `origin`, so a real CI run has not executed. Recording the actual timings + the per-module coverage table + the doctor WARN/OK output requires:

1. The orchestrator to merge this worktree branch back into the wave-3 branch (alongside other wave-3 plans).
2. A push to `origin` (or PR open) to trigger the workflow.
3. `gh run view <run-id>` or the Actions UI to capture timings.

**Expected timings** (based on PATTERNS analysis of comparable Craft 5 plugin CI shapes):

- `unit` job: ~2–3 minutes (composer install ~45s, phpunit ~30s with current 5–6 test files, composer test-coverage ~30s + check-coverage.php ~1s, artifact upload ~5s).
- `smoke` job: ~3–4 minutes (composer create-project for Craft 5 ~90s, composer require with path repo ~30s, doctor command ~5s, plus runner provisioning).
- Total wall-clock with `needs: unit` serialization: ~5–7 minutes.

**Expected first-run doctor output** on a scratch site with no `KUNSTMAAN_SOURCE_PATH`, no legacy DB env vars, no SEOmatic, no Retour: WARN (every adapter check WARN; legacy-DB check WARN; env-source check WARN). Exit code 0 — D-17 contract honored.

**Expected coverage gate behavior** on first run: per-module 70% gate runs against the modules covered by Plan 05-05 / 05-06 unit + integration tests (also wave 3). If those modules ship characterization tests reaching 70%, gate passes. If not, gate fails and unit job goes red. The next operator (running RELEASE-CHECKLIST in Plan 05-08) will capture the actual table and update this section if the gate trips.

This is documented as a known follow-up rather than a blocker — the plan's success criteria (D-15/D-16/D-17/D-18 + TST-03 closure) are all about the workflow shape, not about a green run on first push.

## Threat-model coverage

All six STRIDE rows from the plan's `<threat_model>` are addressed structurally by the workflow as written:

- **T-05-07-01 (Spoofing — malicious setup-php release):** Pinned to `@v2`; accepted risk per plan.
- **T-05-07-02 (Tampering — composer scripts weakened):** Mitigated; `composer test-coverage` line is explicit in ci.yml; check-coverage.php external; any tamper shows in same-PR diff.
- **T-05-07-03 (Repudiation — smoke passes broken plugin):** Mitigated; doctor's deepened checks (adapter presence, env-source DI, locale Rung 0) guard the exit-0 contract.
- **T-05-07-04 (Info disclosure — public CI logs):** Accepted; no secrets emitted.
- **T-05-07-05 (DoS — CI minutes burn):** Mitigated; `needs: unit` gate.
- **T-05-07-06 (EoP — composer install scripts):** Accepted; standard composer-create-project + composer-require lifecycle.

No new threat surface beyond what the plan's threat register already enumerates — no Threat Flags section needed.

## Deviations from Plan

None — workflow matches the plan's <action> block verbatim. No Rule 1/2/3 fixes; no Rule 4 architectural questions raised.

The PreToolUse security_reminder_hook fired on the Write call (it fires on any GitHub workflow edit). The workflow contains no untrusted GitHub event inputs (`github.event.*`, `github.head_ref`, etc.) — only static composer commands and the doctor controller invocation. No injection vector exists; no remediation needed.

## Verification artifacts

- `.github/workflows/ci.yml` (55 lines, 2 jobs, all grep checks pass)
- `composer.json` `scripts.test-coverage` — verified present (line 42, from Plan 05-02)
- `tools/check-coverage.php` — verified present (2.7 KB, from Plan 05-02)
- `phpunit.xml.dist` — verified `<clover outputFile="build/coverage/clover.xml"/>` (line 29, from Plan 05-02)
- `src/console/DoctorController.php` `actionIndex()` — verified present (line 59, from Phase 1 + 4.1 deepening)
- `composer.json` `extra.handle` — verified `"kunstmaan-migrator"` (line 49) → `kunstmaan-migrator/doctor` command alias resolves
- D-26 honored: `ls .github/workflows/` returns only `ci.yml`; no `ship.yml` / `release.yml`

## Self-Check: PASSED

- `.github/workflows/ci.yml` — FOUND
- Commit `ae9a2f1` — FOUND in `git log`
- All 16 acceptance grep checks pass
- YAML structure assertions pass (Ruby YAML parser)
- `git diff src/`, `git diff composer.json`, `git diff phpunit.xml.dist` all empty (verified — only `.github/workflows/ci.yml` modified)

## Commits

- `ae9a2f1` — `ci(05-07): split CI into unit + smoke jobs (D-15..D-18)`
