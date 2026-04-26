# Phase 3: ETL Pipeline & Field Handlers — Pattern Map

**Mapped:** 2026-04-26
**Files analyzed:** 27 (19 v1 verbatim ports + 1 v1 reconcile + 5 modifications to existing v2 files + 2 greenfield artifacts)
**Analogs found:** 27 / 27 (every new file has either a v1 source-of-truth or a v2 sibling pattern)

Phase 3 is dominated by **verbatim ports** from `~/Sites/craft-kunstmaan-migrator/src/{bridge,kunstmaan,craft}/` into the flat v2 namespace under `lameco\kunstmaanmigrator\{transform,load,finalize,fields,extract,services}\`. The reshape work is mechanical (D-41 namespace flatten + import retargeting) but the surface is large (~3000 LOC ETL + ~880 LOC handlers + ~530 LOC CKEditor finalize).

**Two pivot decisions surfaced during pattern mapping** (planner must resolve):

1. **Page-part row ordering.** CONTEXT.md `<decisions>` D-49 says "ordered by `kuma_page_part_refs.weight`". v1 source-of-truth says `ORDER BY context, sequencenumber` at `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php:433`. **Verbatim port discipline (D-46) means the v1 code wins** — the planner records a CONTEXT.md drift note in the relevant plan's RECONCILIATION section.
2. **`kunstmaanmigrator_state` table — already shipped in Phase 1.** REQUIREMENTS.md FND-02 + `src/migrations/Install.php:35-60` already create the table byte-for-byte from v1. CONTEXT.md `<code_context>` listed `src/migrations/Install.php` as a Phase 3 modification — **stale framing**. The Phase 3 disposition is "verify schema parity, no-op modification". Doctor's optional 6th check (table exists + writable) is the only new install-adjacent surface in Phase 3.

**Forwarded patterns from 02.1-PATTERNS.md** (do not re-quote; reference by section):

- §1 Yii `Component` class header + `final class X extends Component` + `public ?Foo $dep = null` slots.
- §2 Settings `??=` env-fallback ladder.
- §3 Verbatim port reshape recipe (namespace flatten, drop dropped-v1-component imports, retarget `LegacyDbService` import).
- §4 Immutable VO pattern (`final class` + `readonly` constructor-promoted properties).
- §11 `writeAtomicJson` + `writeAtomic` idiom for any artifact under `storage/migration/`.
- §10 Gate-first idiom — `enforceNeverProduction()` as the FIRST statement of every `actionX()` method.
- D-42 11-step `actionIndex` shape — N steps + per-step plain-text `OK`/`WARN`/`FAIL` emits with ANSI colors.
- 75a95bc sibling-DI pattern — components registered as bare class strings in `Plugin::config()`, then sibling deps wired in `Plugin::init()` after `parent::init()`.

---

## File Classification

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---------------------|------|-----------|----------------|---------------|
| `src/extract/ExtractService.php` | service (Yii Component) | streaming / file-I/O | `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php` (533 LOC) | exact (verbatim port) |
| `src/transform/TransformService.php` | service (Yii Component) | batch / transform | `~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php` (940 LOC) | exact (verbatim port) |
| `src/load/AtomicMigrationService.php` | service (Yii Component) | request-response (per-entry tx) | `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AtomicMigrationService.php` (255 LOC) | exact |
| `src/load/AssetMigrationService.php` | service (Yii Component) | streaming / file-I/O | `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php` (617 LOC) | exact |
| `src/load/MigrationStateService.php` | service (Yii Component) + reader interface impl | CRUD | `~/Sites/craft-kunstmaan-migrator/src/bridge/load/MigrationStateService.php` (356 LOC) | exact |
| `src/load/MigrationStateReader.php` | interface | — | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/MigrationStateReader.php` (43 LOC) | exact (verbatim port) |
| `src/load/EntryMigrationService.php` | service (Yii Component) | request-response | `~/Sites/craft-kunstmaan-migrator/src/craft/load/EntryMigrationService.php` (662 LOC) | exact |
| `src/load/AssetPathResolver.php` | utility (static helpers) | request-response | `~/Sites/craft-kunstmaan-migrator/src/craft/load/AssetPathResolver.php` (103 LOC) | exact |
| `src/load/TaxonomyResolver.php` | abstract class | — | `~/Sites/craft-kunstmaan-migrator/src/craft/load/TaxonomyResolver.php` (46 LOC) | exact |
| `src/load/BulkNameMatchTaxonomyResolver.php` | service | request-response | `~/Sites/craft-kunstmaan-migrator/src/craft/load/BulkNameMatchTaxonomyResolver.php` (150 LOC) | exact |
| `src/load/MigrationOptions.php` | value object | — | `~/Sites/craft-kunstmaan-migrator/src/craft/load/MigrationOptions.php` (45 LOC) | exact |
| `src/load/AttachService.php` | service (Yii Component) | request-response | `~/Sites/craft-kunstmaan-migrator/src/craft/services/AttachService.php` (178 LOC) | exact |
| `src/finalize/CkeditorRewriterService.php` | service (Yii Component) | batch / transform | `~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php` (529 LOC) | exact (verbatim port; FIN-01/02) |
| `src/fields/FieldHandler.php` | interface | — | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandler.php` (41 LOC) | exact (verbatim port) |
| `src/fields/FieldHandlerRegistry.php` | service (POPO container) | request-response | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandlerRegistry.php` (48 LOC) | exact (verbatim port — drop `MigrationConfigError` import; replace with `\RuntimeException`) |
| `src/fields/DeferredAssetToken.php` | value object (static emitter) | — | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/DeferredAssetToken.php` (27 LOC) | exact |
| `src/fields/ResolverContext.php` | value object | — | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/ResolverContext.php` (40 LOC) | exact (verbatim port — retarget imports to v2 namespaces) |
| `src/fields/handlers/PlainTextHandler.php` | handler (FieldHandler impl) | transform | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/PlainTextHandler.php` (188 LOC) | exact — but **strip `seomatic` mode** (Phase 4 / ADP-01); keep `plain` / `ckeditor` / `link` / `dropdown` |
| `src/fields/handlers/AssetHandler.php` | handler | transform | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/AssetHandler.php` (95 LOC) | exact |
| `src/fields/handlers/RelationHandler.php` | handler | transform / streaming | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/RelationHandler.php` (312 LOC) | exact |
| `src/fields/handlers/MatrixHandler.php` | handler | streaming / transform | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/MatrixHandler.php` (112 LOC) | exact (verbatim port — but **see ordering pivot below** for D-49 / `kuma_page_part_refs` reconciliation) |
| `src/fields/handlers/SplitNameHandler.php` | handler | transform | `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` (176 LOC) | exact |
| `src/Plugin.php` (modify) | bootstrap | — | `src/Plugin.php` (v2 self) — `Plugin::config()` + `Plugin::init()` 75a95bc block | exact (extension; ~10–15 new components + sibling-DI lines) |
| `src/console/MigrateController.php` (modify) | controller | request-response | `src/console/AnalyzeController.php` (v2 self) — D-42 11-step shape | exact (mirror; preserve existing `actionInstall`; add `actionIndex` + 5 stage actions) |
| `src/console/DoctorController.php` (modify, optional) | controller | request-response | `src/console/DoctorController.php` (v2 self) — Phase 02.1 / D-31 5th check pattern | exact (add 6th check — state-table exists + writable, per CONTEXT.md Discretion) |
| `src/models/Settings.php` (modify, optional) | model | — | `src/models/Settings.php` (v2 self) | exact (no new fields needed in Phase 3 — `dryRunDefault` already declared at line 52) |
| `src/migrations/Install.php` (no-op verify) | migration | — | `src/migrations/Install.php` (v2 self) — already shipped in Phase 1 | exact (state-table already created lines 35-60; **no Phase 3 changes**) |
| `.planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` | docs | — | `.planning/phases/02.1-source-introspection/RECONCILIATION.md` | exact (template — Phase 3 expands per-plan with v1↔v2 rule disposition tables) |

---

## Pattern Assignments

### 1. `src/fields/FieldHandler.php` (verbatim port — 41 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandler.php` (lines 1–41).

**Reshape:** namespace flatten — `lameco\kunstmaanmigrator\bridge\fields` → `lameco\kunstmaanmigrator\fields`. The `ResolverContext` import retargets to the same namespace. Add `declare(strict_types=1);` (v1 omits it).

