<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\TargetModel;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AllowedBlocksTest extends TestCase
{
    private function model(): TargetModel
    {
        return new TargetModel(new class() implements SchemaGateway {
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
                return match ($entryTypeHandle) {
                    // contentPage takes everything; blogPage a deliberately narrower subset.
                    'contentPage' => ['pageBuilder' => ['type' => 'Matrix', 'required' => false,
                        'nested' => ['contentBlock', 'contactCardBlock', 'uspBlock'], ]],
                    'blogPage' => ['pageBuilder' => ['type' => 'Matrix', 'required' => false,
                        'nested' => ['contentBlock'], ]],
                    // casePage carries structured fields instead of a builder.
                    'casePage' => ['caseStats' => ['type' => 'Matrix', 'required' => false, 'nested' => ['caseStat']]],
                    default => [],
                };
            }
        });
    }

    #[Test]
    public function a_builder_reports_the_blocks_it_accepts(): void
    {
        self::assertSame(
            ['contentBlock', 'contactCardBlock', 'uspBlock'],
            $this->model()->slot('contentPage', 'pageBuilder')?->nested,
        );
        self::assertSame(['contentBlock'], $this->model()->slot('blogPage', 'pageBuilder')?->nested);
    }

    #[Test]
    public function an_entry_type_without_a_builder_reports_no_slot(): void
    {
        // Emitting a pageBuilder here makes Craft reject the whole entry, so "absent" has to
        // be distinguishable from "accepts anything".
        self::assertNull($this->model()->slot('casePage', 'pageBuilder'));
    }

    #[Test]
    public function nested_type_resolution_is_unambiguous_or_null(): void
    {
        self::assertSame('caseStat', $this->model()->nestedTypeOf('casePage', 'caseStats'));
        self::assertNull($this->model()->nestedTypeOf('contentPage', 'pageBuilder'), 'three choices, no single answer');
    }
}
