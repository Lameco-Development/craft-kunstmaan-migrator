<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\MappingDocument;
use Lameco\KumaCompile\Target\CraftSchema;
use Lameco\KumaCompile\Target\SpecNotes;
use Lameco\KumaCompile\Target\Suggester;
use lameco\kunstmaanmigrator\mapping\FieldExpression;
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
    private const EDITABLE_LANES = ['parts', 'pages', 'entities', 'sidecars'];

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

        // The prefill offer: only on the parts lane, only while rows still lack
        // a target, and only when a content-model spec directory is there to
        // draft from — a fresh mapping is exactly when all three hold.
        $undecided = $lane === 'parts'
            ? count(array_filter($rows, static fn ($row): bool => $row->target === null && $row->dropped === null))
            : 0;
        $specsPath = self::specsPath();

        return $this->renderTemplate('kunstmaan-migrator/_mapping', [
            'lane' => $lane,
            'lanes' => self::EDITABLE_LANES,
            'rows' => $rows,
            'progress' => $error === null ? $editor->progress($lane) : null,
            'path' => $editor->path(),
            'error' => $error,
            'prefill' => $error === null && $undecided > 0 && $specsPath !== null
                ? ['undecided' => $undecided, 'specsPath' => $specsPath]
                : null,
        ]);
    }

    /**
     * Draft every undecided part from the content model's own migration notes.
     *
     * The specs already say which parts each block covers and which property
     * becomes which field; this writes those drafts into the mapping. A draft
     * is not a decision — every drafted row keeps its leftover columns as
     * `unreviewed`, so it stays open until somebody reviews it.
     */
    public function actionPrefill(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
        $path = $editor->path();
        $specsPath = self::specsPath();

        if ($path === null || $specsPath === null) {
            Craft::$app->getSession()->setError(Craft::t('kunstmaan-migrator', 'No mapping or no content-model specs to draft from.'));

            return $this->redirect('kunstmaan-migrator/mapping');
        }

        try {
            $mapping = Mapping::fromFile($path);
            $envName = (string) array_key_first($mapping->environments());
            $database = (string) ($mapping->environments()[$envName]['database'] ?? '');
            $db = LegacyDatabase::connect($envName, $database, $editor->legacyDsn());

            $suggester = new Suggester(
                SpecNotes::fromDirectory($specsPath),
                CraftSchema::fromProjectConfig((string) Craft::getAlias('@root')),
            );
            $result = $suggester->prefill($mapping, $db);

            $document = MappingDocument::fromFile($path);

            foreach ($result['drafted'] as $part => $patch) {
                $document = $document->patch('parts', (string) $part, $patch);
            }

            $document->save();
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirect('kunstmaan-migrator/mapping');
        }

        Craft::$app->getSession()->setNotice(Craft::t(
            'kunstmaan-migrator',
            '{n, plural, =0{Nothing to draft — no spec names the open parts.} =1{1 part drafted from the content model.} other{# parts drafted from the content model.}} {skipped, plural, =0{} other{# stayed open with no draft.}} Every drafted row stays open until you review it.',
            ['n' => count($result['drafted']), 'skipped' => count($result['skipped'])],
        ));

        return $this->redirect('kunstmaan-migrator/mapping');
    }

    /**
     * The content-model spec directory: the plugin setting when set, otherwise
     * `docs/content-model/page-builder` under the project root — where the
     * scaffolded content model keeps its block specs.
     */
    private static function specsPath(): ?string
    {
        $configured = trim((string) App::parseEnv((string) (Plugin::getInstance()->getSettings()->specsPath ?? '')));

        if ($configured !== '') {
            return is_dir($configured) ? $configured : null;
        }

        $default = Craft::getAlias('@root') . '/docs/content-model/page-builder';

        return is_dir($default) ? $default : null;
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

        // Each Craft field's current expression, split into the parts somebody
        // can choose from — which column, and what to do to it. A sidecar has no
        // target of its own — it decorates whichever page carries a row — so its
        // field list is the union of what the page entry types offer.
        $fields = match (true) {
            $lane === 'sidecars'   => $editor->pageFields(),
            $row->target !== null  => $editor->fieldsFor($row->target),
            default                => [],
        };
        $expressions = [];

        foreach ($fields as $field) {
            $expressions[$field] = FieldExpression::parse((string) ($row->map[$field] ?? ''));
        }

        return $this->renderTemplate('kunstmaan-migrator/_mapping-row', [
            'lane' => $lane,
            'row' => $row,
            'targetOptions' => $editor->targetOptions($lane),
            'fields' => $fields,
            'expressions' => $expressions,
            'columns' => $editor->columnsFor($row),
            'transforms' => $editor->transforms(),
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
            $changes = ['drop' => $drop];

            if (($key = $this->targetKey($lane)) !== null) {
                $changes[$key] = null;
            }

            return $changes;
        }

        $target = trim((string) $this->request->getBodyParam('target', ''));

        // `map` is Craft field => legacy expression, which is the DSL's own
        // direction: one field can consume several columns through an
        // expression, and the other direction cannot say that. An empty box
        // means the field is not filled, so the key goes away rather than
        // being written as an empty expression that evaluates to nothing.
        $map = [];

        foreach ((array) $this->request->getBodyParam('map', []) as $field => $parts) {
            $parts = (array) $parts;
            $expression = FieldExpression::compose(
                (string) ($parts['column'] ?? ''),
                (string) ($parts['transform'] ?? ''),
                (string) ($parts['advanced'] ?? ''),
            );

            if ($expression !== '') {
                $map[(string) $field] = $expression;
            }
        }

        $ignore = [];
        $unreviewed = [];

        foreach ((array) $this->request->getBodyParam('columns', []) as $column => $decision) {
            $column = (string) $column;

            if ((string) ($decision['disposition'] ?? 'unreviewed') !== 'ignore') {
                $unreviewed[] = $column;

                continue;
            }

            // A reason left empty stays empty. Writing "not migrated" in its
            // place would invent a rationale nobody gave, and the value of an
            // `ignore:` entry is precisely that somebody wrote one.
            $reason = trim((string) ($decision['reason'] ?? ''));
            $ignore[$column] = $reason !== '' ? $reason : null;
        }

        $changes = [
            'ignore' => self::ignoreValue($ignore),
            'unreviewed' => $unreviewed !== [] ? $unreviewed : null,
            'drop' => null,
        ];

        if (($targetKey = $this->targetKey($lane)) !== null) {
            $changes[$targetKey] = $target !== '' ? $target : null;
        }

        // The field map is only drawn once a block is chosen, so a save from
        // the screen that has not drawn it must not wipe what is there.
        if ($this->request->getBodyParam('map') !== null) {
            $changes['map'] = $map !== [] ? $map : null;
        }

        return $changes;
    }

    /**
     * `ignore:` in the shape the file already uses.
     *
     * The DSL takes both a list of columns and a map of column to reason. A row
     * where nobody has given a reason stays a list rather than becoming a map
     * of nulls — otherwise opening a screen and pressing Save rewrites parts of
     * the mapping that nobody edited, and a diff full of those is a diff that
     * hides the one line somebody meant.
     *
     * @param array<string, ?string> $ignore
     * @return list<string>|array<string, string>|null
     */
    private static function ignoreValue(array $ignore): array|null
    {
        if ($ignore === []) {
            return null;
        }

        $withReasons = array_filter($ignore, static fn (?string $reason): bool => $reason !== null);

        if ($withReasons === []) {
            return array_keys($ignore);
        }

        return array_map(static fn (?string $reason): string => $reason ?? '', $ignore);
    }

    /** Each lane names its target differently; the row does not have to know. */
    private function targetKey(string $lane): ?string
    {
        return match ($lane) {
            'pages' => 'entryType',
            // A sidecar has no target handle: its map addresses page fields
            // directly, on whichever page the polymorphic ref decorates.
            'sidecars' => null,
            default => 'block',
        };
    }

    /**
     * The section's landing: the mapping when one exists, the wizard when
     * none does — the screen you need is the screen you get.
     */
    public function actionHome(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());

        return $this->redirect($editor->path() !== null ? 'kunstmaan-migrator/mapping' : 'kunstmaan-migrator/setup');
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

        // What was queued, in words. "Queued LV, fixup, finalize." leaks two
        // internal pass names at the one moment somebody is watching.
        return $this->asJson([
            'ok' => true,
            'queued' => $queued,
            'message' => Craft::t('kunstmaan-migrator', '{n, plural, =1{Started. 1 job queued.} other{Started. # jobs queued.}}', [
                'n' => count($queued),
            ]),
        ]);
    }
}