**Interface contract to preserve verbatim** (v1 lines 21–41):

```php
interface FieldHandler
{
    /**
     * Stable short identifier used as the registry key.
     *
     * Examples: 'plain', 'ckeditor', 'asset', 'relation', 'link',
     *           'dropdown', 'seomatic', 'matrix'.
     */
    public function id(): string;

    /**
     * Resolves a legacy value into the Craft-native field payload.
     *
     * @param mixed                $legacyValue raw value from the legacy row
     * @param ResolverContext $ctx read-only per-call context
     * @param array<string, mixed> $options     per-call options from FieldSpec
     *
     * @return mixed Craft-ready field value (shape depends on target field)
     */
    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed;
}
```

**Stateless contract** — handlers must not hold per-row state (the docblock at lines 18–19 makes this load-bearing). Per-call deps come through `ResolverContext`.

---

### 2. `src/fields/FieldHandlerRegistry.php` (verbatim port — 48 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/FieldHandlerRegistry.php` (lines 1–48).

**Reshape:**

1. Namespace flatten.
2. **Drop** `use lameco\kunstmaanmigrator\models\MigrationConfigError;` (v1 line 6) — that class is not in v2's surface (per 02.1-PATTERNS.md §3 reshape recipe). Replace `MigrationConfigError::unknownHandler($id, array_keys($this->handlers))` with `new \RuntimeException(sprintf("FieldHandlerRegistry: unknown handler '%s' — registered: [%s].", $id, implode(', ', array_keys($this->handlers))))`.
3. Add `declare(strict_types=1);`.

**Hash-keyed registry pattern to preserve verbatim** (v1 lines 25–48):

```php
final class FieldHandlerRegistry
{
    /** @var array<string, FieldHandler> */
    private array $handlers = [];

    public function register(FieldHandler $handler): void
    {
        $this->handlers[$handler->id()] = $handler;
    }

    public function get(string $id): FieldHandler
    {
        if (!isset($this->handlers[$id])) {
            throw new \RuntimeException(sprintf(
                "FieldHandlerRegistry: unknown handler '%s' — registered: [%s].",
                $id,
                implode(', ', array_keys($this->handlers)),
            ));
        }
        return $this->handlers[$id];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->handlers);
    }
}
```

---

### 3. `src/fields/DeferredAssetToken.php` (verbatim port — 27 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/DeferredAssetToken.php` (lines 1–27).

**Reshape:** namespace flatten only. Add `declare(strict_types=1);`.

**Token format to preserve verbatim** (v1 lines 21–27):

```php
final class DeferredAssetToken
{
    public static function emit(int $legacyId): string
    {
        return 'asset:' . $legacyId;
    }
}
```

**Load-stage paired regex contract** (`AtomicMigrationService.php:209-212` — see §13 below): `/^asset:\d+$/` and capture form `/^asset:(\d+)$/`. The format and consumer are tightly coupled — any change to either side breaks the pipeline silently.

---

### 4. `src/fields/ResolverContext.php` (verbatim port — 40 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/ResolverContext.php` (lines 1–40).

**Reshape:** namespace flatten. Retarget imports:

- `lameco\kunstmaanmigrator\bridge\ckeditor\CkeditorRewriterService` → `lameco\kunstmaanmigrator\finalize\CkeditorRewriterService`
- `lameco\kunstmaanmigrator\craft\load\AssetPathResolver` → `lameco\kunstmaanmigrator\load\AssetPathResolver`
- `lameco\kunstmaanmigrator\kunstmaan\db\LegacyDbService` → `lameco\kunstmaanmigrator\db\LegacyDbService`

**Immutable 7-arg constructor pattern to preserve verbatim** (v1 lines 25–40, see also §4 of 02.1-PATTERNS.md for VO idiom):

```php
final class ResolverContext
{
    /** @param array<string, int> $siteMap kuma_locale → Craft siteId */
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteHandle,
        public readonly MigrationStateReader $state,
        public readonly CkeditorRewriterService $ck,
        public readonly AssetPathResolver $paths,
        public readonly array $siteMap,
        public readonly ?LegacyDbService $legacyDb = null,
    ) {}
}
```

**Construction site:** `TransformService::run()` builds one `ResolverContext` per (site, entry) tuple inside the per-entry loop. Named arguments are recommended (v1 docblock B4 calls this out explicitly at `TransformService.php:26-27`).

---

### 5. `src/load/MigrationStateReader.php` (verbatim port — 43 LOC) — own file, not folded into MigrationStateService

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/MigrationStateReader.php` (lines 1–43).

**Reshape:** namespace flatten — v1's `bridge\fields` location was a layering artifact (handlers consume the read-only surface). v2 lands it under `src/load/` next to `MigrationStateService` (its sole implementer). Drop `declare(strict_types=1);` if matching v1 verbatim; v2 convention adds it.

**Three-method narrow read surface to preserve verbatim** (v1 lines 18–43):

```php
interface MigrationStateReader
{
    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int;

    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string;

    /** @return array<string, mixed>|null */
    public function get(string $source, string $key, ?int $siteId = null): ?array;
}
```

**Why a separate interface (D-11 in v1)** — handlers must not see `MigrationStateService::record/updateMeta/forget/runOnce`. The narrow type is a write-surface firewall. `ResolverContext::$state` is typed `MigrationStateReader`, never `MigrationStateService`, so handlers cannot write through it even by accident.

---

### 6. `src/load/MigrationStateService.php` (verbatim port — 356 LOC, implements MigrationStateReader)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/MigrationStateService.php` (lines 1–356).

**Reshape:**

1. Namespace flatten.
2. `implements MigrationStateReader` import retargets to `lameco\kunstmaanmigrator\load\MigrationStateReader`.
3. Drop the v1 docblock note at lines 28–29 about `kunstmaanSourceId` custom-field replacement — v2 uses the state table for entries too (per CONTEXT.md D-48 state-table-only resume model).
4. `Yii\helpers\Db` import stays; `Generator` may be unused after port (verify).

**Class header pattern** (v1 lines 48–67):

```php
class MigrationStateService extends Component implements MigrationStateReader
{
    /** Wrapped in `{{%...}}` to apply Yii's table-prefix placeholder at query time. */
    public string $statePrefix = 'kunstmaanmigrator_state';

    private ?string $tableName = null;

    private function table(): string
    {
        return $this->tableName ??= '{{%' . $this->statePrefix . '}}';
    }

    private function db(): Connection
    {
        return Craft::$app->db;
    }
```

**Idempotent record() / get() / has() pattern** — preserve the entire CRUD surface verbatim (v1 lines 74–356). Critical detail at v1 lines 132–134: `$targetUidSafe = $targetUid ?? '';` coerces null to empty string because Craft's `$this->uid()` migration helper renders as `char(36) NOT NULL DEFAULT '0'` on MySQL — passing null violates NOT NULL.

**Schema sync invariant:** `$statePrefix` default of `'kunstmaanmigrator_state'` MUST stay aligned with `src/migrations/Install.php::STATE_TABLE = '{{%kunstmaanmigrator_state}}'` (already verified — Phase 1 / FND-02). Any rename breaks both DDL and CRUD.

---

### 7. `src/load/MigrationOptions.php` (verbatim port — 45 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/load/MigrationOptions.php` (lines 1–45).

**Reshape:**

1. Namespace flatten.
2. Add `declare(strict_types=1);`.
3. Add `final class` modifier (v2 convention — VOs are final).

**Constructor pattern** (v1 lines 28–43):

```php
public function __construct(
    public bool $dryRun = false,
    public bool $force = false,
    public int $verbosity = 0,
    public int $batchSize = 50,
    public ?array $legacyClassFilter = null,
    public bool $skipAssets = false,
) {}
```

**v1 uses public r/w properties (NOT readonly).** Verbatim discipline preserves this — operator code may mutate `$opts->verbosity` between stages. v2 keeps the same shape.

**Filter-merge contract:** `MigrationOptions` carries the per-run flags; `MigrationFilters` (Phase 2) carries the `(entities, locales, since)` tuple. They are siblings, not subsumed — each `MigrateController` action constructs both via `Plugin::getInstance()->filterFactory->fromCli(...)` + a local `MigrationOptions::__construct(...)`.

