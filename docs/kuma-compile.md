# kuma-compile

Compiles a legacy **Kunstmaan (Symfony)** database into **Kunstmaan Migrator** payloads, driven by a
declarative mapping file. Project-agnostic: it knows Kunstmaan and it knows the loader's payload
contract, and nothing about any particular site.

```
mapping.yaml ─┐
legacy DB    ─┼─▶ kuma-compile ──▶ payloads/*.ndjson ──▶ Kunstmaan Migrator ──▶ Craft
media root   ─┘   deterministic        (loader contract)      (existing plugin)
```

## Why it exists

The previous approach put the migration's intelligence in a Claude Code skill that made mapping
decisions *at run time*. That can't be reproducible, diffable, or testable, and it can't be handed
to a colleague as "run this."

Here the mapping file **is** the program. Claude drafts it; `kuma-compile` executes it, and makes no
judgment calls of its own. Every decision the migration takes is a line in a YAML file, reviewed in
a PR like any other code.

## Reading the target

The mapping describes a source and a target. The source is discovered from the legacy database
and the Doctrine entities; the target is read from the Craft site's `config/project/**`, which
is version-controlled YAML sitting next to the mapping and needs no running Craft.

Anything the tool can read, it should read. Every question about the target that gets answered
by hand instead — which field holds a heading, what a Matrix's nested entry type is called,
which legacy column becomes which field — is a guess that looks like a fact until a load
fails, or worse, until it succeeds and quietly writes nothing.

## Design rules

1. **Fail loud.** Anything the mapping doesn't name is an error, not a silent skip.
2. **Deliberate omissions are declarations.** `drop:` and `manual:` say "not migrating this", with
   a reason, and show up in the coverage report.
3. **The mapping carries its own disagreements.** A `conflict:` block records two readings; the
   tool refuses to run while any is `open`.
4. **Published content only.** Kunstmaan clones the whole pagepart graph per node version. Every
   query resolves through `public_node_version_id`. On the first real corpus, only **4.6%** of
   `kuma_page_part_refs` rows were reachable from a published page — a migrator that skips this
   resolution moves twenty times the content. There is no toggle.

## Requirements

- PHP 8.3+ with `pdo_mysql`
- The legacy Kunstmaan database(s) loaded locally
- A mapping file — grammar in the consuming project's `docs/migration/MAPPING-DSL.md`

## Preparing a mapping

You do not hand-write one. `init` discovers the inventory; you supply the half a machine
cannot know — which Craft block each legacy part becomes.

```bash
# The CLI ships with the plugin: `vendor/bin/kuma-compile` in a consuming
# project, or `php bin/kuma-compile` from this repo.
export KUMA_DB_USER=root KUMA_DB_PASSWORD=secret   # KUMA_DB_HOST / KUMA_DB_PORT optional

# 1. Generate the skeleton from the live database(s)
vendor/bin/kuma-compile init \
  --env=COM=legacy_com --env=DE=legacy_de \
  --source=/path/to/kunstmaan-checkout \
  --out=migration/mapping/site.yaml

# 2. Fill in the TODOs, checking as you go
vendor/bin/kuma-compile validate migration/mapping/site.yaml

# 3. Once it validates, check nothing live is unaccounted for
vendor/bin/kuma-compile coverage migration/mapping/site.yaml

# 4. Check every required Craft field has something to put in it
vendor/bin/kuma-compile readiness migration/mapping/site.yaml --craft=/path/to/craft

# 5. Preflight before compiling
vendor/bin/kuma-compile doctor migration/mapping/site.yaml
```

`init` writes out every live pagepart class and page type ordered by volume, real table
names, real column lists as `ignore:` candidates, child collections with their foreign
keys, and every locale with its live page count. **The generated file deliberately does not
validate** — each part lacks a disposition, so `validate` fails until you resolve them. A
skeleton you could forget to finish is worse than one that fails loudly.

Pass `--source` whenever you have the Kunstmaan checkout. Table names are declared in PHP
attributes and follow no derivable convention, and child-collection ownership can only be
read from the Doctrine relation — in one real corpus `UserStoryItem` targets
`UserStoriesPagePart` through a join column named `block_link_pp_id`, which any name-based
heuristic attributes to the wrong part. Without `--source` the tool falls back to that
heuristic and marks what it could not determine.

## Commands

### `init`

Generates a mapping skeleton from the live database. Refuses to overwrite an existing file.

