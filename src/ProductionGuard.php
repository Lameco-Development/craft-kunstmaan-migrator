<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator;

use craft\helpers\App;

/**
 * The one place that decides what "production" means.
 *
 * The predicate and the refusal used to be the same method, so anything that
 * wanted to *ask* also had to be able to write to stderr — which is why the
 * doctor checks could only live on a console controller. They are separate
 * now: this answers, NeverProductionTrait refuses, and the control panel can
 * ask without pretending to be a terminal.
 */
final class ProductionGuard
{
    public static function isProduction(): bool
    {
        return App::env('CRAFT_ENVIRONMENT') === 'production';
    }
}
