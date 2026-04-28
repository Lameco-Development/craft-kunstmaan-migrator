# Phase 10 Closing Proof — Restored CQM Rehearsal

Generated: 2026-04-28T17:08:00Z

## Restored-backup workflow

Backup restored:

`~/Sites/cqm-craft-website/storage/backups/craft-starter-kit--2026-04-28-131310--v5.9.20.sql`

Commands executed by the agent:

1. `composer test`
2. `php craft db/restore storage/backups/craft-starter-kit--2026-04-28-131310--v5.9.20.sql --interactive=0`
3. `php craft kunstmaan-migrator/doctor`
4. `php craft kunstmaan-migrator/analyze`
5. `php craft kunstmaan-migrator/map --auto-accept-high=1`
6. `php craft kunstmaan-migrator/compile --overwrite`
7. `php craft kunstmaan-migrator/migrate`
8. `php craft kunstmaan-migrator/migrate --live --confirm=1`
9. `php craft kunstmaan-migrator/verify`

Notes:

- `kunstmaan-migrator/map` without a non-interactive option prompts for manual mapping decisions; the agent used `--auto-accept-high=1` to keep the closing proof automated.
- `kunstmaan-migrator/compile` requires `--overwrite` when compiled `nodeClasses` / `sections` / `sites` blocks already exist.
- The CLI dry-run mode is the default `kunstmaan-migrator/migrate` invocation; `--dry-run` is not a supported option in the current command surface.

## Repository test result

`composer test` completed with exit 0:

- 485 tests
- 1623 assertions
- 1 skipped
- 1 incomplete
- Existing environment warnings: no code coverage driver, existing deprecation notices.

## CQM rehearsal result

Latest verify report:

`~/Sites/cqm-craft-website/storage/migration/VERIFY-2026-04-28--17-00-48.md`

Latest migration report:

`~/Sites/cqm-craft-website/storage/migration/REPORT.md`

Closing evidence:

- `REPORT.md` line 12: `| failed | 0 |`
- `REPORT.md` line 22: `- Total failed: 0`
- `REPORT.md` line 1416: `_No per-entry failures._`
- Verify report labels:
  - `### Craft baseline/current drift (informational)`
  - `### Migration-created state counts (informational)`
  - `### Source/transformed parity (blocking)`
- Source parity is skipped because no source-derived expected count artifact exists in the baseline/source artifacts:
  - `source-parity:unavailable`
- Optional adapter count rows are explicit SKIP rows when not present in the baseline count artifact:
  - `plugins:seomatic`
  - `plugins:retour`

## Page-rooted coverage inspection

Artifact:

`~/Sites/cqm-craft-website/storage/migration/PAGE-ROOTED-COVERAGE.md`

Observed classification rows:

- `out_of_scope`: 56 occurrences
- `dropped`: 1196 occurrences
- `unsupported`: 84 occurrences
- `warning`: 439 occurrences

The restored full workflow reached zero entry failures and zero stage failures. Classified dropped/out-of-scope rows remained report/coverage evidence and did not increment failure counts.

Outstanding release-review note: `PAGE-ROOTED-COVERAGE.md` still contains `warning` / `unsupported` classifications. They are visible rather than silent omissions and require release-owner acceptance before tagging if the strictest Phase 10 gate is interpreted as zero warning/unsupported rows.

## Deviations captured during proof

1. The existing CQM mapping contained/generated invalid high-confidence target proposals for unavailable Craft entry types. Compile now avoids heuristic backfill for entry types not present in the target schema, and invalid CQM target proposals were classified in the external rehearsal mapping as dropped/out_of_scope.
2. `AtomicMigrationService` did not capture `MigrationReport` inside its transaction closure after Plan 10-02 fallback reporting. The live run surfaced this as `Undefined variable $report`; the closure capture was fixed and verified.

## Required domains checked

- Entries: checked through `REPORT.md` failed count and Page-rooted coverage.
- Page parts: checked through `PAGE-ROOTED-COVERAGE.md`; warnings remain visible.
- Relations: checked through Page-rooted coverage and lazy taxonomy resolver run.
- Assets: checked through report fallback/RCA surfaces and migration-created state counts.
- Taxonomies: checked through referenced-only `taxonomyMode` and lazy resolver evidence.
- SEO: adapter stage exercised; no skipped stages in `REPORT.md`.
- Redirects: adapter stage exercised; no skipped stages in `REPORT.md`.
- CKEditor references: finalize counters reported `finalize.unresolvable | 0`.

