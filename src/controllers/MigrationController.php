<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
use Throwable;
use yii\web\Response;

/**
 * The utility's one write: put a migration on the queue.
 *
 * The run itself belongs to the queue, not to a web request — a migration is
 * hours and a request is seconds.
 */
final class MigrationController extends Controller
{
    public function actionQueue(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:kunstmaan-migration');

        // Refused here as well as in the job. This is the button, so this is
        // where an operator should be told, rather than watching a job fail.
        if (App::env('CRAFT_ENVIRONMENT') === 'production') {
            return $this->asJson([
                'ok' => false,
                'message' => Craft::t('app', 'Refusing to migrate against CRAFT_ENVIRONMENT=production.'),
            ]);
        }

        $settings = Plugin::getInstance()->getSettings();
        $path = (string) App::parseEnv($settings->mappingPath);

        if ($path === '' || !is_file($path)) {
            return $this->asJson([
                'ok' => false,
                'message' => Craft::t('app', 'Set a mapping file in the plugin settings first.'),
            ]);
        }

        $request = Craft::$app->getRequest();
        $only = $request->getBodyParam('environment');
        $dryRun = (bool) $request->getBodyParam('dryRun');
        $force = (bool) $request->getBodyParam('force');

        try {
            $environments = Mapping::fromFile($path)->environments();
        } catch (Throwable $e) {
            return $this->asJson(['ok' => false, 'message' => $e->getMessage()]);
        }

        $queued = [];

        foreach (array_keys($environments) as $environment) {
            if (is_string($only) && $only !== '' && $only !== $environment) {
                continue;
            }

            Craft::$app->getQueue()->push(new MigrateEnvironmentJob([
                'mappingPath' => $path,
                'environment' => (string) $environment,
                'dryRun' => $dryRun,
                'force' => $force,
            ]));

            $queued[] = (string) $environment;
        }

        if ($queued === []) {
            return $this->asJson([
                'ok' => false,
                'message' => Craft::t('app', 'The mapping declares no such environment.'),
            ]);
        }

        return $this->asJson([
            'ok' => true,
            'queued' => $queued,
            'message' => sprintf('Queued %s.', implode(', ', $queued)),
        ]);
    }
}
