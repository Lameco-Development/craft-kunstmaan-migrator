<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use Craft;
use craft\helpers\App;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
use Throwable;

/**
 * What the run screen needs to know before offering the button: the mapping,
 * its environments, and whether each one's database and media root are
 * actually reachable from here.
 *
 * Its own class rather than controller code because a migration is hours and
 * a web request is seconds — the controller stays a dispatcher, and the test
 * that keeps EnvironmentPipeline out of it keeps meaning something.
 */
final class RunPanel
{
    /** @return array<string, mixed> */
    public static function data(): array
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
                    static fn(string $path): string => (string) App::parseEnv($path),
                ))
                    ->inspect($environments, Craft::$app->getSites()->getAllSites());
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return [
            'mappingPath' => $path,
            'checks' => $checks,
            'error' => $error,
            'notSetUp' => $notSetUp,
            'isProduction' => ProductionGuard::isProduction(),
        ];
    }
}
