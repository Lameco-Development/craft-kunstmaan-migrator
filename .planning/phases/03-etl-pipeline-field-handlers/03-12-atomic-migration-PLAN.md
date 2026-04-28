---
phase: 03-etl-pipeline-field-handlers
plan: 12
type: execute
wave: 4
depends_on: ['03-03', '03-05', '03-07']
files_modified:
  - src/load/AtomicMigrationService.php
  - src/load/MigrationReport.php
autonomous: true
requirements: [ETL-01, ETL-04, ETL-05, FH-04]
must_haves:
  truths:
    - "AtomicMigrationService is the per-entry transactional driver — Phase A (file I/O before transaction, e.g. asset materialisation) + Phase B (Craft DB transaction wrapping saveEntryForSites + state row record). Atomic-always-on per ETL-04 (no flag)."
    - "Idempotency gate (ETL-05): existingId !== null && !$overwrite short-circuits to $report->incr('skipped'). State-table presence skips the entry."
    - "Deferred-token resolver (FH-04 load-pass): /^asset:\\d+$/ + capture form /^asset:(\\d+)$/ regex pair walks DeferredAssetToken-emitted lists and resolves each via AssetMigrationService. Format and consumer are tightly coupled."
    - "MigrationReport VO is a small per-run accumulator (warnings + counters) — used by AtomicMigrationService inline, by AssetMigrationService via Plan 03-05 marker-replacement, and by MigrateController to render REPORT.md (Plan 03-14)."
  artifacts:
    - path: "src/load/MigrationReport.php"
      provides: "Per-run counters + warnings VO consumed across migration services."
      min_lines: 40
    - path: "src/load/AtomicMigrationService.php"
      provides: "ETL-04 atomic-always-on per-entry transactional driver. 255 LOC verbatim port modulo SeoMigration drop + MigrationReport reuse."
      min_lines: 220
  key_links:
    - from: "src/load/AtomicMigrationService.php"
      to: "src/load/EntryMigrationService.php"
      via: "$module->entryMigrationService->saveEntryForSites(...) inside transaction closure"
      pattern: "saveEntryForSites"
    - from: "src/load/AtomicMigrationService.php"
      to: "src/load/MigrationStateService.php"
      via: "$module->migrationStateService"
      pattern: "migrationStateService"
    - from: "src/load/AtomicMigrationService.php"
      to: "src/load/AssetMigrationService.php"
      via: "ingestAndResolveAssets walk; resolveFromLegacyId per asset:N token"
      pattern: "resolveFromLegacyId"
---

<objective>
Two artifacts:

1. **MigrationReport VO** — small greenfield-from-v1 VO carrying per-run counters (`incr`) + warnings (`warn`). Consumed by AtomicMigrationService inline, by AssetMigrationService (Plan 03-05's deferred markers re-bind here), and by MigrateController for REPORT.md render (Plan 03-14).

2. **AtomicMigrationService** — port v1's 255-LOC per-entry transactional driver. Phase A asset materialisation outside the transaction (file copies aren't transactional); Phase B `Craft::$app->db->transaction(...)` wrapping saveEntryForSites + state record + (Phase 4 reinstates) seoMigrationService closure call. ETL-04 atomic-always-on. ETL-05 idempotency gate. FH-04 deferred-token resolver.

Wave 4 — depends on Plan 03-03 (MigrationStateService) + 03-05 (AssetMigrationService.resolveFromLegacyId) + 03-07 (EntryMigrationService.saveEntryForSites).
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
`src/load/MigrationReport.php` (small VO):
```php
namespace lameco\kunstmaanmigrator\load;

final class MigrationReport
{
    /** @var array<string, int> */
    public array $counts = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<array{legacyId: string|int, slug: ?string, handler: ?string, message: string, trace: ?string}> */
    public array $failures = [];

    public function incr(string $bucket, int $by = 1): void;
    public function warn(string $message): void;
    public function recordFailure(string|int $legacyId, ?string $slug, ?string $handler, \Throwable $e): void;
}
```

