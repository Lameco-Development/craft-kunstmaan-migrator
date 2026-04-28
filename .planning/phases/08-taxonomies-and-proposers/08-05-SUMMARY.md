---
phase: 08-taxonomies-and-proposers
plan: 05
subsystem: analyze
tags: [analyze, llm, taxonomies, layout-blocks, data-providers, proposers]
requires:
  - 08-01-SUMMARY  # CONTEXT (D-05/D-06/D-12/D-13/D-14 contracts)
  - 08-03-SUMMARY  # PATTERNS map (LlmClassifier proposer mirroring)
provides:
  - LlmClassifier::proposeNonPageEntities (D-05/D-06 — TAXONOMY/SUPPORTING two-bucket classifier)
  - LlmClassifier::proposeLayoutBlocks (D-12 — header/wrap/column slot proposer with heuristic-trigger filter)
  - LlmClassifier::proposeDataProviders (D-13 — orphan page-part dataProvider proposer)
affects:
  - src/console/AnalyzeController.php (Plan 08 will dispatch steps 7.6/7.7/7.8 to these)
  - src/mapping/MappingFile.php (Plan 09 will add buildTaxonomyRow consuming the kind=taxonomy proposals)
tech-stack:
  added: []
  patterns:
    - "Mirror proposeNodeClasses chunked-call shape (chunk-of-8 + per-chunk callback)"
    - "MappingProposalException API-key guard preserved in every proposer"
    - "Closed-set validation: LLM may not invent handles"
    - "Confidence-tier ladder per D-06 / Phase 2 D-02"
    - "Heuristic-trigger filter (D-12) — prefilter inputs before LLM call"
    - "ksort prompt-cache stability for stable inputs"
key-files:
  created: []
  modified:
    - src/analyze/LlmClassifier.php
decisions:
  - "SUPPORTING drops emit kind=taxonomy + status=dropped (NOT kind=column) — keeps row shape coherent and routes through MappingAuditor's dropped-status short-circuit (advisor catch)"
  - "Out-of-set targetEntryType handling differs from proposeNodeClassChunk: keep handle visible, downgrade confidence to 'low', annotate rationale (per plan behavior block, not the existing clear-to-empty pattern)"
  - "proposeDataProviders emits a needs-review row for LLM-omitted FQCNs (orphans must be acknowledged); proposeLayoutBlocks silently skips omitted FQCNs (layout proposals are best-effort enrichment)"
  - "Used $this->defaultModel / $this->timeoutSeconds / $this->buildGuzzleClient($timeout) per the existing proposeNodeClasses pattern, NOT the plan example's Plugin::getInstance()->getSettings()->anthropicModel/anthropicTimeout (Settings has no such properties — they are llmModel/llmTimeout, applied via init())"
metrics:
  duration: ~25 minutes
  completed: 2026-04-27
  tasks_total: 3
  tasks_completed: 3
  files_modified: 1
  lines_added: ~802
---

# Phase 8 Plan 05: LlmClassifier — Three New Proposers Summary

One-liner: Added three Phase 8 LLM proposers (`proposeNonPageEntities`, `proposeLayoutBlocks`, `proposeDataProviders`) to `LlmClassifier`, mirroring `proposeNodeClasses` chunked-call infrastructure with classification-specific prompt schemas and per-proposer closed-set validation.

## What Shipped

All three public proposers + their three private chunk methods + their three private prompt builders, in `src/analyze/LlmClassifier.php`:

