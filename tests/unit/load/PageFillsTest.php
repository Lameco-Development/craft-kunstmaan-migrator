<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\load\ExplainContext;
use Lameco\Kunstmaanmigrator\load\PageFills;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\tests\kernel\ChildCollectionTargetsTest;
use Lameco\Kunstmaanmigrator\tests\kernel\PageFieldsLaneTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** `state/explain` judges a page part by what compile wrote, end to end over the #90 fixture. */
final class PageFillsTest extends TestCase
{
    #[Test]
    public function explain_credits_the_image_hero_behind_an_empty_slider(): void
    {
        $mapping = Mapping::fromArray(Yaml::parse(PageFieldsLaneTest::MAPPING));
        $db = PageFieldsLaneTest::db();
        $compiler = new Compiler($mapping, new Transforms(), PageFieldsLaneTest::schema());
        $fills = new PageFills($compiler, $compiler->begin($db, 'NL'));

        // Node 22: an empty slider at sequence 1, then an image hero at sequence 2 — the rows
        // `livePartsOfNode(22)` reads (the fixture's LIKE-escaped entity names do not join there).
        $result = ExplainContext::fromMapping('NL', $mapping)->reconcile(
            [],
            [
                ['lang' => 'nl', 'context' => 'header', 'part' => 'HeaderSlider', 'entity' => 'App\\Entity\\PageParts\\HeaderSliderPagePart', 'id' => 3, 'sequence' => 1],
                ['lang' => 'nl', 'context' => 'header', 'part' => 'Header', 'entity' => 'App\\Entity\\PageParts\\HeaderPagePart', 'id' => 5, 'sequence' => 2],
            ],
            $fills->pageOf(22),
            $fills->of(22),
        );

        self::assertSame('TextPage', $fills->pageOf(22));
        self::assertSame([], $result['unexplained']);
        self::assertSame(
            [
                ['HeaderSlider', 3, 'not written: `Header` #5 filled the page from the `header` page context'],
                ['Header', 5, 'written to the page\'s own fields from the `header` page context, not as a block'],
            ],
            array_map(static fn(array $r): array => [$r['part'], $r['id'], $r['why']], $result['accountedFor']),
        );
    }

    #[Test]
    public function a_node_compile_does_not_carry_has_no_page_and_no_fills(): void
    {
        $mapping = Mapping::fromArray(Yaml::parse(PageFieldsLaneTest::MAPPING));
        $compiler = new Compiler($mapping, new Transforms(), PageFieldsLaneTest::schema());
        $fills = new PageFills($compiler, $compiler->begin(PageFieldsLaneTest::db(), 'NL'));

        self::assertNull($fills->pageOf(999));
        self::assertNull($fills->of(999));
    }

    #[Test]
    public function explain_does_not_flag_parts_whose_child_rows_became_assets(): void
    {
        $mapping = Mapping::fromArray(Yaml::parse(ChildCollectionTargetsTest::MAPPING));
        $compiler = new Compiler($mapping, new Transforms(), ChildCollectionTargetsTest::schema());
        $fills = new PageFills($compiler, $compiler->begin(ChildCollectionTargetsTest::db(), 'NL'));

        // The gallery block is the one element written; its slides went into `images` as assets
        // and leave no block id of their own. The slider filled the page's `sliderImages`.
        $result = ExplainContext::fromMapping('NL', $mapping)->reconcile(
            ['berkvensNl' => ['NL:image_gallery_page_parts:5' => '900']],
            [
                ['lang' => 'nl', 'context' => 'main', 'part' => 'ImageGallery', 'entity' => 'App\\Entity\\PageParts\\ImageGalleryPagePart', 'id' => 5, 'sequence' => 1],
                ['lang' => 'nl', 'context' => 'slider', 'part' => 'ImageSlider', 'entity' => 'App\\Entity\\PageParts\\ImageSliderPagePart', 'id' => 6, 'sequence' => 1],
            ],
            $fills->pageOf(17),
            $fills->of(17),
        );

        self::assertSame([], $result['unexplained']);
        self::assertSame(1, $result['written']);
        self::assertSame(
            [['ImageSlider', 6, 'written to the page\'s own fields from the `slider` page context, not as a block']],
            array_map(static fn(array $r): array => [$r['part'], $r['id'], $r['why']], $result['accountedFor']),
        );
    }
}
