<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\Queue as QueueHelper;
use craft\web\Controller;
use Lameco\Kunstmaanmigrator\console\StateController;
use Lameco\Kunstmaanmigrator\craft\CraftSchemaGateway;
use Lameco\Kunstmaanmigrator\craft\TargetModel;
use Lameco\Kunstmaanmigrator\editor\MappingEditor;
use Lameco\Kunstmaanmigrator\Mapping\FieldExpression;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\MappingCheck;
use Lameco\Kunstmaanmigrator\Mapping\MappingDocument;
use Lameco\Kunstmaanmigrator\Mapping\MappingRow;
use Lameco\Kunstmaanmigrator\Plugin;
use Lameco\Kunstmaanmigrator\queue\FinalizeJob;
use Lameco\Kunstmaanmigrator\queue\MigrateEnvironmentJob;
use Lameco\Kunstmaanmigrator\queue\RecomputeStructureUrisJob;
use Lameco\Kunstmaanmigrator\queue\ResolveDeferredRefsJob;
use Lameco\Kunstmaanmigrator\run\Diagnostics;
use Lameco\Kunstmaanmigrator\run\RunPanel;
use Lameco\Kunstmaanmigrator\safety\ProductionGuard;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use Lameco\Kunstmaanmigrator\Target\SpecNotes;
use Lameco\Kunstmaanmigrator\Target\Suggester;
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
    private const EDITABLE_LANES = MappingEditor::LANES;

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
        $this->requirePermission(Plugin::PERMISSION);

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
        $this->requirePermission(Plugin::PERMISSION);

        $export = StateController::buildExportRows(Plugin::getInstance()->migrationStateService);
        $lines = array_map(
            static fn(array $row): string => (string) json_encode($row, JSON_UNESCAPED_SLASHES),
            $export->rows,
        );

        if (($warning = $export->warning()) !== null) {
            Craft::warning($warning, 'kunstmaan-migrator');
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
        $this->requirePermission(Plugin::PERMISSION);

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
            ? count(array_filter($rows, static fn($row): bool => $row->target === null && $row->dropped === null))
            : 0;
        $specsPath = self::specsPath();

        // What each tab owes: the open count is the only number that makes
        // "which lane next" answerable without clicking through all four.
        // One progress walk per lane feeds both the tab badges and the bar.
        $progress = [];

        if ($error === null) {
            foreach (self::EDITABLE_LANES as $name) {
                try {
                    $progress[$name] = $editor->progress($name);
                } catch (Throwable) {
                    $progress[$name] = null;
                }
            }
        }

        return $this->renderTemplate('kunstmaan-migrator/_mapping', [
            'lane' => $lane,
            'lanes' => self::EDITABLE_LANES,
            'open' => array_map(static fn(?array $p): ?int => $p['open'] ?? null, $progress),
            'rows' => $rows,
            'progress' => $progress[$lane] ?? null,
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
        $this->requirePermission(Plugin::PERMISSION);

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
        $this->requirePermission(Plugin::PERMISSION);

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
            ...self::fieldMapVariables($editor, $lane, $row, $row->target),
            'targetOptions' => $editor->targetOptions($lane),
            'samples' => $editor->samplesFor($row),
        ]);
    }

    /**
     * The `_mapping-row-fields` variable set for one row and one (possibly
     * hypothetical) target — shared by the saved row screen and the live
     * field-map swap, because the preview must render exactly what a save
     * would show.
     *
     * A sidecar has no target of its own — it decorates whichever page carries
     * a row — so its field list is the union of what the page entry types
     * offer, its own mapped fields first: the union across thirty entry types
     * is a long list of strangers to drown in.
     *
     * @return array<string, mixed>
     */
    private static function fieldMapVariables(MappingEditor $editor, string $lane, MappingRow $row, ?string $target): array
    {
        $fields = match (true) {
            $lane === 'sidecars' => $editor->pageFields(),
            $target !== null && $target !== '' => $editor->fieldsFor($target),
            default => [],
        };

        if ($lane === 'sidecars') {
            $mapped = array_keys($row->map);
            usort($fields, static fn(string $a, string $b): int =>
                [!in_array($a, $mapped, true), $a] <=> [!in_array($b, $mapped, true), $b]);
        }

        $expressions = [];

        foreach ($fields as $field) {
            $expressions[$field] = FieldExpression::parse((string) ($row->map[$field] ?? ''));
        }

        return [
            'lane' => $lane,
            'row' => $row,
            'fields' => $fields,
            'expressions' => $expressions,
            'columns' => $editor->columnsFor($row),
            'transforms' => $editor->transforms(),
            'sidecarFills' => $lane === 'pages' && $target !== null && $target !== ''
                ? $editor->sidecarFillsFor($target)
                : [],
            'carriage' => $lane === 'sidecars' ? $editor->sidecarCarriage($row) : [],
        ];
    }

    /**
     * The inverse of the mapping: for one entry type, every field and what
     * feeds it. The lanes answer "what does this legacy thing become"; the
     * operator verifies with "is every field of contentPage fed" — this is
     * the screen for the second question.
     */
    public function actionCoverage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION);

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
        $targets = $editor->coverageTargets();

        // Old links said only ?entryType= — they keep meaning the page kind.
        $kind = (string) $this->request->getQueryParam('kind', 'page');
        $handle = (string) ($this->request->getQueryParam('handle')
            ?? $this->request->getQueryParam('entryType', $targets[0]['handle'] ?? ''));

        if (!in_array(['handle' => $handle, 'kind' => $kind], $targets, true)) {
            [$handle, $kind] = [$targets[0]['handle'] ?? '', $targets[0]['kind'] ?? 'page'];
        }

        return $this->renderTemplate('kunstmaan-migrator/_coverage', [
            'gaps' => $editor->coverageGaps(),
            'targets' => $targets,
            'kind' => $kind,
            'handle' => $handle,
            'coverage' => $handle !== ''
                ? $editor->coverageFor($kind, $handle)
                : ['kind' => $kind, 'receives' => [], 'fields' => []],
        ]);
    }

    /**
     * One entry type's coverage table, for the picker's live swap.
     */
    public function actionCoverageTable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION);

        $kind = (string) $this->request->getRequiredBodyParam('kind');
        $handle = (string) $this->request->getRequiredBodyParam('handle');
        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());

        if (!in_array(['handle' => $handle, 'kind' => $kind], $editor->coverageTargets(), true)) {
            throw new BadRequestHttpException(sprintf('The mapping has no %s target "%s".', $kind, $handle));
        }

        return $this->asJson([
            'html' => Craft::$app->getView()->renderTemplate('kunstmaan-migrator/_coverage-table', [
                'coverage' => $editor->coverageFor($kind, $handle),
            ]),
        ]);
    }

    /**
     * The field map for a hypothetical target, redrawn live.
     *
     * "Save to see its fields" made choosing a Becomes a two-step guess; the
     * dropdown now asks this action and swaps the map in place. Nothing is
     * written — the row is read from the file as it is, only the target is
     * taken from the request — so browsing targets costs nothing.
     */
    public function actionFieldMap(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION);

        $lane = (string) $this->request->getRequiredBodyParam('lane');
        $key = (string) $this->request->getRequiredBodyParam('key');
        $target = trim((string) $this->request->getBodyParam('target', ''));

        if (!in_array($lane, self::EDITABLE_LANES, true)) {
            throw new BadRequestHttpException(sprintf('There is no "%s" lane to edit.', $lane));
        }

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
        $row = $editor->row($lane, $key);

        if ($row === null) {
            throw new BadRequestHttpException(sprintf('The %s lane does not name "%s".', $lane, $key));
        }

        // The partial's own {% js %} (selectize init) lands in a buffer and
        // travels with the HTML — swapped-in markup is otherwise dead.
        $view = Craft::$app->getView();
        $view->startJsBuffer();
        $html = $view->renderTemplate(
            'kunstmaan-migrator/_mapping-row-fields',
            self::fieldMapVariables($editor, $lane, $row, $target),
        );
        $js = $view->clearJsBuffer();

        return $this->asJson(['html' => $html, 'js' => is_string($js) ? $js : '']);
    }

    /**
     * `mapping/check`, as a button: is the file well-formed, does it match
     * this install, are the conflicts decided. The same three questions in the
     * same order the CLI and `migrate` ask them — a mapping that is not
     * well-formed produces misleading target errors, so shape goes first.
     */
    public function actionCheck(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION);

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
        $path = $editor->path();

        if ($path === null) {
            return $this->asJson([
                'ok' => false,
                'headline' => Craft::t('kunstmaan-migrator', 'No mapping file is configured.'),
                'errors' => [],
                'total' => 0,
            ]);
        }

        try {
            $mapping = Mapping::fromFile($path);
        } catch (Throwable $e) {
            $message = Craft::t('kunstmaan-migrator', 'Mapping is unreadable: {message}', ['message' => $e->getMessage()]);

            return $this->asJson(['ok' => false, 'headline' => $message, 'errors' => [], 'total' => 0]);
        }

        $verdict = (new MappingCheck(new TargetModel(new CraftSchemaGateway())))->verdict($mapping);

        if ($verdict !== null) {
            $verdict[0] = Craft::t('kunstmaan-migrator', $verdict[0]);
        }

        $summary = Craft::t('kunstmaan-migrator', 'Well-formed and matches this install: {pages} page types, {parts} parts, {entities} entities.', [
            'pages' => count($mapping->pages()),
            'parts' => count($mapping->parts()),
            'entities' => count($mapping->entities()),
        ]);

        return $this->asJson($verdict === null
            ? ['ok' => true, 'summary' => $summary]
            : ['ok' => false, 'headline' => $verdict[0], 'errors' => array_slice($verdict[1], 0, 40), 'total' => count($verdict[1])]);
    }

    /**
     * The run screen: the mapping's environments, whether each is reachable,
     * and the button. Formerly a Utility; now a page of the section, because
     * one workflow split across two nav areas cost more than the convention
     * bought. The safety never lived in the location — it lives in
     * ProductionGuard and the confirmation dialogs, which came along.
     */
    public function actionRun(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION);

        return $this->renderTemplate('kunstmaan-migrator/_run', RunPanel::data());
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
        $this->requirePermission(Plugin::PERMISSION);

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

        // Anything not mapped simply does not migrate: the form no longer
        // asks per-column questions, so the unused-column bookkeeping is
        // written automatically — every previously listed column moves to
        // `ignore:`, minus the ones the posted map now consumes.
        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
        $row = $editor->row($lane, (string) $this->request->getRequiredBodyParam('key'));
        $consumed = [];

        foreach ($map as $expression) {
            $parsed = FieldExpression::parse($expression);

            if ($parsed->column !== '') {
                $consumed[$parsed->column] = true;
            }
        }

        $ignore = [];

        foreach (array_keys($row?->columns() ?? []) as $column) {
            if (!isset($consumed[(string) $column])) {
                $ignore[] = (string) $column;
            }
        }

        $changes = [
            'ignore' => $ignore !== [] ? $ignore : null,
            'unreviewed' => null,
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
        $this->requirePermission(Plugin::PERMISSION);

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());

        return $this->redirect($editor->path() !== null ? 'kunstmaan-migrator/mapping' : 'kunstmaan-migrator/setup');
    }

    public function actionQueue(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION);

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

        if ($pass === 'uris') {
            Craft::$app->getQueue()->push(new RecomputeStructureUrisJob(['mappingPath' => $path]));

            return $this->asJson(['ok' => true, 'queued' => ['uris'], 'message' => 'Queued URL settling.']);
        }

        try {
            $environments = Mapping::fromFile($path)->environments();
        } catch (Throwable $e) {
            return $this->asJson(['ok' => false, 'message' => $e->getMessage()]);
        }

        $selected = [];

        foreach (array_keys($environments) as $environment) {
            if (is_string($only) && $only !== '' && $only !== $environment) {
                continue;
            }

            $selected[] = (string) $environment;
        }

        if ($selected === []) {
            return $this->asJson([
                'ok' => false,
                'message' => Craft::t('kunstmaan-migrator', 'The mapping declares no such environment.'),
            ]);
        }

        // One job starts the chain (#48): each environment's last batch pushes
        // its adapter pass, each adapter pass pushes the next environment, and
        // the corpus-wide fixup, finalize and URI passes run only after the last one — an
        // ordering the queue now enforces structurally, where FIFO used to
        // merely suggest it (#47) and the web runner proved it a suggestion.
        QueueHelper::push(job: new MigrateEnvironmentJob([
            'mappingPath' => $path,
            'environment' => $selected[0],
            'remainingEnvironments' => array_values(array_slice($selected, 1)),
            'dryRun' => $dryRun,
            'force' => $force,
            'entriesOnly' => $pass === 'entries',
            'chainCorpusPasses' => $pass === 'full',
            'fullCorpus' => $pass === 'full' && count($selected) === count($environments),
            'mappingHash' => sha1((string) file_get_contents($path)),
        ]), priority: 512);

        $queued = $selected;

        if ($pass === 'full') {
            $queued[] = 'fixup';
            $queued[] = 'finalize';
            $queued[] = 'uris';
        }

        // What was queued, in words. "Queued LV, fixup, finalize." leaks two
        // internal pass names at the one moment somebody is watching.
        return $this->asJson([
            'ok' => true,
            'queued' => $queued,
            'message' => Craft::t('kunstmaan-migrator', '{n, plural, =1{Started. 1 stage queued.} other{Started. # stages, chained.}}', [
                'n' => count($queued),
            ]),
        ]);
    }
}