---

### 8. `src/load/AssetPathResolver.php` (verbatim port — 103 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/load/AssetPathResolver.php` (lines 1–103).

**Reshape:** namespace flatten; add `declare(strict_types=1);`.

**Path-traversal-safe `resolveLocal` to preserve verbatim** (v1 lines 36–72):

```php
public static function resolveLocal(?string $kumaUrl, string $rootDir): ?string
{
    if ($kumaUrl === null || $kumaUrl === '') { return null; }

    // Strip the "/uploads/media/" prefix; accept raw filenames too.
    $relative = preg_replace('#^/?uploads/media/#', '', $kumaUrl);
    $relative = ltrim($relative ?? '', '/');
    if ($relative === '') { return null; }

    $rootReal = realpath($rootDir);
    if ($rootReal === false) { return null; }

    $candidate = $rootReal . DIRECTORY_SEPARATOR . $relative;
    $candidateReal = realpath($candidate);
    if ($candidateReal === false) { return null; }

    // Ensure the resolved path is still under rootDir — defeats ../ traversal.
    $rootPrefix = $rootReal . DIRECTORY_SEPARATOR;
    $candidatePrefix = $candidateReal . DIRECTORY_SEPARATOR;
    if (!str_starts_with($candidatePrefix, $rootPrefix)) { return null; }

    if (!is_file($candidateReal)) { return null; }

    return $candidateReal;
}
```

**Threat model traceability:** v1 calls this T-04-11. The `realpath`-on-both-sides + prefix-match is the load-bearing safety check; keep verbatim.

---

### 9. `src/load/TaxonomyResolver.php` + `src/load/BulkNameMatchTaxonomyResolver.php` (verbatim port — 46 + 150 LOC)

**Analogs:**
- `~/Sites/craft-kunstmaan-migrator/src/craft/load/TaxonomyResolver.php` (abstract base)
- `~/Sites/craft-kunstmaan-migrator/src/craft/load/BulkNameMatchTaxonomyResolver.php` (default impl)

**Reshape:**

1. Namespace flatten on both files.
2. Drop `MigrationConfigError` import (line 5 in `BulkNameMatchTaxonomyResolver.php`, line 4 in `TaxonomyResolver.php`). Replace `MigrationConfigError::accumulated([msg])` with `new \RuntimeException($msg)`.
3. Add `declare(strict_types=1);`.

**Fail-fast preflight contract to preserve verbatim** (v1 `BulkNameMatchTaxonomyResolver.php` lines 77–113):

```php
public function resolveAll(array $legacyValues): array
{
    $this->ensureCacheLoaded();
    $result = [];
    $misses = [];

    foreach ($legacyValues as $v) {
        if ($v === '') { continue; }
        $key = ($this->normaliseFn)($v);
        if (isset($this->cache[$key])) {
            $result[$v] = $this->cache[$key];
        } else {
            $misses[] = sprintf("'%s' (normalised '%s')", $v, $key);
        }
    }

    if ($misses !== []) {
        $shown = array_slice($misses, 0, 30);
        $suffix = count($misses) > 30 ? ', …' : '';
        throw new \RuntimeException(sprintf(
            "Taxonomy resolution misses in section '%s': %d value(s) not found in Craft: [%s%s]. "
            . "Create these entries in the Craft CP (section '%s') before re-running.",
            $this->craftSectionHandle,
            count($misses),
            implode(', ', $shown),
            $suffix,
            $this->craftSectionHandle,
        ));
    }
    return $result;
}
```

**Lazy-cache `Entry::find()->section($handle)->site('*')->unique()` pattern** (v1 lines 123–149) — preserve verbatim. The `unique()` call dedupes the multi-site result so first-write-wins matches v1 semantics.

---

### 10. `src/load/AttachService.php` (verbatim port — 178 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/services/AttachService.php` (lines 1–178).

**Reshape:**

1. Namespace flatten — `lameco\kunstmaanmigrator\craft\services` → `lameco\kunstmaanmigrator\load`.
2. Retarget `Install` import to `lameco\kunstmaanmigrator\migrations\Install` (v2 location, already shipped Phase 1).
3. `Settings::entryTypeUids` reference (v1 `attachAllFromSettings` at lines 147–177) — verify against v2 `Settings.php`. **Note:** v2 `Settings.php` does not declare `$entryTypeUids` (Phase 1 only declared connection + AI fields per D-15). Adding it is in-scope for Phase 4 / CFG-01; for Phase 3, scope `AttachService` to the `attachFieldToEntryType($entryTypeUid, $fieldUid)` method only and defer `attachAllFromSettings()` to Phase 4. Mark in RECONCILIATION as `partially ported — Phase 4 follow-up`.

**Idempotent attach pattern to preserve verbatim** (v1 lines 37–137):

The fast-path field-already-attached check (v1 lines 50–62) walks `$layout->getTabs()` then `$tab->getElements()` looking for a `getField()` matching `$fieldUid`. Returns early before touching project-config. Preserve verbatim.

---

### 11. `src/extract/ExtractService.php` (verbatim port — 533 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/extract/ExtractService.php` (lines 1–533).

**Reshape:**

1. Namespace flatten — `lameco\kunstmaanmigrator\kunstmaan\extract` → `lameco\kunstmaanmigrator\extract`.
2. Retarget imports:
   - `lameco\kunstmaanmigrator\kunstmaan\db\LegacyDbService` → `lameco\kunstmaanmigrator\db\LegacyDbService`
   - `lameco\kunstmaanmigrator\kunstmaan\schema\DetailTableResolver` → `lameco\kunstmaanmigrator\source\DetailTableResolver`
   - `lameco\kunstmaanmigrator\kunstmaan\schema\TopologicalOrderer` → `lameco\kunstmaanmigrator\source\TopologicalOrderer`
   - `lameco\kunstmaanmigrator\kunstmaan\db\KunstmaanCoreTables` → `lameco\kunstmaanmigrator\source\KunstmaanCoreTables` (already shipped in v2 at `src/source/KunstmaanCoreTables.php` — verify)
3. Drop `MigrationConfigError` import. Replace with `\RuntimeException`.
4. Drop `KunstmaanSerializedDecoder` import — v1's central serialized-blob safety chokepoint is not yet in v2's surface; **this is a Phase 3 port target** OR a deferred-to-Phase-4 escape hatch (researcher to decide). If deferred, `ExtractService::$serializedDecoder` becomes a `?object = null` slot; the per-blob route at v1 lines 32–37 short-circuits when null. Document in RECONCILIATION as `partially ported — Phase 4 SeomaticPayloadBuilder shares this`.

**Filter integration** — v1's ExtractService does not consume `MigrationFilters` (Phase 2 wasn't in scope). v2 must thread `MigrationFilters` through `ExtractService::run(array $mapping, MigrationFilters $filters, array $options = [])` so:
   - `entities` allow-list scopes the FQCN walk
   - `locales` subset scopes the `kuma_node_translations` JOIN
   - `since` adds a `WHERE updated_at >= :since` predicate (researcher to confirm column name against CQM schema)

This is the dominant **MigrationFilters piping** pattern — see Shared Pattern 2 below.

**Page-part row ordering — VERBATIM PORT (v1 lines 433):**

```php
'SELECT pp.* FROM kuma_page_part_refs ppr '
. 'JOIN ' . $pagePartTable . ' pp ON pp.id = ppr.pagepart_id '
. 'WHERE ppr.pagepartable_id = :nv AND ppr.pagepartable_type = :ptype '
. 'ORDER BY context, sequencenumber',
```

⚠️ **CONTEXT.md D-49 drift.** D-49 says "ordered by `kuma_page_part_refs.weight`". v1 uses `ORDER BY context, sequencenumber`. **D-46 verbatim discipline → v1 wins.** Plan that ports ExtractService records this in its RECONCILIATION section as: "CONTEXT.md D-49 says `weight`; v1 source-of-truth at `ExtractService.php:433` uses `context, sequencenumber`. Adopted v1; CONTEXT.md wording corrected at next phase-doc update."

---

### 12. `src/transform/TransformService.php` (verbatim port — 940 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/transform/TransformService.php` (lines 1–940).

**Reshape:**

