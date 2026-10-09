<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Lameco\Kunstmaanmigrator\finalize\CkeditorFinalizeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which stored rich text the finalize pass picks up.
 *
 * Craft percent-encodes the brackets of Kunstmaan's `src="[M3679]"` on save, so a body whose
 * only legacy reference is a media placeholder reaches finalize as `src="%5BM3679%5D"`. The
 * rewriter resolves that form; the shortlist used to skip it, and 94 such tokens on the
 * Berkvens NL corpus were left as dead URLs without a warning.
 */
final class CkeditorFinalizeServiceMarkersTest extends TestCase
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
        yield 'legacy media url' => ['<img src="/uploads/media/abc/x.jpg">'];
    }

    #[Test]
    #[DataProvider('legacyBodies')]
    public function a_body_carrying_any_form_the_rewriter_resolves_is_a_candidate(string $html): void
    {
        self::assertTrue(CkeditorFinalizeService::carriesMarker($html));
    }

    #[Test]
    public function migrated_text_without_a_legacy_reference_is_left_alone(): void
    {
        self::assertFalse(CkeditorFinalizeService::carriesMarker('<p>Mooie <a href="{entry:12@1:url}">deur</a></p>'));
    }
}
