<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Trello #215: `casePage` has no Page Builder (D29) — the narrative lives in `main`'s
 * pagepart sequence like any other page, but `contexts:` streams a sequence into Matrix
 * blocks, and `casePage.pageBuilder` does not exist. Every part in `main` was silently
 * dropped: `Compiler::site()` already special-cased "casePage and partnerPage carry their
 * own structured fields instead" in a comment, without doing it.
 *
 * `prose:` reads the same ordered sequence and concatenates it into one HTML string for a
 * plain field instead: `Header` folds into `<h#>`, `Text` contributes its own
 * `content | ckeditor`. A part class neither of those is skipped and counted, same as a
 * block type a field disallows.
 */
final class ProsePagesCompileTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs }
        pages:
          CasePage:
            table: case_pages
            section: pages
            entryType: casePage
            prose:
              main: body
        parts:
          Text:
            table: text_parts
            map:
              content: content | ckeditor
          Header:
            table: header_parts
            consumedBy: sequence
            map:
              title: title | inlineHtml
              niv:   niv | titleLevel
        YAML;

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (id INTEGER, pageId INTEGER, pageEntityname TEXT, context TEXT,
                     sequencenumber INTEGER, page_part_id INTEGER, page_part_entityname TEXT)');
        $pdo->exec('CREATE TABLE case_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE text_parts (id INTEGER, content TEXT)');
        $pdo->exec('CREATE TABLE header_parts (id INTEGER, title TEXT, niv TEXT)');
        $pdo->exec('CREATE TABLE cta_parts (id INTEGER, label TEXT)');

        $pdo->exec("INSERT INTO kuma_nodes VALUES (17, NULL, 0, 1, 'App\\Entity\\Pages\\CasePage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (91, 'App\\Entity\\Pages\\CasePage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (4, 17, 'en', 'Ibstock Junior School', 'ibstock', NULL, NULL, 1, 91)");
        $pdo->exec('INSERT INTO case_pages VALUES (100)');

        // Text, then a Header (title pre-wrapped in <p>, as Kunstmaan's own widget stores
        // it), then another Text, then a part class prose: has no rendering for at all —
        // exactly the CallToActionPagePart the real corpus carries in this same sequence.
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, 'App\\\\Entity\\\\Pages\\\\CasePage', 'main', 1, 1, 'App\\\\Entity\\\\PageParts\\\\TextPagePart'),
                    (2, 100, 'App\\\\Entity\\\\Pages\\\\CasePage', 'main', 2, 1, 'App\\\\Entity\\\\PageParts\\\\HeaderPagePart'),
                    (3, 100, 'App\\\\Entity\\\\Pages\\\\CasePage', 'main', 3, 2, 'App\\\\Entity\\\\PageParts\\\\TextPagePart'),
                    (4, 100, 'App\\\\Entity\\\\Pages\\\\CasePage', 'main', 4, 1, 'App\\\\Entity\\\\PageParts\\\\CallToActionPagePart')");
        $pdo->exec("INSERT INTO text_parts VALUES
                    (1, '<p>Peaks around rush hour and long hold times.</p>'),
                    (2, '<p>What we built together.</p>')");
        $pdo->exec("INSERT INTO header_parts VALUES (1, '<p>The approach</p>', 'h2')");
        $pdo->exec("INSERT INTO cta_parts VALUES (1, 'Book a demo')");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return array{0: array<string, mixed>, 1: Compiler} the one compiled entry, and the compiler that made it */
    private function compile(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $out = [];

        $compiler = new Compiler(Mapping::fromFile($path), new Transforms());
        $compiler->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        self::assertCount(1, $out);

        return [$out[0], $compiler];
    }

    #[Test]
    public function a_header_and_two_text_parts_concatenate_into_one_field_in_sequence_order(): void
    {
        [$entry] = $this->compile();
        $body = $entry['sites']['comEnUs']['fieldValues']['body'] ?? null;

        self::assertSame(
            '<p>Peaks around rush hour and long hold times.</p>'
            . '<h2>The approach</h2>'
            . '<p>What we built together.</p>',
            $body,
        );
    }

    #[Test]
    public function a_part_class_prose_cannot_render_is_skipped_and_counted_not_silently_dropped(): void
    {
        [, $compiler] = $this->compile();
        $skipped = $compiler->skipped();

        self::assertNotEmpty(array_filter(
            array_keys($skipped),
            static fn(string $reason): bool => str_contains($reason, 'CallToAction') && str_contains($reason, 'body'),
        ));
    }

    #[Test]
    public function a_header_title_wrapped_in_a_paragraph_does_not_nest_a_p_inside_the_h_tag(): void
    {
        [$entry] = $this->compile();
        $body = (string) ($entry['sites']['comEnUs']['fieldValues']['body'] ?? '');

        self::assertStringContainsString('<h2>The approach</h2>', $body);
        self::assertStringNotContainsString('<h2><p>', $body);
    }
}
