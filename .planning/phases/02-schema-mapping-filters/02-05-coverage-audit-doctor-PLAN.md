---
phase: 02-schema-mapping-filters
plan: 05
type: execute
wave: 4
depends_on:
  - "02-01"
  - "02-02"
  - "02-03"
files_modified:
  - src/mapping/CoverageAuditor.php
  - src/mapping/MappingAuditor.php
  - src/console/AnalyzeController.php
  - src/console/DoctorController.php
  - src/Plugin.php
autonomous: true
requirements:
  - MAP-06
  - MAP-07
  - CONN-03
  - FILT-03
requirements_addressed:
  - MAP-06
  - MAP-07
  - CONN-03
  - FILT-03
must_haves:
  truths:
    - "CoverageAuditor identifies data-bearing legacy columns (fillRate > 0 AND not in STRUCTURAL_IGNORE) lacking a final mapping decision (D-14)"
    - "MappingAuditor walks every (targetEntryType, targetHandle) reference in mapping.yaml against the live Craft FieldLayout (D-16)"
    - "MappingAuditor returns structured findings: missing-entry-type, missing-handle, handler-classification-mismatch"
    - "DoctorController gains a 4th check (checkMappingFile) — the deferred Phase 1 / D-17 mapping health check"
    - "AnalyzeController calls mappingAuditor->audit after merge; --audit-strict elevates findings to fail-state per D-16"
    - "AnalyzeController writes MAPPING-AUDIT.md + console WARN per D-16"
    - "DoctorController accepts --entities, --locales, --since (FILT-03 — accepted but ignored, doctor doesn't read legacy data)"
    - "Plugin.php registers coverageAuditor + mappingAuditor"
  artifacts:
    - path: "src/mapping/CoverageAuditor.php"
      provides: "data-bearing-column coverage check with STRUCTURAL_IGNORE constant"
      contains: "final class CoverageAuditor extends Component"
      contains2: "private const STRUCTURAL_IGNORE"
    - path: "src/mapping/MappingAuditor.php"
      provides: "FieldLayout drift detection with handler-classification rules"
      contains: "final class MappingAuditor extends Component"
    - path: "src/console/AnalyzeController.php"
      provides: "MAPPING-AUDIT.md write site + --audit-strict consumer"
      modified: "actionIndex now calls mappingAuditor->audit + writes MAPPING-AUDIT.md"
    - path: "src/console/DoctorController.php"
      provides: "4th check (checkMappingFile) added to actionIndex"
      modified: "checkMappingFile method added; actionIndex chain extended; FILT-03 flags declared and ignored"
    - path: "src/Plugin.php"
      provides: "Registers coverageAuditor + mappingAuditor"
      contains: "'coverageAuditor'"
      also_contains: "'mappingAuditor'"
  key_links:
    - from: "src/mapping/CoverageAuditor.php"
      to: "schema-dump.json + mapping.yaml"
      via: "audit(array $schemaDump, array $mappingProposals)"
      pattern: "STRUCTURAL_IGNORE"
    - from: "src/mapping/MappingAuditor.php"
      to: "Craft::\\$app->entries"
      via: "getEntryTypeByHandle + getFieldLayout()->getCustomFields()"
      pattern: "getEntryTypeByHandle"
    - from: "src/console/AnalyzeController.php"
      to: "src/mapping/MappingAuditor.php"
      via: "Plugin::getInstance()->mappingAuditor->audit + write MAPPING-AUDIT.md"
      pattern: "mappingAuditor->audit"
    - from: "src/console/DoctorController.php"
      to: "src/mapping/MappingFile.php"
      via: "checkMappingFile uses Yaml::parseFile via mappingFile or directly"
      pattern: "checkMappingFile"
---

<objective>
Ship the coverage gate, the mapping-audit drift detector, and the 4th doctor check.

