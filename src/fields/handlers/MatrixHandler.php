<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields\handlers;

use lameco\kunstmaanmigrator\fields\FieldHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use RuntimeException;

/**
 * Expands a legacy parent-id into a Craft Matrix block array by streaming
 * child rows from a sibling legacy table.
 *
 * Two dispatch paths (selected by options shape):
 *
 *   (a) Generic Matrix path — v1 verbatim. Triggered when 'pagePartClass' is
 *       NOT set in options. Streams rows from `itemTable` filtered by `fkCol`,
 *       emits one Matrix block per row keyed `'new1' => [...], 'new2' => [...]`.
 *
 *   (b) Page-part Matrix path — D-49 mapping-driven. Triggered when 'pagePartClass'
 *       IS set. The caller (TransformService) walks the kuma_node_versions →
 *       kuma_main_pageparts → kuma_page_part_refs JOIN chain and the per-row
 *       FieldSpec walks, then hands MatrixHandler a list of pre-resolved block
 *       fields hashes; this method just wraps them in the new1/new2/... shape.
 *
 * Required options (generic path):
 *   itemTable  (string)       — legacy child table name (e.g. 'lameco_websitebundle_client_item')
 *   fkCol      (string)       — FK column in child table back to parent row
 *   blockType  (string)       — target Matrix block type handle
 *
 * At least one of (generic path):
 *   valueCol   (string)       — single payload column for simple 1-row-1-value shape
 *   bodyCol    (string)       — CKEditor column (routed through $ctx->ck->rewrite)
 *
 * Ordering (generic path — pick one):
 *   orderCols  (list<string>) — multi-column ORDER BY
 *   orderCol   (string)       — single-column ORDER BY
 *                               (default when neither given: 'id')
 *
 * Field-handle override (generic path):
 *   handle     (string, default 'value') — target field handle inside the block
 *
 * Required options (page-part path — D-49):
 *   pagePartClass     (string) — e.g. 'App\\Entity\\PageParts\\TextPagePart'
 *   parentPageClass   (string) — e.g. 'App\\Entity\\Pages\\NewsPage'
 *   context           (string) — e.g. 'main'
 *   targetMatrixField (string) — Craft Matrix field handle on the parent entry type
 *   targetBlockType   (string) — Craft Matrix block-type handle
 *   fields            (array)  — handler-options map keyed on Craft block-type field handle
 *
 * Output: array keyed `'new1' => [...], 'new2' => [...]` ready for
 * $entry->setFieldValue(<matrix field>, ...). The declarative driver
 * (Plan 04) hands this to EntryMigrationService::threadBlockUidsIntoPageBuilder
 * for UID threading (Pitfall 3). Handlers do NOT write to entries directly.
 *
 * Security: table/column names come from the CQM config file (trusted PHP
 * code per RESEARCH.md §Security Domain); the FK value is parameter-bound
 * in the underlying query. Matrix handler does not introduce any
 * unserialize() calls — central-unserialize invariant preserved.
 */
final class MatrixHandler implements FieldHandler
{
    public function id(): string
    {
        return 'matrix';
    }

    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
    {
        // D-49 page-part path: keyed on mapping.yaml pagePart row tuple.
        // Triggered when caller passes 'pagePartClass' option (only TransformService does this,
        // when walking page-part rows for an entry).
        if (isset($options['pagePartClass'])) {
            return $this->resolvePagePartMatrix($legacyValue, $ctx, $options);
        }

        // v1 generic path: itemTable/fkCol/blockType.
        return $this->resolveGenericMatrix($legacyValue, $ctx, $options);
    }

