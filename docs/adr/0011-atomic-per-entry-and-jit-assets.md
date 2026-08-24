# 0011 — Atomic per entry, always; assets materialise on demand

Status: Accepted · 2026-08 · Source: `CLAUDE.md` ground rules

## Context

Two knobs were considered and rejected as flags.

An `--atomic` switch: a run that is sometimes atomic per entry and
sometimes not has two failure shapes to reason about, and the non-atomic
one leaves half-written entries the state table cannot describe.

An asset preload: materialising every referenced asset before the entry
pass would need a compile-then-load two-phase run that does not exist.

## Decision

- Every entry write is atomic, per entry. There is no flag.
- Assets materialise just in time, when an entry references them.
  `AssetMigrationService::ingestReferenced()` is an API for a caller that
  already holds an id set — not a console flag.

## Consequences

- The idempotent state table describes whole entries only.
- A review proposing a preload phase is proposing the two-phase run; that
  is the cost to weigh, not the flag.
