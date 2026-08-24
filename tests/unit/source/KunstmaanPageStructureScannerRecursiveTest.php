<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\source\KunstmaanPageStructureScanner;
use lameco\kunstmaanmigrator\source\KunstmaanSourcePathResolver;
use PHPUnit\Framework\TestCase;

/**
 * 2026-05-18 — `KunstmaanPageStructureScanner::scan()` must walk
 * `src/Entity/Pages/` recursively. Berkvens namespaces its search page
 * under `Pages\Search\SearchPage`; a non-recursive FilesystemIterator
 * silently skipped it, dropping the entity from pageStructure.json →
 * compile dropped it from nodeClasses → extract never produced an
 * extracted/<fqcn-slug>/ payload, leaving the starter-kit "Search Page"
 * placeholder (slug `search-page`) as the active entry in the adopted
 * searchPage Single section, so `/zoeken` 404'd after migrate.
 *
 * The sister scanners (KunstmaanSourceScanner, DoctrineEntityParser)
 * already use RecursiveIteratorIterator + RecursiveDirectoryIterator;
 * aligning here closes the inconsistency.
 */
final class KunstmaanPageStructureScannerRecursiveTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/kunstmaan-page-structure-' . bin2hex(random_bytes(6));
        if (!mkdir($base . '/src/Entity/Pages/Search', 0o755, true) && !is_dir($base . '/src/Entity/Pages/Search')) {
            self::fail("could not create temp Pages/Search dir at {$base}");
        }
        $this->tmpRoot = $base;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
        $this->tmpRoot = '';
        parent::tearDown();
    }

    public function testScannerWalksSubdirectoriesUnderPages(): void
    {
        $this->writeEntity('Pages/HomePage.php', <<<'PHP'
        <?php
        namespace App\Entity\Pages;

        class HomePage {}
        PHP);

        $this->writeEntity('Pages/Search/SearchPage.php', <<<'PHP'
        <?php
        namespace App\Entity\Pages\Search;

        class SearchPage {}
        PHP);

        $scanner = new KunstmaanPageStructureScanner();
        $scanner->pathResolver = $this->fakeResolver($this->tmpRoot);

        $result = $scanner->scan();

        self::assertArrayHasKey('App\\Entity\\Pages\\HomePage', $result);
        self::assertArrayHasKey(
            'App\\Entity\\Pages\\Search\\SearchPage',
            $result,
            'subdir-namespaced Page entities must surface in pageStructure (berkvens regression)',
        );
    }

    private function fakeResolver(string $path): KunstmaanSourcePathResolver
    {
        $resolver = new class extends KunstmaanSourcePathResolver {
            public string $stubPath = '';
            public function resolve(): ?string
            {
                return $this->stubPath !== '' ? $this->stubPath : null;
            }
        };
        $resolver->stubPath = $path;
        return $resolver;
    }

    private function writeEntity(string $relativePath, string $code): void
    {
        $path = $this->tmpRoot . '/src/Entity/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            self::fail("could not create entity fixture dir at {$dir}");
        }
        file_put_contents($path, $code);
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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
