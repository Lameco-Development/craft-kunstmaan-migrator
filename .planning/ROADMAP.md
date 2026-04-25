# Roadmap

5 phases. Coarse granularity. Built greenfield against the v1.x plugin as
brownfield reference. Every v1 requirement maps to exactly one phase. v2
requirements (`NEXT-*`) are deferred to a follow-up milestone.

## Milestone: v1.0

| # | Phase | Goal | Requirements | Success Criteria | UI hint |
|---|-------|------|--------------|------------------|---------|
| 1 | Foundation & Connectivity | A scaffolded Craft 5 plugin with internal legacy-DB connectivity, the `kunstmaanmigrator_state` table, the `kunstmaanSourceId` field, the `NeverProductionTrait`, the `doctor` command, the `migrate/install` shim, and a green PHPUnit suite. | FND-01..05, FND-02a, CONN-01..03 | 5 | no |
| 2 | Schema, Mapping & Filters | `analyze` produces a schema dump + heuristic-and-LLM proposals into a single `mapping.yaml`; the `map` rubber-stamp loop walks proposals; coverage gate hard-blocks `--live`; mapping-audit detects drift; locale auto-detect + preflight; `MigrationFilters` plumbed through every stage. | MAP-01..07, FILT-01..03, LOC-01..02 | 5 | no |
| 3 | ETL Pipeline & Field Handlers | Extract → Transform → Load → Finalize stages with topological ordering, per-entry atomic load, idempotent re-runs, JIT assets (with `--preload-assets`), the six built-in field handlers, and CKEditor token rewrite. | ETL-01..07, FH-01..04, FIN-01..02 | 4 | no |
| 4 | Adapters, Verify & Settings | Optional SEOmatic + Retour adapters (runtime-detected, not composer-required), `verify` parity gate (counts + optional URL spot-check) producing a timestamped report, CP Settings page, console verbosity, rehearsal report artifact. | ADP-01..03, VER-01..03, CFG-01..03 | 4 | yes |
| 5 | Tests, Rehearsal & Release | Transform-stage characterization fixtures from a real dump, full unit suite green, CI workflow running validate + PHPUnit + plugin-load smoke test, rehearsal pass against the CQM dump, release checklist + tag. | TST-01..04 | 4 | no |

### Phase 1: Foundation & Connectivity

**Goal:** A scaffolded Craft 5 plugin that installs cleanly, owns its legacy DB connection, and exposes a working `doctor` command. PHPUnit suite is wired and non-empty on day one — no "tests skipped in 1.0" regret this time.

**Requirements:** FND-01, FND-02, FND-02a, FND-03, FND-04, FND-05, CONN-01, CONN-02, CONN-03.

**Success criteria:**
1. `composer require lameco/craft-kunstmaan-migrator` followed by `./craft plugin/install kunstmaan-migrator` succeeds on a stock Craft 5 install with no SEOmatic / Retour deps.
2. The state table `kunstmaanmigrator_state` and the `kunstmaanSourceId` field exist after install. If a v1.x `kunstmaanSourceId` field UID was already present, the new install reuses it.
3. `./craft kunstmaan-migrator/doctor` reports OK/FAIL for legacy DB, Anthropic key, mapping file presence, and `storage/migration/` write permission. Exits non-zero on any FAIL.
4. `CRAFT_ENVIRONMENT=production` causes any legacy-reading command to refuse with a clear error message.
5. `composer test` (`phpunit`) runs and is green; CI workflow runs the same on every push.

**Plans:** 5 plans

Plans:
- [x] 01-01-composer-scaffold-PLAN.md — composer.json + Plugin.php stub + NeverProductionTrait (FND-01) — completed 2026-04-25
- [ ] 01-02-settings-legacy-db-PLAN.md — Settings model + LegacyDbService + Plugin::init() with conditional legacyDb wiring (CONN-01, CONN-02)
- [ ] 01-03-install-migration-PLAN.md — Install.php (state table + kunstmaanSourceId field with UID reuse) + MigrateController::actionInstall (FND-02, FND-02a, FND-03)
- [ ] 01-04-doctor-command-PLAN.md — DoctorController with 3 preflight checks + NeverProduction enforcement (FND-04, CONN-03)
- [ ] 01-05-tests-ci-docs-PLAN.md — PHPUnit 11 + GitHub Actions CI + README + project-doc patches (FND-05)

### Phase 2: Schema, Mapping & Filters

**Goal:** The operator can run `analyze` against a Kunstmaan dump and end up with a single `mapping.yaml` populated by 9-heuristic auto-matches plus Anthropic proposals for residuals. The `map` rubber-stamp loop walks unresolved rows. Filters and locale handling are first-class.

