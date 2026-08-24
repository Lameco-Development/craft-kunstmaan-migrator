<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\Transforms;
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
    public function a_configured_map_translates_and_records_the_loss(): void
    {
        $t = $this->transforms();

        self::assertSame('indigo', $t->apply('heroColorScheme', 'purple', 'HeaderTab'));
        self::assertSame(['heroColorScheme' => ['purple -> indigo' => 1]], $t->losses());
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
