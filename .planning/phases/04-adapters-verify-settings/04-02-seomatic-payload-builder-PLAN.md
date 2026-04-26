---
plan: 02
phase: 04
title: "SeomaticPayloadBuilder verbatim port"
wave: 2
depends_on: ["04-01"]
files_modified:
  - src/load/SeomaticPayloadBuilder.php
autonomous: true
requirements_addressed: [ADP-01]
---

# Plan 04-02: SeomaticPayloadBuilder verbatim port

## Objective

Port v1's `bridge/load/SeomaticPayloadBuilder.php` (165 LOC) verbatim to `src/load/SeomaticPayloadBuilder.php` (v2 namespace `lameco\kunstmaanmigrator\load`). Pure-function helper that converts a `kuma_seo` row into the SEOmatic `seo` field payload (`metaGlobalVars` + `metaBundleSettings`).

This service is consumed by Plan 04-06 (`SeoMigrationService`). Building it as a separate plan keeps the verbatim-port surface small and lets Plan 04-06 focus on the orchestration body.

## Context

- D-54: verbatim port + RECONCILIATION discipline.
- v1 is the locked column→payload contract per RESEARCH.md §2 + MIGRATION-PLAN.md §7. Do not "improve" or modernize.
- `MigrationStateService` lookup seam with optional `setResolver()` test seam (line 126-129 of v1) is preserved for Phase 5 unit tests.
- Namespace reshape: `bridge\load` → `load` (v2 dropped the three-tier layout).
- DI registration happens in Plan 04-09 (Plugin::config + init wiring).

## Tasks

<task id="01">
  <action>
Create `src/load/SeomaticPayloadBuilder.php`. Copy the entire body of `~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeomaticPayloadBuilder.php` verbatim — class signature, constants, `build(?array $seoRow, int $siteId): array` method, `'fromCustom'` source-key idiom (v1 lines 97-102), `lookupCraftAssetId` helper (lines 152-164), `setResolver` test seam (lines 126-129).

Reshape ONLY:
1. `namespace lameco\kunstmaanmigrator\bridge\load;` → `namespace lameco\kunstmaanmigrator\load;`
2. `use lameco\kunstmaanmigrator\bridge\load\MigrationStateService;` (or wherever v1 imports from) → `use lameco\kunstmaanmigrator\load\MigrationStateService;` (Phase 3 / Plan 03-03 location).
3. Confirm `use Closure;` and `use yii\base\Component;` imports remain identical.

Preserve every docblock, every comment, every constant. The `metaGlobalVars` mapping (v1 lines 81-88) is the load-bearing column→payload contract — port byte-for-byte.
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/load/SeomaticPayloadBuilder.php (entire file — verbatim source)
    - src/load/MigrationStateService.php (confirm v2 namespace + getTargetId signature for the resolver call site)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (SeomaticPayloadBuilder section — exact reshape list)
    - .planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md (D-46 verbatim port discipline this carries forward as D-54)
  </read_first>
  <acceptance_criteria>
    - `test -f src/load/SeomaticPayloadBuilder.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\load;' src/load/SeomaticPayloadBuilder.php` returns `1`
    - `grep -c 'class SeomaticPayloadBuilder extends Component' src/load/SeomaticPayloadBuilder.php` returns `1`
    - `grep -c "'seoTitle' => " src/load/SeomaticPayloadBuilder.php` returns `1` (locked column→payload contract)
    - `grep -c "'fromCustom'" src/load/SeomaticPayloadBuilder.php` returns at least `1` (EN-locale no-leakage idiom preserved)
    - `grep -c 'public function build(' src/load/SeomaticPayloadBuilder.php` returns `1`
    - `grep -c 'private function lookupCraftAssetId(' src/load/SeomaticPayloadBuilder.php` returns `1`
    - `grep -c 'public function setResolver(' src/load/SeomaticPayloadBuilder.php` returns `1` (test seam preserved)
    - `grep -c 'kuma_media:' src/load/SeomaticPayloadBuilder.php` returns at least `1` (state-lookup key)
    - `grep -c 'bridge' src/load/SeomaticPayloadBuilder.php` returns `0` (no leftover bridge\ namespace references)
    - `php -l src/load/SeomaticPayloadBuilder.php` outputs `No syntax errors detected`
    - The line count of the new file is within ±10 lines of v1's 165 LOC (verbatim discipline check): `[ $(wc -l < src/load/SeomaticPayloadBuilder.php) -ge 155 ] && [ $(wc -l < src/load/SeomaticPayloadBuilder.php) -le 175 ]`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

## Verification

- The `metaGlobalVars` shape produced by `build()` matches v1's contract — sample input row produces a 6-key array (`seoTitle`, `seoDescription`, `seoImage`, `ogTitle`, `ogDescription`, `ogImage`).
- The `setResolver()` seam allows test code to inject a closure without a Craft bootstrap (Phase 5 unit-test prerequisite).
- DI wiring deferred to Plan 04-09.

## must_haves

- File `src/load/SeomaticPayloadBuilder.php` exists with the correct namespace.
- v1's column→payload contract is byte-for-byte preserved (locked per RESEARCH.md §2).
- The `MigrationStateService` lookup seam (`kuma_media:<id>`) routes to the v2 service path.
- `setResolver()` test seam is intact (Phase 5 dependency).
- `composer test` stays green.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| Namespace `lameco\kunstmaanmigrator\bridge\load` | **reshaped** — flattened to `lameco\kunstmaanmigrator\load` per PROJECT.md "Drop the three-tier layout" decision. |
| `MigrationStateService` import from `bridge\load\MigrationStateService` | **reshaped** — points to `lameco\kunstmaanmigrator\load\MigrationStateService` per Phase 3 / Plan 03-03 location. |
| `metaGlobalVars` 6-key contract (v1 lines 81-88) | **ported** — byte-for-byte. Locked per RESEARCH.md §2. |
| `'fromCustom'` source-key idiom (v1 lines 97-102) | **ported** — load-bearing for EN-locale "no leakage of NL content" invariant. |
| `setResolver()` test seam (v1 lines 126-129) | **ported** — required for Phase 5 unit tests without Craft bootstrap. |
| `lookupCraftAssetId` `$migrationState ?? $resolver ?? null` ladder | **ported** — preserves v1 fallback chain. |
| `extends Component` Yii base | **ported** — DI surface compatible with v2 Plugin::config(). |
