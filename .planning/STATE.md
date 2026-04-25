---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: "| # | Phase | Goal | Requirements | Success Criteria | UI hint |"
status: Phase 02 in progress — Plan 02 complete (mapping-file)
last_updated: "2026-04-25T20:26:42Z"
progress:
  total_phases: 5
  completed_phases: 1
  total_plans: 11
  completed_plans: 7
  percent: 64
---

# State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-25)

**Core value:** An operator can take a Kunstmaan SQL dump and a configured Craft site, walk through an AI-assisted mapping review, and end up with a faithful migration of content into Craft — predictably, idempotently, and with a clear record of what was migrated and what was dropped.

**Current focus:** Phase 02 — schema-mapping-filters (Plans 01-02 complete; Plans 03-06 pending)

## Milestone

**Active:** v1.0 — Initial release of the revisited plugin.

5 phases:

1. Foundation & Connectivity
2. Schema, Mapping & Filters
3. ETL Pipeline & Field Handlers
4. Adapters, Verify & Settings
5. Tests, Rehearsal & Release

## Current Phase

**Phase 2: Schema, Mapping & Filters** — Plans 01-02 complete (filter+locale primitives + mapping-file shipped). 4 plans remain (03 analyze-pipeline, 04 map-rubber-stamp, 05 coverage-audit-doctor, 06 tests-and-doc-patches).

## Recent Activity