1. Namespace flatten — `bridge\transform` → `transform`.
2. Retarget all 5 dep imports per the recipe at the top of v1 lines 7–14:
   - `bridge\fields\FieldHandlerRegistry` → `fields\FieldHandlerRegistry`
   - `bridge\ckeditor\CkeditorRewriterService` → `finalize\CkeditorRewriterService`
   - `kunstmaan\db\LegacyDbService` → `db\LegacyDbService`
   - `bridge\fields\MigrationStateReader` → `load\MigrationStateReader`
   - `craft\load\AssetPathResolver` → `load\AssetPathResolver`
   - `bridge\fields\ResolverContext` → `fields\ResolverContext`
3. Drop `MigrationConfigError` import; replace with `\RuntimeException`.

**Class header pattern** (v1 lines 42–51):

```php
class TransformService extends Component
{
    public ?FieldHandlerRegistry $handlerRegistry = null;
    public ?CkeditorRewriterService $ckeditorRewriter = null;
    public ?LegacyDbService $legacyDb = null;
    public ?MigrationStateReader $migrationState = null;
    public ?AssetPathResolver $assetPathResolver = null;

    public string $storagePath = '@storage/migration';
```

⚠️ **Sibling-DI required.** Every `?Foo = null` slot above must be wired in `Plugin::init()` post-`parent::init()` (75a95bc pattern) — see Shared Pattern 1 below. v1's closure-DI in `Plugin.php:246-254` is **not** the v2 pattern.

**Per-entry resolver loop pattern** (v1 lines 57–120 — the entry of `run()`):

The method takes `array $mapping` (parsed mapping.yaml) + `array $options` and walks `extracted/<fqcn-slug>/<node-id>.json` files. For Phase 3, the in-process pipeline (D-48 — no disk artifacts between stages) means the planner must reshape `run()` to accept an iterable of extracted-row tuples instead of scanning disk. **This is a structural reshape, not a verbatim port** — record in RECONCILIATION as `intentional reshape — D-48 single-process pipeline`. The handler-routing logic inside the loop body (v1 lines 200+) ports verbatim.

---

### 13. `src/load/AtomicMigrationService.php` (verbatim port — 255 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AtomicMigrationService.php` (lines 1–255).

**Reshape:**

1. Namespace flatten — `bridge\load` → `load`.
2. Drop `MigrationReport` import — v2 has no `MigrationReport` model. **Two options** (researcher decides):
   a. Port `MigrationReport` as a small VO (`src/load/MigrationReport.php`) — minimal surface (warn(string), incr(string)).
   b. Replace with an out-parameter `array &$counts = []` + `Throwable[] &$failures = []` (v2 idiom per 02.1-PATTERNS.md §8 TopologicalOrderer reshape).
   Default stance: option (a) — `MigrationReport` is a clear small VO that the controller already needs to render REPORT.md (D-50/D-52).
3. Drop `AssetResolver` import (v1 line 7) — v2 likely names this differently or folds into `AssetMigrationService`. Researcher resolves at plan time.

**ATOMIC-ALWAYS-ON transaction shape — preserve verbatim** (v1 lines 145–184):

```php
// PHASE B — DB TRANSACTION: saveEntryForSites + per-entry SEO write.
Craft::$app->db->transaction(function () use (
    $module,
    $section,
    $entryType,
    $sourceStream,
    $sourceId,
    $perSite,
    $overwrite,
    $opts,
    $refIdsByLocale,
): void {
    $entry = $module->entryMigrationService->saveEntryForSites(
        $section->id,
        $entryType->id,
        $sourceStream,
        $sourceId,
        $perSite,
        $overwrite,
    );

    if ($refIdsByLocale !== []) {
        $module->migrationStateService->updateMeta(
            $sourceStream,
            (string) $sourceId,
            null,
            ['refIdsByLocale' => $refIdsByLocale],
        );
    }

    if ($entry !== null && $entry->id !== null) {
        $module->seoMigrationService->migrateForEntry((int) $entry->id, $opts, $refIdsByLocale);
    }
});
```

For Phase 3, **drop the `seoMigrationService` call** inside the closure — SEOmatic is Phase 4 / ADP-01. Replace with a pass-through marker comment so Phase 4 can re-insert. Record in RECONCILIATION as `partially ported — Phase 4 ADP-01 reinstates seoMigrationService closure call`.

**Phase A (file I/O before transaction) — preserve verbatim** (v1 lines 117–135). Asset materialisation must happen outside the transaction because file copies aren't transactional.

**Deferred-token resolver — preserve verbatim** (v1 lines 209–212):

```php
// Deferred asset token list: ["asset:N", ...] → resolve each to Craft id.
$firstItem = reset($value);
if (is_string($firstItem) && preg_match('/^asset:\d+$/', $firstItem)) {
    $ids = [];
    foreach ($value as $item) {
        if (is_string($item) && preg_match('/^asset:(\d+)$/', $item, $m)) {
            $craftId = $resolver->resolveFromLegacyId((int) $m[1]);
            if ($craftId > 0) {
                $ids[] = $craftId;
            }
        }
    }
    return $ids;
}
```

**Idempotency gate** (v1 lines 107–116) — `existingId !== null && !$overwrite` short-circuits to `$report->incr('skipped')`. ETL-05 idempotency = state-table presence skip. Preserve verbatim.

---

### 14. `src/load/AssetMigrationService.php` (verbatim port — 617 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AssetMigrationService.php` (lines 1–617).

**Reshape:**

1. Namespace flatten.
2. Retarget imports:
   - `MigrationStateService` → `load\MigrationStateService`
   - `MigrationOptions` → `load\MigrationOptions`
   - `AssetPathResolver` → `load\AssetPathResolver`
3. Drop imports for `AssetScanService` + `AssetBatchJob` — neither in v2 / Phase 3 scope. AssetScan: page-driven JIT default (FH-03) means we don't pre-scan; assets discover via the deferred-token resolver in `AtomicMigrationService::ingestAndResolveAssets()`. AssetBatchJob: queue jobs are out of scope for v2 (PROJECT.md). Document in RECONCILIATION.
4. Drop `KunstmaanSerializedDecoder` import — same disposition as ExtractService (§11).
5. Drop `MigrationReport` per §13.
6. `craft\helpers\Console` + `Craft::warning` calls preserve.

**JIT default + `--preload-assets` opt-in (FH-03)** — Phase 3 ships JIT only; the `ingestReferenced()` batch path (v1 lines 87+) becomes the `--preload-assets` opt-in code path. Default `migrate --live` skips it; assets materialise via the deferred-token resolver per-entry. Record in RECONCILIATION as `repurposed — v1 batch-by-default → v2 opt-in via --preload-assets per FH-03`.

**State row write contract** (v1 lines 30–35) — preserve:

```
source='media', sourceKey='kuma_media:{id}',
targetType='asset' (local file) or 'video' (remote),
targetId=<craft asset id | 0>, targetUid=<asset uid | null>,
meta={ originalUrl, location, contentType, videoId? }
```

**Re-run idempotency** (v1 line 36) — `ids already present in state are skipped unless $opts->force=true`. Preserve.

---

### 15. `src/load/EntryMigrationService.php` (verbatim port — 662 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/craft/load/EntryMigrationService.php` (lines 1–662).

**Reshape:**

1. Namespace flatten — `craft\load` → `load`.
2. Retarget `MigrationConfigError` → `\RuntimeException`.
3. v1 carries a hardcoded `public array $sites = []` populated via `Plugin.php:292` (`['nl' => 'default', 'en' => 'en']`). v2 must populate this from `Settings::$localeMap` + `LocalePreflight::detect()` (Phase 2 LOC-01/02). The `sites` map is set in `Plugin::init()` post-`parent::init()` from the resolved locale-pair output. Researcher to decide exact wiring — leave a TODO marker if unclear at port time.

**Multi-site save pattern with propagate=false** (v1 docblock lines 53–57) — load-bearing, preserve verbatim:

```
This service intentionally passes propagateChanges=false to every saveElement()
call and always re-loads the entry scoped to the target siteId before saving
non-primary site content (Pitfall 2 avoidance). Any code that calls saveElement()
directly from a migration service bypasses this safety — the grep-check in the
acceptance criteria enforces there are no direct saveElement calls outside this
class.
```

