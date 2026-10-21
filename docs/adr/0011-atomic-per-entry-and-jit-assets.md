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
- A deadlock is retried at the payload, never inside the entry's
  transaction: InnoDB rolls the whole transaction back on a 1213, so a
  retry of the one statement that raised it commits a partial entry.
  `run\WriteConflictRetry` re-runs the payload save, which the state table
  and the `sourceUid` make safe to run twice; the writer adapter retries
  nothing.
- "When an entry references them" includes its SEO. The SEO pass runs after
  the entries and resolves `kuma_seo.og_image_id` / `twitter_image_id`
  through the same bound `AssetResolver` as the rest: the id key, then the
  file's `legacy_url:` key, then a just-in-time ingest. A file that only
  `kuma_seo` names is referenced by nothing else, so a state lookup alone
  never found it. A soft-deleted media row is not ingested there: whether
  deleted media migrate is the project's decision, so it resolves only to an
  asset that already exists (the project ingested it on purpose) and is
  otherwise an accepted loss, logged at info level.
- A review proposing a preload phase is proposing the two-phase run; that
  is the cost to weigh, not the flag.
