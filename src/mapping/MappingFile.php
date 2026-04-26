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
     * Kind-agnostic: walks both `kind: column` and `kind: pagePart` rows uniformly.
     * Page-part rows always start `status: needs-review` per D-35, so this method picks
     * them up automatically without any kind-specific branching.
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
     * Build a single column-level proposal row in v1 wire shape, with the D-02 status
     * assigned. The returned row carries an explicit `kind: column` discriminator per D-34
     * so the rubber-stamp loop and merge() identity tuple can branch on row kind.
     *
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function buildRow(array $proposal, string $initialStatus): array
    {
        return [
            'kind'            => 'column',
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
     * Build a kind=pagePart row per D-34. Always emits `status: needs-review` per D-35
     * (no auto-promotion on name match in v1.0; page-part → block-type mapping is a
     * domain decision, no deterministic heuristic is strong enough).
     *
     * Note: `targetEntryType` IS in the row payload (operator fills it via the rubber-stamp
     * loop, or analyze step 4.5 emitter may leave it blank), but is NOT part of the merge
     * identity tuple (W1 fix — see merge() below). Page-part identity is structural-only:
     * (pagePartClass, parentPageClass, context). This prevents idempotent re-run row
     * duplication where analyze emits an empty targetEntryType, the operator fills it,
     * and the next analyze re-emits empty — without the structural-only key, that flow
     * would append a duplicate row.
     *
     * @param list<array{sourceProperty: string, targetHandle: string, handler: string}> $fields
     * @return array<string, mixed>
     */
    public function buildPagePartRow(
        string $pagePartClass,
        string $sourceTable,
        string $parentPageClass,
        string $context,
        string $targetEntryType,
        string $targetMatrixField = '',
        string $targetBlockType = '',
        array  $fields = [],
        string $confidence = '',
        string $rationale = '',
    ): array {
        return [
            'kind'              => 'pagePart',
            'pagePartClass'     => $pagePartClass,
            'sourceTable'       => $sourceTable,
            'parentPageClass'   => $parentPageClass,
            'context'           => $context,
            'targetEntryType'   => $targetEntryType,
            'targetMatrixField' => $targetMatrixField,
            'targetBlockType'   => $targetBlockType,
            'fields'            => $fields,
            'status'            => 'needs-review',
            'confidence'        => $confidence,
            'rationale'         => $rationale,
        ];
    }

    /**
     * Skip-existing merge per D-04 — operator decisions are sacred (MAP-04).
     *
     * Identity tuple is kind-prefixed (Phase 02.1 / D-34 patches Phase 2 / D-04):
     *   - column rows: `column|table|column|targetEntryType` — preserved verbatim from
     *     Phase 2 / D-04. Existing Phase 2 mapping.yaml files load unchanged because rows
     *     without an explicit `kind:` default to `column`.
     *   - pagePart rows: `pagePart|pagePartClass|parentPageClass|context` — STRUCTURAL
     *     ONLY (W1 fix from plan-checker). Page-part identity is the structural triple
     *     "which class lives in which context under which parent page"; the
     *     `targetEntryType` is an operator decision (filled in via the rubber-stamp loop)
     *     and is NOT part of identity. This prevents idempotent re-run row duplication:
     *     analyze emits a row with empty targetEntryType → operator fills it via map →
     *     next analyze re-emits with empty again → tuples match (because no
     *     targetEntryType in key) → row dedupes instead of appending.
     *
     * Existing rows preserved verbatim; incoming rows appended only if their tuple is unseen.
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
            $key = $this->identityKey($row);
            $merged[] = $row;
            $seen[$key] = true;
        }
        foreach ($incoming as $row) {
            if (!is_array($row)) { continue; }
            $key = $this->identityKey($row);
            if (!isset($seen[$key])) {
                $merged[] = $row;
                $seen[$key] = true;
            }
        }
        return ['proposals' => $merged];
    }

    /**
     * Build the merge identity key for a single row (D-34 kind-prefixed tuple).
     *
     * Page-part identity is structural — STRUCTURAL ONLY, no targetEntryType in the key
     * (W1 fix). See merge() docblock for the dedupe-on-empty-targetEntryType rationale.
     *
     * @param array<string, mixed> $row
     */
    private function identityKey(array $row): string
    {
        $kind = (string) ($row['kind'] ?? 'column');
        if ($kind === 'pagePart') {
            // page-part identity is structural-only — no targetEntryType (W1 fix)
            return 'pagePart|' . ($row['pagePartClass'] ?? '')
                . '|' . ($row['parentPageClass'] ?? '')
                . '|' . ($row['context'] ?? '');
        }
        return 'column|' . ($row['table'] ?? '')
            . '|' . ($row['column'] ?? '')
            . '|' . ($row['targetEntryType'] ?? '');
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