**Acceptance grep:** Phase 3's plan for this file MUST include an acceptance criterion: `grep -r 'saveElement' src/ | grep -v 'src/load/EntryMigrationService.php'` returns zero matches.

**`saveEntryForSites` API surface** (v1 lines 95–102) — preserve verbatim:

```php
public function saveEntryForSites(
    int $sectionId,
    int $typeId,
    string $stateSource,
    string|int $stateKey,
    array $perSite,
    bool $force = false,
): Entry
```

---

### 16. `src/finalize/CkeditorRewriterService.php` (verbatim port — 529 LOC; FIN-01 + FIN-02)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/ckeditor/CkeditorRewriterService.php` (lines 1–529).

**Reshape:**

1. Namespace flatten — `bridge\ckeditor` → `finalize`.
2. Retarget `MigrationStateService` + `LegacyDbService` imports to v2.

**`[M<id>]` + `[NT<id>]` regex constants — preserve verbatim** (v1 lines 50, 58):

```php
public const KUMA_MEDIA_PLACEHOLDER_REGEX = '~(?:\[|%5B)M(\d+)(?:\]|%5D)~i';
public const KUMA_NT_PLACEHOLDER_REGEX    = '~(?:\[|%5B)NT(\d+)(?:\]|%5D)~i';
```

The URL-encoded `%5B`/`%5D` variants are required by FIN-01 (REQUIREMENTS.md line 85). The `i` flag handles `%5b`/`%5d` lower-case variants emitted by some HTTP clients. **Both regexes are load-bearing for FIN-01 — verbatim is mandatory.**

**Six-step rewrite pipeline** (v1 lines 98–127 — `rewrite()` method) — preserve verbatim:

```
1.  rewriteAssetAttributes()      — <img src="/uploads/media/*"> → {asset:N@siteId:url}
1b. rewriteMediaPlaceholders()    — [M<id>] → {asset:N@siteId:url}
1c. rewriteNodeTranslationPlaceholders() — [NT<id>] → {entry:N@siteId:url}
2.  rewriteEntryLinks()           — <a href="/internal/path"> → {entry:N@siteId:url} (URL→id map)
3.  stripKumaClasses()            — drop kma-* class tokens
4.  removeEmptyParagraphs()       — empty <p>, <p>&nbsp;</p>, etc.
```

**FIN-02 unresolvable-token annotation policy** — v1 emits `<!-- MIGRATION:UNRESOLVED source=... -->` HTML comments (v1 docblock lines 23–25). Preserve verbatim. This is the canonical strict-policy marker called for in REQUIREMENTS.md FIN-02.

**Cache-warming pattern** (v1 lines 78–91) — three caches: `$urlIdCache`, `$kumaMediaIdCache`, `$ntToEntryCache`. Each guarded by a `$cacheNameWarm` bool. Preserve.

**Two consumer paths:**
- **Inline rewrite** during transform — `PlainTextHandler::writeCkeditor()` calls `$ctx->ck->rewrite($html, $ctx->siteId)`.
- **Finalize-pass rewrite** during `migrate/finalize` — walks every CKEditor field across every migrated entry. The walker is a Phase 3 greenfield (no v1 analog at the orchestration level — v1 did inline-only). Researcher designs at plan time using an `Entry::find()->siteId('*')->all()` walker over the live Craft DB, calling the same `rewrite()` method with cross-entry caches now populated.

---

### 17. `src/fields/handlers/PlainTextHandler.php` (verbatim port — 188 LOC, scoped down to 4 modes)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/PlainTextHandler.php` (lines 1–188).

**Reshape:**

1. Namespace flatten — `bridge\fields\handlers` → `fields\handlers`.
2. Retarget `FieldHandler` + `ResolverContext` to `lameco\kunstmaanmigrator\fields\*`.
3. **Drop `seomatic` mode** — Phase 4 / ADP-01 owns SEOmatic. Strip the `'seomatic'` case from the `match` (line 70), the `writeSeomatic()` method (lines 140–152), and the `SeomaticPayloadBuilder` constructor parameter + import. Update the docblock + valid-modes check (line 54) to `['plain', 'ckeditor', 'link', 'dropdown']`. Record in RECONCILIATION as `seomatic mode dropped intentionally — Phase 4 ADP-01`.

**4-mode dispatcher pattern to preserve verbatim** (v1 lines 64–73, minus seomatic):

```php
public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
{
    return match ($this->mode) {
        'plain'    => $this->writePlain($legacyValue),
        'ckeditor' => $this->writeCkeditor($legacyValue, $ctx),
        'link'     => $this->writeLink($legacyValue, $ctx),
        'dropdown' => $this->writeDropdown($legacyValue, $options),
    };
}
```

**id() pattern** (v1 lines 59–62) — `$mode === 'plain' ? 'plain' : $mode`. So the registry binds 4 distinct ids to 4 instances of the same class with different `$mode` constructor args.

**writeLink classify pattern** (v1 lines 109–134) — preserve verbatim. The `state->getTargetId('page', $value, $siteId)` lookup is the page-internal-link resolver.

---

### 18. `src/fields/handlers/AssetHandler.php` (verbatim port — 95 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/AssetHandler.php` (lines 1–95).

**Reshape:**

1. Namespace flatten.
2. Drop `AssetResolver` import — replace with a `?object $assetResolver = null` slot (researcher names properly at plan time). The lazy-materialise call at v1 lines 66–71 is JIT asset behaviour (FH-03).
3. Retarget `FieldHandler`, `ResolverContext`, `DeferredAssetToken`, `Asset` imports.

**State-table resolution pattern to preserve verbatim** (v1 lines 47–94):

```php
public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
{
    $as = (string) ($options['as'] ?? 'relation');

    if ($legacyValue === null || $legacyValue === '' || $legacyValue === 0 || $legacyValue === '0') {
        return $as === 'imgTag' ? '' : [];
    }

    $source = (string) ($options['stateSource'] ?? 'media');
    $keyFormat = (string) ($options['keyFormat'] ?? 'kuma_media:%d');
    $key = sprintf($keyFormat, (int) $legacyValue);

    $id = $ctx->state->getTargetId($source, $key, null);
    if ($id === null && $source === 'media' && $keyFormat === 'kuma_media:%d') {
        if ($this->assetResolver !== null) {
            $resolved = $this->assetResolver->resolveFromLegacyId((int) $legacyValue);
            if ($resolved > 0) { $id = $resolved; }
        }
    }
    if ($id === null) {
        return $as === 'imgTag' ? "[M{$legacyValue}]" : [DeferredAssetToken::emit((int) $legacyValue)];
    }
    // ... imgTag rendering ...
    return [$id];
}
```

**Critical detail (v1 lines 73–80):** when state-lookup misses, `as=imgTag` returns `"[M{$legacyValue}]"` (the CKEditor placeholder format) — finalize pass resolves it later. `as=relation` returns `[DeferredAssetToken::emit($legacyValue)]` (the `asset:N` token form). Two different deferred-token formats for two different consumer paths. Preserve verbatim.

---

### 19. `src/fields/handlers/RelationHandler.php` (verbatim port — 312 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/RelationHandler.php` (lines 1–312).

**Reshape:** namespace flatten + import retargeting. Drop `MigrationConfigError` if present.

**Three-dispatch pattern to preserve verbatim** (v1 lines 63–83):

```php
public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
{
    if (!isset($options['stateSource']) || $options['stateSource'] === '') {
        throw new \RuntimeException("RelationHandler requires 'stateSource' option.");
    }
    $source = (string) $options['stateSource'];

    if (isset($options['joinTable'])) {
        return $this->resolveViaJoinTable($legacyValue, $ctx, $source, $options);
    }
    if (isset($options['joinTranslation'])) {
        return $this->resolveViaJoinTranslation($legacyValue, $ctx, $source, $options);
    }
    return $this->resolveDirect($legacyValue, $ctx, $source);
}
```

**Identifier whitelist regex (T-06-02-01 mitigation)** (v1 docblock lines 49–54) — every join-table identifier MUST match `^[A-Za-z0-9_]+$` before sprintf-interpolation into SQL. Scalar values bind as named PDO parameters. LIMIT casts to int. Preserve verbatim.

---

### 20. `src/fields/handlers/MatrixHandler.php` (verbatim port — 112 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/MatrixHandler.php` (lines 1–112).

**Reshape:** namespace flatten + import retargeting.