**Requirements:** MAP-01..07, FILT-01..03, LOC-01..02.

**Success criteria:**
1. `analyze` against a fixture Kunstmaan dump emits `storage/migration/schema-dump.json` + `REPORT.md` and writes proposals to a single `mapping.yaml` with per-row `status:`.
2. The 9 deterministic heuristics resolve at least 60% of columns on the CQM rehearsal dump before any LLM call is made.
3. `kunstmaan-migrator/map` loops through unresolved rows interactively and persists decisions atomically to `mapping.yaml`.
4. `kunstmaan-migrator/migrate --live` refuses to start while any data-bearing legacy column has no mapping decision; `--dry-run` prints coverage warnings without blocking.
5. The five top-level CLI commands all accept `--entities=`, `--locales=`, `--since=`, `--max-per-entity=` and produce identical filter behaviour at every stage.

### Phase 3: ETL Pipeline & Field Handlers

**Goal:** End-to-end migration of a Kunstmaan dump's content into Craft, atomically per-entry, idempotent across re-runs, with all six field handlers in place and CKEditor body tokens resolved on the finalize pass.

**Requirements:** ETL-01..07, FH-01..04, FIN-01..02.

**Success criteria:**
1. `migrate --live` against the CQM rehearsal dump completes without error and produces Craft entries with the expected counts (within ±1% of baseline).
2. Re-running `migrate --live` after success skips already-migrated rows by `kunstmaanmigrator_state.legacy_id` — zero duplicates.
3. A forced failure mid-run leaves Craft in a consistent state (the failing entry rolled back, all earlier entries persisted).
4. `migrate/finalize` resolves the cross-entry `[NT<id>]` and media `[M<id>]` tokens left by the load pass; unresolvable tokens are annotated with `<!-- MIGRATION:UNRESOLVED ... -->` and reported in the run summary.

### Phase 4: Adapters, Verify & Settings

**Goal:** SEOmatic and Retour are migrated when their plugins are installed and skipped (with WARN) when they aren't — composer no longer hard-requires either. The verify parity gate produces a timestamped report and the CP exposes a Settings screen for the operator-facing toggles.

**Requirements:** ADP-01..03, VER-01..03, CFG-01..03.

**Success criteria:**
1. Plugin installs cleanly on a Craft 5 host with neither SEOmatic nor Retour. `migrate --live` completes; redirects + SEO bundles are skipped with WARN lines in the rehearsal report.
2. On a host with both plugins installed, redirects and per-locale SEOmatic MetaBundles are migrated — counts match baseline within tolerance.
3. `verify capture-baseline` + `verify` produces a `VERIFY-<timestamp>.md` report under `storage/migration/` regardless of pass/fail.
4. CP Settings page reads + writes Anthropic key, LLM model, LLM timeout, default filter values, and the `dryRunDefault` toggle.

### Phase 5: Tests, Rehearsal & Release

**Goal:** Characterization tests on the Transform stage make regressions cheap to catch, the CI workflow gates every PR, and the v1.0 release ships green against the CQM rehearsal corpus.

**Requirements:** TST-01..04.

**Success criteria:**
1. PHPUnit unit suite covers `MigrationFilters`, `MappingLoader`, every field handler, `CkeditorRewriter`, and the heuristic proposer — minimum 70% line coverage on those modules.
2. Transform-stage characterization fixtures from a real CQM extract run end-to-end through the Transform stage and produce golden-file-stable output across runs.
3. CI workflow on `main` runs `composer validate --strict`, `phpunit`, and a plugin-load smoke test on a scratch Craft 5 install — green on the release commit.
4. v1.0 rehearsal log against the CQM dump records: counts within tolerance vs baseline, zero unresolved CKEditor tokens beyond annotated ones, all assets ingested or explicitly recorded as failed with RCA tags.

---

## Dependencies

- Phase 2 depends on Phase 1 (needs `doctor`, state table, legacy DB connection).
- Phase 3 depends on Phase 2 (needs `mapping.yaml` and filter spec).
- Phase 4 can start after Phase 3 begins — adapter and verify work is largely independent of ETL details.
- Phase 5 depends on Phase 3 + Phase 4 being feature-complete.

## Out-of-milestone (deferred)

`NEXT-01` (writer seam for starter-kit-driven Craft scaffolding), `NEXT-02` (read-only CP status mirror), `NEXT-03` (multi-provider AI), `NEXT-04` (cross-client rehearsal matrix) are explicitly out of v1.0 — see `PROJECT.md` Out of Scope.
