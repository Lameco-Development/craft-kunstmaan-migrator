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

    /**
     * A block with a plain-text field and a Matrix whose nested type has one too — the shape
     * `ContentMediaTabbed` has, where the tab's `text` is PlainText.
     */
    private function richTextSchema(): TargetSchema
    {
        return new class() implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types;

            public function __construct()
            {
                $this->types = [
                    'tabbedContentMediaBlock' => [
                        'heading' => new Slot('heading', 'CKEditor', false),
                        'summary' => new Slot('summary', 'PlainText', false),
                        'tabs' => new Slot('tabs', 'Matrix', false, ['tabbedContentMediaTab']),
                    ],
                    'tabbedContentMediaTab' => [
                        'text' => new Slot('text', 'PlainText', false),
                        'body' => new Slot('body', 'CKEditor', false),
                    ],
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
                return $this->slot($entryType, $field)?->isMatrix() === true ? 'tabbedContentMediaTab' : null;
            }
        };
    }

    /** @return list<string> */
    private function htmlWarnings(string $yaml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new TargetCheck($this->richTextSchema()))->htmlIntoPlainText(Mapping::fromFile($path));
    }

    /**
     * `ckeditor` keeps the markup; a PlainText field has nowhere to render it, so the tags
     * reach the page as text. Measured on the reference corpus: 60 live `ContentMediaTabbed`
     * placements sent `content | ckeditor` into `tabbedContentMediaTab.text`, and the site
     * showed a literal `<p>` in front of the copy. The mapping states the rule itself
     * elsewhere — `uspBlockUsp.text` is flattened with `inlineHtml` "because a rich target
     * would not need the flattening" — so this is the check that keeps the two consistent.
     */
    #[Test]
    public function rich_text_piped_into_a_plain_text_field_is_warned_about(): void
    {
        $warnings = $this->htmlWarnings(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              ContentMediaTabbed:
                block: tabbedContentMediaBlock
                children:
                  tabs:
                    table: tabbed_items
                    map:
                      text: content | ckeditor
            YAML);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('tabbedContentMediaTab.text', $warnings[0]);
        self::assertStringContainsString('inlineHtml', $warnings[0]);
    }

    #[Test]
    public function rich_text_into_a_rich_text_field_is_fine(): void
    {
        self::assertSame([], $this->htmlWarnings(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              ContentMediaTabbed:
                block: tabbedContentMediaBlock
                children:
                  tabs:
                    table: tabbed_items
                    map:
                      body: content | ckeditor
            YAML));
    }

    #[Test]
    public function a_flattened_transform_into_a_plain_text_field_is_fine(): void
    {
        self::assertSame([], $this->htmlWarnings(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              ContentMediaTabbed:
                block: tabbedContentMediaBlock
                children:
                  tabs:
                    table: tabbed_items
                    map:
                      text: content | inlineHtml
            YAML));
    }

    #[Test]
    public function a_block_level_plain_text_field_is_checked_too(): void
    {
        $warnings = $this->htmlWarnings(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              ContentMediaTabbed:
                block: tabbedContentMediaBlock
                map:
                  summary: intro | ckeditor
                  heading: title | ckeditor
            YAML);

        self::assertCount(1, $warnings, 'the CKEditor heading is fine; only the PlainText summary is not');
        self::assertStringContainsString('tabbedContentMediaBlock.summary', $warnings[0]);
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
