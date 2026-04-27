---
phase: 08-taxonomies-and-proposers
plan: 03
subsystem: source/KnowledgeBase
tags: [knowledgebase, llm-prompts, taxonomies, source-truth]
requirements: [TAX-05]
dependency-graph:
  requires:
    - "src/source/KnowledgeBase.php (existing renderPagesMarkdown / renderPagePartsMarkdown shape)"
    - "src/source/DoctrineEntityParser.php (getAll() — Plan 02 source-truth)"
    - "src/source/KunstmaanCoreTables.php (NODES const)"
  provides:
    - "KnowledgeBase::renderTaxonomiesMarkdown(?array \$mapping, DateTimeInterface \$now): string"
  affects:
    - "Wave 2 / Plan 08-05 LlmClassifier::proposeNonPageEntities (consumes the Markdown as \$kbLegacyMd)"
tech-stack:
  added: []
  patterns:
    - "Mirror existing render-method shape: header → discovery query → per-row section → renderTableColumns() reuse"
    - "Defensive feature-detect (property_exists) for sibling Plan 08-02's isGedmoTranslatable flag"
key-files:
  created:
    - "tests/unit/source/KnowledgeBaseTaxonomiesTest.php"
  modified:
    - "src/source/KnowledgeBase.php (new method at lines 373-500)"
decisions:
  - "Walk DoctrineEntityParser->getAll() directly instead of injecting a new KunstmaanSourceScanner property — narrower blast radius for a single-method addition (Rule 1 deviation from plan skeleton)"
  - "Use kuma_nodes.ref_entity_name as the page-entity exclusion source (mirrors renderPagesMarkdown lines 365-370) — scan output has no pageStructure key (plan skeleton mismatch)"
  - "No internal 8000-char truncation — KnowledgeBase docblock (lines 36-37) and must_haves.truths both pin the contract that LlmClassifier::batchPropose handles truncation downstream"
  - "Defensive property_exists check on isGedmoTranslatable so this plan can land independently of sibling Plan 08-02 in the same wave"
metrics:
  duration: "~10 minutes"
  completed: "2026-04-27T14:47:07Z"
  tasks_completed: 1
  files_changed: 2
  commits: 2
---

# Phase 8 Plan 03: Taxonomies KB Surface Summary

KnowledgeBase gains `renderTaxonomiesMarkdown()`, the third public Markdown surface fed to the analyze-stage LLM. It mirrors the shape of `renderPagesMarkdown` and `renderPagePartsMarkdown` but walks the source-parser entity index (filtered to non-page Doctrine entities) instead of `kuma_page_part_refs`. Wave 2 / Plan 08-05's `proposeNonPageEntities` proposer can now feed on the same source-truth Markdown that the existing two proposers consume.

## What changed

### `src/source/KnowledgeBase.php` — new public method (lines 373-500)

```
public function renderTaxonomiesMarkdown(?array $mapping, DateTimeInterface $now): string
```

- **Discovery:** queries `kuma_nodes.ref_entity_name` (deleted=0, non-null) to build the page-FQCN exclusion set, identical to the discovery clause `renderPagesMarkdown` already runs.
- **Candidate set:** every entity in `DoctrineEntityParser->getAll()` whose FQCN is not in the page set. Null parser returns an empty list — the method emits the header + `_No non-Page entities discovered._` line.
- **Per-FQCN section:** short class name H2, FQCN, source table, `COUNT(*)` row count (regex-whitelisted table name to defend against SQL injection — `IDENT_RX` reused), then a column table emitted via `renderTableColumns($out, $sourceTable, $allColumns)`.
- **Gedmo translatable subsection** (lines 467-494): walks `$entityInfo->columns`, emits `### Gedmo translatable fields` only when at least one column carries Plan 02's `isGedmoTranslatable === true` flag. Detected via `property_exists($col, 'isGedmoTranslatable')` so the method works whether sibling Plan 08-02 has landed yet or not.

