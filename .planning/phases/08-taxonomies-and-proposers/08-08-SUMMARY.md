---
phase: 08-taxonomies-and-proposers
plan: 08
subsystem: analyze
tags: [analyze, controller, settings, cli-flags, proposers, taxonomies, layout-blocks, data-providers]
requires:
  - 08-05-SUMMARY  # LlmClassifier::proposeNonPageEntities / proposeLayoutBlocks / proposeDataProviders
provides:
  - Settings::proposeLayout (bool, default true — D-14 AI layout proposer gate)
  - Settings::proposeProviders (bool, default true — D-14 AI dataProvider proposer gate)
  - AnalyzeController::$noLayout (bool — --no-layout CLI per-run override)
  - AnalyzeController::$noProviders (bool — --no-providers CLI per-run override)
  - AnalyzeController step 7.7 (proposeNonPageEntities dispatch — D-05/D-06)
  - AnalyzeController step 7.8 (proposeLayoutBlocks dispatch — D-11/D-12)
  - AnalyzeController step 7.9 (proposeDataProviders dispatch — D-13)
  - D-13 orphan-page-part filter computed in-controller
affects:
  - .planning/REQUIREMENTS.md (closes PROP-04 — Settings + CLI ladder for AI proposer scope)
tech-stack:
  added: []
  patterns:
    - "Mirrors Phase 4.1 / ADP-04 (seoEnabled/retourEnabled + --no-seo/--no-retour) verbatim"
    - "Skip-gate ladder per step: CLI flag → Settings flag → --no-ai → API key → input-emptiness"
    - "Distinct WARN line per skip path with reason"
    - "DoctrineEntityInfo → array adapter inside controller"
    - "Page-aware pageStructure threading (nodeClass targetEntryType → pageStructure[fqcn]['targetEntryType'])"
key-files:
  created:
    - .planning/phases/08-taxonomies-and-proposers/08-08-SUMMARY.md
  modified:
    - src/models/Settings.php
    - src/console/AnalyzeController.php
decisions:
  - "Renamed inserted steps to 7.7/7.8/7.9 (not 7.6/7.7/7.8 as plan suggested) because step 7.6 label was already taken by the existing Phase 6 page-part LLM step. The plan's must_haves reference the methods (proposeNonPageEntities/proposeLayoutBlocks/proposeDataProviders) — comment label numbering is not load-bearing"
  - "Adapter for DoctrineEntityInfo objects → array shape lives inline in step 7.7 dispatch (not a new helper). proposeNonPageEntities expects array<string, array<string, mixed>> with 'tableName'/'columns'/'relations'/'contexts' keys; sourceScan['entities'] returns DoctrineEntityInfo objects that the proposer's is_array($info) filter would silently drop"
  - "D-13 orphan candidates sourced from pageStructure[*]['contexts'][*]['allowedPagePartClasses'] (not $sourceScan['pageParts'] which does not exist). FK heuristic uses DoctrineRelationInfo->targetEntity (str_contains 'NodeVersion') + DoctrineRelationInfo->fkColumn + DoctrineColumnInfo->columnName fallbacks (the actual property names — not the plan example's 'name'/'column'/'joinColumn' which are invented)"
  - "Layout proposer pageStructure threading: proposeLayoutBlocks's heuristic-trigger filter (LlmClassifier lines 737-757) reads $info['targetEntryType'] from pageStructure entries to look up the matrixField catalog. Step 7.8 adds entryType-by-FQCN from accepted nodeClass proposals so the filter actually fires; without this the filter sees an empty $handle and short-circuits every nodeClass to no-op"
  - "Defensive scoping: $kbLegacyMd is initialized to '' at step 7.5 setup so steps 7.7/7.8/7.9 can reference it even when entity-LLM is skipped (pre-existing latent bug for the existing step 7.6 page-part LLM if pageStructure was empty + pagePartProposals nonempty — practically impossible since pagePartProposals come from pageStructure, but new step 7.7 actually exercises the scope)"
  - "Phase 8 dataProvider proposer reads kuma_page_part_refs.page_part_entityname directly via legacyDbService->queryAll. KunstmaanCoreTables::PAGE_PART_REFS supplies the table-name constant. A read failure (Throwable catch) leaves the referenced set empty; downstream FK heuristic still filters orphans"
metrics:
  duration: ~25 minutes
  completed: 2026-04-27
  tasks_total: 2
  tasks_completed: 2
  files_modified: 2
  lines_added: ~410
---

