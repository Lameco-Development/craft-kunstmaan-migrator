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
    /**
     * BlockAvailabilityValidator (D-36 fourth finding kind: 'block-availability').
     * Optional — when null, block-availability checks are skipped (PluginBootstrapTest
     * legacy unit-test bootstrap path). In production wiring (Plugin::config) this is
     * set via Yii component injection.
     */
    public ?BlockAvailabilityValidator $blockAvailabilityValidator = null;

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
     * Phase 02.1 / D-36 adds a fourth finding kind 'block-availability' (delegated
     * to BlockAvailabilityValidator). The validator runs against a v1-shaped
     * mapping adapter built from v2's flat proposals[] (see buildV1ShapedMapping).
     * In v1.0 the matrix-availability index is empty (KnowledgeBase port deferred
     * to Plan 09 reconciliation), so the validator is effectively a no-op until
     * the index lights up; the wiring is correct regardless.
     *
     * Drift findings (D-32) are NOT consumed here — they flow through the call
     * site directly to renderMarkdown() as its second argument. Drift is a
     * separate concern (escalated by --source-strict, not --audit-strict).
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
            // Patched in Phase 02.1 / Plan 09 from v1 MappingValidator.php:580-617 per RECONCILIATION.md.
            // Drop-rationale length check (v1 rule 13): a status:dropped row must carry a
            // rationale of at least 10 chars. Prevents lazy "TODO" / "n/a" drops from sneaking
            // past review. MUST run BEFORE the dropped-skip below or it never fires.
            if ($status === 'dropped') {
                if (strlen((string) ($row['rationale'] ?? '')) < 10) {
                    $findings[] = [
                        'table'           => (string) ($row['table'] ?? ''),
                        'column'          => (string) ($row['column'] ?? ''),
                        'targetEntryType' => (string) ($row['targetEntryType'] ?? ''),
                        'targetHandle'    => (string) ($row['targetHandle'] ?? ''),
                        'kind'            => 'drop-rationale-missing',
                        'detail'          => "dropped row has rationale shorter than 10 chars (v1 MappingValidator rule 13)",
                    ];
                }
                continue;
            }
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

        // Phase 02.1 / D-36: block-availability finding kind via BlockAvailabilityValidator.
        // Build v1-shaped mapping from v2's flat proposals[] (kind=pagePart rows only).
        // matrixIndex is empty in v1.0 — Plan 09 reconciliation may port
        // KnowledgeBase::buildMatrixAvailabilityIndex if the rule-by-rule audit surfaces
        // it as accidentally-dropped. Until then, the validator is wired but inert.
        if ($this->blockAvailabilityValidator !== null) {
            $v1ShapedMapping = $this->buildV1ShapedMapping($mappingProposals);
            $matrixIndex = []; // deferred to Plan 09 per PATTERNS section 12
            $blockErrors = $this->blockAvailabilityValidator->validate($v1ShapedMapping, $matrixIndex);
            foreach ($blockErrors as $errorMessage) {
                $findings[] = [
                    'table'           => '',
                    'column'          => '',
                    'targetEntryType' => '',
                    'targetHandle'    => '',
                    'kind'            => 'block-availability',
                    'detail'          => (string) $errorMessage,
                ];
            }
        }

        return $findings;
    }

    /**
     * Adapter — walk v2's flat proposals[] and emit a v1-shaped mapping
     * (`pageParts` keyed by FQCN with `target` block handle). Used by audit() to
     * feed BlockAvailabilityValidator (whose v1 signature reads pageParts/nodeClasses/sections).
     *
     * v2 doesn't ship nodeClasses / sections in mapping.yaml — those buckets stay
     * empty here. Plan 09 reconciliation may extend the adapter once heuristic 1.5's
     * accepted-rows index surfaces the column → entry-type relation that v1 sourced
     * from a dedicated nodeClasses block.
     *
     * @param list<array<string, mixed>> $mappingProposals
     * @return array{pageParts: array<string, array<string, mixed>>, nodeClasses: array<string, mixed>, sections: array<string, mixed>}
     */
    private function buildV1ShapedMapping(array $mappingProposals): array
    {
        $shaped = ['pageParts' => [], 'nodeClasses' => [], 'sections' => []];
        foreach ($mappingProposals as $row) {
            if (!is_array($row)) { continue; }
            if (((string) ($row['kind'] ?? 'column')) !== 'pagePart') { continue; }
            // Only feed accepted/proposed rows to the validator. Dropped rows are no-ops
            // (operator already decided to drop the page-part); needs-review is incomplete.
            $status = (string) ($row['status'] ?? '');
            if (!in_array($status, ['accepted', 'proposed'], true)) { continue; }
            $key = (string) ($row['pagePartClass'] ?? '');
            if ($key === '') { continue; }
            $shaped['pageParts'][$key] = [
                'action' => null, // v2 has no SKIP action; status:dropped is the v2 idiom (filtered out above)
                'target' => (string) ($row['targetBlockType'] ?? ''),
            ];
        }
        return $shaped;
    }

    /**
     * Render findings as MAPPING-AUDIT.md content + a parallel console-friendly block.
     *
     * Phase 02.1 / D-32: drift findings (DB↔scan mismatch from KunstmaanSourceScanner)
     * are emitted as a sibling section. Locked Discretion: single-file rule preserved
     * (no separate DRIFT-REPORT.md). Empty drift sub-buckets are omitted; both empty
     * → entire Drift section omitted.
     *
     * @param list<array{table: string, column: string, targetEntryType: string, targetHandle: string, kind: string, detail: string}> $findings
     * @param array{dbHasButScanMissing?: list<string>, scanHasButDbMissing?: list<string>} $driftFindings
     */
    public function renderMarkdown(array $findings, array $driftFindings = []): string
    {
        // FieldLayout-side findings block.
        if ($findings === []) {
            $out = "# Mapping Audit\n\nNo drift detected. Mapping references resolve cleanly against the live Craft FieldLayout.\n";
        } else {
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
        }

        // D-32: Drift section (DB↔scan mismatch). Sibling of FieldLayout findings.
        $dbHasButScanMissing = (array) ($driftFindings['dbHasButScanMissing'] ?? []);
        $scanHasButDbMissing = (array) ($driftFindings['scanHasButDbMissing'] ?? []);
        if ($dbHasButScanMissing !== [] || $scanHasButDbMissing !== []) {
            $out .= "\n## Drift findings\n\n";
            if ($dbHasButScanMissing !== []) {
                $out .= "### DB has tables not in source scan\n\n";
                foreach ($dbHasButScanMissing as $t) {
                    $out .= "- `" . (string) $t . "`\n";
                }
                $out .= "\n";
            }
            if ($scanHasButDbMissing !== []) {
                $out .= "### Source scan declares tables missing from DB\n\n";
                foreach ($scanHasButDbMissing as $t) {
                    $out .= "- `" . (string) $t . "`\n";
                }
                $out .= "\n";
            }
        }

        return $out;
    }
}
