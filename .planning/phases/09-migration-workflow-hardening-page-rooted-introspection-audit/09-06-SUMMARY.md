---
phase: 09-migration-workflow-hardening-page-rooted-introspection-audit
plan: 06
subsystem: finalize
tags: [ckeditor, security, unresolved-markers, base64url]
requires: []
provides:
  - comment-safe unresolved CKEditor marker encoding
  - malicious legacy URL regression coverage
affects:
  - src/finalize/CkeditorRewriterService.php
  - tests/unit/finalize/CkeditorRewriterServiceTest.php
tech_stack:
  added: []
  patterns:
    - base64url marker payloads
    - PHPUnit TDD regression coverage
key_files:
  created: []
  modified:
    - src/finalize/CkeditorRewriterService.php
    - tests/unit/finalize/CkeditorRewriterServiceTest.php
decisions:
  - "Use unpadded base64url in sourceB64 marker fields so raw legacy URLs never appear inside HTML comments while remaining recoverable."
metrics:
  completed_at: 2026-04-28T11:01:49Z
  tasks_completed: 2
  files_modified: 2
---

# Phase 9 Plan 06: CKEditor Unresolved Marker Safety Summary

CKEditor unresolved markers now preserve editor-visible unresolved references without allowing malicious legacy URL text to break out of HTML comments.

## Tasks Completed

| Task | Name | Status | Commit |
|---|---|---:|---|
| 1 | Encode unresolved marker source values safely | Complete | `39666ca`, `b3e6f8f` |
| 2 | Run finalize regression tests | Complete | verification-only; no code changes |

## Changes Made

- Added failing TDD coverage requiring unresolved markers to use `sourceB64=...` instead of raw `source=...`.
- Added malicious legacy URL regression coverage for comment-breakout and markup-looking payloads containing `-->`, `<script`, and quote-looking text.
- Added `CkeditorRewriterService::unresolvedMarker()` to emit unpadded base64url source payloads.
- Reused the encoded marker helper for unresolved media URLs, unresolved `[M]` media placeholders, and unresolved `[NT]` node translation placeholders.
- Kept the original unresolved URL/literal in the `src`/`href` attribute or placeholder position for editor/operator discovery.

## Verification

| Command | Result |
|---|---|
| `vendor/bin/phpunit tests/unit/finalize/CkeditorRewriterServiceTest.php --filter 'Unresolved\|Marker\|Comment' --testdox` | Passed after implementation: 5 tests, 24 assertions. PHPUnit reported the pre-existing "No code coverage driver available" warning. |
| `vendor/bin/phpunit tests/unit/finalize/CkeditorRewriterServiceTest.php --testdox` | Passed: 24 tests. |
| `composer test-unit` | Passed. |

## TDD Gate Compliance

- RED commit: `39666ca` (`test(09-06): add failing safe unresolved marker coverage`) produced the expected failures against raw comment markers.
- GREEN commit: `b3e6f8f` (`feat(09-06): encode unresolved marker sources safely`) made the targeted unresolved marker tests pass.
- No refactor commit was needed.

## Deviations from Plan

None - plan executed as written.

## Auth Gates

None.

## Known Stubs

None.

## Threat Flags

None. The changed code mitigates the plan's existing CKEditor marker/comment trust-boundary threats and does not introduce a new network endpoint, auth path, file-access pattern, or schema boundary.

## Files Changed

- `src/finalize/CkeditorRewriterService.php`
- `tests/unit/finalize/CkeditorRewriterServiceTest.php`

## Commits

- `39666ca` — `test(09-06): add failing safe unresolved marker coverage`
- `b3e6f8f` — `feat(09-06): encode unresolved marker sources safely`

## Deferred Issues

None.

## Self-Check: PASSED

- Found `src/finalize/CkeditorRewriterService.php`.
- Found `tests/unit/finalize/CkeditorRewriterServiceTest.php`.
- Found commit `39666ca`.
- Found commit `b3e6f8f`.
