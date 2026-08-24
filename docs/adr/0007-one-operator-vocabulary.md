# 0007 — One operator vocabulary: one engine per verb, thin adapters, the binary stays

Status: Accepted · 2026-08-24 (PRs #63, #65, #66, #67) · Source: `docs/target-structure.md` step 5

## Context

`doctor`, `init`, `check`/`validate` and `coverage` each existed twice —
in the standalone `kuma-compile` binary and in the Craft console — with
different contracts, and the copies drifted (the CLI's `init` had
introspection support the Craft command lacked; the CLI's `validate` had
no conflict gate; there was no Craft `coverage` at all, and the migrate
preflight told the operator to go find the vendored binary).

Two things were wanted at once: a plugin you can hand to somebody without
them locating a CLI inside `vendor/`, and a compile half that still runs
from a machine with no Craft install.

## Decision

Every operator verb has exactly one implementation: `Mapping\MappingInit`
(init), `Mapping\MappingCheck` (check/validate), `Report\Coverage` +
`Report\CoverageReport` (coverage) in the kernel, and for doctor the
Craft-side `run\Diagnostics`, which is the superset — its pure static
`mappingStateChecks()` is the mapping-state answer both doctors give, and
the install checks are the part only a Craft install can answer. The Craft
console command and the `Command\` class in the binary are thin adapters:
option syntax and the DSN source (plugin settings vs environment) are the
only things allowed to differ. The docblocks cross-reference.

The standalone binary stays — as an adapter, not an implementation.

## Consequences

- Adding a verb means adding an engine and two adapters; adding a lane to
  a verb means adding it to the engine so both surfaces get it.
- The Craft command is the superset where the Craft install can answer
  more (the doctor's install checks); the binary is the one to use while
  authoring a mapping before a Craft project exists.
