---
phase: 03-etl-pipeline-field-handlers
plan: 08
subsystem: fields
tags: [field-handlers, verbatim-port, deferred-token, fh-04, fh-03, ckeditor, asset, plain-text, link, dropdown, seomatic-deferred]
requires:
  - src/fields/FieldHandler.php (Plan 03-01)
  - src/fields/ResolverContext.php (Plan 03-01)
  - src/fields/DeferredAssetToken.php (Plan 03-01)
  - src/load/MigrationStateReader.php (Plan 03-02 — accessed via $ctx->state)
  - src/finalize/CkeditorRewriterService.php (Plan 03-06 — accessed via $ctx->ck)
  - src/load/AssetMigrationService.php (Plan 03-05 — wired by Plugin::init at 03-14)
provides:
  - lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler (4 modes: plain | ckeditor | link | dropdown)
  - lameco\kunstmaanmigrator\fields\handlers\AssetHandler (dual-token FH-04 emitter + FH-03 JIT path)
affects:
  - FieldHandlerRegistry wiring (Plan 03-14 — registers 4 PlainTextHandler ids + 1 AssetHandler id)
  - CkeditorRewriterService (consumes [M<id>] tokens at finalize)
  - AtomicMigrationService::ingestAndResolveAssets (consumes 'asset:N' tokens at load)
  - Phase 4 / ADP-01 (reinstates SEOmatic mode + writeSeomatic + SeomaticPayloadBuilder ctor param)
tech-stack:
  added:
    - none (uses craft\elements\Asset, RuntimeException; pure verbatim port)
  patterns:
    - Single-handler-multi-mode dispatcher (D-08-12b in v1 — collapsed handler)
    - Public typed-?object slot for late dependency injection (advisor-locked, mirrors Plan 03-06)
    - Deferred-token contract — string vs bracket form for two consumer regexes
    - JIT lazy-resolve via injected resolver (FH-03 default)
key-files:
  created:
    - src/fields/handlers/PlainTextHandler.php (165 LOC)
    - src/fields/handlers/AssetHandler.php (115 LOC)
  modified: []
decisions:
  - "Port PlainTextHandler verbatim from v1 (188 LOC → 165 LOC after seomatic-mode strip + namespace flatten + declare(strict_types=1) added)."
  - "Strip 'seomatic' mode + writeSeomatic() + SeomaticPayloadBuilder ctor parameter per ADP-01 / Phase 4 deferral. Modes whitelist now exactly 4 entries: plain | ckeditor | link | dropdown."
  - "Class-level docblock notes the Phase 4 reinstatement contract — Phase 4 / ADP-01 owns the 5th mode + writer + builder ctor param."
  - "Port AssetHandler verbatim from v1 (95 LOC → 115 LOC after expanded docblock + namespace flatten + declare(strict_types=1) added)."
  - "Replace v1 typed `?AssetResolver $assetResolver = null` with `?object $assetResolver = null` — advisor-locked decision mirroring Plan 03-06; AssetMigrationService consumes the same `resolveFromLegacyId(int): int` surface, wired by Plugin::init() (Plan 03-14)."
  - "Preserve byte-for-byte the FH-04 dual-token emission branch — `[M{legacyValue}]` for the CkeditorRewriterService finalize-consumer + `[DeferredAssetToken::emit(N)]` ('asset:N') for the AtomicMigrationService load-consumer regex pair."
  - "Preserve byte-for-byte the FH-03 JIT lazy-resolve branch — only the default `source='media'`, `keyFormat='kuma_media:%d'` callers use the resolver; non-default callers preserve the deferred-token miss behaviour."
metrics:
  duration: ~6 minutes
  completed: 2026-04-26
  tasks-completed: 2
  files-created: 2
  loc-added: 280
---

# Phase 03 Plan 08: Field Handlers — PlainText + Asset Summary

