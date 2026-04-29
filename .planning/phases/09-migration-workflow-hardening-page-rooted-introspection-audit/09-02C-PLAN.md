---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 02C
type: execute
wave: 4
depends_on: [09-01, 09-02, 09-02B, 09-04, 09-05]
files_modified:
  - src/console/AnalyzeController.php
  - src/console/MapController.php
  - src/console/MigrateController.php
  - src/console/VerifyController.php
  - src/extract/ExtractService.php
  - src/transform/TransformService.php
  - src/finalize/FinalizeWalker.php
  - src/load/AssetMigrationService.php
  - src/load/TaxonomyMigrationService.php
  - src/load/SeoMigrationService.php
  - src/load/RedirectMigrationService.php
  - src/verify/BaselineCounterService.php
  - tests/integration/filter/CrossStageFilterConsistencyTest.php
autonomous: true
requirements: [PH9-05, PH9-06, PH9-07, PH9-13]
must_haves:
  truths:
    - "Analyze, map, extract, transform, load, finalize, taxonomy, SEO, Retour, verify, baseline, asset preload, and recovery/helper surfaces receive one consistent source-domain scope"
    - "Scoped Page runs include or visibly classify page-owned sidecars beyond Doctrine relationGraph"
    - "This plan runs after `09-01` and `09-05` to avoid concurrent `MigrateController.php` edits"
---

<objective>
Wire the Phase 9 filter core and translator through the full runtime pipeline, and prove Page-scoped runs close over page-owned sidecars.
</objective>

<scope>
In scope:
- Audit and wire every runtime consumer that accepts or derives `MigrationFilters`.
- Ensure `--entities=NewsPage` includes or visibly classifies pageparts, implicit content, page-level relations, asset refs, CKEditor token targets, taxonomy/dataProvider refs, SEO rows, and redirect rows.
- Add integration coverage that prevents stage-specific scope drift.

Non-goals:
- Do not rework filter core or Craft translation primitives; those belong to `09-02` and `09-02B`.
- Do not add full orphan-media import or non-content subsystem migration.
</scope>

<context>
@.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-CONTEXT.md
@.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-PATTERNS.md
@src/console/AnalyzeController.php
@src/console/MapController.php
@src/console/MigrateController.php
@src/console/VerifyController.php
@src/extract/ExtractService.php
@src/transform/TransformService.php
@src/finalize/FinalizeWalker.php
@src/load/AssetMigrationService.php
@src/load/TaxonomyMigrationService.php
@src/load/SeoMigrationService.php
@src/load/RedirectMigrationService.php
@src/verify/BaselineCounterService.php
</context>

<tasks>
<task type="auto" tdd="true">
  <name>Task 1: Inventory and normalize filter handoff across runtime stages</name>
  <files>src/console/AnalyzeController.php, src/console/MapController.php, src/console/MigrateController.php, src/console/VerifyController.php, src/extract/ExtractService.php, src/transform/TransformService.php, src/finalize/FinalizeWalker.php, src/load/TaxonomyMigrationService.php, src/load/SeoMigrationService.php, src/load/RedirectMigrationService.php, src/load/AssetMigrationService.php, src/verify/BaselineCounterService.php, tests/integration/filter/CrossStageFilterConsistencyTest.php</files>
  <behavior>
    - Controllers construct filters through the same `FilterFactory` path and load the same relationGraph source when scoped by entities.
    - Extract, transform, load, asset preload, finalize, taxonomy, SEO, Retour, baseline, verify, and recovery/helper code consume the same source-domain scope rather than reparsing flags or comparing Craft handles directly.
    - Translated Craft handles are used only at Craft query surfaces introduced by `09-02B`.
  </behavior>
  <action>
    Per D-15 and D-16, inventory all `fromCli()`, `MigrationFilters`, `allows()`, and Craft query usages. Update each boundary so the normalized source filter and relationGraph closure flows through analyze -> map -> extract -> transform -> load -> finalize -> taxonomy/SEO/Retour -> verify/baseline/recovery.
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/integration/filter/CrossStageFilterConsistencyTest.php --filter 'stage' --testdox</automated>
  </verify>
  <done>Every runtime stage has an explicit filter handoff and no stage-specific source/Craft domain drift.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Prove Page-owned sidecar closure for scoped runs</name>
  <files>tests/integration/filter/CrossStageFilterConsistencyTest.php, src/extract/ExtractService.php, src/load/AssetMigrationService.php, src/load/TaxonomyMigrationService.php, src/load/SeoMigrationService.php, src/load/RedirectMigrationService.php, src/finalize/FinalizeWalker.php</files>
  <behavior>
    - Synthetic `NewsPage` scoped runs keep pageparts, implicit content, page-level relations, asset references, CKEditor `[M]`/`[NT]` targets, taxonomy/dataProvider references, SEO rows, and redirect rows in scope when page-owned.
    - Any page-owned surface that cannot be included deterministically is emitted to the Phase 9 coverage/audit path as `unsupported`, `warning`, or `out_of_scope`.
  </behavior>
  <action>
    Build integration fixtures around the source-surface discovery matrix from `09-04`. Assert that `--entities=NewsPage` closes over non-Doctrine sidecars and that services either include them or hand structured classification rows to the Page-rooted report. This is the acceptance test for D-16 beyond relationGraph DFS.
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/integration/filter/CrossStageFilterConsistencyTest.php --filter 'sidecar' --testdox</automated>
  </verify>
  <done>Scoped Page runs cannot silently omit page-owned sidecars.</done>
</task>
</tasks>

<verification>
- `vendor/bin/phpunit tests/integration/filter/CrossStageFilterConsistencyTest.php --testdox`
- `composer test-unit`
</verification>

<success_criteria>
D-16 is implemented across the runtime pipeline; ROADMAP criterion 3 is covered end-to-end; Page-scoped runs include or visibly classify all page-owned dependencies.
</success_criteria>

<output>
After completion, create `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-02C-SUMMARY.md`.
</output>