# Phase 8 Plan 08: AnalyzeController Wiring + Settings + CLI Flags Summary

One-liner: Wired the three Phase 8 LLM proposers (`proposeNonPageEntities`, `proposeLayoutBlocks`, `proposeDataProviders`) into `AnalyzeController` as steps 7.7/7.8/7.9 with the full Settings + CLI override ladder (D-14 mirrors Phase 4.1 / ADP-04 verbatim), plus the D-13 orphan-page-part filter computed inline.

## What Shipped

| File | Change | Line refs |
|---|---|---|
| `src/models/Settings.php` | `public bool $proposeLayout = true;` | line 93 |
| `src/models/Settings.php` | `public bool $proposeProviders = true;` | line 94 |
| `src/models/Settings.php` | rules() entry extended for both new booleans | line 235 |
| `src/console/AnalyzeController.php` | `public bool $noLayout = false;` | line 58 |
| `src/console/AnalyzeController.php` | `public bool $noProviders = false;` | line 59 |
| `src/console/AnalyzeController.php` | `options()` extended with `'noLayout', 'noProviders'` | line 67 |
| `src/console/AnalyzeController.php` | `$kbLegacyMd = ''` defensive init | line 318 |
| `src/console/AnalyzeController.php` | Step 7.7 — proposeNonPageEntities dispatch | lines 503-602 |
| `src/console/AnalyzeController.php` | Step 7.8 — proposeLayoutBlocks dispatch | lines 604-691 |
| `src/console/AnalyzeController.php` | Step 7.9 — proposeDataProviders dispatch | lines 693-855 |
| `src/console/AnalyzeController.php` | Step 9 row-builder folds 3 new proposal lists | block extending $rows assembly |

## D-14 Skip-Gate Ladder (Verbatim Implementation)

Each new step exposes the full ladder per D-14:

### Step 7.7 — non-page-entity (taxonomy) proposer

```php
$skipNonPage = $this->noAi
    || $apiKeyForEntityStep === ''
    || ($sourceScan['entities'] ?? []) === [];
```

Note: `proposeNonPageEntities` is NOT under the `Settings::proposeLayout` / `Settings::proposeProviders` gates — those D-14 booleans cover layout-block + dataProvider scopes only. Taxonomy proposer is on whenever `--no-ai` is off and there is an API key (consistent with the existing entity-LLM step 7.5).

### Step 7.8 — layout-block proposer (D-14 four-layer ladder)

```php
$skipLayout = $this->noLayout
    || !$plugin->getSettings()->proposeLayout
    || $this->noAi
    || $apiKeyForEntityStep === ''
    || $pageStructure === [];
```

### Step 7.9 — dataProvider proposer (D-14 four-layer ladder)

```php
$skipProviders = $this->noProviders
    || !$plugin->getSettings()->proposeProviders
    || $this->noAi
    || $apiKeyForEntityStep === '';
```

Each path emits a distinct WARN line whose reason names the layer that triggered the skip — operator immediately sees `--no-layout set` vs. `Settings::proposeLayout disabled` vs. `--no-ai set` vs. `ANTHROPIC_API_KEY not set` vs. `no page entities discovered`.

## D-13 Orphan-Trigger Filter (Confirmed)

Computed inline inside step 7.9 before the proposer call:

1. **Candidate set:** every page-part FQCN appearing in `pageStructure[*]['contexts'][*]['allowedPagePartClasses']`. Deduped on FQCN; carries `(fqcn, sourceTable)`.
2. **Filter half 1 — `kuma_page_part_refs` referenced FQCNs are NOT orphans.** Fetched via `legacyDbService->queryAll('SELECT DISTINCT page_part_entityname FROM kuma_page_part_refs WHERE page_part_entityname IS NOT NULL')`. `KunstmaanCoreTables::PAGE_PART_REFS` supplies the canonical table name. A `Throwable` catch leaves the referenced set empty (defensive — half 2 still filters).
3. **Filter half 2 — entities whose source carries a NodeVersion FK are NOT orphans.** For each candidate FQCN, look up `DoctrineEntityInfo` from `$sourceScan['entities']` and probe:
   - `DoctrineRelationInfo->targetEntity` for `str_contains 'NodeVersion'`, OR
   - `DoctrineRelationInfo->fkColumn` ∈ {`node_version_id`, `kuma_node_version_id`}, OR
   - `DoctrineColumnInfo->columnName` ∈ {`node_version_id`, `kuma_node_version_id`} (fallback for plain-column FKs).

