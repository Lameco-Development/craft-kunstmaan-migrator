# 0006 — Deep modules stay deep; `EntryMigrationService` is not split

Status: Accepted · 2026-08-22 · Source: `ARCHITECTURE-REVIEW.md` §2 and "The ranking now"; `PLUGIN-REVIEW.md` "Second pass"

## Context

`EntryMigrationService` is the largest module in the write half. Two
reviews considered splitting it and both concluded against: its interface
is small relative to what it hides (site propagation, nested-entry
reconciliation, state), and the bugs found so far were at the places where
a seam stopped one caller short — not inside the module.

The review's own words: "Still nothing here argues for splitting the deep
modules. `EntryMigrationService` earns its size."

## Decision

Depth is preserved. Improvements to `EntryMigrationService` and its
neighbours are made by moving a seam to the right place (an element-read
seam, one owner for nested-entry reconciliation) or by turning a property
into a parameter — not by extracting classes to make the file shorter.

## Consequences

- A candidate that reads "split X into three services" needs new evidence:
  a caller that has to bounce between modules to understand one concept, or
  a test that can only reach behaviour by reflection. The latter *is*
  currently true of nested-entry reconciliation
  (`EntryBlockReconciliationTest` names a private method seven times) — the
  remedy the review records is one owner for that rule, which may well be a
  module of its own. That is a deepening, not a split.
- Done for nested-entry reconciliation: `load\BlockIdentity` owns which
  nested entries survive a save, on which sites, and which ids carry to the
  next run. `saveEntryForSites()` kept its role and signature and calls it
  at the same points it used to call eight private methods; the tests moved
  to the module's interface and the reflection went with them.
