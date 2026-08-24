<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Transforms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransformsTest extends TestCase
{
    private function transforms(): Transforms
    {
        return new Transforms([
            'titleLevel' => ['clamp' => ['h2', 'h6']],
            'colorScheme' => ['map' => ['' => 'white', 'white' => 'white', 'indigo' => 'purple', 'grey' => 'white']],
        ]);
    }

    #[Test]
    #[DataProvider('headings')]
    public function inline_html_unwraps_block_tags_but_keeps_emphasis(string $in, ?string $out): void
    {
        self::assertSame($out, $this->transforms()->apply('inlineHtml', $in));
    }

    public static function headings(): array
    {
        return [
            'plain' => ['Business flexibility', 'Business flexibility'],
            'wrapped' => ['<p>Leave us a message</p>', 'Leave us a message'],
            'wrapped w/ attrs' => ['<p class="x">Give us a call</p>', 'Give us a call'],
            'nested wrappers' => ['<div><p>Deep</p></div>', 'Deep'],
            'keeps emphasis' => ['<p>Why <strong>Shomi</strong>?</p>', 'Why <strong>Shomi</strong>?'],
            'strips block-level inside' => ['<p>A<ul><li>b</li></ul></p>', 'Ab'],
            'empty' => ['<p></p>', null],
        ];
    }

    #[Test]
    public function title_level_normalises_bare_digits_and_clamps_h1(): void
    {
        $t = $this->transforms();

        self::assertSame('h3', $t->apply('titleLevel', 'h3'));
        self::assertSame('h4', $t->apply('titleLevel', '4'), 'a bare digit is a level');
        self::assertSame('h2', $t->apply('titleLevel', 'h1'), 'h1 is not offered by the field');
        self::assertSame('h6', $t->apply('titleLevel', 'h6'));
    }

    #[Test]
    public function every_clamp_is_recorded_so_the_loss_is_countable(): void
    {
        $t = $this->transforms();
        $t->apply('titleLevel', 'h1');
        $t->apply('titleLevel', 'h1');
        $t->apply('colorScheme', 'indigo');
        $t->apply('titleLevel', 'h3');   // no loss

        self::assertSame(3, $t->lossCount());
        self::assertSame(['h1 -> h2' => 2], $t->losses()['titleLevel']);
        self::assertSame(['indigo -> purple' => 1], $t->losses()['colorScheme']);
    }

    #[Test]
    public function an_unknown_colour_falls_back_and_is_reported(): void
    {
        $t = $this->transforms();

        self::assertSame('white', $t->apply('colorScheme', 'chartreuse'));
        self::assertSame(['chartreuse -> white' => 1], $t->losses()['colorScheme']);
    }

    #[Test]
    public function centered_reads_the_legacy_alignment_string(): void
    {
        $t = $this->transforms();

        self::assertTrue($t->apply('centered', 'center'));
        self::assertTrue($t->apply('centered', 'Centered'));
        self::assertFalse($t->apply('centered', ''), 'the legacy default is left-aligned');
        self::assertFalse($t->apply('centered', null));
        self::assertFalse($t->apply('centered', 'left'));
    }

    #[Test]
    public function variant_never_guesses_boxed(): void
    {
        $t = $this->transforms();

        self::assertSame('base', $t->apply('variant', ''));
        self::assertSame('base', $t->apply('variant', null));
        self::assertSame('band', $t->apply('variant', 'grey'));
    }

    #[Test]
    public function an_unknown_transform_is_a_hard_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transforms()->apply('nope', 'x');
    }

    #[Test]
    public function a_name_and_role_in_one_column_split_on_the_first_comma(): void
    {
        $t = $this->transforms();

        self::assertSame('Phil Lewin', $t->apply('beforeComma', 'Phil Lewin, headteacher'));
        self::assertSame('headteacher', $t->apply('afterComma', 'Phil Lewin, headteacher'));
    }

    #[Test]
    public function a_name_without_a_comma_has_no_role_rather_than_failing_to_parse(): void
    {
        $t = $this->transforms();

        self::assertSame('Malene Thuesen', $t->apply('beforeComma', 'Malene Thuesen'));
        self::assertNull($t->apply('afterComma', 'Malene Thuesen'));
    }

    #[Test]
    public function a_role_containing_a_comma_keeps_it(): void
    {
        self::assertSame(
            'CTO, EMEA',
            $this->transforms()->apply('afterComma', 'Karine Merouze, CTO, EMEA'),
        );
    }

    #[Test]
    public function a_bare_domain_gets_the_scheme_a_link_field_needs(): void
    {
        $t = $this->transforms();

        self::assertSame('https://www.oesedv.de', $t->apply('url', 'www.oesedv.de'));
        self::assertSame('https://genobit.de/', $t->apply('url', 'genobit.de/'));
        self::assertSame('http://keep.example', $t->apply('url', 'http://keep.example'), 'an existing scheme is left alone');
        self::assertNull($t->apply('url', 'ask the office'), 'what cannot be a host is dropped');
        self::assertNull($t->apply('url', ''));
    }

    #[Test]
    public function a_phone_column_with_no_digits_yields_no_link(): void
    {
        // One partner's phone column holds the word "Senpro". Stripped of non-digits it left a
        // bare `tel:`, which Craft rejects — failing the entire entry over a phone number.
        $t = $this->transforms();

        self::assertNull($t->apply('tel', 'Senpro'));
        self::assertSame('tel:+49231000000', $t->apply('tel', '+49 231 000000'));
    }

    #[Test]
    public function an_email_column_holding_several_addresses_keeps_the_first(): void
    {
        $t = $this->transforms();

        self::assertSame(
            'mailto:a.mayer@kmm.de',
            $t->apply('mailto', 'a.mayer@kmm.de; c.mayer@kmm.de; g.scotto@kmm.de'),
        );
        self::assertSame(1, $t->lossCount(), 'the addresses it could not carry are counted');
    }
}
