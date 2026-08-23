<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Target\Slot;
use Lameco\KumaCompile\Target\SpecNotes;
use Lameco\KumaCompile\Target\Suggester;
use Lameco\KumaCompile\Target\TargetSchema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A fresh mapping opens as a reviewed-nothing draft, not sixty empty rows: the specs
 * already say which parts each block covers and which property becomes which field.
 * A draft is not a decision — leftover columns stay `unreviewed`, the spec's own
 * drops become reasoned ignores, and a decided row is never drafted over.
 */
final class SuggesterPrefillTest extends TestCase
{
    private function schema(): TargetSchema
    {
        return new class implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types = [
                'demoBlock' => [],
            ];

            public function __construct()
            {
                $this->types['demoBlock'] = [
                    'heading' => new Slot('heading', 'CKEditor', false),
                    'colorScheme' => new Slot('colorScheme', 'Dropdown', false),
                ];
            }

            public function hasEntryType(string $handle): bool
            {
                return isset($this->types[$handle]);
            }

            public function hasSection(string $handle): bool
            {
                return true;
            }

            public function slots(string $entryType): array
            {
                return $this->types[$entryType] ?? [];
            }

            public function slot(string $entryType, string $field): ?Slot
            {
                return $this->slots($entryType)[$field] ?? null;
            }

            public function requiredFields(string $entryType): array
            {
                return [];
            }

            public function pathFor(string $entryType, string $field): ?string
            {
                return $this->slot($entryType, $field) !== null ? '' : null;
            }

            public function nestedTypeOf(string $entryType, string $field): ?string
            {
                return null;
            }
        };
    }

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE demo_page_parts (id INTEGER, title TEXT, background_color TEXT, niv TEXT, mystery TEXT)');

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    private function mapping(): Mapping
    {
        return Mapping::fromArray([
            'version' => 1,
            'environments' => ['COM' => ['database' => 'com', 'locales' => ['en' => 'comEnUs']]],
            'parts' => [
                'Demo' => ['table' => 'demo_page_parts', 'unreviewed' => ['title', 'background_color', 'niv', 'mystery']],
                'Decided' => ['table' => 'demo_page_parts', 'block' => 'demoBlock', 'map' => ['heading' => 'title']],
                'Unknown' => ['table' => 'demo_page_parts'],
            ],
        ]);
    }

    #[Test]
    public function an_undecided_part_is_drafted_from_its_spec(): void
    {
        $notes = SpecNotes::fromDirectory(__DIR__ . '/fixtures/specs');
        $result = (new Suggester($notes, $this->schema()))->prefill($this->mapping(), $this->db());

        // `Demo` is named by demoBlock.md's notes table; `Decided` already has a
        // block and is left alone; `Unknown` is named by no spec.
        self::assertSame(['Demo'], array_keys($result['drafted']));
        self::assertArrayHasKey('Unknown', $result['skipped']);

        $patch = $result['drafted']['Demo'];
        self::assertSame('demoBlock', $patch['block']);
        self::assertSame([
            'heading' => 'title | inlineHtml',
            'colorScheme' => 'background_color | colorScheme',
        ], $patch['map']);

        // The spec's own drop is a decision, applied as one; the column no spec
        // mentions stays owed a decision.
        self::assertSame(['niv' => 'the spec drops it (demoBlock.md)'], $patch['ignore']);
        self::assertSame(['mystery'], $patch['unreviewed']);
    }
}
