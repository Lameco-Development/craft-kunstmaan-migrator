<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\FormCompiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One legacy part, several form fields.
 *
 * Kunstmaan has a single `Choice` part and renders it as a select, a radio group or a
 * checkbox group depending on `expanded` and `multiple` — the same two flags Symfony's
 * ChoiceType takes. A mapping that names one `type:` for the class turns all three into
 * a dropdown: measured on the Enreach corpus, 36 of 51 live placements were the wrong
 * widget, and the answers a visitor could give changed with them.
 */
final class FormFieldSwitchTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: legacy
            locales: { en: comEnUs }
        forms:
          context: form
          target: formie
          emit: { block: formBlock, field: form }
          fields:
            Choice:
              table: choice_parts
              switch:
                - when: expanded == 1 and multiple == 1 and lines(choices) == 1
                  type: agree
                  map:
                    label:       label
                    description: choices
                - when: expanded == 1 and multiple == 1
                  type: checkboxes
                  map:
                    label:   label
                    options: choices | lines
                - when: expanded == 1
                  type: radio
                  map:
                    label:   label
                    options: choices | lines
                - else: true
                  type: dropdown
                  map:
                    label:   label
                    options: choices | lines
        YAML;

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, deleted INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (pageEntityname TEXT, pageId INTEGER, context TEXT, page_part_entityname TEXT,
                     page_part_id INTEGER, sequencenumber INTEGER)');
        $pdo->exec('CREATE TABLE choice_parts (id INTEGER, label TEXT, choices TEXT, expanded INTEGER, multiple INTEGER)');

        $pdo->exec('INSERT INTO kuma_nodes VALUES (1, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (11, 'App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (21, 1, 'en', 'Contact us', 1, 11)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\ChoicePagePart', 1, 1),
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\ChoicePagePart', 2, 2),
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\ChoicePagePart', 3, 3),
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\ChoicePagePart', 4, 4)");
        $pdo->exec("INSERT INTO choice_parts VALUES
                    (1, 'Aantal gebruikers', '2 - 5" . PHP_EOL . "6 - 20', 0, 0),
                    (2, 'Interesse?', 'Ja" . PHP_EOL . "Nee', 1, 0),
                    (3, 'Welke diensten?', 'Bellen" . PHP_EOL . "Internet', 1, 1),
                    (4, '_', 'Hiermit willige ich ein.', 1, 1)");

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        $out = [];
        (new FormCompiler(Mapping::fromFile($path), new Transforms([])))
            ->compile($this->db(), 'COM', static function(array $record) use (&$out): void {
                $out = $record['fields'];
            });

        return $out;
    }

    #[Test]
    public function the_configuration_picks_the_field_type(): void
    {
        self::assertSame(
            ['dropdown', 'radio', 'checkboxes', 'agree'],
            array_column($this->fields(), 'type'),
        );
    }

    #[Test]
    public function each_case_brings_its_own_map(): void
    {
        $fields = $this->fields();

        self::assertSame(['2 - 5', '6 - 20'], $fields[0]['settings']['options'], 'a select keeps its options');
        self::assertSame(['Ja', 'Nee'], $fields[1]['settings']['options'], 'so does a radio group');
        self::assertSame(
            'Hiermit willige ich ein.',
            $fields[3]['settings']['description'],
            'a consent checkbox carries its sentence as a description instead',
        );
        self::assertArrayNotHasKey('options', $fields[3]['settings']);
    }

    #[Test]
    public function the_labels_still_come_from_the_row(): void
    {
        self::assertSame(
            ['Aantal gebruikers', 'Interesse?', 'Welke diensten?', '_'],
            array_column($this->fields(), 'label'),
        );
    }

    #[Test]
    public function a_switch_with_no_matching_case_contributes_no_field(): void
    {
        // Not a fallback to the first case: a part the mapping did not decide about is a
        // gap to report, and guessing a widget here is what this whole switch exists to stop.
        $yaml = str_replace('- else: true', '- when: expanded == 99', self::MAPPING);
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        $compiler = new FormCompiler(Mapping::fromFile($path), new Transforms([]));
        $out = [];
        $compiler->compile($this->db(), 'COM', static function(array $record) use (&$out): void {
            $out = $record['fields'];
        });

        self::assertSame(['radio', 'checkboxes', 'agree'], array_column($out, 'type'), 'the select is the one no case claims');
        self::assertArrayHasKey('no forms: case for Choice', $compiler->skipped());
    }
}
