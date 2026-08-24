# Architecture decision records

Decisions that are settled. Each one records the constraint that forced it,
so a future review can tell a decision from a habit — and can reopen one
when the constraint has actually changed, rather than re-proposing the
alternative because it looks cleaner in isolation.

One decision per file. Status is `Accepted` unless noted. A superseded
record stays in place with a pointer to what replaced it.

| | Decision |
|---|---|
| [0001](0001-the-mapping-owns-the-topology.md) | The mapping owns the migration topology; no control-panel form does |
| [0002](0002-nothing-secret-reaches-project-config.md) | Nothing secret reaches project config |
| [0003](0003-compilation-is-craft-runtime-free.md) | Compilation is Craft-runtime-free, enforced by package list |
| [0004](0004-one-namespace-one-root.md) | One namespace, one root; the kernel is CamelCase, the Craft side lowercase |
| [0005](0005-craft-coupling-goes-behind-a-seam-with-two-adapters.md) | Craft coupling goes behind a seam with two adapters |
| [0006](0006-deep-modules-stay-deep.md) | Deep modules stay deep; `EntryMigrationService` is not split |
| [0007](0007-one-operator-vocabulary.md) | One operator vocabulary: one engine per verb, thin adapters, the binary stays |
| [0008](0008-one-pipeline-two-callers.md) | One pipeline, two callers; adapters come from the registry; the environment job is batched |
| [0009](0009-per-environment-facts-are-parameters.md) | Per-environment facts are parameters, not properties |
| [0010](0010-deterministic-at-run-time.md) | Deterministic at run time — no AI in any stage |
| [0011](0011-atomic-per-entry-and-jit-assets.md) | Atomic per entry, always; assets materialise on demand |
| [0012](0012-production-interdiction-everywhere.md) | Production interdiction on every legacy-reading and destructive path |

Adding one: copy the shape of an existing record, number it next, add a
row here. Write it when a review rejects a candidate for a reason a future
reviewer would need — not for "not now".
