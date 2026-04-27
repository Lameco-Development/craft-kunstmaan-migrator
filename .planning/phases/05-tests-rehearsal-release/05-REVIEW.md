---
phase: 05-tests-rehearsal-release
reviewed: 2026-04-27
depth: standard
status: issues-found
findings_count: 9
severity_breakdown:
  high: 0
  medium: 3
  low: 4
  info: 2
---

# Phase 5 Code Review

## Summary

Phase 5 ships small, well-bounded production code (one new console controller, two operator scripts, a CI workflow) anchored by a large test surface. No HIGH-severity correctness or security defects were found. The three rehearsal gate parsers in `RehearsalController` are sound: I specifically traced the suspected Gate 2 vs Gate 3 token collision (Gate 2's `\basset:\d+\b` regex over the full `REPORT.md` body vs the `## Asset RCA` table emitted by `MigrateController`), and confirmed it is a non-issue — the RCA table renders `legacyId` as a bare `%d` and the `reason` column draws from the closed-set taxonomy `filesystem_404 | mime_mismatch | too_large | deferred_unresolved`, none of which match `asset:N`. The deliberate `NeverProductionTrait` omission (D-22) is correctly justified in the class docblock — the controller is read-only over committed artifacts.

The MEDIUM findings are: (1) `tools/check-coverage.php` silently reports 100% for files with zero statements (interface-only files / abstracts mask coverage gaps); (2) the CI smoke job pulls Craft via `composer create-project craftcms/craft scratch-craft` with no version constraint — a future Craft 6 release will silently break the smoke job; and (3) `tools/capture-transform-fixtures.php` writes `mkdir(0755)` directories under the repo without honoring umask, potentially leaving group-writable artifacts on shared dev hosts. Recommend addressing the unpinned Craft constraint before merging Phase 5; the other two are deferrable.

## Findings

### HIGH

None.

### MEDIUM

| # | File:Line | Issue | Recommendation |
|---|-----------|-------|----------------|
| 1 | tools/check-coverage.php:53-55 | When a `<file>` element reports `statements="0"` (interface-only / abstract files / file with only a class declaration), the gate reports `100.0%` and silently passes. A target listed in the `MODULES` allow-list with zero executable statements would never trip the threshold even if its callers were untested. | Treat `$statements === 0` as either a skip-with-notice or as a failure. Minimum: emit a `WARN` line so it's visible in CI output. Better: `if ($statements === 0) { fwrite(STDOUT, sprintf("  SKIP %s (zero statements)\n", $rel)); continue; }`. |
| 2 | .github/workflows/ci.yml:40-42 | `composer create-project craftcms/craft scratch-craft` has no version constraint. When Craft 6 ships, the smoke job will install it against this plugin (which requires `craftcms/cms: ^5.0`), producing either a confusing solver failure or — worse — a partially installed scratch site that masks the breakage. CI breakage will land overnight without any code change. | Pin to the supported major: `composer create-project "craftcms/craft:^5.0" scratch-craft …`. Bump deliberately when Phase 6 / Craft 6 work begins. |
| 3 | tools/capture-transform-fixtures.php:90, 162 | `mkdir(..., 0755, true)` is called without verifying that the existing octal mode survives umask masking. On a multi-user dev host where the operator runs under a `umask 002` shell, the resulting directories become `0775` (group-writable). The captured fixtures and the `tests/fixtures/transform/mapping.json` snapshot are committed into the repo — group-writability propagates into the working tree on those hosts. | Operator-facing only and not exercised in CI, so impact is low — but worth either documenting in the script header or wrapping with a `umask(0022)` set/restore before the `mkdir` calls. |

### LOW

| # | File:Line | Issue | Recommendation |
|---|-----------|-------|----------------|
| 4 | src/console/RehearsalController.php:161 | The "block end" regex `^(#{1,6}\s|\[2\/2\])` terminates Gate 1's count-match block at the next markdown heading. If a future VerifyController emitter adds a sub-heading like `### details` inside the count block, parsing terminates prematurely and any `WARN`/`FAIL` lines below it are silently ignored — the gate would falsely pass. | Anchor the gate-end on `[2/2]` only, or on the explicit `## ` heading prefix (not all heading levels). Add a regression test for "sub-heading inside count block". |
| 5 | src/console/RehearsalController.php:218-258 | `parseAssetRcaTable` keeps `headerSeen` / `separatorSeen` as section-scoped flags but never resets them. If REPORT.md ever contains two `## Asset RCA` sections (e.g., a TOC link rendered as `## Asset RCA` plus the real table), only the first section's first row is treated as the header, and rows in the second section are mis-classified — likely misreporting failures. | Reset `$headerSeen` / `$separatorSeen` on each `## Asset RCA` heading entry, or fail loudly if the section appears twice. |
| 6 | src/console/RehearsalController.php:248 | `array_slice(explode('|', $trimmed), 1, -1)` silently drops the last cell when a row is missing its trailing `|`. A row written as `\| asset:42 \| (missing reason) \| 1` (no trailing pipe) yields cells `['asset:42', '(missing reason)']` — Gate 3 would mis-attribute "reason missing" detection because the count cell is now in the reason slot, not reason itself being empty. | Either require the trailing `|` (warn on absence) or use `explode` without `array_slice(…, -1)` and skip the leading-empty cell only. |
| 7 | tools/capture-transform-fixtures.php:122-127 | `iterator_to_array($extractIter, true)` with `preserve_keys=true` collides duplicate string keys silently. `ExtractService::run` returns either `iterable<int, mixed>` or an associative report; if the iterator ever yields per-batch reports keyed by entity FQCN with collisions, the script silently keeps only the last one. The follow-up `echo "Extract report: ..."` then emits a misleading partial report. | If the script truly tolerates either return shape, force `preserve_keys=false`, or wrap with `iterator_to_array` only after a runtime shape probe. |

### INFO / NIT

| # | File:Line | Issue | Recommendation |
|---|-----------|-------|----------------|
| 8 | tools/capture-transform-fixtures.php:144 | `str_ends_with(basename($dir), '_' . $simpleName)` searching for `PageNode` will also match a sibling directory named `…_RootPageNode` or `…_SubPageNode`. Operator-curated `$TARGET_ENTITIES` list mitigates this in practice, but the shadowing is non-obvious. | Use a `preg_match('/(^|_|\W)' . preg_quote($simpleName, '/') . '$/', basename($dir))` style match, or document the substring-shadowing caveat above the `$TARGET_ENTITIES` array. |
| 9 | tests/integration/transform/TransformCharacterizationTest.php:117 | `@mkdir(dirname($goldenPath), 0755, true);` suppresses the error result. If the directory creation fails (read-only mount, permission denied), the next `file_put_contents` call still attempts to write and produces a confusing `failed to open stream` rather than a clear "cannot create golden directory" message. | Drop the `@`; check the return value: `if (!is_dir($d) && !mkdir($d, 0755, true) && !is_dir($d)) { self::fail("…"); }`. Same idiom the production code at `tools/capture-transform-fixtures.php:90` already uses. |

## Files Reviewed

- src/console/RehearsalController.php
- tools/capture-transform-fixtures.php
- tools/check-coverage.php
- .github/workflows/ci.yml
- phpunit.xml.dist
- composer.json
- tests/integration/transform/TransformCharacterizationTest.php
- tests/unit/console/RehearsalControllerTest.php
- tests/unit/analyze/HeuristicProposerTest.php
- tests/unit/finalize/CkeditorRewriterServiceTest.php
- tests/unit/fields/handlers/PlainTextHandlerTest.php
- tests/unit/fields/handlers/SplitNameHandlerTest.php
- tests/unit/fields/handlers/RelationHandlerTest.php
- tests/unit/fields/handlers/MatrixHandlerTest.php
- tests/unit/fields/handlers/AssetHandlerTest.php

Cross-referenced (read-only) for verification:
- src/console/MigrateController.php (Asset RCA emitter — confirmed Gate 2/3 collision is a non-issue)
- src/load/AssetMigrationService.php (RCA reason taxonomy — closed set, no `asset:N` literals)
- src/analyze/HeuristicProposer.php (heuristic order, signatures referenced by tests)
