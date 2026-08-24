# 0004 — One namespace, one root; the kernel is CamelCase, the Craft side lowercase

Status: Accepted · 2026-08-24 (PR #68) · Source: `docs/target-structure.md` step 6 and "Explicitly rejected"

## Context

After the 2026-08-21 merge the plugin had two PSR-4 roots
(`Lameco\Kunstmaanmigrator\` → `src/`, `Lameco\KumaCompile\` →
`lib/kuma-compile/`). The boundary that mattered was the purity invariant
([0003](0003-compilation-is-craft-runtime-free.md)), not the two
directories, and the second root made every gate carry two path lists.

Three options were on the table: re-extract the compile half as its own
Composer package; keep two roots; fold into one.

## Decision

One vendor namespace, one root, `Lameco\Kunstmaanmigrator\` → `src/`.
The kernel packages keep CamelCase names (`Payload`, `Source`, `Mapping`,
`Target`, `Compile`, `Report`, `Command`); the Craft side keeps the
lowercase package names Craft's own conventions use (`craft`, `load`,
`adapters`, `finalize`, `run`, `editor`, `safety`, and Craft's `console`,
`controllers`, `queue`, `models`, `migrations`, `utilities`, `web`). The
casing is how a reader tells the two halves apart at a glance.

Two things were rejected and stay rejected:

- **Re-extracting the kernel as a separate Composer package.** It would
  reimpose the two-repo drift the merge escaped, now with ~30 public
  classes needing semver. Revisit only if the CLI gains a non-Craft
  consumer.
- **Folding the Craft side into a `Craft\`/`Load\`/`Operator\` trio.**
  Craft fixes `console\`, `controllers\`, `queue\`, `migrations\` by
  convention, and `adapters\`, `finalize\`, `run\`, `editor\`, `db\` are
  real packages with real reasons. The fold is not mechanical and no defect
  is behind it.

## Consequences

- PHP compares namespaces case-insensitively, so a kernel package and a
  Craft-side package may not share a name. That is why `src/payload`,
  `src/compile` and `src/mapping` moved to `load\`, `craft\`, `editor\` and
  the kernel `Mapping\` before the fold.
- Craft's plugin installer needs the plugin namespace mapped to a single
  directory; that is a second reason the physical tree is one root.
- `Lameco\KumaCompile\*` no longer exists. The next release is a major.
