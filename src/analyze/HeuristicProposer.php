<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use yii\base\Component;

/**
 * HeuristicProposer — REFAC-03.
 *
 * Runs the 9 deterministic heuristics that were Phase 9 in the v1
 * MappingProposalService. Single responsibility: take a flat list of
 * coverage-violation rows + a Craft field index keyed by entry type, and
 * return [matched, residual] where matched carries `decision` + `confidence`
 * + `targetHandle` + `handler` + `rationale` per heuristic, and residual is
 * the columns no heuristic matched (handed off to LlmClassifier downstream).
 *
 * Heuristics (applied in order, first match wins):
 *   1. Fill-rate = 0          → drop (high confidence)
 *   2. Exact name equality    → map  (high confidence)
 *   3. *_id stem → asset      → map  (high confidence)
 *   4. TEXT/LONGTEXT/MEDIUMTEXT + richtext field → map (high confidence)
 *   5. *_image / *_photo / *_afbeelding → asset (high confidence)
 *   6. *_date / *_at / *_datum → date (high confidence)
 *   7. *_url / *_link → url/link (high confidence)
 *   8. *_email / 'email' → email field (high confidence)
 *   9. Dutch semantic alias → fuzzy match (medium confidence)
 *
 * Pure logic — no I/O, no LLM, no file writes. The caller (Plan 03
 * AnalyzeController) maps each proposal's `confidence` to mapping.yaml's
 * per-row `status` per D-02 by passing $proposal + $status into
 * MappingFile::buildRow.
 */
final class HeuristicProposer extends Component
{
    /**
     * Dutch semantic alias map — maps Dutch column names to likely English
     * Craft field handles. Used for the fuzzy-match heuristic (medium confidence).
     */
    private const DUTCH_ALIASES = [
        'beschrijving'  => ['description', 'body', 'tekst', 'content'],
        'omschrijving'  => ['description', 'body', 'content'],
        'afbeelding'    => ['image', 'img', 'photo', 'thumbnail'],
        'foto'          => ['image', 'photo', 'thumbnail'],
        'datum'         => ['date', 'publishedDate', 'publishDate'],
        'titel'         => ['title', 'heading', 'name'],
        'naam'          => ['name', 'title'],
        'tekst'         => ['body', 'content', 'text'],
        'subtitel'      => ['subtitle', 'subTitle', 'subheading'],
        'samenvatting'  => ['summary', 'excerpt', 'teaser'],
        'intro'         => ['intro', 'introduction', 'excerpt'],
        'url'           => ['url', 'link', 'slug'],
        'link'          => ['url', 'link'],
    ];

