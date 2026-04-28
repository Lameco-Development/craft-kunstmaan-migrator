# Phase 4 — RECONCILIATION

**Reconciled:** 2026-04-26
**Phase:** 04 — Adapters, Verify & Settings
**v1 brownfield root:** `~/Sites/craft-kunstmaan-migrator/src/`
**v2 fresh-write root:** `src/`

## Context

Phase 4 ports 8 v1 files verbatim per D-54 (feature-parity by default) and
shape-derives one — `BaselineSnapshotService` — per D-59 (explicit drop list:
SHA-heavy snapshot + content hashing, deferred to a future
`verify capture-baseline --deep` flag). This document aggregates the
per-plan RECONCILIATION sections shipped in Plans 04-02, 04-03, 04-04,
04-06, 04-07, 04-08, and 04-09 into a single phase-level table so a future
maintainer can confirm — at a glance — every v1 rule's disposition without
walking each plan's SUMMARY in turn.

The per-plan RECONCILIATION sections are the **load-bearing primary
records**. This file is an **index** with the cross-cutting reshapes lifted
out so the same reshape isn't restated for every plan.

## Per-plan RECONCILIATION sections (primary records)

- **04-02** — SeomaticPayloadBuilder: verbatim port (165 LOC).
- **04-03** — SnapshotDiffer + SpotCheckUrlFetcher: verbatim port (128 + 234 LOC).
  SnapshotDiffer is ported but unused at v1.0; the future
  `verify capture-baseline --deep` flag re-enables it.
- **04-04** — CountGateService + BaselineCounterService: port + shape-derive.
  CountGateService is verbatim with run() signature reshape (D-60).
  BaselineCounterService is the D-59 shape-derived replacement for v1's
  525 LOC BaselineSnapshotService.
- **04-06** — SeoMigrationService: verbatim port body (600 LOC) with 2 reshapes
  (`$sites` source + `$seoTableName` from Settings).
