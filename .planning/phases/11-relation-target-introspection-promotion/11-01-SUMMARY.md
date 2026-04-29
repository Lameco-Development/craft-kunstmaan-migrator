---
phase: 11-relation-target-introspection-promotion
plan: 01
subsystem: source-graph-contracts
tags: [graphs, contracts, fixtures]
key-files:
  - src/source/KunstmaanGraphContract.php
  - src/source/CraftGraphContract.php
  - tests/support/GraphFixtureFactory.php
  - tests/unit/source/GraphContractTest.php
metrics:
  tests: 5
  assertions: 47
---

# Plan 11-01 Summary

## Commits

| Commit | Description |
|---|---|
| 52a9b72 | Define graph contracts/versioning and shared fixtures. |

## Work Completed

- Added versioned normalized Kunstmaan and Craft graph contracts.
- Centralized Kunstmaan relation intent constants: `reference`, `promote`, `embed`, `drop`, `out_of_scope`.
- Added stable graph reference helpers for Kunstmaan page roots/entities/pageparts and Craft entry types/fields/Matrix blocks.
- Added shared graph fixtures for News/Employee relations, Home/pageparts, and Craft News/Home target structures.
- Added focused PHPUnit coverage for contract constants, ref helpers, normalized fixture shape, inbound owner evidence, pagepart usages, Matrix usages, relation targets, and asset volumes.

## Deviations

None.

## Verification

- `vendor/bin/phpunit tests/unit/source/GraphContractTest.php --testdox`
- Plan acceptance greps for graph versions, intent constants, proof-name absence in production contracts, fixture methods, inbound owner evidence, and usage registries.

## Self-Check: PASSED

Plan 11-01 success criteria are met.
