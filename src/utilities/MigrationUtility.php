<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\App;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use lameco\kunstmaanmigrator\run\MappingPreflight;
use lameco\kunstmaanmigrator\run\PdoPreflightProbe;
use Throwable;

/**
 * Where a migration is started and watched.
 *
 * A Utility rather than a top-level section: this is a developer tool used a
 * handful of times per project, and it has no business in the nav beside
 * Entries. Utilities is exactly the drawer Craft keeps such things in.
 */
final class MigrationUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('kunstmaan-migrator', 'Kunstmaan Migration');
    }

    public static function id(): string
    {
        return 'kunstmaan-migration';
    }

    public static function icon(): ?string
    {
        return 'right-left';
    }

    public static function contentHtml(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $path = App::parseEnv($settings->mappingPath);
        $environments = [];
        $checks = [];
        $error = null;
        $notSetUp = false;

        // "Not set up yet" is not an error, and showing it as one is why the
        // first thing a new operator saw was a warning box. It is the normal
        // state of a fresh install, and what it needs is an invitation.
        if (!is_string($path) || $path === '' || !is_file($path)) {
            $notSetUp = true;
        } else {
            try {
                $environments = Mapping::fromFile($path)->environments();
                $checks = (new MappingPreflight(
                    new PdoPreflightProbe(EnvironmentPipeline::dsnFromSettings()),
                    static fn (string $path): string => (string) App::parseEnv($path),
                ))
                    ->inspect($environments, Craft::$app->getSites()->getAllSites());
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return Craft::$app->getView()->renderTemplate('kunstmaan-migrator/_utility', [
            'mappingPath' => $path,
            'checks' => $checks,
            'error' => $error,
            'notSetUp' => $notSetUp,
            'isProduction' => App::env('CRAFT_ENVIRONMENT') === 'production',
        ]);
    }
}
