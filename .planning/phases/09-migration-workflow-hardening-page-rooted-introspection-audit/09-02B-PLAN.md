---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 02B
type: execute
wave: 2
depends_on: [09-02]
files_modified:
  - src/filter/MappingFilterTranslator.php
  - src/verify/CountGateService.php
  - src/console/VerifyController.php
  - src/finalize/FinalizeWalker.php
  - tests/unit/filter/MappingFilterTranslatorTest.php
  - tests/unit/verify/CountGateServiceFiltersTest.php
autonomous: true
requirements: [PH9-06, PH9-07]
must_haves:
  truths:
    - "Craft handle comparison happens only at Craft query surfaces"
    - "Verify and finalize translate source filters through compiled mapping"
    - "Unmapped source filters produce visible warning/skip evidence"
---

<objective>
Translate source-domain entity filters to Craft query scopes only at Craft element/count boundaries.
</objective>

<scope>
In scope:
- Add `MappingFilterTranslator` for compiled mapping -> Craft section/entry-type handle scopes.
- Update verify count gates and finalize Craft queries to use translated scope.
- Keep source-domain filters unchanged outside Craft query surfaces.

Non-goals:
- Do not wire every runtime stage in this plan; `09-02C` owns cross-stage handoff and sidecar closure.
</scope>

<context>
@.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-CONTEXT.md
@.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-PATTERNS.md
@src/filter/MigrationFilters.php
@src/filter/FilterFactory.php
@src/verify/CountGateService.php
@src/console/VerifyController.php
@src/finalize/FinalizeWalker.php
</context>

<tasks>
<task type="auto" tdd="true">
  <name>Task 1: Add compiled mapping filter translator</name>
  <files>src/filter/MappingFilterTranslator.php, tests/unit/filter/MappingFilterTranslatorTest.php</files>
  <behavior>
    - Source entity filters translate to Craft section and entry-type handles through `mapping.nodeClasses`.
    - FQCN and basename source filters resolve deterministically.
    - Unmapped source filters are returned as visible `unmappedSourceEntities`.
  </behavior>
  <action>
    Create `MappingFilterTranslator` that accepts compiled mapping arrays and `MigrationFilters`, reads `mapping.nodeClasses[fqcn].section` and entry-type metadata, and returns deterministic `sectionHandles`, `entryTypeHandles`, and `unmappedSourceEntities`.
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/unit/filter/MappingFilterTranslatorTest.php --testdox</automated>
  </verify>
  <done>Translator tests prove source filters are translated to Craft handles only from compiled mapping.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Apply translation to verify and finalize Craft query surfaces</name>
  <files>src/verify/CountGateService.php, src/console/VerifyController.php, src/finalize/FinalizeWalker.php, tests/unit/verify/CountGateServiceFiltersTest.php</files>
  <behavior>
    - `CountGateService::isSectionFilteredOut()` no longer compares source entities directly with Craft section handles.
    - `VerifyController::actionIndex()` loads compiled mapping once when entity filters require translation.
    - `FinalizeWalker` and Craft Entry/Asset query surfaces receive translated Craft scope rather than raw source entity values.
    - Missing mapping with entity filters returns an actionable verify/finalize failure.
  </behavior>
  <action>
    Per D-17, load compiled mapping at controller/service boundaries, translate source scope with `MappingFilterTranslator`, and pass translated section/type handles into Craft queries. Preserve locale/site scoping and optional plugin skip behavior.
  </action>
  <verify>
    <automated>vendor/bin/phpunit tests/unit/verify/CountGateServiceFiltersTest.php --testdox</automated>
  </verify>
  <done>Verify/finalize filters compare like with like and surface unmapped source filters visibly.</done>
</task>
</tasks>

<verification>
- `vendor/bin/phpunit tests/unit/filter/MappingFilterTranslatorTest.php tests/unit/verify/CountGateServiceFiltersTest.php --testdox`
- `composer test-unit`
</verification>

<success_criteria>
D-17 is implemented for Craft query surfaces, building on the source filter core from `09-02`.
</success_criteria>

<output>
After completion, create `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-02B-SUMMARY.md`.
</output>
