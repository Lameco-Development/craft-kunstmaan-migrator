<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    /** @return list<string> */
    private function validate(string $yaml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new Schema())->validate(Mapping::fromFile($path));
    }

    private const SOUND = <<<'YAML'
        version: 1
        environments:
          COM: { database: legacy, locales: { en: comEnUs } }
        parts:
          Text: { block: contentBlock }
        YAML;

    #[Test]
    public function a_sound_mapping_has_no_violations(): void
    {
        self::assertSame([], $this->validate(self::SOUND));
    }

    #[Test]
    public function an_offline_cutoff_that_is_not_a_date_is_rejected(): void
    {
        // The cutoff reaches SQL as a comparison against a stored timestamp. A value the
        // database cannot read as a date does not fail there, it just compares as a string
        // and quietly changes which pages migrate — so it is refused here instead.
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            defaults:
              offlineCutoff: 'march 2026'
            YAML);

        self::assertContains(
            'defaults: `offlineCutoff: march 2026` is not a date the database can compare (expected YYYY-MM-DD)',
            $errors,
        );
    }

    #[Test]
    public function a_page_that_names_a_table_but_says_nothing_about_its_columns_is_rejected(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            pages:
              PartnerPage: { table: partner_pages, entryType: partnerPage }
            YAML);

        self::assertSame(
            ['page `PartnerPage`: names table `partner_pages` but neither maps nor ignores any of its columns'],
            $errors,
        );
    }

    #[Test]
    public function an_empty_ignore_is_a_declaration_that_a_page_table_carries_nothing(): void
    {
        self::assertSame([], $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            pages:
              HomePage: { table: home_pages, entryType: homePage, ignore: [] }
            YAML));
    }

    #[Test]
    public function a_page_without_an_entry_type_is_rejected(): void
    {
        self::assertContains(
            'page `PartnerPage`: no `entryType:`',
            $this->validate(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                pages:
                  PartnerPage: { table: partner_pages, ignore: [] }
                YAML),
        );
    }

    #[Test]
    public function a_mistyped_page_key_is_rejected(): void
    {
        self::assertContains(
            'page `HomePage`: unknown key `entrytype`',
            $this->validate(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                pages:
                  HomePage: { entrytype: homePage, ignore: [] }
                YAML),
        );
    }

    #[Test]
    public function a_part_without_a_disposition_is_rejected(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Text: { live: 10 }
            YAML);

        self::assertCount(1, $errors);
        self::assertStringContainsString('no disposition', $errors[0]);
    }

    #[Test]
    public function conflicting_dispositions_are_rejected(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Text: { block: contentBlock, drop: "also dropped?" }
            YAML);

        self::assertStringContainsString('conflicting dispositions', $errors[0]);
    }

    #[Test]
    public function a_mistyped_key_is_an_error_not_a_warning(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Text: { block: contentBlock, ignoer: [a] }
            YAML);

        self::assertStringContainsString('unknown key `ignoer`', $errors[0]);
    }

    #[Test]
    public function a_child_collection_needs_a_table_and_a_foreign_key(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Feature:
                block: iconCardsBlock
                children:
                  cards: { order: weight }
            YAML);

        self::assertCount(2, $errors);
        self::assertStringContainsString('missing `table:`', $errors[0]);
        self::assertStringContainsString('missing `fk:`', $errors[1]);
    }

    #[Test]
    public function a_sequence_rule_pointing_at_no_rule_is_rejected(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Text: { block: contentBlock }
            sequence:
              - { id: absorb, match: "Header > *", action: absorb, else: typo-here }
            YAML);

        self::assertStringContainsString('`else: typo-here` names no rule', $errors[0]);
    }

    #[Test]
    public function a_class_claimed_by_two_lanes_is_ambiguous(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Email: { block: contentBlock }
            unmapped:
              parts:
                Email: "dead on this corpus"
            YAML);

        self::assertStringContainsString('claimed by both `parts` and `unmapped`', $errors[0]);
    }

    /**
     * `forms` is not really a lane over the same rows: `FormCompiler` reads its own
     * `PartReader::sequence()` scoped to the `form` context, and `parts` compilation only
     * ever reads `contexts()`, which excludes `form` by construction — so the same class,
     * `Header` say, can be a heading absorbed into a Page Builder block wherever it sits in
     * `main`, and independently a Formie heading field wherever it sits in `form`. Trello
     * #137: this collision is what actually blocked adding the second lane, not a missing
     * `forms: fields:` entry.
     */
    #[Test]
    public function a_class_claimed_by_both_parts_and_forms_is_not_ambiguous(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Header: { consumedBy: sequence }
            forms:
              fields:
                Header: { type: heading }
            YAML);

        self::assertSame(
            [],
            array_values(array_filter($errors, static fn(string $e): bool => str_contains($e, 'claimed by both'))),
        );
    }

    #[Test]
    public function a_promoted_collection_needs_a_destination_and_a_relation(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              GoogleMaps:
                block: officesMapBlock
                promote:
                  legacy_markers: { section: offices }
            YAML);

        self::assertCount(2, $errors);
        self::assertStringContainsString('missing `entryType:`', $errors[0]);
        self::assertStringContainsString('missing `relation:`', $errors[1]);
    }

    #[Test]
    public function a_collection_cannot_be_both_promoted_and_a_matrix_child(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              GoogleMaps:
                block: officesMapBlock
                children:
                  legacy_markers: { table: legacy_markers, fk: gm_pp_id }
                promote:
                  legacy_markers: { section: offices, entryType: office, relation: commonOffices }
            YAML);

        self::assertStringContainsString('both promoted and a Matrix child', $errors[0]);
    }

    #[Test]
    public function an_environment_without_locales_writes_nowhere(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy }
            parts:
              Text: { block: contentBlock }
            YAML);

        self::assertStringContainsString('no `locales:`', $errors[0]);
    }

    #[Test]
    public function an_unreviewed_column_blocks_the_mapping(): void
    {
        // The generator lists what it could not place. A file that still carries those entries is
        // a draft, and saying so here is what stops generator output reading as a decision.
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                unreviewed: [background_color, tag]
            YAML);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('2 columns still unreviewed', implode("\n", $errors));
    }

    #[Test]
    public function an_ignore_with_reasons_is_accepted(): void
    {
        self::assertSame([], $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                ignore:
                  background_color: "design-driven, no Craft equivalent"
            YAML));
    }
}
