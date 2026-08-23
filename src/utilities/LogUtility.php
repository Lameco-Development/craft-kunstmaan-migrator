<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\utilities;

use Craft;
use craft\base\Utility;
use lameco\kunstmaanmigrator\run\RunLog;

/**
 * The run history, where Craft keeps its other logs.
 *
 * Distinct from the section on purpose: the workspace is where migrations are
 * authored and started, this is the read-only record of what already ran —
 * the same split as Craft's own Deprecation Warnings. It never links back
 * into the workflow as a second door.
 */
final class LogUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('kunstmaan-migrator', 'Kunstmaan migration log');
    }

    public static function id(): string
    {
        return 'kunstmaan-migration-log';
    }

    public static function icon(): ?string
    {
        return 'clock-rotate-left';
    }

    public static function contentHtml(): string
    {
        $log = RunLog::default();

        return Craft::$app->getView()->renderTemplate('kunstmaan-migrator/_log', [
            'entries' => $log->entries(100),
            'logPath' => $log->path(),
        ]);
    }
}
