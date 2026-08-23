# Changelog

All notable changes to `lameco/craft-kunstmaan-migrator` are documented in this
file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- **A fresh mapping opens with a prefill offer, not sixty empty rows.** The
  content model's block specs already say which legacy parts each block covers
  (`migrationSource:` and the notes-table headers) and which property becomes
  which field. The Mapping screen offers "Prefill from the content model"
  whenever parts lack a target and a specs directory exists
  (`docs/content-model/page-builder` under the project root by default, a
  plugin setting to override); `suggest --apply` is the same thing from the
  CLI. A draft is not a decision: drafted rows get the block, the field maps
  and the spec's own drops as reasoned ignores, while every leftover column
  stays `unreviewed` — so each row remains open until somebody reviews it.
  On the reference corpus a fresh skeleton prefills 22 of 61 parts in one
  click, with the skipped ones each carrying their reason.

- **The locales step asks one question.** The dropdowns now start on the Craft
  site whose language matches the legacy locale, so the step is a check rather
  than a form; the per-locale "why not" box is gone (a skipped locale is
  written as `!unmapped "not selected during setup"`, still a declaration the
  coverage report honours, and the mapping file is where a better reason
  belongs); and the uploads textarea left the wizard entirely — every
  Kunstmaan site keeps uploads at `public/uploads/media`, so the path is read
  from the detected checkout, with a plugin setting to override the one site
  that breaks the convention.

- **A CP section: Kunstmaan Migration.** The authoring screens stop hiding
  behind URLs nothing linked to — the nav item carries a subnav of Mapping,
  Set up, and Run (which points across to the Utility, where a destructive,
  occasional operation belongs). The section lands on the mapping when one
  exists and on the wizard when none does.

- **The setup wizard starts by finding the site, not asking about it.** A new
  first step scans a folder (default: the Craft project's parent, e.g.
  `~/Sites`) for Kunstmaan checkouts — a checkout qualifies by its
  `composer.lock` naming a `kunstmaan/*` package — and offers them as a list
  with the Kunstmaan version, the database its own `.env` names (`.env.local`
  winning), and whether `public/uploads/media` exists. Picking one prefills the
  connection, the media root on the locales step, and the source path on the
  review step; every value stays editable, and "enter it by hand" remains one
  click. The password is never copied as a literal — the settings model
  rightly refuses one — so the prefill references an env var that already
  resolves to it, or tells the operator exactly what to add to `.env`.
- **The wizard's write step produces the introspection artifact too.** With a
  source path known (detected or typed), creating the mapping runs the same
  introspection the CLI does — booted metadata with static fallback — writes
  `introspection.json` next to the mapping, and generates the skeleton from
  its exact table names and child-collection ownership.

- **`kuma-compile bootstrap`** — starting a migration is one command: it runs
  `survey` (size the corpus), `introspect` (read the application's wiring) and
  `init` (generate the mapping skeleton) in order, writing
  `<dir>/introspection.json` and `<dir>/mapping.yaml`. A mapping that exists is
  never overwritten — bootstrap is how a migration starts, not how it starts
  over — while the survey and the artifact refresh on every run. The three
  steps stay available individually. `init` gained `--introspection=` and
  prefers the artifact's booted metadata (exact tables, child-collection
  ownership from resolved associations) over its static source scan.

- **`kuma-compile introspect`** — dumps the legacy application's own account of
  itself as a committed artifact: booted Doctrine metadata when the checkout
  runs on this machine's PHP (exact tables, columns, associations including
  ManyToMany join tables, run as a child process so the two dependency trees
  never mix), a static ORM-attribute scan when it does not, plus two scans that
  are static either way — the sidecar entities a `NodeListener` wires into the
  page UI, and which columns each form type actually draws. The compiler never
  reads the artifact; the mapping stays the program.
- **`validate --introspection=`** — the mapping checked against that wiring:
  unclaimed ManyToMany selections (a join table is invisible to every column
  list), editor-facing columns ignored without a written reason, and mapped
  columns the entity does not have. First run on the reference corpus: 11
  unclaimed relation selections, 118 silently dropped editor-facing columns,
  and one mapped column that did not exist — an expression that had been
  reading null for the entire life of the mapping.
