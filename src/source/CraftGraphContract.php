<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

final class CraftGraphContract
{
    public const GRAPH_VERSION = 'craft-entry-graph-v1';

    public const KEY_GRAPH_VERSION = 'graphVersion';
    public const KEY_ROOTS = 'roots';
    public const KEY_ENTRY_TYPES = 'entryTypes';
    public const KEY_FIELDS = 'fields';
    public const KEY_MATRIX_BLOCK_TYPES = 'matrixBlockTypes';
    public const KEY_MATRIX_USAGES = 'matrixUsages';
    public const KEY_RELATION_TARGETS = 'relationTargets';
    public const KEY_ASSET_VOLUMES = 'assetVolumes';
    public const KEY_CONSTRAINTS = 'constraints';

    public static function craftEntryTypeRef(string $handle): string
    {
        return 'craft.entryType:' . $handle;
    }

    public static function craftFieldRef(string $entryTypeHandle, string $fieldHandle): string
    {
        return 'craft.field:' . $entryTypeHandle . '.' . $fieldHandle;
    }

    public static function matrixBlockRef(string $matrixFieldHandle, string $blockEntryTypeHandle): string
    {
        return 'craft.matrixBlock:' . $matrixFieldHandle . ':' . $blockEntryTypeHandle;
    }
}
