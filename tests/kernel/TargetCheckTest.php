<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\TargetCheck;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetCheckTest extends TestCase
{
    /** A content model with one entry type carrying one field. */
    private function schema(): TargetSchema
    {
        return new class() implements TargetSchema {
            /** @var array<string, Slot> */
            private array $slots;

            public function __construct()
            {
                $this->slots = ['partnerAddress' => new Slot('partnerAddress', 'PlainText', false)];
            }

            public function hasEntryType(string $handle): bool
            {
                return $handle === 'partnerPage';
            }

            public function hasSection(string $handle): bool
            {
                return $handle === 'partners';
            }

            public function slots(string $entryType): array
            {
                return $this->hasEntryType($entryType) ? $this->slots : [];
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

    /** @return list<string> */
    private function check(string $yaml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new TargetCheck($this->schema()))->check(Mapping::fromFile($path));
    }

    #[Test]
    public function a_page_mapping_a_field_the_entry_type_does_not_have_is_rejected(): void
    {
        self::assertSame(
            ['page `PartnerPage`: entry type `partnerPage` has no field `postalCode`'],
            $this->check(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                pages:
                  PartnerPage:
                    section: partners
                    entryType: partnerPage
                    map: { postalCode: postal_code }
                YAML),
        );
    }

    #[Test]
    public function a_page_mapping_only_fields_that_exist_passes(): void
    {
        self::assertSame([], $this->check(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            pages:
              PartnerPage:
                section: partners
                entryType: partnerPage
                map: { partnerAddress: street }
            YAML));
    }

    #[Test]
    public function a_page_with_no_section_is_checked_against_the_one_the_compiler_uses(): void
    {
        // The compiler writes a page with no `section:` into `pages`; a check that skipped the
        // absent key let that fail at the loader instead of here.
        self::assertSame(
            ['page `PartnerPage`: no section `pages` in Craft'],
            $this->check(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                pages:
                  PartnerPage:
                    entryType: partnerPage
                    map: { partnerAddress: street }
                YAML),
        );
    }

    #[Test]
    public function an_unknown_entry_type_is_reported_once_not_once_per_field(): void
    {
        self::assertSame(
            ['page `NewsPage`: no entry type `newsPage` in Craft'],
            $this->check(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                pages:
                  NewsPage:
                    section: partners
                    entryType: newsPage
                    map: { intro: intro, body: body }
                YAML),
        );
    }
}
