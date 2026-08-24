# 0012 — Production interdiction on every legacy-reading and destructive path

Status: Accepted · 2026-08 · Source: `CLAUDE.md`; `safety\NeverProductionTrait`, `safety\ProductionGuard`

## Context

The plugin reads a live legacy site's database and writes and deletes
Craft elements in bulk. It is a dev/staging tool. A single forgotten
guard on one command is enough for a run against production.

## Decision

`NeverProductionTrait` (console) and `ProductionGuard` (queue jobs,
control-panel actions) hard-block `CRAFT_ENVIRONMENT=production` on every
command, job and CP action that reads the legacy database or mutates
Craft. A new command, job or action that does either carries the guard;
`MappingControllerTest` and its siblings assert the trait is present.

## Consequences

- There is no override flag. Running on production means changing the
  environment, which is a deployment decision, not a CLI one.
- `mapping/init`, `mapping/check`, `mapping/coverage` are guarded even
  though they only *read*: reading the legacy database from a production
  Craft is still the wrong place to be.
