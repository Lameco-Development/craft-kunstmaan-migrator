---
phase: 02-schema-mapping-filters
plan: 02
type: execute
wave: 2
depends_on:
  - "02-01"
files_modified:
  - src/mapping/MappingFile.php
  - src/Plugin.php
autonomous: true
requirements:
  - MAP-01
  - MAP-04
requirements_addressed:
  - MAP-01
  - MAP-04
must_haves:
  truths:
    - "MappingFile reads and writes a single mapping.yaml with per-row status (D-01)"
    - "MappingFile::writeAtomic uses tmp+rename (atomic write per D-07 + CLAUDE.md atomic-always-on)"
    - "MappingFile::merge preserves existing rows verbatim and only appends new (table,column,targetEntryType) tuples (D-04 skip-existing)"
    - "MappingFile::setStatus mutates a single row in place and rewrites atomically (per-keypress write site for Plan 04 map loop)"
    - "MappingFile::buildRow renders v1 wire shape with status field (D-01 + D-02)"
    - "MappingFile::loadProposed returns rows with status in {proposed, needs-review} (Plan 04 consumes this for the rubber-stamp loop)"
    - "Plugin.php registers mappingFile in components map"
  artifacts:
    - path: "src/mapping/MappingFile.php"
      provides: "Mapping YAML reader+writer with status-on-row, atomic IO, skip-existing merge"
      contains: "final class MappingFile extends Component"
      methods: ["load", "loadProposed", "buildRow", "merge", "writeAtomic", "writeAtomicJson", "setStatus", "resolvePath"]
    - path: "src/Plugin.php"
      provides: "Component registration for mappingFile"
      contains: "'mappingFile'"
  key_links:
    - from: "src/mapping/MappingFile.php"
      to: "Symfony\\Component\\Yaml\\Yaml"
      via: "Yaml::dump / Yaml::parseFile"
      pattern: "Yaml::dump.*DUMP_MULTI_LINE_LITERAL_BLOCK"
    - from: "src/mapping/MappingFile.php"
      to: "src/models/Settings.php"
      via: "Settings::mappingPath fallback to storage/migration/mapping.yaml"
      pattern: "mappingPath"
---

<objective>
Ship the single-source-of-truth mapping.yaml reader+writer that every other Phase 2 plan reads from / writes to.

Purpose: Consolidate v1's `MappingDraftReader` + `MappingDraftWriter` into one `MappingFile` service that operates on the new flat `proposals:` list with per-row `status:` (D-01). Atomic writes via tmp+rename (D-07). Skip-existing merge keyed on `(table, column, targetEntryType)` tuple (D-04). Per-row mutation for the map rubber-stamp loop (Plan 04 consumer).

Output: 1 new file `src/mapping/MappingFile.php`, plus `Plugin.php` modified to register `mappingFile` in the components map.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/phases/02-schema-mapping-filters/02-CONTEXT.md
@.planning/phases/02-schema-mapping-filters/02-PATTERNS.md

@src/Plugin.php
@src/models/Settings.php
@src/console/DoctorController.php

