# Verifying a migration

Everything in this file was learned by doing it once, on a 2,248-node corpus into 15 Craft
sites, and every piece of it was written down in the *consuming project's* repository. That is
the wrong place: the next project would start by reading the last project's docs. This is the
operator-facing half, with the client-specific measurements left behind.

The short version: **read the content out of the database, not off the rendered page**, compare
per stage rather than end to end, and use the commands that ship with the plugin before writing
any harness of your own.

---

## Before you measure anything

Four constraints decide whether the numbers you get mean anything.

**Start from an empty database.** Craft never reclaims a base slug once it has handed out `-2`.
An entry that lands under the wrong parent and is later moved keeps the suffix forever, and no
re-run repairs it. Correct-and-re-run fixes field values, block content, and slugs that were
never taken — not one that was. So a verification run goes into a scratch database that was
created for it.

**`craft install` overwrites `config/project/`.** With `CRAFT_ALLOW_ADMIN_CHANGES=true`, Craft
writes the *running database's* project config back out to the repo on boot, and the installer
writes its own `--site-name` and `--site-url` first. Point Craft at a scratch database without
thinking about this and it silently replaces your committed yaml — including giving field files
new uids. `git checkout -- config/project` then `project-config/apply --force` puts the repo
back in charge.

**Do not measure by fetching pages.** `CRAFT_RUN_QUEUE_AUTOMATICALLY=true` means a page request
runs queue jobs, so measuring by rendering mutates the thing being measured.

**One migration at a time.** Craft's mutex uses MySQL named locks, which are server-wide rather
than database-scoped. Two runs against the same server — even into *different* databases —
contend, and the loser dies on the structure lock.

---

## The procedure

```bash
# 1. a clean database. A migration cannot be repaired by re-running it.
mysql -e 'create database <project>_bench'
CRAFT_DB_OVERRIDE=<project>_bench php craft install/craft --interactive=0 ...
git checkout -- config/project          # the installer wrote its own site name back out
CRAFT_DB_OVERRIDE=<project>_bench php craft project-config/apply --force

# 2. migrate. The run settles URLs itself (the closing URI pass); no resave/entries needed.
CRAFT_DB_OVERRIDE=<project>_bench php craft kunstmaan-migrator/migrate \
    --mapping=migration/mapping/<project>.yaml --legacy-env=COM --fail-on-loss

# 3. the payload side, to localise a gap to compile or to load
php craft kunstmaan-migrator/migrate --mapping=migration/mapping/<project>.yaml \
    --dry-run --entries-only --legacy-env=COM --dump=/tmp/payloads
```

**`.env` beats the shell.** `bootstrap.php` typically uses `Dotenv::createUnsafeMutable()`, so
`.env` overwrites variables set on the command line: `CRAFT_DB_DATABASE=x php craft` does
nothing. A `config/db.php` reading a `CRAFT_DB_OVERRIDE` variable is the usual way around it.

---

## Measure per stage, not end to end

```
legacy MySQL  ──►  compiled payload  ──►  Craft entry  ──►  rendered page
                   (kuma-compile)        (the loader)      (templates)
```

Comparing the rendered page against the live legacy page measures the *templates*. On a project
where the page-builder block templates are still being built, a block can hold its content
perfectly and render as nothing, and the migration scores zero for someone else's work.

So the oracle is **the content the entry stores** — `elements_sites.content` for the entry and
every nested block it owns, read straight from the database. Measuring live-vs-payload and
live-vs-entry separately then localises every gap to one stage: missing from the payload is a
mapping or compile gap, present in the payload and missing from the entry is a loader gap.

**One trap, and it will cost you an afternoon.** Craft 5 keys that content JSON by the **field
layout element uid**, not the field uid, and a placement may re-handle its field —
`commonCkeditorTitle` placed as `heading`. A reader that resolves uids against the `fields`
table silently finds nothing at all. The map has to come from `config/project/entryTypes/*.yaml`,
where a `handle: null` placement means "use the base field's own handle".

---

## Use the shipped commands first

Most of what a harness would compute is already a command, and each of them exits non-zero, so
they are gates rather than reports.

| Question | Command |
|---|---|
| Is anything in the legacy site unaccounted for? | `craft kunstmaan-migrator/mapping/coverage <mapping>` (or `kuma-compile coverage` — same measurement) |
| Will every required Craft field get a value? | `kuma-compile readiness <mapping> --craft=.` |
| Which Craft fields does *no* lane fill? | `kuma-compile readiness <mapping> --craft=. --unfilled` |
| Does any Matrix in the target reject a block the mapping writes? | `kuma-compile validate <mapping> --craft=.` |
| Can the target hold per-locale blocks at all? | `craft kunstmaan-migrator/doctor` |
| Did this run lose content? | `migrate --fail-on-loss` |
| Is this run better or worse than the last one? | `state/export` twice, then `state/diff --from= --to=` |
| Why is *this* entry empty? | `state/explain --node=COM:1285` |
| What do I show the client? | `craft kunstmaan-migrator/mapping/coverage <mapping> --markdown` |

`readiness --unfilled` is the one people skip and should not. A required field with no source is
a load blocker and shows up loudly; an *optional* field that no lane fills is invisible, and on
the reference corpus that was every hero field on every page — 37 field instances across 20 entry
types, empty on all 972 migrated pages, with no report naming one of them.

---

## What is left to write yourself

One comparison: for every heading and every text run on the live legacy page, is that text
present anywhere in the migrated entry's stored content? Report it as **recall** — the share of
the legacy page's content units that survive — with token-overlap matching and a containment
shortcut, so a legacy heading that ends up inside a longer rich-text run still counts.

Three corrections that change the numbers enough to change conclusions:

- **Redirected legacy URLs.** Following a redirect compares the entry against a *different* live
  page, and a thin retired node then reads as total content loss. Refuse redirects and exclude
  those pages.
- **Index pages compose their children.** An overview page's live HTML lists its children's
  titles and summaries, which live on the children's own entries. Counting those as loss measures
  the overview template. Report index-type pages separately.
- **The legacy snapshot is older than the live site.** If the newest `kuma_node_translations` row
  predates the fetch, some residual gaps are edits made since, not losses. Spot-check the
  residuals against the legacy database before believing them; the figures are an under-estimate
  of fidelity, not an over-estimate.

Sample ten pages per locale from `kuma_node_translations` where `online = 1`, so the sample holds
only pages the migration claims to have produced.

---

## What good looks like

On the reference corpus, after the structural-placeholder work: **97.7% URL fidelity**, up from
31.4%. That single number is worth measuring first, because it is the one an editor and a client
both notice, and because getting it wrong re-roots every descendant of whatever went wrong.

A run that reports losses is normal. A run that reports losses *and exits 0* is the defect class
this whole file exists to prevent — pass `--fail-on-loss` once a corpus has a known-good loss
count, and treat any increase as a regression.
