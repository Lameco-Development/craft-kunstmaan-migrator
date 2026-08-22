<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use Lameco\KumaCompile\Mapping\Mapping;
use lameco\kunstmaanmigrator\mapping\MappingEditor;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\console\StateController;
use lameco\kunstmaanmigrator\queue\FinalizeJob;
use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
use lameco\kunstmaanmigrator\queue\ResolveDeferredRefsJob;
use lameco\kunstmaanmigrator\run\Diagnostics;
use lameco\kunstmaanmigrator\utilities\MigrationUtility;
use Throwable;
use yii\web\BadRequestHttpException;
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
     * The lanes whose rows are a decision somebody makes.
     *
     * `environments` is deliberately not here — it is topology, and an ordered
     * media-root fallback chain and a locale marked "not migrated, and here is
     * why" are things a YAML file states better than a form.
     */
    private const EDITABLE_LANES = ['parts', 'pages', 'entities'];

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

    /**
     * The mapping, row by row.
     *
     * A mapping is a long file of decisions, and the two things that make
     * deciding hard are both things a file cannot do: tell you where the
     * content actually is, and tell you what you are allowed to say. This is
     * the screen that does both.
     */
    public function actionMapping(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $lane = (string) ($this->request->getQueryParam('lane') ?: 'parts');

        if (!in_array($lane, self::EDITABLE_LANES, true)) {
            throw new BadRequestHttpException(sprintf('There is no "%s" lane to edit.', $lane));
        }

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());

        try {
            $rows = $editor->rows($lane);
            $error = null;
        } catch (Throwable $e) {
            $rows = [];
            $error = $e->getMessage();
        }

        return $this->renderTemplate('kunstmaan-migrator/_mapping', [
            'lane' => $lane,
            'lanes' => self::EDITABLE_LANES,
            'rows' => $rows,
            'path' => $editor->path(),
            'error' => $error,
        ]);
    }

    /**
     * One row's decision: which Craft block, and where each legacy column goes.
     */
    public function actionMappingRow(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $lane = (string) $this->request->getRequiredQueryParam('lane');
        $key = (string) $this->request->getRequiredQueryParam('key');

        if (!in_array($lane, self::EDITABLE_LANES, true)) {
            throw new BadRequestHttpException(sprintf('There is no "%s" lane to edit.', $lane));
        }

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
        $row = $editor->row($lane, $key);

        if ($row === null) {
            throw new BadRequestHttpException(sprintf('The %s lane does not name "%s".', $lane, $key));
        }

        return $this->renderTemplate('kunstmaan-migrator/_mapping-row', [
            'lane' => $lane,
            'row' => $row,
            'blocks' => $lane === 'parts' ? $editor->availableBlocks() : [],
            'fields' => $row->target !== null ? $editor->fieldsFor($row->target) : [],
        ]);
    }

    /**
     * Write one row's decision back to the mapping file.
     *
     * To the file, because the mapping is reviewed in a pull request and an
     * edit that did not show up in the diff would not be reviewed.
     */
    public function actionSaveMappingRow(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $lane = (string) $this->request->getRequiredBodyParam('lane');
        $key = (string) $this->request->getRequiredBodyParam('key');

        if (!in_array($lane, self::EDITABLE_LANES, true)) {
            throw new BadRequestHttpException(sprintf('There is no "%s" lane to edit.', $lane));
        }

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());

        try {
            $editor->patch($lane, $key, $this->changesFromRequest($lane));
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('kunstmaan-migrator', 'Mapping updated.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * The posted form as a patch.
     *
     * A patch rather than the whole row: the form shows a handful of a row's
     * keys and a row carries more — `live`, `children`, `switch` — so posting
     * the row back would drop everything the form does not draw.
     *
     * @return array<string, mixed>
     */
    private function changesFromRequest(string $lane): array
    {
        $drop = trim((string) $this->request->getBodyParam('drop', ''));

        if ($drop !== '') {
            // A dropped row keeps its columns: the decision is "this does not
            // migrate", not "we never knew what was in it", and someone
            // revisiting it should still see what they were giving up.
            return ['drop' => $drop, $this->targetKey($lane) => null];
        }

        $target = trim((string) $this->request->getBodyParam('target', ''));
        $map = [];
        $ignore = [];
        $unreviewed = [];

        foreach ((array) $this->request->getBodyParam('columns', []) as $column => $decision) {
            $column = (string) $column;
            $disposition = (string) ($decision['disposition'] ?? 'unreviewed');
            $value = trim((string) ($decision['value'] ?? ''));

            match ($disposition) {
                // An empty target on a mapped column means "same name", which is
                // what the DSL already means by a bare column expression.
                'map' => $map[$column] = $value !== '' ? $value : $column,
                'ignore' => $ignore[$column] = $value !== '' ? $value : 'not migrated',
                default => $unreviewed[] = $column,
            };
        }

        return [
            $this->targetKey($lane) => $target !== '' ? $target : null,
            'map' => $map !== [] ? $map : null,
            'ignore' => $ignore !== [] ? $ignore : null,
            'unreviewed' => $unreviewed !== [] ? $unreviewed : null,
            'drop' => null,
        ];
    }

    /** Each lane names its target differently; the row does not have to know. */
    private function targetKey(string $lane): string
    {
        return match ($lane) {
            'pages' => 'entryType',
            default => 'block',
        };
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
                'message' => Craft::t('kunstmaan-migrator', 'Refusing to migrate against CRAFT_ENVIRONMENT=production.'),
            ]);
        }

        $settings = Plugin::getInstance()->getSettings();
        $path = (string) App::parseEnv($settings->mappingPath);

        if ($path === '' || !is_file($path)) {
            return $this->asJson([
                'ok' => false,
                'message' => Craft::t('kunstmaan-migrator', 'Set a mapping file in the plugin settings first.'),
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
                'message' => Craft::t('kunstmaan-migrator', 'The mapping declares no such environment.'),
            ]);
        }

        // The two corpus-wide passes, chained onto the same queue rather than left
        // for the operator to remember. `migrate` runs both at the end of an inline
        // run, so a queued "full" that stopped after the environments was not the
        // same migration under a different name — it left every deferred reference
        // dangling and every `[NT<id>]` unrewritten, with nothing saying so. The
        // queue is FIFO, which is the ordering both passes need: they resolve
        // against entries that must already exist.
        if ($pass === 'full') {
            Craft::$app->getQueue()->push(new ResolveDeferredRefsJob());
            Craft::$app->getQueue()->push(new FinalizeJob(['mappingPath' => $path, 'dryRun' => $dryRun]));
            $queued[] = 'fixup';
            $queued[] = 'finalize';
        }

        return $this->asJson([
            'ok' => true,
            'queued' => $queued,
            'message' => sprintf('Queued %s.', implode(', ', $queued)),
        ]);
    }
}