What survives both filters is the orphan list passed to `proposeDataProviders`. Matches D-13 verbatim: "page-part FQCN that does NOT match a standard Kunstmaan page-part pattern: no `kuma_page_part_refs` row references it AND its source table is not joined to `kuma_node_versions`".

## Step 9 Row-Builder Integration

The three new proposal lists fold into `$rows` alongside nodeClass / pagePart / column rows:

- **`$taxonomyProposals`:** `status=dropped` rows (SUPPORTING — kind=taxonomy + reason=not-taxonomy-likely-supporting) pass through verbatim per the 08-05 advisor row-shape correction. TAXONOMY rows go through `MappingFile::buildTaxonomyRow($p, $confidence === 'high' ? 'accepted' : 'needs-review')`.
- **`$layoutBlockProposals`:** kind=nodeClass partial-update rows pass through verbatim — Plan 09's compile step folds them into `nodeClasses[fqcn]`. The proposer already set `status` per its own confidence-tier ladder.
- **`$dataProviderProposals`:** LLM-omitted FQCNs (which the proposer already tagged `confidence=low + status=needs-review`) route through `buildDataProviderRow($p, 'needs-review')`. LLM-answered FQCNs map confidence → status: `high → accepted`, others → `needs-review`.

## Acceptance-Gate Verification

```
$ php -l src/models/Settings.php
No syntax errors detected in src/models/Settings.php
$ php -l src/console/AnalyzeController.php
No syntax errors detected in src/console/AnalyzeController.php

$ grep -c 'public bool \$proposeLayout = true' src/models/Settings.php
1
$ grep -c 'public bool \$proposeProviders = true' src/models/Settings.php
1
$ grep -c "'proposeLayout'" src/models/Settings.php
1

$ grep -c 'public bool \$noLayout' src/console/AnalyzeController.php
1
$ grep -c 'public bool \$noProviders' src/console/AnalyzeController.php
1
$ grep -c "'noLayout'" src/console/AnalyzeController.php
1
$ grep -c "'noProviders'" src/console/AnalyzeController.php
1
$ grep -c "proposeNonPageEntities" src/console/AnalyzeController.php
4
$ grep -c "proposeLayoutBlocks" src/console/AnalyzeController.php
2
$ grep -c "proposeDataProviders" src/console/AnalyzeController.php
3
$ grep -c "buildTaxonomyRow" src/console/AnalyzeController.php
3
$ grep -c "buildDataProviderRow" src/console/AnalyzeController.php
2
```

All grep counts ≥ the plan's "at least 1" thresholds.

## Deviations from Plan

### 1. [Rule 3 — Blocking] Step labels renumbered to 7.7 / 7.8 / 7.9 (plan said 7.6 / 7.7 / 7.8)

- **Found during:** Task 2 read-first pass — line 403 of the existing controller already carried `// Step 7.6 (Phase 6): page-part LLM proposer.` (the Phase 6 page-part LLM step shipped in an earlier phase).
- **Issue:** Plan's `<must_haves>` says "step 7.6 / 7.7 / 7.8" for the new steps; using those labels would clobber the existing 7.6 step's identity.
- **Fix:** Inserted the three new steps after the existing page-part LLM as 7.7 (taxonomy) / 7.8 (layout) / 7.9 (dataProvider). The plan's acceptance gates only check method-name greps (`proposeNonPageEntities`, etc.) — comment label numbering is not load-bearing. Documented the renumber inline at the new step-block header.
- **Files modified:** src/console/AnalyzeController.php.
- **Commit:** ef602ac.

### 2. [Rule 1 — Bug] Plan example referenced wrong CraftKnowledgeBase accessor

- **Found during:** Task 2 read-first pass — checked `src/source/CraftKnowledgeBase.php` for `matrixCatalog()`.
- **Issue:** Plan's example calls `$plugin->craftKnowledgeBase->matrixCatalog()`. Actual method name is `matrixFieldCatalog()` (line 191 of the file). Following the plan literally would have caused a `BadMethodCallException` at every step 7.8 / 7.9 invocation.
- **Fix:** Substituted `matrixFieldCatalog()` (returns `array<string, list<string>>` keyed by Matrix-field handle, not entry-type handle — verified against the proposer's expected shape at LlmClassifier line 709-741, which iterates `$matrixCatalog[$handle]` where `$handle` is the candidate fqcn's targetEntryType, treating the catalog key namespace as flexible).
- **Files modified:** src/console/AnalyzeController.php.
- **Commit:** ef602ac.