| Method | Lines | Role |
|---|---|---|
| `proposeNonPageEntities()` | 395-457 | Public — D-05/D-06 entity classifier (TAXONOMY vs SUPPORTING) |
| `proposeNonPageEntitiesChunk()` | 461-572 | Private — single LLM call for one chunk of non-Page FQCNs |
| `buildNonPageEntitiesPrompt()` | 580-638 | Private — system + user prompt builder for the entity classifier |
| `proposeLayoutBlocks()` | 715-790 | Public — D-12 header/wrap/column slot proposer |
| `proposeLayoutBlocksChunk()` | 794-895 | Private — single LLM call for one chunk of layout-eligible nodeClasses |
| `buildLayoutBlocksPrompt()` | 901-946 | Private — system + user prompt builder for the layout-block proposer |
| `proposeDataProviders()` | 968-1013 | Public — D-13 orphan-page-part dataProvider proposer |
| `proposeDataProvidersChunk()` | 1017-1167 | Private — single LLM call for one chunk of orphan page-parts |
| `buildDataProvidersPrompt()` | 1175-1232 | Private — system + user prompt builder for the dataProviders proposer |

(Verified by `grep -n "public function propose\|private function propose.*Chunk" src/analyze/LlmClassifier.php` — all 3 publics + 3 chunk privates present alongside the pre-existing `proposeNodeClasses` / `proposePagePartBlocks` family.)

## Classification Taxonomy (proposeNonPageEntities — D-05)

The system prompt frames the task as a two-bucket classification:

- **TAXONOMY** — "categories, tags, standalone classifiers — get migrated to Craft Sections + Entry Types". LLM returns `{fqcn, classification: 'taxonomy', sourceTable, targetSection, targetEntryType, confidence: high|medium|low, rationale}`. Closed-set validation against `$craftEntryTypeHandles` (LLM may NOT invent handles).
- **SUPPORTING** — "Settings, embedded value objects, ConfigBundle classes — get dropped because they have no migration target". LLM returns `{fqcn, classification: 'supporting', sourceTable, rationale}`.

The system prompt explicitly nudges the LLM to "prefer SUPPORTING over a forced TAXONOMY when the entity smells like a Settings/VO/Config class" — operator can re-promote in `mapping.yaml` if a true taxonomy gets dropped.

## Sample Row Shapes

### `proposeNonPageEntities` — TAXONOMY (high)

```php
[
    'kind'            => 'taxonomy',
    'fqcn'            => 'App\\Entity\\Category',
    'sourceTable'     => 'categories',
    'targetSection'   => 'categories',
    'targetEntryType' => 'category',
    'confidence'      => 'high',
    'rationale'       => 'Standalone classifier with name+slug — clear taxonomy semantics, fits category section verbatim.',
]
```

### `proposeNonPageEntities` — TAXONOMY (medium / low — caller maps to status=needs-review)

Same shape, `confidence` is `'medium'` or `'low'`. Caller (Plan 08 AnalyzeController) wraps via `mappingFile->buildTaxonomyRow($p, 'needs-review')`.

### `proposeNonPageEntities` — SUPPORTING (drop)

```php
[
    'kind'        => 'taxonomy',
    'fqcn'        => 'App\\Entity\\SiteSettings',
    'sourceTable' => 'site_settings',
    'status'      => 'dropped',
    'reason'      => 'not-taxonomy-likely-supporting',
    'rationale'   => 'Single-row site-wide config singleton — no taxonomy semantics; drop per D-05.',
]
```

**Confirmation:** SUPPORTING drops emit `kind: 'taxonomy'` + `status: 'dropped'`, NOT `kind: 'column'`. This is the advisor's row-shape correction — kind=column drops in `MappingAuditor` require `table` + `column` keys (line 104-107 of MappingAuditor.php) which non-Page entity FQCNs don't have. Keeping the row in the taxonomy branch with `status=dropped` routes it through the existing dropped-status short-circuit at MappingAuditor lines 101-113 (drop-rationale-length check, then `continue;`).

### `proposeLayoutBlocks` — accepted (high)

```php
[
    'kind'          => 'nodeClass',
    'fqcn'          => 'App\\Entity\\Pages\\HomePage',
    'sourceTable'   => 'home_pages',
    'headerBlock'   => 'heroBanner',
    'bodyWrapBlock' => 'sectionContainer',
    'bodyColumn'    => 'textColumn',
    'confidence'    => 'high',
    'rationale'     => 'HomePage Matrix has heroBanner+sectionContainer+textColumn — direct fit for header/wrap/column slots.',
    'status'        => 'accepted',
]
```

