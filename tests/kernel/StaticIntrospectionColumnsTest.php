<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\IntrospectionCheck;
use Lameco\Kunstmaanmigrator\Source\Introspection;
use Lameco\Kunstmaanmigrator\Source\Introspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A checkout that cannot boot is read statically, and the static scan has to see the columns
 * an entity gets from a trait and from a ManyToOne join column — Berkvens' `HeaderPagePart`
 * takes `title`, `title_type`, `title_style` from `TitleItemsTrait` and has `header_image_id`
 * as a join column. Missing them made `validate --introspection` warn on four real columns.
 */
final class StaticIntrospectionColumnsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kkm-static-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Entity/PageParts', 0o777, true);
        mkdir($this->root . '/src/Traits', 0o777, true);

        file_put_contents($this->root . '/src/Traits/TitleItemsTrait.php', <<<'PHP'
            <?php

            namespace App\Traits;

            use Doctrine\ORM\Mapping as ORM;

            trait TitleItemsTrait
            {
                use StyleTrait;

                #[ORM\Column(name: 'title', type: 'string', length: 255, nullable: true)]
                private $title;

                #[ORM\Column(name: 'title_type', type: 'string', length: 255, nullable: true)]
                private $titleType;
            }
            PHP);

        file_put_contents($this->root . '/src/Traits/StyleTrait.php', <<<'PHP'
            <?php

            namespace App\Traits;

            use Doctrine\ORM\Mapping as ORM;

            trait StyleTrait
            {
                use TitleItemsTrait;

                #[ORM\Column(name: 'title_style', type: 'string', length: 255, nullable: true)]
                private $titleStyle;
            }
            PHP);

        file_put_contents($this->root . '/src/Entity/PageParts/HeaderPagePart.php', <<<'PHP'
            <?php

            namespace App\Entity\PageParts;

            use App\Traits\TitleItemsTrait;
            use Doctrine\ORM\Mapping as ORM;
            use Kunstmaan\MediaBundle\Entity\Media;
            use Kunstmaan\PagePartBundle\Entity\AbstractPagePart;

            #[ORM\Table(name: 'lameco_websitebundle_header_page_parts')]
            #[ORM\Entity]
            class HeaderPagePart extends AbstractPagePart
            {
                use TitleItemsTrait;

                #[ORM\Column(name: 'subtitle', type: 'string', length: 255, nullable: true)]
                private $subtitle;

                /**
                 * @var Media
                 */
                #[ORM\JoinColumn(name: 'header_image_id', referencedColumnName: 'id')]
                #[ORM\ManyToOne(targetEntity: Media::class)]
                private $headerImage;

                #[ORM\ManyToMany(targetEntity: Media::class)]
                #[ORM\JoinTable(name: 'header_gallery')]
                #[ORM\JoinColumn(name: 'header_id', referencedColumnName: 'id')]
                #[ORM\InverseJoinColumn(name: 'media_id', referencedColumnName: 'id')]
                private $gallery;
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
    }

    /** @return list<string> */
    private function columnWarnings(): array
    {
        $introspection = Introspection::fromArray((new Introspector())->introspect($this->root, forceStatic: true));
        $mapping = Mapping::fromArray([
            'version' => 1,
            'environments' => ['NL' => ['database' => 'nl', 'locales' => ['nl' => 'berkvensNl']]],
            'parts' => ['Header' => [
                'table' => 'lameco_websitebundle_header_page_parts',
                'block' => 'heroBlock',
                'map' => [
                    'heroTitle' => 'title',
                    'heroTitleTag' => 'title_type',
                    'heroStyle' => 'title_style',
                    'heroSubtitle' => 'subtitle',
                    'heroImage' => 'header_image_id',
                    'gallery' => 'header_id',
                    'typo' => 'subtitel',
                ],
                'note' => 'header_gallery is read elsewhere',
            ]],
        ]);

        return array_values(array_filter(
            (new IntrospectionCheck($mapping, $introspection))->warnings(),
            static fn(string $w): bool => str_contains($w, 'not a column'),
        ));
    }

    #[Test]
    public function an_entity_whose_only_scanned_column_is_a_join_column_is_not_checked(): void
    {
        // BlogPage's own columns come from Kunstmaan's AbstractArticlePage, which a static scan
        // cannot see. Listing only its `blog_author_id` would flag every inherited column it maps.
        file_put_contents($this->root . '/src/Entity/BlogPage.php', <<<'PHP'
            <?php

            namespace App\Entity;

            use Doctrine\ORM\Mapping as ORM;

            #[ORM\Table(name: 'blog_pages')]
            class BlogPage extends AbstractArticlePage
            {
                #[ORM\ManyToOne(targetEntity: BlogAuthor::class)]
                #[ORM\JoinColumn(name: 'blog_author_id', referencedColumnName: 'id')]
                private $blogAuthor;
            }
            PHP);

        $introspection = Introspection::fromArray((new Introspector())->introspect($this->root, forceStatic: true));

        self::assertSame([], $introspection->columnsOf('App\Entity\BlogPage'));
    }

    #[Test]
    public function trait_and_join_columns_are_columns_and_a_typo_still_is_not(): void
    {
        $warnings = $this->columnWarnings();

        self::assertCount(2, $warnings, implode("\n", $warnings));
        self::assertStringContainsString('reads `header_id`', $warnings[0], 'a join-table column is not a column of the entity');
        self::assertStringContainsString('reads `subtitel`', $warnings[1]);
    }
}
