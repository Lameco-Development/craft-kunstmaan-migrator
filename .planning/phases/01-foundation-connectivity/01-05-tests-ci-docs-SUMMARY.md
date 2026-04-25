---
phase: 01-foundation-connectivity
plan: 05
subsystem: tests-ci-docs
tags: [phpunit, ci, docs, github-actions]
dependency-graph:
  requires: [01-01, 01-02, 01-03, 01-04]
  provides: [tests/PluginBootstrapTest, .github/workflows/ci, README.md]
  affects: [.planning/REQUIREMENTS.md, .planning/PROJECT.md]
tech-stack:
  added:
    - "PHPUnit 11.5.55 (already in require-dev from Plan 01)"
    - "GitHub Actions: actions/checkout@v4, shivammathur/setup-php@v2"
  patterns:
    - "phpunit.xml.dist (committed) over phpunit.xml (gitignored, operator-overridable)"
    - "Source-level reflection for Plugin::config() assertion (no Craft container in unit context)"
    - "Single-job CI (no matrix, no Deptrac, no FQCN-lint) per D-22"
key-files:
  created:
    - phpunit.xml.dist
    - tests/bootstrap.php
    - tests/PluginBootstrapTest.php
    - .github/workflows/ci.yml
    - README.md
  modified:
    - .planning/REQUIREMENTS.md (FND-02 column-list correction; CONN-03 mapping-check deferral wording)
    - .planning/PROJECT.md (Key Decisions row "Keep v1's kunstmaanmigrator_state schema verbatim" — column-list correction)
decisions:
  - "D-21: PHPUnit 11 wired with non-empty smoke test on day one (3 assertions: Plugin loads, Settings + LegacyDbService load, Plugin declares legacyDbService component)"
  - "D-22: single-job CI (PHP 8.3 / ubuntu-latest); matrix expansion + plugin-load smoke test (TST-03) deferred to Phase 5"
  - "phpunit.xml.dist (not phpunit.xml) so operators can locally override; standard PHPUnit convention"
  - "Test bootstrap is 4 lines (vendor autoload only) — no Craft bootstrap (half-bootstrapping Craft from a unit-test context is the v1 dead end documented by testFullCircularDiCheckIsDeferredToConsumerRehearsal)"
  - "composer validate uses --no-plugins to work around craftcms/plugin-installer Filesystem absolute-path error (v1 workaround inherited)"
  - "README.md scope is operator-minimum (install + env vars + doctor + production-safety); UPGRADING.md long-form deferred to Phase 5 release"
metrics:
  duration: "~4 minutes"
  completed: 2026-04-25
---

# Phase 1 Plan 05: Tests, CI & Docs Summary

PHPUnit 11 wired with a non-empty PluginBootstrapTest (3 passing assertions), single-job GitHub Actions CI (validate + install + test on PHP 8.3 / ubuntu-latest), operator-facing README, and three doc-consistency patches that align REQUIREMENTS.md / PROJECT.md with the actual `kunstmaanmigrator_state` schema shipped by Plan 03 — closing FND-05 and the open Phase-1 doc debt in one plan.

## What Shipped

### Task 1: PHPUnit 11 wiring (commit 614e469)

- `phpunit.xml.dist` — bootstrap=`tests/bootstrap.php`, scans `tests/`, `requireCoverageMetadata="false"`, `cacheDirectory=".phpunit.cache"`.
- `tests/bootstrap.php` — 4-line vendor/autoload.php loader. Deliberately does NOT bootstrap Craft (D-21 / PATTERNS.md `<discretion>`).
- `tests/PluginBootstrapTest.php` — `final class PluginBootstrapTest extends TestCase` with 3 test methods:
  - `testPluginClassIsLoadable` — `class_exists(Plugin::class, true)`.
  - `testKeyServiceClassesAreLoadable` — checks `LegacyDbService` and `Settings` autoload via PSR-4.
  - `testPluginDeclaresLegacyDbServiceComponent` — source-level reflection via `file_get_contents` on `Plugin.php` to assert the literal `'legacyDbService' => LegacyDbService::class` string. A future refactor that drops the component fails this test loudly.
- `composer test` exits 0 with `OK (3 tests, 3 assertions)`. **FND-05 satisfied** — suite is non-empty on day one per D-21.

### Task 2: GitHub Actions CI workflow (commit 87f10dc)

- `.github/workflows/ci.yml` — single-job, `runs-on: ubuntu-latest`, PHP 8.3 only.
- Steps: `actions/checkout@v4` → `shivammathur/setup-php@v2` → `composer validate --strict --no-plugins` → `composer install --no-interaction --no-progress` → `composer test`.
- `--no-plugins` on validate works around the `craftcms/plugin-installer` Filesystem absolute-path error v1 hit when validating the plugin's own repo.
- **Explicit drops** (deferred per D-22 / PATTERNS.md "Drop from v1"): Deptrac (no three-tier layout in v2), `assert-fqcn-loadable.php`, `--optimize-autoloader`, PHP version matrix, multi-OS matrix, plugin-load smoke test (TST-03 / Phase 5).
- YAML parses via `Symfony\Component\Yaml\Yaml::parseFile`; `! grep -iE 'deptrac|fqcn|matrix'` confirms deferred items absent.

### Task 3: Operator-facing README.md (commit 2c21386)

