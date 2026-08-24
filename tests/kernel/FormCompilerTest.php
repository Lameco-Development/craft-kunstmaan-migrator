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
 * The `forms:` lane, which the mapping declared and nothing compiled.
 */
final class FormCompilerTest extends TestCase
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
            SingleLineText:
              table: single_line_text_parts
              type: singleLineText
              map:
                label:    label
                handle:   internal_name
                required: required | bool
            SubmitButton:
              table: submit_button_parts
              type: submitButton
        YAML;

    /**
     * Two form-owning pages: one live, one whose node was deleted. Plus a layout
     * bracket the lane does not map, because every real corpus is full of them.
     */
    private function db(): LegacyDatabase
    {
        // SQLite keeps backslashes in a string literal literally where MySQL
        // unescapes them, so the fixture writes the doubled form — which is what
        // PartReader's `LIKE '%\\<Entity>'` matches under this driver, and what
        // a single backslash means to MySQL in production.
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, deleted INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (pageEntityname TEXT, pageId INTEGER, context TEXT, page_part_entityname TEXT,
                     page_part_id INTEGER, sequencenumber INTEGER)');
        $pdo->exec('CREATE TABLE single_line_text_parts (id INTEGER, label TEXT, internal_name TEXT, required INTEGER)');
        $pdo->exec('CREATE TABLE submit_button_parts (id INTEGER, label TEXT)');

        $pdo->exec('INSERT INTO kuma_nodes VALUES (1, 0), (2, 1)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (11, 'App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100),
                    (12, 'App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 200)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (21, 1, 'en', 'Contact us', 1, 11),
                    (22, 2, 'en', 'Deleted page', 1, 12)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\SingleLineTextPagePart', 1, 1),
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\RowStartPagePart', 9, 2),
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\SubmitButtonPagePart', 2, 3),
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 200, 'form', 'App\\\\Entity\\\\PageParts\\\\SingleLineTextPagePart', 3, 1)");
        $pdo->exec("INSERT INTO single_line_text_parts VALUES (1, 'First name', 'firstname', 1), (3, 'Ghost', 'ghost', 0)");
        $pdo->exec("INSERT INTO submit_button_parts VALUES (2, 'Send')");

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }

    private function mapping(string $yaml): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    /** @return list<array<string, mixed>> */
    private function compile(?FormCompiler &$compiler = null): array
    {
        $compiler = new FormCompiler($this->mapping(self::MAPPING), new Transforms([]));
        $out = [];

        $compiler->compile($this->db(), 'COM', static function(array $record) use (&$out): void {
            $out[] = $record;
        });

        return $out;
    }

    #[Test]
    public function one_live_page_becomes_one_form(): void
    {
        $forms = $this->compile();

        self::assertCount(1, $forms);
        self::assertSame('kuma:COM:form:PotionsLandingPage:100', $forms[0]['sourceUid']);
    }

    /**
     * The part refs outlive the page. Taking the raw list gave 745 forms on the
     * real COM corpus where 495 placements are live — 250 Formie forms for pages
     * nobody can reach makes the control panel worse, not better.
     */
    #[Test]
    public function a_deleted_page_contributes_no_form(): void
    {
        foreach ($this->compile() as $form) {
            self::assertNotSame('kuma:COM:form:PotionsLandingPage:200', $form['sourceUid']);
        }
    }

    /** The title is what an editor will see in Formie's list, so it comes from the page. */
    #[Test]
    public function the_form_is_named_after_the_page(): void
    {
        self::assertSame('Contact us', $this->compile()[0]['title']);
    }

    #[Test]
    public function fields_keep_their_sequence_and_their_mapped_values(): void
    {
        $fields = $this->compile()[0]['fields'];

        self::assertSame(['singleLineText', 'submitButton'], array_column($fields, 'type'));
        self::assertSame('First name', $fields[0]['label']);
        self::assertSame('firstname', $fields[0]['handle']);
        self::assertTrue($fields[0]['required']);
    }

    /**
     * `RowStart` is a layout bracket the mapping declares as unmapped on
     * purpose. Counting it beats warning about it — a run that complains about
     * every deliberate omission teaches people to stop reading the warnings.
     */
    #[Test]
    public function an_unmapped_part_is_counted_rather_than_dropped_silently(): void
    {
        $compiler = null;
        $this->compile($compiler);

        self::assertSame(['no forms: field for RowStart' => 1], $compiler->skipped());
    }

    #[Test]
    public function a_mapping_with_no_forms_lane_compiles_nothing(): void
    {
        $compiler = new FormCompiler($this->mapping("version: 1\nenvironments: {}\n"), new Transforms([]));
        $seen = 0;

        $compiler->compile($this->db(), 'COM', static function() use (&$seen): void {
            $seen++;
        });

        self::assertSame(0, $seen);
    }
}