**Streaming child-row pattern to preserve verbatim** (v1 lines 47–110):

```php
public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
{
    if ($ctx->legacyDb === null) {
        throw new \RuntimeException('MatrixHandler requires ResolverContext::$legacyDb to be non-null.');
    }
    foreach (['itemTable', 'fkCol', 'blockType'] as $req) {
        if (empty($options[$req]) || !is_string($options[$req])) {
            throw new \RuntimeException("MatrixHandler requires '{$req}' option (non-empty string).");
        }
    }
    if (empty($options['valueCol']) && empty($options['bodyCol'])) {
        throw new \RuntimeException("MatrixHandler requires one of 'valueCol' or 'bodyCol'.");
    }
    // ...
    $sql = sprintf(
        'SELECT * FROM %s WHERE %s = :fk ORDER BY %s',
        $itemTable,
        $fkCol,
        $orderBy,
    );

    $blocks = [];
    $n = 0;
    foreach ($ctx->legacyDb->streamQuery($sql, [':fk' => $fkValue]) as $row) {
        $n++;
        // ... build $fields hash ...
        $blocks['new' . $n] = [
            'type' => $blockType,
            'enabled' => true,
            'fields' => $fields,
        ];
    }
    return $blocks;
}
```

**Block array key pattern: `new1`, `new2`, …** — v1 lines 103. Required by Craft 5 `$entry->setFieldValue()` semantics for new-block creation. Preserve verbatim.

⚠️ **D-49 mapping-driven contract** — the v1 MatrixHandler is generic-table-streaming (config-driven via `itemTable`/`fkCol`/`blockType`). **CONTEXT.md D-49 specifies the page-part flavor explicitly:** mapping.yaml's pagePart row tuple `(pagePartClass, parentPageClass, context) → (targetEntryType, targetMatrixField, targetBlockType, fields[])` is the runtime source of truth. The page-part path is a specialisation: `itemTable` derives from the discriminator-resolved class table, `fkCol` is the kuma_node_versions→kuma_main_pageparts→kuma_page_part_refs join chain. Researcher decides whether to:
- (a) extend `MatrixHandler::resolve()` to dispatch on options shape `[pagePartClass, ...]` vs `[itemTable, ...]`, OR
- (b) introduce a dedicated `PagePartMatrixHandler` and keep the generic one for non-page-part Matrix fields.

Default stance: option (a) — preserve a single `MatrixHandler` class; document the dispatch in RECONCILIATION as `enhanced — D-49 page-part path added`.

---

### 21. `src/fields/handlers/SplitNameHandler.php` (verbatim port — 176 LOC)

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` (lines 1–176).

**Reshape:** namespace flatten + import retargeting.

**Three const-list seed values (Dutch tussenvoegsel handling) — preserve verbatim** (v1 lines 45–63):

```php
private const PREFIX_TOKENS = [
    'dr', 'dr.', 'ir', 'ir.', 'drs', 'drs.', 'prof', 'prof.',
    'mr', 'mr.', 'mw', 'mw.', 'ing', 'ing.', 'mrs', 'mrs.', 'ms', 'ms.',
];
private const INFIX_TOKENS = [
    'van', 'de', 'der', 'den', 'ten', 'ter', 'het', "'t", 'op',
    'aan', 'bij', 'in', 'uit', 'over', 'onder', 'achter',
    'la', 'le', 'du', 'des', 'del', 'da', 'di', 'von', 'zu',
];
private const SUFFIX_TOKENS = ['jr', 'jr.', 'sr', 'sr.', 'i', 'ii', 'iii', 'iv', 'v'];
```

**Per-part dispatcher pattern (v1 lines 72–89)** — handlerOptions `part` selects `firstName|infix|lastName|prefix|suffix`. The `split()` method (lines 96–160) is pure-function and returns all 5 parts; resolve() picks the requested one. Preserve verbatim.

**Defensive infix→lastName fallback** (v1 lines 152–157) — when "Jan van" tokenises to firstName=Jan, infix=van, lastName='', the last infix token promotes to lastName so the entry never saves with an empty `lastName`. Preserve verbatim.

---

### 22. `src/Plugin.php` modification — register Phase 3 components + sibling-DI wiring

**Analog (self):** `src/Plugin.php` (v2, 165 LOC at last commit).

**Pattern to extend** (`src/Plugin.php` lines 66–91 — `config()` block):

```php
public static function config(): array
{
    return [
        'components' => [
            // Phase 1 / 2 / 02.1 entries (preserved verbatim — DO NOT reorder; PluginBootstrapTest invariant)
            'legacyDbService' => LegacyDbService::class,
            'filterFactory'     => FilterFactory::class,
            // ... (lines 71–88) ...
            'blockAvailabilityValidator'    => BlockAvailabilityValidator::class,
            // Phase 3 additions:
            'fieldHandlerRegistry'    => FieldHandlerRegistry::class,
            'plainTextHandler'        => PlainTextHandler::class, // mode='plain' default
            // ... (per-mode handler instances may be const-args — see init() wiring) ...
            'assetHandler'            => AssetHandler::class,
            'relationHandler'         => RelationHandler::class,
            'matrixHandler'           => MatrixHandler::class,
            'splitNameHandler'        => SplitNameHandler::class,
            'migrationStateService'   => MigrationStateService::class,
            'ckeditorRewriterService' => CkeditorRewriterService::class,
            'extractService'          => ExtractService::class,
            'transformService'        => TransformService::class,
            'atomicMigrationService'  => AtomicMigrationService::class,
            'assetMigrationService'   => AssetMigrationService::class,
            'entryMigrationService'   => EntryMigrationService::class,
            'attachService'           => AttachService::class,
        ],
    ];
}
```

⚠️ **PluginBootstrapTest invariant** (Phase 1 / D-21): the existing `'legacyDbService' => LegacyDbService::class` line at line 70 is asserted by source-level reflection. Phase 3 additions go **after** the Phase 02.1 block (line 88), never reordering existing entries.

**`Plugin::init()` sibling-DI block to extend** (`src/Plugin.php` lines 130–149 — the post-`parent::init()` block).

The pattern (75a95bc commit) is: every `?Foo $dep = null` slot on every Phase 3 service gets explicitly assigned in `Plugin::init()`. Phase 3 adds something like:

```php
// Phase 3 sibling-DI wiring (75a95bc pattern). Every service that depends on
// another sibling component is wired here; bare class registrations in config()
// would otherwise leave these `public ?Foo $dep = null` properties null and
// produce silent NPEs at first call.

// Field handler registry — register all 6 modes with the right id() bindings.
$registry = $this->fieldHandlerRegistry;
$registry->register(new PlainTextHandler('plain'));
$registry->register(new PlainTextHandler('ckeditor'));
$registry->register(new PlainTextHandler('link'));
$registry->register(new PlainTextHandler('dropdown'));
$registry->register($this->assetHandler);
$registry->register($this->relationHandler);
$registry->register($this->matrixHandler);
$registry->register($this->splitNameHandler);

// AssetHandler asset-resolver injection.
$this->assetHandler->assetResolver = $this->assetMigrationService; // or dedicated resolver

// CkeditorRewriterService deps.
$this->ckeditorRewriterService->migrationState = $this->migrationStateService;
$this->ckeditorRewriterService->legacyDb       = $this->legacyDbService;
$this->ckeditorRewriterService->assetResolver  = $this->assetMigrationService;

// ExtractService deps.
$this->extractService->legacyDb            = $this->legacyDbService;
$this->extractService->detailTableResolver = $this->detailTableResolver;
$this->extractService->topologicalOrderer  = $this->topologicalOrderer; // verify v2 location

// TransformService deps (5 slots).
$this->transformService->handlerRegistry   = $this->fieldHandlerRegistry;
$this->transformService->ckeditorRewriter  = $this->ckeditorRewriterService;
$this->transformService->legacyDb          = $this->legacyDbService;
$this->transformService->migrationState    = $this->migrationStateService; // narrows to MigrationStateReader
$this->transformService->assetPathResolver = new AssetPathResolver(); // static helper

// AssetMigrationService deps.
$this->assetMigrationService->legacyDb       = $this->legacyDbService;
$this->assetMigrationService->migrationState = $this->migrationStateService;

