<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\RedirectCompiler;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedirectCompilerTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: legacy
            locales: { en: comEnUs, nl: comNlNl, sp: ~ }
        redirects:
          RedirectPage:
            table: redirect_pages
            map:
              source:      node.url
              destination: url
              type:        type
            defaultType: 301
        YAML;

    /**
     * A Kunstmaan node tree small enough to reason about: one redirect page published in
     * three locales, one of which has no Craft site, plus the node it points at.
     */
    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE redirect_pages (id INTEGER, url TEXT, type INTEGER)');

        $pdo->exec("INSERT INTO kuma_nodes VALUES (1, NULL, 0, 1, 'App\\Entity\\Pages\\RedirectPage'), (7, NULL, 0, 2, 'App\\Entity\\Pages\\RedirectPage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (11, 'App\\\\Entity\\\\Pages\\\\RedirectPage', 100),
                    (12, 'App\\\\Entity\\\\Pages\\\\RedirectPage', 101),
                    (13, 'App\\\\Entity\\\\Pages\\\\RedirectPage', 102),
                    (14, 'App\\\\Entity\\\\Pages\\\\ContentPage', 500)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (21, 1, 'en', 'Old', 'old', 'news-knowledge', NULL, 1, 11),
                    (22, 1, 'nl', 'Oud', 'oud', 'nieuws', NULL, 1, 12),
                    (23, 1, 'sp', 'Viejo', 'viejo', 'noticias', NULL, 1, 13),
                    (24, 7, 'en', 'Target', 'target', 'company/news', NULL, 1, 14)");
        $pdo->exec("INSERT INTO redirect_pages VALUES (100, '[NT24]', 302), (101, 'https://example.test/x', NULL), (102, '[NT24]', 301)");

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }

    /** @return list<array<string, mixed>> */
    private function compile(RedirectCompiler $compiler): array
    {
        $out = [];
        $compiler->compile($this->db(), 'COM', static function(array $r) use (&$out): void {
            $out[] = $r;
        });

        return $out;
    }

    private function compiler(): RedirectCompiler
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        return new RedirectCompiler(Mapping::fromFile($path));
    }

    #[Test]
    public function one_redirect_page_becomes_one_redirect_per_published_site(): void
    {
        $compiler = $this->compiler();
        $records = $this->compile($compiler);

        self::assertSame([
            // `[NT24]` addresses a node *translation*; the node is what becomes an entry, so
            // the destination is the node's sourceUid for the loader to resolve to a URI.
            ['from' => '/news-knowledge', 'to' => 'kuma:COM:kuma_nodes:7', 'siteHandle' => 'comEnUs', 'type' => 302],
            ['from' => '/nieuws', 'to' => 'https://example.test/x', 'siteHandle' => 'comNlNl', 'type' => 301],
        ], $records);

        // The Spanish translation is live and has a destination; it has no Craft site, which
        // is a reported loss rather than a silently missing redirect.
        self::assertSame(['locale:COM:sp' => 1], $compiler->skipped());
        self::assertSame(2, $compiler->count());
    }

    #[Test]
    public function a_missing_type_falls_back_to_the_declared_default(): void
    {
        self::assertSame(301, $this->compile($this->compiler())[1]['type']);
    }
}
