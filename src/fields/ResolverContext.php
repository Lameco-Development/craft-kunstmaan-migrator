<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields;

use lameco\kunstmaanmigrator\load\MigrationStateReader;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\db\LegacyDbService;

/**
 * Immutable per-call context passed to every FieldHandler::resolve().
 *
 * Carries:
 *  - target site identity (siteId + siteHandle)
 *  - read-only state reader (D-11 — handlers resolve legacy → Craft ids here)
 *  - CKEditor rewriter (for CKEditor + Matrix-of-CKEditor cases)
 *  - asset path resolver (for handlers that materialise file refs)
 *  - siteMap (legacy-locale → Craft siteId) for cross-site lookups
 *  - optional LegacyDbService for handlers that need sibling-row reads
 *    (MatrixHandler streams child rows via this)
 *
 * All properties are readonly — constructing a new context is the only
 * way to target a different site or swap dependencies during a run.
 */
final class ResolverContext
{
    /**
     * @param array<string, int> $siteMap kuma_locale ('nl','en',...) → Craft siteId
     */
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteHandle,
        public readonly MigrationStateReader $state,
        public readonly CkeditorRewriterService $ck,
        public readonly AssetPathResolver $paths,
        public readonly array $siteMap,
        public readonly ?LegacyDbService $legacyDb = null,
    ) {
    }
}
