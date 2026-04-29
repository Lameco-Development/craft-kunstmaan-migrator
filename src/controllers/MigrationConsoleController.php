<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\queue\jobs\MigrationPipelineJob;
use lameco\kunstmaanmigrator\queue\jobs\MigrationStageJob;
use lameco\kunstmaanmigrator\runs\MigrationRunService;
use lameco\kunstmaanmigrator\safety\MigrationGateService;
use lameco\kunstmaanmigrator\safety\MigrationSafety;
use Throwable;
use yii\web\Response;

/**
 * Control Panel migration console adapter.
 *
 * Phase 12 keeps long-running execution out of CP requests. This controller
 * builds the Utility view model and, in the queue-action task, dispatches queue
 * jobs against run records rather than running workflows inline.
 */
final class MigrationConsoleController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionQueueAnalyze(): Response
    {
        $plugin = $this->validatedQueueRequest();
        $filters = $this->requestFilters();
        $options = $this->requestOptions();
        $aiConfirmed = (string) Craft::$app->getRequest()->getBodyParam('aiConfirmed', '') === '1';
        $gates = $plugin->migrationGateService->analyzeGates($aiConfirmed);
        if ($this->hasBlockingGate($gates)) {
            $this->setFailFlash(self::copy()['gateFailure']);
            return $this->redirectBackToConsole('analyze');
        }

        return $this->queueStage($plugin, 'analyze', 'analyze', $filters, $options, $gates);
    }

    public function actionQueueCompile(): Response
    {
        $plugin = $this->validatedQueueRequest();
        $filters = $this->requestFilters();
        $options = $this->requestOptions();
        $compileGates = $plugin->migrationGateService->compileGates();
        $gates = array_values((array) ($compileGates['compileReadiness'] ?? []));
        if ($this->hasBlockingGate($gates)) {
            $this->setFailFlash(self::copy()['gateFailure']);
            return $this->redirectBackToConsole('compile');
        }

        return $this->queueStage($plugin, 'compile', 'compile', $filters, $options, $gates);
    }

    public function actionQueueVerify(): Response
    {
        $plugin = $this->validatedQueueRequest();
        $filters = $this->requestFilters();
        $options = $this->requestOptions();

        return $this->queueStage($plugin, 'verify', 'report', $filters, $options, []);
    }

    public function actionQueueDryRun(): Response
    {
        $plugin = $this->validatedQueueRequest();
        $filters = $this->requestFilters();
        $options = $this->requestOptions();
        $gates = $plugin->migrationGateService->dryRunGates($filters, $options);
        if ($this->hasBlockingGate($gates)) {
            $this->setFailFlash(self::copy()['gateFailure']);
            return $this->redirectBackToConsole('runs');
        }

        return $this->queuePipeline($plugin, 'dryRun', $filters, $options, $gates);
    }

    public function actionQueueLive(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAdmin();
        $this->requireElevatedSession();

        $plugin = Plugin::getInstance();
        $plugin->migrationSafety->assertNotProductionForCp();
        $this->assertCpQueueActionsAllowed($plugin);
        $this->assertCpLiveQueueActionAllowed($plugin);

        $request = Craft::$app->getRequest();
        $filters = $this->requestFilters();
        $options = $this->requestOptions();
        $backupAcknowledged = (string) $request->getBodyParam('backupAcknowledged', '') === '1';
        $typedPhrase = trim((string) $request->getBodyParam('confirmation', '')); // Required phrase: MIGRATE LIVE
        $warningsAccepted = (string) $request->getBodyParam('warningsAccepted', '') === '1';
        $gates = $plugin->migrationGateService->liveGates(
            filters: $filters,
            options: $options,
            backupAcknowledged: $backupAcknowledged,
            typedPhrase: $typedPhrase,
            warningsAccepted: $warningsAccepted,
        );

        if ($typedPhrase !== MigrationGateService::LIVE_CONFIRMATION_PHRASE || $this->hasBlockingGate($gates)) {
            $this->setFailFlash(self::copy()['gateFailure']);
            return $this->redirectBackToConsole('runs');
        }

        return $this->queuePipeline($plugin, 'live', $filters, $options, $gates);
    }

    /** @return array<string, mixed> */
    public static function utilityVariables(): array
    {
        $activeTab = self::activeTab();
        $gateService = self::gateService();
        $filters = self::defaultFilters();
        $options = self::defaultOptions();
        $copy = self::copy();
        $compileGates = self::safeArray(fn (): array => $gateService->compileGates(), self::emptyCompileGates());

        return [
            'title' => 'Kunstmaan Migration Console',
            'subtitle' => 'Review readiness, mapping coverage, queued runs, logs, and artifacts for the Kunstmaan → Craft migration. CLI remains the canonical workflow.',
            'tabs' => self::tabs($activeTab),
            'activeTab' => $activeTab,
            'readiness' => self::safeList(fn (): array => $gateService->readiness()),
            'analyzeGates' => self::safeList(fn (): array => $gateService->analyzeGates(false)),
            'compileGates' => $compileGates,
            'dryRunGates' => self::safeList(fn (): array => $gateService->dryRunGates($filters, $options)),
            'liveGates' => self::safeList(fn (): array => $gateService->liveGates(
                filters: $filters,
                options: $options,
                backupAcknowledged: false,
                typedPhrase: '',
                warningsAccepted: false,
            )),
            'latestRun' => self::latestRun(),
            'runs' => self::runs(),
            'reports' => self::reports(),
            'mapping' => self::mappingVariables(),
            'compileSummary' => self::compileSummary($compileGates),
            'filters' => $filters,
            'options' => $options,
            'runActions' => self::runActions($copy),
            'cliCommands' => self::cliCommands(),
            'copy' => $copy,
        ];
    }

    private function validatedQueueRequest(): Plugin
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $plugin->migrationSafety->assertNotProductionForCp();
        $this->assertCpQueueActionsAllowed($plugin);

        return $plugin;
    }

    private function assertCpQueueActionsAllowed(Plugin $plugin): void
    {
        if (!$plugin->getSettings()->allowCpQueueActions) {
            $this->setFailFlash('Control Panel queue actions are disabled in plugin settings.');
            throw new \yii\web\ForbiddenHttpException('Control Panel queue actions are disabled.');
        }
    }

    private function assertCpLiveQueueActionAllowed(Plugin $plugin): void
    {
        if (!$plugin->getSettings()->allowCpLiveQueueAction) {
            $this->setFailFlash('Control Panel live queue action is disabled in plugin settings.');
            throw new \yii\web\ForbiddenHttpException('Control Panel live queue action is disabled.');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>> $gateSnapshot
     */
    private function queueStage(Plugin $plugin, string $stage, string $mode, array $filters, array $options, array $gateSnapshot): Response
    {
        $run = $plugin->migrationRunService->createRun(
            stage: $stage,
            mode: $mode,
            filters: $filters,
            options: $options,
            initiatedByUserId: $this->currentUserId(),
            gateSnapshot: $gateSnapshot,
        );
        $runId = (int) $run->id;
        $queueJobId = Craft::$app->queue->push(new MigrationStageJob([
            'runId' => $runId,
            'stage' => $stage,
            'mode' => $mode,
            'filters' => $filters,
            'options' => $options,
        ]));
        $plugin->migrationRunService->markQueued($runId, $queueJobId);
        $plugin->migrationRunService->appendQueueJobId($runId, $queueJobId);
        $this->setSuccessFlash(self::copy()['successfulQueue']);

        return $this->redirectToRun($runId);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>> $gateSnapshot
     */
    private function queuePipeline(Plugin $plugin, string $mode, array $filters, array $options, array $gateSnapshot): Response
    {
        $run = $plugin->migrationRunService->createRun(
            stage: 'migrate',
            mode: $mode,
            filters: $filters,
            options: $options,
            initiatedByUserId: $this->currentUserId(),
            gateSnapshot: $gateSnapshot,
        );
        $runId = (int) $run->id;
        $queueJobId = Craft::$app->queue->push(new MigrationPipelineJob([
            'runId' => $runId,
            'mode' => $mode,
            'filters' => $filters,
            'options' => $options,
            'gateSnapshot' => $gateSnapshot,
            'batchOffset' => 0,
            'batchLimit' => (int) Craft::$app->getRequest()->getBodyParam('batchLimit', 50),
        ]));
        $plugin->migrationRunService->markQueued($runId, $queueJobId);
        $plugin->migrationRunService->appendQueueJobId($runId, $queueJobId);
        $this->setSuccessFlash(self::copy()['successfulQueue']);

        return $this->redirectToRun($runId);
    }

    /** @return array<string, mixed> */
    private function requestFilters(): array
    {
        $request = Craft::$app->getRequest();

        return [
            'entities' => $this->normalizeList($request->getBodyParam('entities', '')),
            'locales' => $this->normalizeList($request->getBodyParam('locales', '')),
            'since' => trim((string) $request->getBodyParam('since', '')),
        ];
    }

    /** @return array<string, mixed> */
    private function requestOptions(): array
    {
        $request = Craft::$app->getRequest();

        return [
            'source' => 'cp',
            'maxPerEntity' => $this->nullableInt($request->getBodyParam('maxPerEntity', null)),
            'overwrite' => (string) $request->getBodyParam('overwrite', '') === '1',
        ];
    }

    /** @return list<string> */
    private function normalizeList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items,
        )));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param list<array<string, mixed>> $gates */
    private function hasBlockingGate(array $gates): bool
    {
        foreach ($gates as $gate) {
            if ((bool) ($gate['blocking'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function currentUserId(): ?int
    {
        $id = Craft::$app->getUser()->getId();
        return is_numeric($id) ? (int) $id : null;
    }

    private function redirectBackToConsole(string $tab): Response
    {
        return $this->redirect(UrlHelper::cpUrl('utilities/kunstmaan-mapping', ['tab' => $tab]));
    }

    private function redirectToRun(int $runId): Response
    {
        return $this->redirect(UrlHelper::cpUrl('utilities/kunstmaan-mapping', ['tab' => 'runs', 'runId' => $runId]));
    }

    private static function activeTab(): string
    {
        $tab = trim((string) self::queryParam('tab', 'readiness'));
        $allowed = ['readiness', 'analyze', 'mapping', 'compile', 'runs', 'reports', 'danger'];

        return in_array($tab, $allowed, true) ? $tab : 'readiness';
    }

    /**
     * @return list<array{id:string,label:string,url:string,selected:bool}>
     */
    private static function tabs(string $activeTab): array
    {
        $tabs = [
            'readiness' => 'Readiness',
            'analyze' => 'Analyze',
            'mapping' => 'Mapping',
            'compile' => 'Compile',
            'runs' => 'Runs',
            'reports' => 'Reports',
            'danger' => 'Danger Zone',
        ];

        $out = [];
        foreach ($tabs as $id => $label) {
            $out[] = [
                'id' => $id,
                'label' => $label,
                'url' => self::cpUrl(['tab' => $id]),
                'selected' => $id === $activeTab,
            ];
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function copy(): array
    {
        return [
            'canonicalWorkflow' => 'CLI remains the canonical workflow.',
            'cliFallbackIntro' => 'Use the CLI when you need full debug output, production safety verification, or a gate cannot be verified from the Control Panel.',
            'gateFailure' => 'This action cannot start because one or more safety gates failed. Review the failed gates below, fix the issue, then try again or use the shown CLI command.',
            'queueDryRun' => 'Queue dry run',
            'queueAnalyze' => 'Queue analyze',
            'queueCompile' => 'Queue compile',
            'queueVerifyReport' => 'Queue verify/report',
            'queueLiveMigration' => 'Queue live migration',
            'analyzeUnavailable' => 'Analyze is unavailable because no Anthropic API key is configured. Add an API key in plugin settings or run the CLI with an approved environment.',
            'dryRunGate' => 'A successful dry run with the same filters and options is required before live migration can be queued.',
            'compileGate' => 'A recent compile with no fatal warnings is required before live migration can be queued.',
            'warningAcceptance' => 'I understand selected warning/unsupported mapping rows may be skipped, dropped, or migrated imperfectly.',
            'emptyLogLines' => 'No log lines have been written for this run yet. Refresh after the queue worker starts processing.',
            'emptyArtifacts' => 'No artifacts have been recorded for this run yet. Artifacts are written under storage/migration.',
            'emptyMappingHeading' => 'No mapping rows found',
            'emptyMappingBody' => 'Run analyze and compile first, then return here to review mapping coverage.',
            'emptyFilterBody' => 'No rows match the current filters. Clear filters to view all mapping rows.',
            'productionHardBlock' => MigrationSafety::PRODUCTION_REFUSAL,
            'backupAcknowledgement' => 'I have a restorable database and asset backup for this environment.',
            'aiConfirmation' => 'I understand analyze may send schema and mapping context to Anthropic.',
            'successfulQueue' => 'Job queued. Progress will appear in the current run record.',
        ];
    }

    /** @return array<string, string> */
    private static function cliCommands(): array
    {
        return [
            'doctor' => './craft kunstmaan-migrator/doctor',
            'analyze' => './craft kunstmaan-migrator/analyze',
            'map' => './craft kunstmaan-migrator/map',
            'compile' => './craft kunstmaan-migrator/compile',
            'dryRun' => './craft kunstmaan-migrator/migrate --dry-run',
            'live' => './craft kunstmaan-migrator/migrate --live',
            'verify' => './craft kunstmaan-migrator/verify',
            'queue' => './craft queue/listen',
        ];
    }

    /**
     * @param array<string, string> $copy
     * @return array<string, array<string, mixed>>
     */
    private static function runActions(array $copy): array
    {
        return [
            'analyze' => ['label' => $copy['queueAnalyze'], 'action' => 'kunstmaan-migrator/migration-console/queue-analyze', 'destructive' => false],
            'compile' => ['label' => $copy['queueCompile'], 'action' => 'kunstmaan-migrator/migration-console/queue-compile', 'destructive' => false],
            'verify' => ['label' => $copy['queueVerifyReport'], 'action' => 'kunstmaan-migrator/migration-console/queue-verify', 'destructive' => false],
            'dryRun' => ['label' => $copy['queueDryRun'], 'action' => 'kunstmaan-migrator/migration-console/queue-dry-run', 'destructive' => false],
            'live' => ['label' => $copy['queueLiveMigration'], 'action' => 'kunstmaan-migrator/migration-console/queue-live', 'destructive' => true],
        ];
    }

    /** @param array<string, mixed> $compileGates @return array<string, mixed> */
    private static function compileSummary(array $compileGates): array
    {
        return [
            'timestamp' => $compileGates['latest-compile-timestamp'] ?? null,
            'status' => $compileGates['compile-status'] ?? 'unknown',
            'fatalWarningCount' => (int) ($compileGates['fatalWarningCount'] ?? 0),
            'warningCount' => (int) ($compileGates['warningCount'] ?? 0),
            'artifactPaths' => array_values((array) ($compileGates['artifactPaths'] ?? [])),
            'logReportLink' => (string) ($compileGates['logReportLink'] ?? ''),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function latestRun(): ?array
    {
        return self::runService()?->latest();
    }

    /** @return list<array<string, mixed>> */
    private static function runs(): array
    {
        return self::runService()?->list(50) ?? [];
    }

    /** @return list<array{name:string,type:string,path:string,modified:?int,runId:?int}> */
    private static function reports(): array
    {
        $paths = [
            'REPORT.md' => 'report',
            'VERIFY-*.md' => 'verify',
            'PAGE-ROOTED-COVERAGE.md' => 'coverage',
            'MAPPING-AUDIT.md' => 'audit',
            'kunstmaan-schema.json' => 'graph',
            'craft-schema.json' => 'graph',
        ];

        $reports = [];
        foreach ($paths as $path => $type) {
            $reports[] = [
                'name' => $path,
                'type' => $type,
                'path' => 'storage/migration/' . $path,
                'modified' => null,
                'runId' => null,
            ];
        }

        return $reports;
    }

    /** @return array<string, mixed> */
    private static function mappingVariables(): array
    {
        return self::safeArray(
            fn (): array => MappingController::utilityVariables(),
            [
                'mappingPath' => 'storage/migration/mapping.yaml',
                'entities' => [],
                'selectedEntity' => '',
                'indexedRows' => [],
                'summaryCounts' => [],
                'targetOptions' => [
                    'sections' => [],
                    'entryTypes' => [],
                    'fieldHandles' => [],
                    'matrixFields' => [],
                    'blockTypes' => [],
                    'handlers' => [],
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function emptyCompileGates(): array
    {
        return [
            'latest-compile-timestamp' => null,
            'compile-status' => 'unknown',
            'fatalWarningCount' => 0,
            'warningCount' => 0,
            'artifactPaths' => [],
            'logReportLink' => '',
            'compileReadiness' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultFilters(): array
    {
        $plugin = self::plugin();
        $settings = $plugin?->getSettings();
        $defaults = (array) ($settings->defaultFilters ?? []);

        return [
            'entities' => self::csvQueryParam('entities', (array) ($defaults['entities'] ?? [])),
            'locales' => self::csvQueryParam('locales', (array) ($defaults['locales'] ?? [])),
            'since' => trim((string) self::queryParam('since', (string) ($defaults['since'] ?? ''))),
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultOptions(): array
    {
        return [
            'source' => 'cp',
        ];
    }

    private static function gateService(): MigrationGateService
    {
        $plugin = self::plugin();
        if ($plugin instanceof Plugin && $plugin->has('migrationGateService', true)) {
            /** @var MigrationGateService $service */
            $service = $plugin->get('migrationGateService');
            return $service;
        }

        return new MigrationGateService();
    }

    private static function runService(): ?MigrationRunService
    {
        $plugin = self::plugin();
        if ($plugin instanceof Plugin && $plugin->has('migrationRunService', true)) {
            /** @var MigrationRunService $service */
            $service = $plugin->get('migrationRunService');
            return $service;
        }

        return null;
    }

    private static function plugin(): ?Plugin
    {
        try {
            $plugin = Plugin::getInstance();
            return $plugin instanceof Plugin ? $plugin : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param callable():array<string,mixed> $callback @param array<string,mixed> $fallback @return array<string,mixed> */
    private static function safeArray(callable $callback, array $fallback): array
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** @param callable():list<array<string,mixed>> $callback @return list<array<string,mixed>> */
    private static function safeList(callable $callback): array
    {
        try {
            return array_values($callback());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private static function csvQueryParam(string $name, array $default = []): array
    {
        $value = self::queryParam($name, implode(',', $default));
        if (!is_string($value)) {
            return $default;
        }

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value),
        )));
    }

    private static function queryParam(string $name, mixed $default = null): mixed
    {
        try {
            $request = Craft::$app?->getRequest();
            if ($request === null || $request->getIsConsoleRequest()) {
                return $default;
            }

            return $request->getQueryParam($name, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /** @param array<string, string> $params */
    private static function cpUrl(array $params = []): string
    {
        try {
            return UrlHelper::cpUrl('utilities/kunstmaan-mapping', $params);
        } catch (Throwable) {
            $query = http_build_query($params);
            return 'utilities/kunstmaan-mapping' . ($query === '' ? '' : '?' . $query);
        }
    }
}