Verbatim ports of two v1 field handlers into the flat `fields\handlers` namespace: PlainTextHandler (4-mode dispatcher; v1's 5th 'seomatic' mode dropped per ADP-01 / Phase 4 deferral) and AssetHandler (dual deferred-token emitter — FH-04 contract — plus FH-03 JIT lazy-resolve via injected AssetMigrationService).

## What Was Built

**PlainTextHandler (`src/fields/handlers/PlainTextHandler.php`, 165 LOC):**
- 4-mode dispatcher (`plain` | `ckeditor` | `link` | `dropdown`) via constructor `$mode` argument.
- `id()` returns `'plain'` for the plain mode and the mode name otherwise — registry binds 4 distinct ids.
- `'plain'` — null-safe scalar → string cast (null → '').
- `'ckeditor'` — FH-04 inline-rewrite path: delegates to `$ctx->ck->rewrite((string) $legacyValue, $ctx->siteId)`.
- `'link'` — classifies legacy link strings into Craft 5 Link-field shape (`{type, value}`) for email / entry / url types; entry classification consults `$ctx->state->getTargetId('page', $value, $siteId)` then a null-site fallback.
- `'dropdown'` — validates against `options.allowed` allow-list; `options.onUnknown` defaults to `'skip'` (returns null) or `'throw'`.
- 5th mode 'seomatic' + `writeSeomatic()` + `SeomaticPayloadBuilder` constructor parameter all dropped — Phase 4 / ADP-01 reinstates.

**AssetHandler (`src/fields/handlers/AssetHandler.php`, 115 LOC):**
- Single mode dispatched via `options.as`: `'relation'` (default; returns `[int $id]`) or `'imgTag'` (returns `<img>` HTML).
- State-lookup contract: `getTargetId($source, sprintf($keyFormat, $legacyValue), null)` with site-null per the migrate-once-reference-everywhere convention.
- FH-03 JIT lazy-resolve branch: when state-lookup misses AND default contract (`source='media'`, `keyFormat='kuma_media:%d'`), delegates to `$this->assetResolver->resolveFromLegacyId($legacyValue)`. Resolver is wired late (Plan 03-14 Plugin::init) into the public `?object $assetResolver = null` slot.
- FH-04 deferred-token emission on full miss:
  - `as=imgTag` → returns string `"[M{$legacyValue}]"` — consumed by CkeditorRewriterService at finalize (FIN-01).
  - `as=relation` → returns `[DeferredAssetToken::emit($legacyValue)]` (the `'asset:N'` token) — consumed by AtomicMigrationService::ingestAndResolveAssets at load time per the `/^asset:\d+$/` regex pair.
- Resolved-id `imgTag` rendering preserved: HTML-escaped `src` + `alt` from `Asset::findOne` lookup.

## Reconciliation vs v1 (Plan 03-08 only)

### PlainTextHandler

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 64-73 — match dispatcher with 5 arms | plain / ckeditor / link / dropdown / seomatic | ported (4 arms preserved) | `'seomatic'` arm dropped intentionally — Phase 4 / ADP-01 owns SEOmatic. Whitelist updated to 4 entries. |
| Lines 140-152 — `writeSeomatic()` method | SEOmatic payload writer. | dropped intentionally | Phase 4 deferral. |
| Line 6 — `use bridge\load\SeomaticPayloadBuilder;` import | SEOmatic builder import. | dropped intentionally | Phase 4 deferral. |
| Line 52 — constructor `?SeomaticPayloadBuilder $seomaticBuilder = null` parameter | Builder injection slot. | dropped intentionally | Phase 4 deferral. |
| Lines 59-62 — `id()` method | `$mode === 'plain' ? 'plain' : $mode` — 4 distinct registry ids. | ported verbatim | Plugin::init() (Plan 03-14) registers 4 instances. |
| Lines 109-134 — `writeLink` classify pattern | Page-internal-link resolver via `state->getTargetId('page', ...)`. | ported verbatim | Same file. |
| Lines 89-95 — `writeCkeditor` body | Inline `$ctx->ck->rewrite(...)` call. | ported verbatim | FH-04 inline-rewrite path. |
| Lines 76-82 — `writePlain` body | null-safe scalar → string. | ported verbatim | Same file. |
| Lines 163-187 — `writeDropdown` body | allow-list validation w/ skip/throw modes. | ported verbatim | Same file. |
| MigrationConfigError throws | If present — none present in v1. | n/a | `RuntimeException` is preserved (already the v1 choice). |

### AssetHandler

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 47-94 — `resolve()` body | State-lookup → JIT-fallback → deferred-token chain. | ported byte-for-byte | FH-04 contract — finalize-pass + load-pass each consume their own token format. |
| Lines 73-80 — `[M{$legacyValue}]` vs `DeferredAssetToken::emit()` branch | Two formats for two consumers. | ported byte-for-byte | Load-bearing for FH-04. |
| Lines 60-72 — JIT lazy-resolve via `$this->assetResolver->resolveFromLegacyId()` | FH-03 JIT default path. | ported verbatim | Plan 03-14 wires assetResolver to AssetMigrationService. |
| Line 6 — `use bridge\load\AssetResolver;` import | v1 had a dedicated AssetResolver class. | reshape: import dropped; typed `?object $assetResolver = null` | Advisor-locked — v2 folds AssetResolver responsibility into AssetMigrationService. Same reshape as Plan 03-06. |
| Line 40 — `public ?AssetResolver $assetResolver = null` typed slot | Resolver injection slot. | reshape: typed `?object` | See above. |
| Lines 82-91 — `imgTag` resolved-id rendering | `Asset::findOne` + HTML-escaped `<img>`. | ported verbatim | Same file. |
| Lines 51-53 — early-empty-value guard | Returns `''` for imgTag, `[]` for relation. | ported verbatim | Same file. |
| MigrationConfigError throws | If present — none present in v1. | n/a | `RuntimeException` is the unmodified v1 choice. |

### Counts (Plan 03-08 only)

| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| PlainTextHandler | 4 (writePlain, writeCkeditor, writeLink, writeDropdown) | 3 (seomatic mode arm + writeSeomatic + SeomaticPayloadBuilder import/ctor param) | 0 |
| AssetHandler | 3 (resolve dispatch, JIT branch, dual-token emission) | 1 (AssetResolver typed import — replaced with `?object` slot) | 0 |
| **Plan 03-08 totals** | **7** | **4** | **0** |

Matches the plan's reconciliation budget exactly.

## Verification

| Check | Result |
|---|---|
| `php -l src/fields/handlers/PlainTextHandler.php` | No syntax errors detected |
| `php -l src/fields/handlers/AssetHandler.php` | No syntax errors detected |
| PlainTextHandler line count | 165 (≥ 150 floor) |
| AssetHandler line count | 115 (≥ 80 floor) |
| PlainTextHandler — namespace flat (`fields\handlers`) | 1 |
| PlainTextHandler — `implements FieldHandler` | 1 |
| PlainTextHandler — `'seomatic'` literal | 0 (mode stripped) |
| PlainTextHandler — `writeSeomatic` | 0 (method removed) |
| PlainTextHandler — `SeomaticPayloadBuilder` | 0 (import + ctor param removed) |
| PlainTextHandler — 4 write methods present | 8 matches (declarations + match arms) |
| PlainTextHandler — `ck->rewrite` | 1 (FH-04 inline-rewrite path) |
| PlainTextHandler — `MigrationConfigError` | 0 |
| PlainTextHandler — bridge/craft/kunstmaan ns leaks | 0 |
| AssetHandler — namespace flat (`fields\handlers`) | 1 |
| AssetHandler — `implements FieldHandler` | 1 |
| AssetHandler — `use ...DeferredAssetToken;` | 1 |
| AssetHandler — `DeferredAssetToken::emit` | 2 (docblock + branch) |
| AssetHandler — `[M{` imgTag form | 2 (docblock + branch) |
| AssetHandler — `public ?object $assetResolver = null` | 1 |
| AssetHandler — `resolveFromLegacyId` | 2 (docblock + JIT branch) |
| AssetHandler — `kuma_media` | 7 (default keyFormat references) |
| AssetHandler — `MigrationConfigError` | 0 |
| AssetHandler — bridge/craft/kunstmaan ns leaks | 0 (only `craft\elements\Asset` which is a different root) |

## Deviations from Plan

None — plan executed exactly as written. Both handlers ported verbatim modulo (a) namespace flatten, (b) import retargeting, (c) PlainTextHandler 'seomatic' mode + writer + builder ctor param dropped per ADP-01 deferral, (d) AssetHandler `?AssetResolver` typed property reshaped to `?object` per advisor-locked decision (mirrors Plan 03-06).

## Commits

- `f888a98` — feat(03-08): port PlainTextHandler with seomatic mode stripped (src/fields/handlers/PlainTextHandler.php, 165 LOC)
- `ffa4075` — feat(03-08): port AssetHandler with dual-token emission preserved (src/fields/handlers/AssetHandler.php, 115 LOC)

## Key Links Forward

- `src/fields/handlers/PlainTextHandler.php` → `src/finalize/CkeditorRewriterService.php` via `$ctx->ck->rewrite()` in `writeCkeditor()` (FH-04 inline-rewrite contract).
- `src/fields/handlers/AssetHandler.php` → `src/fields/DeferredAssetToken.php` via `DeferredAssetToken::emit($legacyValue)` on relation-path miss (FH-04 deferred-token contract).
- `src/fields/handlers/AssetHandler.php` → `src/finalize/CkeditorRewriterService.php` (indirect) via `[M{$legacyValue}]` emission on imgTag-path miss (FIN-01 finalize consumer).
- `src/fields/handlers/AssetHandler.php` → `src/load/AtomicMigrationService.php` (indirect, future) via `'asset:N'` token emission on relation-path miss (load-pass `/^asset:\d+$/` regex consumer in Plan 03-13).
- `src/fields/handlers/AssetHandler.php` → `src/load/AssetMigrationService.php` (late-bound) via `$assetResolver->resolveFromLegacyId()` JIT branch — wired by Plugin::init() in Plan 03-14.

## Self-Check: PASSED

Files exist:
- FOUND: src/fields/handlers/PlainTextHandler.php
- FOUND: src/fields/handlers/AssetHandler.php

Commits exist:
- FOUND: f888a98 (PlainTextHandler)
- FOUND: ffa4075 (AssetHandler)