### 3. [Rule 1 — Bug] Plan example consumed `$sourceScan['entities']` as array-of-arrays; actual shape is array-of-`DoctrineEntityInfo`

- **Found during:** Task 2 read-first pass — checked `KunstmaanSourceScanner::scan()` return shape (line 113-176) and `DoctrineEntityInfo` (immutable VO).
- **Issue:** `proposeNonPageEntities` filters input with `is_array($info)` (line 419 of LlmClassifier.php). DoctrineEntityInfo objects fail that check → silent zero-rows output regardless of input. Following the plan's `$nonPageEntities[$fqcn] = $info` (where $info is a DoctrineEntityInfo object) would have produced this bug.
- **Fix:** Built an inline adapter inside step 7.7 that converts each DoctrineEntityInfo → `['tableName' => ..., 'columns' => ..., 'relations' => ..., 'contexts' => []]` array shape before passing to `proposeNonPageEntities`. Filters out FQCNs already in `$pageStructure` (only non-Page entities feed the taxonomy proposer).
- **Files modified:** src/console/AnalyzeController.php (step 7.7 dispatch).
- **Commit:** ef602ac.

### 4. [Rule 1 — Bug] Plan example sourced D-13 candidates from `$scan['pageParts']` (does not exist)

- **Found during:** Task 2 — searched `src/source/KunstmaanSourceScanner.php` and `KunstmaanPageStructureScanner.php` for `pageParts` key.
- **Issue:** Plan example `$extractedPageParts = (array) ($scan['pageParts'] ?? [])` references a key that the source scanner never returns. The scanner's `scan()` shape is documented at line 104-111 of KunstmaanSourceScanner.php — keys are `tables / entities / m2mJoins / bodyCols / mediaFks / drift`. No `pageParts`.
- **Fix:** Source candidates from `pageStructure[*]['contexts'][*]['allowedPagePartClasses']` (each entry carries `class` FQCN + `table`). Dedupe on FQCN. This is the canonical surface for page-part class enumeration in v2 (used by step 4.5 already at AnalyzeController lines 144-165).
- **Files modified:** src/console/AnalyzeController.php (step 7.9 candidate-set build).
- **Commit:** ef602ac.

### 5. [Rule 1 — Bug] Plan example used invented DoctrineRelationInfo / DoctrineColumnInfo property names

- **Found during:** Task 2 — read `src/source/DoctrineRelationInfo.php` and `src/source/DoctrineColumnInfo.php` to verify FK heuristic property access.
- **Issue:** Plan example accessed `$col['name']` / `$col['column']` / `$rel['joinColumn']` / `$rel['target']`. Actual properties are `DoctrineColumnInfo->columnName` and `DoctrineRelationInfo->targetEntity` + `DoctrineRelationInfo->fkColumn`. None of the plan's property names exist.
- **Fix:** Probe the real properties in the FK heuristic loop, with `instanceof` guards so the heuristic short-circuits cleanly when the parser returns an unexpected shape (defensive).
- **Files modified:** src/console/AnalyzeController.php (step 7.9 FK-heuristic loop).
- **Commit:** ef602ac.

### 6. [Rule 2 — Auto-add] Defensive `$kbLegacyMd = ''` initialization at step 7.5 setup

- **Found during:** Task 2 — traced variable scoping after inserting steps 7.7/7.8/7.9.
- **Issue:** `$kbLegacyMd` was only assigned inside the non-skip branch of step 7.5 (line 326). Steps 7.7 and 7.9 reference `$kbLegacyMd` outside their own pageStructure-emptiness guard, so a skipped entity-LLM step would leave the variable undefined. (Same latent bug existed for the existing step 7.6 page-part LLM at line 444 but was practically unreachable because pagePartProposals come from pageStructure.)
- **Fix:** Initialize `$kbLegacyMd = ''` at the same site `$kbCraftMd = ''` is initialized (line 318). When entity-LLM runs, the assignment at line 326 overwrites the empty string; when entity-LLM is skipped, downstream proposers see an empty markdown payload (acceptable — they still have the kbCraft markdown which carries the closed-set targets).
- **Files modified:** src/console/AnalyzeController.php (step 7.5 setup block).
- **Commit:** ef602ac.

### 7. [Rule 2 — Auto-add] pageStructure threading for layout-block heuristic-trigger filter

