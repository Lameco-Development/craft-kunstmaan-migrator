# Changelog

All notable changes to `lameco/craft-kunstmaan-migrator` are documented in this
file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

Measured against the reference corpus on 2026-08-25 (`development` @
`c4f4ef2`, clean database, three environments, console run, **no
`resave/entries`**): COM lands **1,093 of 1,131** live translations on their
legacy URL — 96.6%, or 98.7% on the denominator AUDIT used (entries disabled
on a site excluded) — against 76.6% after load and 97.7% only after a resave
before the URI stage existed. LV 97.2%. Every counter this release stops
dropping read zero on that run (`writeConflictRetries`, `mediaTokenIssues`,
`deferredRefs`, `perSiteBlocksNotRepresentable`); the queue held 5,084
`UpdateElementSlugsAndUris` jobs at priority 1024 afterwards — the starved
maintenance the URI stage replaces. Details in the site's
`docs/migration/AUDIT.md`.

Speed, same corpus, COM as a full single environment on the console path,
Imagick installed: **38m 52s end to end** (entry pass 30m 16s, fixup 6m 29s,
finalize 18s, URIs 1m 49s), against 1h 14m earlier the same day and roughly
2h the night before — 1,830 created, 15,885 elements, 0 soft-deleted,
0 retries, `searchindex` untouched until the final stage. The phase timers
put the remaining half hour at 78% entry saves (410 ms average; the
`siteGroup`-propagated taxonomy entries such as the 599 FAQs at 526 ms
each, ten site rows apiece) and 20% assets (4,842 resolutions at 40 ms).
The benchmark slice that found each step is in
`docs/verifying-a-migration.md`.

### Added

- **A Coverage screen — the inverse of the mapping.** Pick an entry type and
  see every field and what feeds it: page maps, sidecars, the parts lane
  through its context fields, with required-but-unfed fields flagged and a
  roll-up naming only the entry types with holes. Backed by
  `FieldProvenance`, one computed inversion every screen answers from, so a
  screen can no longer disagree with another screen — or with the run.
- **A permanent run log.** Every migrate, finalize and fixup run writes
  started/finished/failed with its counts to
  `storage/kunstmaan-migrator/runs.jsonl` (one shared `RunLog::track()`
  envelope; counts survive into the failed event). A read-only **Kunstmaan
  migration log** utility renders the history beside Craft's own logs.
- **Re-running the wizard merges instead of clobbering.** Newly discovered
  rows join the existing mapping as open, live counts refresh, every decision
  and comment stays; "start over" remains as the explicit choice, with its
  cost stated in decided rows. This retires the failure where a finished
  mapping became a skeleton because replace was the only door.
- **The mapping screens answer live.** Choosing a Becomes redraws the field
  map without a save; the detect step rescans without a reload; the coverage
  picker swaps in place and keeps the URL meaningful; `mapping/check` is a
  button whose verdict renders inline. One shared `kumaSwap` helper owns the
  loading and error paths.
- **Rows show their context.** Page rows name the sidecar that fills each
  hero field (with an edit link); sidecar rows say how many mapped entry
  types carry each field and which drop it; every column dropdown shows three
  real sample values from the legacy table; lane tabs carry their open
  counts; the Becomes dropdown is searchable and grouped by section.

### Changed

- **No search indexing during the run; the index is rebuilt once at the
  end.** Craft extracted search keywords inline on every save — every owner
  save, every block save, and the owner again for each block whose field is
  searchable — for content nobody searches until the migration is done. A
  run that ends in the closing passes now saves with `updateSearchIndex`
  off, under the same gate as the URI pass, and a final index stage
  (`SearchIndexPass`, after the URI pass in both callers) hands every
  element the state table names — entries with their nested entries, assets,
  navigation nodes — to Craft's own `UpdateSearchIndex` jobs in chunks of
  200, without re-saving anything. The summary and the run log report
  `searchIndexDeferred` (saves made unindexed) and `searchIndexQueued`
  (elements handed to the queue). `--entries-only`, `--dry-run` and
  `load/entry` keep indexing on save, as before.
- **The unlisted-site wipe is one query per entry.** `BlockIdentity::prune()`
  asked every Craft site the payload never named for a localised entry — six
  null-returning element loads per entry on a nine-site install, ~11k per
  run. It now asks the seam once which sites the entry has a row on
  (`ElementWriter::siteIdsOf()`) and loads the entry only there.
