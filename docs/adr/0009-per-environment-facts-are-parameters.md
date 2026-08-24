# 0009 — Per-environment facts are parameters, not properties

Status: Accepted · 2026-08-22, completed in PR #34 · Source: `ARCHITECTURE-REVIEW.md` §6; `PLUGIN-REVIEW.md` §2.3

## Context

The plugin's services are singletons. Anything per-environment written
onto one — the site map, the environment name, the media roots — is
inherited by whatever runs next, or by whatever runs outside
`EnvironmentPipeline::run()`. That is how a rewriter cache outlived its
database, and how a finalize pass ran with the previous environment's
media roots.

## Decision

A fact about *this* environment travels as an argument. `SiteMap` made
the trip first; `EnvironmentContext` (environment name, database, media
roots, site map) carries the rest. No per-environment value is assigned
to a service property.

## Consequences

- Both failures above are unexpressible: a caller outside the pipeline has
  no context to inherit.
- The remaining property injection in `Plugin::init()` (`wireServices()`,
  the `?object` duck-typed `assetResolver`, six `??= new
  CraftElementWriter()` fallbacks) is the open half of this record:
  constructor injection would retire it. It is hygiene that touches every
  service and has no defect behind it yet.
