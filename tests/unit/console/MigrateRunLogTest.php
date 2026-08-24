<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\console;

use Lameco\Kunstmaanmigrator\console\MigrateController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 2026-08-23 — the run-log utility read only queue runs, so a console
 * `migrate` (how every e2e verification actually runs) left the CP screen
 * empty and the operator wondering whether it was looking at the right
 * database. Lock the source-level contract: the inline path writes the same
 * three RunLog entries its queue-job counterparts do.
 */
final class MigrateRunLogTest extends TestCase
{
    public function testConsoleMigrateTracksTheSameJobsAsTheQueuePath(): void
    {
        $file = (string) (new ReflectionClass(MigrateController::class))->getFileName();
        $source = (string) file_get_contents($file);

        self::assertStringContainsString("RunLog::default()->track('migrate'", $source);
        self::assertStringContainsString("RunLog::default()->track('fixup'", $source);
        self::assertStringContainsString("RunLog::default()->track('finalize'", $source);
    }
}
