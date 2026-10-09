<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Transforms;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `transforms:` entry with a `map:` is a transform in its own right — the mapping declares
 * the vocabulary, the code supplies the mechanics. Surfaced by the Enreach e2e run: the hero
 * colour field offers indigo/lavender where the shared `colorScheme` collapse emits purple,
 * and 80 pages (both homepages included) failed validation on it.
 */
final class ConfiguredTransformTest extends TestCase
{
    private function transforms(): Transforms
    {
        return new Transforms([
            'heroColorScheme' => [
                'map' => ['purple' => 'indigo', 'violet' => 'lavender', 'white' => 'white'],
                'fallback' => 'white',
            ],
            'buttonType' => [
                'map' => ['btn-outline-white' => 'secondary', 'btn-indigo' => 'primary'],
            ],
        ]);
    }

    #[Test]
    public function a_mapped_value_is_translated_not_lost(): void
    {
        // A `map:` hit is the mapping saying what the value becomes: a label turned
        // into the option value the target stores (Berkvens FR's `technische informatie`
        // → `technicalInformation`) carries everything across. Counting it as a loss
        // made `--fail-on-loss` refuse a run that lost nothing.
        $t = $this->transforms();

        self::assertSame('indigo', $t->apply('heroColorScheme', 'purple', 'HeaderTab'));
        self::assertSame([], $t->losses());
        self::assertSame(0, $t->lossCount());
    }

    /**
     * A hit that maps to nothing (`''` or `~`) drops the value the source held — the map
     * says so on purpose, but the value is still gone, so it is counted, and a reviewed
     * one goes in `acceptedLosses:`.
     */
    #[Test]
    public function a_value_mapped_to_nothing_is_a_loss(): void
    {
        $t = new Transforms(['alignRight' => ['map' => ['left' => '', 'right' => '1', 'none' => null]]]);

        self::assertSame('', $t->apply('alignRight', 'left'));
        self::assertSame('1', $t->apply('alignRight', 'right'));
        self::assertSame('', $t->apply('alignRight', 'none'));
        self::assertSame(['alignRight' => ['left -> ' => 1, 'none -> ' => 1]], $t->losses());
    }

    #[Test]
    public function an_identity_mapping_is_not_a_loss(): void
    {
        $t = $this->transforms();

        self::assertSame('white', $t->apply('heroColorScheme', 'White'));
        self::assertSame([], $t->losses());
    }

    #[Test]
    public function an_unknown_value_falls_back_and_is_recorded(): void
    {
        $t = $this->transforms();

        self::assertSame('white', $t->apply('heroColorScheme', 'chartreuse', 'HeaderTab'));
        self::assertSame(['heroColorScheme' => ['chartreuse -> white' => 1]], $t->losses());
    }

    #[Test]
    public function without_a_fallback_an_unknown_value_becomes_null(): void
    {
        $t = $this->transforms();

        self::assertNull($t->apply('buttonType', 'btn-mystery'));
        self::assertSame(['buttonType' => ['btn-mystery -> null' => 1]], $t->losses());
    }

    #[Test]
    public function a_name_with_no_configured_map_still_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown transform `buttonTyop`');

        $this->transforms()->apply('buttonTyop', 'btn-indigo');
    }
}
