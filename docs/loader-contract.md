# Kunstmaan Migrator payload contract

Canonical schema for the JSON payload the `kuma-migrate` orchestration repo
produces and this plugin's `load/entry` command (Task 3) consumes. This file
is the single source of truth — `kuma-migrate` mirrors it, it does not fork
it.

Implemented by:

- `src/Payload/Payload.php` — `Payload::fromArray()` parses the
  shape below. The kernel package: it also owns `SourceUid` (the grammar) and
  `PayloadValidator`/`Violation`, and depends on nothing.
- `src/Payload/SchemaGateway.php` — the schema lookups the
  validator needs, as a port; `src/craft/CraftSchemaGateway.php` is the
  Craft-backed adapter.

## Payload shape

One JSON object per legacy Kunstmaan entity, describing the Craft entry it
should become:

```json
{
  "sourceUid": "kuma:COM:nt_page:143",
  "aliases": ["kuma:DE:nt_page:87"],
  "section": "pages",
  "entryType": "contentPage",
  "sites": {
    "en": {
      "enabled": true,
      "title": "Swyx",
      "slug": "products/swyx",
      "parentRef": "kuma:COM:nt_page:12",
      "postDate": "2024-03-01T10:00:00+00:00",
      "fieldValues": {
        "pageBuilder": [
          {"type": "contentMediaBlock", "fields": {"heading": "…", "media": {"_asset": "uploads/swyx.jpg"}}}
        ],
        "relatedPages": [{"_ref": "kuma:COM:nt_page:200"}],
        "body": "<p>… {{kuma:media:123}} …</p>"
      }
    }
  }
}
```

| Field | Type | Meaning |
|---|---|---|
| `sourceUid` | string | Canonical legacy identity of this entity — see grammar below. The idempotency key: re-loading the same `sourceUid` updates the same Craft entry instead of creating a duplicate. |
| `aliases` | list\<string\> | Other legacy identities (e.g. a duplicated node across environments/locales) that resolve to the same target entry. Same grammar as `sourceUid`. |
| `section` | string | Target Craft section handle. |
| `entryType` | string | Target Craft entry type handle within that section. |
| `structural` | bool | Optional, default `false`. Marks a path-segment placeholder — see "Structural placeholders" below. The only payload permitted to be enabled on no site. |
| `single` | bool | Optional, default `false`. A single-row config source (Kunstmaan `AbstractConfig`, mapped with `single: true` on the `entities:` lane) merging into the section's existing entry. Its sites may omit `title` entirely; the loader then leaves the entry's existing title untouched. Exempt from `MISSING_TITLE`. |
| `sites` | object | Keyed by Craft site handle. Every site the entry should exist on. |
| `sites.*.enabled` | bool | Whether the entry is enabled for this site. |
| `sites.*.title` | string\|null | Native `Entry::$title`. May be omitted only when the entry type auto-generates its title (`hasTitleField: false` + a `titleFormat`), or on a `single` payload (the existing entry title survives). |
| `sites.*.slug` | string\|null | Native `Entry::$slug`. |
| `sites.*.parentRef` | string\|null | `sourceUid` of this site's parent entry (Structure sections). Resolved to a Craft entry id at load time — see "Two-pass `_ref` resolution" below. |
| `sites.*.postDate` | string\|null | ISO 8601 datetime. |
| `sites.*.fieldValues` | object | Custom-field handle → value. Handles must exist in the entry type's field layout. |

### Structural placeholders

A Kunstmaan URL is the slug chain of a node's ancestors, and an ancestor contributes its
segment whether or not it is itself published and whether or not it becomes content. Three
kinds routinely become nothing: a node with no online translation, a page type the mapping
parks as unmapped, and — most often — a `RedirectPage`, which is how Kunstmaan gives a
section its landing URL. Emit nothing for them and every descendant is re-rooted, losing
that segment from its URL and colliding with whatever else now shares its parent.

`"structural": true` marks an entry that exists only to own such a segment:

```json
{
  "sourceUid": "kuma:com:kuma_nodes:28",
  "section": "pages",
  "entryType": "contentPage",
  "structural": true,
  "sites": {
    "comEnUs": { "enabled": false, "title": "News & knowledge", "slug": "news-knowledge" },
    "comNlNl": { "enabled": false, "title": "News & kennis",     "slug": "news-knowledge" }
  }
}
```

It carries no `fieldValues`, and it is disabled on every site. Being *listed* in `sites` is
what matters: the loader pre-seeds `setEnabledForSite()` for every listed site before the
first save, so Craft propagates the entry there, computes its URI, and hands the segment to
its descendants — while the entry's own URL returns 404 (`Entry::route()` serves only
`STATUS_LIVE`) and falls through to Retour. For a `RedirectPage` ancestor that is exactly the
wanted behaviour: the segment survives, and the redirect still fires.

