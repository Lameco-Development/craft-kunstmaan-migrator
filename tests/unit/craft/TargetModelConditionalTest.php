<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\TargetModel;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The live schema says what project config says: a field under a layout element condition is
 * required only where the condition holds, so `readiness` against a live site agrees with
 * `readiness --craft`.
 */
final class TargetModelConditionalTest extends TestCase
{
    #[Test]
    public function a_conditional_placement_reaches_the_slot_and_an_unreported_one_is_not(): void
    {
        $model = new TargetModel(new class() implements SchemaGateway {
            public function sectionByHandle(string $h): ?array
            {
                return null;
            }
            public function entryTypeByHandle(string $h): ?array
            {
                return null;
            }
            public function primarySite(): array
            {
                return ['id' => 1, 'handle' => 'nl'];
            }
            public function siteByHandle(string $h): ?array
            {
                return null;
            }
            public function fieldHandlesFor(string $t): array
            {
                return [];
            }
            public function blockTypesFor(string $t, string $f): array
            {
                return [];
            }
            public function fieldSlotsFor(string $entryTypeHandle): array
            {
                return [
                    'heroSlides' => ['type' => 'Assets', 'required' => true, 'nested' => [], 'conditional' => true],
                    // A gateway that does not report conditions: unconditional, as before.
                    'heroTitle' => ['type' => 'PlainText', 'required' => true, 'nested' => []],
                ];
            }
        });

        self::assertTrue($model->slot('berkvensNlContentPage', 'heroSlides')?->conditional);
        self::assertFalse($model->slot('berkvensNlContentPage', 'heroTitle')?->conditional);
    }
}
