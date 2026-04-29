<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\safety;

use InvalidArgumentException;
use lameco\kunstmaanmigrator\safety\GateResult;
use lameco\kunstmaanmigrator\safety\MigrationGateService;
use lameco\kunstmaanmigrator\safety\MigrationSafety;
use lameco\kunstmaanmigrator\models\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MigrationGateServiceTest extends TestCase
{
    public function testGateResultSerializesAllFields(): void
    {
        $result = new GateResult(
            id: 'queue-worker-readiness',
            label: 'Queue worker readiness',
            status: 'unknown',
            severity: 'error',
            message: 'Queue readiness could not be verified.',
            remediation: 'Use the CLI after confirming a worker is running.',
            cliCommand: './craft kunstmaan-migrator/migrate --live',
            blocking: false,
        );

        self::assertSame([
            'id' => 'queue-worker-readiness',
            'label' => 'Queue worker readiness',
            'status' => 'unknown',
            'severity' => 'error',
            'message' => 'Queue readiness could not be verified.',
            'remediation' => 'Use the CLI after confirming a worker is running.',
            'cliCommand' => './craft kunstmaan-migrator/migrate --live',
            'blocking' => true,
        ], $result->toArray());
    }

    public function testGateResultAllowsOnlyLockedStatuses(): void
    {
        foreach (['passed', 'warning', 'blocked', 'unknown'] as $status) {
            $result = new GateResult(
                id: $status,
                label: $status,
                status: $status,
                severity: $status === 'passed' ? 'success' : 'warning',
                message: $status,
                remediation: '',
                cliCommand: '',
                blocking: $status !== 'passed',
            );

            self::assertSame($status, $result->toArray()['status']);
        }
    }

    public function testGateResultRejectsUnsupportedStatuses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GateResult(
            id: 'bad',
            label: 'Bad',
            status: 'unsupported',
            severity: 'error',
            message: 'Bad status',
            remediation: '',
            cliCommand: '',
            blocking: true,
        );
    }

    public function testReadinessExposesAllLockedDashboardIds(): void
    {
        $service = $this->makeGateService();

        $ids = array_column($service->readiness(), 'id');

        self::assertSame([
            'environment-name',
            'production-status',
            'admin-status',
            'elevated-session-status',
            'legacy-db-reachability',
            'anthropic-api-key-presence',
            'kunstmaan-source-path-validity',
            'storage-migration-writable',
            'mapping-file-path',
            'mapping-coverage-summary',
            'unsupported-warning-row-counts',
            'latest-compile-timestamp',
            'fatal-compile-warning-count',
            'queue-component-availability',
            'queue-worker-readiness',
            'latest-run-details',
            'cp-queue-actions-enabled',
            'cp-live-queue-action-enabled',
        ], $ids);
    }

    public function testDryRunGatesExposeLockedIdsAndSettingsBlock(): void
    {
        $settings = $this->settings();
        $settings->allowCpQueueActions = false;
        $service = $this->makeGateService(settings: $settings);

        $gates = $service->dryRunGates(['entities' => 'pages'], ['overwrite' => false]);

        self::assertSame([
            'non-production',
            'admin',
            'mapping-file-exists',
            'recent-compile-exists',
            'no-fatal-compile-warnings',
            'filters-options-valid',
            'queue-can-accept-jobs',
            'cp-queue-actions-enabled',
        ], array_column($gates, 'id'));

        $settingsGate = $this->gateById($gates, 'cp-queue-actions-enabled');
        self::assertSame('blocked', $settingsGate['status']);
        self::assertTrue($settingsGate['blocking']);
    }

    public function testCompileGatesExposeLatestCompileSummaryAndReadinessFields(): void
    {
        $service = $this->makeGateService();

        $compile = $service->compileGates();

        foreach ([
            'latest-compile-timestamp',
            'compile-status',
            'fatalWarningCount',
            'warningCount',
            'artifactPaths',
            'logReportLink',
            'compileReadiness',
        ] as $field) {
            self::assertArrayHasKey($field, $compile);
        }
    }

    public function testLiveGatesExposeLockedIdsAndStrictBlockingSemantics(): void
    {
        $settings = $this->settings();
        $settings->allowCpLiveQueueAction = false;
        $service = $this->makeGateService(settings: $settings, queueWorkerReady: null);

        $gates = $service->liveGates(
            filters: ['entities' => 'pages'],
            options: ['preloadAssets' => false],
            backupAcknowledged: true,
            typedPhrase: 'WRONG',
            warningsAccepted: false,
        );

        self::assertSame([
            'non-production',
            'admin',
            'elevated-session',
            'typed-confirmation',
            'successful-dry-run-same-options',
            'recent-compile-no-fatals',
            'queue-worker-readiness',
            'backup-acknowledgement',
            'mapping-coverage',
            'warning-unsupported-acceptance',
            'cp-live-queue-action-enabled',
            'job-production-hard-block',
        ], array_column($gates, 'id'));

        self::assertSame('blocked', $this->gateById($gates, 'typed-confirmation')['status']);
        self::assertStringContainsString('MIGRATE LIVE', $this->gateById($gates, 'typed-confirmation')['message']);
        self::assertSame('unknown', $this->gateById($gates, 'queue-worker-readiness')['status']);
        self::assertSame(
            'Queue readiness could not be verified. Live migration is blocked from the Control Panel; use the CLI after confirming a worker is running.',
            $this->gateById($gates, 'queue-worker-readiness')['remediation'],
        );
        self::assertTrue($this->gateById($gates, 'queue-worker-readiness')['blocking']);
        self::assertSame('blocked', $this->gateById($gates, 'cp-live-queue-action-enabled')['status']);
    }

    private function makeGateService(?Settings $settings = null, ?bool $queueWorkerReady = true): TestableMigrationGateService
    {
        $settings ??= $this->settings();

        return new TestableMigrationGateService(
            settings: $settings,
            safety: new TestMigrationSafety(),
            adminStatus: true,
            elevatedSessionStatus: true,
            mappingFileExists: true,
            latestCompile: [
                'status' => 'succeeded',
                'compiledAt' => '2026-04-29T10:00:00Z',
                'fatalWarningCount' => 0,
                'warningCount' => 1,
                'artifactPaths' => ['storage/migration/PAGE-ROOTED-COVERAGE.md'],
                'logReportLink' => 'storage/migration/PAGE-ROOTED-COVERAGE.md',
            ],
            latestDryRun: [
                'status' => 'succeeded',
                'filters' => ['entities' => 'pages'],
                'options' => ['preloadAssets' => false],
            ],
            mappingCoverage: [
                'total' => 4,
                'accepted' => 2,
                'warning' => 1,
                'unsupported' => 1,
            ],
            queueCanAcceptJobs: true,
            queueWorkerReady: $queueWorkerReady,
        );
    }

    private function settings(): Settings
    {
        $reflection = new ReflectionClass(Settings::class);
        /** @var Settings $settings */
        $settings = $reflection->newInstanceWithoutConstructor();
        return $settings;
    }

    /**
     * @param list<array<string, mixed>> $gates
     * @return array<string, mixed>
     */
    private function gateById(array $gates, string $id): array
    {
        foreach ($gates as $gate) {
            if (($gate['id'] ?? null) === $id) {
                return $gate;
            }
        }

        self::fail("Missing gate {$id}");
    }
}