// EntryMigrationService.
$this->entryMigrationService->stateService = $this->migrationStateService;
$this->entryMigrationService->sites        = $this->resolveSitesMap(); // helper from LocalePreflight
```

**Anti-pattern callout — DO NOT use v1's closure-DI.** v1's `Plugin.php:235-294` uses `$this->set('extractService', function() use ($self) { ... })` closures. **v2 uses init()-time direct property assignment** per 75a95bc. Closures defeat the bare-class-strings-in-config() invariant that the PluginBootstrapTest reflection check depends on. Record in RECONCILIATION as `intentional reshape — closure-DI dropped, replaced with init() property-injection per 75a95bc`.

---

### 23. `src/console/MigrateController.php` modification — add 5 stage actions + actionIndex

**Analog (self):** `src/console/MigrateController.php` (54 LOC currently — only `actionInstall` shipped Phase 1) + `src/console/AnalyzeController.php` (D-42 11-step shape).

**Preserve existing `actionInstall()` verbatim** — it's load-bearing for FND-02a (verified by acceptance grep at Phase 1 / Plan 03).

**Pattern to copy from `AnalyzeController::actionIndex`** (`src/console/AnalyzeController.php` lines 68–397, the 11-step orchestration):

```php
public function actionIndex(): int
{
    // FND-04 / D-20: NeverProduction guard FIRST.
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    $this->stdout("Migrate: extract → transform → load → finalize\n", Console::FG_CYAN);

    $plugin = Plugin::getInstance();
    $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

    // Step 1: locale preflight (LOC-02).
    $unmapped = $plugin->localePreflight->ensure($filters);
    if ($unmapped !== null) { /* FAIL + return ExitCode::CONFIG */ }
    $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);

    // Step 2: load mapping.yaml + coverage gate (per MAP-06).
    // Step 3: extract — feed pre-computed source-scanner output + filters
    // Step 4: transform — per-entry resolver loop + handler dispatch
    // Step 5: load — per-entry atomic migration + JIT asset materialisation
    // Step 6: finalize — CKEditor token resolution pass
    // Step 7: REPORT.md (D-50 failures + D-52 counts)

    return ExitCode::OK;
}
```

**Sub-actions (`actionExtract`, `actionTransform`, `actionLoad`, `actionFinalize`, `actionTruncate`)** — each follows the same gate-first idiom + filter parsing + single-stage execution. Per CONTEXT.md D-48, standalone `actionLoad` re-runs extract+transform internally (lazy / streaming) and skips state-recorded entries. The internal pipeline is the same primitive set; the controller just decides where to enter and where to stop.

**Per-entry progress emission (ETL-06)** — preserve the doctor OK/WARN/FAIL idiom (Phase 1 / D-19) at per-entry granularity:

```
[1/547] news/article-foo → created
[2/547] news/article-bar → skipped
[3/547] news/article-baz → FAILED: AssetHandler threw on missing kuma_media:99
```

Emitted via `$this->stdout()` / `$this->stderr()` with `Console::FG_GREEN` / `Console::FG_RED`. Aggregated counts roll up to the REPORT.md summary block (D-52).

**`actionTruncate` (D-51)** — wide+safety-rails. Default `--dry-run`; requires `--live --confirm`. Honors `--entities` / `--locales` filters. NeverProductionTrait still gates regardless of flags. Researcher writes the SQL DELETE patterns at plan time (state-table rows + Craft entries with kunstmaanSourceId set + assets pulled in).

**Filter flag declaration** — same shape as `AnalyzeController` lines 49–66:

```php
public bool $live = false;
public bool $confirm = false;       // for truncate
public bool $preloadAssets = false; // FH-03 opt-in
public ?string $entities = null;
public ?string $locales = null;
public ?string $since = null;

public function options($actionID): array
{
    return array_merge(parent::options($actionID), [
        'live', 'confirm', 'preloadAssets', 'entities', 'locales', 'since',
    ]);
}
```

---

### 24. `src/console/DoctorController.php` modification (optional — 6th check)

**Analog (self):** `src/console/DoctorController.php` lines 56–60 (`&&`-chained checks) + lines 190–214 (`checkKunstmaanSourcePath` — Phase 02.1 / D-31 5th check shape).

**Pattern to extend** (lines 56–60):

```php
$ok = $this->checkLegacyDb()             && $ok;
$ok = $this->checkApiKey()               && $ok;
$ok = $this->checkStorageDir()           && $ok;
$ok = $this->checkMappingFile()          && $ok;
$ok = $this->checkKunstmaanSourcePath()  && $ok;
// Phase 3 (Discretion):
$ok = $this->checkStateTable()           && $ok;
```

**6th check shape** (mirror `checkMappingFile` at lines 160–180):

```php
private function checkStateTable(): bool
{
    try {
        $tableName = '{{%kunstmaanmigrator_state}}';
        if (!Craft::$app->db->getTableSchema($tableName)) {
            $this->stderr(
                "  FAIL state table '{$tableName}' missing — run "
                . "`./craft kunstmaan-migrator/migrate/install` first.\n",
                Console::FG_RED,
            );
            return false;
        }
        // Probe writability with a no-op SELECT 1 against the table.
        Craft::$app->db->createCommand("SELECT COUNT(*) FROM {$tableName}")->queryScalar();
        $this->stdout("  OK   kunstmaanmigrator_state table reachable\n", Console::FG_GREEN);
        return true;
    } catch (Throwable $e) {
        $this->stderr("  FAIL state table check: {$e->getMessage()}\n", Console::FG_RED);
        return false;
    }
}
```

**Researcher decision (CONTEXT.md Discretion):** include or skip. Doctor's 5 checks already cover the load-bearing preflight surface; the 6th is incremental safety. Default stance: include — cheap, deterministic, and catches the case where Phase 1 install ran before Phase 3 schema bumps land.

---

### 25. `src/migrations/Install.php` — NO PHASE 3 CHANGES

**Analog (self):** `src/migrations/Install.php` (113 LOC, shipped Phase 1 / Plan 03 per FND-02).

The `kunstmaanmigrator_state` table creation is already complete at lines 35–60. Schema is byte-for-byte from v1 per `~/Sites/craft-kunstmaan-migrator/src/craft/migrations/Install.php:61-72`. **No Phase 3 modifications required.**

CONTEXT.md `<code_context>` listed this as a Phase 3 modification — that framing is stale. REQUIREMENTS.md FND-02 confirms Phase 1 shipped it. The Phase 3 disposition is `verify, no-op`. Doctor's 6th check (§24) is the only new state-table-adjacent surface.

---

### 26. `src/models/Settings.php` — NO REQUIRED PHASE 3 CHANGES

**Analog (self):** `src/models/Settings.php` (115 LOC).

`$dryRunDefault = true` already declared at line 52 per Phase 1 / D-15 (forward-looking declarations). Phase 3 has no new Settings surface. CFG-01 (CP form) is Phase 4.

If Phase 3's plans surface a need for a new Settings field (e.g. `$preloadAssetsDefault`, `$migrationBatchSize`), follow the 02.1-PATTERNS.md §2 4-step recipe (declare property → add to behaviors → add `??=` env line → add rules entry). Default stance: defer to Phase 4 unless rehearsal proves a need.

---

### 27. `.planning/phases/03-etl-pipeline-field-handlers/RECONCILIATION.md` (greenfield artifact)

**Analog:** `.planning/phases/02.1-source-introspection/RECONCILIATION.md` (the template).

Per CONTEXT.md D-46, every Phase 3 plan that ports a v1 file MUST emit a per-plan RECONCILIATION section. The phase-level RECONCILIATION.md aggregates them at phase close (Plan N pattern from Phase 02.1).

**Section template (one per ported file):**

```markdown
## <ServiceName> reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/<path>` (<LOC> LOC)
**v2 file:** `src/<flatpath>` (<LOC> LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| ... | ... | ported / dropped intentionally / dropped accidentally → patched | ... |

### Walk of remaining v1 lines (for any rule the explicit-rules table missed)

- ...

### Accidentally-dropped rules — patch list

1. ...
```

**Phase 3 phase-summary table follows the 02.1 shape:**

| Pair | ported | dropped intentionally | dropped accidentally |
|------|---:|---:|---:|

The summary lands in the Plan N closing artifact, NOT the per-plan RECONCILIATION sections.

---

## Shared Patterns

### Shared Pattern 1: Plugin::init() sibling-DI wiring (75a95bc)

**Source:** `src/Plugin.php` lines 130–149 (Phase 02.1 fix commit 75a95bc).

**Apply to:** Every Phase 3 service that depends on another sibling component.

**The bug being prevented:** `Plugin::config()` registers components as bare class strings. Yii instantiates them via `Yii::createObject($class)` which calls the no-arg constructor only — every `public ?Foo $dep = null` slot stays null. Without explicit injection in `init()`, the first call into the service NPEs.

**The fix pattern:** after `parent::init()`, before `controllerNamespace` setup, every service-with-deps gets explicit property assignment from sibling components on `$this`. Components reference each other through the magic-property accessors (`$this->fooService`) which Yii resolves through `config()`.

**Phase 02.1 covered:** `kunstmaanSourceScanner`, `kunstmaanPageStructureScanner`, `mappingAuditor`. **Phase 3 adds:** all ~10 ETL services + handler-registry registrations + matrix/asset/ckeditor/finalize sibling injections (see §22 above).

**Anti-pattern (v1):** closure-DI via `$this->set('foo', function() use ($self) { ... })`. Do NOT replicate.

---

### Shared Pattern 2: MigrationFilters piping through every stage

**Source:** Phase 2 / D-10 (`src/console/AnalyzeController.php:78`, `src/console/MapController.php:62`).

**Apply to:** Every Phase 3 controller action + every Phase 3 service that operates on legacy data.

**The piping shape:** Controller resolves `$filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since)` once; passes the same `MigrationFilters` instance through every stage call. Services accept `MigrationFilters` as an explicit method parameter (NEVER pull it from a global).

**Stages in Phase 3 that consume filters:**

- `ExtractService::run(array $mapping, MigrationFilters $filters, array $options = [])` — entities allow-list scopes the FQCN walk; locales scope the `kuma_node_translations` JOIN; since adds a date predicate.
- `TransformService::run($mapping, $filters, $options)` — locales scope the per-site loop in the resolver context construction.
- `AssetMigrationService::ingestReferenced(MigrationOptions $opts, MigrationFilters $filters)` — locales scope the per-site state-row writes.
- `AtomicMigrationService::migrateOneEntry(...)` — receives filters via the controller-injected MigrationOptions or as a separate param (researcher decides).
- `MigrateController::actionTruncate` — filters scope the DELETE WHERE clauses (D-51 — no "nuke everything" footgun).

**Critical invariant (FILT-02):** "a row excluded at extract is also absent from verify counts." Filters cannot be re-applied or relaxed mid-pipeline. The constructor-only / readonly `MigrationFilters` VO (Phase 2 / Plan 01) makes this structurally impossible to violate.

---

### Shared Pattern 3: NeverProductionTrait gate-first idiom (Phase 1 / D-20)

**Source:** `src/NeverProductionTrait.php` (19 LOC) + every controller action's first statement.

**Apply to:** Every `MigrateController` action that reads legacy DB or writes Craft (i.e., all 6 of `actionIndex`/`actionExtract`/`actionTransform`/`actionLoad`/`actionFinalize`/`actionTruncate`, plus the existing `actionInstall`).

**Pattern** (from `src/console/AnalyzeController.php:70-73` + `src/console/MigrateController.php:30-33`):

```php
public function actionXxx(): int
{
    // FND-04 / D-20: NeverProduction guard FIRST — before any legacy DB read or Craft write.
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    // ... rest of action ...
}
```

**`use NeverProductionTrait;`** must appear at the top of the controller class body. Already present in `MigrateController.php:20`.

**Acceptance grep:** for every Phase 3 controller action, the **first statement** of the method body is `if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }`. Phase 1 / D-20 acceptance grep enforces this at byte level — Phase 3 plans add new actions to that grep's allow-list.

---

### Shared Pattern 4: Plain-text OK/WARN/FAIL emit (Phase 1 / D-19)

**Source:** `src/console/DoctorController.php` (5-check shape, lines 56–60) + `src/console/AnalyzeController.php` (D-42 11-step shape).

**Apply to:** Every Phase 3 controller step + every per-entry progress line (ETL-06).

**Format:**

```
  OK   <action> [— <detail>]
  WARN <action> [— <detail>]
  FAIL <action> [— <detail>]
