<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\KnowledgeBase;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 / Plan 03 — `renderTaxonomiesMarkdown()` smoke test.
 *
 * Mirrors `KnowledgeBaseSmokeTest` for the existing two render methods.
 * Asserts:
 *   - the method exists and returns a non-empty string with the expected
 *     `# Kunstmaan Taxonomy Candidates` header,
 *   - per-FQCN sections are emitted for non-page Doctrine entities,
 *   - page entities discovered via `kuma_nodes.ref_entity_name` are excluded
 *     from the candidate list,
 *   - the entityParser-null path degrades gracefully to the no-entities line.
 *
 * Fixture pattern follows DoctrineEntityParserAttributesOnlyTest: write
 * minimal entity PHP files under `<tmp>/src/Entity/...` and warm the parser
 * via `sourceCheckoutPath`.
 */
final class KnowledgeBaseTaxonomiesTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/kb-taxonomies-' . bin2hex(random_bytes(6));
        $entityDir = $base . '/src/Entity/Pages';
        if (!mkdir($entityDir, 0o755, true) && !is_dir($entityDir)) {
            self::fail("could not create temp Entity dir at {$entityDir}");
        }
        $this->tmpRoot = $base;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
        parent::tearDown();
    }

    public function testRendersHeaderEvenWithNoEntities(): void
    {
        $kb = new KnowledgeBase();
        $kb->legacyDb = new TaxonomiesStubLegacyDbService([], []);

        $md = $kb->renderTaxonomiesMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        self::assertNotSame('', $md);
        self::assertStringStartsWith('# Kunstmaan Taxonomy Candidates', $md);
        self::assertStringContainsString('No non-Page entities discovered', $md);
    }

    public function testEmitsCandidateSectionForNonPageEntity(): void
    {
        // Fixture: NewsPage (page) and Category (non-page).
        $this->writeFile(
            'src/Entity/Pages/NewsPage.php',
            <<<'PHP'
            <?php
            namespace App\Entity\Pages;
            use Doctrine\ORM\Mapping as ORM;
            #[ORM\Entity]
            #[ORM\Table(name: 'lameco_websitebundle_news_page')]
            final class NewsPage {
                #[ORM\Column(type: 'integer')]
                private int $id;
            }
            PHP,
        );
        $this->writeFile(
            'src/Entity/Category.php',
            <<<'PHP'
            <?php
            namespace App\Entity;
            use Doctrine\ORM\Mapping as ORM;
            #[ORM\Entity]
            #[ORM\Table(name: 'lameco_websitebundle_category')]
            final class Category {
                #[ORM\Column(type: 'integer')]
                private int $id;
                #[ORM\Column(type: 'string')]
                private string $name;
            }
            PHP,
        );

        $entityParser = new DoctrineEntityParser();
        $entityParser->sourceCheckoutPath = $this->tmpRoot;

        $kb = new KnowledgeBase();
        $kb->legacyDb = new TaxonomiesStubLegacyDbService(
            pageFqcns: ['App\\Entity\\Pages\\NewsPage'],
            rowCounts: ['lameco_websitebundle_category' => 7],
        );
        $kb->entityParser = $entityParser;

        $md = $kb->renderTaxonomiesMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        self::assertStringStartsWith('# Kunstmaan Taxonomy Candidates', $md);
        self::assertStringContainsString('## Category', $md);
        self::assertStringContainsString('App\\Entity\\Category', $md);
        self::assertStringContainsString('lameco_websitebundle_category', $md);
        self::assertStringNotContainsString('## NewsPage', $md);
    }

    public function testEntityParserMissingProducesEmptyResultGracefully(): void
    {
        $kb = new KnowledgeBase();
        $kb->legacyDb = new TaxonomiesStubLegacyDbService([], []);
        // entityParser intentionally null.

        $md = $kb->renderTaxonomiesMarkdown(null, new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        self::assertStringStartsWith('# Kunstmaan Taxonomy Candidates', $md);
        self::assertStringContainsString('No non-Page entities discovered', $md);
    }

    private function writeFile(string $relPath, string $contents): void
    {
        $abs = $this->tmpRoot . '/' . $relPath;
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            self::fail("could not mkdir {$dir}");
        }
        file_put_contents($abs, $contents);
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir((string) $file->getRealPath());
            } else {
                @unlink((string) $file->getRealPath());
            }
        }
        @rmdir($path);
    }
}

/**
 * Test stub for LegacyDbService used by the taxonomies render path.
 *
 * Pattern-matches the SQL prefixes the method emits:
 *   - `SELECT DISTINCT ref_entity_name FROM kuma_nodes ...` → page FQCNs.
 *   - `SELECT DATABASE()` → schema name placeholder.
 *   - information_schema scans → empty.
 *   - `SELECT COUNT(*) FROM \`<table>\`` → per-table row count.
 */
final class TaxonomiesStubLegacyDbService extends LegacyDbService
{
    /**
     * @param list<string> $pageFqcns
     * @param array<string, int> $rowCounts
     */
    public function __construct(
        private array $pageFqcns = [],
        private array $rowCounts = [],
    ) {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function queryAll(string $sql, array $params = []): array
    {
        if (stripos($sql, 'kuma_nodes') !== false && stripos($sql, 'ref_entity_name') !== false) {
            return array_map(
                static fn(string $fqcn): array => ['ref_entity_name' => $fqcn],
                $this->pageFqcns,
            );
        }
        return [];
    }

    /** @param array<string, mixed> $params */
    public function queryScalar(string $sql, array $params = []): mixed
    {
        if (stripos($sql, 'DATABASE()') !== false) {
            return 'test_db';
        }
        if (preg_match('/SELECT COUNT\(\*\) FROM `([a-z0-9_]+)`/i', $sql, $m) === 1) {
            return $this->rowCounts[$m[1]] ?? 0;
        }
        return 0;
    }

    /** @param array<string, mixed> $params */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return null;
    }
}
