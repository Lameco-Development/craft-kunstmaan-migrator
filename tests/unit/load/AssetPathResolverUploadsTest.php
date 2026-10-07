<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\AssetPathResolver;
use PHPUnit\Framework\TestCase;

/**
 * A `/uploads/<dir>/…` path outside `kuma_media`, found from the media roots the mapping
 * already states.
 *
 * `mediaRoot` names `…/public/uploads/media` — the Kunstmaan convention — so a catalogue file
 * at `/uploads/models_import/A12.jpg` sits beside that root, under its parent. A root the
 * mapping names at `…/uploads` itself serves the same paths directly.
 */
final class AssetPathResolverUploadsTest extends TestCase
{
    private string $web;

    protected function setUp(): void
    {
        $this->web = sys_get_temp_dir() . '/kmig-uploads-' . uniqid();
        mkdir($this->web . '/uploads/media', 0777, true);
        mkdir($this->web . '/uploads/models_import', 0777, true);
        file_put_contents($this->web . '/uploads/media/pic.png', 'png');
        file_put_contents($this->web . '/uploads/models_import/A12.jpg', 'jpg');
        file_put_contents($this->web . '/secret.txt', 'nope');
    }

    protected function tearDown(): void
    {
        @unlink($this->web . '/uploads/media/pic.png');
        @unlink($this->web . '/uploads/models_import/A12.jpg');
        @unlink($this->web . '/secret.txt');
        @rmdir($this->web . '/uploads/media');
        @rmdir($this->web . '/uploads/models_import');
        @rmdir($this->web . '/uploads');
        @rmdir($this->web);
    }

    public function testAFileBesideTheMediaRootResolvesUnderItsParent(): void
    {
        self::assertSame(
            realpath($this->web . '/uploads/models_import/A12.jpg'),
            AssetPathResolver::resolveUpload('/uploads/models_import/A12.jpg', $this->web . '/uploads/media'),
        );
    }

    public function testARootNamedAtTheUploadsDirectoryServesThePathDirectly(): void
    {
        self::assertSame(
            realpath($this->web . '/uploads/models_import/A12.jpg'),
            AssetPathResolver::resolveUpload('/uploads/models_import/A12.jpg', $this->web . '/uploads'),
        );
    }

    public function testAMediaPathResolvesExactlyAsBefore(): void
    {
        self::assertSame(
            realpath($this->web . '/uploads/media/pic.png'),
            AssetPathResolver::resolveUpload('/uploads/media/pic.png', $this->web . '/uploads/media'),
        );
        self::assertSame(
            realpath($this->web . '/uploads/media/pic.png'),
            AssetPathResolver::resolveUpload('pic.png', $this->web . '/uploads/media'),
        );
    }

    public function testAMissingFileOrAnEscapeResolvesToNothing(): void
    {
        $root = $this->web . '/uploads/media';

        self::assertNull(AssetPathResolver::resolveUpload('/uploads/models_import/gone.jpg', $root));
        self::assertNull(AssetPathResolver::resolveUpload('/uploads/../secret.txt', $root));
        self::assertNull(AssetPathResolver::resolveUpload('/uploads/models_import', $root));
        self::assertNull(AssetPathResolver::resolveUpload(null, $root));
    }

    public function testTheUploadDirectoryIsWhatLegacyTreePlacementFallsBackTo(): void
    {
        self::assertSame('models_import', AssetPathResolver::uploadDir('/uploads/models_import/A12.jpg'));
        self::assertSame('documents/brochures', AssetPathResolver::uploadDir('/uploads/documents/brochures/x.pdf'));
        self::assertNull(AssetPathResolver::uploadDir('/uploads/media/5f08/pic.png'), 'kuma_media keeps its own folders');
        self::assertNull(AssetPathResolver::uploadDir('/uploads/loose.jpg'));
        self::assertNull(AssetPathResolver::uploadDir('pic.png'));
    }
}