`src/load/AtomicMigrationService.php`:
```php
namespace lameco\kunstmaanmigrator\load;

use yii\base\Component;

class AtomicMigrationService extends Component
{
    public ?MigrationStateService  $migrationStateService = null;
    public ?EntryMigrationService  $entryMigrationService = null;
    public ?AssetMigrationService  $assetMigrationService = null;

    /** Per-entry atomic load. ETL-04 always-on. */
    public function migrateOneEntry(
        array $transformedRow,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void;
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create MigrationReport VO + back-bind Plan 03-05's deferred markers</name>
  <files>src/load/MigrationReport.php</files>
  <read_first>
    - src/load/AssetMigrationService.php (Plan 03-05 — locate every "// MigrationReport VO deferred to Plan 03-13" marker)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §13 (AtomicMigrationService — MigrationReport option (a))
    - src/load/MigrationOptions.php (Plan 03-02 — VO pattern reference)
  </read_first>
  <action>
    Create greenfield-from-v1 file `src/load/MigrationReport.php`. Note: Plan name says "Plan 03-13" in the legacy markers but this is Plan 03-12 in the final wave plan ordering — both refer to this file. The marker text from Plan 03-05 reads "MigrationReport VO deferred to Plan 03-13"; that's documentation lag, not a contradiction.

    **PSR-4 path:** `src/load/MigrationReport.php`.
    **Namespace:** `lameco\kunstmaanmigrator\load`.
    **File header:** `<?php declare(strict_types=1);` + namespace.
    **Class:** `final class MigrationReport`.

    **Body (executor implements):**

    ```php
    /**
     * Per-run counters + warnings + failures accumulator.
     * Consumed by:
     *  - AtomicMigrationService::migrateOneEntry (Plan 03-12) — inline incr/warn during per-entry pipeline
     *  - AssetMigrationService (Plan 03-05) — re-binds deferred markers to incr/warn calls
     *  - MigrateController (Plan 03-14) — renders REPORT.md per D-50 (failures) + D-52 (counts)
     */
    final class MigrationReport
    {
        /** @var array<string, int> bucketName → count */
        public array $counts = [];

        /** @var list<string> */
        public array $warnings = [];

        /** @var list<array{legacyId: string|int, slug: ?string, handler: ?string, message: string, trace: ?string}> */
        public array $failures = [];

        public function incr(string $bucket, int $by = 1): void
        {
            $this->counts[$bucket] = ($this->counts[$bucket] ?? 0) + $by;
        }

        public function warn(string $message): void
        {
            $this->warnings[] = $message;
        }

        public function recordFailure(string|int $legacyId, ?string $slug, ?string $handler, \Throwable $e): void
        {
            // Trace excerpt: last 5 frames per CONTEXT D-50.
            $trace = $e->getTrace();
            $excerpt = array_slice($trace, 0, 5);
            $this->failures[] = [
                'legacyId' => $legacyId,
                'slug' => $slug,
                'handler' => $handler,
                'message' => $e->getMessage(),
                'trace' => $this->renderTrace($excerpt),
            ];
            $this->incr('failed');
        }

        private function renderTrace(array $frames): string
        {
            // Each frame as "file:line method" — preserves D-50 stack-trace excerpt format.
            $lines = [];
            foreach ($frames as $f) {
                $file = $f['file'] ?? '?';
                $line = $f['line'] ?? '?';
                $cls = $f['class'] ?? '';
                $type = $f['type'] ?? '';
                $fn = $f['function'] ?? '?';
                $lines[] = "{$file}:{$line} {$cls}{$type}{$fn}";
            }
            return implode("\n", $lines);
        }
    }
    ```

    **Back-bind Plan 03-05's deferred markers.** Plan 03-05 left `// MigrationReport VO deferred to Plan 03-13` markers at every site where v1 called `$report->incr('xxx')` / `$report->warn('xxx')`. This task does NOT modify Plan 03-05's file directly — instead it ensures the VO is now present so Plan 03-14's wiring round can re-bind. The executor running Plan 03-12 may optionally update the marker comments in AssetMigrationService.php to read `// MigrationReport VO available — Plan 03-14 wires the consumer.` but the actual re-binding of `$counts['xxx']++` accumulators back to `$report->incr('xxx')` is Plan 03-14's responsibility (it owns Plugin::init() sibling-DI + the controller integration).
  </action>
  <verify>
    <automated>php -l src/load/MigrationReport.php</automated>
  </verify>
  <done>
    - `src/load/MigrationReport.php` exists; `php -l` returns "No syntax errors".
    - File has at least 40 lines.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\load;" src/load/MigrationReport.php` returns 1.
    - `grep -c "final class MigrationReport" src/load/MigrationReport.php` returns 1.
    - `grep -c "function incr(string \\$bucket" src/load/MigrationReport.php` returns 1.
    - `grep -c "function warn(string \\$message): void" src/load/MigrationReport.php` returns 1.
    - `grep -c "function recordFailure" src/load/MigrationReport.php` returns 1.
    - `grep -c "Throwable" src/load/MigrationReport.php` >= 1 (failure-recording surface).
    - `grep -c "array_slice" src/load/MigrationReport.php` >= 1 (D-50 5-frame stack excerpt).
  </done>
