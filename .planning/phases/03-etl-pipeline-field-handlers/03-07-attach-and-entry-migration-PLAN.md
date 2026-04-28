---
phase: 03-etl-pipeline-field-handlers
plan: 07
type: execute
wave: 2
depends_on: ['03-03']
files_modified:
  - src/load/AttachService.php
  - src/load/EntryMigrationService.php
autonomous: true
requirements: [ETL-04]
must_haves:
  truths:
    - "AttachService::attachFieldToEntryType($entryTypeUid, $fieldUid): void is the idempotent field-to-entry-type attach helper. attachAllFromSettings() deferred to Phase 4 (Settings::entryTypeUids not declared until CFG-01)."
    - "EntryMigrationService::saveEntryForSites(int $sectionId, int $typeId, string $stateSource, string|int $stateKey, array $perSite, bool $force = false): Entry is the only Craft saveElement() call site allowed in the codebase — every other code path routes through this method (multi-site discipline)."
    - "Multi-site save uses propagateChanges=false on every saveElement call and re-loads each entry scoped to the target siteId before saving non-primary site content (Pitfall 2 avoidance, v1 docblock lines 53-57)."
    - "$sites map (kuma_locale → Craft site handle) populated in Plugin::init() (Plan 03-14) from LocalePreflight::detect() + Settings::$localeMap."
  artifacts:
    - path: "src/load/AttachService.php"
      provides: "Idempotent field-to-entry-type attach (Phase 4 expands attachAllFromSettings)."
      min_lines: 130
    - path: "src/load/EntryMigrationService.php"
      provides: "Multi-site entry save orchestrator. The only saveElement() consumer in the codebase."
      min_lines: 580
  key_links:
    - from: "src/load/EntryMigrationService.php"
      to: "src/load/MigrationStateService.php"
      via: "public ?MigrationStateService $stateService"
      pattern: "MigrationStateService"
---

<objective>
Two ports in this plan, grouped because they're both small (130 + 660 LOC area) and share the load-stage namespace:

1. **AttachService** — partial port from v1's 178-LOC craft/services/AttachService. Phase 3 scope: `attachFieldToEntryType($entryTypeUid, $fieldUid): void`. The `attachAllFromSettings()` method is deferred to Phase 4 because v2 Settings doesn't declare `$entryTypeUids` yet (CFG-01 owns).

2. **EntryMigrationService** — verbatim port of v1's 662-LOC craft/load/EntryMigrationService. The multi-site entry save orchestrator. Sole consumer of Craft's `saveElement()` API in the entire codebase — every other migration service routes through this method.

Wave 2 — depends on Plan 03-03 (MigrationStateService).
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
`src/load/AttachService.php`:
```php
namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\migrations\Install;
use yii\base\Component;

class AttachService extends Component
{
    public function attachFieldToEntryType(string $entryTypeUid, string $fieldUid): void;
    // attachAllFromSettings() — DEFERRED to Phase 4 / CFG-01.
}
```

