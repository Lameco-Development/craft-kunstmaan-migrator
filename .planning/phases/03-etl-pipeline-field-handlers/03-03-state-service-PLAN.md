---
phase: 03-etl-pipeline-field-handlers
plan: 03
type: execute
wave: 1
depends_on: ['03-02']
files_modified:
  - src/load/MigrationStateService.php
autonomous: true
requirements: [ETL-05]
must_haves:
  truths:
    - "MigrationStateService is a Yii Component implementing MigrationStateReader; the narrow read interface and the wide write surface live on the same class but downstream consumers (handlers) only see the narrow type via ResolverContext::$state."
    - "record() writes a row keyed on (source, sourceKey, siteId) UNIQUE per the Phase 1 / FND-02 schema (which already shipped the table — Install.php no-op for Phase 3 per PATTERNS §25)."
    - "$targetUid null-coercion to '' (empty string) at write time avoids violating the NOT NULL DEFAULT '0' constraint Craft's uid() helper renders on MySQL."
    - "$statePrefix default 'kunstmaanmigrator_state' must stay aligned with src/migrations/Install.php STATE_TABLE — schema-sync invariant."
  artifacts:
    - path: "src/load/MigrationStateService.php"
      provides: "ETL-05 idempotency CRUD over kunstmaanmigrator_state."
      min_lines: 300
  key_links:
    - from: "src/load/MigrationStateService.php"
      to: "src/load/MigrationStateReader.php"
      via: "implements MigrationStateReader"
      pattern: "implements MigrationStateReader"
    - from: "src/load/MigrationStateService.php"
      to: "src/migrations/Install.php"
      via: "$statePrefix matches STATE_TABLE constant"
      pattern: "kunstmaanmigrator_state"
---

<objective>
Port the 356-LOC MigrationStateService verbatim from v1 — the read+write CRUD over `kunstmaanmigrator_state`. The table itself already shipped Phase 1 / FND-02 (Install.php is a no-op for Phase 3 per PATTERNS §25). This plan adds the service that reads/writes the table.

Wave 1 — depends on Plan 03-02 only (the MigrationStateReader interface). Implements that interface so handlers get the narrow type via ResolverContext.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md
@.planning/phases/02.1-source-introspection/02.1-PATTERNS.md

<interfaces>
`src/load/MigrationStateService.php`:
```php
namespace lameco\kunstmaanmigrator\load;

use Craft;
use yii\base\Component;
use yii\db\Connection;

class MigrationStateService extends Component implements MigrationStateReader
{
    public string $statePrefix = 'kunstmaanmigrator_state';

    // Read API (MigrationStateReader contract):
    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int;
    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string;
    /** @return array<string, mixed>|null */
    public function get(string $source, string $key, ?int $siteId = null): ?array;

    // Write API (NOT in MigrationStateReader — handlers must not see this):
    public function record(string $source, string|int $key, ?int $siteId, string $targetType, ?int $targetId, ?string $targetUid, array $meta = []): void;
    public function updateMeta(string $source, string|int $key, ?int $siteId, array $meta): void;
    public function forget(string $source, string|int $key, ?int $siteId = null): void;
    public function runOnce(string $token, callable $fn): void;
    public function has(string $source, string|int $key, ?int $siteId = null): bool;
}
```