Because it is enabled nowhere, `structural` is the one payload exempt from the
`NO_ENABLED_SITE` violation. Every other rule still applies, so a site listed with a slug
still needs a title unless the entry type has a `titleFormat`. List a site only where the
ancestor genuinely has a slug in that locale: Kunstmaan omits the segment for a locale it was
never translated into, and borrowing another locale's slug invents a path the old site never
served.

### `fieldValues` value shapes

- **Plain value** — scalar or CKEditor HTML string. CKEditor HTML may embed
  `{{kuma:media:<id>}}` placeholders the loader rewrites once the referenced
  asset is migrated (unrelated to `_ref`, not validated by `PayloadValidator`)
  — see "Legacy-media resolution" below.
- **Matrix field** — a list of blocks: `{"type": "<blockTypeHandle>", "fields": {...}}`.
  `type` must be one of the block types (nested entry types) allowed on that
  Matrix field.
- **Relation to another migrated entry** — `{"_ref": "<sourceUid>"}`, anywhere
  in the fieldValues tree (top-level or nested inside a matrix block's
  `fields`). Same grammar as `sourceUid`.
- **Asset relation** — `{"_asset": "<legacy asset path>"}`, anywhere in the
  fieldValues tree. Resolved at save time — see "Legacy-media resolution"
  below — not by `PayloadValidator`.
- **Link field** — one map, not a list: `{"value": "<url>", "label": "…", "target": "_blank"}`.
  `label` and `target` are optional. Craft reads `value`; a list, or a `url` key, is discarded
  without an error.
- **Link field pointing at a migrated entry** — `{"_linkRef": "<sourceUid>", "label": "…"}`.
  Craft stores an entry link as a reference tag, so the loader resolves the uid and writes
  `{"value": "{entry:<id>@<siteId>:url}", …}`. Same grammar and same fail-forward contract as
  `_ref`: unresolved means the link is dropped, not that a bogus value is written.
- **Formie form relation** — `{"_form": "kuma:<ENV>:form:<Entity>:<id>"}`, emitted inside its
  list container (`"commonForm": [{"_form": …}]`). The form lane's own grammar: one segment
  more than `sourceUid`, resolved against the state row `FormMigrationService` records
  (`source = "form"`, `key = <the whole uid>`). Unresolved defers exactly like a `_ref` —
  on a full run the forms adapter follows the entries, so the fixup pass is what patches it.

## `sourceUid` grammar

```
kuma:<ENV>:<table>:<id>
```

Regex: `^kuma:[A-Za-z0-9_-]+:[a-z0-9_]+:\d+$`

- `<ENV>` — the legacy environment/locale the row was extracted from (e.g. `COM`, `DE`).
- `<table>` — the legacy source table (lowercase, e.g. `nt_page`).
- `<id>` — the legacy row's numeric primary key.

The grammar applies identically to `sourceUid`, every entry in `aliases`,
every `_ref`, and `parentRef`.

## Validation rules

`PayloadValidator::validate()` runs every rule below and returns the full
list of violations (it does not stop at the first failure). Each violation
carries the `sourceUid` of the payload it came from, a `code`, and a
human-readable `message`.

| Code | Fails when |
|---|---|
| `BAD_SOURCE_UID` | `sourceUid`, or any entry in `aliases`, doesn't match the grammar above. |
| `UNKNOWN_SECTION` | `section` doesn't resolve to a registered Craft section. |
| `UNKNOWN_ENTRY_TYPE` | `entryType` doesn't resolve to a registered Craft entry type. |
| `UNKNOWN_SITE` | A key under `sites` isn't a registered Craft site handle. |
| `NO_ENABLED_SITE` | No entry under `sites` has `enabled: true`. |
| `UNKNOWN_FIELD` | A `fieldValues` key isn't in the entry type's field layout. |
| `UNKNOWN_BLOCK_TYPE` | A Matrix block's `type` isn't an allowed block type for that field. |
| `MISSING_TITLE` | An enabled site has no `title`, and the entry type has no title format (i.e. requires an explicit title). |
| `BAD_REF` | A `_ref` or `parentRef` value doesn't match the `sourceUid` grammar. |
| `BAD_DATE` | `postDate` is present but isn't a valid ISO 8601 datetime. |

`UNKNOWN_FIELD`/`UNKNOWN_BLOCK_TYPE`/`MISSING_TITLE` are skipped for a site
when `entryType` itself doesn't resolve (`UNKNOWN_ENTRY_TYPE` already covers
that failure; checking field/block/title rules against an unresolved entry
type would only produce noise).