Purpose:
- `CoverageAuditor` (D-14) is the gate the Phase 3 `migrate --live` command will hard-fail on; this plan ships the auditor service. The hard-fail wiring lives in Phase 3, but the verdict producer lives here so Phase 3 can just consume it.
- `MappingAuditor` (D-16) compares mapping.yaml's `(targetEntryType, targetHandle)` references against the live Craft FieldLayout, with handler-classification rules ported from v1's `MappingValidator.php` (647 LOC).
- `AnalyzeController` is patched to call MappingAuditor after merge, write `MAPPING-AUDIT.md`, and elevate to fail-state when `--audit-strict` is set.
- `DoctorController` gets the 4th check (`checkMappingFile`) deferred from Phase 1 / D-17, completing CONN-03. Doctor also gains FILT-03 flag declarations (accepted but ignored — doctor doesn't read legacy data).

Output: 2 new service files (`CoverageAuditor`, `MappingAuditor`), 2 modified controllers, `Plugin.php` updated with 2 new component registrations.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/REQUIREMENTS.md
@.planning/phases/02-schema-mapping-filters/02-CONTEXT.md
@.planning/phases/02-schema-mapping-filters/02-PATTERNS.md

@src/Plugin.php
@src/console/DoctorController.php
@src/console/AnalyzeController.php
@src/mapping/MappingFile.php

<interfaces>
<!-- v1 brownfield reference (read-only) -->
- ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php
  - lines 437-491: actionCoverage shape — coverageAuditor->audit call site + stderr layout
  - lines 1769-1888: collectMappingDrifts — drift detection algorithm (port to MappingAuditor)
  - lines 1794-1802: excludedHandles list (port verbatim)
  - lines 1827-1837: FieldLayout walk pattern
- ~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php
  - lines 56-70: CANONICAL_HANDLERS and HANDLER_ALIASES (port)
  - 647 LOC total — handler-classification rule set; port the classification rules

<!-- From src/console/DoctorController.php (Phase 1) — current shape (3 checks) -->
class DoctorController extends Controller {
    use NeverProductionTrait;
    public function actionIndex(): int {
        if (($gate = $this->enforceNeverProduction()) !== null) { return $gate; }
        $this->stdout("Doctor: preflight diagnostics\n", Console::FG_CYAN);
        $ok = true;
        $ok = $this->checkLegacyDb()    && $ok;
        $ok = $this->checkApiKey()      && $ok;
        $ok = $this->checkStorageDir()  && $ok;
        $this->stdout("\n" . ($ok ? "Doctor: PASS\n" : "Doctor: FAIL ..."), $ok ? Console::FG_GREEN : Console::FG_RED);
        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
    private function checkLegacyDb(): bool { ... }
    private function checkApiKey(): bool { ... }
    private function checkStorageDir(): bool { ... }
}

<!-- From src/mapping/MappingFile.php (Plan 02) -->
public function load(?string $path = null): array;
public function resolvePath(): string;
public function writeAtomic(string $path, string $contents): bool;

<!-- From src/console/AnalyzeController.php (Plan 03) -->
public bool $auditStrict = false;     // declared but not yet consumed — this plan wires it
// Plan 03 ends actionIndex with REPORT.md write; this plan slots audit BEFORE the report.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Build CoverageAuditor with STRUCTURAL_IGNORE constant + data-bearing-column check</name>
  <files>src/mapping/CoverageAuditor.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-14 STRUCTURAL_IGNORE seed list; D-15 dual-mode gate; MAP-06 hard-fail definition)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/mapping/CoverageAuditor.php" section, lines 514–540)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php (lines 437–491: v1's actionCoverage stderr layout)
    - src/db/LegacyDbService.php (Yii Component idiom)
    - src/Plugin.php (Plugin::getInstance() pattern)
  </read_first>
  <action>
Create `src/mapping/CoverageAuditor.php` as a `final` Yii Component. Returns the verdict; does NOT decide hard-fail-vs-warn (that's the consumer's choice — Phase 3's `migrate --live` per D-15). Exact contents (~80 LOC):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use yii\base\Component;

/**
 * Coverage gate (D-14, MAP-06): a "data-bearing legacy column" is every column
 * with fillRate > 0 AND name not in STRUCTURAL_IGNORE. A column is "covered" when
 * mapping.yaml has at least one row with matching (table, column) and status in
 * {accepted, dropped}. proposed/needs-review do NOT count as covered.
 *
 * D-15: this service produces the verdict only. Consumer code (Phase 3 migrate)
 * decides hard-fail (--live) vs warn-and-continue (--dry-run).
 */
final class CoverageAuditor extends Component
{
    /**
     * Kunstmaan structural columns we never migrate as data — seed list per D-14.
     * These are the bookkeeping columns Doctrine / nested-set / soft-delete stuff
     * that have no semantic content for migration.
     */
    private const STRUCTURAL_IGNORE = [
        'id', 'parent_id', 'lft', 'rgt', 'lvl', 'tree_root',
        'created', 'updated', 'createdBy', 'updatedBy',
        'createdBy_id', 'updatedBy_id',
        'internal_name', 'discr', 'public', 'hidden_from_nav',
        'children_index', 'sequencenumber', 'ref',
        'deletedAt', 'version',
        'kunstmaanSourceId', // set programmatically at load time, never migrated as data
    ];

    /**
     * Audit coverage. Returns a list of unmapped data-bearing columns.
     *
     * @param array{tables: array<string, int>, columns: array<string, list<array<string, mixed>>>} $schemaDump
     * @param list<array<string, mixed>> $mappingProposals
     * @return list<array{table: string, column: string, fillRate: float, rows: int}>
     */
    public function audit(array $schemaDump, array $mappingProposals): array
    {
        // Index covered columns by (table|column) — covered means status ∈ {accepted, dropped}.
        $covered = [];
        foreach ($mappingProposals as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status !== 'accepted' && $status !== 'dropped') {
                continue;
            }
            $key = ($row['table'] ?? '') . '|' . ($row['column'] ?? '');
            $covered[$key] = true;
        }

        $violations = [];
        foreach ($schemaDump['columns'] ?? [] as $table => $cols) {
            $rowCount = (int) ($schemaDump['tables'][$table] ?? 0);
            foreach ($cols as $col) {
                $name = (string) ($col['column'] ?? '');
                $fillRate = (float) ($col['fillRate'] ?? 0);
                if ($name === '') { continue; }
                if (in_array($name, self::STRUCTURAL_IGNORE, true)) { continue; }
                if ($fillRate <= 0) { continue; }
                $key = $table . '|' . $name;
                if (isset($covered[$key])) { continue; }
                $violations[] = [
                    'table'    => (string) $table,
                    'column'   => $name,
                    'fillRate' => $fillRate,
                    'rows'     => $rowCount,
                ];
            }
        }
        return $violations;
    }

    /**
     * Render a v1-shaped stderr block. Caller writes via $this->stderr(...).
     *
     * @param list<array{table: string, column: string, fillRate: float, rows: int}> $violations
     */
    public function renderViolations(array $violations): string
    {
        if ($violations === []) {
            return '';
        }
        // Group by table for readability.
        $byTable = [];
        foreach ($violations as $v) {
            $byTable[$v['table']][] = $v;
        }
        $out = '';
        foreach ($byTable as $table => $rows) {
            $n = count($rows);
            $out .= "FAIL {$table}: {$n} unmapped data-bearing column(s)\n";
            foreach ($rows as $r) {
                $out .= sprintf("     - %s (fill=%.1f%%, rows=%d)\n", $r['column'], $r['fillRate'] * 100, $r['rows']);
            }
        }
        return $out;
    }
}
```

Notes:
- The STRUCTURAL_IGNORE list is the seed set from CONTEXT.md D-14 plus a few obvious additions (`createdBy_id`, `updatedBy_id`, `deletedAt`, `version`, `kunstmaanSourceId`). The list can grow in a follow-up if the rehearsal corpus surfaces more bookkeeping columns; v1.0 ships a sensible default.
- `audit()` is pure (no I/O). Caller (Phase 3 migrate or this plan's AnalyzeController patch in Task 3) decides what to do with a non-empty violation list.
- `renderViolations()` is a presentational helper that produces the v1-shaped stderr block. Callers can write it via `$this->stderr($auditor->renderViolations($violations))` for ergonomic operator output.
  </action>
  <verify>
    <automated>php -l src/mapping/CoverageAuditor.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/mapping/CoverageAuditor.php` exits 0
    - `grep -c 'final class CoverageAuditor extends Component' src/mapping/CoverageAuditor.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\mapping;' src/mapping/CoverageAuditor.php` equals 1
    - `grep -c 'private const STRUCTURAL_IGNORE' src/mapping/CoverageAuditor.php` equals 1
    - `grep -c 'public function audit' src/mapping/CoverageAuditor.php` equals 1
    - `grep -c "'parent_id'" src/mapping/CoverageAuditor.php` equals 1
    - `grep -c "'lft'" src/mapping/CoverageAuditor.php` equals 1
    - `grep -c "'kunstmaanSourceId'" src/mapping/CoverageAuditor.php` equals 1
    - `grep -c 'public function renderViolations' src/mapping/CoverageAuditor.php` equals 1
  </acceptance_criteria>
  <done>CoverageAuditor produces violation list using STRUCTURAL_IGNORE filter + data-bearing rule + status ∈ {accepted, dropped} coverage check; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 2: Build MappingAuditor with FieldLayout walk + handler-classification rules</name>
  <files>src/mapping/MappingAuditor.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-16 drift findings + handler-classification mismatch + warn-only-default vs --audit-strict)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/mapping/MappingAuditor.php" section, lines 545–586)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/console/controllers/AnalyzeController.php (lines 1794-1802 excludedHandles list; lines 1827-1837 FieldLayout walk)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/mapping/MappingValidator.php (lines 56-70: CANONICAL_HANDLERS + HANDLER_ALIASES — port verbatim)
  </read_first>
  <action>