The schema for the underlying table (already shipped in Phase 1):
- `id`, `source`, `sourceKey`, `targetType`, `targetId`, `targetUid`, `siteId`, `meta`, `dateCreated`, `dateUpdated`
- UNIQUE INDEX on `(source, sourceKey, siteId)`
- INDEX on `(dateUpdated)`
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port MigrationStateService</name>
  <files>src/load/MigrationStateService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/load/MigrationStateService.php (v1, 356 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §6 (MigrationStateService — full reshape recipe)
    - src/load/MigrationStateReader.php (Plan 03-02 — interface to implement)
    - src/migrations/Install.php (Phase 1 — confirm STATE_TABLE constant value matches $statePrefix default)
    - .planning/phases/02.1-source-introspection/02.1-PATTERNS.md §1 (Yii Component header + final class extends Component pattern)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/load/MigrationStateService.php` to `src/load/MigrationStateService.php`. Apply these mechanical edits:

    1. Namespace `lameco\kunstmaanmigrator\bridge\load` → `lameco\kunstmaanmigrator\load`.
    2. Retarget the `implements MigrationStateReader` import: change `use lameco\kunstmaanmigrator\bridge\fields\MigrationStateReader;` to `use lameco\kunstmaanmigrator\load\MigrationStateReader;`.
    3. Drop the v1 docblock note at lines 28-29 about `kunstmaanSourceId` custom-field replacement (PATTERNS §6 reshape #3 — per CONTEXT D-48 the state table covers entries too in v2; the v1 hedge no longer applies).
    4. **Verify** `Yii\helpers\Db` import (or `yii\helpers\Db` lowercase) is present. The `Generator` import (v1 may have it for Generator returns) — verify usage; if no `Generator` return type appears in v2 ports of the class body, drop the import; if it does appear, keep it.
    5. Add `declare(strict_types=1);` if v1 omits.

    DO NOT change:
    - The class declaration `class MigrationStateService extends Component implements MigrationStateReader` — it must stay non-final because v1 allows subclassing for testing.
    - The `public string $statePrefix = 'kunstmaanmigrator_state';` default — schema-sync invariant per PATTERNS §6.
    - The `private ?string $tableName = null;` slot.
    - The `private function table(): string { return $this->tableName ??= '{{%' . $this->statePrefix . '}}'; }` body.
    - The `private function db(): Connection { return Craft::$app->db; }` body.
    - The entire CRUD surface (record / get / has / forget / updateMeta / getTargetId / getTargetUid / runOnce) — every method body, every variable name, every SQL fragment must be verbatim from v1 lines 74-356.
    - The `$targetUidSafe = $targetUid ?? '';` coercion (v1 lines 132-134 area) — load-bearing because Craft's `uid()` helper renders MySQL `char(36) NOT NULL DEFAULT '0'` and passing null violates NOT NULL.
    - SQL bound-parameter usage everywhere; never interpolate `$source` / `$key` / etc. into SQL strings.

    **Schema-sync verification.** Before finishing, run `grep -c "STATE_TABLE.*=.*'{{%kunstmaanmigrator_state}}'" src/migrations/Install.php`. Confirm it returns 1. If it returns 0, the install constant has drifted — STOP and report; do not proceed (this is the schema-sync invariant from PATTERNS §6).

    Add a docblock comment at the top of the class body (above `public string $statePrefix`):
    ```php
    /**
     * Schema-sync invariant: $statePrefix MUST stay aligned with src/migrations/Install.php's
     * STATE_TABLE constant ('{{%kunstmaanmigrator_state}}'). Any rename breaks both DDL and CRUD.
     * Phase 1 / FND-02 shipped the DDL; Phase 3 / Plan 03-03 ships the CRUD.
     */
    ```
  </action>
  <verify>
    <automated>php -l src/load/MigrationStateService.php && grep -c "STATE_TABLE.*=.*'{{%kunstmaanmigrator_state}}'" src/migrations/Install.php</automated>
  </verify>
  <done>
    - `src/load/MigrationStateService.php` exists; `php -l` returns "No syntax errors".
    - `grep -c "class MigrationStateService extends Component implements MigrationStateReader" src/load/MigrationStateService.php` returns 1.
    - `grep -c "public string \\$statePrefix = 'kunstmaanmigrator_state'" src/load/MigrationStateService.php` returns 1.
    - `grep -c "use lameco\\\\kunstmaanmigrator\\\\load\\\\MigrationStateReader;" src/load/MigrationStateService.php` returns 1.
    - `grep -c "MigrationConfigError" src/load/MigrationStateService.php` returns 0 (none expected — v1 didn't import this in the state service).
    - `grep -c "function record(" src/load/MigrationStateService.php` returns 1.
    - `grep -c "function get(" src/load/MigrationStateService.php` >= 1.
    - `grep -c "function has(" src/load/MigrationStateService.php` returns 1.
    - `grep -c "function forget(" src/load/MigrationStateService.php` returns 1.
    - `grep -c "function updateMeta(" src/load/MigrationStateService.php` returns 1.
    - `grep -c "function runOnce(" src/load/MigrationStateService.php` returns 1.
    - `grep -c "function getTargetId(" src/load/MigrationStateService.php` returns 1.
    - `grep -c "function getTargetUid(" src/load/MigrationStateService.php` returns 1.
    - `grep -c "?? ''" src/load/MigrationStateService.php` >= 1 (the targetUid null coercion preserved).
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\bridge" src/load/MigrationStateService.php` returns zero matches.
    - File has at least 300 lines (verbatim port from 356 LOC v1).
    - The grep for STATE_TABLE in Install.php returns 1 (schema-sync invariant holds).
  </done>
</task>

</tasks>

<reconciliation>
## MigrationStateService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/MigrationStateService.php` (356 LOC)
**v2 file:** `src/load/MigrationStateService.php` (~340 LOC after namespace flatten + import retargeting + v2 declare(strict_types) addition)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 28-29 — docblock note about kunstmaanSourceId custom-field replacement | v1 hedge that the state table might be replaced by per-element custom field. | dropped intentionally | Per CONTEXT D-48: v2 commits to state-table-only resume model. Hedge no longer applies. |
| Lines 48-67 — class header + statePrefix + table() helper + db() helper | Schema-sync chokepoint. | ported verbatim (modulo namespace) | Same body. statePrefix invariant preserved. |
| Lines 74-356 — entire CRUD surface (record / get / has / forget / updateMeta / runOnce / getTargetId / getTargetUid) | All read/write operations. | ported verbatim | Same file. |
| Line 132-134 area — `$targetUidSafe = $targetUid ?? '';` null coercion | NOT NULL DEFAULT '0' compatibility. | ported verbatim | Load-bearing — required for Craft's uid() helper compatibility. |
| `implements MigrationStateReader` import | v1 imports from `bridge/fields/`. | retargeted | v2 lands MigrationStateReader at `src/load/MigrationStateReader.php` per Plan 03-02 (PATTERNS §5). |

### Counts (Plan 03-03 only)
| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| MigrationStateService | 4 | 1 (kunstmaanSourceId hedge docblock) | 0 |

## Install.php disposition (no Phase 3 changes)

**v1 reference:** `~/Sites/craft-kunstmaan-migrator/src/craft/migrations/Install.php` lines 61-72 — `kunstmaanmigrator_state` table DDL.
**v2 reference:** `src/migrations/Install.php` lines 35-60 — already shipped Phase 1 / FND-02 with the byte-for-byte schema (PATTERNS §25).

**Phase 3 disposition:** `verify schema parity, no-op modification`. The state table exists from Phase 1's install. Plan 03-03's MigrationStateService reads/writes the existing table; no DDL changes are required.
</reconciliation>

<verification>
- `php -l src/load/MigrationStateService.php` returns "No syntax errors detected".
- All file-level acceptance greps pass.
- Schema-sync invariant: STATE_TABLE constant in Install.php matches `$statePrefix` default.
- Reconciliation table records 4 ported / 1 dropped intentionally / 0 dropped accidentally.
</verification>

<success_criteria>
- MigrationStateService ports verbatim with namespace flatten + interface retarget.
- All 8 CRUD methods preserve v1 bodies byte-for-byte.
- Schema-sync invariant documented and verified.
- Service implements MigrationStateReader narrow interface (the firewall pattern).
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-03-state-service-SUMMARY.md`.
</output>