### `validate`

Two checks, the second optional.

**Shape** — no database, no Craft checkout needed. Unknown keys are errors, not warnings — a mistyped key
fails silently otherwise, meaning a rule never fires and content quietly does not migrate.
Also catches parts with no disposition or two, child collections missing a table or foreign
key, sequence rules whose `else:` names no rule, and a class claimed by two lanes.

**Target** — with `--craft=<project root>`, every handle the mapping names is checked to exist:
block entry types, field handles (including nested Matrix paths), sections, promote
destinations and relation fields. Required fields the mapping never supplies are reported as
warnings rather than errors, since a field may have a default.

Two warnings predict content arriving *wrong* rather than not arriving, which is the kind of
defect a migration ships without noticing:

- **a required field nothing fills** — the block lands and renders empty. On the reference
  corpus this was `faqBlock.faqSource` and `faqBlock.heading`: 84 compiled FAQ blocks carrying
  7 headings and 0 sources between them, so the blocks existed and showed nothing.
- **rich text aimed at a plain-text field** — `| ckeditor` keeps the legacy HTML, and a
  `PlainText` field has nowhere to render it, so the tags reach the page as text. Use
  `inlineHtml`, which flattens block tags and keeps emphasis.

Both are also printed by the `migrate` preflight and the control panel's Check button, so an
operator running a migration sees them without knowing to run `validate` first.

This check exists because the alternative is finding out at load time. On the first real
mapping it caught eight wrong handles — `embed` for a field called `embedCode`, `logos` for
`logoSliderItems`, `statistics` for `stats` — each of which had been written by hand and
looked entirely plausible.

### `ignore:` and `unreviewed:`

`init` cannot know what you are deliberately not migrating, so it writes what it could not place
under **`unreviewed:`**, and `validate` fails while any entry remains. Resolving one means moving
it into `map:`, or into `ignore:` **with a reason**:

```yaml
ignore:
  latitude: "folded into the native address field"
  date:     "no Craft equivalent"
```

The list form `ignore: [a, b, c]` still parses — it is how every mapping was written before reasons
existed — but it is counted and reported, never treated as settled. It is the state where a
decision and a generator default are indistinguishable, which is how a legacy country relation once
sat in `ignore:` and migrated as nothing at all while three documents described that list as a
deliberate declaration.

#### Spec divergence

`validate --craft=<root> --specs=<dir>` (repeatable) fails on any legacy column the mapping drops
that the content model's specs give a target for. The specs each carry a
`Migration notes (Kunstmaan → Craft)` table — the field map somebody already thought through, in
the same repo — and nothing compared the two, so the mapping could silently disagree with the
document describing it.

The check is source-driven: "does the mapping drop a column this spec maps", not "does the mapping
fill every field this spec names". One spec covers several legacy classes, so the target-driven
question flags a part for rows describing a different part. It needs no database — both sides are
text in the repo.

A reasoned `ignore:` still diverges. A reason records that somebody decided; it does not make the
spec agree, and two documents describing different migrations is the condition this exists to
catch. Overriding a spec means changing the spec.

### `suggest`

Drafts field maps for parts that have a target block but no `map:`.

The target content model's block specs carry a `Migration notes (Kunstmaan → Craft)` table
pairing legacy properties with the fields they become, and naming what was deliberately
dropped. That table is a field map somebody already thought through. `suggest` reads it,
resolves each property against the legacy table's real columns and each field against the
Craft schema, and prints YAML for the parts it can resolve — reporting everything it cannot
rather than guessing.

```bash
kuma-compile suggest mapping.yaml --specs=docs/content-model/page-builder \
                                  --craft=/path/to/craft --env=COM
```

Output is a draft for review, not an edit. Spec tables are prose: a row may list several
targets against one source group, or name a field inside a parenthetical reason. Read what
it produces before pasting it into the mapping.

### `doctor`

Preflight. Checks the mapping parses, every environment is reachable and actually looks like a
Kunstmaan schema, and no conflict is still open. Lists non-blocking `todo:` entries. Exits non-zero
if anything blocks.

### `compile`

Reads the legacy database and writes one NDJSON payload file per environment. Refuses to run on
a mapping that does not validate or that still has an open conflict.

One Kunstmaan *node* becomes one Craft entry; its published *translations* become that entry's
sites, each with its own field values — which is what Craft's per-site content model expects.
`sourceUid` is the node identity, so a re-run updates rather than duplicates.

