# 0001 — The mapping owns the migration topology

Status: Accepted · 2026-08 · Source: `CLAUDE.md` ground rules; `docs/target-structure.md` "Explicitly rejected"

## Context

A migration's topology — which legacy databases exist, where each one's
uploads live, which legacy locale writes to which Craft site, which locale
is deliberately not migrated and why — has to be stated somewhere. The two
candidates were a control-panel form and the version-controlled mapping
file that already carries every field decision.

A real corpus needs things a form expresses badly: an ordered media-root
fallback chain, and a locale marked "not migrated, and here is why". A form
that holds them holds them once per install and outside review.

## Decision

The mapping YAML owns the topology, next to the field mappings it travels
with. The control panel edits the mapping; it does not hold topology of its
own. The `Settings` screen states what the config file decided and offers
only the adapter on/off switches and each adapter's own settings.

## Consequences

- Topology is reviewed in a PR like every other migration decision, and is
  identical on every machine that checks out the repo.
- `environments:` grew the `mediaRoot` chain and the `!unmapped` locale
  marker instead of the CP growing fields.
- A review proposing "a settings screen for the databases" is proposing to
  reverse this; the constraint (reviewability, per-machine drift) has not
  changed.
