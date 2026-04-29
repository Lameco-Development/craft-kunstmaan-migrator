<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\utilities;

use Craft;
use craft\base\Utility;
use craft\web\View;
use lameco\kunstmaanmigrator\controllers\MappingController;

final class KunstmaanMappingUtility extends Utility
{
    public static function id(): string
    {
        return 'kunstmaan-mapping';
    }

    public static function displayName(): string
    {
        return 'Kunstmaan Mapping';
    }

    public static function icon(): ?string
    {
        return 'shuffle';
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate(
            'kunstmaan-migrator/_mapping/index',
            MappingController::utilityVariables(),
            View::TEMPLATE_MODE_CP,
        );
    }
}