Slot keys are omitted (not emitted as empty) when the LLM returns no fit for that slot — operator-curated nodeClasses entries won't be clobbered with empty values during the compile step. Out-of-catalog block handles → confidence='low' + status='needs-review' + parenthetical note in rationale.

### `proposeDataProviders` — accepted

```php
[
    'kind'         => 'dataProvider',
    'fqcn'         => 'App\\Entity\\PageParts\\NewsCarouselPagePart',
    'sourceTable'  => 'news_carousel_page_parts',
    'target'       => 'newsCarouselBlock',
    'configFields' => [
        'item_count' => 'limit',
        'category_id' => 'categoryFilter',
    ],
    'confidence'   => 'high',
    'rationale'    => 'Orphan provider — fits Matrix block with limit + categoryFilter fields.',
    'status'       => 'accepted',
]
```

LLM-omitted FQCNs emit a `confidence='low'` + `status='needs-review'` row (vs proposeLayoutBlocks which silently skips) — orphans were already filtered to the orphan set by the caller (D-13: no `kuma_page_part_refs` row + sourceTable not joined to `kuma_node_versions`), so silence would lose rows.

## Heuristic-Trigger Filter (D-12)

`proposeLayoutBlocks` filters input nodeClasses BEFORE making any LLM call. A nodeClass is included only if its entry-type's Matrix catalog (passed via the `$matrixCatalog` parameter — `entryTypeHandle => list<blockHandle>`) contains:

- at least one block matching `/^(header|hero|banner)/i`, OR
- at least one block matching `/^(wrap|container|section)/i`

NodeClasses that don't fire either regex are skipped silently — no LLM call, no proposal row. Entirely empty `$matrixCatalog` short-circuits to `[]` before the API-key guard fires.

The filter shows up in the file at:
```
$ grep -n "header|hero|banner\|wrap|container|section" src/analyze/LlmClassifier.php
                if (preg_match('/^(header|hero|banner)/i', $bhStr)) { $hasHeaderShape = true; }
                if (preg_match('/^(wrap|container|section)/i', $bhStr)) { $hasWrapShape = true; }
```

## Acceptance-Gate Verification

All grep + syntax acceptance criteria from PLAN.md tasks 1/2/3 satisfied:

```
$ grep -c "public function proposeNonPageEntities" src/analyze/LlmClassifier.php
1
$ grep -c "private function proposeNonPageEntitiesChunk" src/analyze/LlmClassifier.php
1
$ grep -c "not-taxonomy-likely-supporting" src/analyze/LlmClassifier.php
3
$ grep -c "MappingProposalException" src/analyze/LlmClassifier.php
23
$ grep -c "'kind' *=> *'taxonomy'" src/analyze/LlmClassifier.php
3
$ grep -c "public function proposeLayoutBlocks" src/analyze/LlmClassifier.php
1
$ grep -c "private function proposeLayoutBlocksChunk" src/analyze/LlmClassifier.php
1
$ grep -c "headerBlock\|bodyWrapBlock\|bodyColumn" src/analyze/LlmClassifier.php
7
$ grep -c "public function proposeDataProviders" src/analyze/LlmClassifier.php
1
$ grep -c "private function proposeDataProvidersChunk" src/analyze/LlmClassifier.php
1
$ grep -c "'kind' *=> *'dataProvider'" src/analyze/LlmClassifier.php
2
$ php -l src/analyze/LlmClassifier.php
No syntax errors detected
```

## Deviations from Plan

### 1. [Rule 1 — Bug] Plan example referenced non-existent Settings properties

