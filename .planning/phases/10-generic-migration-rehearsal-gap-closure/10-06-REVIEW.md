---
phase: 10-generic-migration-rehearsal-gap-closure
reviewed: 2026-04-28T00:00:00Z
depth: standard
files_reviewed: 10
files_reviewed_list:
  - src/audit/PageRootedCoverageAuditor.php
  - src/audit/PageRootedSurfaceDiscovery.php
  - src/console/MigrateController.php
  - src/finalize/CkeditorRewriterService.php
  - src/finalize/FinalizeWalker.php
  - src/load/MigrationReport.php
  - tests/unit/audit/PageRootedCoverageAuditorTest.php
  - tests/unit/audit/PageRootedSurfaceDiscoveryTest.php
  - tests/unit/console/MigrateControllerFailureExitTest.php
  - tests/unit/finalize/CkeditorRewriterServiceTest.php
findings:
  critical: 0
  warning: 2
  info: 0
  total: 2
status: issues_found
---

# Phase 10 Plan 10-06: Code Review Report

**Depth:** standard
**Files Reviewed:** 10
**Status:** issues_found

## Summary

Reviewed the Phase 10 plan 10-06 changes around CKEditor finalize unresolved diagnostics/report blocking and Page-rooted coverage discovery/auditing.

The targeted PHPUnit suite for the changed unit tests passes, but there are two correctness gaps that matter for release readiness:

1. Coverage auditing can still mark an accepted column as `migrated` when it has a target handle but no handler, hiding an incomplete source-column mapping.
2. Finalize unresolved diagnostics are only recorded for `[M...]` / `[NT...]` placeholders, while unresolved legacy `/uploads/media/...` URLs still increment `finalize.unresolvable` without producing actionable diagnostic rows.

## Warnings

### WR-01: Accepted column rows with missing handlers can be reported as migrated

**File:** `src/audit/PageRootedCoverageAuditor.php:183-185`

**Issue:**
`PageRootedSurfaceDiscovery::directFieldRows()` treats an accepted column as actionable only when both `targetHandle` and `handler` are present. However, `PageRootedCoverageAuditor::mappingCategories()` only checks `targetHandle` before classifying accepted column proposals as `migrated`.

That means a proposal like this can be discovered as a warning, but then the auditor can override it to `migrated`:

```php
[
    'kind' => 'column',
    'table' => 'generic_pages',
    'column' => 'employee_id',
    'targetHandle' => 'employee',
    'handler' => '',
    'status' => 'accepted',
]
```

This hides an incomplete mapping, which conflicts with the requirement that evidence-backed FK/asset/relation gaps remain actionable and that the plugin must not hide missing content.

**Fix:**
Align the auditor's completeness check with discovery by requiring both a target handle and handler before an accepted column mapping can override discovery to `migrated`.

Add a regression test in `PageRootedCoverageAuditorTest` for an accepted column with `targetHandle` set and `handler` empty, asserting the final audited category remains `warning`.

### WR-02: Unresolved legacy media URLs are counted as blocking but produce no diagnostic rows

**File:** `src/finalize/CkeditorRewriterService.php:540-543`

**Issue:**
Unresolved `[M...]` and `[NT...]` placeholders call `recordUnresolvedDiagnostic()`, but unresolved legacy media URLs handled by `rewriteAssetAttributes()` only append the unresolved marker:

```php
$marker = $this->unresolvedMarker($url);
return $attr . '=' . $quote . $url . $quote . $marker;
```

`FinalizeWalker` still increments `unresolvable` when the resulting HTML contains `<!-- MIGRATION:UNRESOLVED`, so a field containing only an unresolved `/uploads/media/...` URL will block the run via `finalize.unresolvable`, but `REPORT.md` will not include a corresponding row in `## Finalize unresolved diagnostics`.

That leaves operators with a blocking count but no structural details for this class of unresolved CKEditor reference.

**Fix:**
Record a structural diagnostic for unresolved media URLs as well. Avoid raw body/sample leakage; if the URL itself is considered acceptable structural evidence, ensure it is safely escaped in report rendering. Otherwise store a bounded/sanitized path field.

Add a regression test where `<img src="/uploads/media/missing.jpg">` is rewritten, `consumeUnresolvedDiagnostics()` returns one `media_url` row, and `FinalizeWalker`/report rendering surfaces that row.
