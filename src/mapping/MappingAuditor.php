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