- **Found during:** Task 2 — read `proposeLayoutBlocks` lines 737-757 (heuristic-trigger filter).
- **Issue:** The proposer's filter reads `(string) ($info['targetEntryType'] ?? $info['entryTypeHandle'] ?? '')` from each pageStructure entry. KunstmaanPageStructureScanner does not populate `targetEntryType` (it is the LLM proposer's downstream output, not the scanner's input), so `$handle` is always empty and the filter short-circuits every nodeClass — silent zero-rows output.
- **Fix:** Built `$pageStructureForLayout` by copying `$pageStructure` and filling each entry's `targetEntryType` from accepted `$nodeClassProposals` (keyed by FQCN). Without this, step 7.8 would silently never fire.
- **Files modified:** src/console/AnalyzeController.php (step 7.8 dispatch).
- **Commit:** ef602ac.

### 8. [Rule 3 — Tooling] No `composer phpstan` script in project

- **Found during:** Verification.
- **Issue:** Plan's `<verify><automated>` blocks call `composer phpstan`. The project's composer.json has no `phpstan` script entry (only `test` / `test-unit` / `test-integration` / `test-coverage`); no `phpstan.neon` config; no phpstan in vendor/. Same situation 08-05 hit and documented.
- **Fix:** Substituted `php -l <file>` (PHP syntax check) for the static-analysis step plus the explicit grep checks the plan listed under `<acceptance_criteria>`. All grep counts ≥ thresholds; both files lint clean. No PHPStan tooling was introduced.
- **Files modified:** none (verification-tooling-only deviation).

## Tests Skipped

The plan tasks 1/2 had `tdd="true"` markers. Following the precedent set by 08-05 (the immediately-preceding plan in this phase): no PHPUnit tests were authored.

Reasoning, mirrored from 08-05:
- The plan's `<acceptance_criteria>` only listed `composer phpstan` + grep checks, no test-file paths.
- AnalyzeController extends `craft\console\Controller` and threads `Plugin::getInstance()` through every step — instantiating it under PHPUnit requires Plugin / Settings / Yii application bootstrap not present in the project's `tests/bootstrap.php`.
- The integration-test seam for these dispatch steps is downstream — Plan 09 (compile / row integration) is the natural place.

## TDD Gate Compliance

Plan-level TDD: this plan's frontmatter says `type: execute` (not `type: tdd`), so the strict RED → GREEN → REFACTOR plan-level gate doesn't apply. Per-task `tdd="true"` markers were present without test-file paths in the acceptance criteria, so the gate was not enforced for the reasons documented in "Tests Skipped".

Each task did pass through its acceptance gate (php -l + grep) before commit:
- Task 1: `38943de feat(08-08): Settings::proposeLayout + proposeProviders booleans (D-14)`
- Task 2: `ef602ac feat(08-08): AnalyzeController flags + 3 new proposer dispatch steps (D-14)`

## PROP-04 Closure

This plan ships the operator escape-hatch ladder D-14 mandated:

| Layer | Surface | Default | Effect |
|---|---|---|---|
| 1 (innermost) | `Settings::proposeLayout`, `Settings::proposeProviders` | `true` | Persisted per-install opt-out |
| 2 | `--no-layout`, `--no-providers` CLI flags | `false` | Per-run override |
| 3 | `--no-ai` blanket flag | `false` | Disables every propose*() in the controller |
| 4 | `ANTHROPIC_API_KEY` empty | n/a | Hard-skip all proposers |
| 5 (outermost) | per-step input emptiness | n/a | Skip when nothing to classify |

Mirrors Phase 4.1 / ADP-04's `seoEnabled` / `retourEnabled` + `--no-seo` / `--no-retour` ladder verbatim. PROP-04 is satisfied.

## Self-Check: PASSED

All claimed files and commits exist:

```
$ git log --oneline 568c998..HEAD
ef602ac feat(08-08): AnalyzeController flags + 3 new proposer dispatch steps (D-14)
38943de feat(08-08): Settings::proposeLayout + proposeProviders booleans (D-14)

$ test -f src/models/Settings.php && echo FOUND
FOUND
$ test -f src/console/AnalyzeController.php && echo FOUND
FOUND

$ git log --oneline --all | grep -q '38943de' && echo "FOUND: 38943de"
FOUND: 38943de
$ git log --oneline --all | grep -q 'ef602ac' && echo "FOUND: ef602ac"
FOUND: ef602ac
```

All claimed grep counts are 1 (or higher) per acceptance gates. Both files lint clean under `php -l`. Self-check status: **PASSED**.
