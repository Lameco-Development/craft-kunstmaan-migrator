<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\MappingCheck;
use Lameco\KumaCompile\Target\Slot;
use Lameco\KumaCompile\Target\TargetSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One verdict for three renderers: the CLI check, the migrate preflight and
 * the CP button must refuse for the same reason in the same words.
 */
final class MappingCheckTest extends TestCase
{
    /** @return array{0: string, 1: list<string>}|null */
    private function verdict(string $yaml): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        $schema = new class() implements TargetSchema {
            public function hasEntryType(string $handle): bool
            {
                return $handle === 'contentPage';
            }

            public function hasSection(string $handle): bool
            {
                return $handle === 'pages';
            }

            public function slots(string $entryType): array
            {
                return $this->hasEntryType($entryType) ? ['summary' => new Slot('summary', 'PlainText', false)] : [];
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

        return (new MappingCheck($schema))->verdict(Mapping::fromFile($path));
    }

    #[Test]
    public function shape_is_judged_before_the_target(): void
    {
        // The entry type is also wrong for this install — but the malformed
        // shape must win, because target errors on a malformed file mislead.
        $verdict = $this->verdict(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            pages:
              ContentPage: { entryType: nopePage, ignore: [], bogus: nope }
            YAML);

        self::assertNotNull($verdict);
        self::assertSame('Mapping is not well-formed', $verdict[0]);
    }

    #[Test]
    public function a_clean_mapping_may_run(): void
    {
        self::assertNull($this->verdict(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            pages:
              ContentPage: { entryType: contentPage, ignore: [] }
            YAML));
    }
}
