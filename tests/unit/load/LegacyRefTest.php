<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\LegacyRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which `kuma_seo` row a state row's entry copies its SEO from — the legacy class and entity
 * id `kuma_seo.ref_entity_name` / `ref_id` hold.
 */
final class LegacyRefTest extends TestCase
{
    #[Test]
    public function a_compiled_entity_row_has_no_legacy_ref(): void
    {
        // `FR:product_photo` is the compiled grammar, not a class: deriving `FR:product\photo`
        // matched no `kuma_seo` row, and the adapter then cleared the entity's own SEO.
        self::assertSame([null, 0], LegacyRef::fromState('FR:product_photo', '12', null));
    }

    #[Test]
    public function a_compiled_page_carrying_its_legacy_class_still_resolves(): void
    {
        self::assertSame(
            ['App\Entity\Pages\TextPage', 7],
            LegacyRef::fromState('FR:kuma_nodes', '12', ['legacyClass' => 'App\Entity\Pages\TextPage', 'legacyEntityId' => 7]),
        );
    }

    #[Test]
    public function meta_stored_as_json_is_read(): void
    {
        self::assertSame(
            ['App\Entity\News', 3],
            LegacyRef::fromState('news', '1', '{"legacyClass":"App\\\\Entity\\\\News","legacyEntityId":3}'),
        );
    }

    #[Test]
    public function an_fqcn_source_falls_back_to_its_class_and_key(): void
    {
        self::assertSame(['App\Entity\Pages\TextPage', 5], LegacyRef::fromState('App_Entity_Pages_TextPage', '5', null));
    }

    #[Test]
    public function a_singleton_without_meta_has_no_legacy_ref(): void
    {
        self::assertSame([null, 0], LegacyRef::fromState('singleton', 'globalSettings', null));
    }

    #[Test]
    public function a_source_that_cannot_be_a_class_has_no_legacy_ref(): void
    {
        self::assertSame([null, 0], LegacyRef::fromState('news', '5', null));
        self::assertSame([null, 0], LegacyRef::fromState('App_Entity_News', 'abc', null));
    }
}
