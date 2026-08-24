# 0008 — One pipeline, two callers; adapters come from the registry; the environment job is batched

Status: Accepted · 2026-08 · Source: `CLAUDE.md` ground rules; `PLUGIN-REVIEW.md` §2.2, §4.3

## Context

The compile and load halves drifted apart once when a file on disk was
the contract between them and each caller assembled a run its own way. A
queued "full migration" was not full, because the queue path and the
console path ran different adapter lists in a different order.

## Decision

- **`EnvironmentPipeline` is what the console command and the queue job
  both run.** Neither assembles a run differently.
- **A pass that runs after an environment's entries is a
  `MigrationAdapter`** with an `Adapter` row and a factory in
  `AdapterRegistry`; `runAdapters()` is a loop over the registry. There is
  no fifth hard-coded call site. `redirects` is the one documented
  exception and says why in place.
- **`MigrateEnvironmentJob` is batched** (`craft\queue\BaseBatchedJob`),
  driven by `CompilerRun` and the unit primitives on `Compiler`, with a
  fresh `CompilerRun` per batch and `catchUpStructural()` on resume. Jobs
  that spawn continuations use `QueueHelper::push()` so priority and TTR
  propagate; nothing calls `getQueue()->push()` directly on these jobs.

## Consequences

- Optional adapters (SEOmatic, Retour, Navigation, Formie, embedded
  assets) are detected at run time via `Craft::$app->plugins->getPlugin()`
  and stay out of composer `require`; an absent plugin is reported, not a
  failed install.
- A review proposing "just call the adapter directly here, it's one line"
  is proposing the fifth call site.
