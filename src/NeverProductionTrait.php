<?php

namespace lameco\kunstmaanmigrator;

use craft\helpers\Console;
use yii\console\ExitCode;

trait NeverProductionTrait
{
    protected function enforceNeverProduction(): ?int
    {
        if (ProductionGuard::isProduction()) {
            $this->stderr("Refusing to run against CRAFT_ENVIRONMENT=production\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return null;
    }
}