<interfaces>
<!-- v1 brownfield (read-only references, not in this repo) -->
- ~/Sites/craft-kunstmaan-migrator/src/bridge/services/MappingDraftWriter.php
  - lines 34-50: writeAtomic (tmp + rename pattern — port verbatim)
  - lines 76-95: buildDraftPayload (row shape — reshape for status-on-row)
  - line 61: Yaml::dump flags (Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
  - lines 146-173: mergePreservingEdits (key on tuple — port indexing)
- ~/Sites/craft-kunstmaan-migrator/src/bridge/services/MappingDraftReader.php
  - lines 105+: Yaml::parseFile($path) ?? [] pattern
  - lines 248-285: mutateStatusInPlace (port simplified for flat list)

<!-- From src/models/Settings.php (Phase 1, D-15) -->
public ?string $mappingPath          = null;

<!-- From src/Plugin.php (Phase 1 + Plan 01) — current components after Plan 01 ships -->
'components' => [
    'legacyDbService' => LegacyDbService::class,
    'filterFactory'   => FilterFactory::class,
    'localePreflight' => LocalePreflight::class,
],
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create MappingFile service (load/buildRow/merge/setStatus/writeAtomic/writeAtomicJson)</name>
  <files>src/mapping/MappingFile.php</files>
  <read_first>
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-01 flat proposals list shape; D-04 skip-existing tuple key; D-07 atomic per-keypress)
    - .planning/phases/02-schema-mapping-filters/02-PATTERNS.md ("src/mapping/MappingFile.php" section, lines 408–510 — full code blueprint)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/services/MappingDraftWriter.php (lines 34-50 writeAtomic; lines 61 yaml dump flags; lines 76-95 buildDraftPayload; lines 146-173 mergePreservingEdits)
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/services/MappingDraftReader.php (lines 248-285 mutateStatusInPlace — adapt for flat list)
    - src/db/LegacyDbService.php (Yii Component idiom reference)
    - src/console/DoctorController.php (Symfony YAML use statement at line 11; Plugin::getInstance() pattern)
    - src/models/Settings.php (mappingPath property — Phase 1 / D-15)
  </read_first>
  <action>
Create `src/mapping/MappingFile.php` as a `final` Yii Component consolidating the v1 MappingDraftReader + MappingDraftWriter into one class operating on the flat status-on-row format. Exact contents (~150 LOC):

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use Symfony\Component\Yaml\Yaml;
use yii\base\Component;

/**
 * Single source of truth for mapping.yaml — flat `proposals:` list with per-row `status`.
 *
 * Consolidates v1's MappingDraftReader (303 LOC) + MappingDraftWriter (384 LOC)
 * into one service. v2 has no .draft / .drops / DESIGN-GAPS sidecars (D-01).
 *
 * Atomic writes via tmp+rename (D-07) — operator can Ctrl+C any time without losing
 * state. Skip-existing merge (D-04) — operator decisions are sacred (MAP-04).
 *
 * Path resolution: Settings::mappingPath wins; otherwise storage/migration/mapping.yaml.
 */
final class MappingFile extends Component
{
    /**
     * Resolve the on-disk mapping.yaml path (Settings override or storage default).
     */
    public function resolvePath(): string
    {
        $settingsPath = (string) (Plugin::getInstance()->getSettings()->mappingPath ?? '');
        if ($settingsPath !== '') {
            return $settingsPath;
        }
        return Craft::$app->path->getStoragePath() . '/migration/mapping.yaml';
    }

