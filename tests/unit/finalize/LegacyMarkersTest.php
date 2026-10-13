<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Lameco\Kunstmaanmigrator\finalize\LegacyMarkers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which stored content the finalize pass picks up.
 *
 * Craft percent-encodes the brackets of Kunstmaan's `src="[M3679]"` on save, so a body whose
 * only legacy reference is a media placeholder reaches finalize as `src="%5BM3679%5D"`. The
 * rewriter resolves that form; the shortlist used to skip it, and 94 such tokens on the
 * Berkvens NL corpus were left as dead URLs without a warning.
 */
final class LegacyMarkersTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function legacyBodies(): iterable
    {
        yield 'raw media placeholder' => ['<img src="[M3679]">'];
        yield 'encoded media placeholder' => ['<img alt="" src="%5BM3679%5D">'];
        yield 'encoded media placeholder, lower-case escape' => ['<a href="%5bM1276%5d">pdf</a>'];
        yield 'raw node placeholder' => ['<a href="[NT558]">x</a>'];
        yield 'encoded node placeholder' => ['<a href="%5BNT558%5D">x</a>'];
        yield 'encoded node placeholder, lower-case escape' => ['<a href="%5bNT558%5d">x</a>'];
        yield 'double-encoded media placeholder' => ['<img src="%255BM3679%255D">'];
        yield 'double-encoded node placeholder, lower-case escape' => ['<a href="%255bNT558%255d">x</a>'];
        yield 'legacy media url' => ['<img src="/uploads/media/abc/x.jpg">'];
    }

    #[Test]
    #[DataProvider('legacyBodies')]
    public function a_body_carrying_any_form_the_rewriter_resolves_is_a_candidate(string $html): void
    {
        self::assertTrue(LegacyMarkers::inText($html));
    }

    #[Test]
    #[DataProvider('legacyBodies')]
    public function the_database_shortlist_finds_every_body_the_field_check_accepts(string $html): void
    {
        // A body the field check would rewrite but the SQL never selects is never loaded at all:
        // that is how 94 encoded media tokens on Berkvens NL were missed.
        $conditions = LegacyMarkers::likeConditions('es.content');
        self::assertSame('or', array_shift($conditions));

        $found = array_filter(
            $conditions,
            static fn(array $like): bool => $like[0] === 'like' && $like[1] === 'es.content' && str_contains($html, $like[2]),
        );

        self::assertNotSame([], $found);
    }

    #[Test]
    public function migrated_text_without_a_legacy_reference_is_left_alone(): void
    {
        self::assertFalse(LegacyMarkers::inText('<p>Mooie <a href="{entry:12@1:url}">deur</a></p>'));
    }
}
