<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\BlockBuilder;
use Lameco\KumaCompile\Compile\EntityIndex;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\PartReader;
use Lameco\KumaCompile\Target\Slot;
use Lameco\KumaCompile\Target\TargetSchema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `link()` used to emit `[['url' => …]]` whatever it was aimed at. A Craft Link field reads
 * `value` from one map, and a Matrix reads a list of blocks — so both targets discarded it
 * silently. Measured on the Enreach corpus: 126 link placements emitted, none stored.
 */
final class LinkShapeTest extends TestCase
{
    private function schema(): TargetSchema
    {
        return new class implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types = [];

            public function __construct()
            {
                $this->types = [
                    'contentMediaBlock' => [
                        'buttons' => new Slot('buttons', 'Matrix', false, ['button']),
                    ],
                    'button' => [
                        'commonLink' => new Slot('commonLink', 'Link', false),
                        'commonButtonType' => new Slot('commonButtonType', 'Dropdown', false),
                    ],
                    'uspBlockUsp' => [
                        'link' => new Slot('link', 'Link', false),
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
                $slot = $this->slot($entryType, $field);

                return $slot !== null && count($slot->nested) === 1 ? $slot->nested[0] : null;
            }
        };
    }

    private function builder(string $block): BlockBuilder
    {
        return new BlockBuilder(
            new PartReader(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])),
            new Transforms(),
            'COM',
            $this->schema(),
            $block,
            null,
            // node translation 110 belongs to node 42.
            new EntityIndex([], [110 => 42]),
        );
    }

    #[Test]
    public function a_link_field_takes_one_map_keyed_value(): void
    {
        self::assertSame(
            ['link' => ['value' => 'https://example.com/x', 'label' => 'Find out more', 'target' => '_blank']],
            $this->builder('uspBlockUsp')->fieldsFrom(
                ['link' => 'link(link_url, link_text, link_new_window)'],
                ['link_url' => 'https://example.com/x', 'link_text' => 'Find out more', 'link_new_window' => 1],
                'Usp',
            ),
        );
    }

    #[Test]
    public function an_internal_link_becomes_a_ref_for_the_loader_to_resolve(): void
    {
        // `[NT110]` addresses a node *translation*; the node is what becomes an entry.
        self::assertSame(
            ['link' => ['_linkRef' => 'kuma:COM:kuma_nodes:42', 'label' => 'Read more']],
            $this->builder('uspBlockUsp')->fieldsFrom(
                ['link' => 'link(link_url, link_text)'],
                ['link_url' => '[NT110]', 'link_text' => 'Read more'],
                'Usp',
            ),
        );
    }

    #[Test]
    public function a_bare_email_address_is_typed_as_one(): void
    {
        // Craft only sniffs the link type from a bare string. A map — the only way to carry a
        // label — defaults to `url`, so an untyped address fails validation and takes the whole
        // entry with it.
        self::assertSame(
            ['link' => ['type' => 'email', 'value' => 'mailto:sales.sp@enreach.com', 'label' => 'Book a meeting']],
            $this->builder('uspBlockUsp')->fieldsFrom(
                ['link' => 'link(link_url, link_text)'],
                ['link_url' => 'sales.sp@enreach.com', 'link_text' => 'Book a meeting'],
                'Usp',
            ),
        );
    }

    #[Test]
    public function a_column_that_already_carries_its_scheme_keeps_it(): void
    {
        self::assertSame(
            ['link' => ['type' => 'tel', 'value' => 'tel:+31612345678']],
            $this->builder('uspBlockUsp')->fieldsFrom(
                ['link' => 'link(link_url, link_text)'],
                ['link_url' => 'tel:+31612345678', 'link_text' => ''],
                'Usp',
            ),
        );
    }

    #[Test]
    public function a_matrix_target_gets_a_button_block_with_the_link_on_the_nested_type(): void
    {
        self::assertSame(
            ['buttons' => [[
                'type' => 'button',
                'fields' => [
                    'commonLink' => ['value' => 'https://example.com/y', 'label' => 'Se mere'],
                    'commonButtonType' => 'primary',
                ],
            ]]],
            $this->builder('contentMediaBlock')->fieldsFrom(
                ['buttons' => 'link(link_url, link_text, link_new_window, link_type)'],
                ['link_url' => 'https://example.com/y', 'link_text' => 'Se mere', 'link_new_window' => 0, 'link_type' => 'primary'],
                'ContentMedia',
            ),
        );
    }

    #[Test]
    public function a_row_with_no_url_produces_no_button_at_all(): void
    {
        // Otherwise a legacy row an editor left half-filled becomes a button with a style and
        // nowhere to go — which is what 92% of the migrated buttons were.
        self::assertSame(
            [],
            $this->builder('contentMediaBlock')->fieldsFrom(
                ['buttons' => 'link(link_url, link_text, link_new_window, link_type)'],
                ['link_url' => '  ', 'link_text' => 'Se mere', 'link_new_window' => 0, 'link_type' => 'primary'],
                'ContentMedia',
            ),
        );
    }

    #[Test]
    public function a_button_style_the_row_does_not_carry_is_left_off(): void
    {
        self::assertSame(
            ['buttons' => [[
                'type' => 'button',
                'fields' => ['commonLink' => ['value' => 'https://example.com/z']],
            ]]],
            $this->builder('contentMediaBlock')->fieldsFrom(
                ['buttons' => 'link(link_url, link_text, link_new_window, link_type)'],
                ['link_url' => 'https://example.com/z', 'link_text' => '', 'link_new_window' => 0, 'link_type' => null],
                'ContentMedia',
            ),
        );
    }
}
