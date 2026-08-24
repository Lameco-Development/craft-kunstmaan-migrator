# 0003 — Compilation is Craft-runtime-free, enforced by package list

Status: Accepted · 2026-08-21 (merge of the compile repo), generalised 2026-08-24 (PR #68) · Source: `docs/target-structure.md` "The invariant worth keeping"; `phpstan/LibPurityRule`

## Context

The compile half — reading the legacy database and the mapping, emitting
payloads — was a separate repository so that it could test and run without
a booted Craft. Every leak so far (a `Craft::warning` in a pure builder)
was found by a test dying on "Class Craft not found", after the fact.

The compile half is *Craft-schema-aware*: it parses the site's
version-controlled `config/project/**` to model targets. That is a
different thing from touching the Craft runtime.

## Decision

The kernel packages — `Payload`, `Source`, `Mapping`, `Target`, `Compile`,
`Report`, `Command` — and their tests in `tests/kernel` may never name
`Craft`, `craft\*`, `yii\*`, or any Craft-side package of the plugin.
`LibPurityRule` turns that into a PHPStan failure, keyed on the namespace a
file declares (compared case-insensitively, as PHP does), not on a
directory.

A fact the kernel needs from Craft is passed in through a port the kernel
defines (`Target\TargetSchema`, `Payload\SchemaGateway`) and the Craft side
implements.

## Consequences

- The kernel's ~270 tests run in well under a second with no Craft
  bootstrap; the standalone `bin/kuma-compile` runs from a machine with no
  Craft install.
- Moving a class into a kernel package is a claim that it is pure; the
  analyser checks the claim.
- A review proposing to let the kernel read `Craft::$app` "just for this
  one lookup" is proposing to reverse this. The answer is a port.
