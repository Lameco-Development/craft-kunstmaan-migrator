---
phase: 04-adapters-verify-settings
plan: 02
subsystem: adapter-seomatic
tags: [seomatic, port, adapter, verbatim, kuma_seo, metaGlobalVars]
status: complete
requires:
  - phase: 03
    plan: "03"
    artifact: "src/load/MigrationStateService.php — getTargetId(source, key, ?siteId): ?int seam used for kuma_media:<id> → Craft asset id resolution"
  - phase: 04
    plan: "01"
    artifact: "src/models/Settings.php — seoTableName + verify thresholds (foundation, not directly read here but unblocks Wave 2)"
provides:
  - artifact: "src/load/SeomaticPayloadBuilder.php"
    summary: "165 LOC verbatim port of v1 bridge/load/SeomaticPayloadBuilder.php. Pure-function build(?array $seoRow, int $siteId): array helper that converts a kuma_seo row into the SEOmatic seo field payload (metaGlobalVars + metaBundleSettings). Preserves the locked column→payload contract (RESEARCH.md §2), the 'fromCustom' source-key idiom (EN-locale no-leakage invariant), the setResolver() test seam, and the MigrationStateService kuma_media:<id> lookup ladder."
affects:
  - "Plan 04-06 (SeoMigrationService) — consumer; injects this builder as $seoPayload DI slot."
  - "Plan 04-09 (Plugin::config + init wiring) — registers this component + wires migrationState DI slot."
  - "Phase 5 unit tests — setResolver() seam allows test code to inject a closure without a Craft bootstrap."
tech-stack:
  added: []
  patterns:
    - "Verbatim port discipline (D-54) — body byte-for-byte from v1; only namespace + import reshape allowed."
    - "Yii Component DI (extends yii\\base\\Component) preserved for v2 Plugin::config() compatibility."
    - "setResolver() Closure-based test seam — Closure::fromCallable() injection so unit tests don't need Craft bootstrap."
key-files:
  created:
    - "src/load/SeomaticPayloadBuilder.php (165 LOC) — verbatim port from v1 with namespace flatten only."
  modified: []
decisions:
  - "Verbatim-port discipline (D-54): body is byte-for-byte from v1. Only the namespace declaration changed (bridge\\load → load) and the explicit MigrationStateService use-statement now points at the v2 Phase 3 / Plan 03-03 location (lameco\\kunstmaanmigrator\\load\\MigrationStateService instead of lameco\\kunstmaanmigrator\\bridge\\load\\MigrationStateService). Every docblock, every comment, every constant, every method body is identical to v1."
  - "metaGlobalVars 6-key contract preserved byte-for-byte (RESEARCH.md §2 lock): seoTitle, seoDescription, seoImage, ogTitle, ogDescription, ogImage. The mapping was empirically derived from 5899 kuma_seo rows in v1 — meta_robots (12), og_type (29), og_url (111), twitter_* (≤10) and canonical_url (doesn't exist) are dropped at the source; SEOmatic's sitewide defaults cover them."
  - "'fromCustom' source-key idiom preserved verbatim (v1 lines 97-102). This is load-bearing for the EN-locale no-leakage invariant: using 'sameAsSiteTwitter' for empty values causes SEOmatic to resolve the Twitter-fallback description from the primary (NL) site, writing NL content into per-site EN content JSON. Explicit 'fromCustom' + empty string clears propagated content and stores a true empty for that locale."
  - "setResolver() test seam preserved (v1 lines 126-129). Required for Phase 5 unit tests so closure-based asset-id resolution can run without instantiating MigrationStateService + state table."
  - "lookupCraftAssetId fallback ladder preserved verbatim: $resolver (test) → $migrationState (production via getTargetId('media', 'kuma_media:<id>')) → null. Unresolvable ids return null; caller is already warned via the Plan 03 asset scanner."
  - "DI registration deferred to Plan 04-09 (per plan contract). This plan ships the file only."
