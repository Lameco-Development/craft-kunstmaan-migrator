<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\mapping;

use Lameco\Kunstmaanmigrator\mapping\SetupStep;
use PHPUnit\Framework\TestCase;

/**
 * The steps, declared once.
 *
 * A wizard whose "step 2 of 4" is written into four templates is a wizard that
 * says "3 of 4" on two of them within a month.
 */
final class SetupStepTest extends TestCase
{
    public function testTheStepsRunInOrderAndKnowTheirNumber(): void
    {
        self::assertSame(
            ['detect', 'connect', 'sites', 'locales', 'review'],
            array_map(static fn(SetupStep $s): string => $s->value, SetupStep::all()),
        );

        self::assertSame(1, SetupStep::Detect->number());
        self::assertSame(2, SetupStep::Connect->number());
        self::assertSame(5, SetupStep::Review->number());
    }

    public function testEachStepKnowsWhatComesBeforeAndAfter(): void
    {
        self::assertSame(SetupStep::Sites, SetupStep::Connect->next());
        self::assertSame(SetupStep::Detect, SetupStep::Connect->previous());
        self::assertNull(SetupStep::Detect->previous());
        self::assertSame(SetupStep::Locales, SetupStep::Review->previous());
        self::assertNull(SetupStep::Review->next());
    }

    /**
     * The question is what somebody reads, and it is the thing they will not go
     * looking for in a manual — so every step has one.
     */
    public function testEveryStepAsksAQuestion(): void
    {
        foreach (SetupStep::all() as $step) {
            self::assertNotSame('', $step->title());
            self::assertStringEndsWith('?', $step->question(), $step->value . ' should ask something');
        }
    }

    public function testStepsCanBeComparedForProgress(): void
    {
        self::assertTrue(SetupStep::Connect->isBefore(SetupStep::Review));
        self::assertFalse(SetupStep::Review->isBefore(SetupStep::Connect));
    }
}