    /**
     * Auto-match deterministically. Returns [$matched, $residual] where:
     *   - $matched: list of proposals for columns matched by heuristic alone
     *   - $residual: list of violations still needing LLM triage
     *
     * @param list<array{table: string, column: string, fillRate: float|int, rows?: int, samples?: list<string>, sqlType?: string, targetEntryType: string, sourceNodeClass?: string}> $violations
     * @param array<string, list<array{handle: string, type: string, classification?: string}>> $craftFieldIndex
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public function autoMatch(array $violations, array $craftFieldIndex): array
    {
        $matched = [];
        $residual = [];

        foreach ($violations as $v) {
            $entryType = (string) ($v['targetEntryType'] ?? '');
            $column = (string) ($v['column'] ?? '');
            $fillRate = (float) ($v['fillRate'] ?? -1);
            $sqlType = strtoupper((string) ($v['sqlType'] ?? ''));
            $fields = $craftFieldIndex[$entryType] ?? [];

            // 1. Zero fill-rate → auto-drop (high confidence).
            if ($fillRate === 0.0) {
                $matched[] = $this->buildProposal(
                    $v, '', '', 'fill-rate is 0 — no data in source', 'high', 'drop',
                );
                continue;
            }

            // 2. Exact name match (high confidence).
            $handle = $this->exactNameMatch($column, $fields);
            if ($handle !== null) {
                $matched[] = $this->buildProposal(
                    $v,
                    $handle['handle'],
                    $this->handlerForClassification($handle['classification'] ?? ''),
                    'auto-match: name equality',
                );
                continue;
            }

            // 3. *_id → asset field by stem (high confidence).
            if (str_ends_with($column, '_id')) {
                $stem = substr($column, 0, -3);
                $assetHandle = $this->findAssetByStem($stem, $fields);
                if ($assetHandle !== null) {
                    $matched[] = $this->buildProposal(
                        $v, $assetHandle, 'asset', 'auto-match: *_id → asset field',
                    );
                    continue;
                }
            }

            // 4. SQL type TEXT/LONGTEXT/MEDIUMTEXT → ckeditor (high confidence).
            // Only when the entry type has a richtext-classified field.
            if ($sqlType !== '' && str_contains($sqlType, 'TEXT')) {
                $richtextField = $this->findFieldByClassification('richtext', $fields);
                if ($richtextField !== null) {
                    $matched[] = $this->buildProposal(
                        $v,
                        $richtextField['handle'],
                        'ckeditor',
                        sprintf('auto-match: sqlType=%s → richtext field', $sqlType),
                    );
                    continue;
                }
            }

            // 5. *_image / *_photo / *_afbeelding → asset field (high confidence).
            if ($this->columnEndsWith($column, ['_image', '_photo', '_afbeelding'])) {
                $assetField = $this->findFieldByClassification('asset', $fields);
                if ($assetField !== null) {
                    $matched[] = $this->buildProposal(
                        $v,
                        $assetField['handle'],
                        'asset',
                        sprintf('auto-match: %s → asset field', $column),
                    );
                    continue;
                }
            }

            // 6. *_date / *_at / *_datum → date field (high confidence).
            if ($this->columnEndsWith($column, ['_date', '_at', '_datum'])) {
                $dateField = $this->findFieldByClassificationOrHandle('date', 'date', $fields);
                if ($dateField !== null) {
                    $matched[] = $this->buildProposal(
                        $v,
                        $dateField['handle'],
                        $this->handlerForClassification($dateField['classification'] ?? ''),
                        sprintf('auto-match: %s → date field', $column),
                    );
                    continue;
                }
            }

            // 7. *_url / *_link → url or link field (high confidence).
            if ($this->columnEndsWith($column, ['_url', '_link'])) {
                $urlField = $this->findFieldByClassificationOrHandle('url', 'url', $fields)
                    ?? $this->findFieldByClassificationOrHandle('url', 'link', $fields);
                if ($urlField !== null) {
                    $matched[] = $this->buildProposal(
                        $v,
                        $urlField['handle'],
                        $this->handlerForClassification($urlField['classification'] ?? ''),
                        sprintf('auto-match: %s → url/link field', $column),
                    );
                    continue;
                }
            }

            // 8. *_email or bare 'email' → email field (high confidence).
            if (str_ends_with(strtolower($column), '_email') || strtolower($column) === 'email') {
                $emailField = $this->findFieldByHandleContaining('email', $fields);
                if ($emailField !== null) {
                    $matched[] = $this->buildProposal(
                        $v,
                        $emailField['handle'],
                        $this->handlerForClassification($emailField['classification'] ?? ''),
                        sprintf('auto-match: %s → email field', $column),
                    );
                    continue;
                }
            }

            // 9. Dutch semantic alias → fuzzy match (medium confidence).
            $aliasMatch = $this->dutchAliasMatch($column, $fields);
            if ($aliasMatch !== null) {
                $matched[] = $this->buildProposal(
                    $v,
                    $aliasMatch['handle'],
                    $this->handlerForClassification($aliasMatch['classification'] ?? ''),
                    sprintf('Dutch alias: %s → %s', $column, $aliasMatch['handle']),
                    'medium',
                );
                continue;
            }

            $residual[] = $v;
        }

        return [$matched, $residual];
    }

    // ------------------------------------------------------------------
    // Internals — heuristic helpers
    // ------------------------------------------------------------------

    /**
     * @param list<array{handle: string, type: string, classification?: string}> $fields
     * @return array{handle: string, type: string, classification?: string}|null
     */
    private function exactNameMatch(string $column, array $fields): ?array
    {
        $needle = strtolower($column);
        foreach ($fields as $f) {
            if (strtolower((string) ($f['handle'] ?? '')) === $needle) {
                return $f;
            }
        }
        return null;
    }

    /**
     * @param list<array{handle: string, type: string, classification?: string}> $fields
     */
    private function findAssetByStem(string $stem, array $fields): ?string
    {
        $stem = strtolower($stem);
        foreach ($fields as $f) {
            if (($f['classification'] ?? '') !== 'asset') { continue; }
            $h = strtolower((string) ($f['handle'] ?? ''));
            if ($h === $stem || str_contains($h, $stem)) {
                return (string) $f['handle'];
            }
        }
        return null;
    }