`src/load/EntryMigrationService.php`:
```php
namespace lameco\kunstmaanmigrator\load;

use craft\elements\Entry;
use yii\base\Component;

class EntryMigrationService extends Component
{
    public ?MigrationStateService $stateService = null;
    /** @var array<string, string> kuma_locale → Craft site handle (filled by Plugin::init() from LocalePreflight + Settings) */
    public array $sites = [];

    public function saveEntryForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        bool $force = false,
    ): Entry;
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Partial port of AttachService — attachFieldToEntryType only; defer attachAllFromSettings to Phase 4</name>
  <files>src/load/AttachService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/services/AttachService.php (v1, 178 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §10 (AttachService — full reshape recipe)
    - src/migrations/Install.php (lines 1-30 — confirm v2 namespace lameco\kunstmaanmigrator\migrations and class signature)
    - src/models/Settings.php (lines 40-80 — confirm Settings does NOT declare $entryTypeUids; that's the deferral driver)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/craft/services/AttachService.php` to `src/load/AttachService.php`. Apply per PATTERNS §10:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\craft\services` → `lameco\kunstmaanmigrator\load`.

    **2. Retarget the Install import:** `use lameco\kunstmaanmigrator\...\Install;` → `use lameco\kunstmaanmigrator\migrations\Install;`.

    **3. Drop and replace MigrationConfigError if present:** `\RuntimeException`.

    **4. attachAllFromSettings deferral.**
    Locate the v1 method `public function attachAllFromSettings(...)` (lines ~147-177 area in v1). **Remove the method body entirely** and replace with this stub:
    ```php
    /**
     * DEFERRED to Phase 4 / CFG-01 — Settings::$entryTypeUids is not declared yet
     * (v2 Settings only ships connection + AI fields per Phase 1 / D-15). The CP
     * Settings form (CFG-01) introduces the field; this method is reinstated in
     * the same phase with the v1 body.
     *
     * For Phase 3, throw to make the omission explicit if anything calls this.
     */
    public function attachAllFromSettings(): void
    {
        throw new \RuntimeException(
            'AttachService::attachAllFromSettings() is deferred to Phase 4 / CFG-01. '
            . 'Phase 3 only ships attachFieldToEntryType($entryTypeUid, $fieldUid).',
        );
    }
    ```

    **5. attachFieldToEntryType — preserve byte-for-byte.**
    The fast-path field-already-attached check (PATTERNS §10 lines 50-62) walks `$layout->getTabs()` then `$tab->getElements()` looking for a `getField()` matching `$fieldUid`. Returns early before touching project-config. Idempotent attach pattern (lines 37-137) — preserve all of it verbatim.

    **6. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: the fast-path early return logic, the project-config writes, the layout-tabs-element walk.
  </action>
  <verify>
    <automated>php -l src/load/AttachService.php</automated>
  </verify>
  <done>
    - `src/load/AttachService.php` exists; `php -l` returns "No syntax errors".
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\load;" src/load/AttachService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\migrations\\\\Install;" src/load/AttachService.php` returns 1.
    - `grep -c "function attachFieldToEntryType(string \\$entryTypeUid, string \\$fieldUid)" src/load/AttachService.php` returns 1.
    - `grep -c "function attachAllFromSettings" src/load/AttachService.php` returns 1.
    - `grep -c "deferred to Phase 4 / CFG-01" src/load/AttachService.php` returns 1.
    - `grep -c "MigrationConfigError" src/load/AttachService.php` returns 0.
    - File has at least 130 lines.
  </done>
</task>

<task type="auto">
  <name>Task 2: Verbatim port EntryMigrationService — the only saveElement consumer in the codebase</name>
  <files>src/load/EntryMigrationService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/load/EntryMigrationService.php (v1, 662 LOC — ENTIRE FILE)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §15 (EntryMigrationService reshape recipe)
    - src/load/MigrationStateService.php (Plan 03-03 — confirm record / has / get public surface)
    - src/locale/LocalePreflight.php (lines 1-50 — confirm detect() return shape; the $sites map is populated from this)
    - src/models/Settings.php (lines 40-80 — confirm $localeMap or similar field; if absent, $sites stays empty until Plugin::init() wires)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/craft/load/EntryMigrationService.php` to `src/load/EntryMigrationService.php`. Apply per PATTERNS §15:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\craft\load` → `lameco\kunstmaanmigrator\load`.

    **2. Drop and replace MigrationConfigError:** `\RuntimeException`.

    **3. Retarget any other v1 imports** (likely `bridge/load/MigrationStateService` → `load/MigrationStateService`, same namespace — drop the import).

    **4. $sites map handling.**
    v1 carries `public array $sites = []` populated in v1's `Plugin.php:292` via a hardcoded `['nl' => 'default', 'en' => 'en']`. v2 must populate from `Settings::$localeMap` + `LocalePreflight::detect()` at `Plugin::init()` time (Plan 03-14). For Plan 03-07, leave the property declaration as `public array $sites = [];` — empty default. Plugin::init() in Plan 03-14 sets it via:
    ```php
    $this->entryMigrationService->sites = $this->resolveSitesMap();
    ```
    where `resolveSitesMap()` is a Plugin helper composing LocalePreflight::detect() and Settings::$localeMap. Mark the property:
    ```php
    /**
     * kuma_locale (string) → Craft site handle (string).
     * Populated by Plugin::init() (Plan 03-14) from LocalePreflight::detect() + Settings::$localeMap.
     * Empty default — saveEntryForSites() throws if accessed while empty.
     */
    public array $sites = [];
    ```

    **5. Preserve verbatim — multi-site save with propagateChanges=false** (PATTERNS §15, v1 docblock lines 53-57):
    The docblock states this is the ONLY saveElement() call site in the codebase. Plan 03-14's controller wiring + every other migration service (AtomicMigrationService, AssetMigrationService) MUST route entry saves through this class. Plan 03-14 enforces this with a phase-level grep check.

    **6. Preserve verbatim — saveEntryForSites public surface** (v1 lines 95-102):
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

    **7. Add `declare(strict_types=1);` if v1 omits.**

    **8. Sibling-DI:**
    ```php
    public ?MigrationStateService $stateService = null;
    ```

    DO NOT change: the saveElement call site shape, the propagate=false flag, the per-site re-load pattern, the multi-site save loop, any private helper, any field-mapping logic.
  </action>
  <verify>
    <automated>php -l src/load/EntryMigrationService.php</automated>
  </verify>
  <done>
    - `src/load/EntryMigrationService.php` exists; `php -l` returns "No syntax errors".
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\load;" src/load/EntryMigrationService.php` returns 1.
    - `grep -c "function saveEntryForSites(" src/load/EntryMigrationService.php` returns 1.
    - `grep -c "public array \\$sites = \\[\\]" src/load/EntryMigrationService.php` returns 1.
    - `grep -c "public ?MigrationStateService \\$stateService = null" src/load/EntryMigrationService.php` returns 1.
    - `grep -c "MigrationConfigError" src/load/EntryMigrationService.php` returns 0.
    - `grep -c "propagate" src/load/EntryMigrationService.php` >= 1 (multi-site discipline preserved).
    - `grep -c "saveElement" src/load/EntryMigrationService.php` >= 1 (this IS the saveElement consumer).
    - File has at least 580 lines.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/load/EntryMigrationService.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## AttachService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/craft/services/AttachService.php` (178 LOC)
