<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\run\Diagnostics;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryNavigationGateway;
use PHPUnit\Framework\TestCase;

/**
 * A legacy menu mapped to a nav that does not exist is skipped at run time with
 * a warning buried in the report. `doctor` says so before the run: the map is
 * configured, so a missing target is a misconfiguration, not an absence.
 */
final class DiagnosticsMenuHandlesTest extends TestCase
{
    public function testNoMapMeansNoRow(): void
    {
        self::assertSame([], Diagnostics::menuHandleChecks([], new InMemoryNavigationGateway()));
    }

    public function testEveryMappedNavExistingIsGreen(): void
    {
        $checks = Diagnostics::menuHandleChecks(
            ['top' => 'berkvensNlTop', 'main' => 'berkvensNlMain'],
            new InMemoryNavigationGateway(['berkvensNlTop' => 1, 'berkvensNlMain' => 2]),
        );

        self::assertSame(['navigation_menu_handles'], array_column($checks, 'check'));
        self::assertTrue($checks[0]['ok']);
    }

    public function testAMappedHandleWithNoNavFailsAndIsNamed(): void
    {
        $checks = Diagnostics::menuHandleChecks(
            ['top' => 'berkvensNlTop', 'main' => 'berkvensNlMain'],
            new InMemoryNavigationGateway(['berkvensNlTop' => 1]),
        );

        self::assertCount(1, $checks);
        self::assertFalse($checks[0]['ok']);
        self::assertStringContainsString('main → berkvensNlMain', $checks[0]['detail']);
        self::assertStringNotContainsString('berkvensNlTop', $checks[0]['detail']);
    }
}