An entry's sites are exactly those translations and nothing else. A locale the node was never
translated into gets no row at all: an empty row is what lets Craft propagate the primary site's
slug into that locale, where it collides with the real entry and takes a permanent `-2`.

A translation that is switched off gets no row either, which is a deliberate reversal — earlier
versions wrote it disabled so it kept owning its slug in that locale's URL. Kunstmaan switches a
translation off instead of deleting it, so a corpus carries years of dead locales that no editor
will ever publish, and the URLs they were preserving are covered by the redirects lane. Set
`defaults.offlineCutoff` to keep the recent ones:

```yaml
defaults:
  # Offline translations saved on or after this date are editorial work in progress, and
  # come across disabled for an editor to publish. Older ones are dropped. Omit the key
  # and every offline translation is dropped regardless of age.
  offlineCutoff: '2026-03-01'
```

The rescue only reaches a translation that was published at some point: Kunstmaan keeps an
unpublished edit in a draft version, and a translation with no public version has no page entity
to read content from, so no date can bring it across.

This decides which *pages* exist, not which URLs are right. An offline ancestor still hands its
slug to the published pages beneath it, however old it is — see "Structural placeholders" in
`docs/loader-contract.md`.

`--craft=<project root>` reads the target content model and uses it to derive the nested entry
type of every Matrix field and where an absorbed heading lands. Without it, both fall back to
the field handle, and a mapping may need `absorbInto:` written by hand.

`--dry-run` reports without writing, `--limit` caps entries per environment, `--env` selects one.

Every lossy conversion and every skipped part is counted and printed. That report is the point:
what a migration could not carry across should be a number, not a surprise.

### `coverage`

Measures the mapping against the live content it claims to describe. Every live pagepart class and
page entity must be claimed by a lane — `blocks`, `sequence`, `forms`, `globals`, `redirects`, or
the explicit `unmapped` lane. Anything unclaimed is a hole, and holes exit non-zero.

Also reports legacy locales with no Craft site, and how many live pages each strands.

`--json` emits the same data machine-readably, for CI.

### `readiness`

Every required field on every Craft entry type the mapping writes to, and whether the mapping has a
value for it. Requires `--craft`; the target content model is what says which fields are required.

The asymmetry this answers: a legacy column with no Craft counterpart is data loss and `validate`
already forces you to declare it under `ignore:`. The reverse — a Craft field that is required and
has no legacy source — is invisible until a load rejects the entry. There are only two fixes, and
both are decisions a human makes: supply a default in the mapping, or relax the field in Craft.

Three verdicts:

| verdict | meaning |
|---|---|
| `ok` | the mapping fills it on every live row |
| `default` | nothing fills it, but the Craft field has its own default and Craft applies it to a fresh element. Not a blocker — reported because the migration is choosing that value for every affected row |
| `partial` | mapped, but the source column is empty on some live rows and no default catches them — the compiler drops empty values rather than writing them, so those rows fail exactly like unmapped ones |
| `missing` | nothing fills it and there is no default |

The `default` verdict is why the field's own settings are read and not just its `required` flag. A
required dropdown with `default: true` on an option needs no mapping work at all, and a report that
cannot tell it apart from a genuine hole sends people to fix mappings that are already fine.

Coverage is the whole target, not just the blocks named by `parts:` — nested Matrix entry types,
page entry types, `promote:` targets and the blocks the `sequence` lane emits. A heading that
arrives by absorption is credited to the sequence lane and then measured: how many live placements
actually have a Header in front of them.

Fill rates come from the legacy databases and accumulate across environments. Two things it reports
along the way, both of which are findings rather than tool errors: columns the mapping reads that an
environment does not have (the legacy databases are not one schema), and transforms that manufacture
a value out of an empty column — `background_color | variant` yields `base`, so measuring the raw
column would report a blocker that does not exist. Whether a transform survives an empty input is
asked of the real transform rather than restated here.

Every run states what a verdict costs at load time. Craft enforces a required custom field only in
`SCENARIO_LIVE`, and the loader never sets it, so nothing here blocks a load — the cost is an editor
who cannot save the entry in the control panel, and blocks that render empty. Saying so in the
report is deliberate: without it the output reads as a preflight gate, which is what its author
assumed and got wrong.

`--offline` skips the database and reports the schema half only. `--all` includes satisfied fields,
`--markdown` emits a table to commit next to the mapping, `--json` is for CI, and `--strict` exits
non-zero while anything is unsatisfied.

