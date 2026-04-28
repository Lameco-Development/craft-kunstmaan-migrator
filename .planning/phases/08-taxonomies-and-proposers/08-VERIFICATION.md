---
phase: 08-taxonomies-and-proposers
verified: 2026-04-27T00:00:00Z
status: passed
score: 19/19 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: none
  previous_score: n/a
  gaps_closed: []
  gaps_remaining: []
  regressions: []
---

# Phase 8: Taxonomies & AI Proposer Coverage — Verification Report

**Phase Goal:** Port v1's `TaxonomyMigrationService` and add the missing
`taxonomies:` mapping block (analyze proposer + compile + extract + load);
extend the analyze AI proposer to cover `dataProviders` and page-builder
layout (`headerBlock` / `bodyWrapBlock` / `bodyColumn`) so operator
hand-authoring shrinks; document remaining out-of-scope Kunstmaan surfaces
in CHANGELOG.

**Verified:** 2026-04-27
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (from ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `taxonomies:` mapping block accepted by `MappingFile` / `MappingAuditor` / `MappingCompiler` | VERIFIED | `MappingFile::buildTaxonomyRow` line 243; `MappingAuditor` taxonomy branch line 115; `MappingCompiler::compileTaxonomies` line 762 |
| 2 | Analyze AI proposer emits taxonomy candidates for non-Page entities | VERIFIED | `LlmClassifier::proposeNonPageEntities` line 395; emits `kind=taxonomy` rows (lines 519/539/573) |
| 3 | `TaxonomyMigrationService` ported (verbatim with 5 documented reshape points) | VERIFIED | `src/load/TaxonomyMigrationService.php` 483 LOC; D-08/D-09/D-56 reshape markers verified inline (5 reshape points called out in docblock); RECONCILIATION 16-row table |
| 4 | End-to-end taxonomy test drives one entity through stub→assert | VERIFIED | `tests/integration/load/TaxonomyMigrationTest.php` — 4 active tests passing + 1 plan-permitted `markTestIncomplete` for D-09 fallback (test seam only; fallback IS implemented at lines 388-413) |
| 5 | `dataProviders` proposer emits orphan page-part candidates | VERIFIED | `LlmClassifier::proposeDataProviders` line 968; `kind=dataProvider` emit at lines 1085/1127; compiled via `MappingCompiler::compileDataProviders` line 878 |
| 6 | Page-builder layout proposer for `headerBlock` / `bodyWrapBlock` / `bodyColumn` | VERIFIED | `LlmClassifier::proposeLayoutBlocks` line 715; `kind=nodeClass` partial-update emit line 858; folded by `MappingCompiler::compileLayoutBlocks` line 835 (skip-existing per slot) |
| 7 | Compile surfaces counters (`taxonomiesEmitted`, `layoutBlocksEmitted`, `dataProvidersEmitted`) | VERIFIED | `MappingCompiler` lines 391-393 in `_compileReport`; CompileController emits all three via stdout (lines 210/217/224) |
| 8 | CHANGELOG "Known omissions in v1.0" lists 9 deliberately-out-of-scope surfaces | VERIFIED | CHANGELOG.md lines 116-138; 9 omissions documented (FormBundle, SearchBundle, MenuBundle, user accounts, kuma_translations, media folder hierarchy, asset metadata, slug history, drafts); README line 25 + PROJECT.md line 50 cross-link to anchor |

**Score:** 8/8 ROADMAP truths verified.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/load/TaxonomyMigrationService.php` | Verbatim port of v1's 443 LOC + 5 reshape markers | VERIFIED | 483 LOC; D-08/D-09/D-56 inline markers grep-verified; per-row atomic transaction at line 187; SQL whitelist at line 150 |
| `src/Plugin.php` taxonomyMigrationService DI | Component config slot + sibling-DI fanout | VERIFIED | Component map line 169; DI fanout lines 324-332 (legacyDb + migrationState + mappingFile) |
| `src/console/MigrateController.php` taxonomies wiring | Step 4.5 bolt-on (BEFORE pages) + actionTaxonomies sub-action | VERIFIED | Line 282 Step 4.5 in actionIndex; line 571 actionTaxonomies (gated by NeverProductionTrait); D-04 invariant — no `--no-taxonomies` flag |
| `src/analyze/LlmClassifier.php` 3 new proposers | `proposeNonPageEntities`, `proposeLayoutBlocks`, `proposeDataProviders` | VERIFIED | Lines 395, 715, 968 (public methods) + chunk methods at 461, 794, 1017 |
| `src/console/AnalyzeController.php` steps 7.7/7.8/7.9 | Dispatch all 3 new proposers with `--no-layout` / `--no-providers` gates | VERIFIED | Step 7.7 line 503; Step 7.8 line 604; Step 7.9 line 696; CLI flags at lines 58-59; Settings consultation at lines 611, 699 |
| `src/compile/MappingCompiler.php` 3 new compile passes | `compileTaxonomies`, `compileLayoutBlocks`, `compileDataProviders` | VERIFIED | Lines 762, 835, 878 (private methods); orchestration lines 350, 359, 367 |
| `src/console/CompileController.php` counter surfacing | 3 stdout lines for the new counters | VERIFIED | Lines 210/217/224 emit `compiled N taxonomy / layout / dataProvider block(s)` |
| `src/mapping/MappingFile.php` row builders | `buildTaxonomyRow` + `buildDataProviderRow` | VERIFIED | Lines 243, 269; merge-key dispatch at lines 307, 310 |
| `src/mapping/MappingAuditor.php` taxonomy audit branch | `kind=taxonomy` audit + `taxonomy-no-column-rows` finding | VERIFIED | Line 115 (branch); line 193 (finding kind) |
| `src/source/DoctrineEntityParser.php` Gedmo scan | `Gedmo\Mapping\Annotation\*` namespace scan extending existing ORM scan | VERIFIED | Line 239 per-property flag; `propertyHasGedmoTranslatable` at line 282; SRC-20 invariant preserved (line 304: docblock `@Gedmo` MUST NOT trip flag) |
| `src/source/DoctrineColumnInfo.php` `isGedmoTranslatable` | Per-property bool flag | VERIFIED | Line 32 readonly bool default false |
| `src/source/KnowledgeBase.php` `renderTaxonomiesMarkdown` | New public method mirroring `renderPagePartsMarkdown` | VERIFIED | Line 373; throws when `legacyDb` unset (line 376) |
| `src/db/LegacyDbService.php` `extTranslationsFor` | Verbatim port from v1 | VERIFIED | Line 156; named-bind-parameter shape preserved per docblock at line 136 |
| `src/source/KunstmaanCoreTables.php` `EXT_TRANSLATIONS` | Constant added | VERIFIED | Line 31 `'ext_translations'` (NOT `kuma_`-prefixed) |
| `src/filter/MigrationFilters.php` reachability auto-include | `allows()` consults `relationGraph` reachable cache | VERIFIED | `allows()` line 70; `reachable()` cache line 86; D-04 mandate |
| `src/console/DoctorController.php` 11th check `checkExtTranslations` | INFO/WARN/OK; never FAIL per D-09 | VERIFIED | Line 589; line 90 chains into actionIndex `$ok` ladder |
| `src/templates/_settings.twig` AI H2 group | `proposeLayout` + `proposeProviders` toggles | VERIFIED | Line 120 `<h2>AI</h2>`; lightswitches at 127, 135 |
| `src/models/Settings.php` AI proposer toggles | `proposeLayout` + `proposeProviders` (default `true`) | VERIFIED | Lines 93-94; rules() boolean validator at line 235 |
| CHANGELOG.md "Known omissions" | 9 surfaces under v1.0 entry | VERIFIED | Line 116 heading; 9 entries documented; README + PROJECT.md cross-link to anchor |
| 08-RECONCILIATION.md | 16-row reshape disposition table + net-additive surfaces | VERIFIED | 62 LOC; 16 reshape rows + 8 net-additive surfaces + open/deferred + cross-references |

### Required Tests

| Test | Status | Evidence |
|------|--------|----------|
| `tests/integration/load/TaxonomyMigrationTest.php` | VERIFIED | 4 active tests passing (skip-row defensive, SQL injection regex, empty-block warn-and-return, run-order); 1 plan-permitted `markTestIncomplete` for D-09 fallback (Plan 15 explicitly permits) |
| `tests/unit/compile/MappingCompilerTaxonomiesTest.php` | VERIFIED | Exists; passes |
| `tests/unit/compile/MappingCompilerLayoutBlocksTest.php` | VERIFIED | Exists; passes |
| `tests/unit/compile/MappingCompilerDataProvidersTest.php` | VERIFIED | Exists; passes |
| `tests/unit/source/DoctrineEntityParserAttributesOnlyTest.php` | VERIFIED | Exists; passes (Gedmo branches covered) |
| `tests/unit/source/KnowledgeBaseTaxonomiesTest.php` | VERIFIED | Exists; passes |
| `tests/unit/filter/MigrationFiltersReachabilityTest.php` | VERIFIED | Exists; passes |
| `tests/unit/console/DoctorControllerExtTranslationsCheckTest.php` | VERIFIED | Exists; passes |
| `tests/Unit/console/MigrateControllerTaxonomiesWiringTest.php` | VERIFIED | Exists; "Plugin declares taxonomy migration service wiring" passes |
| `tests/integration/PluginBootstrapTest.php` | VERIFIED | Exists; passes |

### Key Link Verification

| From | To | Via | Status | Details |
|------|------|-----|--------|---------|
| `MigrateController::actionIndex` | `TaxonomyMigrationService::migrateAll` | Step 4.5 bolt-on | WIRED | line 282; mergeReport line 294; runs BEFORE pages per D-03 |
| `MigrateController::actionTaxonomies` | `TaxonomyMigrationService::migrateAll` | NeverProductionTrait gate + sub-action | WIRED | line 571 entry; line 612 mergeReport |
| `Plugin::config()` | `TaxonomyMigrationService` component slot | components map | WIRED | line 169 |
| `Plugin::init()` | `TaxonomyMigrationService` deps | sibling-DI fanout | WIRED | lines 324-332 (legacyDb + migrationState + mappingFile); deliberately omits `sites` per D-08 (service reads `mapping.sites` itself) |
| `AnalyzeController` Step 7.7 | `LlmClassifier::proposeNonPageEntities` | direct call | WIRED | line 560 |
| `AnalyzeController` Step 7.8 | `LlmClassifier::proposeLayoutBlocks` | direct call | WIRED | line 660; gated by `--no-layout` (line 610) and `Settings::proposeLayout` (line 611) |
| `AnalyzeController` Step 7.9 | `LlmClassifier::proposeDataProviders` | direct call | WIRED | line 812; gated by `--no-providers` (line 698) and `Settings::proposeProviders` (line 699) |
| `MappingCompiler::compile()` | 3 new compile passes | direct call | WIRED | lines 350, 359, 367; counters returned in `_compileReport` lines 391-393 |
| `CompileController` | 3 new counter stdout lines | `_compileReport` consumption | WIRED | lines 210, 217, 224 |
| `DoctorController::actionIndex` | `checkExtTranslations` | `$ok` ladder | WIRED | line 90; INFO/WARN/OK never FAIL per D-09 |
| `MappingFile::merge()` | taxonomy/dataProvider builders | `kind` discriminator | WIRED | lines 307, 310 dispatch |
| `MappingAuditor::audit()` | taxonomy branch | `kind=taxonomy` switch | WIRED | line 115; emits `taxonomy-no-column-rows` finding |
| `TaxonomyMigrationService::applyGedmoTranslations` | `LegacyDbService::extTranslationsFor` | direct call | WIRED | line 384 (via `extTranslationsFor`); D-09 fallback at lines 388-413 |
| `DoctrineEntityParser::parseFile` | Gedmo per-property scan | `propertyHasGedmoTranslatable` | WIRED | line 282; flag flows through `DoctrineColumnInfo::isGedmoTranslatable` |
| `KnowledgeBase::renderTaxonomiesMarkdown` | LlmClassifier prompt feed | AnalyzeController plumbthrough | WIRED | exposed at line 373; AnalyzeController feeds output to `proposeNonPageEntities` |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| PHPUnit suite green | `composer test` | 375 tests, 1048 assertions, 1 incomplete (plan-permitted), 0 failures | PASS |
| Phase 8 specific tests green | `phpunit --filter "Taxonom\|LayoutBlock\|DataProvider\|ExtTranslations"` | 30 tests, 115 assertions, 0 failures | PASS |
| PHP syntax (top 4 modified files) | `php -l <file>` × 4 | No syntax errors | PASS |
| Anti-pattern scan (TODO/FIXME/XXX) on Phase 8 source files | `grep TODO\|FIXME\|XXX` | Zero hits in TaxonomyMigrationService / MappingCompiler / LlmClassifier (Phase 8 surfaces) | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| TAX-01 | 08-01 + 08-09 | mapping.taxonomies block accepted by MappingFile/MappingAuditor/MappingCompiler | SATISFIED | MappingFile lines 243/307; MappingAuditor line 115; MappingCompiler line 762 |
| TAX-02 | 08-05 | LlmClassifier::proposeNonPageEntities | SATISFIED | LlmClassifier line 395 |
| TAX-03 | 08-10 | --entities= filter auto-includes via relation-graph reachability | SATISFIED | MigrationFilters lines 17/70/86 |
| TAX-04 | 08-02 | DoctrineEntityParser Gedmo namespace scan | SATISFIED | DoctrineEntityParser line 282; DoctrineColumnInfo line 32 |
| TAX-05 | 08-03 | KnowledgeBase::renderTaxonomiesMarkdown | SATISFIED | KnowledgeBase line 373 |
| TAX-06 | 08-04 | LegacyDbService::extTranslationsFor + EXT_TRANSLATIONS constant | SATISFIED | LegacyDbService line 156; KunstmaanCoreTables line 31 |
| TAX-07 | 08-11 | TaxonomyMigrationService verbatim port (483 LOC) | SATISFIED | src/load/TaxonomyMigrationService.php 483 LOC, 5 reshape markers inline |
| TAX-08 | 08-12 | Plugin DI + MigrateController bolt-on/sub-action | SATISFIED | Plugin lines 169/324-332; MigrateController lines 282/571 |
| TAX-09 | 08-14 | Doctor 11th check checkExtTranslations | SATISFIED | DoctorController line 589 |
| TAX-10 | 08-15 | TaxonomyMigrationTest integration | SATISFIED | tests/integration/load/TaxonomyMigrationTest.php — 4 active passing + 1 plan-permitted markTestIncomplete |
| TAX-11 | 08-16 | MappingCompiler taxonomies/layout/dataProviders unit tests | SATISFIED | 3 test files: MappingCompilerTaxonomiesTest, MappingCompilerLayoutBlocksTest, MappingCompilerDataProvidersTest |
| PROP-01 | 08-05 | proposeNonPageEntities emits kind=taxonomy + kind=column drop rows | SATISFIED | LlmClassifier line 519 (kind=taxonomy emit) |
| PROP-02 | 08-05 (was 08-06) | proposeLayoutBlocks emits kind=nodeClass partial-update for slots | SATISFIED | LlmClassifier line 715, kind=nodeClass at 858 |
| PROP-03 | 08-05 (was 08-07) | proposeDataProviders emits kind=dataProvider for orphan page-parts | SATISFIED | LlmClassifier line 968, kind=dataProvider at 1085/1127 |
| PROP-04 | 08-08 + 08-13 | Settings::proposeLayout + Settings::proposeProviders + CLI flags + AI H2 group | SATISFIED | Settings lines 93-94; AnalyzeController lines 58-59; _settings.twig line 120 |
| PROP-05 | 08-09 | MappingCompiler folds layout proposals + emits dataProviders + counters | SATISFIED | MappingCompiler lines 350/359/367/391-393 |
| PROP-06 | 08-16 | Unit tests for layout/data-providers compile invariants | SATISFIED | MappingCompilerLayoutBlocksTest + MappingCompilerDataProvidersTest |
| DOC-01 | 08-17 | 08-RECONCILIATION.md with v1 rule disposition table | SATISFIED | 08-RECONCILIATION.md exists; 16-row reshape table + 8 net-additive |
| DOC-02 | 08-17 | CHANGELOG known-omissions section + cross-links | SATISFIED | CHANGELOG.md line 116 (9 omissions); README line 25 + PROJECT.md line 50 cross-links |

**All 19 requirement IDs declared in PLAN frontmatter are SATISFIED. No orphaned requirements.**

### Anti-Patterns Found

None blocking. The single `markTestIncomplete` in `TaxonomyMigrationTest::testD09FallbackCopiesSourceLocaleAcrossSitesWhenExtTranslationsEmpty` is plan-permitted (Plan 08-15 explicitly authorizes the deferral) and the underlying D-09 fallback IS implemented at `TaxonomyMigrationService.php` lines 388-413 — only the test seam is deferred (would require a Craft bootstrap or Craft::$app shim).

### Human Verification Required

None. Phase 8 is plumbing/proposer/load-stage work fully exercised by the unit + integration test suite (375 tests, 1048 assertions). There is no UI or visual surface in this phase that requires human eyes for goal achievement. The CP `_settings.twig` AI H2 group is a static template addition covered by template existence + lightswitch shape; the operator-facing CHANGELOG / README / PROJECT.md cross-links are documentation grep-verified.

### Notable Observations (informational)

1. **ROADMAP plan 08-17 checkbox** — line 325 of `.planning/ROADMAP.md` still shows `[ ]` (unchecked) for plan 08-17 even though the SUMMARY exists, the work is complete, and tests pass. This is a cosmetic post-execution checkbox-update miss; does not affect goal achievement. Consider updating in a separate housekeeping commit.

2. **D-09 markTestIncomplete (informational)** — `TaxonomyMigrationTest::testD09FallbackCopies…` is the only incomplete test in the entire suite. It is plan-permitted per Plan 08-15 acceptance criteria and documented in 08-RECONCILIATION.md "Open / deferred items". The fallback path itself is implemented and gated by the Doctor 11th check. Future work to add a Craft::$app shim would close the test seam.

### Gaps Summary

No gaps. All 19 requirement IDs (TAX-01..11, PROP-01..06, DOC-01..02) are
codified in REQUIREMENTS.md and verified against codebase evidence:
service ports, DI wiring, controller bolt-on / sub-action, three new LLM
proposers, three new compile passes, mapping-row scaffolding, doctor
check, AI settings group, CHANGELOG known-omissions section, and
RECONCILIATION.md disposition table. Test suite green (375/1048).

---

_Verified: 2026-04-27_
_Verifier: Claude (gsd-verifier)_