- **`m2m(join_table, owner_column, target_column)` expression** — reads the ids
  an owning row selects through a ManyToMany join table; `ref()` now accepts
  the resulting list and turns each id into the entry it became, keeping order
  and dropping ids that resolve to nothing.
- **Literal expressions** — `'band'` supplies a value no column carries, for a
  required field whose answer is a design fact rather than data.
- **`readiness` walks the entities lane** — a required field on a taxonomy
  target was invisible before; `country.flag` (required Assets, no legacy
  source) now reports as missing instead of not at all.

- **A `sidecars:` lane** for the per-page entities Kunstmaan attaches outside
  the pagepart tree — header tabs, footer tabs, structured data. The lane keys
  on the polymorphic `(ref_entity_name, ref_id)` column pair, not on a table
  name, so any corpus's variant of the concept maps the same way. Resolution is
  per locale through the published node version, the same path `kuma_seo`
  already follows, so two locales with different tab rows each get their own.
  A mapped field the target entry type does not carry is dropped and counted
  per type, which turns a field-layout gap into a measured fact. `readiness`
  credits the lane; `survey` and `init` discover candidate tables by the column
  signature; the control panel mapping editor gains the lane, offering the
  union of page fields since a sidecar decorates whichever page carries a row.
- **`links()` accepts nested `link(...)` groups** — a table holding several
  whole links (primary/secondary/tertiary) becomes several buttons in one
  Matrix, the fourth column of a group still mapping to the button style.

- **`links(column=Label, …)` field expression** — N sibling single-URL columns
  become one button each in a Matrix of buttons, with the label carried by the
  mapping because the legacy table never stored one. Built for SocialMedia's
  five network columns, which target `linksBlock.buttons`, a required Matrix
  nothing could fill.
- **`concat(expr, expr, …)` field expression** — joins every non-empty
  alternative where `coalesce()` keeps only the first. Built for ContactPerson,
  which keeps prose in both `content` and `contact_person_content` on 80 live
  rows; the spec folds them into one field.
- **`centered` transform** — a legacy alignment string (`center`/`centered`)
  becomes the alignment Lightswitch value.

### Fixed

- **`link()` aimed at an indexed nested position now sees its Matrix.** A target
  like `cards[0].buttons` addresses the buttons Matrix on the nested card type;
  the slot resolver only looked at top-level targets, so `link()` emitted a bare
  link map Craft discards without a word. The resolver now walks nested
  positions, which is what lets a single-tile part (Product: title + link, no
  child table) compile as a cardsBlock holding one card.

## 2.0.0-alpha.3 — 2026-08-22

Setting the plugin up no longer starts with a text field and prior knowledge.

### Added

- **A four-step setup wizard.** Connect a legacy database, pick the databases to
  read, bind each legacy locale to a Craft site, then review. Each step
  validates where it is answered — a bad credential is reported on the
  connection step, not as a stack trace on the first run — and driver errors are
  translated into something an operator can act on. The wizard refuses to run
  against production.
- **An empty install says what to do next** instead of rendering an empty form,
  and the mapping editor shows how far through a lane you are.
- **A run reports itself while it runs.** The utility polls the queue and shows
  the current job and its progress, rather than leaving the operator to guess
  whether anything is happening.

### Changed

- **The field map is two dropdowns**, a source column and a transform, read from
  the live install rather than typed as an expression from memory. Saving a row
  you did not edit writes nothing — an earlier no-op save rewrote 1,652 lines
  and destroyed the mapping's comments.

### Fixed

- A `path` repository left in the plugin's own composer.json pointed it at
  itself and broke installation from a clean checkout.
- Two bugs found by static analysis in its first run, and four found by parsing
  every template — including one that took the settings page down entirely.

### Internal

- PHPStan runs in CI at level 1 over `src` and `lib/kuma-compile/src`.
- The NodeMenu navigation pass is under test. It read Craft's primary site
  statically, looked entries up around the ElementWriter seam, and constructed
  elements directly — three things that made it undrivable without a booted
  CMS, and the reason it shipped two undefined-variable bugs.

