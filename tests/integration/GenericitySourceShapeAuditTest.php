<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration;

use PHPUnit\Framework\TestCase;

final class GenericitySourceShapeAuditTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/source-shape-audit-' . bin2hex(random_bytes(6));
        $entityDir = $this->tmpRoot . '/src/Entity/Pages';
        if (!mkdir($entityDir, 0o755, true) && !is_dir($entityDir)) {
            self::fail("Could not create synthetic source tree at {$entityDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
        $this->tmpRoot = '';
        parent::tearDown();
    }

    public function testAuditPrintsOnlyStructuralSourceShapeSummary(): void
    {
        $this->writeEntity('Pages/NewsPage.php', <<<'PHP'
        <?php
        namespace App\Entity\Pages;

        use App\Entity\Media\Media;
        use App\Entity\Taxonomy\Category;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'app_news_pages')]
        final class NewsPage
        {
            #[ORM\Column(type: 'string', name: 'title')]
            private string $title = 'PRIVATE_TITLE_VALUE';

            #[ORM\ManyToOne(targetEntity: Media::class)]
            #[ORM\JoinColumn(name: 'hero_media_id')]
            private $hero;

            #[ORM\ManyToMany(targetEntity: Category::class)]
            #[ORM\JoinTable(name: 'app_news_pages_categories')]
            private $categories;

            public function proprietaryBody(): string
            {
                return 'SECRET_BODY_TEXT_SHOULD_NOT_LEAK';
            }
        }
        PHP);
        $this->writeEntity('PageParts/GalleryPagePart.php', <<<'PHP'
        <?php
        namespace App\Entity\PageParts;

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'app_gallery_pageparts')]
        final class GalleryPagePart
        {
            #[ORM\OneToMany(targetEntity: GalleryImage::class, mappedBy: 'part')]
            private $images;
        }
        PHP);

        $output = $this->runAudit($this->tmpRoot);

        self::assertStringContainsString('project=' . $this->tmpRoot, $output);
        self::assertStringContainsString('classes=2', $output);
        self::assertStringContainsString('pages=1', $output);
        self::assertStringContainsString('pageparts=1', $output);
        self::assertStringContainsString('class=App\\Entity\\Pages\\NewsPage', $output);
        self::assertStringContainsString('table=app_news_pages', $output);
        self::assertStringContainsString('relation=ManyToOne property=hero target=App\\Entity\\Media\\Media fk=hero_media_id', $output);
        self::assertStringContainsString('relation=ManyToMany property=categories target=App\\Entity\\Taxonomy\\Category joinTable=app_news_pages_categories', $output);
        self::assertStringContainsString('risk=taxonomy_or_data_provider_like class=App\\Entity\\Taxonomy\\Category', $output);
        self::assertStringContainsString('risk=media_fk table=app_news_pages column=hero_media_id', $output);

        self::assertStringNotContainsString('SECRET_BODY_TEXT_SHOULD_NOT_LEAK', $output);
        self::assertStringNotContainsString('PRIVATE_TITLE_VALUE', $output);
        self::assertStringNotContainsString('proprietaryBody', $output);
        self::assertStringNotContainsString('return ', $output);
    }

    private function writeEntity(string $relative, string $php): void
    {
        $path = $this->tmpRoot . '/src/Entity/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            self::fail("Could not create {$dir}");
        }
        file_put_contents($path, $php . "\n");
    }

    private function runAudit(string $sourcePath): string
    {
        $tool = dirname(__DIR__, 2) . '/tools/audit-source-shapes.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' ' . escapeshellarg($sourcePath);
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        return implode("\n", $output);
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
            $file->isDir() ? rmdir((string) $file->getRealPath()) : unlink((string) $file->getRealPath());
        }
        rmdir($path);
    }
}