- **04-07** — RedirectMigrationService: verbatim port body (692 LOC) with 2
  reshapes (`$sites` source + hardcoded site handles `'default'`/`'en'`
  removed per PATTERNS flag #4).
- **04-08** — CaptureBaselineHtmlService: verbatim port (73 LOC).
- **04-09** — VerifyController + Plugin wiring: verbatim port body (343 LOC)
  with 5 reshapes (tolerance source, baseline-from-disk, atomic-write seam,
  report path, `SKIP <plugin>` rows for skipped optional-plugin gates).

## Aggregate disposition table

| v1 file | LOC | v2 location | Disposition | Key reshapes / drops |
|---|---|---|---|---|
| `bridge/load/SeoMigrationService.php` | 600 | `src/load/SeoMigrationService.php` | ported | namespace flatten, imports, `$sites` from `Plugin::resolveSitesMap()`, `$seoTableName` from Settings (no mapping.yaml read) |
| `bridge/load/SeomaticPayloadBuilder.php` | 165 | `src/load/SeomaticPayloadBuilder.php` | ported | namespace flatten, `MigrationStateService` import; `setResolver()` test seam preserved verbatim |
| `bridge/load/RedirectMigrationService.php` | 692 | `src/load/RedirectMigrationService.php` | ported | namespace flatten, imports, `$sites` from `resolveSitesMap()`, hardcoded `'default'`/`'en'` site handles removed (PATTERNS flag #4), `$redirectsTableName` from Settings |
| `craft/verify/CountGateService.php` | 131 | `src/verify/CountGateService.php` | ported | namespace flatten; `run()` signature reshape (D-60 — tolerance + expectedCounts as arguments, no mapping.yaml read); Retour gate added (D-58); taxonomy gate added |
| `craft/verify/SnapshotDiffer.php` | 128 | `src/verify/SnapshotDiffer.php` | ported (unused at v1.0) | namespace flatten only; reintroduce when `verify capture-baseline --deep` ships |
| `craft/verify/SpotCheckUrlFetcher.php` | 234 | `src/verify/SpotCheckUrlFetcher.php` | ported | namespace flatten; B1 fix line-level diff preserved byte-for-byte (replaces the earlier byte-count proxy) |
| `craft/verify/CaptureBaselineHtmlService.php` | 73 | `src/verify/CaptureBaselineHtmlService.php` | ported | namespace flatten + SpotCheckUrlFetcher import |
| `craft/verify/BaselineSnapshotService.php` | 525 | `src/verify/BaselineCounterService.php` (renamed) | **shape-derived, NOT verbatim (D-59)** | dropped: `contentSha256`, `hash_file`, `gitSha`, `normalizeForHash`, `getSerializedFieldValues`, per-section `entries[]` payload, `SNAPSHOT_FORMAT_VERSION`. Kept: section count + `countsBySite`, taxonomy + Retour + SEOmatic gated counts, asset count from state table. Future hook: `verify capture-baseline --deep` for the SHA path. |
| `bridge/console/controllers/VerifyController.php` | 343 | `src/console/VerifyController.php` | ported | namespace flatten; tolerance from Settings + CLI ladder (NOT mapping.yaml — D-60); baseline path canonicalised to `storage/`; atomic-write seam via MappingFile; report path canonicalised to `storage/`; `SKIP <plugin>` rows for skipped optional-plugin gates |

## Cross-cutting reshapes

These apply consistently across multiple Phase 4 plans — extracted here so
each plan's per-plan RECONCILIATION can reference them by name instead of
re-stating the rationale.

- **Namespace flattening** — v1's `bridge\` and `craft\` prefixes dropped
  → flat `lameco\kunstmaanmigrator\<concern>\` per PROJECT.md "drop the
  three-tier layout" decision.
- **Plugin DI** — v1's `setComponents` + mapping.yaml reads → `Plugin::config()`
  registration with `Plugin::init()` sibling-DI wiring (Phase 02.1 pattern,
  ref commit 75a95bc).
- **`$sites` source** — v1's mapping.yaml `sites:` block read → single source
  of truth `Plugin::resolveSitesMap()` (already wires EntryMigrationService
  and now Seo/Redirect services consistently).
- **Atomic writes** — v1's raw `file_put_contents` for migrator artifacts →
  `MappingFile::writeAtomic` / `writeAtomicJson` (Phase 2 / D-07).
- **Tolerance source** — v1 read tolerance from mapping.yaml `verify.tolerance`
  → Settings + CLI ladder (D-60). mapping.yaml stays clean of verify config.
- **Baseline shape** — v1's full SHA snapshot (525 LOC, content-hashed) →
  counts-only D-59 shape; SHA path explicitly deferred to a future
  `--deep` flag.
- **Hardcoded site handles** — v1's `'default'` / `'en'` literals in
  RedirectMigrationService → resolved sites map only (PATTERNS flag #4).
- **Optional-plugin gates** — v1 hard-required SEOmatic/Retour at gate time →
  v2 emits `SKIP <plugin>` rows when `Craft::$app->plugins->getPlugin(...)`
  returns null, excluding skips from the overall pass calculation (ADP-03).

## Future hooks

These are intentional re-entry points for v2's deliberately deferred surface:

- **`verify capture-baseline --deep`** — re-introduces v1's SHA-heavy
  snapshot for refactor-safety regression coverage. v1 source is preserved at
  `~/Sites/craft-kunstmaan-migrator/src/craft/verify/BaselineSnapshotService.php`.
- **`VERIFY-<ts>.json` machine-readable sidecar** — wait for the NEXT-04
  cross-client matrix before locking the JSON shape.
- **`storage/migration/spot-check-urls.txt`** — operator-curated; conventions
  (path templates, host substitutions) can grow at the consumer-site level.

## Verification

Every v1 rule above has an explicit v2 disposition. No rule is dropped silently.
The dropped items (D-59 SHA path, mapping.yaml `verify.tolerance` read, the two
hardcoded site handles) are listed above with rationale and re-entry hooks
where applicable. The aggregate-table LOC numbers are confirmed against v1
brownfield via `wc -l` at reconciliation time.

## Related documents

- `.planning/phases/02.1-source-introspection/RECONCILIATION.md` — template
  precedent (v1↔v2 rule-by-rule disposition format).
- `.planning/phases/04-adapters-verify-settings/04-CONTEXT.md` — D-54, D-58,
  D-59, D-60, D-66 canonical statements.
- `.planning/phases/04-adapters-verify-settings/04-PATTERNS.md` — advisor
  flags 1–7 (the cross-cutting reshape source list).
- Per-plan SUMMARY.md files for Plans 04-02 through 04-11 — primary
  RECONCILIATION sections live in each plan's SUMMARY.