metrics:
  completed: "2026-04-26"
  tasks-completed: "1/1"
  total-loc-added: "165 (one new file, exact match with v1)"
  test-suite: "60 tests / 137 assertions (unchanged from baseline; no test additions per plan — Plan 04-12 owns Phase 4 test corpus)"
---

# Phase 4 Plan 02: SeomaticPayloadBuilder verbatim port Summary

**Verbatim port of v1's `bridge/load/SeomaticPayloadBuilder.php` (165 LOC) into `src/load/SeomaticPayloadBuilder.php` under the v2 flat namespace `lameco\kunstmaanmigrator\load`. Pure-function `build(?array $seoRow, int $siteId): array` helper that converts a `kuma_seo` row into the SEOmatic `seo` field payload (`metaGlobalVars` + `metaBundleSettings`). Preserves the locked 6-key column→payload contract (RESEARCH.md §2), the `'fromCustom'` EN-locale no-leakage idiom, the `setResolver()` test seam, and the `MigrationStateService` `kuma_media:<id>` lookup ladder. Only reshape: `bridge\load` → `load`.**

## Status

**COMPLETE.** Single task executed and committed; composer test green (60 tests / 137 assertions — unchanged baseline). DI wiring deferred to Plan 04-09 per plan contract.

## Performance

- **Duration:** ~10 min
- **Started:** 2026-04-26T20:11:00Z (approx)
- **Completed:** 2026-04-26T20:20:00Z (approx)
- **Tasks:** 1/1
- **Files created:** 1 (`src/load/SeomaticPayloadBuilder.php`)
- **Files modified:** 0

## Tasks Completed

| Task | Name                                  | Commit  | Files                                  |
| ---- | ------------------------------------- | ------- | -------------------------------------- |
| 1    | Verbatim port SeomaticPayloadBuilder  | 99d0ad8 | `src/load/SeomaticPayloadBuilder.php`  |

## What Landed

### `src/load/SeomaticPayloadBuilder.php` (165 LOC, NEW)

Final class declaration (line 40):

```php
class SeomaticPayloadBuilder extends Component
{
    private ?Closure $resolver = null;

    /** DI slot: MigrationStateService for fallback asset-id resolution. */
    public ?MigrationStateService $migrationState = null;
```

Public surface (3 methods):

- `build(?array $seoRow, int $siteId): array` (lines 52-114) — pure transform, returns `['metaGlobalVars' => [...], 'metaBundleSettings' => [...]]`.
- `setResolver(callable $resolver): void` (line 126) — internal test seam; `Closure::fromCallable($resolver)` injection.
- (Yii Component init / behaviors inherited; no override needed.)

Private helpers (3):

- `str(array $row, string $key): string` (line 134) — null-safe row coercion.
- `resolveMediaId(mixed $kumaMediaId): ?int` (line 140) — null/empty/0 short-circuit + `(int)` cast guard.
- `lookupCraftAssetId(int $kumaMediaId): ?int` (line 152) — `$resolver ?? $migrationState->getTargetId('media', 'kuma_media:<id>') ?? null` ladder.

The locked `metaGlobalVars` 6-key contract (lines 81-88):

```php
$metaGlobalVars = [
    'seoTitle' => $metaTitle,
    'seoDescription' => $metaDescription,
    'seoImage' => $ogImageId !== null ? (string) $ogImageId : '',
    'ogTitle' => $ogTitle,
    'ogDescription' => $ogDescription,
    'ogImage' => $ogImageId !== null ? (string) $ogImageId : '',
];
```

The `'fromCustom'` EN-locale no-leakage idiom (lines 97-102):

```php
$metaBundleSettings = [
    'seoTitleSource' => 'fromCustom',
    'seoDescriptionSource' => 'fromCustom',
    'ogTitleSource' => 'fromCustom',
    'ogDescriptionSource' => 'fromCustom',
];
```

When `$ogImageId !== null`, three additional keys are appended:
`seoImageSource = 'fromAsset'`, `seoImageIds = [$ogImageId]`, `ogImageSource = 'sameAsSeo'`.

## Acceptance Criteria — All Pass