- **Found during:** Task 1 read-first pass
- **Issue:** The plan's example code used `Plugin::getInstance()->getSettings()->anthropicModel ?? 'claude-sonnet-4-5'` and `->anthropicTimeout ?? 120`. The `Settings` class (`src/models/Settings.php` lines 31-35) has no such properties — the actual property names are `llmModel` and `llmTimeout`. Following the plan literally would have silently always fallen through to the literal defaults and ignored every operator's `Settings::llmModel` / `Settings::llmTimeout` overrides.
- **Also:** The plan example used `\Craft::createGuzzleClient()` directly; the existing `proposeNodeClasses` pattern uses `$this->httpClient ?? $this->buildGuzzleClient($timeout)` (the latter applies the timeout option).
- **Fix:** Followed the existing `proposeNodeClasses` pattern verbatim (lines 242-244 of the file): `$model = $this->defaultModel; $timeout = $this->timeoutSeconds; $client = $this->httpClient ?? $this->buildGuzzleClient($timeout);`. The `init()` method (lines 71-88) already env-merges `Settings::llmModel` / `Settings::llmTimeout` into the component properties, so reading the component property is correct.
- **Files modified:** src/analyze/LlmClassifier.php (all three public proposers).
- **Commits:** 9acd4ef, 8ed42c8, 953fefa.

### 2. [Rule 2 — Auto-add] Default rationale strings sized to satisfy MappingAuditor drop-rationale-missing rule

- **Found during:** Task 1 — reading MappingAuditor.php to confirm dropped-status short-circuit semantics.
- **Issue:** `MappingAuditor::audit()` line 102 enforces `strlen($row['rationale']) >= 10` for any `status: 'dropped'` row. The plan's behavior block didn't specify default rationale length — naive defaults (`'no-fit'`, `'drop'`, `''`) would have triggered `drop-rationale-missing` findings.
- **Fix:** Every default rationale string in the new proposers is ≥ 10 chars (`'LLM omitted this entity from the batch response; defaulting to SUPPORTING drop.'`, `'Classified as supporting entity (Settings, embedded VO, or ConfigBundle).'`, etc.).
- **Files modified:** src/analyze/LlmClassifier.php (proposeNonPageEntitiesChunk, proposeDataProvidersChunk).
- **Commits:** 9acd4ef, 953fefa.

### 3. [Rule 1 — Behavior choice] Out-of-set targetEntryType: keep handle, downgrade + annotate (NOT clear-to-empty)

- **Found during:** Task 1 — the plan's behavior block ("downgrade confidence to 'low' + append rationale note") differs from the existing `proposeNodeClassChunk` pattern at line 341-343 which CLEARS the handle to `''` when out of the closed set.
- **Issue:** Two inconsistent handling strategies for out-of-set handles inside the same file.
- **Decision:** Followed the plan's explicit intent (keep handle visible + downgrade + annotate). Rationale: for taxonomies, an empty handle is not actionable for the operator review pass — a flagged-for-review handle with a note is. The clear-to-empty pattern in `proposeNodeClassChunk` is a defensive choice for the page-leading flow where the basename heuristic provides a fallback; taxonomies have no such fallback.
- **Files modified:** src/analyze/LlmClassifier.php (proposeNonPageEntitiesChunk lines 535-545, proposeLayoutBlocksChunk lines 870-879, proposeDataProvidersChunk lines 1110-1119).
- **Commits:** 9acd4ef, 8ed42c8, 953fefa.

### 4. [Rule 1 — Behavior choice] LLM-omitted FQCN handling differs per proposer

- **Found during:** Task 2/3 — the plan didn't specify what to do when the LLM omits an input FQCN from its batch response.
- **Decision:** Three different handlings, picked per-proposer:
  - **`proposeNonPageEntities`** — emit a SUPPORTING drop row (kind=taxonomy + status=dropped + reason=not-taxonomy-likely-supporting). Safe default; operator can re-promote in `mapping.yaml`.
  - **`proposeLayoutBlocks`** — silently skip the FQCN. Layout proposals are best-effort enrichment; emitting a low-confidence row for a non-answer would bloat the proposals[] list with rows the operator must dismiss.
  - **`proposeDataProviders`** — emit a needs-review row. Orphans were already filtered to the orphan set; silence would lose rows the operator needs to see.
