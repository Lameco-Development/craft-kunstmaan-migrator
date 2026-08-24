<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\GlobalsCompiler;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `globals:` lane, which the mapping declared and nothing compiled.
 */
final class GlobalsCompilerTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: legacy
            locales: { en: comEnUs, nl: comNlNl }
        globals:
          FooterPage:
            contexts:
              footer-column: { target: 'nav:footerMain' }
              footer-bottom: { target: 'nav:footerBottom' }
              footer-orphan: { target: ~ }
            parts:
              FooterBox:
                table: footer_box_parts
                map:
                  title:     title
                  url:       link
                  newWindow: link_new_window | bool
                children:
                  items:
                    table: footer_items
                    fk: footer_pp_id
                    order: id
                    map:
                      title: text
                      url:   link
              FooterContact:
                table: footer_contact_parts
                drop: "carries only a CTA reference; nothing a nav node could hold"
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
        $pdo->exec('CREATE TABLE footer_box_parts (id INTEGER, title TEXT, link TEXT, link_new_window INTEGER)');
        $pdo->exec('CREATE TABLE footer_items (id INTEGER, footer_pp_id INTEGER, text TEXT, link TEXT)');
        $pdo->exec('CREATE TABLE footer_contact_parts (id INTEGER, call_to_action_block_id INTEGER)');

        // One footer page per locale, which is how Kunstmaan models a site footer.
        $pdo->exec('INSERT INTO kuma_nodes VALUES (1, 0), (2, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (11, 'App\\\\Entity\\\\Pages\\\\FooterPage', 100),
                    (12, 'App\\\\Entity\\\\Pages\\\\FooterPage', 200)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (21, 1, 'en', 'Footer', 1, 11),
                    (22, 2, 'nl', 'Footer', 1, 12)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    ('App\\\\Entity\\\\Pages\\\\FooterPage', 100, 'footer-column', 'App\\\\Entity\\\\PageParts\\\\FooterBoxPagePart', 1, 1),
                    ('App\\\\Entity\\\\Pages\\\\FooterPage', 100, 'footer-bottom', 'App\\\\Entity\\\\PageParts\\\\FooterContactPagePart', 5, 1),
                    ('App\\\\Entity\\\\Pages\\\\FooterPage', 100, 'footer-column', 'App\\\\Entity\\\\PageParts\\\\ContentHighlightPagePart', 7, 2),
                    ('App\\\\Entity\\\\Pages\\\\FooterPage', 200, 'footer-column', 'App\\\\Entity\\\\PageParts\\\\FooterBoxPagePart', 2, 1)");
        $pdo->exec("INSERT INTO footer_box_parts VALUES (1, 'Products', '[NT21]', 0), (2, 'Producten', '[NT22]', 1)");
        $pdo->exec("INSERT INTO footer_items VALUES
                    (31, 1, 'Voice', '/voice'), (32, 1, 'Video', '/video'), (33, 2, 'Spraak', '/spraak')");
        $pdo->exec('INSERT INTO footer_contact_parts VALUES (5, 900)');

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }

    private function mapping(string $yaml): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    /** @return list<array<string, mixed>> */
    private function compile(?GlobalsCompiler &$compiler = null): array
    {
        $compiler = new GlobalsCompiler($this->mapping(self::MAPPING), new Transforms([]));
        $out = [];

        $compiler->compile($this->db(), 'COM', static function(array $record) use (&$out): void {
            $out[] = $record;
        });

        return $out;
    }

    /** One footer per locale, so the same column exists once per locale. */
    #[Test]
    public function each_locale_contributes_its_own_footer(): void
    {
        $locales = array_column($this->compile(), 'locale');
        sort($locales);

        self::assertSame(['en', 'nl'], $locales);
    }

    /**
     * The target is the mapping's decision. This lane spent its whole life with
     * `target: ~` and a note reading "globalSettings fields vs. the navigation
     * plugin"; the answer belongs in the reviewable file, not in PHP.
     */
    #[Test]
    public function the_context_carries_the_target_the_mapping_declared(): void
    {
        foreach ($this->compile() as $record) {
            self::assertSame('nav:footerMain', $record['target']);
        }
    }

    #[Test]
    public function a_box_carries_its_items_as_children_in_order(): void
    {
        $en = array_values(array_filter($this->compile(), static fn(array $r): bool => $r['locale'] === 'en'))[0];

        self::assertSame('Products', $en['title']);
        self::assertSame('[NT21]', $en['url']);
        self::assertSame(['Voice', 'Video'], array_column($en['children'], 'title'));
    }

    /**
     * "We chose not to" and "we did not know how" must not look the same in a
     * run report, so a part the mapping drops is counted under its own reason.
     */
    #[Test]
    public function a_dropped_part_is_distinguishable_from_an_unknown_one(): void
    {
        $compiler = null;
        $this->compile($compiler);

        $skipped = $compiler->skipped();

        self::assertArrayHasKey('FooterContact dropped by the mapping', $skipped);
        self::assertArrayHasKey('no globals: part for ContentHighlight', $skipped);
        self::assertArrayHasKey('footer-orphan has no target', $skipped);
    }

    /** A context with no target is reported rather than guessed at. */
    #[Test]
    public function a_context_without_a_target_emits_nothing(): void
    {
        foreach ($this->compile() as $record) {
            self::assertNotSame('footer-orphan', $record['context']);
        }
    }
}
