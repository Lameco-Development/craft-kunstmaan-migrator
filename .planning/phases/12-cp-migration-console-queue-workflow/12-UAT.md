---
status: testing
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
updated: 2026-04-29T11:43:19Z
---

## Current Test

number: 2
name: Utility opens the migration console
expected: |
  Open Craft Utilities and choose the Kunstmaan Migration Console. It remains under Utilities, not a top-level CP nav item, and shows the title "Kunstmaan Migration Console", the CLI-canonical subtitle, and tabs in this order: Readiness, Analyze, Mapping, Compile, Runs, Reports, Danger Zone.
awaiting: user response

## Tests

### 1. Settings page loads
expected: Open the plugin Settings page in the Craft Control Panel. The page loads without a Twig template error, shows the five groups Connectivity, Mapping, Execution, Adapters, and Retention, and the Anthropic API key is shown only as a masked/read-only presence indicator rather than the raw key.
result: issue
reported: "Yes, it works. However, I think ideally we should have a sidebar with the different sections like how https://cqm-craft-website.test/craft-cms/formie/settings?site=default does it."
severity: minor

### 2. Utility opens the migration console
expected: Open Craft Utilities and choose the Kunstmaan Migration Console. It remains under Utilities, not a top-level CP nav item, and shows the title "Kunstmaan Migration Console", the CLI-canonical subtitle, and tabs in this order: Readiness, Analyze, Mapping, Compile, Runs, Reports, Danger Zone.
result: issue
reported: "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'craft_starter_kit.kunstmaanmigrator_runs' doesn't exist; after fixing the run-table read path, Twig reported: The \"defined\" test only works with simple variables in \"kunstmaan-migrator/_console/_readiness\" at line 40."
severity: blocker

### 3. Readiness tab summarizes gates
expected: The Readiness tab shows Environment, Connectivity, Mapping & Compile, Queue, and Latest run cards with text status labels such as Passed, Warning, Blocked, or Unknown. Blocked/unknown items include remediation copy, and Queue dry run is only available when dry-run gates pass.
result: [pending]

### 4. Analyze tab is AI-explicit and queued
expected: The Analyze tab has entity, locale, and since filters, an AI confirmation checkbox, the required Anthropic missing-key disabled copy when no key is configured, an equivalent CLI command, and Queue analyze only becomes usable after the safety/API/AI confirmation gates pass. Submitting queues a job and does not run analyze inline.
result: [pending]

### 5. Mapping tab supports canonical review
expected: The Mapping tab exposes URL-preserved filters for entity/page, status, kind, finding severity, and search; rows show source, target, handler, status, finding, and rationale before edit controls; batch actions support accept, needs-review, drop, and warning acceptance with typed confirmations for high-risk actions while updating the canonical mapping.yaml only.
result: [pending]

### 6. Compile and Reports tabs expose artifacts
expected: The Compile tab shows latest compile timestamp/status, fatal/warning counts, artifact/log paths, readiness gates, equivalent CLI command, and a Queue compile form. The Reports tab lists REPORT.md, VERIFY artifacts, PAGE-ROOTED-COVERAGE.md, MAPPING-AUDIT.md, schema/graph artifacts, and provides a queued verify/report action without inventing a separate reporting model.
result: [pending]

### 7. Runs tab and run detail show durable records
expected: The Runs tab lists durable migration runs with run ID, stage, mode, status, filters, initiating admin, queue job IDs, progress, timestamps, artifacts, and actions. View details opens the selected run, including gate snapshot, filters/options, queue job IDs, log/artifact paths, failure details, readable summary data, and collapsed raw JSON where applicable.
result: [pending]

### 8. CP queue actions create jobs, not inline workflows
expected: Queue analyze, compile, verify/report, dry-run, and live actions are CP POST/admin-only, create a run record, push a Craft queue job, store the queue job ID, redirect back to the console Runs/detail context, and do not execute long migration workflows inline during the web request.
result: [pending]

### 9. Live migration is strictly gated
expected: The live migration panel is visually separated from dry run and blocks live queueing unless non-production, admin, elevated session, MIGRATE LIVE typed phrase, successful same-options dry run, recent no-fatal compile, queue worker readiness, backup acknowledgement, warning/unsupported acceptance, CP live opt-in, and job production hard-block gates all pass. Unverifiable queue readiness blocks CP live and points to CLI remediation.
result: [pending]

### 10. Danger Zone remains deferred
expected: The Danger Zone tab shows reset/truncate and artifact cleanup panels with the required RESET MIGRATION STATE and DELETE ARTIFACTS copy, but those actions are disabled/deferred in this phase and there are no active destructive reset or cleanup submit forms.
result: [pending]

## Summary

total: 10
passed: 0
issues: 2
pending: 8
skipped: 0
blocked: 0

## Gaps

- truth: "Settings page should expose section navigation in a sidebar like Formie settings."
  status: failed
  reason: "User reported: Yes, it works. However, I think ideally we should have a sidebar with the different sections like how https://cqm-craft-website.test/craft-cms/formie/settings?site=default does it."
  severity: minor
  test: 1
  root_cause: ""
  artifacts: []
  missing: []
  debug_session: ""
- truth: "The Kunstmaan Migration Console should open even before any migration run records exist."
  status: failed
  reason: "User reported: SQLSTATE[42S02]: Base table or view not found: 1146 Table 'craft_starter_kit.kunstmaanmigrator_runs' doesn't exist; after fixing the run-table read path, Twig reported: The \"defined\" test only works with simple variables in \"kunstmaan-migrator/_console/_readiness\" at line 40."
  severity: blocker
  test: 2
  root_cause: "MigrationRunService::latest() and list() queried {{%kunstmaanmigrator_runs}} unconditionally from the Utility view model before the plugin migration had created the run-record table. The console templates also used Twig null-coalescing on filtered/parenthesized expressions, which compiles through Twig's defined test and is invalid for complex expressions."
  artifacts:
    - path: "src/runs/MigrationRunService.php"
      issue: "Read-side run queries do not guard a missing run-record table."
    - path: "src/controllers/MigrationConsoleController.php"
      issue: "Utility view model calls latestRun()/runs() during console render."
    - path: "templates/_console/_readiness.twig"
      issue: "Dry-run gate lookup used null coalescing on a filtered expression."
    - path: "templates/_console/_runs.twig"
      issue: "Selected run lookup used null coalescing on a filtered expression."
  missing:
    - "Guard run-record read methods so missing tables return empty run state instead of crashing the CP."
    - "Keep run-record mutations loud with a clear run-migrations error before queueing actions."
    - "Use explicit temporary variables plus default(null) for filtered Twig lookups."
  debug_session: ""
