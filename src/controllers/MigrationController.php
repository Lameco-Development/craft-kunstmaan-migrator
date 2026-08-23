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
use Lameco\KumaCompile\Mapping\Schema;
use Lameco\KumaCompile\Target\TargetCheck;
use lameco\kunstmaanmigrator\compile\TargetModel;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\run\RunPanel;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\console\StateController;
use lameco\kunstmaanmigrator\queue\FinalizeJob;
use lameco\kunstmaanmigrator\queue\MigrateEnvironmentJob;
use lameco\kunstmaanmigrator\queue\ResolveDeferredRefsJob;
use lameco\kunstmaanmigrator\run\Diagnostics;
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
            ? count(array_filter($rows, static fn ($row): bool => $row->target === null && $row->dropped === null))
            : 0;
        $specsPath = self::specsPath();

        // What each tab owes: the open count is the only number that makes
        // "which lane next" answerable without clicking through all four.
        $open = [];

        if ($error === null) {
            foreach (self::EDITABLE_LANES as $name) {
                try {
                    $open[$name] = $editor->progress($name)['open'];
                } catch (Throwable) {
                    $open[$name] = null;
                }
            }
        }

        return $this->renderTemplate('kunstmaan-migrator/_mapping', [
            'lane' => $lane,
            'lanes' => self::EDITABLE_LANES,
            'open' => $open,
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

        // Each Craft field's current expression, split into the parts somebody
        // can choose from — which column, and what to do to it. A sidecar has no
        // target of its own — it decorates whichever page carries a row — so its
        // field list is the union of what the page entry types offer.
        $fields = match (true) {
            $lane === 'sidecars'   => $editor->pageFields(),
            $row->target !== null  => $editor->fieldsFor($row->target),
            default                => [],
        };
        // The union across thirty entry types is a long list of strangers; a
        // sidecar's own mapped fields drown in it. They come first.
        if ($lane === 'sidecars') {
            $mapped = array_keys($row->map);
            usort($fields, static fn (string $a, string $b): int =>
                [!in_array($a, $mapped, true), $a] <=> [!in_array($b, $mapped, true), $b]);
        }

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
            'sidecarFills' => $lane === 'pages' && $row->target !== null
                ? $editor->sidecarFillsFor($row->target)
                : [],
            'carriage' => $lane === 'sidecars' ? $editor->sidecarCarriage($row) : [],
            'samples' => $editor->samplesFor($row),
        ]);
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

        // In live-volume order, like the lanes: rows() already sorts by where
        // the content is, and the first entry type is the default answer.
        $entryTypes = [];

        foreach ($editor->rows('pages') as $row) {
            if ($row->target !== null && $row->target !== '') {
                $entryTypes[$row->target] = true;
            }
        }

        $entryTypes = array_keys($entryTypes);
        $entryType = (string) $this->request->getQueryParam('entryType', $entryTypes[0] ?? '');

        return $this->renderTemplate('kunstmaan-migrator/_coverage', [
            'gaps' => $editor->coverageGaps(),
            'entryTypes' => $entryTypes,
            'entryType' => $entryType,
            'coverage' => $entryType !== '' && in_array($entryType, $entryTypes, true)
                ? $editor->coverageFor($entryType)
                : ['pageTypes' => [], 'fields' => []],
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

        $entryType = (string) $this->request->getRequiredBodyParam('entryType');
        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());

        if (!in_array($entryType, $editor->mappedEntryTypes(), true)) {
            throw new BadRequestHttpException(sprintf('The pages lane does not target "%s".', $entryType));
        }

        return $this->asJson([
            'html' => Craft::$app->getView()->renderTemplate('kunstmaan-migrator/_coverage-table', [
                'coverage' => $editor->coverageFor($entryType),
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

        $fields = $target !== '' ? $editor->fieldsFor($target) : [];
        $expressions = [];

        foreach ($fields as $field) {
            $expressions[$field] = FieldExpression::parse((string) ($row->map[$field] ?? ''));
        }

        // The partial's own {% js %} (selectize init) lands in a buffer and
        // travels with the HTML — swapped-in markup is otherwise dead.
        $view = Craft::$app->getView();
        $view->startJsBuffer();
        $html = $view->renderTemplate('kunstmaan-migrator/_mapping-row-fields', [
            'lane' => $lane,
            'row' => $row,
            'fields' => $fields,
            'expressions' => $expressions,
            'columns' => $editor->columnsFor($row),
            'transforms' => $editor->transforms(),
            'sidecarFills' => $lane === 'pages' && $target !== '' ? $editor->sidecarFillsFor($target) : [],
            'carriage' => [],
        ]);
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
        $this->requirePermission(Plugin::PERMISSION);

        $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
        $path = $editor->path();

        if ($path === null) {
            Craft::$app->getSession()->setError(Craft::t('kunstmaan-migrator', 'No mapping file is configured.'));

            return $this->redirectToPostedUrl();
        }

        try {
            $mapping = Mapping::fromFile($path);
        } catch (Throwable $e) {
            $message = Craft::t('kunstmaan-migrator', 'Mapping is unreadable: {message}', ['message' => $e->getMessage()]);

            if ($this->request->getAcceptsJson()) {
                return $this->asJson(['ok' => false, 'headline' => $message, 'errors' => [], 'total' => 0]);
            }

            Craft::$app->getSession()->setError($message);

            return $this->redirectToPostedUrl();
        }

        $verdict = match (true) {
            ($errors = (new Schema())->validate($mapping)) !== []
                => [Craft::t('kunstmaan-migrator', 'Mapping is not well-formed'), $errors],
            ($errors = (new TargetCheck(new TargetModel(new CraftSchemaGateway())))->check($mapping)) !== []
                => [Craft::t('kunstmaan-migrator', 'Mapping does not match this Craft install'), $errors],
            ($conflicts = $mapping->openConflicts()) !== []
                => [
                    Craft::t('kunstmaan-migrator', 'Unresolved conflicts — set conflict.status: decided'),
                    array_map(static fn ($c): string => sprintf('%s: %s vs %s', $c->subject, $c->artifact, $c->spec), $conflicts),
                ],
            default => null,
        };

        $summary = Craft::t('kunstmaan-migrator', 'Well-formed and matches this install: {pages} page types, {parts} parts, {entities} entities.', [
            'pages' => count($mapping->pages()),
            'parts' => count($mapping->parts()),
            'entities' => count($mapping->entities()),
        ]);

        // The screen asks over Ajax and draws the verdict inline; the flash
        // path stays for a plain form post with JavaScript unavailable.
        if ($this->request->getAcceptsJson()) {
            return $this->asJson($verdict === null
                ? ['ok' => true, 'summary' => $summary]
                : ['ok' => false, 'headline' => $verdict[0], 'errors' => array_slice($verdict[1], 0, 40), 'total' => count($verdict[1])]);
        }

        if ($verdict === null) {
            Craft::$app->getSession()->setNotice($summary);
        } else {
            [$headline, $errors] = $verdict;
            $shown = array_slice($errors, 0, 8);
            $more = count($errors) - count($shown);
            Craft::$app->getSession()->setError(
                $headline . ': ' . implode(' · ', $shown)
                . ($more > 0 ? ' ' . Craft::t('kunstmaan-migrator', '… and {more} more', ['more' => $more]) : ''),
            );
        }

        return $this->redirectToPostedUrl();
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
