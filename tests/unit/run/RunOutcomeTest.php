<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\run;

use lameco\kunstmaanmigrator\run\RunOutcome;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * The exit code is the only part of a run most callers read.
 *
 * The default has to stay 0-on-loss — every real corpus loses something, and a migrator that
 * always fails is one nobody reads the output of. What matters is that `--fail-on-loss` is
 * honest when it is asked for, and that a genuine failure is never downgraded by it.
 */
final class RunOutcomeTest extends TestCase
{
    public function testACleanRunIsOk(): void
    {
        self::assertSame(ExitCode::OK, RunOutcome::exitCode(false, 0, false, 0, 0, 0));
        self::assertSame(ExitCode::OK, RunOutcome::exitCode(false, 0, true, 0, 0, 0));
    }

    public function testLossesDoNotFailARunByDefault(): void
    {
        self::assertSame(ExitCode::OK, RunOutcome::exitCode(false, 0, false, 1030, 483, 107));
    }

    public function testFailOnLossTurnsEachKindOfLossIntoAFailure(): void
    {
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, RunOutcome::exitCode(false, 0, true, 1, 0, 0));
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, RunOutcome::exitCode(false, 0, true, 0, 1, 0));
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, RunOutcome::exitCode(false, 0, true, 0, 0, 1));
    }

    public function testAWriteFailureFailsWhateverTheLossFlagSays(): void
    {
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, RunOutcome::exitCode(true, 0, false, 0, 0, 0));
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, RunOutcome::exitCode(false, 3, false, 0, 0, 0));
    }

    public function testLostCountsAnyOfTheThree(): void
    {
        self::assertFalse(RunOutcome::lost(0, 0, 0));
        self::assertTrue(RunOutcome::lost(0, 0, 5));
    }
}
