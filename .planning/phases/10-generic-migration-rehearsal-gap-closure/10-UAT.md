---
status: partial
phase: 10-generic-migration-rehearsal-gap-closure
source:
  - 10-01-SUMMARY.md
  - 10-02-SUMMARY.md
  - 10-03-SUMMARY.md
  - 10-04-SUMMARY.md
  - 10-05-SUMMARY.md
  - 10-06-SUMMARY.md
started: 2026-04-28T18:29:49Z
updated: 2026-04-28T18:37:38Z
---

## Current Test

[testing paused — 6 items outstanding]

## Tests

### 1. CQM regenerate/finalize release gate
expected: In `/Users/macbook25/Sites/cqm-craft-website`, rerunning the current workflow regenerates migration artifacts so unresolved finalize tokens are no longer hidden: if any remain, the command exits non-zero for live release paths and `storage/migration/REPORT.md` shows `finalize.unresolvable` plus bounded structural diagnostics. If none remain, `finalize.unresolvable` is zero.
result: [pending]

### 2. Page-rooted coverage no-input placeholders removed
expected: Regenerated `storage/migration/PAGE-ROOTED-COVERAGE.md` no longer contains warning/unsupported rows created solely from absent scanner input such as `asset:not-discovered`, `ckeditor_ref:not-discovered`, or `many_to_*:not-discovered`; any remaining warning/unsupported rows are backed by real source, mapping, structure, or relation evidence.
result: [pending]

### 3. NewsPage employee/image evidence remains actionable
expected: NewsPage-style FK/asset evidence such as `employee_id`, `image_id`, and `preview_image_id` is not silently treated as migrated when mapping is empty or incomplete; regenerated coverage/reporting keeps these as actionable warnings until mapped, dropped, or explicitly accepted.
result: [pending]

### 4. Incomplete accepted column mappings stay blocking/actionable
expected: An accepted source column mapping with `targetHandle` but no `handler` remains a warning/actionable coverage row instead of being reclassified as migrated.
result: [pending]

### 5. Legacy media URL diagnostics are actionable
expected: A CKEditor field with an unresolved legacy `/uploads/media/...` URL records a `media_url` unresolved diagnostic in addition to the unresolved marker, so `REPORT.md` gives structural detail for the blocking count without leaking CKEditor body content.
result: [pending]

### 6. CQM runbook release gates are visible
expected: `.planning/rehearsal/v1.0/cqm/README.md` documents the default dry-run command, `--live` write mode, and the release gates requiring zero unresolved finalize tokens and no unaccepted blocking Page-rooted coverage rows.
result: [pending]

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0
blocked: 0

## Gaps

[none yet]
