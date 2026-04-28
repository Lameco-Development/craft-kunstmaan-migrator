# v1.0 Release Checklist

**Pre-tag gate.** Every step must be green before pushing the v1.0 tag.

Manual + mechanical: every step has a pass/fail script behind it; no automated `ship.yml` workflow (Phase 5 / D-26 — re-evaluate post-v1.1 if shipping cadence demands it).

## Steps

1. [ ] **`composer validate --strict --no-plugins`** green.
   _Pass criterion:_ exit 0; output ends with `composer.json is valid`.

2. [ ] **`composer test`** green (Unit + Integration suites).
   _Pass criterion:_ exit 0; trailing `OK (N tests, M assertions)` line.

3. [ ] **`composer test-coverage`** green (per-module 70% line-coverage gate on every TST-01 module).
   _Pass criterion:_ exit 0; per-module table reports `OK` on every line; final line reports the gate as passed (`tools/check-coverage.php` exit 0 — no module under threshold).
   _Driver requirement:_ pcov OR xdebug installed locally (operator side); CI uses pcov via `shivammathur/setup-php`.

4. [ ] **CI smoke job green** on a recent commit (HEAD-of-main or the v1.0 release commit).
   _Pass criterion:_ both `unit` and `smoke` jobs pass on `.github/workflows/ci.yml`. The smoke job proves scratch-Craft plugin install/load and expected missing-runtime-config behavior only; it is not a successful migration rehearsal. Verify via `gh run list --workflow=ci.yml --limit 1` or the GitHub UI.

5. [ ] **CQM real workflow rehearsal complete:** `doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify`.
   _Pre-requisite:_ CQM is run against `~/Sites/cqm-website/` as source and the configured `~/Sites/cqm-craft-website/` Craft target.
   _Pass criterion:_ each command exits with the expected status; `compile` writes `storage/migration/PAGE-ROOTED-COVERAGE.md`; dry-run is reviewed before live; `verify` produces the release report.

6. [ ] **CQM Page-rooted coverage report reviewed.**
   _Pass criterion:_ `storage/migration/PAGE-ROOTED-COVERAGE.md` exists and every row categorized `dropped`, `out_of_scope`, `unsupported`, or `warning` has an explicit release disposition. `migrated` rows must cover the expected Kunstmaan Page-owned surfaces. Missing surfaces are acceptable only when the report category and reason match the v1.0 scope.

7. [ ] **CQM `kunstmaan-migrator/rehearsal/check`** exits 0 against `.planning/rehearsal/v1.0/cqm/`.
   _Pre-requisite:_ operator has captured CQM rehearsal artifacts (REPORT.md, VERIFY.md, baseline.json, doctor-output.txt, mapping-summary.txt) under that directory per `.planning/rehearsal/v1.0/cqm/README.md`.
   _Pass criterion:_ `./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm` exits 0 with all three gates (count tolerance, zero unresolved CKEditor tokens, full asset RCA) reporting OK on stdout.
   _This is the binding v1.0 ship gate (Phase 5 / D-19, D-23)._

8. [ ] **Structural source-shape audit captured for CQM, Simac, and Enreach.**
   _Pass criterion:_ run `php tools/audit-source-shapes.php ~/Sites/cqm-website ~/Sites/simac-website ~/Sites/enreach-website` (or the subset present on the release host) and store only structural findings in the release notes/checklist. Output may include counts, class names, table names, relation types, relation metadata presence, and risk flags; it must not include source method bodies, property values, secrets, SQL row data, or content samples.

9. [ ] **Simac + Enreach structural sample notes captured** under `.planning/rehearsal/v1.0/{simac,enreach}/` (advisory).
   _Pass criterion:_ both directories document source-shape audit observations. Simac/Enreach Craft targets are not required for v1.0 unless separately configured by the operator; failures to run a Craft migration there do NOT block the v1.0 tag.

10. [ ] **`CHANGELOG.md` rewritten for v1.0.**
   _Pass criterion:_ `## 1.0.0 — <date>` heading present at the top of the unreleased / latest entry; the `<release-date>` placeholder substituted with the actual tag date; Breaking Changes / Added / Changed / Removed / Security sections describe v2-vs-v1.x scope.

11. [ ] **Tag pushed; `STATE.md` updated; milestone closed via `/gsd-complete-milestone`.**
   _Pass criterion:_ `git tag v1.0.0` pushed to origin; `.planning/STATE.md` reflects "v1.0 milestone closed"; `/gsd-complete-milestone` ran.

## Composer.json `version` field — INTENTIONALLY NOT REQUIRED

Phase 5 / D-25 originally listed a step 8 conditional on Lameco's release-process convention ("only if Lameco's release process pins versions in composer.json"). Pre-planning verification:

- `~/Sites/craft-kunstmaan-migrator/composer.json` (v1.x reference plugin) — no `version` field
- `~/Sites/craft-seo-import/composer.json` — no `version` field
- `~/Sites/craft-entry-optimizer/composer.json` — no `version` field

Composer derives the version from the git tag (`v1.0.0` → `1.0.0`); the `extra.schemaVersion` Craft uses for plugin migrations is separate and unrelated to release version. The conditional therefore resolves to "do not add a version step." This decision is recorded here so a future plugin maintainer can see the rationale and not re-add it without context.

## Pre-publish gate — NOT part of v1.0 ship

The repo currently lives under `lameco/` and is private. CQM rehearsal fixtures (`.planning/rehearsal/v1.0/cqm/REPORT.md` etc., and `tests/fixtures/transform/input/*.json`) commit verbatim with NL-diacritic data, image references, user names — operator-grade realism per Phase 5 / D-04.

**If/when** the repo goes public under any non-`lameco/` namespace:

- Anonymize all CQM-derived fixtures (rehearsal artifacts + transform fixtures) via a scrub pass before publishing
- Verify no embedded credentials / API keys / private URLs in any committed file
- Re-run `composer test` after the scrub to confirm the corpus still passes

The pre-publish gate is **NOT** a v1.0 ship gate; it is a future concern that lands when the repo's namespace changes.

## No automated `ship.yml` workflow

Phase 5 / D-26 explicitly chose against an automated `ship.yml` / `release.yml` GitHub Actions workflow for v1.0. The release path is operator-driven and manual, gated by this checklist. Re-evaluate post-v1.1 if shipping cadence demands automation.