## Two-pass `_ref` resolution semantics

Payloads are loaded in one pass per file, in file order, with no
topological sort — an entry can legitimately reference another entry
(`_ref` / `parentRef`) that hasn't been loaded yet (forward reference,
circular reference, or simply file-order luck).

- **Pass 1 (`load/entry`, Tasks 3–4):** for every `_ref`/`parentRef`, resolve
  the target `sourceUid` against the migration state table. If it already
  resolves to a Craft entry, write the id immediately — this happens
  regardless of nesting depth: a `_ref` inside a Matrix block's `fields` (or
  nested arbitrarily deep) resolves exactly like a top-level one, since
  topological/file-order luck already made the target resolvable. If it
  doesn't yet resolve, the field/parent link is left unset for now and the
  unresolved reference is recorded (state meta `pendingRefs`) rather than
  failing the whole payload. In other words: nested `_ref`s resolving
  correctly at save time — driven by whatever load order the orchestration
  side happens to produce — is the *primary* resolution mechanism; deferral
  only kicks in for genuine forward/cyclic references.
- **Pass 2 (`load/fixup`, Task 5):** run once every payload in the batch has
  been through pass 1. For each recorded `pendingRefs` entry, re-resolve the
  target `sourceUid`; if it now resolves, patch the field/parent on the
  already-saved entry (using `path` to locate the right container, including
  inside nested Matrix blocks — see below) and clear it from `pendingRefs`.
  Anything still unresolved after pass 2 is reported as an orphan reference.

This lets the loader accept payload files in whatever order they're
generated without requiring the orchestration side to compute a dependency
graph first.

### `pendingRefs` entry shape

Each unresolved `_ref`/`parentRef` recorded during pass 1 is one entry in the
list persisted under state meta `pendingRefs` (and mirrored in
`SaveResult::$deferredRefs`):

```json
{
  "field": "pageBuilder",
  "site": "en",
  "ref": "kuma:COM:nt_page:900",
  "path": ["pageBuilder", 2, "fields", "relatedEntries"]
}
```

| Key | Meaning |
|---|---|
| `field` | The entry's own top-level field handle the ref was found under (or the literal string `parentId` for an unresolved `parentRef`). Kept for flat fields and reporting, even though `path` is now the authoritative location. |
| `site` | The site handle the ref belongs to. |
| `ref` | The unresolved `sourceUid`. |
| `path` | Ordered list of array keys/indices from the site's `fieldValues` root down to the **container** holding the unresolved `_ref` (e.g. `["pageBuilder", 2, "fields", "relatedEntries"]` for a `_ref` nested inside the 3rd `pageBuilder` block's `relatedEntries` relation). Empty (`[]`) for a `parentId` entry, since `parentRef` lives outside `fieldValues`. The path deliberately stops at the container, not the ref's own slot within it — an unresolved `_ref` is dropped entirely from the saved payload (no bogus id written), so its original index is already stale by the time anything reads `pendingRefs` back; `load/fixup` (Task 5) locates the container via `path` and re-populates it once the target resolves. |

`path` is what makes a nested `_ref` (unlike a flat top-level one) locatable
by Task 5 at all — without it, only the top-level field handle would be
known, which isn't enough to find the right slot inside a Matrix block.

## Legacy-media resolution

Implemented by `PayloadEntrySaver`'s `fieldValues` walk (same recursive pass
that resolves `_ref`, extended in Task 8), driving two existing, independent
collaborators — neither requires the other to be configured:

- `Lameco\Kunstmaanmigrator\load\AssetMigrationService::resolveFromLegacyUrl(string): int`
  — resolves `_asset`.
- `Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService::rewriteCurlyMediaTokens()`
  (which lazily delegates to `AssetMigrationService::resolveFromLegacyId(int): int`
  via its `assetResolver` slot) — resolves `{{kuma:media:<id>}}`.

### `_asset` — filesystem JIT, no legacy DB required

`{"_asset": "<legacy asset path>"}` (e.g. `/uploads/media/swyx.jpg`) is
resolved via `AssetMigrationService::resolveFromLegacyUrl()`, which strips
the `/uploads/media/` URL prefix, joins the remainder onto the
**`LEGACY_MEDIA_PATH`** env var (a plain filesystem root — no legacy MySQL
connection needed), and JIT-ingests the file into Craft the first time it's
seen (cached afterwards by state key `legacy_url:<sha1(path)>`).

- Resolved (`> 0`) — the numeric Craft asset id is substituted for the node,
  the same shape a resolved `_ref` produces.
