<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use yii\base\Component;

/**
 * HeuristicProposer — REFAC-03 + Phase 02.1 / D-44 entity-aware heuristic 1.5.
 *
 * Runs the 10 deterministic heuristics that were Phase 9 in the v1
 * MappingProposalService. Single responsibility: take a flat list of
 * coverage-violation rows + a Craft field index keyed by entry type, and
 * return [matched, residual] where matched carries `decision` + `confidence`
 * + `targetHandle` + `handler` + `rationale` per heuristic, and residual is
 * the columns no heuristic matched (handed off to LlmClassifier downstream).
 *
 * Heuristics (applied in order, first match wins):
 *   1.   Fill-rate = 0                               → drop (high confidence)
 *   1.5  (D-44) Entity-aware: column → entity        → map  (high confidence)
 *        property → Craft field handle, scoped by
 *        entity-class context (table → accepted-row
 *        targetEntryType → property name match)
 *   3.   Exact name equality                          → map  (high confidence)
 *   4.   *_id stem → asset                            → map  (high confidence)
 *   5.   TEXT/LONGTEXT/MEDIUMTEXT + richtext field   → map  (high confidence)
 *   6.   *_image / *_photo → asset                    → map  (high confidence)
 *   7.   *_date / *_at → date                         → map  (high confidence)
 *   8.   *_url / *_link → url/link                    → map  (high confidence)
 *   9.   *_email / 'email' → email field              → map  (high confidence)
 *
 * Pure logic — no I/O, no LLM, no file writes. The caller (Plan 03
 * AnalyzeController) maps each proposal's `confidence` to mapping.yaml's
 * per-row `status` per D-02 by passing $proposal + $status into
 * MappingFile::buildRow.
 *
 * Note on language: column names in Lameco-convention Kunstmaan projects are
 * English (verified against ~/Sites/cqm-website — 59 unique columns, 0 Dutch).
 * v1's Dutch-alias heuristic and *_afbeelding / *_datum suffix entries were
 * removed as dead code. Anomalous Dutch-handle Kunstmaan projects fall through
 * to the LlmClassifier residual path.
 */
final class HeuristicProposer extends Component
{
    /**
     * Entity index for heuristic 1.5 (D-44). Set by AnalyzeController from
     * KunstmaanSourceScanner.scan()['entities'] before calling autoMatch().
     * Keyed by entity FQCN; value is the parsed Doctrine entity metadata.
     *
     * @var array<string, DoctrineEntityInfo>
     */
    public array $entityIndex = [];

    /**
     * Existing accepted column-row proposals for heuristic 1.5 (D-44). Set by
     * AnalyzeController from MappingFile::load($mappingPath)['proposals'] filtered
     * to status=accepted + kind=column rows. Heuristic 1.5 walks this list to
     * resolve a column's parent table to the operator-confirmed targetEntryType.
     *
     * @var list<array<string, mixed>>
     */
    public array $acceptedRows = [];

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
            $tableName = (string) ($v['table'] ?? '');
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

            // Heuristic 1.5 (D-44): entity-aware match. Column → entity property →
            // Craft field handle, scoped by entity-class context. Resolution chain:
            //   $tableName → DoctrineEntityInfo (via entityIndex) → look up an
            //   accepted column row for this table → that row's targetEntryType →
            //   match property name against fields on that entry type.
            // Higher confidence than heuristic 3 (exact-name) because it's scoped to
            // the entity that owns the column, not a global handle search. Confidence
            // = 'high' → AnalyzeController::statusForHeuristic auto-promotes to
            // status=accepted (Phase 2 / D-02). When no accepted row exists for the
            // entity yet, this heuristic falls through and the existing heuristics
            // 3-9 fire as before.
            if ($tableName !== '' && $this->entityIndex !== []) {
                $entityForTable = null;
                foreach ($this->entityIndex as $entityInfo) {
                    if ($entityInfo->tableName === $tableName) {
                        $entityForTable = $entityInfo;
                        break;
                    }
                }
                if ($entityForTable !== null) {
                    $resolvedEntryType = '';
                    foreach ($this->acceptedRows as $acceptedRow) {
                        if ((string) ($acceptedRow['table'] ?? '') === $tableName) {
                            $resolvedEntryType = (string) ($acceptedRow['targetEntryType'] ?? '');
                            if ($resolvedEntryType !== '') { break; }
                        }
                    }
                    if ($resolvedEntryType !== '') {
                        $propertyName = '';
                        foreach ($entityForTable->columns as $colInfo) {
                            if ($colInfo->columnName === $column) {
                                $propertyName = $colInfo->propertyName;
                                break;
                            }
                        }
                        $resolvedFields = $craftFieldIndex[$resolvedEntryType] ?? [];
                        if ($propertyName !== '') {
                            $matchField = null;
                            foreach ($resolvedFields as $f) {
                                if ((string) ($f['handle'] ?? '') === $propertyName) {
                                    $matchField = $f;
                                    break;
                                }
                            }
                            if ($matchField !== null) {
                                // Re-target the violation to the resolved entry type so
                                // buildProposal carries the entity-scoped target. Confidence
                                // is explicitly 'high' so AnalyzeController::statusForHeuristic
                                // auto-promotes to status=accepted (Phase 2 / D-02 + D-44).
                                $vScoped = $v;
                                $vScoped['targetEntryType'] = $resolvedEntryType;
                                $matched[] = $this->buildProposal(
                                    $vScoped,
                                    (string) $matchField['handle'],
                                    $this->handlerForClassification((string) ($matchField['classification'] ?? '')),
                                    sprintf(
                                        'heuristic 1.5: entity %s property %s → %s.%s',
                                        $entityForTable->fqcn,
                                        $propertyName,
                                        $resolvedEntryType,
                                        (string) $matchField['handle'],
                                    ),
                                    'high',
                                    'map',
                                );
                                continue;
                            }
                        }
                    }
                }
            }

            // 3. Exact name match (high confidence).
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

            // 4. *_id → asset field by stem (high confidence).
            // Skip when the column is literally `_id` (empty stem matches every asset field).
            if (str_ends_with($column, '_id') && strlen($column) > 3) {
                $stem = substr($column, 0, -3);
                $assetHandle = $this->findAssetByStem($stem, $fields);
                if ($assetHandle !== null) {
                    $matched[] = $this->buildProposal(
                        $v, $assetHandle, 'asset', 'auto-match: *_id → asset field',
                    );
                    continue;
                }
            }

            // 5. SQL type TEXT/LONGTEXT/MEDIUMTEXT → ckeditor (high confidence).
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

            // 6. *_image / *_photo → asset field (high confidence).
            if ($this->columnEndsWith($column, ['_image', '_photo'])) {
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

            // 7. *_date / *_at → date field (high confidence).
            if ($this->columnEndsWith($column, ['_date', '_at'])) {
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

            // 8. *_url / *_link → url or link field (high confidence).
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

            // 9. *_email or bare 'email' → email field (high confidence).
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
