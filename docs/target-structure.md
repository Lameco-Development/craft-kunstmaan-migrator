# Target structure

Where the codebase is heading, and the order of the moves. Written 2026-08-24,
after the two-root structure review; the review's findings are summarised
inline so this document stands alone.

## The invariant worth keeping

The valuable thing the `src/` / `lib/kuma-compile/` split protects is not "two
directories" — it is:

> **Compilation is deterministic, Craft-schema-aware, and Craft-runtime-free.**

`lib/` reads the legacy database, the mapping, and the Craft site's
version-controlled project config, and emits payloads without ever touching a
`craft\`/`yii\` symbol. `phpstan/LibPurityRule` turns that from a convention
into a build failure. Everything below preserves this invariant; nothing below
depends on the two-root layout to express it.

## Target layout

One vendor namespace, packaged by contract, with an enforced dependency
direction:

```
Lameco\KunstmaanMigrator\
  Payload\    the shared kernel: Payload VO, SourceUid, PayloadValidator.
              Depends on nothing.
  Source\     legacy Kunstmaan access: one reader, one table-constants class,
              one DSN ladder, checkout introspection. Depends on nothing.
  Mapping\    the program: schema, document, skeleton, editor model.
  Target\     Craft's content model read from project config + the
              TargetSchema port (implemented Craft-side).
  Compile\    Source + Mapping + Target → Payload. Craft-runtime-free.
  Craft\      the gateways (ElementWriter, Navigation, Formie, Embeds) —
              every Craft coupling behind a seam with an in-memory twin.
  Load\       Payload → Craft, through Craft\. State table, adapters.
  Operator\   console, CP, queue. Thin adapters over services that live in
              their home packages; every operator verb exists exactly once.
  Safety\     production interdiction.
```

The purity rule generalises with it: `Payload`, `Source`, `Mapping`, `Target`,
`Compile` may never reference `craft\`, `yii\`, `Craft\`, `Load\`, or
`Operator\` — checked by package list rather than file path, tests included.

## Why (the review findings this answers)

- The seam between the halves is **five contracts wide** (payload array,
  mapping grammar, `TargetSchema` port, live `Dsn`/`LegacyDatabase` objects,
  report objects) while the docs describe one. Packaging by contract makes
  each crossing a named package edge instead of an undocumented import.
- The **sourceUid grammar had no owner** — minted by five sprintfs in the
  compile half, parsed by regexes and re-concatenated in four write-half
  sites. Fixed: `SourceUid` (the seed of `Payload\`).
- **Two legacy-DB access layers** open two connections to the same database
  in the same run, with two sets of table-name knowledge (`Legacy\*` readers
  vs `LegacyDbService` + `KunstmaanCoreTables`), plus a third, half-dead
  checkout-inspection path (`KunstmaanEnvReader`). One `Source\` package ends
  this.
- **Duplicate operator vocabulary**: `doctor`, `init`, `check`, `coverage`
  each exist in the standalone CLI and the Craft console with different
  contracts. `EnvironmentPipeline` and `Diagnostics` already prove the fix —
  one service, thin adapters; `Operator\` makes it the rule.

## The path from here

Each step lands independently, ships green through the existing gates, and is
worth having even if the later steps never happen.

1. **Housekeeping** — DONE (PR #58). One vendor casing, `safety/`, purity rule
   over lib tests, lib files on the coverage watch list, dist hygiene, honest
   boundary wording.
2. **`SourceUid`** — DONE (this change). One owner for the idempotency key,
   in `Lameco\KumaCompile\Payload\` — the first inhabitant of the future
   kernel package. All mint/parse sites delegate.
3. **One legacy-DB layer** — DONE (PR #61). Move `KunstmaanCoreTables` into
   `lib/.../Legacy/` and make the lib readers use it; give the six write-half
   sidecar services their legacy reads through the lib reader (a thin
   Craft-side provider hands it the PDO) instead of a second Yii connection;
   delete `KunstmaanEnvReader`'s dead runtime path in favour of
   `CheckoutScanner`. Precondition: the WATCHED coverage tests for those
   services (they sit at 10–26%).
   Landed with one deviation: the lib readers keep their heredoc literals;
   `KunstmaanCoreTables` (now in lib/Legacy) is the greppable index. The
   env-reader dead path was revived via `Settings::$legacySourcePath`
   rather than deleted.
4. **Grow the kernel** — DONE (this change). Move `Payload`, `PayloadValidator` next to
   `SourceUid` (they are already Craft-free — only their namespace says
   otherwise); `docs/loader-contract.md` then documents the package it sits
   beside.
5. **One operator vocabulary** — doctor DONE (PR #63): `run\Diagnostics` is
   the superset doctor (it now includes the CLI doctor's mapping-state
   answers — conflicts, unreviewed, todos — via `mappingStateChecks()`), and
   the two commands cross-reference each other. init DONE (this change):
   `Mapping\MappingInit` is the one skeleton engine — pair grammar, entity
   ladder, overwrite refusal — and both commands are thin adapters over it;
   the Craft command gained the `--introspection` support only the CLI had.
   check DONE (this change): `Mapping\MappingCheck` is the one verdict —
   shape, install, blocks, spec divergence, conflicts, in that order, plus
   one warnings list — and `kuma-compile validate` renders it like the
   Craft check, the migrate preflight and the CP button already did; the
   Craft check gained the `--specs`/`--introspection` lanes, the CLI
   validate gained the conflict gate. Remaining verb: `coverage` — same
   treatment. The standalone binary stays — as an adapter, not an
   implementation.
6. **Namespace consolidation.** Mechanical, last, optional: fold
   `Lameco\KumaCompile\` into `Lameco\KunstmaanMigrator\{Payload,Source,
   Mapping,Target,Compile}` and generalise the purity rule to package lists.
   Only worth doing once 3–5 have made the packages real; renaming first
   would relabel the current tangle.

Steps 3–5 are refactors with behavioural risk and want the usual
review-then-execute treatment. Step 6 is a rename.

## Explicitly rejected

- **Re-extracting `lib/` as a separate Composer package** — reimposes the
  two-repo drift the 2026-08-21 merge escaped, now with ~27 public classes
  needing semver. Revisit only if the CLI gains a non-Craft consumer.
- **A CP form owning migration topology** — the mapping owns it
  (`CLAUDE.md`, ground rules).
