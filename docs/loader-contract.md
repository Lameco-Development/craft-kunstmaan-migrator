# Kuma Loader payload contract

Canonical schema for the JSON payload the `kuma-migrate` orchestration repo
produces and this plugin's `load/entry` command (Task 3) consumes. This file
is the single source of truth — `kuma-migrate` mirrors it, it does not fork
it.

Implemented by:

- `src/payload/Payload.php` — `Payload::fromArray()` parses the shape below.
- `src/payload/PayloadValidator.php` — checks a parsed `Payload` against the
  live Craft schema and the `sourceUid` grammar.
- `src/payload/Violation.php` — one failed rule.
- `src/payload/SchemaGateway.php` / `CraftSchemaGateway.php` — the Craft
  schema lookups the validator needs, behind a fakeable interface.

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
| `sites` | object | Keyed by Craft site handle. Every site the entry should exist on. |
| `sites.*.enabled` | bool | Whether the entry is enabled for this site. |
| `sites.*.title` | string\|null | Native `Entry::$title`. May be omitted only when the entry type auto-generates its title (`hasTitleField: false` + a `titleFormat`). |
| `sites.*.slug` | string\|null | Native `Entry::$slug`. |
| `sites.*.parentRef` | string\|null | `sourceUid` of this site's parent entry (Structure sections). Resolved to a Craft entry id at load time — see "Two-pass `_ref` resolution" below. |
| `sites.*.postDate` | string\|null | ISO 8601 datetime. |
| `sites.*.fieldValues` | object | Custom-field handle → value. Handles must exist in the entry type's field layout. |

### `fieldValues` value shapes

- **Plain value** — scalar or CKEditor HTML string. CKEditor HTML may embed
  `{{kuma:media:<id>}}` placeholders the loader rewrites once the referenced
  asset is migrated (unrelated to `_ref`, not validated by `PayloadValidator`).
- **Matrix field** — a list of blocks: `{"type": "<blockTypeHandle>", "fields": {...}}`.
  `type` must be one of the block types (nested entry types) allowed on that
  Matrix field.
- **Relation to another migrated entry** — `{"_ref": "<sourceUid>"}`, anywhere
  in the fieldValues tree (top-level or nested inside a matrix block's
  `fields`). Same grammar as `sourceUid`.
- **Asset relation** — `{"_asset": "<legacy asset path>"}`. Resolved by the
  existing asset field handler, not by `PayloadValidator`.

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