## Non-node tables

Not every target is a page. A Kunstmaan corpus keeps its taxonomies in ordinary tables outside the
node tree, and every page foreign key into one of them is a relation with nowhere to point until
that table has been migrated. The `entities:` lane turns those rows into entries; `ref(<Entity>)`
turns the foreign key into the `sourceUid` of the entry the row became, and `ref(node)` reaches the
node tree for the keys that point at a page instead.

`dedupe:` decides identity only — the uid, not the sites. An entity is written to the sites the
running environment maps and no others, because locale → site is a per-environment fact; a shared
entity still ends up on every site, by accumulation across the runs against its one uid.

`dedupe:` has no default, because neither answer is safe to assume. In the first real corpus the
case, news, event and insight category tables are exact clones across two databases — not deduping
them makes 28 entries where the site has 14 categories — while the blog category table reuses ids
17, 18, 20 and 21 for entirely unrelated names per database, so deduping *that* merges unrelated
categories into one entry. The mapping states which, per entity, and a missing `dedupe:` is an
error.

`single: true` covers the other kind of non-node table: a one-row config source (Kunstmaan
`AbstractConfig` — a phone number, an address, a logo) merging into the section's existing entry.
A `single:` row needs no `title:` — the entry it merges into already has one, set by whichever
contributor saved first, and the compiled payload omits the title key entirely so the loader
leaves it untouched. `children:` works on an entity the same way it does on a page or a pagepart:
a table hanging off the row by foreign key becomes nested Matrix blocks in the named field.

## Field expressions

Beyond `column | transform`, a `map:` value can be:

- `link(url, text, newWindow, type)` — the four legacy link columns that repeat across a dozen
  parts, collapsed into one button.
- `lookup(<Entity>.<column>)` — follow a foreign key to a column on the row it points at. Some
  values are simply not on the table being read: the country code a Craft Address needs is the
  abbreviation on the row `country_id` names.
- `address(addressLine1=street, postalCode=postal_code, …)` — a Craft Address element gathered from
  the columns a legacy table spreads it across. Named arguments, because an address has nine usable
  parts and no natural order; each value is a full expression, so a country code can arrive through
  `lookup()`.
- `coalesce(a | …, b | …)` — the first alternative that yields something. One target, two legacy
  columns that may each hold it.
- `ref(nodeLink)` — Kunstmaan's internal-link form `[NT<node translation id>]`, resolved through the
  node the translation belongs to. `externalUrl` is its complement: it drops that form from a column
  that holds either a link or a URL, and counts what it dropped, because a Craft Link field rejects
  `[NT115]` outright and takes the whole entry down with it.

## Determinism

`tests/CompileDeterminismTest.php` compiles the same mapping against the same database twice and
asserts the payloads are byte-identical, and that every `sourceUid` in a run is unique — that uid
is the idempotency key, so a duplicate would collapse two legacy nodes onto one Craft entry.

It needs a real legacy database, so it skips unless one is configured:

```bash
KUMA_TEST_MAPPING=migration/mapping/site.yaml \
KUMA_TEST_CRAFT=/path/to/craft \
KUMA_DB_PASSWORD=… vendor/bin/phpunit --testsuite Compile
```

That makes it a check you run against a corpus rather than a unit test, which is the only honest
way to test a compiler whose input is a 40-table Kunstmaan schema. The loader side of the same
property — a second load writing identical values, not merely finding the same entry — is asserted
in the Kunstmaan Migrator repo.

## Status

Released as part of `lameco/craft-kunstmaan-migrator` — this directory versions and ships with
the plugin (it was a standalone repo until 2026-08-21). `init`, `validate`, `doctor`, `coverage`,
`readiness` and `compile` cover the page-builder, sequence, entities (including `single:` config
rows) and redirects lanes; the forms and globals lanes are compiled by `FormCompiler` /
`GlobalsCompiler`, driven from the plugin's adapter services.

`promote:` is the same: validated against the target, never compiled. Every run counts each
declared promotion it did not emit, so a clean coverage report does not read as "this migrated"
for a collection nothing builds.

## Related

- **Kunstmaan Migrator** (`lameco/craft-kunstmaan-migrator` v2) — the thin Craft 5 plugin that consumes the
  payloads. Documents the payload contract this tool must produce.

## License

MIT.
