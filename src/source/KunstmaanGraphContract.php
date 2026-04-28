<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

final class KunstmaanGraphContract
{
    public const GRAPH_VERSION = 'kunstmaan-page-graph-v1';

    public const KEY_GRAPH_VERSION = 'graphVersion';
    public const KEY_ROOTS = 'roots';
    public const KEY_ENTITIES = 'entities';
    public const KEY_RELATIONS = 'relations';
    public const KEY_PAGEPARTS = 'pageparts';
    public const KEY_PAGEPART_USAGES = 'pagepartUsages';
    public const KEY_ASSETS = 'assets';
    public const KEY_TABLES = 'tables';
    public const KEY_SAMPLES = 'samples';
    public const KEY_CONSTRAINTS = 'constraints';

    public const INTENT_REFERENCE = 'reference';
    public const INTENT_PROMOTE = 'promote';
    public const INTENT_EMBED = 'embed';
    public const INTENT_DROP = 'drop';
    public const INTENT_OUT_OF_SCOPE = 'out_of_scope';

    public static function pageRootRef(string $fqcn): string
    {
        return 'kunstmaan.page:' . ltrim($fqcn, '\\');
    }

    public static function entityRef(string $fqcn): string
    {
        return 'kunstmaan.entity:' . ltrim($fqcn, '\\');
    }

    public static function pagepartRef(string $fqcn): string
    {
        return 'kunstmaan.pagepart:' . ltrim($fqcn, '\\');
    }
}
