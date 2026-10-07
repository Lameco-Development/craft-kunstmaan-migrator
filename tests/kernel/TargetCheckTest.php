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
                return in_array($handle, ['partnerPage', 'redirectPage'], true);
            }

            public function hasSection(string $handle): bool
            {
                return in_array($handle, ['partners', 'berkvensNlPages', 'newsChannel'], true);
            }

            public function sectionType(string $handle): ?string
            {
                return ['partners' => 'structure', 'berkvensNlPages' => 'structure', 'newsChannel' => 'channel'][$handle] ?? null;
            }

            public function sectionEntryTypes(string $handle): ?array
            {
                return [
                    'partners' => ['partnerPage'],
                    'berkvensNlPages' => ['partnerPage', 'redirectPage'],
                    'newsChannel' => ['partnerPage'],
                ][$handle] ?? null;
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

    /** A mapping whose pages live in `berkvensNlPages`, with the given `defaults:` lines. */
    private function checkStructural(string $defaults): array
    {
        return $this->check(<<<YAML
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            defaults:
            {$defaults}
            pages:
              PartnerPage:
                section: berkvensNlPages
                entryType: partnerPage
                map: { partnerAddress: street }
            YAML);
    }

    #[Test]
    public function a_structural_section_that_accepts_the_structural_entry_type_passes(): void
    {
        self::assertSame([], $this->checkStructural(<<<'YAML'
              structuralSection: berkvensNlPages
              structuralEntryType: redirectPage
            YAML));
    }

    #[Test]
    public function a_missing_structural_section_is_rejected(): void
    {
        self::assertSame(
            ['defaults.structuralSection: no section `xidoorPages` in Craft'],
            $this->checkStructural(<<<'YAML'
                  structuralSection: xidoorPages
                  structuralEntryType: redirectPage
                YAML),
        );
    }

    #[Test]
    public function the_default_structural_section_is_checked_when_placeholders_are_configured(): void
    {
        // No `structuralSection:` means `pages`, the section the compiler will write into.
        self::assertSame(
            ['defaults.structuralSection: no section `pages` in Craft'],
            $this->checkStructural('  structuralEntryType: redirectPage'),
        );
    }

    #[Test]
    public function a_structural_section_that_is_not_a_structure_is_rejected(): void
    {
        self::assertSame(
            ['defaults.structuralSection: section `newsChannel` is a channel, not a structure — a placeholder there cannot parent anything'],
            $this->checkStructural(<<<'YAML'
                  structuralSection: newsChannel
                  structuralEntryType: partnerPage
                YAML),
        );
    }

    #[Test]
    public function a_structural_section_that_does_not_allow_the_entry_type_is_rejected(): void
    {
        self::assertSame(
            ['defaults.structuralEntryType: section `partners` does not allow entry type `redirectPage`'],
            $this->checkStructural(<<<'YAML'
                  structuralSection: partners
                  structuralEntryType: redirectPage
                YAML),
        );
    }

    #[Test]
    public function an_unknown_structural_entry_type_is_rejected(): void
    {
        self::assertSame(
            ['defaults.structuralEntryType: no entry type `folderPage` in Craft'],
            $this->checkStructural(<<<'YAML'
                  structuralSection: berkvensNlPages
                  structuralEntryType: folderPage
                YAML),
        );
    }

    #[Test]
    public function without_a_structural_entry_type_the_structural_section_is_not_checked(): void
    {
        // No entry type, no placeholders: the compiler never writes to the section, so an
        // existing mapping whose target has no `pages` must not start failing here.
        self::assertSame([], $this->checkStructural('  structuralSection: xidoorPages'));
    }
}
