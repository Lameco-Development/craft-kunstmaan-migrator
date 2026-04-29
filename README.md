# Kunstmaan Migrator (revisited)

A Craft CMS 5 plugin that migrates content from a legacy Kunstmaan (Symfony) site
into an existing Craft CMS site. Craft is the source of truth for schema —
Kunstmaan content gets mapped onto Craft sections / fields / entry types as
they already exist.

> **Status:** v1.0 hardening. The canonical operator workflow is
> `doctor -> analyze -> map -> compile -> migrate --dry-run -> migrate --live -> verify`.
> See `.planning/ROADMAP.md` for the full plan.

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

Advanced project-shape hints live in `config/kunstmaan-migrator.php`; see
`config/kunstmaan-migrator.example.php`. Use these only for schema-specific
decisions the generic analyze/compile flow cannot infer, such as ambiguous
rich-text fallback blocks or explicit relation mirrors into Craft presentation
fields.

## Operator workflow

Kunstmaan **Page** entities are the source root. Each accepted Page mapping
produces a Craft **Entry** in the configured section/entry type; page-owned
detail rows, page parts, relations, taxonomies/data providers, SEO/redirect
sidecars, CKEditor references, and referenced assets are accounted for from
that Page root.

Run the migration in this order:

```bash
./craft kunstmaan-migrator/doctor
./craft kunstmaan-migrator/analyze
# Review and edit storage/migration/mapping.yaml as needed.
./craft kunstmaan-migrator/map
./craft kunstmaan-migrator/compile
./craft kunstmaan-migrator/migrate --dry-run
./craft kunstmaan-migrator/migrate --live
./craft kunstmaan-migrator/verify
```

The `analyze` stage may call Anthropic for mapping proposals. `compile`,
`migrate`, `finalize`, and `verify` are deterministic and do not make runtime
AI calls. The Control Panel is not the canonical operation surface; the CLI
workflow above is.

Generic automation is intentionally partial. Project-specific mapping edits
are expected, but silent omissions are not accepted: every page-owned source
surface should be migrated, deliberately dropped with rationale, marked
out-of-scope, reported as unsupported, or surfaced as a warning.

### Page-rooted coverage report

`compile` writes the operator review artifact at:

```text
storage/migration/PAGE-ROOTED-COVERAGE.md
```

Use this report before any live run. Its categories mean:

- `migrated` — accepted mapping routes the source surface into the Craft Entry
  or a reachable sidecar.
- `dropped` — operator mapping deliberately excludes the surface; the reason
  should explain why this is acceptable.
- `out_of_scope` — the surface is outside v1.0 scope, such as FormBundle,
  SearchBundle, MenuBundle, users/ACLs, non-public drafts, or full orphan-media
  import.
- `unsupported` — the scanner found a structural shape the current migrator
  cannot safely migrate automatically; either add a mapping/handler that makes
  it explicit or accept it as release debt.
- `warning` — more operator review is needed, usually because source metadata
  or target Craft mapping evidence is incomplete.

A missing surface is acceptable only when its category and reason match the
project's release intent. For example, an orphan media row that no migrated
Entry references is expected out-of-scope in v1.0; a page-owned relation with
no mapping is not acceptable until it is migrated, visibly dropped, or marked
unsupported with follow-up.

### Asset behavior

Assets are page-driven. Default load is JIT: assets are pulled as migrated
entries reference them. `--preload-assets` still follows the same model and
preloads **referenced assets only** from the in-scope transformed payloads. It
does not import every `kuma_media` row and does not import orphan media by
default.

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
PHP 8.3 / ubuntu-latest on every push and pull request. The scratch-Craft smoke
job proves the plugin installs and its CLI command loads; if migration runtime
configuration is absent in that scratch site, the expected doctor failure is
treated as configuration evidence rather than a successful rehearsal.

Before tagging v1.0, run the transform characterization suite in release mode:

```bash
RELEASE_REHEARSAL=1 vendor/bin/phpunit tests/integration/transform/TransformCharacterizationTest.php --testdox
```

That mode fails loudly when the private CQM fixture corpus is empty. Normal
developer runs skip the empty-corpus sentinel so contributors do not need
private rehearsal data for unrelated changes.

## License

MIT
