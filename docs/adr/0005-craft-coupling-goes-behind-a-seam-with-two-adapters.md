# 0005 — Craft coupling goes behind a seam with two adapters

Status: Accepted · 2026-08 · Source: `CLAUDE.md` "Test discipline"; `tests/unit/craft/WriteBoundaryTest.php`; `ARCHITECTURE-REVIEW.md` §7

## Context

The test suite cannot boot a Craft application. Anything that reaches
`Craft::$app` or a third-party plugin's static facade is only testable
through a seam. The v1.x plugin shipped with no tests; the write half of
v2 was at 10–26% coverage until its writes went behind `ElementWriter`.

A seam with one adapter is a hypothesis: nothing proves the interface is
the one the production code needs, and nothing stops the next caller
going around it.

## Decision

Every Craft coupling sits behind a seam the plugin defines, with a
production adapter *and* an in-memory one. Four exist — `ElementWriter`
(Craft's element writes), `NavigationGateway` (verbb/navigation),
`FormGateway` (verbb/formie), `EmbedGateway` (spicyweb/craft-embedded-assets)
— and `WriteBoundaryTest` holds each in place: only the named adapter may
cross its boundary, and each seam must have its test twin.

New Craft coupling is added as a seam plus a second adapter, not as
another call site.

## Consequences

- The `ElementWriter` seam is spent for coverage purposes: the `Log` seam
  is what made its failure branches reachable, and the *reads*
  (`Entry::find()` across `src/load/`) are the next binding constraint.
  An element-read seam is the successor, not an exception to this record.
- `tools/check-coverage.php`'s `WATCHED` list is the graduation path for
  the modules a new seam makes testable.
