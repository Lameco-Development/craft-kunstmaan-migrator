# Kunstmaan Migrator

A Craft CMS 5 plugin that writes a legacy Kunstmaan (Symfony) site into Craft.
Kunstmaan Migrator is deliberately thin: it validates a payload against the live
Craft schema, saves it (upsert, idempotent), resolves cross-entry `_ref`s in a
second pass, imports redirects, and reports state — nothing more.

All migration intelligence lives in [`lameco/kuma-compile`](https://github.com/lameco/kuma-compile),
which this plugin requires: reading the legacy database, the mapping file and its
shape rules, and compiling both into payloads. Nothing is decided at run time. The
plugin contributes one thing kuma-compile cannot have — a `TargetSchema` that
answers from the live Craft site rather than from a parse of `config/project/**`.

See [`docs/loader-contract.md`](docs/loader-contract.md) for the payload schema.

## Requirements

- PHP 8.3+
- Craft CMS 5 (`^5.0`)

## Installation

```bash
composer require lameco/craft-kunstmaan-migrator
./craft plugin/install kunstmaan-migrator
```

## One command

```bash
./craft kunstmaan-migrator/migrate --mapping=migration/mapping/site.yaml --force
```

Reads the legacy Kunstmaan database, compiles it against the mapping, and writes it into
Craft — in one process. Validates the mapping's shape, then every handle it names against the
*live* Craft schema, then refuses to run while any `conflict:` is still open.

Per environment, in order: taxonomy entries, page entries with their blocks and assets, then
SEO meta, redirects, navigation and translations. The four adapters run after that
environment's entries because each of them resolves a legacy id to an entry that has to exist
already; `--entriesOnly` skips them while you iterate on the entry pass.

`--dump=<dir>` writes the compiled payloads out for inspection; `--dryRun` compiles and
reports without writing; `--legacyEnv` and `--limit` narrow the run.

Compiling and loading were separate tools exchanging NDJSON. The file was a contract, and
contracts drift: the compiler emitted the documented `{type, fields}` block shape while the
loader needed a `sourceRef` marker the contract never mentioned, so Matrix rows updated
partially and neither side could see why. Payloads are still available as an artifact — they
are just no longer the seam.

## Commands

| Command | Description |
| --- | --- |
| `kunstmaan-migrator/load/entry --payload=<file> [--dry-run]` | Validates a payload (JSON/NDJSON) against the live Craft schema and, unless `--dry-run` is passed, saves it — idempotent upsert by `sourceUid`, alias recording, deferred `_ref`s parked for the fixup pass. |
| `kunstmaan-migrator/load/fixup` | Second pass: drains every state row's pending `_ref`s left behind by `load/entry` and patches them in now that the referenced entries exist. Run once every payload in a batch has gone through `load/entry`. |
| `kunstmaan-migrator/load/redirects --payload=<file>` | Loads a redirects payload (NDJSON), resolving `kuma:<ENV>:<table>:<id>` targets to their migrated entry's URI and writing them via Retour when installed. `migrate` compiles the same records from the mapping's `redirects:` lane and loads them directly, so this is for a payload produced by other means. |
| `kunstmaan-migrator/state/export` | Streams the migrator's state table as NDJSON (`sourceUid` / `entryId` / `targetType` / `alias_of` per line) for resume/verify tooling. |
| `kunstmaan-migrator/doctor` | Preflight checks: plugin installed + state table reachable, `storage/migration/` writable, not running in production, Retour presence. |

Every command prints machine-readable JSON (or NDJSON) to stdout and exits
non-zero on failure — no ANSI prose.

## Production safety

The plugin **refuses to run** any legacy-reading or destructive command when
`CRAFT_ENVIRONMENT=production`. It is a dev / staging tool only.

## Development

```bash
composer install
composer test
```

CI runs `composer validate --strict` + `composer install` + `composer test`
on PHP 8.3 / ubuntu-latest on every push and pull request.

## License

MIT