## 2.0.0-alpha.2 — 2026-08-22

The first *tagged* release of v2. `2.0.0-alpha.1` below was written up in July
and never tagged, so until now the only way to install this plugin was to track
a branch — which is why every consumer pinned `dev-v2-loader`, a branch that
stopped receiving work at PR #19 while the work continued elsewhere.

### Added

- **Making a mapping is part of the plugin.** `mapping/init` discovers a legacy
  corpus across several databases and writes a skeleton for it — pagepart
  classes and page types by live volume, real table names, unplaced columns,
  child collections with their foreign keys, every locale with its live page
  count. `mapping/check` says whether a mapping is well-formed and whether this
  Craft install accepts it. Both were reachable only through a CLI shipped
  inside `vendor/`.
- **The mapping is editable from the control panel**, per lane and per row. The
  block and field lists are read from the live install rather than typed from
  memory, and an edit writes back to the file so it stays the single source of
  truth — rewriting only the keys that changed, so the diff is the decision.
- **The `forms:` and `globals:` lanes**, declared in the DSL since it was
  written and compiled by nothing. Forms become Formie forms behind a
  `FormGateway` seam; the legacy footer becomes navigation nodes, with the
  target stated per context in the mapping rather than chosen in code.
- **Adapter-owned configuration.** An adapter declares its own settings and the
  screen renders what it declares, so a pass a project ships is configurable
  without editing a model it does not own.

### Fixed

- **A registered adapter could never run.** The gate read the operator's switch
  with `property_exists()`, and only the four built-ins have a literal
  property — so every other adapter was gated off permanently while rendering a
  settings row and resolving to a runnable service.
- **The settings screen was a 500** (Twig 2 `for … if` syntax), and separately
  **could never be saved** by any project that had set its password as an
  environment reference: the env parser swaps in the resolved value before
  validation, so the validator that exists to reject a literal password
  rejected `$KUMA_DB_PASSWORD`.
- **The NodeMenu pass had been erroring since the site-map refactor**, reading a
  variable it was never given. It migrated nothing and the run reported no
  failure.
- **The rewriter cached legacy ids across databases.** They are unique only
  within one, so one environment's media resolved to another's asset.
- **The finalize pass had three implementations that disagreed** — one ran once
  against whichever database the loop ended on; two looped correctly and lost
  the media roots.
- **A queued run was not the run it replaced**, skipping the fixup and finalize
  passes with nothing on screen saying so.

### BREAKING

