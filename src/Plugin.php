<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator;

use craft\base\Plugin as BasePlugin;

/**
 * Kunstmaan → Craft Migrator plugin entrypoint.
 *
 * Phase 1 / Plan 01 ships the class skeleton (properties only).
 * Plan 02 wires the legacyDb Yii component, controllerNamespace switch,
 * createSettingsModel(), and settingsHtml() once Settings + LegacyDbService land.
 */
class Plugin extends BasePlugin
{
    // D-08: v2 declares schemaVersion 1.0.0 (NOT v1.x's 2.0.0).
    // On v1.x→v2 swap-in hosts the declared version is below the installed version,
    // which is fine because Install.php's `tableExists` guard makes re-runs safe.
    public string $schemaVersion = '1.0.0';

    // D-16: enables the CP Settings page. The placeholder template ships in Plan 02;
    // the real form lives in Phase 4 / CFG-01.
    public bool $hasCpSettings = true;
}