| Check                                                                            | Expected | Actual |
| -------------------------------------------------------------------------------- | -------- | ------ |
| `test -f src/load/SeomaticPayloadBuilder.php`                                    | true     | OK     |
| `grep -c '^namespace lameco\\kunstmaanmigrator\\load;' …`                        | 1        | 1      |
| `grep -c 'class SeomaticPayloadBuilder extends Component' …`                     | 1        | 1      |
| `grep -c "'seoTitle' => " …`                                                     | 1        | 1      |
| `grep -c "'fromCustom'" …`                                                       | ≥ 1      | 7      |
| `grep -c 'public function build(' …`                                             | 1        | 1      |
| `grep -c 'private function lookupCraftAssetId(' …`                               | 1        | 1      |
| `grep -c 'public function setResolver(' …`                                       | 1        | 1      |
| `grep -c 'kuma_media:' …`                                                        | ≥ 1      | 2      |
| `grep -c 'bridge' …`                                                             | 0        | 0      |
| `php -l src/load/SeomaticPayloadBuilder.php`                                     | 0 errors | OK     |
| Line count within ±10 of v1's 165 LOC                                            | 155-175  | 165    |
| `composer test`                                                                  | exit 0   | exit 0 |

## Decisions Made

- **Verbatim discipline followed exactly.** Only the two reshape kinds the plan permits were applied: namespace declaration (`bridge\load` → `load`) and the `MigrationStateService` use-statement target. Every other byte — class signature, all docblocks (including the 39-line class header that documents the column→payload contract), the 6-key `metaGlobalVars` array, the 4-key `metaBundleSettings` base + 3-key image extension, the test-seam closure injection, the resolver fallback ladder — is byte-for-byte from v1.
- **No DI registration in this plan.** Plugin::config() is untouched. Per plan contract Plan 04-09 owns the wiring.

## Deviations from Plan

None — plan executed exactly as written. Single task, single commit, all acceptance criteria green on the first attempt.

## Issues Encountered

None.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| Namespace `lameco\kunstmaanmigrator\bridge\load` | **reshaped** — flattened to `lameco\kunstmaanmigrator\load` per PROJECT.md "Drop the three-tier layout" decision (D-54). |
| `MigrationStateService` import from `bridge\load\MigrationStateService` | **reshaped** — points to `lameco\kunstmaanmigrator\load\MigrationStateService` per Phase 3 / Plan 03-03 location. |
| `metaGlobalVars` 6-key contract (v1 lines 81-88) | **ported** — byte-for-byte. Locked per RESEARCH.md §2. |
| `'fromCustom'` source-key idiom (v1 lines 97-102) | **ported** — load-bearing for EN-locale "no leakage of NL content" invariant. |
| `setResolver()` test seam (v1 lines 126-129) | **ported** — required for Phase 5 unit tests without Craft bootstrap. |
| `lookupCraftAssetId` `$migrationState ?? $resolver ?? null` ladder | **ported** — preserves v1 fallback chain. |
| `extends Component` Yii base | **ported** — DI surface compatible with v2 Plugin::config(). |

## Next Plan Readiness

- **Plan 04-06 (SeoMigrationService verbatim port)** has its `seoPayload` DI dependency satisfied: `\lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder` is declarable as a typed property and the `build()` signature matches the v1 caller pattern.
- **Plan 04-09 (Plugin::config + init wiring)** can register `seomaticPayloadBuilder` in `Plugin::config()` and wire `$migrationState = $stateService` in `Plugin::init()` per the standard pattern.
- **Phase 5 unit tests** can inject closure resolvers via `setResolver()` to exercise `build()` without a Craft bootstrap.

## Self-Check: PASSED

- File `src/load/SeomaticPayloadBuilder.php` — FOUND (165 LOC).
- Commit `99d0ad8` (`feat(04-02): port SeomaticPayloadBuilder verbatim from v1`) — FOUND in `git log`.

---
*Phase: 04-adapters-verify-settings*
*Completed: 2026-04-26*