- **`lameco/kuma-compile` is no longer a dependency — its source now ships inside this
  plugin** at `lib/kuma-compile/`, autoloaded through a second PSR-4 root. The package was
  never published to a remote, so a `path` repository pointing at a checkout on one developer's
  machine was the only way to satisfy the requirement: `composer install` failed anywhere else.
  One repo, one install, one version.

  The `Lameco\KumaCompile\` namespace is deliberately kept. The compile engine reads the legacy
  database and knows nothing about Craft, and that boundary is worth keeping legible — it is why
  its 113 tests run in under a tenth of a second with no Craft bootstrap. They run here as the
  `Compile` suite. The standalone `kuma-compile` CLI still works and is still Craft-free; its
  bootstrap now finds an autoloader from any of the four places it can now live.

  Consumers drop the `kuma-compile` path repository from their `composer.json`; nothing else
  changes, because no import moved.

- **The plugin previously required `lameco/kuma-compile`.** Twenty files existed in both repos and
  had already drifted: the page-lane checks lived only here, the `unreviewed:` lane and
  fill measurement only there. What stays here is what only a running Craft site can
  answer — `TargetModel`, now the live-gateway implementation of `TargetSchema`,
  returning `Slot` objects rather than arrays.

### Added

- `migrate` runs the four adapters that were configured in `Plugin::init()` and called
  by nothing: SEO meta, redirects, navigation and translations. They run per environment
  after that environment's entries, because each resolves a legacy id to an entry that
  has to exist already. `--entriesOnly` skips them.
- Redirects compile from the mapping's `redirects:` lane instead of a payload file
  nothing ever wrote. `load/redirects --payload=` still reads that file; both paths meet
  at `LoadController::reportForRedirects()`.
- `_address` payload nodes resolve into Craft Address elements, reusing the id of an
  address the entry already owns so a re-load updates rather than deletes and recreates.

### Fixed

- `legacyDb` is repointed per environment. It is registered once from one setting, which
  is right for a one-database migration and wrong for a three-database one: a DE run read
  COM's `kuma_seo`, `kuma_menu` and `kuma_translation` and reported them as migrated.

## 2.0.0-alpha.1 — 2026-07-07

First alpha of the v2 rewrite. The plugin pivots from an all-in-one
extract/transform/load/verify pipeline to a thin, payload-driven **loader**:
it consumes fully-resolved Craft-native JSON payloads and writes them into
Craft. Reading the legacy Kunstmaan database and proposing/compiling
field/section mappings now live entirely in the separate `kuma-migrate`
orchestration repo, which mirrors [`docs/loader-contract.md`](docs/loader-contract.md)
as the payload contract between the two repos.

### BREAKING

- **Plugin handle renamed `kunstmaan-migrator` → `kunstmaan-migrator`.** Every
  console command moves from `./craft kunstmaan-migrator/...` to
  `./craft kunstmaan-migrator/...`, including the install command
  (`./craft plugin/install kunstmaan-migrator`). The composer package name
  (`lameco/craft-kunstmaan-migrator`) and the PSR-4 namespace
  (`lameco\kunstmaanmigrator\`) are unchanged — only the plugin handle and
  human-facing name move.
- **`analyze` / `map` / `compile` / `verify` / `migrate` and the mapping.yaml
  workflow are removed** from this plugin entirely, along with the CP
  "Migration Pipeline" utility, the Anthropic-backed proposal stage, and the
  plugin-owned legacy MySQL connection. That machinery now lives in
  `kuma-migrate`, which reads the legacy database, proposes mappings, and
  compiles them into the JSON payloads this plugin loads. This plugin makes
  no outbound AI calls and never reads a legacy database.
- **Command surface is now exactly five commands:**
  - `kunstmaan-migrator/load/entry --payload=<file> [--dry-run]` — validate (and,
    unless `--dry-run`, save) a payload: idempotent upsert by `sourceUid`,
    alias recording, deferred `_ref`s parked for `load/fixup`.
  - `kunstmaan-migrator/load/fixup` — second pass, resolves and patches every
    pending `_ref` left behind by `load/entry`.
  - `kunstmaan-migrator/load/redirects --payload=<file>` — loads a redirects
    payload, resolving `sourceUid` targets to migrated-entry URIs.
  - `kunstmaan-migrator/state/export` — streams the migrator's state table as
    NDJSON for resume/verify tooling.
  - `kunstmaan-migrator/doctor` — preflight checks (plugin/state-table reachable,
    storage writable, not production, Retour presence).

### Removed (vs the pre-alpha plan)

- Anthropic-backed `analyze` proposal stage and the `ANTHROPIC_API_KEY`
  requirement.
- Single `mapping.yaml` review workflow and its CP editor.
- `compile`, `verify`, `migrate` (and `migrate/install`) console commands.
- Plugin-owned legacy MySQL connection and its `CRAFT_LEGACY_DB_*` /
  `DATABASE_URL` configuration surface — legacy-DB reachability is
  orchestration-side (`kuma-migrate`) now.
- CP "Migration Pipeline" runner utility and CP settings page
  (`hasCpSettings = false`).

### Kept

- **`NeverProductionTrait`** still hard-blocks every write command when
  `CRAFT_ENVIRONMENT=production`.
- **Optional SEOmatic / Retour adapters**, runtime-detected via
  `Craft::$app->plugins->getPlugin(...)`; neither is a hard composer
  `require`.
- **PHPUnit 11 test suite** — unit + integration tests, `composer test`.

[2.0.0-alpha.1]: https://github.com/lameco/craft-kunstmaan-migrator/releases/tag/v2.0.0-alpha.1