final class TestMigrationSafety extends MigrationSafety
{
    public function isProduction(): bool
    {
        return false;
    }

    public function environmentName(): string
    {
        return 'dev';
    }
}

final class TestableMigrationGateService extends MigrationGateService
{
    /**
     * @param array<string, mixed>|null $latestCompile
     * @param array<string, mixed>|null $latestDryRun
     * @param array<string, int> $mappingCoverage
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly MigrationSafety $safety,
        private readonly ?bool $adminStatus,
        private readonly ?bool $elevatedSessionStatus,
        private readonly bool $mappingFileExists,
        private readonly ?array $latestCompile,
        private readonly ?array $latestDryRun,
        private readonly array $mappingCoverage,
        private readonly ?bool $queueCanAcceptJobs,
        private readonly ?bool $queueWorkerReady,
    ) {
        parent::__construct();
    }

    protected function settings(): Settings
    {
        return $this->settings;
    }

    protected function safety(): MigrationSafety
    {
        return $this->safety;
    }

    protected function adminStatus(): ?bool
    {
        return $this->adminStatus;
    }

    protected function elevatedSessionStatus(): ?bool
    {
        return $this->elevatedSessionStatus;
    }

    protected function mappingFileExists(): bool
    {
        return $this->mappingFileExists;
    }

    protected function latestCompileRun(): ?array
    {
        return $this->latestCompile;
    }

    protected function latestSuccessfulDryRun(array $filters, array $options): ?array
    {
        return $this->latestDryRun;
    }

    protected function mappingCoverage(): array
    {
        return $this->mappingCoverage;
    }

    protected function queueCanAcceptJobs(): ?bool
    {
        return $this->queueCanAcceptJobs;
    }

    protected function queueWorkerReady(): ?bool
    {
        return $this->queueWorkerReady;
    }
}