- **⚠ One namespace, one root.** `Lameco\KumaCompile\*` is now
  `Lameco\Kunstmaanmigrator\*` and lives under `src/` with everything else;
  `lib/kuma-compile/` is gone. Kernel packages keep their CamelCase names
  (`Payload`, `Mapping`, `Target`, `Compile`, `Report`, `Command`) and
  `Legacy` is now `Source`. To make room, three Craft-side packages whose
  lowercase names PHP would have treated as the same namespace moved to
  their real homes: `payload\{PayloadEntrySaver,FixupService,RefResolver,
  SaveResult}` → `load\`, `payload\CraftSchemaGateway` and
  `compile\TargetModel` → `craft\`, `mapping\` → the kernel `Mapping\` for
  the pure editor model, `craft\CraftTargetCatalogue`, and `editor\MappingEditor`.
  The purity rule now keys on the package list rather than a directory, and
  covers the kernel's tests (`tests/kernel`). The standalone CLI is
  `bin/kuma-compile` (`vendor/bin/kuma-compile` in a consuming project, as
  before). Anything that imported a `Lameco\KumaCompile\` class needs the
  new name; the Craft commands and the binary are unchanged.
- **The Run screen moved from Utilities into the plugin's own section** —
  one workflow, one nav area. Its production guard and confirmations came
  along unchanged; every screen and action now requires the plugin's section
  permission (`accessPlugin-kunstmaan-migrator`) instead of the old utility
  permission. The section also gained its nav icon (`icon-mask.svg`).
- **A row save is refused only for damage, never for unfinished work.**
  `Schema::validateRow()` distinguishes malformation (unknown keys,
  conflicting dispositions, broken children) from completeness (no target
  yet, columns unreviewed) — the progress bar's business. Saving a fresh
  skeleton row by row, clearing a target, and keeping a column
  not-looked-at all work now; `validate()` remains the gate a run must pass
  in full.
- **One check verdict for three renderers.** The CLI `mapping/check`, the
  migrate preflight and the CP button all ask `MappingCheck` in kuma-compile;
  the CP button gained the blocks-nothing-accepts stage it silently lacked.
- **`state/export` returns an `ExportResult`** carrying rows, the exclusion
  count and its warning — both exports report exclusions, by signature
  rather than by convention.

### Fixed

- **The fixup and finalize passes run under the maintenance hold too.** The
  hold on Craft's per-save maintenance — entry-URI jobs vetoed, search
  indexing deferred — covered the entry loop and the adapters and stopped
  there: the console ran fixup and finalize outside it, and the queue's
  `ResolveDeferredRefsJob` and `FinalizeJob` never armed it. On the
  reference corpus a single-environment console run reported
  `searchIndexDeferred: 13,348` and `slugJobsVetoed: 1,935`, then grew
  `searchindex` by 23,973 entry rows during the two passes (446 patch saves,
  153 finalize saves), every one queued for indexing again by the index
  stage. Both passes now run under the same `MaintenanceGuard` in both
  callers, gated the same way — never on a dry run, `--entries-only`, or the
  run screen's stand-alone fixup and finalize buttons, which no URI pass or
  index stage follows — and their vetoed and deferred counts fold into
  `slugJobsVetoed` and `searchIndexDeferred` on the summary and the run log.
- **The fixup pass classifies a reference nothing will ever resolve, once.**
  On the reference corpus the pass ran 20 minutes for `patched: 642,
  orphans: 206` — every orphan a ref from the COM home page to a node whose
  page type the mapping declares unmapped, re-walked, re-resolved and
  re-reported on every run. When the caller states the run walked the whole
  corpus (an un-narrowed console `migrate`, a queue chain that queued every
  environment — a parameter, never inferred), a pending ref whose target
  has no state row moves from `pendingRefs` to the sibling meta key
  `unresolvableRefs` with a reason, and the summary's `fixup` block reports
  it once as `unresolvable` plus `unresolvableTargets` grouped by target.
  `orphans` keeps meaning "still pending after this pass"; `--fail-on-loss`
  counts both. A narrowed run, the run screen's stand-alone fixup button and
  `load/fixup` leave everything pending, as before.
- **A patch costs one element save per field, not one per reference.** The
  pass now resolves every pending target of an entry first (one state lookup
  per distinct target per pass), groups the resolvable refs per (site,
  top-level field), reads the field once, applies every patch to that value
  and saves once — and skips the save when the stored value already holds
  the patched id. 642 patched refs used to be up to 642 element saves and
  1,284 element loads; the number to measure is the `fixup` wall time in the
  run log and the element saves per patched ref.
- **The deferral that wrote `pendingRefs` empty, explained and closed.** An
  entry that already existed is left untouched without `--force`, but the
  saver still recorded that its references resolved — against fields the
  save never wrote. On a resumed run the parent existed by the time the run
  came back round, so `[]` overwrote the deferral the placeholder still
  needed and the fixup pass had nothing to repair. An untouched entry now
  keeps the `pendingRefs` its own save recorded.
- **The run settles Structure URIs itself.** A Structure entry's URI is its
  parent's plus its slug, computed at save time, and the parent was not always
  written first — entity-lane units precede every node, deferred `parentRef`s
  are patched at the end of the corpus, and Craft's own descendant-URI
  maintenance after a `resaving` save goes to the queue at default priority,
  behind the whole 512 chain. `resave/entries` was the only thing recomputing
  them, and it was an operator convention (76.6% to 97.7% URL fidelity on the
  reference corpus). A `StructureUriPass` now walks every Structure section
  the mapping writes into parents-first and recomputes each entry's URI on
  every site through the `ElementWriter` seam (`structureEntries`,
  `updateSlugAndUri`), straight into `elements_sites` — no element save, no
  queue. Both callers run it: the console after finalize (reported under
  `uris`), the queue chain as `RecomputeStructureUrisJob` after `FinalizeJob`,
  and the run screen offers it as a recovery pass. `migrate --resave` is now
  off by default and only there to compare against.
- **A run that settles URIs itself no longer leaves Craft's deferred URI
  jobs behind.** Every migration save is a `resaving` one, and for a
  Structure entry Craft answers it by queueing `UpdateElementSlugsAndUris`
  for the descendants at default priority 1024 — behind the 512 chain. After
  the reference run the queue held 5,084 of them, each an element save that
  queues its own descendants, all waiting for the next `queue/run`, all made
  redundant by the URI pass. A `UriJobGuard` seam (production adapter on the
  queue's before-push event, in-memory twin) now vetoes entry URI jobs while
  a run that ends in the URI pass is in progress — armed and disarmed by
  `EnvironmentPipeline` for both callers, never for `--entries-only`,
  `--dry-run` or `load/entry`, so an interrupted or narrowed run keeps
  Craft's maintenance — and `StructureUriPass` releases the ones still
  waiting once its walk is done, since a batched queue run is many
  processes and a handler in one is gone in the next. Search-index jobs and
  other element types' URI jobs are never touched. Reported as
  `slugJobsVetoed` and `slugJobsReleased` in the summary and the run log.
  The run screen's recovery passes now push at 512 through
  `QueueHelper::push()` like the rest of the chain, so they no longer wait
  behind that backlog.
- **A deadlock no longer commits a partial entry.** The writer adapter
  retried the one element save that hit a 1213 deadlock, inside the entry's
  transaction — which InnoDB had already rolled back whole. The retried
  element then committed on top of an entry whose primary save, state row
  and earlier site rows were gone, and the run reported success: a partial
  entry the state table cannot describe. The adapter now retries nothing;
  `run\WriteConflictRetry` re-runs the whole payload save (bounded, with
  backoff) for both callers, counts each retry as `writeConflictRetries` in
  the summary, and when it gives up the problem names the sourceUid and says
  the entry was rolled back whole. A file an asset ingest already copied into
  the volume before the rollback is not undone; the next run re-ingests it
  under a conflict-avoiding filename, as before.
- The mapping YAML is parsed once per request instead of per question (the
  coverage screen alone cost ~2N+4 full parses for N mapped entry types).
- `RunLog::entries()` reads a bounded tail instead of the whole append-only
  file.
- The unit suite runs clean: the export builder no longer needs a booted
  Craft to count its exclusions, null-plugin property reads are guarded, and
  the deprecated `setAccessible()` calls are gone.

### Internal

- A phpstan rule holds the kuma-compile boundary mechanically: nothing under
  `lib/kuma-compile` may reference Craft, `craft\*`, `yii\*`, or the
  plugin's own namespace.

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

## 1.2.0-beta.6 — 2026-09-04

Three Trello reports (#159, #137) turned into one shared engineering gap and one wrong
cross-lane rule, built on `release/1.2.0-beta` (1.2.0-beta.5).

### Added

- **`switch:` — documented since `MAPPING-DSL.md`'s first draft, never implemented.**
  `PartRow::switchCases()` already parsed a part's `switch:` cases; nothing evaluated one.
  `BlockBuilder::build()` read `spec['block']` as a static string and returned early when a
  switch-only part had none, and `Compiler::blockFor()`'s "does this part have a block" gate
  read `PartRow::block()` (singular — also null for a switch part), so a switch part was
  skipped before `build()` ever ran. Both fixed: a `when: children.any(item.<col> != null)`
  / `children.all(...)` condition is evaluated against the same child rows every case reads,
  the first match wins (`else:` is the catch-all), and the winning case's own `map:`/
  `children:`/`firstChild:` replace the part's shared ones outright — the two blocks a
  switch chooses between rarely share a field's shape. Mapped in enreach-craft-website:
  `Feature`, whose highlight items mixed real photos and small icons under one
  unconditional `iconCardsBlock` target — a photo now gets `cardsBlock`, whose `image` slot
  is not sized for an icon. Verified against the live corpus: 12 iconCardsBlock instances
  stay icon-based, 41 switch to cardsBlock.

### Fixed

- **A part class could not be claimed by both `parts` and `forms`, and the two lanes never
  actually compete for a row.** `FormCompiler::compile()` reads its own `PartReader::sequence()`
  scoped to the `form` context (or the few pages `forms: overrides:` re-scope to `main`); `parts`
  compilation only ever reads `contexts()`, which `defaults.contexts` excludes `form` from by
  construction. So `Header` — a `consumedBy: sequence` heading absorbed into a Page Builder
  block wherever it sits in `main` — can independently be a Formie `heading` field wherever it
  sits in `form`, and the schema's blanket cross-lane collision check refused that as
  ambiguous. `parts`/`globals`/`unmapped` still share one collision bucket — those three do
  compete over the same context space; `forms` does not, and is checked separately now.
  Unblocks mapping `Header` into `forms: fields:` for PotionsLandingPage's "Book a Demo"-style
  section titles, previously dropped with no lane at all.

## 1.2.0-beta.5 — 2026-09-04

Built on `release/1.2.0-beta` (1.2.0-beta.4), from a Trello report (#215) that
`casePage`'s `body` and `results` fields arrived empty on every case.

### Added

- **`prose:`, a new page-row key, for an entry type with no Page Builder.**
  `casePage` (D29) reads its narrative from the same kind of pagepart
  sequence any other page does, but `contexts:` streams a sequence into
  Matrix blocks — and `casePage.pageBuilder` does not exist, so every part
  was dropped. `Compiler::site()` had said as much in a comment
  ("casePage and partnerPage carry their own structured fields instead")
  without doing it. `prose:` reads a context's sequence and concatenates it
  into one HTML string for a plain field instead: a `Header` part folds
  into `<h#>` (unwrapping the `<p>` Kunstmaan's own widget stores its title
  in, so it does not nest inside the heading), a `Text` part contributes
  its own `content | ckeditor`. Any other part class in the sequence — a
  case's inline `Quote`, `RawHtml`, `Button`, `ContentMedia` and others,
  measured on the Enreach corpus — is skipped and counted per type, the
  same visibility a block type a field disallows already gets, rather than
  silently dropped as before.
  `results` stays unmapped: nothing in the legacy sequence marks where a
  case's narrative ends and its results begin — the headings that occur
  ("Summary", "Background", "Communications needs", ...) are per-case
  prose, not a consistent section title — so recovering it needs an editor
  to split the migrated `body` by hand, not a pattern the mapping can
  infer.