```

Two-space indent + 4-char status word + space + free text. ANSI colors via `Console::FG_GREEN` / `Console::FG_YELLOW` / `Console::FG_RED`. **Never use emoji or unicode.**

**For per-entry progress (ETL-06):**

```
[N/total] <slug-or-key> → created|updated|skipped|FAILED: <reason>
```

The `[N/total]` prefix replaces the `OK/WARN/FAIL` word; the verb after `→` is the outcome. `FAILED:` is the only one that prints to stderr; the others print to stdout.

---

### Shared Pattern 5: writeAtomic / writeAtomicJson for storage/migration/ artifacts

**Source:** Phase 2 / D-07 — `src/mapping/MappingFile.php::writeAtomic` / `writeAtomicJson`.

**Apply to:** Every artifact under `storage/migration/` written by Phase 3 — REPORT.md, MAPPING-AUDIT.md updates (if any), per-run logs, etc.

**Pattern:**

```php
$plugin->mappingFile->writeAtomic($path, $contents);   // for text/markdown
$plugin->mappingFile->writeAtomicJson($path, $data);   // for JSON
```

Both are tmp+rename atomic, durable across crashes mid-write.

---

### Shared Pattern 6: storage/migration directory as artifact root

**Source:** `src/console/AnalyzeController.php:111` (`$storageDir = Craft::$app->path->getStoragePath() . '/migration';`).

**Apply to:** Every Phase 3 controller that writes artifacts.

Phase 3 adds these artifacts under that root:

- `REPORT.md` — extended with D-50 failures + D-52 counts (existing file from Phase 2 — append/overwrite policy is researcher's call).
- (no new disk artifacts per D-48 — extract/transform are streaming).

**Doctor's `checkStorageDir` (Phase 1 / D-18)** auto-creates this directory at 0755. Every controller can assume it exists when run after `doctor`.

---

## No Analog Found

No Phase 3 file lacks an analog. Every new file maps to either:

- a v1 source-of-truth (verbatim port, modulo namespace flatten + import retargeting), OR
- a v2 sibling pattern (MigrateController → AnalyzeController, Doctor 6th check → Doctor 5th check, Plugin.php registrations → 75a95bc init() block).

**Exception (greenfield orchestration only):** `migrate/finalize` walker — the v1 ckeditor rewriter is per-row inline; v1 has no "walk every CKEditor field after the load pass" orchestrator. Phase 3 builds this greenfield at the controller level using `Entry::find()->siteId('*')->all()` + per-field detection (researcher designs at plan time). The `rewrite()` method itself is verbatim from v1; only the walker is new.

---

## Metadata

**Analog search scope:**
- v1 brownfield root: `~/Sites/craft-kunstmaan-migrator/src/{bridge,kunstmaan,craft}/`
- v2 self-references: `src/console/`, `src/Plugin.php`, `src/db/`, `src/migrations/`, `src/models/`, `src/source/`, `src/mapping/`, `src/filter/`, `src/locale/`
- Phase 02.1 priors: `.planning/phases/02.1-source-introspection/02.1-PATTERNS.md`, `RECONCILIATION.md`

**Files scanned:**
- v1 source files read in full or partial: 14 (FieldHandler, FieldHandlerRegistry, DeferredAssetToken, ResolverContext, MigrationStateReader, AtomicMigrationService, MigrationStateService partial, AssetMigrationService partial, TransformService partial, CkeditorRewriterService partial, ExtractService partial, EntryMigrationService partial, MigrationOptions, AssetPathResolver, TaxonomyResolver, BulkNameMatchTaxonomyResolver, AttachService, AssetHandler, MatrixHandler, PlainTextHandler, RelationHandler partial, SplitNameHandler, v1 Plugin.php partial, v1 Install.php).
- v2 self files read in full: 6 (Plugin.php, AnalyzeController.php, MigrateController.php, DoctorController.php, MapController.php partial, Install.php, NeverProductionTrait.php, db/LegacyDbService.php, models/Settings.php).
- Phase 02.1 docs: 2.

**Pattern extraction date:** 2026-04-26.