- 328 words — under the 600-word ceiling for operator-minimum.
- Sections: Status (Phase 1 boundary explicit), Requirements, Installation (`composer require` + `./craft plugin/install`), re-install (`./craft kunstmaan-migrator/migrate/install` per FND-02a), Configuration (8 env vars per D-12), Doctor (3 checks per D-17), Production safety (NeverProductionTrait blocks `CRAFT_ENVIRONMENT=production`), Development (`composer install` + `composer test`), License.
- Does NOT promise `kunstmaan-migrator/{analyze,map,verify}` (intentionally absent until Phases 2-4 ship).
- UPGRADING.md long-form (v1.x → v2 swap-in playbook) deliberately deferred to Phase 5 release per CONTEXT.md "Claude's Discretion" / `<deferred>` block — there's no tested upgrade path to document yet.

### Task 4: Doc-consistency patches (commit e99574a)

Three surgical Edit operations:

1. **REQUIREMENTS.md FND-02** column list — replaced the stale v1-imagined names (`legacy_class, legacy_id, craft_id, migrated_at, status`) with v1.x's actual schema shipped by Plan 03 (`id, source, sourceKey, targetType, targetId, targetUid, siteId, meta, dateCreated, dateUpdated`, with UNIQUE on `(source, sourceKey, siteId)` and INDEX on `(dateUpdated)`). Also dropped the now-obsolete "Note: STALE" annotation Plan 03's verifier had appended.
2. **REQUIREMENTS.md CONN-03** wording — acknowledges the mapping-file check ships in Phase 2 alongside the mapping loader (deferred per D-17). Phase 1 ships 3 doctor checks, not 4.
3. **PROJECT.md Key Decisions row** "Keep v1's `kunstmaanmigrator_state` schema verbatim" — same column-list correction so the project doc matches the requirement and the migration code.

All three files now reference the same 10-column schema. The verifier and any future reader sees one schema description, not two contradictory ones.

## Verification

Plan-level verification block from PLAN.md `<verification>`:

```
1. composer dump-autoload --no-interaction         → exits 0 ✔
2. composer validate --strict --no-plugins         → ./composer.json is valid ✔
3. composer test                                   → OK (3 tests, 3 assertions) ✔
4. vendor/bin/phpunit --no-coverage --testdox      → reports 3 passing tests ✔
5. .github/workflows/ci.yml parses as valid YAML   → Symfony YAML parses ✔
6. README.md exists with operator-essential content → 328 words, all greps pass ✔
7. ! grep -q 'legacy_class' .planning/REQUIREMENTS.md → absent ✔
   ! grep -q 'legacy_class' .planning/PROJECT.md      → absent ✔
   grep -q 'mapping-file validity check ships in Phase 2' .planning/REQUIREMENTS.md → present ✔
8. Cross-doc consistency (REQUIREMENTS.md / PROJECT.md / src/migrations/Install.php) → same 10 columns ✔
```

All 4 tasks' `<acceptance_criteria>` blocks fully verified by their respective grep / parse / exit-code checks during execution.

## Phase 1 Success Criteria Status (per ROADMAP.md)

This plan closes the last open Phase-1 success criterion (SC5):

- **SC1** (composer install + plugin install) — Plans 01-04. ✔
- **SC2** (state table + `kunstmaanSourceId` field, UID-reuse) — Plan 03. ✔
- **SC3** (doctor reports 3 checks, exits non-zero on FAIL) — Plan 04 + CONN-03 wording fix in Plan 05. ✔
- **SC4** (`CRAFT_ENVIRONMENT=production` refusal) — Plans 03+04 NeverProductionTrait gates. ✔
- **SC5** (`composer test` green + CI runs same) — **this plan**. ✔

**Phase 1 is feature-complete.**

## Deviations from Plan

None — plan executed exactly as written.

The Phase 1 plans 01-05 collectively shipped zero auto-fixes (Rules 1-3) and zero architectural escalations (Rule 4). The plan-checker / patterns-mapper / context discipline paid off: every step was paste-ready and every acceptance grep passed first time.

## Authentication Gates

None encountered. Phase 1 has no third-party auth surface — composer install pulls public packages, PHPUnit and the CI workflow have no secrets.

## Threat Surface Scan

No new security-relevant surface introduced beyond what the plan's `<threat_model>` already enumerated:

- T-1-09 (Tampering, GH Actions versions) — accepted: `actions/checkout@v4` and `shivammathur/setup-php@v2` major-version-pinned per established v1 pattern; full SHA pinning is a Phase 5 hardening item.
- T-1-10 (InfoDisclosure, reflection in PluginBootstrapTest) — accepted: `file_get_contents` reads `Plugin.php` source for a literal-string assertion. No secrets or runtime values; same-repo static source. Standard PHPUnit pattern.
- T-1-11 (InfoDisclosure, README env vars) — accepted: env var NAMES only, never EXAMPLE values that look real (placeholders use `secret`, `sk-ant-...`).

No new STRIDE flags surfaced during execution.

## Self-Check: PASSED

Files created/modified all verified on disk:

- ✔ `phpunit.xml.dist` (FOUND)
- ✔ `tests/bootstrap.php` (FOUND)
- ✔ `tests/PluginBootstrapTest.php` (FOUND)
- ✔ `.github/workflows/ci.yml` (FOUND)
- ✔ `README.md` (FOUND)
- ✔ `.planning/REQUIREMENTS.md` (modified — FND-02 + CONN-03)
- ✔ `.planning/PROJECT.md` (modified — Key Decisions row)

Commits all verified in `git log`:

- ✔ `614e469` — feat(01-05): wire PHPUnit 11 with non-empty PluginBootstrapTest
- ✔ `87f10dc` — feat(01-05): add GitHub Actions CI workflow (D-22)
- ✔ `2c21386` — docs(01-05): add operator-facing minimum README
- ✔ `e99574a` — docs(01-05): patch three doc inconsistencies (FND-02, CONN-03, PROJECT key decision)
