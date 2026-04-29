---
status: complete
phase: 12-cp-migration-console-queue-workflow
source:
  - 12-01-SUMMARY.md
  - 12-02-SUMMARY.md
  - 12-03-SUMMARY.md
  - 12-04-SUMMARY.md
  - 12-05-SUMMARY.md
  - 12-06-SUMMARY.md
  - 12-07-SUMMARY.md
  - 12-08-SUMMARY.md
  - 12-09-SUMMARY.md
  - 12-10-SUMMARY.md
  - 12-UI-SPEC.md
started: 2026-04-29T11:36:47Z
updated: 2026-04-29T13:55:27Z
---

## Current Test

complete: true
result: "UAT checklist complete: 8 passed, 2 fixed minor gaps."

## Tests

### 1. Settings page loads
expected: Open the plugin Settings page in the Craft Control Panel. The page loads without a Twig template error, shows the five groups Connectivity, Mapping, Execution, Adapters, and Retention, and the Anthropic API key is shown only as a masked/read-only presence indicator rather than the raw key.
result: issue
reported: "Yes, it works. However, I think ideally we should have a sidebar with the different sections like how https://cqm-craft-website.test/craft-cms/formie/settings?site=default does it."
severity: minor

### 2. Utility opens the migration console
expected: Open Craft Utilities and choose the Kunstmaan Migration Console. It remains under Utilities, not a top-level CP nav item, and shows the title "Kunstmaan Migration Console", the CLI-canonical subtitle, and tabs in this order: Readiness, Analyze, Mapping, Compile, Runs, Reports, Danger Zone.
result: pass
note: "Initially failed on missing run-record table and invalid Twig coalesce expression; fixed in 9d433da and confirmed by user."

### 3. Readiness tab summarizes gates
expected: The Readiness tab shows Environment, Connectivity, Mapping & Compile, Queue, and Latest run cards with text status labels such as Passed, Warning, Blocked, or Unknown. Blocked/unknown items include remediation copy, and Queue dry run is only available when dry-run gates pass.
result: pass

### 4. Analyze tab is AI-explicit and queued
expected: The Analyze tab has entity, locale, and since filters, an AI confirmation checkbox, the required Anthropic missing-key disabled copy when no key is configured, an equivalent CLI command, and Queue analyze only becomes usable after the safety/API/AI confirmation gates pass. Submitting queues a job and does not run analyze inline.
result: issue
reported: "Yes it does. Anything we can do about the text fields? Could they just be dropdowns or something?"
severity: minor

### 5. Mapping tab supports canonical review
expected: The Mapping tab exposes URL-preserved filters for entity/page, status, kind, finding severity, and search; rows show source, target, handler, status, finding, and rationale before edit controls; batch actions support accept, needs-review, drop, and warning acceptance with typed confirmations for high-risk actions while updating the canonical mapping.yaml only.
result: pass
note: "Initially lost tab=mapping on filter submit; fixed in 9b9205b and confirmed by user."

### 6. Compile and Reports tabs expose artifacts
expected: The Compile tab shows latest compile timestamp/status, fatal/warning counts, artifact/log paths, readiness gates, equivalent CLI command, and a Queue compile form. The Reports tab lists REPORT.md, VERIFY artifacts, PAGE-ROOTED-COVERAGE.md, MAPPING-AUDIT.md, schema/graph artifacts, and provides a queued verify/report action without inventing a separate reporting model.
result: pass

### 7. Runs tab and run detail show durable records
expected: The Runs tab lists durable migration runs with run ID, stage, mode, status, filters, initiating admin, queue job IDs, progress, timestamps, artifacts, and actions. View details opens the selected run, including gate snapshot, filters/options, queue job IDs, log/artifact paths, failure details, readable summary data, and collapsed raw JSON where applicable.
result: pass

### 8. CP queue actions create jobs, not inline workflows
expected: Queue analyze, compile, verify/report, dry-run, and live actions are CP POST/admin-only, create a run record, push a Craft queue job, store the queue job ID, redirect back to the console Runs/detail context, and do not execute long migration workflows inline during the web request.
result: pass
reported: "Yes, however not able to queue anything since we have blocking items."
note: "Initially blocked from verification. Fixed Analyze checkbox pre-submit gating, queued workflow option sanitization, CP compile overwrite intent, and queue progress-label truncation. Confirmed CP Compile created run #3 with queue job #20654 and completed successfully."

### 9. Live migration is strictly gated
expected: The live migration panel is visually separated from dry run and blocks live queueing unless non-production, admin, elevated session, MIGRATE LIVE typed phrase, successful same-options dry run, recent no-fatal compile, queue worker readiness, backup acknowledgement, warning/unsupported acceptance, CP live opt-in, and job production hard-block gates all pass. Unverifiable queue readiness blocks CP live and points to CLI remediation.
result: pass

### 10. Danger Zone remains deferred
expected: The Danger Zone tab shows reset/truncate and artifact cleanup panels with the required RESET MIGRATION STATE and DELETE ARTIFACTS copy, but those actions are disabled/deferred in this phase and there are no active destructive reset or cleanup submit forms.
result: pass

## Summary

total: 10
passed: 8
issues: 2
pending: 0
skipped: 0
blocked: 0

## Gaps

- truth: "Settings page should expose section navigation in a sidebar like Formie settings."
  status: fixed
  reason: "User reported: Yes, it works. However, I think ideally we should have a sidebar with the different sections like how https://cqm-craft-website.test/craft-cms/formie/settings?site=default does it."
  severity: minor
  test: 1
  root_cause: "The settings fragment rendered grouped fields but had no in-page section navigation."
  artifacts:
    - path: "templates/_settings.twig"
      fix: "Added fragment-safe sidebar navigation with anchors for Connectivity, Mapping, Execution, Adapters, and Retention."
    - path: "tests/unit/Plugin/SettingsHtmlTest.php"
      fix: "Added a source contract for the settings sidebar."
  missing: []
  debug_session: ""
- truth: "Analyze filters should use guided controls where values are knowable instead of plain comma-separated text fields."
  status: fixed
  reason: "User reported: Yes it does. Anything we can do about the text fields? Could they just be dropdowns or something?"
  severity: minor
  test: 4
  root_cause: "The Analyze tab currently renders entity and locale filters as free-text inputs even though entities can often be derived from source introspection/mapping context and locales can reuse existing locale option discovery."
  artifacts:
    - path: "templates/_console/_analyze.twig"
      fix: "Entity and locale filters now render multi-select controls when options are available, with comma-separated text fallback."
    - path: "src/controllers/MigrationConsoleController.php"
      fix: "View model now exposes analyze filter option lists from mapping entities and legacy locale discovery."
  missing: []
  debug_session: ""
