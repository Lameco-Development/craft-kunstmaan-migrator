<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\console\StateController;
use lameco\kunstmaanmigrator\queue\FinalizeJob;
use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
use lameco\kunstmaanmigrator\queue\ResolveDeferredRefsJob;
use lameco\kunstmaanmigrator\run\Diagnostics;
use lameco\kunstmaanmigrator\utilities\MigrationUtility;
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
    /**
     * The `doctor` checks, in the browser.
     *
     * Synchronous because they are: six reads that answer in milliseconds.
     * Queueing something that fast would only put a page refresh between the
     * question and the answer.
     */
    public function actionDoctor(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $checks = (new Diagnostics())->run();

        return $this->asJson([
            'ok' => Diagnostics::allPassed($checks),
            'checks' => $checks,
        ]);
    }

    /**
     * The state table as NDJSON, the same rows `state/export` writes.
     *
     * A download rather than a JSON body: this is the file people diff between
     * runs, and asking them to copy it out of a browser console is how it stops
     * being used.
     */
    public function actionExport(): Response
    {
        $this->requirePermission('utility:' . MigrationUtility::id());

        $lines = [];

        foreach (StateController::buildExportRows(Plugin::getInstance()->migrationStateService) as $row) {
            $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES);
        }

        return Craft::$app->getResponse()->sendContentAsFile(
            implode(PHP_EOL, $lines) . PHP_EOL,
            'kunstmaan-migration-state.ndjson',
            ['mimeType' => 'application/x-ndjson'],
        );
    }

    public function actionQueue(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:' . MigrationUtility::id());

        // Refused here as well as in the job. This is the button, so this is
        // where an operator should be told, rather than watching a job fail.
        if (ProductionGuard::isProduction()) {
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
        $pass = (string) ($request->getBodyParam('pass') ?: 'full');

        // The two passes that run once for the whole corpus rather than per
        // environment: both resolve references that cannot exist until every
        // environment has been written.
        if ($pass === 'fixup') {
            Craft::$app->getQueue()->push(new ResolveDeferredRefsJob());

            return $this->asJson(['ok' => true, 'queued' => ['fixup'], 'message' => 'Queued reference resolution.']);
        }

        if ($pass === 'finalize') {
            Craft::$app->getQueue()->push(new FinalizeJob(['mappingPath' => $path, 'dryRun' => $dryRun]));

            return $this->asJson(['ok' => true, 'queued' => ['finalize'], 'message' => 'Queued finalize.']);
        }

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
                'entriesOnly' => $pass === 'entries',
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
