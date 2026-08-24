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
 * The batched-queue contract (#48): a job compiles a handful of units, its
 * process ends, and a fresh process resumes. Correctness means the payload
 * stream is byte-identical to the one monolithic walk — entity rows, pages,
 * and the structural placeholders interleaved between them — no matter where
 * the batch boundaries fall, with every batch running on a freshly built
 * Compiler and CompilerRun plus a `catchUpStructural()` to the resume point.
 */
final class BatchedCompileEquivalenceTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs, nl: comNlNl }
        defaults:
          structuralEntryType: contentPage
        entities:
          FaqCategory:
            table: faq_category
            section: faqCategories
            entryType: faqCategory
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
        $pdo->exec('CREATE TABLE faq_category (id INTEGER, title TEXT)');
        $pdo->exec('INSERT INTO home_pages VALUES (500)');
        $pdo->exec('INSERT INTO case_pages VALUES (600), (601), (602)');
        $pdo->exec("INSERT INTO faq_category VALUES (1, 'Billing'), (2, 'Devices'), (3, 'Contracts')");

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

    private function mapping(): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        return Mapping::fromFile($path);
    }

    /** @return list<array<string, mixed>> */
    private function full(): array
    {
        $out = [];
        (new Compiler($this->mapping(), new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        return $out;
    }

    /**
     * The batched drive: a FRESH Compiler and CompilerRun per unit — the
     * worst-case batch size of one, every unit in its own "process".
     *
     * @return list<array<string, mixed>>
     */
    private function batched(int $entityChunk): array
    {
        $out = [];
        $emit = static function(array $p) use (&$out): void {
            $out[] = $p;
        };

        // Unit list, enumerated once (what the job's loadData() does).
        $probe = new Compiler($this->mapping(), new Transforms());
        $probeRun = $probe->begin($this->db(), 'COM');
        $units = [];

        foreach ($probe->entityLaneCounts($probeRun) as $lane => $count) {
            for ($offset = 0; $offset < $count; $offset += $entityChunk) {
                $units[] = ['e', $lane, $offset, $entityChunk];
            }
        }

        foreach (array_keys($probeRun->nodesById) as $nodeId) {
            $units[] = ['n', $nodeId];
        }

        $units[] = ['t'];

        $lastNode = null;

        foreach ($units as $unit) {
            $compiler = new Compiler($this->mapping(), new Transforms());
            $run = $compiler->begin($this->db(), 'COM');

            if ($lastNode !== null) {
                $compiler->catchUpStructural($run, $lastNode);
            }

            match ($unit[0]) {
                'e' => $compiler->compileEntitySlice($run, $unit[1], $unit[2], $unit[3], $emit),
                'n' => $compiler->compileNodeUnit($run, $unit[1], $emit),
                't' => $compiler->finishStructural($run, $emit),
            };

            if ($unit[0] === 'n') {
                $lastNode = $unit[1];
            }
        }

        return $out;
    }

    #[Test]
    public function a_batch_size_of_one_reproduces_the_monolithic_walk_exactly(): void
    {
        self::assertSame($this->full(), $this->batched(entityChunk: 1));
    }

    #[Test]
    public function chunked_entity_slices_change_nothing(): void
    {
        self::assertSame($this->full(), $this->batched(entityChunk: 2));
    }
}
