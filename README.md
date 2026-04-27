# Kunstmaan Migrator (revisited)

A Craft CMS 5 plugin that migrates content from a legacy Kunstmaan (Symfony) site
into an existing Craft CMS site. Craft is the source of truth for schema —
Kunstmaan content gets mapped onto Craft sections / fields / entry types as
they already exist.

> **Status:** Phase 1 (Foundation & Connectivity). Plugin scaffolds, connects
> to a legacy MySQL DB, attaches the `kunstmaanSourceId` field, and exposes a
> working `doctor` command. The `analyze` / `map` / `migrate` / `verify`
> commands land in Phases 2-4. See `.planning/ROADMAP.md` for the full plan.

## Requirements

- PHP 8.3+
- Craft CMS 5 (`^5.0`)
- A reachable legacy Kunstmaan MySQL database
- An Anthropic API key (for the `analyze` proposal stage in Phase 2)

## Installation

> For Kunstmaan surfaces this migrator deliberately does NOT cover (FormBundle,
> SearchBundle, MenuBundle, user accounts, media folder hierarchy, slug history
> beyond `kuma_redirects`, drafts), see
> [Known omissions in v1.0](CHANGELOG.md#known-omissions-in-v10).

```bash
composer require lameco/craft-kunstmaan-migrator
./craft plugin/install kunstmaan-migrator
```

Re-running install (or applying future schema bumps):

```bash
./craft kunstmaan-migrator/migrate/install
```

## Configuration

The plugin owns its legacy MySQL connection internally — you do **not** need
to declare a `legacyDb` Yii component in `config/app.php`. Configure via env
vars:

```
CRAFT_LEGACY_DB_SERVER=localhost
CRAFT_LEGACY_DB_DATABASE=kunstmaan_dump
CRAFT_LEGACY_DB_USER=root
CRAFT_LEGACY_DB_PASSWORD=secret
CRAFT_LEGACY_DB_PORT=3306             # default 3306
CRAFT_LEGACY_DB_CHARSET=utf8mb4       # default utf8mb4
CRAFT_LEGACY_DB_TABLE_PREFIX=         # default empty

ANTHROPIC_API_KEY=sk-ant-...          # required for Phase 2 analyze
```

Plugin Settings (Settings → Plugins → Kunstmaan Migrator) override env vars
when set. The Settings UI ships in Phase 4; until then, env vars are the
canonical configuration surface.

## Doctor

Verify configuration before running migration commands:

```bash
./craft kunstmaan-migrator/doctor
```

Reports OK / FAIL on:

1. Legacy DB reachability
2. Anthropic API key presence (presence only — the value is never logged)
3. `storage/migration/` writable (auto-created if missing)

Exits 0 on full pass, 1 on any FAIL.

## Production safety

The plugin **refuses to run** when `CRAFT_ENVIRONMENT=production`. It is a
dev / staging tool only.

## Development

```bash
composer install
composer test
```

CI runs `composer validate --strict` + `composer install` + `composer test` on
PHP 8.3 / ubuntu-latest on every push and pull request.

## License

MIT