</task>

<task type="auto">
  <name>Task 2: Verbatim port AtomicMigrationService — atomic-always-on transaction shape preserved + SEOmatic closure dropped</name>
  <files>src/load/AtomicMigrationService.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/load/AtomicMigrationService.php (v1, 255 LOC — ENTIRE FILE)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §13 (AtomicMigrationService — full reshape recipe)
    - src/load/MigrationReport.php (Task 1 output — confirm public surface)
    - src/load/MigrationStateService.php (Plan 03-03)
    - src/load/EntryMigrationService.php (Plan 03-07 — confirm saveEntryForSites signature)
    - src/load/AssetMigrationService.php (Plan 03-05 — confirm resolveFromLegacyId signature)
    - src/load/MigrationOptions.php (Plan 03-02 — confirm 6-arg ctor)
    - src/fields/DeferredAssetToken.php (Plan 03-01 — paired-regex contract documentation)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AtomicMigrationService.php` to `src/load/AtomicMigrationService.php`. Apply per PATTERNS §13:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\load` → `lameco\kunstmaanmigrator\load`.

    **2. Drop the v1 `MigrationReport` import** if it's from a different namespace. v2's MigrationReport is in same namespace as this file — drop the import (or leave none if v1 didn't import it).

    **3. Drop and replace MigrationConfigError if present.**

    **4. Drop `AssetResolver` import.** v2 uses `AssetMigrationService::resolveFromLegacyId(int): int` for asset resolution. Replace any v1 `$assetResolver->...` calls with `$this->assetMigrationService->resolveFromLegacyId(...)`. Sibling-DI pattern: add `public ?AssetMigrationService $assetMigrationService = null;` slot.

    **5. PRESERVE BYTE-FOR-BYTE — Phase A (file I/O before transaction)** (PATTERNS §13, v1 lines 117-135):
    Asset materialisation must happen outside the transaction because file copies aren't transactional. The v1 body assembles `$refIdsByLocale` and pre-materialises assets via the deferred-token resolver (step 7 below) BEFORE the `Craft::$app->db->transaction(...)` call.

    **6. PRESERVE BYTE-FOR-BYTE — Phase B (Craft DB transaction)** (PATTERNS §13, v1 lines 145-184) **with one explicit drop**:
    ```php
    Craft::$app->db->transaction(function () use ($module, $section, $entryType, $sourceStream, $sourceId, $perSite, $overwrite, $opts, $refIdsByLocale): void {
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

        // PHASE 4 / ADP-01: SEOmatic closure-call goes here.
        // For Phase 3, this site is left as a comment marker. The v1 line
        //     $module->seoMigrationService->migrateForEntry((int) $entry->id, $opts, $refIdsByLocale);
        // will be reinstated in Phase 4 inside the same closure (preserves atomicity with the entry save).
    });
    ```

    **Drop `seoMigrationService` call inside the closure** (PATTERNS §13). Replace with the comment marker shown above. SEOmatic is Phase 4. Document in RECONCILIATION as `partially ported — Phase 4 ADP-01 reinstates seoMigrationService closure call`.

    **7. PRESERVE BYTE-FOR-BYTE — deferred-token resolver** (PATTERNS §13, v1 lines 209-212):
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
    The two regexes (`/^asset:\d+$/` match form + `/^asset:(\d+)$/` capture form) are tightly coupled to `DeferredAssetToken::emit()` per Plan 03-01's paired-regex contract documentation. Preserve byte-for-byte.

    `$resolver` here refers to the asset resolver — wire to `$this->assetMigrationService->resolveFromLegacyId(...)`. The `$resolver` variable name comes from v1's body; either keep the name with `$resolver = $this->assetMigrationService;` line earlier in the method, or replace `$resolver->resolveFromLegacyId` with `$this->assetMigrationService->resolveFromLegacyId` directly. **Decision: replace inline** — reduces local-variable nesting; idiomatic v2.

    **8. PRESERVE BYTE-FOR-BYTE — idempotency gate** (PATTERNS §13, v1 lines 107-116):
    ```php
    // ETL-05 idempotency gate.
    $existingId = $this->migrationStateService->getTargetId($sourceStream, (string) $sourceId, null);
    if ($existingId !== null && !$overwrite) {
        $report->incr('skipped');
        return;
    }
    ```
    Same shape — state-table presence skip unless overwrite is set.

    **9. Sibling-DI slots:**
    ```php
    public ?MigrationStateService $migrationStateService = null;
    public ?EntryMigrationService $entryMigrationService = null;
    public ?AssetMigrationService $assetMigrationService = null;
    ```
    Plugin::init() (Plan 03-14) wires.

    **10. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: any failure-handling logic (D-50 continue-and-log via `$report->recordFailure(...)`), any per-locale ref tracking, any options-shape parsing, any pre-transaction validation.
  </action>
  <verify>
    <automated>php -l src/load/AtomicMigrationService.php</automated>
  </verify>
  <done>
    - `src/load/AtomicMigrationService.php` exists; `php -l` returns "No syntax errors".
    - File has at least 220 lines.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\load;" src/load/AtomicMigrationService.php` returns 1.
    - `grep -c "Craft::\\\$app->db->transaction" src/load/AtomicMigrationService.php` >= 1 (atomic-always-on transaction preserved).
    - `grep -c "saveEntryForSites" src/load/AtomicMigrationService.php` >= 1 (entry save called inside closure).
    - `grep -c '/\\^asset:\\\\d\\+\\$/' src/load/AtomicMigrationService.php` >= 1 (asset-token match regex preserved).
    - `grep -c '/\\^asset:(\\\\d+)\\$/' src/load/AtomicMigrationService.php` >= 1 (asset-token capture regex preserved).
    - `grep -c "resolveFromLegacyId" src/load/AtomicMigrationService.php` >= 1 (asset resolution call).
    - `grep -c "ETL-05 idempotency" src/load/AtomicMigrationService.php` >= 1 (gate comment present).
    - `grep -c "PHASE 4 / ADP-01" src/load/AtomicMigrationService.php` >= 1 (SEOmatic deferral marker).
    - `grep -c "seoMigrationService->migrateForEntry" src/load/AtomicMigrationService.php` returns 0 (call dropped — Phase 4 reinstates).
    - `grep -c "AssetResolver" src/load/AtomicMigrationService.php` returns 0 (v1 import dropped).
    - `grep -c "MigrationConfigError" src/load/AtomicMigrationService.php` returns 0.
    - `grep -c "public ?MigrationStateService" src/load/AtomicMigrationService.php` returns 1.
    - `grep -c "public ?EntryMigrationService" src/load/AtomicMigrationService.php` returns 1.
    - `grep -c "public ?AssetMigrationService" src/load/AtomicMigrationService.php` returns 1.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/load/AtomicMigrationService.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## MigrationReport reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/.../MigrationReport.php` (small v1 VO if present; v2 builds independently).
**v2 file:** `src/load/MigrationReport.php` (~50 LOC greenfield-from-v1)

| Rule | Description | Disposition |
|---|---|---|
| `incr(string, int=1)` + `warn(string)` API | v1 surface. | ported (rebuilt as small VO; v1 may have been more elaborate but Phase 3 only needs incr/warn/recordFailure) |
| `recordFailure(legacyId, slug, handler, Throwable)` | v2 D-50 failure schema. | new in v2 (D-50) — last-5-frames stack excerpt |

## AtomicMigrationService reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/AtomicMigrationService.php` (255 LOC)
**v2 file:** `src/load/AtomicMigrationService.php` (~245 LOC)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 117-135 — Phase A file I/O before transaction | Asset materialisation outside tx. | ported verbatim | Same file. File copies aren't transactional — preserved discipline. |
| Lines 145-184 — Phase B Craft DB transaction wrapping saveEntryForSites + state record | Atomic-always-on. | ported verbatim (modulo SEO closure drop) | ETL-04 satisfied. |
| Lines ~150 — `seoMigrationService->migrateForEntry(...)` inside closure | SEOmatic per-entry write. | partially ported — Phase 4 / ADP-01 reinstates | Comment marker preserves the closure shape; Phase 4 reinstates the call inside the same closure (preserves atomicity with entry save). |
| Lines 209-212 — deferred-token resolver `/^asset:\d+$/` + capture form | FH-04 load-pass token resolution. | ported byte-for-byte | Tightly coupled to `DeferredAssetToken::emit()` (Plan 03-01) — any change breaks the FH-04 contract. |
| Lines 107-116 — idempotency gate (`existingId !== null && !$overwrite`) | ETL-05 state-table-presence skip. | ported verbatim | Same gate. |
| `use AssetResolver` (separate v1 class) | v1 had dedicated AssetResolver. | dropped intentionally | Advisor-locked: AssetMigrationService implements the resolver surface directly. `$this->assetMigrationService->resolveFromLegacyId()` replaces `$resolver->resolveFromLegacyId()`. |
| `use MigrationReport` (v1 namespace) | Per-run counters. | retargeted to local namespace | Same namespace as this file — import dropped. |
| MigrationConfigError throws | Typed errors. | dropped intentionally | `\RuntimeException`. |

### Counts (Plan 03-12 only)
| Pair | ported | dropped intentionally | dropped accidentally | partially ported | new in v2 |
|---|---:|---:|---:|---:|---:|
| MigrationReport | 1 | 0 | 0 | 0 | 1 (recordFailure D-50) |
| AtomicMigrationService | 4 | 2 (AssetResolver, MigrationConfigError) | 0 | 1 (SEOmatic closure call — Phase 4) | 0 |
</reconciliation>

<verification>
- `php -l` exits 0 for both files.
- AtomicMigrationService preserves the atomic-always-on transaction shape verbatim.
- Deferred-token regex pair preserved byte-for-byte (FH-04 contract).
- ETL-05 idempotency gate preserved.
- SEOmatic closure-call dropped with explicit Phase 4 reinstatement marker.
- MigrationReport VO supports D-50 failure schema with 5-frame stack excerpt.
</verification>

<success_criteria>
- AtomicMigrationService 255 LOC ports verbatim modulo SEOmatic closure-drop + AssetResolver fold-in + namespace flatten.
- MigrationReport VO present with incr/warn/recordFailure API.
- ETL-04 + ETL-05 + FH-04 contracts preserved.
- Reconciliation documents 5 ported / 2 dropped intentionally / 1 partially ported / 1 new in v2.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-12-atomic-migration-SUMMARY.md`.
</output>