    /**
     * Find the first field with a given classification.
     *
     * @param list<array{handle: string, type: string, classification?: string}> $fields
     * @return array{handle: string, type: string, classification?: string}|null
     */
    private function findFieldByClassification(string $classification, array $fields): ?array
    {
        foreach ($fields as $f) {
            if (($f['classification'] ?? '') === $classification) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Find a field by classification first, then fall back to handle keyword.
     *
     * @param list<array{handle: string, type: string, classification?: string}> $fields
     * @return array{handle: string, type: string, classification?: string}|null
     */
    private function findFieldByClassificationOrHandle(string $classification, string $handleKeyword, array $fields): ?array
    {
        foreach ($fields as $f) {
            if (($f['classification'] ?? '') === $classification) {
                return $f;
            }
        }
        $keyword = strtolower($handleKeyword);
        foreach ($fields as $f) {
            if (str_contains(strtolower((string) ($f['handle'] ?? '')), $keyword)) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Find a field whose handle contains the given keyword (case-insensitive).
     *
     * @param list<array{handle: string, type: string, classification?: string}> $fields
     * @return array{handle: string, type: string, classification?: string}|null
     */
    private function findFieldByHandleContaining(string $keyword, array $fields): ?array
    {
        $keyword = strtolower($keyword);
        foreach ($fields as $f) {
            if (str_contains(strtolower((string) ($f['handle'] ?? '')), $keyword)) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Check if column name ends with any of the given suffixes (case-insensitive).
     *
     * @param list<string> $suffixes
     */
    private function columnEndsWith(string $column, array $suffixes): bool
    {
        $lower = strtolower($column);
        foreach ($suffixes as $suffix) {
            if (str_ends_with($lower, strtolower($suffix))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dutch alias fuzzy-match. Normalizes the column name (lowercase, also tries
     * the last underscore-segment), then checks the DUTCH_ALIASES map in both
     * directions: key→values and values→key+siblings.
     *
     * @param list<array{handle: string, type: string, classification?: string}> $fields
     * @return array{handle: string, type: string, classification?: string}|null
     */
    private function dutchAliasMatch(string $column, array $fields): ?array
    {
        $normalized = strtolower($column);
        $segments = explode('_', $normalized);
        $lastSegment = end($segments);

        $candidates = [$normalized];
        if ($lastSegment !== $normalized) {
            $candidates[] = $lastSegment;
        }

        // Build handle-to-field lookup for fast matching.
        $fieldsByHandle = [];
        foreach ($fields as $f) {
            $h = strtolower((string) ($f['handle'] ?? ''));
            if ($h !== '') {
                $fieldsByHandle[$h] = $f;
            }
        }

        foreach ($candidates as $candidate) {
            // Case A: candidate matches a Dutch alias key → look for the values as handles.
            if (isset(self::DUTCH_ALIASES[$candidate])) {
                foreach (self::DUTCH_ALIASES[$candidate] as $target) {
                    $t = strtolower($target);
                    if (isset($fieldsByHandle[$t])) {
                        return $fieldsByHandle[$t];
                    }
                }
            }

            // Case B: candidate matches a value in some alias list → try key + other values.
            foreach (self::DUTCH_ALIASES as $key => $values) {
                $valuesLower = array_map('strtolower', $values);
                if (in_array($candidate, $valuesLower, true)) {
                    if (isset($fieldsByHandle[$key])) {
                        return $fieldsByHandle[$key];
                    }
                    foreach ($valuesLower as $alt) {
                        if ($alt !== $candidate && isset($fieldsByHandle[$alt])) {
                            return $fieldsByHandle[$alt];
                        }
                    }
                }
            }
        }

        return null;
    }

    private function handlerForClassification(string $classification): string
    {
        return match ($classification) {
            'asset' => 'asset',
            'richtext' => 'ckeditor',
            'richtext-container' => 'matrix',
            'relation' => 'relation',
            'plugin' => 'seomatic',
            'date' => 'date',
            'email' => 'email',
            'url' => 'url',
            default => 'plain',
        };
    }

    /**
     * @param array<string, mixed> $v  Violation row (carries table, column, targetEntryType, fillRate, sqlType, samples)
     * @return array<string, mixed>
     */
    private function buildProposal(
        array $v,
        string $targetHandle,
        string $handler,
        string $rationale,
        string $confidence = 'high',
        string $decision = 'map',
    ): array {
        return [
            'table'           => (string) ($v['table'] ?? ''),
            'column'          => (string) ($v['column'] ?? ''),
            'targetEntryType' => (string) ($v['targetEntryType'] ?? ''),
            'decision'        => $decision,
            'targetHandle'    => $targetHandle,
            'handler'         => $handler,
            'rationale'       => $rationale,
            'confidence'      => $confidence, // 'high' | 'medium' | 'low'
            'fillRate'        => (float) ($v['fillRate'] ?? 0),
            'sqlType'         => (string) ($v['sqlType'] ?? ''),
            'samples'         => (array) ($v['samples'] ?? []),
        ];
    }
}