- Unresolved (`0`, meaning `LEGACY_MEDIA_PATH` is unset, the path isn't under
  `/uploads/media/`, or the file is missing on disk) — no bogus id is ever
  written; the node is dropped from its containing list/map entirely (same
  fail-forward contract as an unresolved `_ref`) and one entry is appended to
  the live report's `unresolvedAssets` list:

  ```json
  {
    "sourceUid": "kuma:COM:nt_page:143",
    "field": "media",
    "site": "en",
    "path": ["pageBuilder", 2, "fields"],
    "asset": "/uploads/media/swyx.jpg"
  }
  ```

  `path`/`field` follow the exact same convention as `pendingRefs` above
  (path stops at the container, not the dropped node's own slot). Unlike
  `_ref`, there is **no** two-pass fixup for `unresolvedAssets` — a missing
  legacy file doesn't become resolvable by re-running `load/entry` in a
  different file order, so this is a terminal report, not a deferred one.

### `{{kuma:media:<id>}}` — legacy-DB JIT, rewritten through CkeditorRewriterService

Every string field value is cheaply checked for the substring
`{{kuma:media:`; when present, the string is run through
`CkeditorRewriterService::rewriteCurlyMediaTokens()` — a narrow primitive that
ONLY matches the `{{kuma:media:<id>}}` grammar. Each `<id>` is resolved lazily
via `AssetResolver::resolveFromLegacyId()` (a legacy-DB JIT lookup by
`kuma_media.id`, requiring the legacy DB connection — see env vars below;
wired to the same `AssetMigrationService` singleton `_asset` resolution uses),
with both hits and misses cached per request so a given id costs at most one
resolver call regardless of how many times it recurs.

This is deliberately **not** the full `CkeditorRewriterService::rewrite()`
pipeline — the payload-load path only ever promises `{{kuma:media:<id>}}`
rewriting, so `rewriteCurlyMediaTokens()` leaves every other transformation
`rewrite()` performs completely untouched: `[M<id>]`/`[NT<id>]`
CKEditor-plugin placeholders, raw `<img src="/uploads/media/...">` rewriting,
and `kma-*` class/empty-`<p>` stripping. A body that merely shares a paragraph
with a media token — e.g. an `[NT<id>]` internal link or a `kma-*` class two
sentences later — is saved byte-identical apart from the token itself.
`rewrite()` itself is unchanged and still runs in full elsewhere (the
transform-stage `MatrixHandler`/`PlainTextHandler` field handlers).

- Resolved — the token is rewritten to a Craft ref-token,
  `{asset:<craftAssetId>@<siteId>:url}`, identical in shape to every other
  asset reference `CkeditorRewriterService` produces.
- Unresolved — the original `{{kuma:media:<id>}}` token is left **verbatim**
  as an inert, visible marker (double curly braces are not Craft's
  single-brace ref-tag grammar, so it can never be mistaken for a resolved
  reference) with a trailing `<!-- MIGRATION:UNRESOLVED sourceB64=... -->`
  HTML comment, and one entry is appended to the live report's
  `mediaTokenIssues` list:

  ```json
  {
    "sourceUid": "kuma:COM:nt_page:143",
    "field": "body",
    "site": "en",
    "path": ["body"],
    "tokenFamily": "media_token",
    "legacyId": 123,
    "siteId": 1,
    "token": "{{kuma:media:123}}",
    "source": "kuma_media:123",
    "reason": "no matching Craft asset id"
  }
  ```

Neither `unresolvedAssets` nor `mediaTokenIssues` fail the save or flip
`load/entry`'s exit code — matching the unresolved-`_ref` convention: a
per-item miss is reported, not fatal.

### Config env vars

| Env var | Consumed by | Meaning when unset |
|---|---|---|
| `LEGACY_MEDIA_PATH` | `AssetMigrationService::resolveFromLegacyUrl` (and every other filesystem-based asset ingest) | Every `_asset` node resolves to `0` (reported unresolved); no crash. |
| `CRAFT_LEGACY_DB_SERVER` / `CRAFT_LEGACY_DB_DATABASE` / `CRAFT_LEGACY_DB_USER` / `CRAFT_LEGACY_DB_PASSWORD` / `CRAFT_LEGACY_DB_PORT` / `CRAFT_LEGACY_DB_CHARSET` / `CRAFT_LEGACY_DB_TABLE_PREFIX` | `AssetMigrationService::resolveFromLegacyId` (via the legacy DB connection `Plugin::init()` registers from `Settings`) | Every `{{kuma:media:<id>}}` token whose id isn't already cached in state resolves to unresolved (inert marker + report entry); no crash. |

`doctor` surfaces both as informational-only checks (`legacy_media_root`,
`legacy_db`) — `ok=true` when either is simply absent, since a no-asset site
needs neither; each only fails when it's configured but broken
(unreadable directory / unreachable connection).
