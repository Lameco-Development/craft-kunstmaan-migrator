<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\safety;

use craft\helpers\App;
use RuntimeException;
use yii\base\Component;

/**
 * CP/job-safe production guard for Phase 12 migration actions.
 *
 * NeverProductionTrait remains the console-controller guard. This helper uses
 * the same CRAFT_ENVIRONMENT === production semantics where CP controllers and
 * queue jobs cannot depend on console stderr/ExitCode behavior.
 */
class MigrationSafety extends Component
{
    public const PRODUCTION_REFUSAL = 'Migration actions are disabled in production. Run this workflow only from a development or staging Craft environment.';

    public function isProduction(): bool
    {
        return $this->environmentName() === 'production';
    }

    public function environmentName(): string
    {
        $environment = App::env('CRAFT_ENVIRONMENT');
        return is_string($environment) ? $environment : '';
    }

    public function assertNotProductionForCp(): void
    {
        $this->assertNotProduction();
    }

    public function assertNotProductionForJob(): void
    {
        $this->assertNotProduction();
    }

    private function assertNotProduction(): void
    {
        if ($this->isProduction()) {
            throw new RuntimeException(self::PRODUCTION_REFUSAL);
        }
    }
}
