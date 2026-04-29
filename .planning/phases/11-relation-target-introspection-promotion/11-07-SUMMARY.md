# Plan 11-07 Summary: Verification/reporting and scoped rehearsal

## Outcome

Page-rooted coverage now consumes graph-backed relation evidence, distinguishes unresolved relation gaps from accepted relation intent decisions, and the CQM runbook documents the scoped NewsPage/HomePage proof.

## Implemented

- Extended `PageRootedSurfaceDiscovery` to accept the Kunstmaan graph and emit relation evidence rows from graph relations when available.
- Kept absence of graph relation evidence non-synthetic: no `relation:not-discovered` blocker rows are created when graph input is missing.
- Extended `PageRootedCoverageAuditor` to classify relation evidence by accepted relation intent:
  - missing intent => `relation.unresolved` warning/blocker evidence;
  - `drop` => intentional dropped relation;
  - `out_of_scope` => intentional out-of-scope relation;
  - `promote`, `reference`, `embed` => migrated/covered relation evidence.
- Added `MigrationReport::recordRelationCoverage()` and relation counters:
  - `relation.unresolved`
  - `relation.intent.drop`
  - `relation.intent.out_of_scope`
  - `relation.promoted`
- Added relation buckets to the generated migrate report count table.
- Updated `.planning/rehearsal/v1.0/cqm/README.md` with scoped proof commands for `NewsPage,HomePage` and expected artifact evidence.

## Verification

- `php -l src/audit/PageRootedSurfaceDiscovery.php && php -l src/audit/PageRootedCoverageAuditor.php && php -l src/load/MigrationReport.php && php -l src/console/MigrateController.php`
- `vendor/bin/phpunit tests/unit/audit/PageRootedCoverageAuditorGraphRelationsTest.php tests/unit/audit/PageRootedSurfaceDiscoveryGraphRelationsTest.php --testdox`
- Plan 11-07 acceptance greps for relation counters, `out_of_scope`, no synthetic relation blockers, no production proof-target hardcoding, and scoped runbook evidence.
- `composer test`

## Notes

- The runbook remains scoped to CQM `NewsPage` and `HomePage`; it does not add mandatory commands for other projects.
- Intentional relation drops and out-of-scope decisions are reportable but non-blocking; unresolved relation evidence remains a release blocker until mapped or intentionally classified.