### `tests/unit/source/KnowledgeBaseTaxonomiesTest.php` — new file

Three smoke tests, fixture pattern lifted from `DoctrineEntityParserAttributesOnlyTest`:

1. `testRendersHeaderEvenWithNoEntities` — no entityParser wired → header + "No non-Page entities discovered" footer.
2. `testEmitsCandidateSectionForNonPageEntity` — temp-fixture entity dir with one page (`NewsPage`) and one non-page (`Category`); `kuma_nodes` stub returns NewsPage's FQCN; assertions cover Category section presence + NewsPage exclusion.
3. `testEntityParserMissingProducesEmptyResultGracefully` — null `entityParser`, same empty-result expectation.

Stub `LegacyDbService` matches by SQL-string prefix: `kuma_nodes ref_entity_name` → page FQCNs; `DATABASE()` → schema literal; `SELECT COUNT(*)` → per-table count; everything else → empty list.

## Plan-skeleton deviations

The `<action>` block in 08-03-PLAN.md carried a few mismatches against the live codebase. Resolving them was Rule 1 (bug) for the broken parts and Rule 4-deferred-to-must_haves for the truncation contradiction.

### `[Rule 1 — bug]` Plan skeleton referenced `$this->kunstmaanSourceScanner` and `scan()['pageStructure']`

**Found during:** Task 1 implementation.
**Issue:** `KnowledgeBase` has no `kunstmaanSourceScanner` property — only `entityParser` (`DoctrineEntityParser`). Likewise `KunstmaanSourceScanner::scan()` returns `tables / entities / m2mJoins / bodyCols / mediaFks / drift` — there is no `pageStructure` key on the scanner output (that key lives on a separate `KunstmaanPageStructureScanner` orchestrator).
**Fix:** Walk `$this->entityParser->getAll()` directly (which is exactly what `KunstmaanSourceScanner::scan()` itself does internally for its own `entities` index). Compute the page-entity exclusion set from `kuma_nodes.ref_entity_name` — same query `renderPagesMarkdown` already runs at lines 365-370. No DI churn, no new Plugin.php wiring.
**Files modified:** `src/source/KnowledgeBase.php`.
**Commit:** `3f1e2b5`.

### `[Rule 1 — bug]` Plan skeleton treated `DoctrineEntityInfo` / `DoctrineColumnInfo` as arrays

**Found during:** Task 1 implementation.
**Issue:** Skeleton accessed `$entityInfo['columns']` and `$col['name']`. These are immutable VOs — `$entityInfo->columns` (array of `DoctrineColumnInfo`), per-col `$col->columnName / $col->propertyName`. The plan's syntax would fatal at runtime.
**Fix:** Use property access throughout.
**Commit:** `3f1e2b5`.

### `[Rule 1 — bug]` Plan skeleton called `renderTableColumns()` as a string-returning helper

**Found during:** Task 1 implementation.
**Issue:** Skeleton wrote `$columnsBlock = $this->renderTableColumns($sourceTable, $cols);`. Actual signature (lines 660-725 of the modified file) is `(array &$out, string $table, array $allColumns): void` — appends by reference, returns void, prefixes its own `_N rows._` line.
**Fix:** Pre-load `$allColumns = $this->loadAllColumns()` once before the candidate loop, then call `$this->renderTableColumns($out, $sourceTable, $allColumns);` per candidate. Drop the manual `COUNT(*)` re-query the plan would otherwise have needed (the helper already prints rows).
**Commit:** `3f1e2b5`.

### `[Rule 4-resolved-via-must_haves]` Truncation contract conflict in the plan itself