    /**
     * v1 verbatim generic Matrix path — streams child rows from a sibling legacy
     * table and emits one Craft Matrix block per row.
     *
     * @param array<string, mixed> $options
     * @return array<string, array{type: string, enabled: bool, fields: array<string, mixed>}>
     */
    private function resolveGenericMatrix(mixed $legacyValue, ResolverContext $ctx, array $options): array
    {
        if ($ctx->legacyDb === null) {
            throw new RuntimeException('MatrixHandler requires ResolverContext::$legacyDb to be non-null.');
        }
        foreach (['itemTable', 'fkCol', 'blockType'] as $req) {
            if (empty($options[$req]) || !is_string($options[$req])) {
                throw new RuntimeException("MatrixHandler requires '{$req}' option (non-empty string).");
            }
        }
        if (empty($options['valueCol']) && empty($options['bodyCol'])) {
            throw new RuntimeException("MatrixHandler requires one of 'valueCol' or 'bodyCol'.");
        }

        $fkValue = (int) $legacyValue;
        if ($fkValue <= 0) {
            return [];
        }

        $itemTable = (string) $options['itemTable'];
        $fkCol = (string) $options['fkCol'];
        $blockType = (string) $options['blockType'];
        $handle = (string) ($options['handle'] ?? 'value');

        // Build ORDER BY clause from orderCols (list) or orderCol (string), default 'id'.
        $orderCols = $options['orderCols'] ?? null;
        if (is_array($orderCols) && $orderCols !== []) {
            $orderBy = implode(', ', array_map('strval', $orderCols));
        } else {
            $orderBy = (string) ($options['orderCol'] ?? 'id');
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :fk ORDER BY %s',
            $itemTable,
            $fkCol,
            $orderBy,
        );

        $blocks = [];
        $n = 0;
        foreach ($ctx->legacyDb->streamQuery($sql, [':fk' => $fkValue]) as $row) {
            $n++;
            $fields = [];
            if (!empty($options['valueCol'])) {
                $valueCol = (string) $options['valueCol'];
                $fields[$handle] = $row[$valueCol] ?? null;
            }
            if (!empty($options['bodyCol'])) {
                $bodyCol = (string) $options['bodyCol'];
                $raw = (string) ($row[$bodyCol] ?? '');
                // Route CKEditor bodies through the rewriter directly (avoids
                // circular registry dependency — handlers never call each
                // other through the registry in v1.1).
                $fields[$handle] = $raw === '' ? '' : $ctx->ck->rewrite($raw, $ctx->siteId);
            }
            $blocks['new' . $n] = [
                'type' => $blockType,
                'enabled' => true,
                'fields' => $fields,
            ];
        }

        return $blocks;
    }

    /**
     * D-49 page-part Matrix path. The pre-resolution of FieldSpec walks is owned by TransformService;
     * by the time we land here, $legacyValue is a list of already-built Craft block-fields hashes,
     * and we just wrap them in the new1/new2/... key shape that Craft 5 setFieldValue expects.
     *
     * Expected $legacyValue shape: list<array{fields: array<string, mixed>}>.
     *
     * @param array<string, mixed> $options
     * @return array<string, array{type: string, enabled: bool, fields: array<string, mixed>}>
     */
    private function resolvePagePartMatrix(mixed $legacyValue, ResolverContext $ctx, array $options): array
    {
        // Validate required options (D-49 mapping.yaml pagePart row tuple).
        foreach (['pagePartClass', 'parentPageClass', 'context', 'targetMatrixField', 'targetBlockType'] as $req) {
            if (empty($options[$req]) || !is_string($options[$req])) {
                throw new RuntimeException("MatrixHandler (page-part path) requires '{$req}' option (non-empty string).");
            }
        }
        if (!isset($options['fields']) || !is_array($options['fields'])) {
            throw new RuntimeException("MatrixHandler (page-part path) requires 'fields' option (array).");
        }

        // Empty / null input → no blocks.
        if ($legacyValue === null || $legacyValue === '' || $legacyValue === []) {
            return [];
        }
        if (!is_array($legacyValue)) {
            throw new RuntimeException(
                "MatrixHandler (page-part path) expects \$legacyValue to be a list of pre-resolved row hashes; got " . gettype($legacyValue) . '.'
            );
        }

        $blockType = (string) $options['targetBlockType'];

        // Wrap each pre-resolved row's fields hash in the Craft 5 new-block key shape.
        // Block-array key 'new' . $n preserved verbatim — required by Craft 5
        // setFieldValue() semantics for new-block creation.
        $blocks = [];
        $n = 0;
        foreach ($legacyValue as $row) {
            if (!is_array($row) || !isset($row['fields']) || !is_array($row['fields'])) {
                throw new RuntimeException(
                    "MatrixHandler (page-part path) expects each row to be array{fields: array<string,mixed>}."
                );
            }
            $n++;
            $blocks['new' . $n] = [
                'type' => $blockType,
                'enabled' => true,
                'fields' => $row['fields'],
            ];
        }

        return $blocks;
    }
}