**v2 file:** `src/load/AttachService.php` (~135 LOC after attachAllFromSettings stub-out)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 37-137 — `attachFieldToEntryType` body + fast-path check | Idempotent field attach + early return when field already in layout. | ported verbatim | Same file. |
| Lines 50-62 — fast-path layout-walks | Walks `$layout->getTabs() → $tab->getElements()` looking for `getField()` matching `$fieldUid`. | ported verbatim | Load-bearing — preserves operator-observable Project-Config quietness. |
| Lines 147-177 — `attachAllFromSettings` body | v1 reads `Settings::$entryTypeUids` and walks. | partially ported — Phase 4 follow-up | Method stub throws `\RuntimeException` with "deferred to Phase 4 / CFG-01" message. v2 Settings does not declare `$entryTypeUids` until CFG-01 introduces the CP form. Plan 03-14 sibling-DI wiring does NOT call this method. Phase 4 reinstates v1 body. |

## EntryMigrationService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/craft/load/EntryMigrationService.php` (662 LOC)
**v2 file:** `src/load/EntryMigrationService.php` (~640 LOC after namespace flatten)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 53-57 docblock — propagateChanges=false discipline | Sole saveElement consumer; multi-site re-load before save. | ported verbatim | Plan 03-14 enforces "no other saveElement calls" via phase-level grep. |
| Lines 95-102 — `saveEntryForSites` public signature | API surface. | ported verbatim | Same args. |
| `public array $sites = []` | v1 hardcoded `['nl' => 'default', 'en' => 'en']` in v1's Plugin.php:292. | reshape: empty default + Plugin::init() population from LocalePreflight + Settings::$localeMap | Plan 03-14 wires. Doctrine note: v2 must throw if `$sites` is empty when `saveEntryForSites` is called — defensive guard added by executor at the entry of the method. |
| MigrationConfigError throws | Typed errors. | dropped intentionally | Replaced with `\RuntimeException`. |

### Counts (Plan 03-07 only)
| Pair | ported | dropped intentionally | dropped accidentally | partially ported |
|---|---:|---:|---:|---:|
| AttachService | 2 | 1 (MigrationConfigError if present) | 0 | 1 (attachAllFromSettings) |
| EntryMigrationService | 2 | 1 (MigrationConfigError) | 0 | 0 (sites-map is reshape, not partial) |
</reconciliation>

<verification>
- `php -l` exits 0 for both files.
- AttachService::attachFieldToEntryType ports verbatim; attachAllFromSettings stubs with explicit Phase 4 deferral.
- EntryMigrationService is the sole saveElement consumer (Plan 03-14 enforces).
- propagateChanges=false discipline preserved.
- $sites map populated by Plugin::init() in Plan 03-14 from LocalePreflight + Settings.
</verification>

<success_criteria>
- AttachService partial port lands with explicit Phase 4 deferral marker.
- EntryMigrationService verbatim port preserves the multi-site discipline.
- Reconciliation documents 2+2 ported / 1+1 dropped intentionally / 1 partially ported.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-07-attach-and-entry-migration-SUMMARY.md`.
</output>
