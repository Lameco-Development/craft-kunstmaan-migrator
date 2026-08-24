<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\Compiler;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Kunstmaan URL is the slug chain of a node's ancestors, and an ancestor earns its segment
 * whether or not it is published and whether or not it becomes content. The tree below is the
 * shape that broke the first real corpus: a `RedirectPage` section landing node (28) with a
 * published child, and an offline `CategoryPage` (3) with a published child. Neither becomes
 * an entry, so without placeholders both children re-root and lose a path segment.
 */
final class StructuralAncestorTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs, nl: comNlNl }
        defaults:
          structuralEntryType: contentPage
        pages:
          HomePage:
            table: home_pages
            section: homePage
            entryType: homePage
          CasePage:
            table: case_pages
            section: pages
            entryType: casePage
        unmapped:
          pageTypes:
            RedirectPage: "handled by the redirects lane"
        YAML;

    /** The same mapping with no structural entry type declared. */
    private const MAPPING_WITHOUT_TYPE = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs, nl: comNlNl }
        pages:
          HomePage:
            table: home_pages
            section: homePage
            entryType: homePage
          CasePage:
            table: case_pages
            section: pages
            entryType: casePage
        unmapped:
          pageTypes:
            RedirectPage: "handled by the redirects lane"
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
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER)');
        $pdo->exec('CREATE TABLE home_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE case_pages (id INTEGER)');
        $pdo->exec('INSERT INTO home_pages VALUES (500)');
        $pdo->exec('INSERT INTO case_pages VALUES (600), (601), (602)');

        // 1 home · 28 RedirectPage (published, but the redirects lane owns it) · 17 its case
        //          3 CategoryPage (no online translation at all)             · 18 its case
        $pdo->exec("INSERT INTO kuma_nodes VALUES
                    (1,  NULL, 0, 1, 'App\\Entity\\Pages\\HomePage'),
                    (28, 1,    0, 2, 'App\\Entity\\Pages\\RedirectPage'),
                    (17, 28,   0, 3, 'App\\Entity\\Pages\\CasePage'),
                    (9,  1,    0, 4, 'App\\Entity\\Pages\\CasePage'),
                    (3,  9,    0, 5, 'App\\Entity\\Pages\\CategoryPage'),
                    (18, 3,    0, 6, 'App\\Entity\\Pages\\CasePage')");

        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (90, 'App\\Entity\\Pages\\HomePage', 500),
                    (91, 'App\\Entity\\Pages\\CasePage', 600),
                    (92, 'App\\Entity\\Pages\\CasePage', 601),
                    (93, 'App\\Entity\\Pages\\CasePage', 602)");

        // 28 carries slugs in both locales; 3 only in EN — and is online in neither.
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (1, 1,  'en', 'Home',        NULL,             NULL, NULL, 1, 90),
                    (2, 28, 'en', 'News',        'news-knowledge', NULL, NULL, 1, NULL),
                    (3, 28, 'nl', 'Nieuws',      'nieuws-kennis',  NULL, NULL, 1, NULL),
                    (4, 17, 'en', 'Voiceworks',  'voiceworks',     NULL, NULL, 1, 91),
                    (5, 17, 'nl', 'Voiceworks',  'voiceworks',     NULL, NULL, 1, 91),
                    (6, 3,  'en', 'Products',    'products',       NULL, NULL, 0, NULL),
                    (9, 9,  'en', 'For business','for-businesses', NULL, NULL, 1, 93),
                    (7, 18, 'en', 'Acme',        'acme',           NULL, NULL, 1, 92),
                    (8, 18, 'nl', 'Acme NL',     'acme-nl',        NULL, NULL, 0, NULL)");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return list<array<string, mixed>> */
    private function compile(string $mapping = self::MAPPING): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $mapping);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function bySourceUid(array $payloads): array
    {
        $out = [];

        foreach ($payloads as $payload) {
            $out[(string) $payload['sourceUid']] = $payload;
        }

        return $out;
    }

    #[Test]
    public function an_ancestor_that_becomes_no_entry_is_emitted_as_a_path_segment(): void
    {
        $payloads = $this->bySourceUid($this->compile());

        self::assertArrayHasKey('kuma:COM:kuma_nodes:28', $payloads, 'the RedirectPage ancestor owns a segment');
        $placeholder = $payloads['kuma:COM:kuma_nodes:28'];

        self::assertTrue($placeholder['structural']);
        self::assertSame('pages', $placeholder['section']);
        self::assertSame('contentPage', $placeholder['entryType']);
        self::assertSame('news-knowledge', $placeholder['sites']['comEnUs']['slug']);
        self::assertSame('nieuws-kennis', $placeholder['sites']['comNlNl']['slug']);
    }

    #[Test]
    public function a_path_segment_is_enabled_nowhere_and_carries_no_content(): void
    {
        $placeholder = $this->bySourceUid($this->compile())['kuma:COM:kuma_nodes:28'];

        foreach ($placeholder['sites'] as $handle => $site) {
            self::assertFalse($site['enabled'], sprintf('site "%s" must stay disabled', $handle));
            // A disabled entry still owns its URI, so its own URL 404s into Retour while the
            // segment still reaches its descendants. Content would make it a page instead.
            self::assertArrayNotHasKey('fieldValues', $site);
        }

        self::assertArrayNotHasKey('legacy', $placeholder);
    }

    #[Test]
    public function the_child_of_a_dropped_ancestor_points_at_the_placeholder(): void
    {
        $payloads = $this->bySourceUid($this->compile());
        $case = $payloads['kuma:COM:kuma_nodes:17'];

        self::assertSame('kuma:COM:kuma_nodes:28', $case['sites']['comEnUs']['parentRef']);
        self::assertSame('kuma:COM:kuma_nodes:28', $case['sites']['comNlNl']['parentRef']);
    }

    #[Test]
    public function an_ancestor_with_no_published_translation_still_owns_its_segment(): void
    {
        $payloads = $this->bySourceUid($this->compile());

        self::assertArrayHasKey('kuma:COM:kuma_nodes:3', $payloads);
        self::assertSame('kuma:COM:kuma_nodes:3', $payloads['kuma:COM:kuma_nodes:18']['sites']['comEnUs']['parentRef']);
    }

    #[Test]
    public function a_locale_the_ancestor_was_never_translated_into_gets_no_segment(): void
    {
        // Node 3 has an EN slug and no NL one. Kunstmaan omits the segment for NL rather than
        // borrowing the EN slug, and inventing a path the old site never served is worse than
        // a shorter one.
        $placeholder = $this->bySourceUid($this->compile())['kuma:COM:kuma_nodes:3'];

        self::assertSame(['comEnUs'], array_keys($placeholder['sites']));
    }

    #[Test]
    public function the_home_node_is_not_a_structure_parent(): void
    {
        // Kunstmaan hangs everything off the home node; in Craft the home page is its own
        // single section, and a structure cannot parent across sections. So 28 is a root of
        // `pages` — and must not be turned into a placeholder for the home node above it.
        $payloads = $this->bySourceUid($this->compile());

        self::assertArrayNotHasKey('parentRef', $payloads['kuma:COM:kuma_nodes:28']['sites']['comEnUs']);
        self::assertArrayNotHasKey('kuma:COM:kuma_nodes:1', array_filter(
            $payloads,
            static fn(array $p): bool => (bool) ($p['structural'] ?? false),
        ));
    }

    #[Test]
    public function a_placeholder_is_emitted_before_the_page_that_parents_it(): void
    {
        // Node 3's parent is node 9, an ordinary page. The loader resolves a parentRef against
        // entries it has already written, so a placeholder emitted before its parent lands at
        // the structure root — and Craft's `-2` collision slug, once assigned, is never given
        // back. Tree order (`lft`) is what keeps that from happening.
        $order = array_column($this->compile(), 'sourceUid');

        $nine = array_search('kuma:COM:kuma_nodes:9', $order, true);
        $three = array_search('kuma:COM:kuma_nodes:3', $order, true);

        self::assertNotFalse($nine);
        self::assertNotFalse($three);
        self::assertLessThan($three, $nine, 'node 9 must be emitted before the placeholder it parents');
    }

    #[Test]
    public function an_offline_translation_still_writes_its_slug_for_that_site(): void
    {
        // Node 18's NL translation is offline but carries a slug; Kunstmaan still builds the
        // NL URL from it. The row is written disabled so it owns the segment without publishing.
        $payloads = $this->bySourceUid($this->compile());
        $sites = $payloads['kuma:COM:kuma_nodes:18']['sites'];

        self::assertArrayHasKey('comNlNl', $sites);
        self::assertFalse($sites['comNlNl']['enabled']);
        self::assertSame('acme-nl', $sites['comNlNl']['slug']);

        // …and a published translation is never downgraded by one.
        self::assertTrue($sites['comEnUs']['enabled']);
        self::assertSame('acme', $sites['comEnUs']['slug']);
    }

    #[Test]
    public function a_placeholder_is_not_counted_as_a_migrated_page(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        $compiler = new Compiler(Mapping::fromFile($path), new Transforms());
        $compiler->compile($this->db(), 'COM', static function(array $p): void {
        });

        // Three case pages and one home page are content; the two ancestors are not.
        self::assertSame(4, $compiler->entryCount());
        self::assertSame(2, $compiler->structuralCount());
    }

    #[Test]
    public function without_a_configured_entry_type_the_loss_is_reported_rather_than_silent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING_WITHOUT_TYPE);

        $compiler = new Compiler(Mapping::fromFile($path), new Transforms());
        $payloads = [];
        $compiler->compile($this->db(), 'COM', static function(array $p) use (&$payloads): void {
            $payloads[] = $p;
        });

        self::assertSame(0, $compiler->structuralCount());
        self::assertSame([], array_filter($payloads, static fn(array $p): bool => (bool) ($p['structural'] ?? false)));
        self::assertArrayHasKey('structural:no-entry-type:COM', $compiler->skipped());

        // …and the children that lose their segment are counted too, so a run that cannot
        // place its ancestors still says how many pages it re-rooted.
        self::assertArrayHasKey('parent:COM:CasePage', $compiler->skipped());
    }
}
