<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\TargetModel;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The live answer to "is this section a structure, and which entry types does it allow" —
 * what `validate` asks of `defaults.structuralSection` before any placeholder is written there.
 */
final class TargetModelSectionTest extends TestCase
{
    private function model(): TargetModel
    {
        return new TargetModel(new class() implements SchemaGateway {
            public function sectionByHandle(string $h): ?array
            {
                return match ($h) {
                    'folders' => ['id' => 1, 'handle' => 'folders', 'type' => 'structure', 'entryTypes' => ['redirectPage', 'contentPage']],
                    // A gateway that does not report the detail: unknown, not wrong.
                    'legacy' => ['id' => 2, 'handle' => 'legacy'],
                    default => null,
                };
            }
            public function entryTypeByHandle(string $h): ?array
            {
                return null;
            }
            public function primarySite(): array
            {
                return ['id' => 11, 'handle' => 'en'];
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
                return [];
            }
        });
    }

    #[Test]
    public function a_section_reports_its_type_and_allowed_entry_types(): void
    {
        self::assertSame('structure', $this->model()->sectionType('folders'));
        self::assertSame(['redirectPage', 'contentPage'], $this->model()->sectionEntryTypes('folders'));
    }

    #[Test]
    public function a_section_the_gateway_says_nothing_about_answers_unknown(): void
    {
        self::assertNull($this->model()->sectionType('legacy'));
        self::assertNull($this->model()->sectionEntryTypes('legacy'));
        self::assertNull($this->model()->sectionType('nope'));
        self::assertNull($this->model()->sectionEntryTypes('nope'));
    }
}
