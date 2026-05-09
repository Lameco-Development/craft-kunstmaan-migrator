<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\AssetMigrationService;
use lameco\kunstmaanmigrator\models\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 2026-05-09 — Lock the contract for `Settings::$skipAssetSizeValidation`
 * and the corresponding bypass branch in AssetMigrationService::ingestRow().
 *
 * Use case: Lameco's craft-starter-kit ships a per-extension size cap
 * (modules/lameco/Module.php — 10MB for PDFs). The cap is appropriate for
 * editor uploads but rejects valid pre-existing assets during migration.
 * Surfaced by deklerk's >10MB PDF.
 *
 * Direct end-to-end of the catch branch needs a Craft bootstrap + a real
 * EVENT_BEFORE_SAVE listener; that lands in the integration suite. Here
 * we lock the source-level contract so a future refactor can't silently
 * remove the bypass surface or change the message-string match.
 */
final class AssetMigrationServiceSizeBypassTest extends TestCase
{
    public function testSettingsExposesBypassFlagDefaultingFalse(): void
    {
        // Reflection avoids constructing Settings (which boots Craft's Yii
        // chain — needs a container that unit tests don't provide).
        $rc = new ReflectionClass(Settings::class);
        self::assertTrue($rc->hasProperty('skipAssetSizeValidation'));

        $defaults = $rc->getDefaultProperties();
        self::assertArrayHasKey('skipAssetSizeValidation', $defaults);
        self::assertFalse($defaults['skipAssetSizeValidation']);
    }

    public function testAssetMigrationServiceCarriesBypassFlagDefaultingFalse(): void
    {
        $service = new AssetMigrationService();
        self::assertFalse($service->skipAssetSizeValidation);
    }

    public function testBypassBranchMatchesStarterKitExceptionMessageHead(): void
    {
        // Lock the message-prefix contract — the starter-kit at
        // modules/lameco/Module.php throws HttpException(400, "The file is
        // too large for $extension files. Maximum allowed size: ...").
        // Our str_starts_with check must match that prefix verbatim.
        $file = (string) (new ReflectionClass(AssetMigrationService::class))->getFileName();
        $source = (string) file_get_contents($file);

        self::assertStringContainsString('skipAssetSizeValidation', $source);
        self::assertStringContainsString("'The file is too large'", $source);
        self::assertStringContainsString('$e->statusCode === 400', $source);
        self::assertStringContainsString('catch (\\yii\\web\\HttpException $e)', $source);
    }

    public function testBypassFlagPropagatesViaPluginInit(): void
    {
        // Plugin::init wires Settings::$skipAssetSizeValidation onto the
        // AssetMigrationService instance. Lock the wire-up so a future
        // refactor doesn't silently drop the connection (which would be
        // a no-op-with-config — the worst kind of regression).
        $file = dirname((string) (new ReflectionClass(AssetMigrationService::class))->getFileName(), 2)
            . '/Plugin.php';
        $source = (string) file_get_contents($file);

        self::assertStringContainsString(
            '$this->assetMigrationService->skipAssetSizeValidation',
            $source,
        );
        self::assertStringContainsString(
            'getSettings()->skipAssetSizeValidation',
            $source,
        );
    }
}