## 1.2.0-beta.4 — 2026-09-03

Found re-verifying 1.2.0-beta.3's redirect fix against a full three-environment
re-migration — a second, related bug in the same lane, built on
`release/1.2.0-beta` (1.2.0-beta.3).

### Fixed

- **A redirect could be silently overwritten by a same-named redirect from a
  different legacy environment.** `kuma_redirects.origin` and a section-move's
  legacy URL are plain path strings with no environment marker, and every
  Retour redirect the migrator wrote (or looked up) used `siteId = null` —
  Retour's own "applies to every site" bucket. COM's own `en`-locale
  "enreach-contact" page and LV's separate `en`-locale "enreach-contact" page
  both normalise to `/en/products/enreach-contact`; migrating LV after COM
  silently overwrote COM's correct redirect with LV's own, because nothing
  told Retour the two were for different sites. `upsertRetourRedirect()` now
  takes an explicit site id and passes it to both the existing-row lookup and
  the saved record; threaded from every call site that can derive one.
  Verified against the live corpus: the two environments' redirects for that
  path now coexist as two site-scoped rows instead of one clobbering the
  other.

## 1.2.0-beta.3 — 2026-09-03

Found re-verifying 1.2.0-beta.2's fixes against the full three-environment Enreach
migration — a sixth cross-environment id-collision bug, the same class as
1.2.0-beta.2's homepage fix but in the redirects lane. Covers the `@enreachComBaseUrl`
/ wrong-locale redirect symptom behind Trello #137, #152 and #157.

### Fixed

- **A `kuma_redirects` row's destination could resolve to an unrelated entry from a
  DIFFERENT legacy environment.** `kuma_node_id` is only unique within one
  environment's own database — COM, DE and LV each restart their own numbering.
  `RedirectMigrationService::resolveEntryIdForLegacyNode()` resolved a redirect's
  destination via state sources with no environment in the lookup key at all
  (`"page"`, `"singleton"`, the bare legacy class name), so a coincidental id match
  from a different environment's own migration could win. Measured: COM's
  `/en/products/enreach-contact` redirect resolved to LV's own separate
  "enreach-contact" product page. Fixed the same way `NavigationMigrationService::
  resolveEntryIdForNode()` already handles it: try the environment-scoped
  `"<ENV>:kuma_nodes"` source first.

## 1.2.0-beta.2 — 2026-09-02

Five fixes found running the full three-environment Enreach migration for the first
time and comparing every reported Trello ticket against the result, built on
`release/1.2.0-beta` (1.2.0-beta.1). Covers Trello #148, #183, and the raw-token
link defects behind #142/#185/#209 and the new-since-beta.1 report finding — the
cross-environment homepage clobber discovered by that same comparison pass.

### Fixed

- **Running a second legacy environment's migration after a first could wipe the
  first environment's Matrix block content on any Craft Single shared across every
  site (the Enreach homepage, most visibly).** `BlockIdentity::prune()` asked
  `ElementWriter::siteIdsOf()` for every Craft site the owner entry has a row on —
  install-wide, not just the environment currently running — so DE's own save saw
  COM's five sites as "not in this run's keep-list" and hard-deleted their real,
  independently-propagated blocks. `prune()` now intersects candidate sites with
  the `SiteMap` already threaded through every call, scoped to the running
  environment; a site outside that environment's own mapping is never a deletion
  candidate.
- **A remote-video `kuma_media` row (Vimeo/YouTube/Dailymotion oEmbed) mapped with
  `| asset` was silently dropped, even though the loader has carried remote-video
  support since 1.2.0-beta.1.** `MediaIndex` only ever indexed rows with a `url`,
  and a remote-video row has none by construction (its target lives in `metadata`
  instead) — `pathFor()` always answered null for it, and `BlockBuilder` read that
  as a dangling reference. The `asset` transform now recognises a remote-video row
  via `MediaIndex::isRemoteVideo()` and emits an id-shaped marker the loader already
  knows how to resolve, instead of a path that was never going to exist.
- **`mergeColumnGroups()` concatenated one context's rows before the other's**
  instead of interleaving them row by row, so a section with two stacked rows per
  side (`middle-left`: A, B; `middle-right`: C, D) produced columns paired (A, B)
  then (C, D) instead of the legacy (A, C) then (B, D).
- **An unresolvable `[NT<id>]` link in the `globals:` footer-navigation lane wrote
  the raw token as the link's URL** instead of falling back to the node's manual
  `kuma_redirects` target, the way every other link on the page already does since
  1.2.0-beta.1 — this lane builds its links through a different path
  (`GlobalsMigrationService`, not `BlockBuilder`) that never got the same fallback.
- **A `[M<id>]` media/document token embedded inside an otherwise-complete URL
  (Kunstmaan's "secure download" link builder, e.g. a PDF's
  `?token=[M2317]`) was written to the migrated site verbatim.** `oneLink()` only
  recognised `[NT<id>]` filling a URL's *entire* value; a `[M<id>]` token embedded
  mid-string now resolves the referenced `kuma_media` row directly (through the
  same JIT asset pipeline `| asset` uses) into a real Craft asset link, discarding
  the legacy download-token plumbing it has no equivalent for.

## 1.2.0-beta.1 — 2026-09-02

Twelve fixes for the Enreach migration, built on `release/1.1.0-beta` (1.1.0-beta.2 in
production), covering Trello #137, #141, #142, #143 (mapping-only, not in this tag),
#145, #148, #149, #150, #151, #152, #155 (mapping-only, not in this tag), #157, #163,
#169, #183, #185, #186, #187, #188 (mapping-only, not in this tag) and #189
(mapping-only, not in this tag).

