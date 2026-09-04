<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\FormCompiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\Schema;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Trello #137: a `Header` pagepart placed in a form context (a "Book a Demo" section title
 * on a PotionsLandingPage) had no `forms: fields:` entry, so `FormCompiler::compile()`
 * silently filtered it — `Header` already had a `parts:` entry (`consumedBy: sequence`, for
 * headings absorbed into a Page Builder block), and the two lanes never met over the same
 * row: `FormCompiler` reads its own `PartReader::sequence()` scoped to the `form` context,
 * `parts` compilation only ever reads `contexts()`, which excludes `form` by construction.
 * The schema's cross-lane collision check did not know that and refused a `Header` entry
 * under both `parts:` and `forms:` as ambiguous — this is what actually blocked the fix,
 * not a missing forms.fields entry.
 */
final class FormsHeaderLaneTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: legacy
            locales: { en: comEnUs }
        pages:
          ContentPage:
            table: content_pages
            section: pages
            entryType: contentPage
        parts:
          Header:
            table: header_page_parts
            consumedBy: sequence
            map:
              title: title
              niv:   niv
        forms:
          context: form
          target: formie
          emit: { block: formBlock, field: form }
          fields:
            Header:
              table: header_page_parts
              type: heading
              map:
                label:       title
                headingSize: niv
            SingleLineText:
              table: single_line_text_parts
              type: singleLineText
              map:
                label:  label
                handle: internal_name
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
        $pdo->exec('CREATE TABLE header_page_parts (id INTEGER, title TEXT, niv TEXT)');
        $pdo->exec('CREATE TABLE single_line_text_parts (id INTEGER, label TEXT, internal_name TEXT)');

        $pdo->exec('INSERT INTO kuma_nodes VALUES (1, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (11, 'App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (21, 1, 'en', 'Contact us', 1, 11)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\HeaderPagePart', 1, 1),
                    ('App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 100, 'form', 'App\\\\Entity\\\\PageParts\\\\SingleLineTextPagePart', 2, 2)");
        $pdo->exec("INSERT INTO header_page_parts VALUES (1, 'Book a Demo', 'h3')");
        $pdo->exec("INSERT INTO single_line_text_parts VALUES (2, 'Email', 'email')");

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }

    #[Test]
    public function header_claimed_by_both_parts_and_forms_is_not_a_schema_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $errors = (new Schema())->validate(Mapping::fromFile($path));

        self::assertSame(
            [],
            array_values(array_filter($errors, static fn(string $e): bool => str_contains($e, 'claimed by both'))),
        );
    }

    #[Test]
    public function a_header_in_the_form_context_compiles_to_a_heading_field(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $mapping = Mapping::fromFile($path);
        $out = [];

        (new FormCompiler($mapping, new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        self::assertCount(1, $out);
        $types = array_column($out[0]['fields'], 'type');
        self::assertSame(['heading', 'singleLineText'], $types);
        self::assertSame('Book a Demo', $out[0]['fields'][0]['label']);
        self::assertSame('h3', $out[0]['fields'][0]['settings']['headingSize']);
    }
}
