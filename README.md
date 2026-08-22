# Kunstmaan Migrator

A Craft CMS 5 plugin that migrates a legacy Kunstmaan (Symfony) site into Craft:
it reads the legacy databases, compiles them against a mapping file, and writes
entries, assets, SEO meta, redirects, navigation and translations into Craft.

It is a **development tool**. It refuses to run when `CRAFT_ENVIRONMENT=production`.

## Requirements

- PHP 8.3+
- Craft CMS 5 (`^5.0`)

Optional, each enabling one adapter — detected at runtime, never required:
[SEOmatic](https://github.com/nystudio107/craft-seomatic),
[Retour](https://github.com/nystudio107/craft-retour),
[Navigation](https://github.com/verbb/navigation).

## Installation

```bash
composer require lameco/craft-kunstmaan-migrator
./craft plugin/install kunstmaan-migrator
```

## How it fits together

```
kuma_* legacy MySQL  ──►  kuma-compile  ──►  payloads  ──►  loader  ──►  Craft
   (one per legacy       reads the mapping,   validated     writes entries, assets,
    environment)         builds blocks        per payload   SEO, redirects, nav
```

The compile half lives in `lib/kuma-compile/` and knows nothing about Craft —
it reads the legacy database and the mapping and emits payloads. The write half
validates each payload against the *live* Craft schema, saves it as an
idempotent upsert keyed on `sourceUid`, and parks references it cannot resolve
yet for a second pass.

The two used to be separate tools exchanging NDJSON. The file was a contract,
and contracts drift: the compiler emitted the documented `{type, fields}` block
shape while the loader needed a `sourceRef` marker the contract never mentioned,
so Matrix rows updated partially and neither side could see why. They now run in
one process. Payloads are still available with `--dump` — they are just no
longer the seam.

See [`docs/loader-contract.md`](docs/loader-contract.md) for the payload schema,
and read **Structural placeholders** there: it is what makes migrated URLs match
the legacy ones.

## Where migrated assets land

By default, `{volume}/migrated/{year}/` — a bucket keyed on the file's own
created date, which is a fact about the file no editor has gone looking for.

Set `assetFolderStrategy` to `legacy-tree` and the Kunstmaan folder structure is
mirrored instead: `kuma_folders` is a nested set and every `kuma_media` row
carries `folder_id`, so the client's own organisation survives the move. A
corpus with more than one legacy source roots each environment in its own
segment first — `COM/Media/Afbeeldingen/Visuals/` beside `DE/…` — because three
installs each ship a folder called `Media/Afbeeldingen` and merging them
interleaves three sites' files under one name.

Folder names travel as the client wrote them; a file whose folder cannot be
resolved falls back to the year bucket rather than the volume root.

## Configuration

Two things, and they live in different places on purpose.

**The connection and the adapter switches** are plugin settings
(Settings → Kunstmaan Migrator, or `config/kunstmaan-migrator.php`). The
credential fields take the *name* of an environment variable, not its value —
Craft writes plugin settings into project config, which is committed and
deployed, and a password typed here would go with it. The settings screen
refuses one.

**The topology** — which databases exist, where each one's uploads live, which
legacy locale writes to which Craft site — comes from the mapping file, which
is version-controlled next to the field mappings it travels with. The settings
screen shows it read-only.

You do not hand-write one. `kuma-compile init` discovers the inventory from the
live legacy database — every pagepart class and page type ordered by volume,
real table names, child collections with their foreign keys, and every locale
with its live page count — and leaves you the half a machine cannot know: which
Craft block each legacy part becomes.

```bash
php lib/kuma-compile/bin/kuma-compile init --help
```

The grammar is validated by `lib/kuma-compile/src/Mapping/Schema.php`, which is
the authority on what a mapping may contain.

## Authoring the mapping

The mapping is the program. `kuma-compile` is the tool that writes it, and it
runs against the legacy database with no Craft anywhere — which is what lets a
mapping be authored before the target site exists.

```bash
vendor/bin/kuma-compile init --env=COM=enreach_website > migration/mapping/site.yaml
vendor/bin/kuma-compile validate migration/mapping/site.yaml --craft=.
vendor/bin/kuma-compile coverage migration/mapping/site.yaml
vendor/bin/kuma-compile readiness migration/mapping/site.yaml --craft=.
```

| Command | |
| --- | --- |
| `init` | discover the inventory — every pagepart class and page type by volume, real table names, child collections with their foreign keys, every locale with its live page count |
| `validate` | the mapping's own shape, then every handle it names against the target's project config |
| `coverage` | **did I miss anything in the legacy site** — anything not named in the mapping is an error, not a silent skip |
| `readiness --craft=.` | **will every required Craft field get a value** — the mirror of `coverage`, pointed at the target |
| `readiness --craft=. --unfilled` | the *optional* Craft fields no lane fills at all |
| `suggest` | draft rows for parts the mapping does not name yet |
| `doctor` | can the legacy environments be reached, and do they hold what the mapping says |

`init` deliberately emits a skeleton that **fails `validate`**: every part lacks
a disposition, so nothing runs until a human has resolved each one. It is a
checklist, and finishing it is what makes it a program.

`coverage` and `readiness` ask the same question in opposite directions, and a
mapping can pass one and fail the other. An unmapped legacy column is silent
data loss; an unfilled required Craft field is an entry an editor cannot save.

**`--unfilled` is the third question**, and the one that went unasked longest: a
Craft field that is *optional* and that no lane writes to is not a load blocker,
so it never appeared in `readiness` — and on the reference corpus that was 37
hero field instances across 20 entry types, empty on every one of 972 migrated
pages, with nothing reporting it. It groups by field handle, because
`heroTitle` unfilled on twenty entry types is one decision rather than twenty
findings. Read the `Craft writes` column: a field with a dropdown default is
populated on every migrated entry with no legacy data behind it, which is how
`heroColorScheme` reads as migrated on 6,173 rows and is not.

## Running it

Point the plugin at a mapping, then either use the control panel utility
(Utilities → Kunstmaan Migration) or the console:

```bash
./craft kunstmaan-migrator/doctor        # is this install ready, and is every environment reachable
./craft kunstmaan-migrator/migrate --mapping=migration/mapping/site.yaml
```

`migrate` validates the mapping's shape, then every handle it names against the
live Craft schema, then refuses to run while any `conflict:` is still open. Per
environment, in order: taxonomy entries, page entries with their blocks and
assets, then SEO meta, redirects, navigation and translations — the adapters run
after that environment's entries because each resolves a legacy id to an entry
that has to exist already. Then, once across the whole corpus, the fixup pass
resolves deferred references and the finalize pass rewrites legacy links and
media in rich text.

| flag | |
| --- | --- |
| `--dry-run` | compile and report without writing |
| `--dump=<dir>` | write the compiled payloads out for inspection |
| `--legacy-env=COM` | one environment only |
| `--only=PartnerPage` | one page type / entity, comma separated |
| `--limit=N` | stop after N entries |
| `--force` | re-save entries that already exist |
| `--entries-only` | skip the adapters, the fixup and the finalize pass |
| `--finalize-only` | run the finalize pass alone (idempotent, safe to re-run) |
| `--queue` | hand the run to Craft's queue, one job per environment |
| `--skip-assets` | skip the asset stage entirely |
| `--fail-on-loss` | exit non-zero when the run lost content, not only when it failed |
| `--resave=0` | skip the closing re-save (on by default; see below) |
| `--allow-drift` | run even though the legacy corpus has grown past the mapping |

**The run re-saves for you.** URIs are computed at save time from the parent's
URI, so a subtree written before its ancestor's per-site slugs settle keeps a
stale prefix — on the reference corpus, the difference between 76.6% and 97.7%
URL fidelity. Every section the mapping writes into is re-saved when the run
finishes. Pass `--resave=0` to skip it, and run it yourself afterwards:

```bash
./craft resave/entries --section=pages
```

**The run checks its own coverage first.** The legacy site is still live while the
migration is being built: editors add pages, and three weeks in someone adds a new
pagepart class. `coverage` catches that only when somebody remembers to run it, and
nobody remembers. `migrate` now takes the same snapshot at the top of the run and
refuses while any live pagepart class or page type is claimed by no lane —
`unmapped:` with a reason counts as claimed. A narrowed run (`--only`, `--limit`)
warns instead, because the tight iteration loop is not claiming to be complete.
`--allow-drift` runs anyway.

**Losses do not fail a run by default.** A migration that drops content is
counted and reported, and still exits 0. `--fail-on-loss` makes lossy
conversions, unresolved assets and unresolved references non-zero, which is what
you want in CI once a corpus has a known-good loss count.

**Run one migration at a time.** Craft's mutex uses MySQL named locks, which are
server-wide rather than database-scoped, so two concurrent migrations against
the same server contend and the loser fails on the structure lock.

**A slug collision is permanent, so a wrong placement cannot be re-run away.**
Craft never reclaims a base slug once it has handed out `-2`. An entry that
lands under the wrong parent and is later moved keeps the suffix forever — 204
of the URL differences in the reference corpus's first verification run were
this, and no amount of `--force` repairs one. Correct-and-re-run fixes field
values, block content, slugs that were never taken. It does not fix a slug that
was. The repair is an empty database and a fresh run, which is why the trial
runs (`--dry-run`, `--only`, `--limit`) exist and why the first full run should
go into a scratch database.

**There is no undo.** No rollback, no purge, no "migrate --down". The recovery
procedure is to drop the database and restore it. That is a survivable answer
only because the production guard means the only databases this ever touches are
ones you can afford to drop — see below.

### The other commands

| Command | |
| --- | --- |
| `load/entry --payload=<file> [--dry-run]` | validate and save a single payload file |
| `load/fixup` | drain the deferred `_ref`s parked by the load pass |
| `load/redirects --payload=<file>` | load a redirects payload produced by other means |
| `state/export` | stream the state table as NDJSON — the file to diff between runs |
| `state/diff --from=<a> --to=<b>` | what changed between two exports: entries that stopped being written, entries whose block count moved |

Every command prints machine-readable JSON or NDJSON to stdout and exits
non-zero on failure.

## Extending it

A pass that runs after an environment's entries exist is a `MigrationAdapter`.
Register one and it runs alongside the built-ins, gated by the same settings
switch and plugin check:

```php
Event::on(AdapterRegistry::class, AdapterRegistry::EVENT_REGISTER_ADAPTERS,
    static function (RegisterAdaptersEvent $event): void {
        $event->adapters[] = new Adapter(
            'acme', 'Acme', 'acmeEnabled', 'acme-plugin',
            static fn () => new AcmeMigrationService(),
        );
    });
```

## Development

```bash
composer install
composer test              # 491 tests
composer test-coverage     # per-module gate, needs pcov or xdebug
```

CI runs `composer validate --strict`, the suite and the coverage gate on PHP 8.3,
then a smoke job that installs the plugin into a scratch Craft 5 and runs `doctor`.

## License

MIT