Create `src/mapping/MappingAuditor.php` as a `final` Yii Component. Returns structured findings; mode-agnostic (warn-only or strict is the caller's call). ~120 LOC:

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use Craft;
use yii\base\Component;

/**
 * Mapping-audit (MAP-07 / D-16): walks every (targetEntryType, targetHandle) reference
 * in mapping.yaml and checks against the live Craft FieldLayout. Drift findings:
 *
 *   - missing-entry-type:                    targetEntryType is not a registered EntryType handle
 *   - missing-handle:                        targetEntryType exists but targetHandle is not in its layout
 *   - handler-classification-mismatch:       targetHandle exists but its Craft field type does not match
 *                                            the row's handler classification (e.g. row says 'ckeditor',
 *                                            Craft field is plainText)
 *
 * Excluded handles (do not flag drift) — these are native Element props or programmatically-
 * managed fields that don't need a Craft FieldLayout entry. Ported verbatim from v1.
 *
 * Mode-agnostic: returns the findings list. AnalyzeController decides warn-only (default)
 * vs strict (--audit-strict, or migrate --live which is always strict per D-16).
 */
final class MappingAuditor extends Component
{
    /** Handles that bypass the FieldLayout check (port verbatim from v1 lines 1794-1802). */
    private const EXCLUDED_HANDLES = [
        'kunstmaanSourceId', // set programmatically at load time
        'title', 'slug', 'postDate', 'expiryDate', 'enabled', 'authorId', // native Element props
    ];

    /** Canonical handler vocabulary (port from v1 MappingValidator.php:56-70). */
    private const CANONICAL_HANDLERS = [
        'asset', 'ckeditor', 'date', 'dropdown', 'email', 'link',
        'matrix', 'plain', 'relation', 'seomatic', 'splitName', 'url',
    ];

    /** Aliases that resolve to a canonical handler. */
    private const HANDLER_ALIASES = [
        'plainText' => 'plain',
        'PlainText' => 'plain',
    ];

    /**
     * Map handler → expected Craft field type FQCN (or substring match on getType()).
     * Used by handler-classification-mismatch detection. Conservative — only encode
     * the unambiguous mappings; everything else passes through.
     *
     * Keys are canonical handlers. Values are substrings to look for in the field's class name.
     */
    private const HANDLER_FIELD_HINTS = [
        'ckeditor' => 'ckeditor',
        'asset'    => 'Assets',
        'date'     => 'Date',
        'email'    => 'Email',
        'matrix'   => 'Matrix',
        'plain'    => 'PlainText',
        'url'      => 'Url',
    ];

    /**
     * Audit drift. Returns structured findings.
     *
     * @param list<array<string, mixed>> $mappingProposals
     * @return list<array{table: string, column: string, targetEntryType: string, targetHandle: string, kind: string, detail: string}>
     */
    public function audit(array $mappingProposals): array
    {
        $findings = [];

        // Cache entry types seen so we don't re-resolve.
        $entryTypeCache = []; // handle => null|EntryType

        foreach ($mappingProposals as $row) {
            $status = (string) ($row['status'] ?? '');
            // Only audit rows that will be applied (accepted/proposed). Dropped rows are no-ops.
            if ($status === 'dropped') { continue; }
            $entryHandle = (string) ($row['targetEntryType'] ?? '');
            $fieldHandle = (string) ($row['targetHandle'] ?? '');
            $handler     = (string) ($row['handler'] ?? '');
            // Resolve handler alias.
            $handler = self::HANDLER_ALIASES[$handler] ?? $handler;

            if ($entryHandle === '' || $fieldHandle === '') { continue; }
            if (in_array($fieldHandle, self::EXCLUDED_HANDLES, true)) { continue; }

            if (!array_key_exists($entryHandle, $entryTypeCache)) {
                $entryTypeCache[$entryHandle] = Craft::$app->entries->getEntryTypeByHandle($entryHandle);
            }
            $entryType = $entryTypeCache[$entryHandle];

            if ($entryType === null) {
                $findings[] = [
                    'table'           => (string) ($row['table'] ?? ''),
                    'column'          => (string) ($row['column'] ?? ''),
                    'targetEntryType' => $entryHandle,
                    'targetHandle'    => $fieldHandle,
                    'kind'            => 'missing-entry-type',
                    'detail'          => "EntryType '{$entryHandle}' not found in Craft.",
                ];
                continue;
            }

            $layout = $entryType->getFieldLayout();
            $field = null;
            if ($layout !== null) {
                foreach ($layout->getCustomFields() as $f) {
                    if ((string) $f->handle === $fieldHandle) {
                        $field = $f;
                        break;
                    }
                }
            }
            if ($field === null) {
                $findings[] = [
                    'table'           => (string) ($row['table'] ?? ''),
                    'column'          => (string) ($row['column'] ?? ''),
                    'targetEntryType' => $entryHandle,
                    'targetHandle'    => $fieldHandle,
                    'kind'            => 'missing-handle',
                    'detail'          => "Field handle '{$fieldHandle}' not present in {$entryHandle}'s FieldLayout.",
                ];
                continue;
            }

            // Handler-classification mismatch detection (best-effort substring match).
            if ($handler !== '' && in_array($handler, self::CANONICAL_HANDLERS, true)) {
                $hint = self::HANDLER_FIELD_HINTS[$handler] ?? null;
                if ($hint !== null) {
                    $fieldClass = $field::class;
                    if (stripos($fieldClass, $hint) === false) {
                        $findings[] = [
                            'table'           => (string) ($row['table'] ?? ''),
                            'column'          => (string) ($row['column'] ?? ''),
                            'targetEntryType' => $entryHandle,
                            'targetHandle'    => $fieldHandle,
                            'kind'            => 'handler-classification-mismatch',
                            'detail'          => "Row handler '{$handler}' but Craft field is " . $fieldClass,
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Render findings as MAPPING-AUDIT.md content + a parallel console-friendly block.
     *
     * @param list<array{table: string, column: string, targetEntryType: string, targetHandle: string, kind: string, detail: string}> $findings
     */
    public function renderMarkdown(array $findings): string
    {
        if ($findings === []) {
            return "# Mapping Audit\n\nNo drift detected. Mapping references resolve cleanly against the live Craft FieldLayout.\n";
        }
        $out = "# Mapping Audit\n\n" . count($findings) . " drift finding(s):\n\n";
        $out .= "| Table | Column | Entry Type | Handle | Kind | Detail |\n";
        $out .= "|-------|--------|------------|--------|------|--------|\n";
        foreach ($findings as $f) {
            $out .= sprintf(
                "| `%s` | `%s` | `%s` | `%s` | %s | %s |\n",
                $f['table'], $f['column'], $f['targetEntryType'], $f['targetHandle'],
                $f['kind'], str_replace('|', '\\|', $f['detail']),
            );
        }
        return $out;
    }
}
```

Notes:
- The handler-classification check uses substring match on the field class FQCN (`Assets` → `craft\fields\Assets`, `PlainText` → `craft\fields\PlainText`). It's intentionally conservative — false negatives are tolerable; false positives would create alarm fatigue.
- Findings are emitted in mapping.yaml order to keep the audit deterministic and diffable.
- `EXCLUDED_HANDLES` is a literal port from v1 lines 1794-1802 — match the v1 list exactly so the rehearsal corpus drift report is comparable.
  </action>
  <verify>
    <automated>php -l src/mapping/MappingAuditor.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/mapping/MappingAuditor.php` exits 0
    - `grep -c 'final class MappingAuditor extends Component' src/mapping/MappingAuditor.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\mapping;' src/mapping/MappingAuditor.php` equals 1
    - `grep -c 'public function audit' src/mapping/MappingAuditor.php` equals 1
    - `grep -c 'EXCLUDED_HANDLES' src/mapping/MappingAuditor.php` is 2 or more (const + use)
    - `grep -c 'CANONICAL_HANDLERS' src/mapping/MappingAuditor.php` is 2 or more
    - `grep -c 'HANDLER_ALIASES' src/mapping/MappingAuditor.php` is 2 or more
    - `grep -c 'kunstmaanSourceId' src/mapping/MappingAuditor.php` equals 1 (excluded)
    - `grep -c "'title'" src/mapping/MappingAuditor.php` equals 1 (excluded native prop)
    - `grep -c 'getEntryTypeByHandle' src/mapping/MappingAuditor.php` equals 1
    - `grep -c 'getCustomFields' src/mapping/MappingAuditor.php` equals 1
    - `grep -c "'missing-entry-type'" src/mapping/MappingAuditor.php` equals 1
    - `grep -c "'missing-handle'" src/mapping/MappingAuditor.php` equals 1
    - `grep -c "'handler-classification-mismatch'" src/mapping/MappingAuditor.php` equals 1
    - `grep -c 'public function renderMarkdown' src/mapping/MappingAuditor.php` equals 1
  </acceptance_criteria>
  <done>MappingAuditor walks FieldLayout, returns structured drift findings; renderMarkdown produces MAPPING-AUDIT.md content; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 3: Wire CoverageAuditor + MappingAuditor into AnalyzeController and register in Plugin.php</name>
  <files>src/console/AnalyzeController.php, src/Plugin.php</files>
  <read_first>
    - src/console/AnalyzeController.php (Plan 03 — current shape; --audit-strict declared at line ~38; actionIndex ends with REPORT.md write)
    - src/mapping/CoverageAuditor.php (Task 1 — audit() + renderViolations())
    - src/mapping/MappingAuditor.php (Task 2 — audit() + renderMarkdown())
    - src/Plugin.php (current shape after Plan 03 — components map has 8 entries)
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-16 warn-only default; --audit-strict elevates; analyze writes MAPPING-AUDIT.md per D-16)
  </read_first>
  <action>
**Step A — modify `src/console/AnalyzeController.php`:**

Locate the end of `actionIndex()`, AFTER the `mapping.yaml` write block ("OK   mapping.yaml updated"), BEFORE the `REPORT.md` write block. Insert a new step that calls `mappingAuditor->audit` and writes `MAPPING-AUDIT.md`.

Find this section:
```php
        $this->stdout("  OK   mapping.yaml updated (" . count($merged['proposals']) . " rows total) → {$mappingPath}\n", Console::FG_GREEN);

        // Step 7: REPORT.md
        $reportPath = $storageDir . '/REPORT.md';
```

Replace with:
```php
        $this->stdout("  OK   mapping.yaml updated (" . count($merged['proposals']) . " rows total) → {$mappingPath}\n", Console::FG_GREEN);

        // Step 7: mapping-audit (D-16). Warn-only by default; --audit-strict elevates.
        $findings = $plugin->mappingAuditor->audit($merged['proposals']);
        $auditPath = $storageDir . '/MAPPING-AUDIT.md';
        $auditMd = $plugin->mappingAuditor->renderMarkdown($findings);
        if (!$plugin->mappingFile->writeAtomic($auditPath, $auditMd)) {
            $this->stderr("  FAIL could not write {$auditPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        if ($findings === []) {
            $this->stdout("  OK   mapping audit clean → {$auditPath}\n", Console::FG_GREEN);
        } else {
            $n = count($findings);
            if ($this->auditStrict) {
                $this->stderr("  FAIL mapping audit: {$n} drift finding(s) (--audit-strict) → {$auditPath}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("  WARN mapping audit: {$n} drift finding(s) → {$auditPath}\n", Console::FG_YELLOW);
        }

        // Step 8: REPORT.md
        $reportPath = $storageDir . '/REPORT.md';
```

The audit step lands BEFORE REPORT.md so REPORT.md generation sees a known-good audit state. The `--audit-strict` short-circuit returns early without writing REPORT.md — that's fine; the operator runs again after fixing drift.

**Step B — modify `src/Plugin.php` to register the 2 new components:**

Add `use` statements next to the other mapping/ imports:
```php
use lameco\kunstmaanmigrator\mapping\CoverageAuditor;
use lameco\kunstmaanmigrator\mapping\MappingAuditor;
use lameco\kunstmaanmigrator\mapping\MappingFile;
```
(MappingFile is already imported from Plan 02; add the two new ones.)

Add 2 `@property-read` lines to the class docblock:
```php
 * @property-read CoverageAuditor $coverageAuditor
 * @property-read MappingAuditor $mappingAuditor
```

Extend the components map:
```php
return [
    'components' => [
        'legacyDbService'   => LegacyDbService::class,
        'filterFactory'     => FilterFactory::class,
        'localePreflight'   => LocalePreflight::class,
        'mappingFile'       => MappingFile::class,
        'schemaDumper'      => SchemaDumper::class,
        'heuristicProposer' => HeuristicProposer::class,
        'llmClassifier'     => LlmClassifier::class,
        'reportBuilder'     => ReportBuilder::class,
        'coverageAuditor'   => CoverageAuditor::class,    // Phase 2 (Plan 05) — D-14 MAP-06
        'mappingAuditor'    => MappingAuditor::class,     // Phase 2 (Plan 05) — D-16 MAP-07
    ],
];
```
  </action>
  <verify>
    <automated>php -l src/console/AnalyzeController.php && php -l src/Plugin.php && composer test</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/console/AnalyzeController.php` exits 0
    - `php -l src/Plugin.php` exits 0
    - `composer test` exits 0
    - `grep -c 'mappingAuditor->audit' src/console/AnalyzeController.php` equals 1
    - `grep -c 'MAPPING-AUDIT.md' src/console/AnalyzeController.php` is 1 or more
    - `grep -c '\$this->auditStrict' src/console/AnalyzeController.php` is 1 or more (now consumed, not just declared)
    - `grep -c "'coverageAuditor'   => CoverageAuditor::class" src/Plugin.php` equals 1
    - `grep -c "'mappingAuditor'    => MappingAuditor::class" src/Plugin.php` equals 1
    - `grep -c '@property-read CoverageAuditor \$coverageAuditor' src/Plugin.php` equals 1
    - `grep -c '@property-read MappingAuditor \$mappingAuditor' src/Plugin.php` equals 1
  </acceptance_criteria>
  <done>AnalyzeController calls mappingAuditor->audit and writes MAPPING-AUDIT.md; --audit-strict elevates findings to fail-state; Plugin.php registers both new components; composer test green.</done>
</task>

<task type="auto">
  <name>Task 4: Add 4th doctor check (checkMappingFile) + FILT-03 flag declarations to DoctorController</name>
  <files>src/console/DoctorController.php</files>
  <read_first>
    - src/console/DoctorController.php (current shape — 3 checks; gate-first; ANSI color usage)
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-17 mapping-file check deferred from Phase 1; FILT-03 doctor accepts but ignores filter flags)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/console/DoctorController.php" section, lines 180–219: full code template for checkMappingFile)
    - src/mapping/MappingFile.php (resolvePath signature; the check can use it for path resolution)
  </read_first>
  <action>
Modify `src/console/DoctorController.php`. Two distinct edits:

**Edit 1 — declare FILT-03 flags + extend `options()`:**

Find the existing class body opener:
```php
class DoctorController extends Controller
{
    use NeverProductionTrait;

    public function actionIndex(): int
```

Insert (after `use NeverProductionTrait;` and before `actionIndex`):
```php
class DoctorController extends Controller
{
    use NeverProductionTrait;

    // FILT-03: doctor accepts the three filter flags for command-surface uniformity but
    // ignores them — doctor doesn't read legacy data, so filters are a no-op here.
    public ?string $entities = null;
    public ?string $locales  = null;
    public ?string $since    = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['entities', 'locales', 'since']);
    }

    public function actionIndex(): int
```

**Edit 2 — add the 4th check call site in `actionIndex()`:**

Find:
```php
        $ok = true;
        $ok = $this->checkLegacyDb()    && $ok;
        $ok = $this->checkApiKey()      && $ok;
        $ok = $this->checkStorageDir()  && $ok;
```

Replace with:
```php
        $ok = true;
        $ok = $this->checkLegacyDb()    && $ok;
        $ok = $this->checkApiKey()      && $ok;
        $ok = $this->checkStorageDir()  && $ok;
        $ok = $this->checkMappingFile() && $ok;
```

**Edit 3 — add the `checkMappingFile()` private method at the end of the class** (after `checkStorageDir`):

```php
    /**
     * Check #4 (deferred from Phase 1 / D-17): mapping.yaml health.
     *
     * Soft-warn on missing file (analyze creates it, not doctor); hard-fail on parse error
     * or missing top-level `proposals:` key.
     */
    private function checkMappingFile(): bool
    {
        $path = Plugin::getInstance()->mappingFile->resolvePath();
        try {
            if (!is_file($path)) {
                $this->stdout("  WARN mapping.yaml not found at {$path} (run analyze first)\n", Console::FG_YELLOW);
                return true; // WARN-only — file is created by analyze, not by doctor
            }
            $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path);
            if (!is_array($parsed) || !array_key_exists('proposals', $parsed)) {
                $this->stderr("  FAIL mapping.yaml at {$path} missing top-level 'proposals:' key\n", Console::FG_RED);
                return false;
            }
            $rowCount = is_array($parsed['proposals']) ? count($parsed['proposals']) : 0;
            $this->stdout("  OK   mapping.yaml at {$path} ({$rowCount} rows)\n", Console::FG_GREEN);
            return true;
        } catch (Throwable $e) {
            $this->stderr("  FAIL mapping.yaml parse error: {$e->getMessage()}\n", Console::FG_RED);
            return false;
        }
    }
```

Notes:
- `Plugin::getInstance()->mappingFile->resolvePath()` is the clean path resolver — uses Settings::mappingPath if set, else `storage/migration/mapping.yaml`. This is the same method MapController and AnalyzeController use, so doctor's check is consistent with the actual run-time path.
- The WARN-only-on-missing-file path is the right call: doctor preflights what analyze produces. A missing mapping.yaml on a fresh greenfield install is expected, not broken.
- No new `use` statements required in DoctorController — `Plugin`, `Throwable`, and `Console` are already imported in the Phase 1 version. `Symfony\Component\Yaml\Yaml` is referenced via FQCN (`\Symfony\Component\Yaml\Yaml`) to avoid a new use statement, matching the Phase 1 minimalism. (If you prefer adding `use Symfony\Component\Yaml\Yaml;` for consistency, that's also fine — both work.)
  </action>
  <verify>
    <automated>php -l src/console/DoctorController.php && composer test</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/console/DoctorController.php` exits 0
    - `composer test` exits 0
    - `grep -c 'private function checkMappingFile' src/console/DoctorController.php` equals 1
    - `grep -c '\$ok = \$this->checkMappingFile()' src/console/DoctorController.php` equals 1
    - `grep -c "public ?string \$entities" src/console/DoctorController.php` equals 1
    - `grep -c "public ?string \$locales" src/console/DoctorController.php` equals 1
    - `grep -c "public ?string \$since" src/console/DoctorController.php` equals 1
    - `grep -c "'entities', 'locales', 'since'" src/console/DoctorController.php` equals 1
    - `grep -c 'mappingFile->resolvePath' src/console/DoctorController.php` equals 1
    - `grep -c 'top-level' src/console/DoctorController.php` is 1 or more (the FAIL message references "top-level 'proposals:' key")
  </acceptance_criteria>
  <done>DoctorController has 4 checks (legacy DB, anthropic key, storage dir, mapping file); accepts FILT-03 flags (ignored for doctor); composer test green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| mapping.yaml + schema-dump.json → CoverageAuditor / MappingAuditor | Read previously-written files. Tampering during read is theoretically possible but covered by the atomic-write contract from Plan 02 (any partially-written file would fail YAML parse cleanly). |
| Live Craft FieldLayout → MappingAuditor | Read-only against Craft's own data. Trust boundary is internal — both this code and the FieldLayout live in the same Craft application. |
| Production env → DoctorController | NeverProduction trait still gates (Phase 1 / D-20). Doctor's check #4 only reads from disk via `Yaml::parseFile`; no legacy DB read in this check. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-2-19 | T (Tampering) | mapping.yaml YAML parse error from corruption | mitigate | DoctorController's `checkMappingFile` catches `Throwable` from `Yaml::parseFile` and reports a FAIL with the parse message. The atomic-write contract from Plan 02 means the file is either complete-and-valid or unchanged-from-before-write — never partial. |
| T-2-20 | E (Elevation of Privilege) | DoctorController against production | mitigate | NeverProduction trait gates Doctor's actionIndex (Phase 1 / D-20). The 4th check inherits the gate without modification. |
| T-2-21 | R (Repudiation) | MAPPING-AUDIT.md persists drift findings | mitigate | The audit findings are written via `MappingFile::writeAtomic` (Plan 02 atomic). Even when `--audit-strict` aborts the run, MAPPING-AUDIT.md is written first — operators always have an audit trail of what drifted. |
| T-2-22 | I (Information Disclosure) | MAPPING-AUDIT.md exposes Craft schema details | accept | The audit lists EntryType handles + field handles + Craft field class names. These are not secrets; they're already visible in Craft's CP and project-config. The file lives under `storage/migration/` which is not web-accessible. |
</threat_model>

<verification>
- `php -l` passes on all 5 affected files (2 new + 3 modified)
- `composer test` exits 0
- Plugin.php registers exactly 10 components (Phase 1 + 9 Phase 2 across Plans 01-05)
- DoctorController has exactly 4 checks; 4th is checkMappingFile
- AnalyzeController calls mappingAuditor->audit before REPORT.md write
- AnalyzeController's `--audit-strict` is now consumed (not just declared)
</verification>

<success_criteria>
1. `CoverageAuditor` returns the list of unmapped data-bearing columns (fillRate>0 AND not in STRUCTURAL_IGNORE AND not covered by status ∈ {accepted, dropped}).
2. `MappingAuditor` walks every accepted/proposed row's `(targetEntryType, targetHandle)` pair against the live FieldLayout and returns structured findings (missing-entry-type / missing-handle / handler-classification-mismatch).
3. `AnalyzeController` writes `storage/migration/MAPPING-AUDIT.md` after every analyze run; warn-only by default; `--audit-strict` elevates non-empty findings to fail-state.
4. `DoctorController` runs 4 checks (the 4th is the deferred mapping-file health check from Phase 1 / D-17) — CONN-03 fully satisfied.
5. All three top-level controllers (`doctor`, `analyze`, `map`) declare `--entities` / `--locales` / `--since` (FILT-03 fully satisfied across the controllers shipped in Phase 2). `migrate` and `verify` declarations land in Phase 3+ when those controllers ship.
6. `Plugin.php` registers `coverageAuditor` and `mappingAuditor`; PHPDoc reflects them; component count is now 10.
</success_criteria>

<output>
After completion, create `.planning/phases/02-schema-mapping-filters/02-05-SUMMARY.md` documenting:
- Files created/modified with line counts
- Plugin.php diff summary (10 components after this plan)
- Confirmation that DoctorController now has 4 checks
- Confirmation that AnalyzeController consumes --audit-strict (not just declares)
- Confirmation that CONN-03 deferred mapping check is satisfied
- Confirmation that FILT-03 is satisfied across the 3 Phase-2 controllers (doctor/analyze/map); migrate+verify deferred to Phase 3+
- Note: CoverageAuditor verdict producer is shipped but the consumer (Phase 3 migrate --live hard-fail wiring) is deferred; this is intentional per D-15 (the gate behavior lives in the consumer, the verdict lives in the auditor)
</output>
