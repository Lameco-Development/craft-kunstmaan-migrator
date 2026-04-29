<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\safety;

use Craft;
use lameco\kunstmaanmigrator\mapping\MappingFile;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\runs\MigrationRunService;
use yii\base\Component;

/**
 * Structured gate evaluation for Phase 12 CP/queue migration actions.
 *
 * The service returns array-shaped GateResult data so CP templates, controllers,
 * and queue dispatchers can render the same safety contract they enforce.
 */
class MigrationGateService extends Component
{
    public const LIVE_CONFIRMATION_PHRASE = 'MIGRATE LIVE';
    public const QUEUE_UNKNOWN_REMEDIATION = 'Queue readiness could not be verified. Live migration is blocked from the Control Panel; use the CLI after confirming a worker is running.';

    public ?MigrationRunService $migrationRunService = null;
    public ?MappingFile $mappingFile = null;
    public ?Settings $settings = null;
    public ?MigrationSafety $migrationSafety = null;

    /** @return list<array<string, mixed>> */
    public function readiness(): array
    {
        $settings = $this->settings();
        $compile = $this->latestCompileRun();
        $coverage = $this->mappingCoverage();
        $latestRun = $this->latestRun();

        return [
            $this->gate('environment-name', 'Environment name', 'passed', 'info', $this->environmentMessage(), '', '', false),
            $this->booleanGate('production-status', 'Production status', !$this->safety()->isProduction(), 'Non-production environment confirmed.', MigrationSafety::PRODUCTION_REFUSAL, true),
            $this->nullableGate('admin-status', 'Admin status', $this->adminStatus(), 'Admin user confirmed.', 'An admin user is required to queue migration actions.', true),
            $this->nullableGate('elevated-session-status', 'Elevated session status', $this->elevatedSessionStatus(), 'Elevated session confirmed.', 'An elevated session is required for high-risk actions.', false),
            $this->nullableGate('legacy-db-reachability', 'Legacy DB reachability', $this->legacyDbReachable(), 'Legacy database connection is reachable.', 'Run `./craft kunstmaan-migrator/doctor` and fix legacy DB settings.', false),
            $this->booleanGate('anthropic-api-key-presence', 'Anthropic API key presence', $this->hasAnthropicApiKey($settings), 'Anthropic API key is configured.', 'Analyze is unavailable because no Anthropic API key is configured.', false),
            $this->nullableGate('kunstmaan-source-path-validity', 'Kunstmaan source path validity', $this->kunstmaanSourcePathValid($settings), 'Kunstmaan source path is valid.', 'Set a valid Kunstmaan source checkout path in plugin settings or config.', false),
            $this->nullableGate('storage-migration-writable', 'Storage migration writable', $this->storageMigrationWritable(), '`storage/migration` is writable.', 'Ensure Craft can write to `storage/migration`.', false),
            $this->booleanGate('mapping-file-path', 'Mapping file path', $this->mappingFileExists(), 'mapping.yaml exists.', 'Run analyze and review mapping.yaml before queueing migration actions.', false),
            $this->coverageGate('mapping-coverage-summary', 'Mapping coverage summary', $coverage, false),
            $this->unsupportedWarningGate('unsupported-warning-row-counts', 'Unsupported/warning row counts', $coverage, false),
            $this->gate(
                'latest-compile-timestamp',
                'Latest compile timestamp',
                $compile === null ? GateResult::STATUS_UNKNOWN : GateResult::STATUS_PASSED,
                $compile === null ? 'unknown' : 'success',
                $compile === null ? 'No compile run has been recorded.' : 'Latest compile: ' . (string) ($compile['compiledAt'] ?? $compile['dateFinished'] ?? $compile['dateUpdated'] ?? 'unknown'),
                'Run `./craft kunstmaan-migrator/compile` or queue compile from the Control Panel.',
                './craft kunstmaan-migrator/compile',
                $compile === null,
            ),
            $this->booleanGate('fatal-compile-warning-count', 'Fatal compile warning count', (int) ($compile['fatalWarningCount'] ?? 0) === 0, 'No fatal compile warnings detected.', 'Resolve fatal compile warnings before live migration.', true),
            $this->nullableGate('queue-component-availability', 'Queue component availability', $this->queueCanAcceptJobs(), 'Queue component is available.', 'Configure Craft queue before queueing migration actions.', true),
            $this->queueWorkerGate(),
            $this->gate(
                'latest-run-details',
                'Latest run details',
                $latestRun === null ? GateResult::STATUS_UNKNOWN : GateResult::STATUS_PASSED,
                $latestRun === null ? 'unknown' : 'info',
                $latestRun === null ? 'No migration runs yet.' : sprintf(
                    'Latest run: %s/%s %s.',
                    (string) ($latestRun['stage'] ?? 'unknown'),
                    (string) ($latestRun['mode'] ?? 'unknown'),
                    (string) ($latestRun['status'] ?? 'unknown'),
                ),
                'Queue a dry run after readiness checks pass, or run the equivalent CLI command from your terminal.',
                './craft kunstmaan-migrator/migrate',
                false,
            ),
            $this->booleanGate('cp-queue-actions-enabled', 'CP queue actions enabled', $settings->allowCpQueueActions, 'Control Panel queue actions are enabled.', 'Enable CP queue actions in plugin settings.', true),
            $this->booleanGate('cp-live-queue-action-enabled', 'CP live queue action enabled', $settings->allowCpLiveQueueAction, 'Control Panel live queue action is enabled.', 'Enable CP live queue action in plugin settings after confirming safety policy.', true),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function analyzeGates(bool $aiConfirmed): array
    {
        $settings = $this->settings();

        return [
            $this->booleanGate('non-production', 'Non-production', !$this->safety()->isProduction(), 'Non-production environment confirmed.', MigrationSafety::PRODUCTION_REFUSAL, true),
            $this->nullableGate('admin', 'Admin', $this->adminStatus(), 'Admin user confirmed.', 'An admin user is required to queue analyze.', true),
            $this->booleanGate('anthropic-api-key-presence', 'Anthropic API key presence', $this->hasAnthropicApiKey($settings), 'Anthropic API key is configured.', 'Analyze is unavailable because no Anthropic API key is configured.', true),
            $this->booleanGate('ai-confirmation', 'AI confirmation', $aiConfirmed, 'AI usage confirmed.', 'I understand analyze may send schema and mapping context to Anthropic.', true),
            $this->booleanGate('cp-queue-actions-enabled', 'CP queue actions enabled', $settings->allowCpQueueActions, 'Control Panel queue actions are enabled.', 'Enable CP queue actions in plugin settings.', true),
        ];
    }

    /** @return array<string, mixed> */
    public function compileGates(): array
    {
        $compile = $this->latestCompileRun();

        return [
            'latest-compile-timestamp' => $compile['compiledAt'] ?? $compile['dateFinished'] ?? $compile['dateUpdated'] ?? null,
            'compile-status' => $compile['status'] ?? 'unknown',
            'fatalWarningCount' => (int) ($compile['fatalWarningCount'] ?? 0),
            'warningCount' => (int) ($compile['warningCount'] ?? 0),
            'artifactPaths' => array_values((array) ($compile['artifactPaths'] ?? [])),
            'logReportLink' => (string) ($compile['logReportLink'] ?? $compile['logPath'] ?? ''),
            'compileReadiness' => [
                $this->booleanGate('non-production', 'Non-production', !$this->safety()->isProduction(), 'Non-production environment confirmed.', MigrationSafety::PRODUCTION_REFUSAL, true),
                $this->nullableGate('admin', 'Admin', $this->adminStatus(), 'Admin user confirmed.', 'An admin user is required to queue compile.', true),
                $this->booleanGate('mapping-file-exists', 'Mapping file exists', $this->mappingFileExists(), 'mapping.yaml exists.', 'Run analyze and review mapping.yaml before compile.', true),
                $this->coverageGate('mapping-coverage', 'Mapping coverage', $this->mappingCoverage(), true),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function dryRunGates(array $filters, array $options): array
    {
        $settings = $this->settings();
        $compile = $this->latestCompileRun();

        return [
            $this->booleanGate('non-production', 'Non-production', !$this->safety()->isProduction(), 'Non-production environment confirmed.', MigrationSafety::PRODUCTION_REFUSAL, true),
            $this->nullableGate('admin', 'Admin', $this->adminStatus(), 'Admin user confirmed.', 'An admin user is required to queue dry run.', true),
            $this->booleanGate('mapping-file-exists', 'Mapping file exists', $this->mappingFileExists(), 'mapping.yaml exists.', 'Run analyze and review mapping.yaml before dry run.', true),
            $this->booleanGate('recent-compile-exists', 'Recent compile exists', $compile !== null && (string) ($compile['status'] ?? '') === MigrationRunService::STATUS_SUCCEEDED, 'Recent successful compile exists.', 'A recent compile with no fatal warnings is required before migration can be queued.', true),
            $this->booleanGate('no-fatal-compile-warnings', 'No fatal compile warnings', (int) ($compile['fatalWarningCount'] ?? 0) === 0, 'No fatal compile warnings detected.', 'Resolve fatal compile warnings before migration.', true),
            $this->booleanGate('filters-options-valid', 'Filters/options valid', $this->filtersOptionsValid($filters, $options), 'Filters and options are valid.', 'Review selected filters/options and retry.', true),
            $this->nullableGate('queue-can-accept-jobs', 'Queue can accept jobs', $this->queueCanAcceptJobs(), 'Queue can accept jobs.', 'Configure Craft queue before queueing dry run.', true),
            $this->booleanGate('cp-queue-actions-enabled', 'CP queue actions enabled', $settings->allowCpQueueActions, 'Control Panel queue actions are enabled.', 'Enable CP queue actions in plugin settings.', true),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function liveGates(array $filters, array $options, bool $backupAcknowledged, string $typedPhrase, bool $warningsAccepted): array
    {
        $settings = $this->settings();
        $compile = $this->latestCompileRun();
        $coverage = $this->mappingCoverage();
        $dryRun = $this->latestSuccessfulDryRun($filters, $options);
        $hasWarningsOrUnsupported = ((int) ($coverage['warning'] ?? 0) + (int) ($coverage['unsupported'] ?? 0)) > 0;

        return [
            $this->booleanGate('non-production', 'Non-production', !$this->safety()->isProduction(), 'Non-production environment confirmed.', MigrationSafety::PRODUCTION_REFUSAL, true),
            $this->nullableGate('admin', 'Admin', $this->adminStatus(), 'Admin user confirmed.', 'An admin user is required to queue live migration.', true),
            $this->nullableGate('elevated-session', 'Elevated session', $this->elevatedSessionStatus(), 'Elevated session confirmed.', 'Live migration requires an elevated Craft session.', true),
            $this->booleanGate('typed-confirmation', 'Typed confirmation', $typedPhrase === self::LIVE_CONFIRMATION_PHRASE, 'Typed confirmation matches MIGRATE LIVE.', 'Type `MIGRATE LIVE` to confirm this non-production live run.', true),
            $this->booleanGate('successful-dry-run-same-options', 'Successful dry run with same options', $dryRun !== null, 'A successful dry run with the same filters and options exists.', 'A successful dry run with the same filters and options is required before live migration can be queued.', true),
            $this->booleanGate('recent-compile-no-fatals', 'Recent compile with no fatals', $compile !== null && (string) ($compile['status'] ?? '') === MigrationRunService::STATUS_SUCCEEDED && (int) ($compile['fatalWarningCount'] ?? 0) === 0, 'Recent compile has no fatal warnings.', 'A recent compile with no fatal warnings is required before live migration can be queued.', true),
            $this->queueWorkerGate(),
            $this->booleanGate('backup-acknowledgement', 'Backup acknowledgement', $backupAcknowledged, 'Backup acknowledged.', 'I have a restorable database and asset backup for this environment.', true),
            $this->coverageGate('mapping-coverage', 'Mapping coverage', $coverage, true),
            $this->booleanGate('warning-unsupported-acceptance', 'Warning/unsupported acceptance', !$hasWarningsOrUnsupported || $warningsAccepted, 'Warning/unsupported mapping rows accepted.', 'I understand selected warning/unsupported mapping rows may be skipped, dropped, or migrated imperfectly.', true),
            $this->booleanGate('cp-live-queue-action-enabled', 'CP live queue action enabled', $settings->allowCpLiveQueueAction, 'Control Panel live queue action is enabled.', 'Enable CP live queue action in plugin settings after confirming safety policy.', true),
            $this->booleanGate('job-production-hard-block', 'Job production hard-block', !$this->safety()->isProduction(), 'Queue job production hard-block will pass.', MigrationSafety::PRODUCTION_REFUSAL, true),
        ];
    }

    protected function settings(): Settings
    {
        if ($this->settings instanceof Settings) {
            return $this->settings;
        }

        $plugin = Plugin::getInstance();
        if ($plugin instanceof Plugin) {
            /** @var Settings $settings */
            $settings = $plugin->getSettings();
            return $settings;
        }

        return new Settings();
    }

    protected function safety(): MigrationSafety
    {
        return $this->migrationSafety ?? new MigrationSafety();
    }

    protected function adminStatus(): ?bool
    {
        $app = $this->craftApp();
        if ($app === null) {
            return null;
        }

        $user = $app->user ?? null;
        $identity = $user?->identity ?? null;
        if ($identity === null) {
            return null;
        }

        return (bool) ($identity->admin ?? false);
    }

    protected function elevatedSessionStatus(): ?bool
    {
        $app = $this->craftApp();
        if ($app === null) {
            return null;
        }

        $user = $app->user ?? null;
        if ($user === null || !method_exists($user, 'getHasElevatedSession')) {
            return null;
        }

        return (bool) $user->getHasElevatedSession();
    }

    protected function legacyDbReachable(): ?bool
    {
        return null;
    }

    protected function kunstmaanSourcePathValid(Settings $settings): ?bool
    {
        $path = (string) ($settings->kunstmaanSourcePath ?? '');
        if ($path === '') {
            return null;
        }

        return is_dir($path);
    }

    protected function storageMigrationWritable(): ?bool
    {
        $app = $this->craftApp();
        if ($app === null) {
            return null;
        }

        $storagePath = $app->path?->getStoragePath();
        if (!is_string($storagePath) || $storagePath === '') {
            return null;
        }

        $migrationPath = $storagePath . '/migration';
        return is_dir($migrationPath) && is_writable($migrationPath);
    }

    protected function mappingFileExists(): bool
    {
        return is_file($this->mappingPath());
    }

    /** @return array<string, int> */
    protected function mappingCoverage(): array
    {
        if (!$this->mappingFileExists()) {
            return ['total' => 0, 'accepted' => 0, 'warning' => 0, 'unsupported' => 0];
        }

        $data = ($this->mappingFile ?? new MappingFile())->load($this->mappingPath());
        $coverage = ['total' => 0, 'accepted' => 0, 'warning' => 0, 'unsupported' => 0];
        foreach ((array) ($data['proposals'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $coverage['total']++;
            $status = (string) ($row['status'] ?? '');
            if (isset($coverage[$status])) {
                $coverage[$status]++;
            }
        }

        return $coverage;
    }

    /** @return array<string, mixed>|null */
    protected function latestCompileRun(): ?array
    {
        return $this->runService()?->latest('compile', 'compile');
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    protected function latestSuccessfulDryRun(array $filters, array $options): ?array
    {
        $run = $this->runService()?->latest('migrate', 'dryRun');
        if ($run === null || (string) ($run['status'] ?? '') !== MigrationRunService::STATUS_SUCCEEDED) {
            return null;
        }

        return $this->sameArrayValues((array) ($run['filters'] ?? []), $filters)
            && $this->sameArrayValues((array) ($run['options'] ?? []), $options)
            ? $run
            : null;
    }

    /** @return array<string, mixed>|null */
    protected function latestRun(): ?array
    {
        return $this->runService()?->latest();
    }

    protected function queueCanAcceptJobs(): ?bool
    {
        $app = $this->craftApp();
        if ($app === null) {
            return null;
        }

        return $app->has('queue', true) === true;
    }

    protected function queueWorkerReady(): ?bool
    {
        return null;
    }

    protected function filtersOptionsValid(array $filters, array $options): bool
    {
        foreach ([$filters, $options] as $values) {
            foreach ($values as $value) {
                if (is_resource($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasAnthropicApiKey(Settings $settings): bool
    {
        return is_string($settings->anthropicApiKey) && $settings->anthropicApiKey !== '';
    }

    private function environmentMessage(): string
    {
        $environment = $this->safety()->environmentName();
        return $environment === '' ? 'CRAFT_ENVIRONMENT is not set.' : 'Environment: ' . $environment;
    }

    private function mappingPath(): string
    {
        $settingsPath = (string) ($this->settings()->mappingPath ?? '');
        if ($settingsPath !== '') {
            return $settingsPath;
        }

        $app = $this->craftApp();
        if ($app !== null) {
            $storagePath = $app->path?->getStoragePath();
            if (is_string($storagePath) && $storagePath !== '') {
                return $storagePath . '/migration/mapping.yaml';
            }
        }

        return 'storage/migration/mapping.yaml';
    }

    private function runService(): ?MigrationRunService
    {
        if ($this->migrationRunService instanceof MigrationRunService) {
            return $this->migrationRunService;
        }

        if (!class_exists('Yii')) {
            return null;
        }

        $plugin = Plugin::getInstance();
        if ($plugin instanceof Plugin && $plugin->has('migrationRunService', true)) {
            /** @var MigrationRunService $service */
            $service = $plugin->get('migrationRunService');
            return $service;
        }

        return null;
    }

    private function craftApp(): ?object
    {
        if (!class_exists('Craft') || !isset(Craft::$app)) {
            return null;
        }

        return Craft::$app;
    }

    private function queueWorkerGate(): array
    {
        $ready = $this->queueWorkerReady();
        if ($ready === null) {
            return $this->gate(
                'queue-worker-readiness',
                'Queue worker readiness',
                GateResult::STATUS_UNKNOWN,
                'error',
                'Queue worker readiness could not be verified.',
                self::QUEUE_UNKNOWN_REMEDIATION,
                './craft queue/listen',
                true,
            );
        }

        return $this->booleanGate(
            'queue-worker-readiness',
            'Queue worker readiness',
            $ready,
            'Queue worker readiness confirmed.',
            self::QUEUE_UNKNOWN_REMEDIATION,
            true,
        );
    }

    /**
     * @param array<string, int> $coverage
     */
    private function coverageGate(string $id, string $label, array $coverage, bool $blocking): array
    {
        $total = (int) ($coverage['total'] ?? 0);
        $accepted = (int) ($coverage['accepted'] ?? 0);
        $unsupported = (int) ($coverage['unsupported'] ?? 0);

        return $this->gate(
            $id,
            $label,
            $total > 0 && $unsupported === 0 ? GateResult::STATUS_PASSED : ($total > 0 ? GateResult::STATUS_WARNING : GateResult::STATUS_UNKNOWN),
            $total > 0 && $unsupported === 0 ? 'success' : 'warning',
            "Mapping coverage: {$accepted}/{$total} accepted; {$unsupported} unsupported.",
            'Review mapping coverage and resolve or explicitly accept unsupported rows before live migration.',
            './craft kunstmaan-migrator/map',
            $blocking && ($total === 0 || $unsupported > 0),
        );
    }

    /**
     * @param array<string, int> $coverage
     */
    private function unsupportedWarningGate(string $id, string $label, array $coverage, bool $blocking): array
    {
        $warning = (int) ($coverage['warning'] ?? 0);
        $unsupported = (int) ($coverage['unsupported'] ?? 0);
        $clean = $warning === 0 && $unsupported === 0;

        return $this->gate(
            $id,
            $label,
            $clean ? GateResult::STATUS_PASSED : GateResult::STATUS_WARNING,
            $clean ? 'success' : 'warning',
            $clean ? 'No warning or unsupported mapping rows detected.' : "{$warning} warning and {$unsupported} unsupported mapping rows detected.",
            'Review or explicitly accept warning/unsupported mapping rows before live migration.',
            './craft kunstmaan-migrator/map',
            $blocking && !$clean,
        );
    }

    private function nullableGate(string $id, string $label, ?bool $passed, string $passedMessage, string $failedMessage, bool $blocking): array
    {
        if ($passed === null) {
            return $this->gate($id, $label, GateResult::STATUS_UNKNOWN, 'unknown', $failedMessage, $failedMessage, '', $blocking);
        }

        return $this->booleanGate($id, $label, $passed, $passedMessage, $failedMessage, $blocking);
    }

    private function booleanGate(string $id, string $label, bool $passed, string $passedMessage, string $failedMessage, bool $blocking): array
    {
        return $this->gate(
            $id,
            $label,
            $passed ? GateResult::STATUS_PASSED : GateResult::STATUS_BLOCKED,
            $passed ? 'success' : 'error',
            $passed ? $passedMessage : $failedMessage,
            $passed ? '' : $failedMessage,
            '',
            $blocking && !$passed,
        );
    }

    private function gate(string $id, string $label, string $status, string $severity, string $message, string $remediation, string $cliCommand, bool $blocking): array
    {
        return (new GateResult(
            id: $id,
            label: $label,
            status: $status,
            severity: $severity,
            message: $message,
            remediation: $remediation,
            cliCommand: $cliCommand,
            blocking: $blocking,
        ))->toArray();
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sameArrayValues(array $left, array $right): bool
    {
        ksort($left);
        ksort($right);

        return $left == $right;
    }
}