- 2026-04-25: Phase 2 / Plan 02 (mapping-file) executed. 2 tasks, 2 commits (00aa2d3, 15acd89). MappingFile lands at src/mapping/MappingFile.php (196 LOC) as a final Yii Component consolidating v1's MappingDraftReader (303 LOC) + MappingDraftWriter (384 LOC). Eight public methods: resolvePath, load, loadProposed, buildRow, merge, setStatus, writeAtomic, writeAtomicJson. D-01 honored — single mapping.yaml with per-row status; no .draft / .drops / DESIGN-GAPS sidecars. D-04 honored — merge keys on (table, column, targetEntryType) tuple, preserves every existing row verbatim, only appends incoming rows whose tuple is unseen (operator decisions sacred per MAP-04). D-07 honored — writeAtomic uses tmp + rename with bin2hex(random_bytes(4)) suffix; setStatus rewrites the whole file via writeAtomic so the Plan 04 map loop gets atomic-always-on per-keypress for free. writeAtomicJson sibling helper added (not a v1 port) so Plan 03's SchemaDumper has the same atomic-write contract for schema-dump.json. Plugin::config() expanded from 3 to 4 components; @property-read MappingFile $mappingFile added. composer test stays green (7 tests, 11 assertions). MAP-04 satisfied; MAP-01 partial — analyze pipeline lands in Plan 03.
- 2026-04-25: Phase 2 / Plan 01 (filter-locale-primitives) executed. 4 tasks, 4 commits (dc50088, 8fa4bcc, ac78230, eb06930). MigrationFilters value object lands at src/filter/MigrationFilters.php with exactly three readonly properties (entities, locales, since) per D-12 — no maxPerEntity reference anywhere. FilterFactory at src/filter/FilterFactory.php implements D-10 merge rules: null CLI arg falls through to Settings::default*, '' clears default, non-empty comma-splits + trims; each filter independent. LocalePreflight at src/locale/LocalePreflight.php ships detect() (DISTINCT lang FROM kuma_node_translations) and ensure(MigrationFilters): ?array (returns null on pass / unmapped list on LOC-02 fail; scopes check to filters->locales when explicitly set). Plugin::config() expanded from 1 to 3 components (legacyDbService preserved, filterFactory + localePreflight added) with matching @property-read PHPDoc lines. composer test still green (7 tests, 11 assertions). FILT-01, FILT-02, FILT-03, LOC-01, LOC-02 satisfied. Paste-ready sites: block rendering deferred to ReportBuilder in Plan 03.
- 2026-04-25: Phase 2 context captured (`02-CONTEXT.md`, `02-DISCUSSION-LOG.md` — commit 9990f5e). 17 decisions covering: D-01..D-04 (flat `proposals:` list with status-on-row, four-tier confidence→status, drop-reason in rationale, skip-existing re-run merge); D-05..D-08 (compact one-screen rubber-stamp UX, two-step `[r]emap` picker, atomic per-keypress write, stateless resume); D-09..D-13 (Kunstmaan source-class allow-list, per-filter CLI override, column-presence `--since` on AbstractArticlePage's `date` column, `--max-per-entity` DROPPED — patches FILT-01 + ROADMAP success criterion 5); D-14..D-17 (schema-dump-minus-structural-minus-zero-fill coverage definition, hard `--live`/warn `--dry-run` gate behavior, console+MAPPING-AUDIT.md drift findings warn-only with `--audit-strict` opt-in, locale preflight on every legacy-reading command). v1 brownfield reuse plan: HeuristicProposer (407 LOC) and LlmClassifier (481 LOC) port near-verbatim; MappingDraftReader/Writer port with status-on-row reshape; MappingValidator (647 LOC) ports for the new MappingAuditor; ProposalRouter is fully replaced; AnalyzeController collapses from 2138 LOC / 9 sub-actions to a single entrypoint; v1's MigrationFilters (post-Craft scope) is reference-only — v2 redesigns for legacy-side scoping.
- 2026-04-25: Phase 1 / Plan 05 (tests-ci-docs) executed. 4 tasks, 4 commits (614e469, 87f10dc, 2c21386, e99574a). FND-05 closed: phpunit.xml.dist + tests/bootstrap.php + tests/PluginBootstrapTest.php (3 assertions per D-21) ship a non-empty smoke suite — composer test exits 0 with OK (3 tests, 3 assertions). .github/workflows/ci.yml is single-job (PHP 8.3 / ubuntu-latest, validate + install + test) per D-22 — no Deptrac, no FQCN-lint, no matrix expansion (TST-03 plugin-load smoke test deferred to Phase 5). README.md ships operator minimum (328 words: install + 8 env vars + doctor + production-safety; UPGRADING.md long-form deferred to Phase 5 release). Three doc patches: REQUIREMENTS.md FND-02 column-list correction (10 cols + UNIQUE + INDEX); REQUIREMENTS.md CONN-03 wording amended to acknowledge mapping-file check ships in Phase 2 alongside the loader (deferred per D-17); PROJECT.md Key Decisions row "Keep v1's kunstmaanmigrator_state schema verbatim" — same column-list correction. All three docs now consistent with src/migrations/Install.php. **Phase 1 feature-complete.**
- 2026-04-25: Phase 1 / Plan 04 (doctor-command) executed. 1 task, 1 commit (ea24a39). DoctorController lands the three preflight checks per D-17 (legacy DB SELECT 1 reachability, Anthropic key presence via Settings-or-env, storage/migration writability with D-18 auto-create at 0755). actionIndex first-statement-gates on enforceNeverProduction (D-20) — FND-04 now satisfied across both Phase 1 controllers. T-1-01 / T-1-03 / T-1-04 STRIDE mitigations verified by greps; T-1-08 accepted. v1's checkQueueWorker (CLI-inline default) and checkMapping (defers to Phase 2) explicitly dropped. Plain-text OK/FAIL output with Console::FG_GREEN / FG_RED / FG_CYAN. Exit 0 on full pass / 1 on any FAIL. && -against-$ok pattern ensures every check runs even after a failure. FND-04, CONN-03 (partial — 3 checks per D-17, mapping check deferred to Phase 2) satisfied.
- 2026-04-25: Phase 1 / Plan 03 (install-migration) executed. 2 tasks, 2 commits (898d632, 8ba9d1a). Install.php lands the v1.x-verbatim D-06 state-table schema (10 cols, 2 indexes) guarded by tableExists; D-09 UID-reuse chain (project-config → getFieldByHandle → mint) preserves the 570-row CQM rehearsal continuity; safeDown is a verbatim no-op per FND-03 / D-10. MigrateController ships only actionInstall (Phase 3 actions deferred per D-05) with NeverProduction gate first (D-20) and MigrationManager wired on track 'kunstmaanmigrator'. FND-02, FND-02a, FND-03 satisfied.
- 2026-04-25: Phase 1 / Plan 02 (settings-legacy-db) executed. 3 tasks, 3 commits (e27e375, 09911ea, 9c05e3b). Settings model with full v2 surface (8 read-active + 8 declared) lands; LegacyDbService Yii Component (5 read-only methods) lands; Plugin::init() promoted from stub to full Phase 1 form with conditional legacyDb registration (D-11), console controllerNamespace switch, createSettingsModel(), settingsHtml() + placeholder _settings.twig. CONN-01, CONN-02 satisfied.
- 2026-04-25: Phase 1 / Plan 01 (composer-scaffold) executed. 4 tasks, 3 commits (0c8061e, f8c1719, b608527). composer.json validates strict, PSR-4 autoload resolves Plugin + NeverProductionTrait FQCNs, schemaVersion=1.0.0 confirmed via reflection. FND-01 satisfied.
- 2026-04-25: Phase 1 context captured (`01-CONTEXT.md`, `01-DISCUSSION-LOG.md`). 25 implementation decisions across source layout, state schema, legacy DB wiring, settings + doctor edges, CI.
- 2026-04-25: Project initialized via `/gsd-new-project`. PROJECT.md, REQUIREMENTS.md, ROADMAP.md committed.

## Decisions

- Phase 2 / Plan 02 D-01: mapping.yaml is a single flat proposals: list with per-row status. v1's four-bucket layout (mapping.yaml + .draft + .drops + DESIGN-GAPS.md) is not ported; MappingFile knows about one file with one shape.
- Phase 2 / Plan 02 D-04: merge keys on (table, column, targetEntryType) tuple. Existing rows preserved verbatim; incoming rows only appended if their tuple is absent. There is no overwrite path, no smart diff. MAP-04 byte-for-byte.
- Phase 2 / Plan 02 D-07: writeAtomic = mkdir -p + write to ${path}.tmp.${bin2hex(random_bytes(4))} + rename($tmp, $path). setStatus wraps it for per-keypress atomic writes (Plan 04 map loop consumer).
- Phase 2 / Plan 02 design-note: writeAtomicJson is a sibling helper (not a v1 port). Plan 03's SchemaDumper writes schema-dump.json through it so the tmp+rename idiom lives in one place.
- Phase 2 / Plan 02 design-note: buildRow accepts initialStatus as an argument; MappingFile is status-agnostic. Confidence-tier → status logic (D-02) is applied by the Plan 03 analyze orchestration outside this class.
- Phase 2 / Plan 01 D-12: MigrationFilters has exactly three readonly properties (entities, locales, since); no maxPerEntity. Verified by grep -c maxPerEntity src/filter/MigrationFilters.php returning 0.
- Phase 2 / Plan 01 D-10: FilterFactory::fromCli implements three-state merge — null falls through to Settings::default*, '' clears default, non-empty comma-splits + trims (entities/locales) or used as-is (since). Each filter independent.
- Phase 2 / Plan 01 D-17: LocalePreflight::ensure returns null on pass or list of unmapped locales on fail. NO silent fallthrough; caller responsible for hard-fail. Service is detection + verdict only — paste-ready sites: block rendering deferred to ReportBuilder (Plan 03).
- Phase 2 / Plan 01 component-registration: MigrationFilters is a pure VO (instantiated by FilterFactory), NOT a Yii Component. Plugin::config() registers FilterFactory + LocalePreflight only. legacyDbService literal-string preserved so PluginBootstrapTest reflection assertion stays green.
- D-05: MigrateController in Phase 1 ships ONLY actionInstall; extract / transform / load / finalize are deferred to Phase 3.
- D-06: state-table schema is byte-for-byte v1.x (10 cols + 2 indexes); REQUIREMENTS.md FND-02 wording is now stale (Plan 05 updates it).
- D-07: Install.php is single source of install truth — v1.x's m000000_000000_install_migration_state.php and m260425_000000_upgrade_to_v2.php are NOT carried forward.
- D-09: UID-reuse chain is project-config → getFieldByHandle → mint. Literal 'kunstmaanSourceId' kept inline in getFieldByHandle for grep-based continuity assertions.
- D-10: safeDown() is a verbatim no-op (returns true). Operator wipes manually for full reset. FND-03 contract.
- D-20: NeverProduction gate is the first statement of every controller action body. Verified by ordering grep on actionInstall.
- D-08: schemaVersion declared as 1.0.0 (treat v2 as fresh plugin; v1.x→v2 swap-in handled by Install.php's tableExists guard)
- D-23: NeverProductionTrait ported byte-for-byte from v1 (no declare(strict_types=1))
- D-24: SEOmatic + Retour are composer suggest entries (not require); Deptrac + Rector dropped
- D-25: composer extra block uses handle=kunstmaan-migrator, schemaVersion=1.0.0, class=lameco\kunstmaanmigrator\Plugin
- D-11: legacyDb Yii application component registered conditionally via `!Craft::$app->has('legacyDb', true)` guard. Swap-in hosts retain config/app.php declaration; greenfield hosts get plugin's env-driven Connection.
- D-12: Settings sources legacyDb* from CRAFT_LEGACY_DB_* env vars; config/kunstmaan-migrator.php overrides win via `??=` idiom in Settings::init().
- D-13: LegacyDbService is read-only by discipline (5 methods: db/queryOne/queryAll/queryScalar/streamQuery). Domain helpers deferred to Phases 2-4. Verified by file-level grep — zero write-op symbols.
- D-14: anthropicApiKey resolves from ANTHROPIC_API_KEY env, Settings property override wins. Never echoed/logged.
- D-15: Settings model declares full v2 surface upfront (8 Phase-1 read-active + 8 Phase-2-4 declared). Phase 4 / CFG-01 plugs in without refactor.
- D-16: hasCpSettings = true; createSettingsModel() returns new Settings(); settingsHtml() renders placeholder _settings.twig (real form ships in Phase 4).
- D-03: console controllerNamespace = lameco\kunstmaanmigrator\console, switched only on console requests (web namespace deferred to Phase 4).
- D-17: doctor ships 3 checks (legacyDb, anthropicApiKey, storageDir). The mapping-file check listed in REQUIREMENTS.md CONN-03 defers to Phase 2 alongside the mapping loader/validator; Plan 05 patches the wording.
- D-18: storage/migration/ is auto-created with mode 0755 on first doctor invocation if missing (greenfield convenience; v1.x had no equivalent).
- D-19: doctor output style is plain-text OK/FAIL with two-space indent + ANSI colors (FG_GREEN / FG_RED / FG_CYAN). Exit 0 on full pass, 1 on any FAIL. && -against-$ok ensures all checks run even after a failure.
- D-21: PHPUnit 11 wired with non-empty smoke test on day one. tests/bootstrap.php is 4 lines (vendor autoload only) — Craft is NOT half-bootstrapped from unit context (v1's documented dead end). PluginBootstrapTest ships 3 assertions: Plugin loads, Settings + LegacyDbService load, Plugin::config() declares legacyDbService component (source-level reflection).
- D-22: CI is single-job (PHP 8.3 / ubuntu-latest), validate + install + test. composer validate uses --no-plugins to dodge craftcms/plugin-installer Filesystem absolute-path error. Matrix expansion + plugin-load smoke test (TST-03) deferred to Phase 5.

## Last Session

- **Last:** 2026-04-25T20:26:42Z
- **Stopped at:** Phase 2 / Plan 02 complete — mapping-file shipped (MappingFile + Plugin component registration)
- **Resume file:** `.planning/phases/02-schema-mapping-filters/02-03-analyze-pipeline-PLAN.md` (next plan in Phase 2)
- **Blockers:** None
- **Doc patches still queued for Phase 2 ship (Plan 06):** REQUIREMENTS.md FILT-01 (drop `--max-per-entity=N`), ROADMAP.md Phase 2 success criterion 5 (drop `--max-per-entity=` from flag list — three flags, not four)

## Reference Material

- Brownfield reference: `~/Sites/craft-kunstmaan-migrator` (v1.1 of the plugin we're rewriting). Critical-review notes captured in PROJECT.md Context section.
- Future starter-kit reference: `~/Sites/craft-starter-kit` (relevant for `NEXT-01` only — out of v1 scope).