### Added

- `columnGroups:`, a new top-level mapping key: two or more simultaneous contexts
  (`middle-left`+`middle-right` on ProductPage) collapse into one block with one
  Matrix column per context, instead of each context becoming its own block in
  mapping-declaration order.
- `firstChild:`, a new part-row key: the same row shape as `children:`, but only the
  first row (in existing order) merges flat onto the parent block instead of becoming
  a nested Matrix — for a legacy slider/gallery kept for its first image only.
- `append:` and `enabledField:`, two new per-context keys alongside the existing
  `prepend:`: `append:` places a context's blocks after everything else, even the
  form block; `enabledField:` sets a boolean page field to true the moment that
  context actually produced a block. Both keys are now validated (unknown keys,
  wrong types) the same way `prepend:` always should have been.
- `translatorFallback('<key>')`, a new pipe-transform reading `kuma_translation` for
  the compiling translation's locale — a Kunstmaan pagepart's rendered label (a
  Symfony translator string, not a database column) as a fallback when the part has
  no title of its own.
- `overrides:` on the `forms:` lane: a page-type-specific context (or list of
  contexts) instead of one context for every page type, for a page type whose
  form fields sit in a different region than the rest.
- An unresolvable internal link (`[NT<id>]` addressing a deleted node, or a
  `RedirectPage` that never becomes an entry) now falls back to the node's manual
  `kuma_redirects` target as a plain URL, instead of dropping the link — and its
  label — entirely.
- `doctor`'s block-propagation check now also walks `sidecars:` fields, not only
  page `contexts:`, so a `propagationMethod: all` field fed from a sidecar (a hero
  button, say) is flagged before a run instead of only after one.
