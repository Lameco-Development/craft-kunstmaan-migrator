---
phase: 03-etl-pipeline-field-handlers
plan: 02
type: execute
wave: 1
depends_on: []
files_modified:
  - src/load/MigrationStateReader.php
  - src/load/MigrationOptions.php
  - src/load/AssetPathResolver.php
  - src/load/TaxonomyResolver.php
  - src/load/BulkNameMatchTaxonomyResolver.php
autonomous: true
requirements: [ETL-04, ETL-05]
must_haves:
  truths:
    - "MigrationStateReader interface declares the narrow read surface (getTargetId / getTargetUid / get) — handlers consume this, never the full MigrationStateService write surface (the firewall pattern from PATTERNS §5)."
    - "MigrationOptions is a public-r/w (NOT readonly) VO carrying dryRun + force + verbosity + batchSize + legacyClassFilter + skipAssets — operator code may mutate fields between stages."
    - "AssetPathResolver::resolveLocal(?string, string): ?string is path-traversal-safe via realpath-on-both-sides + prefix match (T-04-11 mitigation)."
    - "BulkNameMatchTaxonomyResolver throws \\RuntimeException with miss-list + remediation hint when name-match misses occur (fail-fast preflight contract)."
  artifacts:
    - path: "src/load/MigrationStateReader.php"
      provides: "Narrow read interface for handlers (write-surface firewall)."
      min_lines: 25
    - path: "src/load/MigrationOptions.php"
      provides: "Per-run flags VO."
      min_lines: 25
    - path: "src/load/AssetPathResolver.php"
      provides: "Path-traversal-safe local-asset path resolver."
      min_lines: 50
    - path: "src/load/TaxonomyResolver.php"
      provides: "Abstract base for taxonomy resolvers."
      min_lines: 30
    - path: "src/load/BulkNameMatchTaxonomyResolver.php"
      provides: "Default name-match taxonomy resolver impl."
      min_lines: 100
  key_links:
    - from: "src/load/BulkNameMatchTaxonomyResolver.php"
      to: "src/load/TaxonomyResolver.php"
      via: "extends TaxonomyResolver"
      pattern: "extends TaxonomyResolver"
    - from: "src/load/AssetPathResolver.php"
      to: "(static helper — no incoming wiring)"
      via: "static method calls from TransformService + AssetMigrationService"
      pattern: "AssetPathResolver::resolveLocal"
---

<objective>
Land 5 small load-namespace files: the read-only state interface (firewall) + 3 VOs + 1 abstract base + 1 default impl. All five are verbatim ports from v1 with namespace flatten + import retargeting + the `MigrationConfigError → \RuntimeException` reshape per PATTERNS §3.

This plan is Wave 1, parallel-safe with Plan 03-01 (no shared files). Plan 03-03 (MigrationStateService) implements the MigrationStateReader interface this plan creates.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md

<interfaces>
`src/load/MigrationStateReader.php` (interface — verbatim 3 methods):
```php
namespace lameco\kunstmaanmigrator\load;

interface MigrationStateReader
{
    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int;
    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string;
    /** @return array<string, mixed>|null */
    public function get(string $source, string $key, ?int $siteId = null): ?array;
}
```

`src/load/MigrationOptions.php`:
```php
namespace lameco\kunstmaanmigrator\load;

final class MigrationOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $force = false,
        public int $verbosity = 0,
        public int $batchSize = 50,
        public ?array $legacyClassFilter = null,
        public bool $skipAssets = false,
    ) {}
}
```
**NOT readonly** — operator code may mutate `verbosity` etc. between stages (verbatim from v1).

`src/load/AssetPathResolver.php`:
```php
namespace lameco\kunstmaanmigrator\load;

final class AssetPathResolver
{
    public static function resolveLocal(?string $kumaUrl, string $rootDir): ?string;
    // (other static helpers ported verbatim from v1)
}
```

`src/load/TaxonomyResolver.php` (abstract):
```php
namespace lameco\kunstmaanmigrator\load;

abstract class TaxonomyResolver
{
    /** @param list<string> $legacyValues @return array<string,int> */
    abstract public function resolveAll(array $legacyValues): array;
}
```

