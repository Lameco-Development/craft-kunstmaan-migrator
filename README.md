# Kuma Loader

A Craft CMS 5 plugin that loads fully-resolved, Craft-native JSON payloads
produced by a legacy Kunstmaan (Symfony) site into Craft. Kuma Loader is
deliberately thin: it validates a payload against the live Craft schema,
saves it (upsert, idempotent), resolves cross-entry `_ref`s in a second
pass, imports redirects, and reports state — nothing more.

All migration intelligence — reading the legacy Kunstmaan database,
proposing field/section mappings, and compiling those mappings into the
payload files this plugin consumes — lives in the separate
[`kuma-migrate`](https://github.com/lameco/kuma-migrate) orchestration repo.
This plugin never reads the legacy database and makes no outbound AI calls;
it is a runtime-zero-intelligence loader.

See [`docs/loader-contract.md`](docs/loader-contract.md) for the payload
schema this plugin expects `kuma-migrate` to produce.

## Requirements

- PHP 8.3+
- Craft CMS 5 (`^5.0`)

## Installation

```bash
composer require lameco/craft-kunstmaan-migrator
./craft plugin/install kuma-loader
```

## Commands

| Command | Description |
| --- | --- |
| `kuma-loader/load/entry --payload=<file> [--dry-run]` | Validates a payload (JSON/NDJSON) against the live Craft schema and, unless `--dry-run` is passed, saves it — idempotent upsert by `sourceUid`, alias recording, deferred `_ref`s parked for the fixup pass. |
| `kuma-loader/load/fixup` | Second pass: drains every state row's pending `_ref`s left behind by `load/entry` and patches them in now that the referenced entries exist. Run once every payload in a batch has gone through `load/entry`. |
| `kuma-loader/load/redirects --payload=<file>` | Loads a redirects payload (NDJSON), resolving `kuma:<ENV>:<table>:<id>` targets to their migrated entry's URI and writing them via Retour when installed. |
| `kuma-loader/state/export` | Streams the migrator's state table as NDJSON (`sourceUid` / `entryId` / `targetType` / `alias_of` per line) for resume/verify tooling. |
| `kuma-loader/doctor` | Preflight checks: plugin installed + state table reachable, `storage/migration/` writable, not running in production, Retour presence. |

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