    /**
     * Load and parse the mapping file. Returns an empty proposals shape if absent.
     *
     * @return array{proposals: list<array<string, mixed>>}
     */
    public function load(?string $path = null): array
    {
        $path ??= $this->resolvePath();
        if (!is_file($path)) {
            return ['proposals' => []];
        }
        $parsed = Yaml::parseFile($path) ?? [];
        if (!is_array($parsed) || !isset($parsed['proposals']) || !is_array($parsed['proposals'])) {
            return ['proposals' => []];
        }
        // Re-key as a list (drop string keys; preserve order).
        $rows = [];
        foreach ($parsed['proposals'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return ['proposals' => $rows];
    }

    /**
     * Subset of rows that the `map` rubber-stamp loop walks (D-08 stateless resume).
     *
     * @return list<array<string, mixed>>
     */
    public function loadProposed(?string $path = null): array
    {
        $data = $this->load($path);
        $out = [];
        foreach ($data['proposals'] as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'proposed' || $status === 'needs-review') {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Build a single proposal row in v1 wire shape, with the D-02 status assigned.
     *
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function buildRow(array $proposal, string $initialStatus): array
    {
        return [
            'table'           => (string) ($proposal['table'] ?? ''),
            'column'          => (string) ($proposal['column'] ?? ''),
            'targetEntryType' => (string) ($proposal['targetEntryType'] ?? ''),
            'targetHandle'    => (string) ($proposal['targetHandle'] ?? ''),
            'handler'         => (string) ($proposal['handler'] ?? ''),
            'confidence'      => (string) ($proposal['confidence'] ?? 'medium'),
            'rationale'       => (string) ($proposal['rationale'] ?? ''),
            'fillRate'        => (float)  ($proposal['fillRate'] ?? 0),
            'sqlType'         => (string) ($proposal['sqlType'] ?? ''),
            'samples'         => array_slice((array) ($proposal['samples'] ?? []), 0, 3),
            'status'          => $initialStatus,
        ];
    }

    /**
     * Skip-existing merge per D-04 — operator decisions are sacred (MAP-04).
     *
     * Identity tuple: (table, column, targetEntryType). Existing rows preserved verbatim;
     * incoming rows appended only if their tuple is unseen.
     *
     * @param array{proposals: list<array<string, mixed>>} $existing
     * @param list<array<string, mixed>>                    $incoming
     * @return array{proposals: list<array<string, mixed>>}
     */
    public function merge(array $existing, array $incoming): array
    {
        $merged = [];
        $seen = [];
        foreach (($existing['proposals'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $key = ($row['table'] ?? '') . '|' . ($row['column'] ?? '') . '|' . ($row['targetEntryType'] ?? '');
            $merged[] = $row;
            $seen[$key] = true;
        }
        foreach ($incoming as $row) {
            if (!is_array($row)) { continue; }
            $key = ($row['table'] ?? '') . '|' . ($row['column'] ?? '') . '|' . ($row['targetEntryType'] ?? '');
            if (!isset($seen[$key])) {
                $merged[] = $row;
                $seen[$key] = true;
            }
        }
        return ['proposals' => $merged];
    }

    /**
     * Mutate a single row's status (and optionally rationale, targetHandle, handler)
     * in place. Used by the `map` rubber-stamp loop after every keypress (D-07 atomic).
     *
     * Returns false if the row index does not exist (caller's bug, not a runtime error).
     */
    public function setStatus(
        string  $path,
        int     $rowIndex,
        string  $status,
        ?string $rationale = null,
        ?string $targetHandle = null,
        ?string $handler = null,
    ): bool {
        $parsed = Yaml::parseFile($path) ?? [];
        if (!is_array($parsed) || !isset($parsed['proposals'][$rowIndex]) || !is_array($parsed['proposals'][$rowIndex])) {
            return false;
        }
        $parsed['proposals'][$rowIndex]['status'] = $status;
        if ($rationale !== null)    { $parsed['proposals'][$rowIndex]['rationale']    = $rationale; }
        if ($targetHandle !== null) { $parsed['proposals'][$rowIndex]['targetHandle'] = $targetHandle; }
        if ($handler !== null)      { $parsed['proposals'][$rowIndex]['handler']      = $handler; }
        $yaml = Yaml::dump($parsed, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        return $this->writeAtomic($path, $yaml);
    }

    /**
     * Atomic write: tmp file + rename. Port of v1 MappingDraftWriter::writeAtomic
     * (lines 34-50). Operator Ctrl+C never corrupts state.
     */
    public function writeAtomic(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $bytes = @file_put_contents($tmp, $contents);
        if ($bytes === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Atomic JSON write — sibling helper for schema-dump.json (Plan 03 / SchemaDumper).
     * Same tmp+rename pattern; JSON_PRETTY_PRINT for diff-friendly output.
     *
     * @param array<string, mixed> $data
     */
    public function writeAtomicJson(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        return $this->writeAtomic($path, $json . "\n");
    }
}
```

Notes:
- Use `final class` per the Phase 1 service idiom (LegacyDbService is `class` only because Craft's component magic; v1 makes services `final`).
- The `random_bytes(4)` for tmp filename is verbatim from v1's writeAtomic.
- `Yaml::parseFile($path) ?? []` is the canonical v1 read pattern.
- `Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)` is the canonical v1 dump pattern (4=inline level, 2=indent).
- The class is callable from any controller/service via `Plugin::getInstance()->mappingFile`.
  </action>
  <verify>
    <automated>php -l src/mapping/MappingFile.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/mapping/MappingFile.php` exits 0
    - `grep -c 'final class MappingFile extends Component' src/mapping/MappingFile.php` equals 1
    - `grep -c 'namespace lameco\\\\kunstmaanmigrator\\\\mapping;' src/mapping/MappingFile.php` equals 1
    - `grep -c 'public function resolvePath' src/mapping/MappingFile.php` equals 1
    - `grep -c 'public function load' src/mapping/MappingFile.php` is 2 or more (load + loadProposed)
    - `grep -c 'public function buildRow' src/mapping/MappingFile.php` equals 1
    - `grep -c 'public function merge' src/mapping/MappingFile.php` equals 1
    - `grep -c 'public function setStatus' src/mapping/MappingFile.php` equals 1
    - `grep -c 'public function writeAtomic' src/mapping/MappingFile.php` is 2 or more (writeAtomic + writeAtomicJson)
    - `grep -c "Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK" src/mapping/MappingFile.php` is 1 or more
    - `grep -c "Yaml::parseFile" src/mapping/MappingFile.php` is 2 or more (in load and setStatus)
    - `grep -c "random_bytes(4)" src/mapping/MappingFile.php` equals 1
    - `grep -cE "rename\\(\\\$tmp, \\\$path\\)" src/mapping/MappingFile.php` equals 1 (atomic-write pattern)
  </acceptance_criteria>
  <done>MappingFile ships all 8 methods with v1's atomic-write idiom, status-on-row merge, and per-row setStatus mutation; PHP lint clean.</done>
</task>

<task type="auto">
  <name>Task 2: Wire MappingFile into Plugin::config()</name>
  <files>src/Plugin.php</files>
  <read_first>
    - src/Plugin.php (current shape after Plan 01 — components map now has 3 entries: legacyDbService, filterFactory, localePreflight)
    - src/mapping/MappingFile.php (created in Task 1 — FQCN to register)
  </read_first>
  <action>
Modify `src/Plugin.php` to register the new `mappingFile` component. Three small edits:

**Edit 1 — add `@property-read` to the class docblock:**

Find:
```php
 * @property-read LegacyDbService $legacyDbService
 * @property-read FilterFactory $filterFactory
 * @property-read LocalePreflight $localePreflight
 * @method Settings getSettings()
```

Replace with:
```php
 * @property-read LegacyDbService $legacyDbService
 * @property-read FilterFactory $filterFactory
 * @property-read LocalePreflight $localePreflight
 * @property-read MappingFile $mappingFile
 * @method Settings getSettings()
```

**Edit 2 — add `use` statement (alphabetize against existing imports):**

Find the existing `use` block and add `use lameco\kunstmaanmigrator\mapping\MappingFile;` so the block becomes:

```php
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\locale\LocalePreflight;
use lameco\kunstmaanmigrator\mapping\MappingFile;
use lameco\kunstmaanmigrator\models\Settings;
```

**Edit 3 — add to the components map:**

Find:
```php
        return [
            'components' => [
                'legacyDbService' => LegacyDbService::class,    // Phase 1
                'filterFactory'   => FilterFactory::class,      // Phase 2 (Plan 01) — D-10 Settings+CLI merge
                'localePreflight' => LocalePreflight::class,    // Phase 2 (Plan 01) — LOC-01 detect + LOC-02 ensure
            ],
        ];
```

Replace with:
```php
        return [
            'components' => [
                'legacyDbService' => LegacyDbService::class,    // Phase 1
                'filterFactory'   => FilterFactory::class,      // Phase 2 (Plan 01) — D-10 Settings+CLI merge
                'localePreflight' => LocalePreflight::class,    // Phase 2 (Plan 01) — LOC-01 detect + LOC-02 ensure
                'mappingFile'     => MappingFile::class,        // Phase 2 (Plan 02) — D-01/D-04/D-07 status-on-row IO
            ],
        ];
```

Do NOT touch Plugin::init(), createSettingsModel(), or settingsHtml().
  </action>
  <verify>
    <automated>php -l src/Plugin.php && composer test</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/Plugin.php` exits 0
    - `grep -c "'mappingFile'     => MappingFile::class" src/Plugin.php` equals 1
    - `grep -c '@property-read MappingFile \$mappingFile' src/Plugin.php` equals 1
    - `grep -c 'use lameco\\\\kunstmaanmigrator\\\\mapping\\\\MappingFile;' src/Plugin.php` equals 1
    - `composer test` exits 0 (Phase 1 PluginBootstrapTest still green)
  </acceptance_criteria>
  <done>Plugin.php registers mappingFile; composer test green; PHP lint clean.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Filesystem → MappingFile | Read mapping.yaml from disk. The file is operator-authored / -mutated; tampering between reads is the threat. |
| MappingFile → Filesystem | Atomic writes (tmp + rename) to mapping.yaml. Concurrent invocations / crashes mid-write are the threat. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-2-05 | T (Tampering) | mapping.yaml mid-write corruption | mitigate | `writeAtomic` writes to `${path}.tmp.${random_bytes(4)}` first, then `rename($tmp, $path)`. POSIX rename is atomic on the same filesystem. Operator Ctrl+C between bytes-written and rename leaves the original mapping.yaml intact. Verified by Task 1 acceptance criterion `rename($tmp, $path)` grep. |
| T-2-06 | T (Tampering) | Race between two concurrent `map` invocations | accept | Single-operator workflow (CLAUDE.md "CLI-only operator surface"). The atomic-rename ensures last-writer-wins without corruption; the operator may lose a keypress's worth of work but mapping.yaml stays valid. No file locking introduced — would add machinery without proportionate benefit. |
| T-2-07 | R (Repudiation) | Drop reasons must persist for audit | mitigate | The `rationale` field is part of every row (buildRow + setStatus both write it). Per D-03, drop-reason vocabulary lives in `rationale` ("fill-rate is 0", "no Craft target — operator-decided drop in map loop", etc.). Once setStatus writes a 'dropped' row with rationale, it's persisted by the same atomic write — no separate audit log needed. |
| T-2-08 | I (Information Disclosure) | tmp file contents visible briefly | accept | mapping.yaml contains no secrets — only legacy column names, Craft handles, and short legacy data samples. Living briefly under storage/migration/mapping.yaml.tmp.HEX with the same permissions as the parent directory is acceptable. The `bin2hex(random_bytes(4))` suffix prevents predictable tmp paths. |
</threat_model>

<verification>
- `php -l` passes on both files
- `composer test` exits 0 (Phase 1 smoke test still green)
- Plugin.php now registers exactly 4 components: legacyDbService, filterFactory, localePreflight, mappingFile
- MappingFile has all 8 public methods (resolvePath, load, loadProposed, buildRow, merge, setStatus, writeAtomic, writeAtomicJson)
</verification>

<success_criteria>
1. `MappingFile::load()` parses a YAML file and returns `['proposals' => [...]]`; returns `['proposals' => []]` for missing files.
2. `MappingFile::loadProposed()` returns only rows with status in `{proposed, needs-review}`.
3. `MappingFile::buildRow()` produces the v1 wire shape with the requested initialStatus.
4. `MappingFile::merge()` preserves every existing row verbatim and only appends new tuples (D-04).
5. `MappingFile::setStatus()` mutates a single row in place and rewrites via atomic write.
6. `MappingFile::writeAtomic()` uses tmp + rename (atomic on same filesystem).
7. `MappingFile::writeAtomicJson()` is a sibling helper for schema-dump.json (Plan 03 consumer).
8. `MappingFile::resolvePath()` returns Settings::mappingPath if set, else `storage/migration/mapping.yaml`.
9. Plugin.php registers `mappingFile` in components map; PHPDoc reflects new property.
</success_criteria>

<output>
After completion, create `.planning/phases/02-schema-mapping-filters/02-02-SUMMARY.md` documenting:
- Files created (with line counts)
- Plugin.php diff summary (4 components now registered)
- Confirmation that v1's MappingDraftReader + MappingDraftWriter were consolidated (not ported as separate classes)
- Confirmation that atomic-rename pattern matches v1 verbatim
- Any deviation from action text (should be none)
</output>
