# Plan 11-04 Summary: Analyze/prompt integration

## Outcome

Analyze now writes canonical graph-shaped schema artifacts for both migration sides and passes the normalized graph pair into the LLM residual mapping prompt.

## Implemented

- Registered `kunstmaanPageWalker` and `craftEntryWalker` plugin components.
- Wired walker dependencies in `Plugin::init()` using the existing sibling component style.
- Updated `AnalyzeController::actionIndex()` to:
  - keep the legacy Kunstmaan schema sample dump in memory for existing heuristic/pagepart steps;
  - write `storage/migration/kunstmaan-schema.json` from `KunstmaanPageWalker`;
  - write `storage/migration/craft-schema.json` from `CraftEntryWalker`;
  - validate both graph artifacts expose the expected `graphVersion`;
  - scope Craft graph roots from existing mapping entry handles when present, falling back to Craft entry type handles;
  - pass `$kunstmaanGraph` and `$craftGraph` to `LlmClassifier::batchPropose()`.
- Updated `LlmClassifier` residual prompt flow to render fenced `<kunstmaanGraph>` and `<craftGraph>` sections before the legacy markdown context.
- Instructed the LLM to use stable graph refs and relation intents exactly: `reference`, `promote`, `embed`, `drop`, `out_of_scope`.
- Preserved valid `sourceRef`, `targetRef`, and `relationIntent` fields returned by graph-aware LLM proposals.

## Verification

- `php -l src/analyze/LlmClassifier.php && php -l src/console/AnalyzeController.php && php -l src/Plugin.php`
- `vendor/bin/phpunit tests/unit/analyze/LlmClassifierGraphPromptTest.php tests/unit/console/AnalyzeControllerGraphArtifactsTest.php tests/unit/console/AnalyzeControllerDualSchemaDumpTest.php --testdox`
- `vendor/bin/phpunit tests/unit/source tests/unit/analyze tests/unit/console tests/unit/compile --testdox`
- Plan 11-04 acceptance greps for walker registration, canonical schema writes, graph prompt variables, relation intent vocabulary, Anthropic-only analyze path, and no runtime `LlmClassifier` references.

## Notes

- `kunstmaan-schema.json` is now the canonical Kunstmaan graph artifact; the older source schema dump is intentionally retained only as an internal compatibility input for legacy heuristic/pagepart stages.
- Content-only mapping policy remains deferred/configurable; analyze only exposes factual source columns in graph/source context.
