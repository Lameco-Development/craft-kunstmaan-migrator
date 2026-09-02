<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\RedirectMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 2026-08-24 — the direct kuma_redirects import existed since v1
 * (migrateLegacyTables) and nothing in the v2 pipeline called it: 1,419
 * admin-managed redirects on the Enreach corpus never reached Retour while
 * the class docblock said they would. Lock the wiring: the adapter's own
 * migrateAll() runs the direct import, before the RedirectPage lane, so an
 * environment with no redirect pages still imports its redirect table.
 */
final class RedirectDirectImportWiringTest extends TestCase
{
    public function testMigrateAllRunsTheDirectImport(): void
    {
        $file = (string) (new ReflectionClass(RedirectMigrationService::class))->getFileName();
        $source = (string) file_get_contents($file);

        $migrateAll = strpos($source, 'function migrateAll(');
        $directCall = strpos($source, '$this->importDirectRedirects($context->sites', (int) $migrateAll);
        $compilerCall = strpos($source, 'new RedirectCompiler(', (int) $migrateAll);

        self::assertNotFalse($migrateAll);
        self::assertNotFalse($directCall, 'migrateAll() must run the direct kuma_redirects import');
        self::assertNotFalse($compilerCall);
        self::assertLessThan($compilerCall, $directCall, 'the table import runs before the RedirectPage lane');
    }

    public function testSectionMovesAreGatedAndEmitOnlyOnDifference(): void
    {
        $file = (string) (new ReflectionClass(RedirectMigrationService::class))->getFileName();
        $source = (string) file_get_contents($file);

        // Off until measured per corpus — the adapter setting is the gate.
        self::assertStringContainsString("config()['sectionMoves']", $source);
        // A page whose URL survived byte-for-byte emits nothing.
        self::assertStringContainsString("rtrim(\$oldPath, '/') === rtrim(\$newPath, '/')", $source);
        // A 301 must land on a live page — placeholders are disabled entries.
        self::assertStringContainsString('getEnabledForSite()', $source);
    }

    /**
     * 2026-09-03 — `kuma_node_id` is only unique WITHIN one legacy environment's own
     * database; COM/DE/LV each restart their own numbering. A `kuma_redirects` row
     * compiled for one environment resolved to an unrelated entry a DIFFERENT
     * environment happened to record under the same numeric id — measured: COM's
     * `/en/products/enreach-contact` redirect resolved to LV's own separate
     * "enreach-contact" product page. `resolveEntryIdForLegacyNode()` now tries the
     * environment-scoped `"<ENV>:kuma_nodes"` source first, same fix already applied
     * to `NavigationMigrationService::resolveEntryIdForNode()`. Locks the wiring:
     * `$environment` must reach every hop between `migrateAll()` and the lookup.
     */
    public function testRedirectDestinationResolutionIsScopedToItsOwnEnvironment(): void
    {
        $file = (string) (new ReflectionClass(RedirectMigrationService::class))->getFileName();
        $source = (string) file_get_contents($file);

        self::assertStringContainsString(
            'importDirectRedirects($context->sites, $context->name',
            $source,
            'migrateAll() must pass the environment name into the direct-import chain',
        );
        self::assertStringContainsString(
            "sprintf('%s:kuma_nodes', \$environment)",
            $source,
            'resolveEntryIdForLegacyNode() must try the environment-scoped source before the unscoped legacy-compat ones',
        );

        // The environment-scoped lookup must run BEFORE the unscoped fallback loop,
        // not after — an unscoped match would otherwise win on a coincidental id
        // collision even when the correct, scoped entry also exists.
        $methodStart = strpos($source, 'private function resolveEntryIdForLegacyNode(');
        self::assertNotFalse($methodStart);
        $scopedLookup = strpos($source, "sprintf('%s:kuma_nodes', \$environment)", $methodStart);
        $unscopedLoop = strpos($source, 'array_push($candidateSources,', $methodStart);
        self::assertNotFalse($scopedLookup);
        self::assertNotFalse($unscopedLoop);
        self::assertLessThan($unscopedLoop, $scopedLookup, 'the environment-scoped lookup must be tried first');
    }
}