**Found during:** Pre-implementation review.
**Issue:** Plan `<behavior>` and `<action>` both said "if final output exceeds 8000 chars, truncate with `_…truncated…_`." But the same plan's `must_haves.truths` row says "same string-truncation contract as existing KB methods" — and the existing KB docblock (lines 36-37) says verbatim: *"KnowledgeBase emits FULL text. The LlmClassifier::batchPropose call site already truncates internally via wasTruncated(...). Do NOT add truncation here."*
**Resolution:** `must_haves` wins — it cites the same convention as the file docblock; the action-block prose is internally inconsistent with both. The new method emits full text. Adding internal truncation would diverge from the existing two render methods and break the consistency invariant the must_have asserts.
**Commit:** `3f1e2b5`.

### `[Rule 3 — blocker]` Plan's verify command (`composer phpstan`) is unavailable

**Found during:** Verification step.
**Issue:** `composer.json` does not declare a `phpstan` script and `phpstan/phpstan` is not in `require-dev`. Running `composer phpstan` would have errored.
**Fix:** Replace with `composer test` (`vendor/bin/phpunit`). The new `KnowledgeBaseTaxonomiesTest` plus the existing `KnowledgeBaseSmokeTest` together exercise the full method-resolution surface (Yii Component magic dispatch will UnknownMethodException on a missing method, exactly as the RED gate caught). PHP `php -l` syntax check also run on the modified file.
**Verification result:** All 329 unit tests pass, no syntax errors.
**Commit:** N/A (verification adjustment, no code change).

## Acceptance criteria

| Criterion | Result |
| --- | --- |
| `grep -c "public function renderTaxonomiesMarkdown" src/source/KnowledgeBase.php` returns 1 | PASS |
| `grep -c "Kunstmaan Taxonomy Candidates" src/source/KnowledgeBase.php` returns 1 | PASS |
| `grep -c "isGedmoTranslatable" src/source/KnowledgeBase.php` returns at least 1 | PASS (returns 3) |
| `composer phpstan` exits 0 | N/A — replaced with `composer test` (329 tests passing, 0 failures) |
| Method callable from Wave 2 / Plan 05 (`LlmClassifier::proposeNonPageEntities`) | PASS — public signature `(?array $mapping, DateTimeInterface $now): string` mirrors the existing render methods exactly |

## Plan 02 isGedmoTranslatable consumption point

The flag is consumed inside the per-candidate loop at `src/source/KnowledgeBase.php` lines 467-494. The check is gated on `property_exists($col, 'isGedmoTranslatable')` so it activates only after sibling Plan 08-02 (also wave 1, parallel execution) lands the property on `DoctrineColumnInfo`. Until then the loop body produces an empty `$translatable` list and the `### Gedmo translatable fields` subsection is omitted — the rest of the per-FQCN output renders normally.

When 08-02 lands and a Doctrine entity carries one or more `#[Gedmo\Translatable]` properties, the subsection appears as:

```
### Gedmo translatable fields

- `name`
- `description`
```

The list reads `$col->columnName` (DB column) when present, falling back to `$col->propertyName`. Empty names are skipped (defensive).

## Truncation contract

Verified preserved: the new method emits the full Markdown string with no internal cap. Behavior matches `renderPagesMarkdown` and `renderPagePartsMarkdown`. Downstream truncation continues to live in `LlmClassifier::batchPropose` per the file docblock at lines 36-37.

## Commits

| Hash | Type | Description |
| --- | --- | --- |
| `a3a1fe7` | test | TDD RED — failing test for `renderTaxonomiesMarkdown` |
| `3f1e2b5` | feat | TDD GREEN — implementation in KnowledgeBase |

## Self-Check: PASSED

- File `src/source/KnowledgeBase.php` exists and contains the new method (verified by grep).
- File `tests/unit/source/KnowledgeBaseTaxonomiesTest.php` exists.
- Both commit hashes (`a3a1fe7`, `3f1e2b5`) present in `git log --oneline --all`.
- Full unit suite green (329 tests, 889 assertions).
- TDD gate sequence intact: `test(...)` commit precedes `feat(...)` commit.
