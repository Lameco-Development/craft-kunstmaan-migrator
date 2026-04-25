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
