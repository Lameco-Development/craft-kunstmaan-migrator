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
}