`src/load/BulkNameMatchTaxonomyResolver.php`:
```php
namespace lameco\kunstmaanmigrator\load;

final class BulkNameMatchTaxonomyResolver extends TaxonomyResolver
{
    public function resolveAll(array $legacyValues): array;   // throws \RuntimeException on misses
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Port MigrationStateReader interface + MigrationOptions VO + AssetPathResolver helper</name>
  <files>src/load/MigrationStateReader.php, src/load/MigrationOptions.php, src/load/AssetPathResolver.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/MigrationStateReader.php (v1, 43 LOC — full file)
    - ~/Sites/craft-kunstmaan-migrator/src/craft/load/MigrationOptions.php (v1, 45 LOC — full file)
    - ~/Sites/craft-kunstmaan-migrator/src/craft/load/AssetPathResolver.php (v1, 103 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §5 (MigrationStateReader — narrow-read firewall rationale)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §7 (MigrationOptions — public-r/w not readonly)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §8 (AssetPathResolver — path-traversal safety)
  </read_first>
  <action>
    **MigrationStateReader.** Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/MigrationStateReader.php` to `src/load/MigrationStateReader.php`. Apply ONLY:
    1. Namespace `lameco\kunstmaanmigrator\bridge\fields` → `lameco\kunstmaanmigrator\load`. (PATTERNS §5: v1's `bridge\fields` location was a layering artifact; v2 lands it next to its sole implementer.)
    2. Add `declare(strict_types=1);` if v1 omits.

    DO NOT change: the 3 method signatures, the `?int` and `?array` return types, the docblock comments (`@return array<string, mixed>|null`).

    **MigrationOptions.** Copy `~/Sites/craft-kunstmaan-migrator/src/craft/load/MigrationOptions.php` to `src/load/MigrationOptions.php`. Apply:
    1. Namespace `lameco\kunstmaanmigrator\craft\load` → `lameco\kunstmaanmigrator\load`.
    2. Add `declare(strict_types=1);`.
    3. Add the `final` modifier to the class (v2 convention — VOs are final). v1 lacks this; v2 adds it.

    DO NOT change: the constructor's 6 args (dryRun=false, force=false, verbosity=0, batchSize=50, legacyClassFilter=null, skipAssets=false). DO NOT add `readonly` modifiers — properties are public r/w per v1 and PATTERNS §7.

    **AssetPathResolver.** Copy `~/Sites/craft-kunstmaan-migrator/src/craft/load/AssetPathResolver.php` to `src/load/AssetPathResolver.php`. Apply:
    1. Namespace `lameco\kunstmaanmigrator\craft\load` → `lameco\kunstmaanmigrator\load`.
    2. Add `declare(strict_types=1);` if v1 omits.

    DO NOT change: the `resolveLocal()` body (the realpath-on-both-sides + prefix-match safety check is load-bearing for T-04-11), the regex `'#^/?uploads/media/#'`, the `DIRECTORY_SEPARATOR` handling, any other static helpers in the class.

    Add a single-line comment block above `resolveLocal()`:
    ```php
    // T-04-11 path-traversal mitigation — preserves v1's realpath-on-both-sides + prefix-match.
    // DO NOT modify the realpath logic without re-evaluating the threat model.
    ```
  </action>
  <verify>
    <automated>php -l src/load/MigrationStateReader.php && php -l src/load/MigrationOptions.php && php -l src/load/AssetPathResolver.php</automated>
  </verify>
  <done>
    - All 3 files exist; `php -l` returns "No syntax errors" for each.
    - `grep -c "interface MigrationStateReader" src/load/MigrationStateReader.php` returns 1.
    - `grep -c "function getTargetId(string \\$source, string \\$key, ?int \\$siteId = null): ?int" src/load/MigrationStateReader.php` returns 1.
    - `grep -c "function getTargetUid(string \\$source, string \\$key, ?int \\$siteId = null): ?string" src/load/MigrationStateReader.php` returns 1.
    - `grep -c "final class MigrationOptions" src/load/MigrationOptions.php` returns 1.
    - `grep -c "public bool \\$dryRun = false" src/load/MigrationOptions.php` returns 1.
    - `grep -c "readonly" src/load/MigrationOptions.php` returns 0 (NOT readonly per PATTERNS §7).
    - `grep -c "function resolveLocal" src/load/AssetPathResolver.php` returns 1.
    - `grep -c "realpath" src/load/AssetPathResolver.php` returns 2 (rootReal + candidateReal — the load-bearing safety check).
    - `grep -c "T-04-11" src/load/AssetPathResolver.php` returns 1.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/load/MigrationStateReader.php src/load/MigrationOptions.php src/load/AssetPathResolver.php` returns zero matches.
  </done>
</task>

<task type="auto">
  <name>Task 2: Port TaxonomyResolver abstract base + BulkNameMatchTaxonomyResolver default impl</name>
  <files>src/load/TaxonomyResolver.php, src/load/BulkNameMatchTaxonomyResolver.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/load/TaxonomyResolver.php (v1, 46 LOC — full file)
    - ~/Sites/craft-kunstmaan-migrator/src/craft/load/BulkNameMatchTaxonomyResolver.php (v1, 150 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §9 (taxonomy resolver — fail-fast preflight contract)
  </read_first>
  <action>
    **TaxonomyResolver.** Copy `~/Sites/craft-kunstmaan-migrator/src/craft/load/TaxonomyResolver.php` to `src/load/TaxonomyResolver.php`. Apply:
    1. Namespace `lameco\kunstmaanmigrator\craft\load` → `lameco\kunstmaanmigrator\load`.
    2. **Drop** the `use lameco\kunstmaanmigrator\models\MigrationConfigError;` import if present (v1 line 4 area).
    3. If `MigrationConfigError::accumulated([msg])` is referenced anywhere in the abstract body, replace with `new \RuntimeException($msg)`.
    4. Add `declare(strict_types=1);`.

    DO NOT change: the `abstract class TaxonomyResolver` declaration, the abstract method signatures, the docblock.

    **BulkNameMatchTaxonomyResolver.** Copy `~/Sites/craft-kunstmaan-migrator/src/craft/load/BulkNameMatchTaxonomyResolver.php` to `src/load/BulkNameMatchTaxonomyResolver.php`. Apply:
    1. Namespace flatten same as above.
    2. Drop `use lameco\kunstmaanmigrator\models\MigrationConfigError;` (v1 line 5 area).
    3. Replace `throw MigrationConfigError::accumulated([msg])` with EXACTLY:
       ```php
       throw new \RuntimeException(sprintf(
           "Taxonomy resolution misses in section '%s': %d value(s) not found in Craft: [%s%s]. "
           . "Create these entries in the Craft CP (section '%s') before re-running.",
           $this->craftSectionHandle,
           count($misses),
           implode(', ', $shown),
           $suffix,
           $this->craftSectionHandle,
       ));
       ```
       (The shown/suffix/misses local variables are constructed in v1 lines 89-99 — preserve those local variable assignments verbatim.)
    4. Add `declare(strict_types=1);`.
    5. Add `final` modifier to the class.

    DO NOT change: the lazy-cache `Entry::find()->section($handle)->site('*')->unique()` pattern, the `normaliseFn` callback handling, the cache-invariant guards, the array_slice(misses, 0, 30) truncation logic.

    PATTERNS §9 quotes the resolveAll body verbatim — preserve every line aside from the throw replacement.
  </action>
  <verify>
    <automated>php -l src/load/TaxonomyResolver.php && php -l src/load/BulkNameMatchTaxonomyResolver.php</automated>
  </verify>
  <done>
    - Both files exist; `php -l` returns "No syntax errors" for each.
    - `grep -c "abstract class TaxonomyResolver" src/load/TaxonomyResolver.php` returns 1.
    - `grep -c "MigrationConfigError" src/load/TaxonomyResolver.php` returns 0.
    - `grep -c "MigrationConfigError" src/load/BulkNameMatchTaxonomyResolver.php` returns 0.
    - `grep -c "final class BulkNameMatchTaxonomyResolver extends TaxonomyResolver" src/load/BulkNameMatchTaxonomyResolver.php` returns 1.
    - `grep -c "Taxonomy resolution misses in section" src/load/BulkNameMatchTaxonomyResolver.php` returns 1.
    - `grep -c "throw new \\\\RuntimeException" src/load/BulkNameMatchTaxonomyResolver.php` returns 1.
    - `grep -c "site('\\*')" src/load/BulkNameMatchTaxonomyResolver.php` returns 1 (the multi-site lazy-cache pattern preserved).
    - `grep -c "array_slice" src/load/BulkNameMatchTaxonomyResolver.php` returns 1 (30-miss truncation preserved).
  </done>
</task>

</tasks>

<reconciliation>
## MigrationStateReader reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/MigrationStateReader.php` (43 LOC)
**v2 file:** `src/load/MigrationStateReader.php` (~28 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 18-43 — 3-method narrow read surface | getTargetId / getTargetUid / get | ported verbatim | Same file. |
| File location `bridge/fields/` | v1 placed it next to handlers (sole consumers). | reshape: relocated to `src/load/` | PATTERNS §5: handlers don't `use` the interface directly (received via `ResolverContext::$state`). Sole implementer is `MigrationStateService` in `src/load/`. Co-locating with the implementer keeps the firewall obvious — handlers receive the narrow type, never the wide service. |

## MigrationOptions reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/craft/load/MigrationOptions.php` (45 LOC)
**v2 file:** `src/load/MigrationOptions.php` (~32 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 28-43 — 6-arg constructor | dryRun/force/verbosity/batchSize/legacyClassFilter/skipAssets | ported verbatim | Same file, same args, same defaults. |
| (no v1 rule) — `final` modifier | v1 omits. | added (v2 convention) | All v2 VOs are `final` per 02.1-PATTERNS.md §4. Behavioral equivalence preserved. |

## AssetPathResolver reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/craft/load/AssetPathResolver.php` (103 LOC)
**v2 file:** `src/load/AssetPathResolver.php` (~95 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 36-72 — `resolveLocal` realpath-on-both-sides + prefix-match | T-04-11 path-traversal-safe local resolver. | ported verbatim | Same file, same body. Comment added flagging T-04-11 traceability. |
| Other static helpers | Various `kuma_` URL parsers. | ported verbatim | Same file. |

## TaxonomyResolver + BulkNameMatchTaxonomyResolver reconciliation

**v1 files:** `~/Sites/craft-kunstmaan-migrator/src/craft/load/{TaxonomyResolver,BulkNameMatchTaxonomyResolver}.php` (46 + 150 LOC)
**v2 files:** Same names under `src/load/` (~40 + ~145 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| `BulkNameMatchTaxonomyResolver.php:5` — `use ...MigrationConfigError;` | Typed error import. | dropped intentionally | Replaced with `\RuntimeException` per 02.1 reshape recipe. Same operator-facing message via sprintf literal. |
| `BulkNameMatchTaxonomyResolver.php:77-113` — fail-fast preflight (resolveAll) | 30-miss truncation + remediation hint. | ported verbatim (modulo throw replacement) | Same logic; throw rebuilt with `\RuntimeException` carrying same message body. |
| `BulkNameMatchTaxonomyResolver.php:123-149` — lazy-cache `Entry::find()...site('*')->unique()` | Multi-site dedupe; first-write-wins. | ported verbatim | Same file. |

### Counts (Plan 03-02 only)
| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| MigrationStateReader | 1 | 0 | 0 |
| MigrationOptions | 1 | 0 | 0 |
| AssetPathResolver | 2 | 0 | 0 |
| TaxonomyResolver | 1 | 0 | 0 |
| BulkNameMatchTaxonomyResolver | 2 | 1 (MigrationConfigError import) | 0 |
| **Plan 03-02 totals** | **7** | **1** | **0** |
</reconciliation>

<verification>
- `php -l` exits 0 for all 5 files.
- `grep -rn "MigrationConfigError" src/load/` returns zero matches.
- `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/load/` returns zero matches.
- All file-level acceptance greps pass.
</verification>

<success_criteria>
- 5 files land under `src/load/` with verbatim port discipline.
- MigrationStateReader interface preserved byte-for-byte (3-method narrow surface).
- MigrationOptions stays mutable (no readonly modifiers added).
- AssetPathResolver path-traversal safety check (T-04-11) preserved verbatim with traceability comment.
- BulkNameMatchTaxonomyResolver throws fail-fast with miss-list + remediation hint via \RuntimeException.
</success_criteria>

<output>
After completion, create `.planning/phases/03-etl-pipeline-field-handlers/03-02-load-vos-SUMMARY.md`.
</output>