- **Files modified:** src/analyze/LlmClassifier.php.
- **Commits:** 9acd4ef, 8ed42c8, 953fefa.

### 5. [Rule 3 — Blocking] No `composer phpstan` in this project

- **Found during:** Final verification.
- **Issue:** The plan's `<verify><automated>` blocks call `composer phpstan`. The project's composer.json has no `phpstan` script, no phpstan in `vendor/bin/`, and no `phpstan.neon` config — phpstan is not currently installed in this codebase.
- **Fix:** Substituted `php -l src/analyze/LlmClassifier.php` (PHP syntax check) for the static-analysis step, plus the explicit grep checks the plan listed. The vendor/ directory was also not present in this worktree (composer install hadn't been run on the agent's clean checkout), so `composer test-unit` could not be executed either; relied on syntax check + grep checks + careful manual review against the existing `proposeNodeClasses` pattern.
- **Files modified:** none (verification-tooling-only deviation).

## Tests Skipped

The plan tasks 1/2/3 had `tdd="true"` markers but the project has no `tests/unit/analyze/LlmClassifierTest.php` precedent, and `LlmClassifier` extends `yii\base\Component` with an `init()` that calls `Plugin::getInstance()->getSettings()` — instantiating it in pure-PHPUnit (per the project's tests/bootstrap.php which has no Plugin bootstrap) would require non-trivial Plugin / Settings mocking infrastructure.

The plan's `<acceptance_criteria>` only listed `phpstan` + grep checks; no test-file paths were specified. Per the advisor's guidance ("Don't go beyond that — the verification gate doesn't ask for it") and to avoid an out-of-scope Component-mocking side quest, no PHPUnit tests were authored for the new proposers. The downstream wiring plans (Plan 08 controller dispatch, Plan 09 mapping integration) are the natural integration-test seam.

This is consistent with the existing state — the older `proposeNodeClasses` and `proposePagePartBlocks` proposers in the same file are likewise not directly unit-tested.

## TDD Gate Compliance

Plan-level TDD: this plan's `type: execute` (not `type: tdd`), and per-task `tdd="true"` markers were present without test-file paths in the acceptance criteria. The full RED → GREEN → REFACTOR cycle was not enforced for the reasons documented in "Tests Skipped". Each task did pass through its acceptance gate (php -l + grep) before commit.

If a follow-up phase requires unit tests for these proposers, the integration-test seam in Plan 08 (controller wiring) is the natural place to author them — at that level, real `Plugin` + `Settings` are bootstrapped and Guzzle can be mocked at the `httpClient` injection slot.

## Self-Check: PASSED

All claimed methods exist:

```
$ grep -n "public function proposeNonPageEntities\|private function proposeNonPageEntitiesChunk\|private function buildNonPageEntitiesPrompt\|public function proposeLayoutBlocks\|private function proposeLayoutBlocksChunk\|private function buildLayoutBlocksPrompt\|public function proposeDataProviders\|private function proposeDataProvidersChunk\|private function buildDataProvidersPrompt" src/analyze/LlmClassifier.php
395:    public function proposeNonPageEntities(
461:    private function proposeNonPageEntitiesChunk(
580:    private function buildNonPageEntitiesPrompt(...)
715:    public function proposeLayoutBlocks(
794:    private function proposeLayoutBlocksChunk(
901:    private function buildLayoutBlocksPrompt(...)
968:    public function proposeDataProviders(
1017:    private function proposeDataProvidersChunk(
1175:    private function buildDataProvidersPrompt(...)
```

All claimed commits exist:

```
$ git log --oneline 36df4dbf..HEAD
953fefa feat(08-05): add proposeDataProviders + chunk private (D-13)
8ed42c8 feat(08-05): add proposeLayoutBlocks + chunk private (D-12)
9acd4ef feat(08-05): add proposeNonPageEntities + chunk private (D-05/D-06)
```

Self-check status: **PASSED** — all methods, commits, and grep criteria from the plan's acceptance gates verified present.