- `IntrospectionCheck` gained a fourth check, `unclaimedOwnedCollections()` — an
  owning one-to-many association (a page's own child collection, not a join table)
  the mapping never reads at all, the shape `productBrandItems` on `ProductPage` is.
  `editableColumns()`, dead code since it was written, is wired in as a fifth.

### Fixed

- **A row with no substance of its own vanished, heading and all.** `BlockBuilder`
  correctly stopped emitting a block whose every field came from a fixed literal
  (`contentMediaVariant: 'band'`) — but the check ran before the sequencer's absorbed
  heading was merged in, so a Header absorbed into an otherwise-bare block took the
  whole block down with it instead of migrating with that heading.
- **A Potions page's `text` context, and the form after it, landed at the foot of
  the page instead of near the top.** `prepend:` existed and was read by nothing for
  `text`; the form block was always placed last regardless of what came before it.
- **`SeoMigrationService` scoped a `kuma_seo` lookup to whichever environment's pass
  happened to be running, not the environment the entry actually came from.** On a
  shared Craft site fed by more than one legacy environment, the later pass's SEO
  data could silently overwrite the earlier pass's, on an unrelated page.
- **A failed remote-video embed recorded itself as permanently resolved.** The
  placeholder state row used `targetId 0` for "no asset yet"; every caller's
  already-resolved fast path read that as a real id rather than as "try again."
- **`RedirectIndex` never matched anything on a multi-locale environment.**
  `kuma_redirects.origin` carries a locale prefix (`/nl/...`) that
  `kuma_node_translations.url` never does; the index now strips it the same way the
  loader-side `RedirectMigrationService` already does, for the same reason.
- **`mergeColumnGroups()` could drop held-back blocks outright** if none of them
  carried the group's named column (a `column:` typo, most likely) — they now fall
  back to normal placement instead, with the reason recorded.
- **`TargetCheck` let the schema's own single-nested-type guess outrank an explicit
  `block:` on a `children:` row**, the opposite of what the compiler itself does, and
  never validated a child row's own nested `children:`.
- **A comma inside a `join()` separator's own literal split the separator in half**
  instead of being read as part of it (`splitArguments()` tracked parenthesis depth
  but not quotes).

## 1.0.0 (2026-08-24)


### ⚠ BREAKING CHANGES

* v2 migrator — payload loader, compile engine merged in, URL fidelity 31% → 98% ([#15](https://github.com/Lameco-Development/craft-kunstmaan-migrator/issues/15))

### Features

* **08-01:** add buildTaxonomyRow + buildDataProviderRow to MappingFile ([ac3b8fc](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ac3b8fcb9744806838ae035148e715b1a3893e1f))
* **08-01:** add kind=taxonomy audit branch to MappingAuditor ([7793506](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7793506b459520d62d5f946d7919fa456e82c79e))
* **08-01:** scaffold compileTaxonomies + Phase 8 _compileReport counters ([e165e42](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/e165e42c13deae86d79ad95f8e7615001f4176f8))
* **08-02:** scan Gedmo namespace and surface isGedmoTranslatable flag ([4ca1e17](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4ca1e1796a7a0661bb76c275cfc92135c5e2894a))
* **08-03:** add renderTaxonomiesMarkdown to KnowledgeBase ([3f1e2b5](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/3f1e2b5cd9cc378d80c509ee877ca617c9b5994c))
* **08-04:** restore extTranslationsFor + EXT_TRANSLATIONS constant ([d82ecac](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d82ecac38802a85e188257096adc8152e319464d))
* **08-05:** add proposeDataProviders + chunk private (D-13) ([953fefa](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/953fefa9c4aa7fd1e00f8fc4d5c27a893a05a271))
* **08-05:** add proposeLayoutBlocks + chunk private (D-12) ([8ed42c8](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8ed42c885d93fadd87a69ac80705b6edbb8d9ac9))
* **08-05:** add proposeNonPageEntities + chunk private (D-05/D-06) ([9acd4ef](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9acd4ef9da2753b6451a373786fee7656ac1621b))
* **08-08:** AnalyzeController flags + 3 new proposer dispatch steps (D-14) ([ef602ac](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ef602acca81515cdb07492f15bfa0d4dcb26af4e))
* **08-08:** Settings::proposeLayout + proposeProviders booleans (D-14) ([38943de](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/38943de03b55fe7e0e5a64f38e94907e1543c543))
* **08-09:** surface taxonomies/layoutBlocks/dataProviders counters in CompileController ([ca137f1](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ca137f1b60cfb5678affe97140f607ca72913489))
* **08-09:** wire MappingCompiler compile passes for taxonomies/layout/dataProviders ([d3cb7c2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d3cb7c21bc3eed3dc074314c28cfb1508084f2a8))
* **08-10:** MigrationFilters auto-includes taxonomies via relation-graph reachability ([f6bd945](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f6bd9457c97e9e1995f96de0d3ccb8daf8703f2f))
* **08-11:** port TaxonomyMigrationService with 5 v2 reshape points ([5ef4105](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5ef4105fe5c9a9cfa3e17f9f2fcfa05131fe40ce))
* **08-12:** wire taxonomies stage into MigrateController (TAX-08) ([8c24cb3](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8c24cb3c55d0b6a9c96098325e9af618f09b6617))
* **08-12:** wire TaxonomyMigrationService into Plugin DI ([f466d5f](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f466d5f5d53f690acd3043714015d6b97dce509c))
* **08-13:** _settings.twig AI H2 group with proposeLayout + proposeProviders (D-14) ([358b3c7](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/358b3c7b9d265e1631c23f2f0b84f5486636e1e8))
* **08-14:** add 11th doctor check checkExtTranslations (TAX-09 / D-09) ([30c3b1e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/30c3b1e9b306866c1821e6a9ea7654fefd73719d))
* **08.1:** D-05a — exclude PagePart classes from proposeNonPageEntities ([28c781c](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/28c781cb02727021817217a6063692065e3bdf87))
* **08.1:** D-07a — defensive compileTaxonomies skip on incomplete rows ([5afd01d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5afd01da271d6f395853a8a7fb9fb6de73e2ddf6))
* **08.1:** D-08a — TaxonomyMigrationService soft-skips incomplete rows ([64cf6ec](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/64cf6ec61b144bc90172ff6cf18ff4487f13f2dd))
* **08.2:** D-15 — CraftKnowledgeBase exposes Matrix sub-entry-type sub-fields ([41b5086](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/41b50862aad1fc0ffa5202e65ce2008eea3a72d5))
* **08.2:** D-15 — TransformService collapses dotted-path target handles ([d4503c2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d4503c278c98bd31a8003fdbae031f52475aa478))
* **08.3:** D-16 — derive pageBuilderHandle from accepted page-part rows ([a429877](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/a4298778277e19f48313eff132ed517766ad4586))
* **08.4:** D-17/D-18/D-19 — three coupled fixes for migration completeness ([6e1726a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6e1726a087b0d20f112bf0450029eb47e7454038))
* **08.5:** D-20..D-24 — FK-relation introspection + extract joining ([c905142](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/c905142c20fcf1d4326bd72a7fcfce51f7322415))
* **08.6:** D-25 — parent-aware Matrix selection (homepage unblocker) ([2e54a52](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/2e54a5296f3a2f05aecf9003d79917be6ca290d5))
* **08.6:** D-26/D-27 — per-pagepart column proposer + sub-field catalog ([5a7a7bd](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5a7a7bd461dbe53f84f8e1b9b1403ae11cb902c4))
* **08.6:** D-28 — enrich block-field metadata in LLM prompt ([7613f22](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7613f222e4d4c6916c413b152364b475f8ce4248))
* **08.6:** scope analyze proposer steps by --entities ([7f0d500](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7f0d500e772c3cc815859ee3d111f184fde543d0))
* **08.6:** scope load stage by --entities (close stale-payload leak) ([6b4dd96](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6b4dd96de6692891cf42b31aa6fc1ae7ab2af25a))
* **08.7:** D-29 — info_schema-based M2M join-table auto-discovery ([5e94524](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5e9452458150efc6569aba2e6696837494532c5e))
* **08.7:** D-29/D-30/D-31 — relation-aware page-part field proposer ([bdfa411](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/bdfa411165d9553b4ee9a33166ba97ad6975e333))
* **08.7:** D-32 — auto-fill relation handlerOptions.stateSource at compile ([dfa9751](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/dfa9751b444b1415d13b0ef69405f75252ae2455))
* **08.7:** D-33 — implicit-content rows reach page-part fields LLM ([4d171a3](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4d171a3c6e0a8d8aa355ea3b237503632cd72edc))
* **08.7:** D-38 — flat page-part content fold for matrix-less parents ([1ce3cf8](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/1ce3cf87f155c8a9a4b9dacc28ab99aacc916c18))
* **08.7:** D-39 — auto-detect flatPagePartContent at compile ([f6e48fa](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f6e48fac3732de8665309686a3e396ad124a8002))
* **08.7:** D-39 — auto-detect flatPagePartContent at compile ([070ffd3](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/070ffd34349a6658088f75ff5baf38eeb925aeaa))
* **08.7:** D-40 — compile-stage targetHandle validation against entry-type catalog ([fbe2f6e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/fbe2f6e761082a89afbc3f6cd57410ed5f2b6b72))
* **08.7:** D-40 — compile-stage targetHandle validation against entry-type catalog ([9690e0a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9690e0a7def9bef5aa32d230502601abd273d1a4))
* **08.7:** F1 — page-wins auto-folding for ManyToOne 1:1 wrapping pairs ([d191dd1](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d191dd1048b4489f671f0424a46203d1fa0edd84))
* **08.7:** F1 — page-wins auto-folding for ManyToOne 1:1 wrapping pairs ([9a0b39d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9a0b39d2dc41599690932c4da16d13ef0e38a111))
* **09-01:** fail migrate on missing compiled blocks ([25a7ce9](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/25a7ce943740f2d0ff21392e8dfc16939776f1be))
* **09-01:** preserve mapping blocks during merge ([02b892b](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/02b892b9c288d6c90bb7af8840899daa782d11ff))
* **09-02B:** apply translated filters to Craft query surfaces ([6ebb13a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6ebb13ac3b340f71d19a2b496015a7c056801c93))
* **09-02B:** implement mapping filter translator ([3a7df8f](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/3a7df8f00239ef2664e7c8be11515f86ea4bdf1c))
* **09-02C:** wire source filters across runtime stages ([afee318](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/afee3184a09a4efe74c2d4586c4ce1c12ef85ac7))
* **09-02:** normalize source entity filters ([fbf3996](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/fbf3996acc3b4fda70554adbc055b65ce94ad19b))
* **09-02:** wire relation graph into filters ([f8b5f1a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f8b5f1a2633601d134d0349d9bee53f0a6591fc9))
* **09-03:** harden compile target validation ([762eed6](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/762eed6712adbfbe8c6a14c103e1a3fd2a78b194))
* **09-04:** implement Page-rooted coverage auditor ([8738693](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8738693df3ab79699b64bf7934155745de9a46a0))
* **09-04:** implement Page-rooted surface discovery ([8fb6779](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8fb6779646c8fc4097d0441a43816d4b5b9eba8e))
* **09-04:** write Page-rooted coverage artifacts during compile ([4f48fd4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4f48fd45999f8f92b244eea3266d3051167a399e))
* **09-05:** fail migrate when report records failures ([0b3ef6e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/0b3ef6e3302b6071713244a13f5a91fb34e82903))
* **09-05:** preload only referenced asset ids ([ced731e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ced731e045f748fc692f932686f66db7263e71df))
* **09-06:** encode unresolved marker sources safely ([b3e6f8f](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b3e6f8f94b3c8d52e0ba84a7b16bb537b2d29aec))
* **09-07:** add structural source shape audit ([20821fd](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/20821fd8a894b8e592ec150ba62f7df793a23644))
* **10-01:** block load-fatal target mappings ([fc7ceaa](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/fc7ceaa5074a8911740f9838f0ec7226c51171b9))
* **10-01:** validate pagebuilder matrix ownership ([be3cd0b](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/be3cd0ba44fb55570891d191adb70e494644a1c2))
* **10-02:** add Matrix native title fallback ([8efc0b2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8efc0b278fc4659e17c17b7cfa4fb5a17e2b0656))
* **10-02:** add sparse locale primary save fallback ([19b1701](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/19b170101aad6c297b58198aaeb42c8139810ad6))
* **10-02:** render load fallback report rows ([48b6cef](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/48b6cef6e0959ccd5891ee96aebcbe9f79639f3a))
* **10-03:** delegate taxonomy relation misses ([25da6c3](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/25da6c349a3d5353959d801103440bca47e5599f))
* **10-03:** extract taxonomy lazy resolver ([dad9dd7](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/dad9dd7312fe1160313e9e657588655e3431cb91))
* **10-03:** make full taxonomy import opt-in ([8b2be56](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8b2be56a8db41bbf36ea0f255afb72b88d02eb24))
* **10-04:** split verify count domains ([0e85fb4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/0e85fb42d41b52b0d42a8e5da329d381eeb8a466))
* **10-05:** merge transform sentinel warnings into migration report ([f19d513](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f19d513f68834a40ae14f1a04b01b40e125cb0b5))
* **10-05:** resolve taxonomy fallback site handles from mapping values ([47b9b4e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/47b9b4ecf622b8b4f52c48205fdeef2102d9bb7f))
* **10-06:** add finalize token diagnostics ([2ddca4a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/2ddca4a2f6e40862a1a7a51402822e4f86314d3f))
* **10-06:** block unresolved finalize output ([cd7e371](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/cd7e371f21650cdec6fbf144f4f7ef721c2d9ce6))
* **10-06:** make page-rooted coverage evidence-based ([41cb413](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/41cb4134964c4e269449ebcc56c6e7eecdb601eb))
* **11:** add Craft entry walker ([3e636d4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/3e636d40141cef7241d56a6093f2e83e8cfde548))
* **11:** add Kunstmaan page walker ([eb17483](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/eb1748358c705f1ee02b6370f5b0aed265ab403c))
* **11:** define graph contracts ([52a9b72](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/52a9b727be71e7811060b2a05354ea6629183b5a))
* **11:** report graph relation coverage ([594194a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/594194a4dc02947c7dcbf4f097c3fb92478a8ac4))
* **11:** support promoted relation targets ([6002ec4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6002ec46341c6887bf84216adcf204959c392c65))
* **11:** validate graph-compatible mappings ([65bcee5](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/65bcee5b876cc6f0c914ddf5816b57379a773020))
* **11:** wire graph artifacts into analyze ([d7d77ce](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d7d77cee5b5a7caf5cdc4181f99933cd5fb3b2c9))
* **12-01:** add migration run schema and record ([6edd293](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6edd2935523a84bd3a80c98f058ac46761ca0891))
* **12-01:** implement migration run lifecycle service ([84142f8](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/84142f8c95b67835dc4a147ec3d96f600be47403))
* **12-02:** extract analyze workflow service ([7076b6f](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7076b6f2682b4e4c1a6449433651de0c41f3a314))
* **12-02:** extract compile workflow service ([835da9a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/835da9a83b188170972fb87caa7343258be14b02))
* **12-03:** extract migrate workflow service ([1df0082](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/1df00829644b34a1ad6219cc08b439a3db8d7690))
* **12-03:** extract verify workflow service ([d661143](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d661143d26abbbd74ef46fed2151898141b51010))
* **12-04:** add batch mapping actions ([fbad346](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/fbad346b5b072b22011622372b3533bdb6ce1a77))
* **12-04:** add mapping review filters ([ee4ffd4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ee4ffd4bc341b7b776bc78873ef745c2f5fca2ea))
* **12-04:** update mapping review UI ([5151858](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5151858b21b7c15ee4de1a3bcedf2e0cd93d2300))
* **12-05:** add stable CP execution settings ([f4f74ef](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f4f74efb8b2a00543650fe5ae77483ccd7682b52))
* **12-05:** slim CP settings to stable groups ([ee7d272](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ee7d2728268ac8a242548c71dfd197f527316fa7))
* **12-06:** add CP and job production guard ([539a1be](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/539a1be03693351e4c374a2b99be85da9f5372e8))
* **12-06:** add structured gate result value object ([d2bf7d3](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d2bf7d39d8a3b3c67c5b5b9583d9fe090e63a664))
* **12-06:** implement migration gate service ([df7e63a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/df7e63a67c0d5339953773890b7adbb3ed316329))
* **12-07:** register phase 12 plugin components ([26c78a4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/26c78a463fcd58456bc68814ee086fd6f02a5ea6))
* **12-07:** wire phase 12 gate dependencies ([85e5ac6](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/85e5ac6e69c69df20dd4415dfbeb787287482f22))
* **12-08:** create serialization-safe stage job ([06d6ea6](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/06d6ea60bbb0bda2f80d9e1b3dfd7791289aaf3f))
* **12-08:** create staged migration pipeline job ([ca95e2f](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ca95e2faa3ae96a4a3b95620b9abaa3bec776efe))
* **12-09:** add admin-only CP queue endpoints ([cf82770](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/cf827703719903dde2414ce3430c48f95509f84c))
* **12-09:** create migration console view model ([7a20b2e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7a20b2efa46b0b19464d45aada8f59a1c315afd0))
* **12-09:** render migration console utility shell ([ed7feea](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ed7feeaf93daf5fd1ba7d7d6c2fcc774c41f37a7))
* **12-10:** build console shell readiness analyze compile templates ([9fde9fa](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9fde9fa9cc52afb4b965c7f9555417fab9713192))
* **12-10:** build dry-run live controls and danger zone ([f781c51](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f781c514468eb91e40f6d5563c5531bbca566afc))
* **12-10:** build mapping runs and reports templates ([231b593](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/231b593e8f52138fc84a85c0f765106ca7fd594a))
* a run says what it is doing while it does it ([d9c10ec](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d9c10ec604e8c748052a1554c3024dd22762d9f6))
* a second seam at verbb, so Navigation is testable end to end ([a6e7148](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/a6e71484f8cccec2e455af581a083c66924fbc75))
* a settings screen, with the credential field that cannot leak ([6779b01](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6779b01cc1fbdc2012392efd38963373467eeef4))
* a utility to preflight and queue a migration ([929a665](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/929a665cf0aee95258c95b58be1a590ed91759d0))
* an adapter owns its configuration, and can actually be switched on ([715e3f2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/715e3f2d95f6a9221038076ee902792467e49373))
* an empty install invites you in, and the editor says how far you are ([f4445a5](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f4445a5aa482bda9903eca0f9e248f07c7497104))
* **analyze+compile:** AI page-part proposer + configurable fallback ([dcfa53c](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/dcfa53c285257f773fa7195ed439f7168f4c9607))
* **analyze:** AI picks Craft entry type (entity-level LLM mapping) ([a42d5d0](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/a42d5d09c4e01a5c238db6bdcc5545a2c768b0ec))
* **analyze:** live progress bar over LLM batch loop (Yii Console helper) ([83017af](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/83017af31bb69109f61bbdbe0fae209faf406ec2))
* **analyze:** synthetic page-part proposer for content-only pages (Phase 7 part 2) ([5da9175](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5da9175dc7c323370fb39f5c1f84948f660cac83))
* **compile+extract:** wire implicit-content blocks end-to-end (Phase 7) ([a5dfc22](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/a5dfc2297094240107e5b148147a86027dae6ab1))
* **compile:** bridge proposals[] → nodeClasses + sections + sites ([13444c0](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/13444c0ff832b2a294782efb3b440e746cbff7f9))
* **compile:** validate compiled section handles against real Craft entry types ([95d2816](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/95d2816b8fae5ee229013909f7c2984cbb6724f1))
* configure relation mirror rules ([b6584ed](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b6584ed63cb5340c739c0497e6751d6e2ad14bee))
* e2e-verify the migrator against the Enreach corpus, then production-harden it ([b90a16e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b90a16eb9864fa8a07bce2663091a4f225b1b08a))
* edit the mapping from the control panel, one row at a time ([9417940](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9417940f58865c5e650463e77765898988f3407f))
* every console action reachable from the control panel ([f292198](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f2921986fd309c2fa4d5df5cc0fab970da18e5d0))
* **fallback:** page-part fallback + CP form inputs + 3-state semantics ([23139b2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/23139b20864ff6a6260b5e70d20c22cf2c124d98))
* generalize locale-driven adapter lookups ([7139bca](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7139bca1da2ffaeac4f883e91356c28025a19629))
* harden page-rooted migration pipeline ([181b7b3](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/181b7b350401fad003990731a32c70e37f15b2bd))
* infer generic fallback content blocks ([0b84a7d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/0b84a7df8ff1c833fb4975ce33495a339dc14051))
* making a mapping is part of the plugin ([eeeff1d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/eeeff1d4cacd4b06baa0f8bdf76b7664ad790ca4))
* **migrate:** --limit=N + --only-id=N debug flags ([ae9b0bf](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ae9b0bffd75b146ec0f464de0f290653694e52e9))
* **migrate:** live progress bars for extract / transform / finalize stages ([43ba917](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/43ba917e7b08717b02b922d1f99efaf3c4ef82c9))
* Navigation across the seam, and what the seam does not reach there ([2a5ef54](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/2a5ef5451b89f41ba52026461f6ff78e5d9816ab))
* one adapter gate and a registry, instead of the same check four times ([676bd9d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/676bd9ddf1a41e40c5e2b902c433a4e4b705acd6))
* one pipeline both the console and the queue can run ([f3747c5](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f3747c5afccc4fae1d427fad2ff4b320571d6a69))
* phase 12 CP migration console + site profile fallbacks ([8ad1351](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8ad1351ec9b7049ec7bec82b83338eb7639fe35f))
* put a seam at the Craft write boundary, and use it in EntryMigrationService ([3e96f07](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/3e96f07791e3f293b3492e9e1a1be49ad4344642))
* setting up is four questions, each answered where it is asked ([b766da2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b766da2556f084efbefb61b44aca7dd668640fd0))
* the adapter registry becomes an execution list ([8ad8433](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8ad8433676441dd56302b7ad72562a39dcc6aa66))
* the editor covers every lane, and the diff is only the decision ([f8f6e2f](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f8f6e2f3de70a27266e94809e8644d42eacb8225))
* the field map is two choices, and saving a row you did not edit is free ([360312a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/360312a2013326c0c129ce5733a2092b3e5f8f70))
* the forms lane, built on the two abstractions that made it expressible ([116fd80](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/116fd80847ff28552aaf6944275dcb8843ec682e))
* the globals lane, and a target the mapping decides ([71da47d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/71da47d174a4234e7dda83c2622e6d64a0d8641c))
* the last three modules across the seam, and a test that keeps them there ([a0379e7](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/a0379e767b69af0ab1c6ec7022b1a92a01133863))
* the mapping becomes something you can edit, not just read ([1689118](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/168911847cc3a51c25b53dcbc2ef832b295718f8))
* the site map is a value passed per call, not state left on a singleton ([dbab39c](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/dbab39c8859848d179ca8a849709da78e69486da))
* v2 migrator — payload loader, compile engine merged in, URL fidelity 31% → 98% ([#15](https://github.com/Lameco-Development/craft-kunstmaan-migrator/issues/15)) ([6eb6ea2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6eb6ea226f2859203bc57bc472fc37ca307a64fd))
* which environment is running is a value, not ambient state ([0db191c](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/0db191cfea7e2130237e9becde4cf72d0cb1f4af))


### Bug Fixes

* **02.1:** IN-04 emit Craft::info for unresolved page-entity table mappings ([c93983b](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/c93983bc2d46cd2eef742e3c9954168081f591c8))
* **02:** IN-01 fence + sanitise residual samples in LLM batch prompt ([3b49c94](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/3b49c9496863bf206af2e0302961d74967b91207))
* **02:** IN-02 warn when targetKbMarkdown is truncated for LLM prompt ([4e32149](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4e3214958f905b10bc98f951b9e08d86ea6bb8ef))
* **02:** IN-03 throw when legacy DSN omits dbname instead of returning empty ([d432089](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d432089f3af86cc65eef361ff27df6f9937c0760))
* **02:** IN-04 document preg_replace null-coalesce invariant with assert() ([df14cb4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/df14cb43631d155fa00fc03b7d9dae79c5e28844))
* **05:** MEDIUM-1 skip zero-statement files in coverage gate ([7dec5ee](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7dec5ee6c930901f0d1efa0b7d75cfdb235aba66))
* **05:** MEDIUM-2 pin scratch-Craft to ^5.0 in smoke job ([16774cf](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/16774cf007943c4fd19aa40d6768f39b91227802))
* **05:** MEDIUM-3 wrap mkdir() with umask guard in capture script ([75e9da0](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/75e9da010ce3b58702995e1c7acf96ea42efaf4e))
* **08-01:** add fqcn field to taxonomy audit findings per plan action ([b3ea44a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b3ea44a20901f6bd96a0f2689a110188c6084c53))
* **08-13:** update SettingsHtmlTest to expect 4 H2 groups (AI added) ([1f4d573](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/1f4d573b2957c86c3042b1ee76341d5bf4c55621))
* **08.7:** D-33 follow-up — implicit-content stubs no longer suppress LLM proposals ([1df96b4](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/1df96b4f3747dc3f180f43f7a86b85030b34f3b8))
* **08.7:** D-34 — taxonomy default-language fallback for site-translated fields ([28be7ea](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/28be7ea5dad7cae6d8a686ccd890d7ca0e97e0a4))
* **08.7:** D-35 — pick Craft primary site by flag, not array order ([151e4ff](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/151e4ff5887ef0ad55ca38720081a99b0ce67754))
* **08.7:** D-35 — pick Craft primary site from configured set, not array[0] ([53c3c61](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/53c3c6183dc839bf0de1e0fa573af9978806d4cb))
* **08.7:** D-36 — DetailTableResolver knows Kunstmaan vendor page-part tables ([afb8b76](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/afb8b76e9831c6adca6862299215d7de47423d42))
* **08.7:** D-37 — FinalizeWalker translates --entities to Craft entry types ([b6d4db7](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b6d4db7f395c8db687dc255c1e8bcad36bb3345d))
* **08.7:** load-stage --entities filter accepts FQCN; surface finalize errors ([0ffdab1](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/0ffdab1cd1d90b34236ea4fb0a9e5ff9c344781c))
* **10-04:** carry migration report into atomic entry saves ([8f0a528](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/8f0a5286edda3b700821c9184549d3c4fc0f6d08))
* **10-04:** prevent invalid heuristic entry-type backfill ([083a7a1](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/083a7a1c7470c3136bb824143874f12f7a26039f))
* **10-06:** WR-01 require handler for accepted column coverage ([696da35](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/696da356f8226139a2601d1d21a6e2acc9c98cf3))
* **10-06:** WR-02 record unresolved media URL diagnostics ([9effa9b](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9effa9b24efc69b12fe01a37f868bb9a26a346ce))
* **10:** keep extracted page artifacts source-faithful ([32ec83e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/32ec83ea406cf9944086fc3542894c323be7baac))
* **10:** make relation-expanded extraction opt-in ([0437a4d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/0437a4dda0b215038ed231894ba2d1f5276e482e))
* **10:** W-01 check localized taxonomy saves ([dcf7497](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/dcf749790af29579933ebea44886e6e48fa26d66))
* **10:** W-02 block live load after transform marker ([5cadcca](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5cadcca0bfafb28689b576ef79a62736c12ca42e))
* **11:** filter pageparts by page entity regions ([40b8f8a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/40b8f8a06782162def32eddb226823a9c6381961))
* **11:** preserve graph relation mapping metadata ([eb80c9e](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/eb80c9e4d8be7932d75d430106d2d6b8729ae4cd))
* **12-03:** delegate verify capture actions ([fda5468](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/fda5468c922ecdcca991f9cdce707299b3dd5a55))
* **12-10:** select run detail from run list ([d7cfb73](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/d7cfb7353de35b8741134e13bc6adc4ed598b87e))
* a mapping's media roots may name an environment variable ([ea6e48c](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ea6e48c5a159c9dc80eaa02f8c0ac2a41ee3056c))
* allow analyze queue confirmation ([a443946](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/a4439462c79bd44fd56e23470a5e2a30ecede946))
* **analyze:** wire KnowledgeBase legacyDb + entityParser in Plugin::init() ([84cc2b0](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/84cc2b04b3d8c8f69dc07cd569168adf2bcd8219))
* cap queue progress labels ([2a3d889](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/2a3d889f8c6cb4f3b556b9827384cfa0757df78f))
* **compile/sites:** use LLM targetSection + mapping.yaml sites: block precedence ([4f2ed60](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4f2ed607179fbe6bc98b7447c7a7944fe404a626))
* Craft 5 calls it EVENT_REGISTER_UTILITIES ([77d026d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/77d026d9a9a7183140a6a3179212b95fc8b9b6c4))
* doctor asks whether this migration can run ([40b14c6](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/40b14c65fecb5ac0ea59244eb73b53dda9047d89))
* drop a path repository pointing the plugin at itself ([9e3dd51](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9e3dd5173d5f19316d0ac7dce5052466e8484d90))
* **etl:** port missing LegacyDbService methods + preserve mapping shape ([9e5edb2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9e5edb244546a43284f5e74e52ed0f04c3379cdf))
* Gap [C] chain — empty matrix-block relations on cross-page entries ([#11](https://github.com/Lameco-Development/craft-kunstmaan-migrator/issues/11)) ([5394597](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/5394597ca684de3f056ae7af2b3900487323ea4a))
* give the rewriter a seam instead of a coverage exemption ([b7332aa](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b7332aa8ca256adae3949ea7b13c7495cbc0b6d1))
* guard the one write command that had no production guard ([ab4606b](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/ab4606b80eb8afdda54fb04267ec45c10b238d4e))
* harden migration console startup ([9d433da](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9d433dade6118f2cc6fec68f82982a42c7c72b7b))
* improve CP settings and analyze filters ([3b76f51](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/3b76f511727c92f6fb5a57fd10fca71249bb8294))
* preserve mapping tab filters ([9b9205b](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9b9205bd5d78a35f1f0ec4e2dc1dc6108e52b100))
* queue compile with overwrite intent ([1fab564](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/1fab5647479e2fe2a297de4054e1c51ef6390b60))
* restore CP settings template resolution ([fb7c2c5](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/fb7c2c5d3ad095c38fe3df10d10812fc70924944))
* sanitize queued workflow options ([340d7d9](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/340d7d9da544b24fd383631c5d1c34b864a6f561))
* settings resolution stops writing secrets into project config ([95bfa18](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/95bfa1815c8c471c93ce30acfd49a448d8837d8c))
* suppress Craft revisions during migration via resaving=true ([#12](https://github.com/Lameco-Development/craft-kunstmaan-migrator/issues/12)) ([4f9a216](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4f9a2169a1002d5aa6c86da85d3c5ef97b387112))
* the dry-run switch, the caches that outlived their database, and one finalize ([666a228](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/666a22812512fb305682443e7c26614607fbc8da))
* the settings screen renders, saves, and has something to configure ([adfe6fb](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/adfe6fb0fce0139fd6d3cc76b430146d7735b75a))
* two locales may point at one Craft site ([f427829](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/f42782996b20d50abdefebef6c41c7bb563971de))
* what running it against the real corpus found ([70cb6c2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/70cb6c2b2756bf97e47b03f07caecb781fd917c6))


### Miscellaneous Chores

* **09-01:** verify workflow hardening regression suite ([a7b2520](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/a7b2520fa6c1f21ab40e099557e44e0704b2d63d))
* **09-03:** verify compile regression suite ([64a945b](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/64a945b5da0563a2e18ca98326dcd30434393e70))
* **09-04:** verify coverage and compile suites ([1b70733](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/1b70733846c2bfd023e0cdd177da148572d95362))
* **09-05:** verify migration runtime hardening ([9060dd0](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/9060dd048b3af55d78f95ec6ead3f8e7c6232261))
* bootstrap the release-please manifest ([12ca3ff](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/12ca3ff4958afdf0c7e011838d707218c297449c))
* bootstrap the release-please manifest ([7df2dd6](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/7df2dd676bd569e7e10762638cf9beb33896c3b9))
* merge executor worktree (05-05 unit-tests-analyze-finalize) ([bcc07a0](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/bcc07a06994bd295e92ff77ebb45bfda7150eddb))
* merge executor worktree (05-06 unit-tests-field-handlers) ([6c22d13](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6c22d135727f2458b2f9992534b2252d5f9b02d9))
* merge executor worktree (05-07 ci-smoke-job) ([b425e1d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b425e1df44ab4ab8cb4c8110291cd8935bac2479))
* merge executor worktree (05-08 release-checklist-changelog-reconciliation) ([419ca84](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/419ca84a0248add1c80546888543bbfb0b92869c))
* merge executor worktree (worktree-agent-a35dd2397ee78c0d1) ([c844fff](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/c844fffc916dc9dd08fe6c7f440362f0545430b3))
* merge executor worktree (worktree-agent-a3b883978f810937a) ([4475acc](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4475acc8fbbc09d180cf3f1da7228d0d878f4d49))
* merge executor worktree (worktree-agent-a4c6c5be23701c6ed) ([69a6e79](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/69a6e79e3fa2449d081653ed8e8039a3a19ed661))
* merge executor worktree (worktree-agent-a81a7cdb02c3857ba) ([4a479c1](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/4a479c1da8f31c50f3d0e960d61590d8f821c487))
* merge executor worktree (worktree-agent-ac4780f3994175e2e) ([79cffb2](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/79cffb2ce22936743fb11c58f6b2b9ed38f76212))
* merge executor worktree (worktree-agent-adcfe8962ac160853) ([617af9d](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/617af9ddca91a4437ecc9fd3c8f9cc5eff3f98b2))
* merge executor worktree (worktree-agent-aedbca33581f1a36d) ([b389b1a](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/b389b1a76caa1170f0d7472824aa03d7b9ebd66f))
* merge executor worktree (worktree-agent-af7d5f20b905540a7) ([24b5f26](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/24b5f26705c9edb998ba6481f50f92127bbbfdc2))
* merge executor worktree (worktree-agent-afc33bad5a4766414) ([6ddc642](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/6ddc642f7a4f212379ccb657cde99b0e301d0f08))
* the Craft conventions the plugin had grown out of ([6333426](https://github.com/Lameco-Development/craft-kunstmaan-migrator/commit/63334263af31d3812a422683efc564fa0a0b3c27))

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
  (`Lameco\Kunstmaanmigrator\`) are unchanged — only the plugin handle and
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
