<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Link field that points at a legacy media file.
 *
 * `link()` writes a legacy button URL as it found it, and on Berkvens NL 19 buttons and image
 * links held `/uploads/media/<folder>/<file>?token=[M<id>]` — a path that does not exist after
 * the migration. The finalize pass rewrites the same reference in rich text; this is the Link
 * field's turn: the link becomes an asset link, keeping its label and target.
 */
final class CkeditorRewriterLinkTest extends TestCase
{
    private const ALL_TYPES = ['entry', 'url', 'asset', 'email', 'tel'];

    private function service(): CkeditorRewriterService
    {
        $svc = new CkeditorRewriterService();
        $svc->seedUrlIdCache(['/uploads/media/67c6faf26f8e0/voorwaarden.pdf' => 501]);
        $svc->seedKumaMediaIdCache([3786 => 502]);

        return $svc;
    }

    #[Test]
    public function a_button_to_a_legacy_file_becomes_an_asset_link_with_its_label_and_target(): void
    {
        $svc = $this->service();

        self::assertSame(
            ['type' => 'asset', 'value' => '{asset:501@6:url}', 'label' => 'Voorwaarden', 'target' => '_blank'],
            $svc->rewriteLink([
                'value' => '/uploads/media/67c6faf26f8e0/voorwaarden.pdf?token=[M3446]',
                'type' => 'url',
                'label' => 'Voorwaarden',
                'target' => '_blank',
            ], 6, self::ALL_TYPES),
        );
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }

    #[Test]
    public function the_media_token_resolves_a_file_the_path_does_not(): void
    {
        self::assertSame(
            ['type' => 'asset', 'value' => '{asset:502@6:url}'],
            $this->service()->rewriteLink(
                ['value' => '/uploads/media/687a3191dae98/1.png?token=[M3786]', 'type' => 'url'],
                6,
                self::ALL_TYPES,
            ),
        );
    }

    #[Test]
    public function an_unresolvable_file_is_left_as_it_is_and_reported(): void
    {
        $svc = $this->service();

        self::assertNull($svc->rewriteLink(
            ['value' => '/uploads/media/5f1/deleted.pdf?token=[M383]', 'type' => 'url', 'label' => 'Download'],
            6,
            self::ALL_TYPES,
        ));

        $diagnostics = $svc->consumeUnresolvedDiagnostics();
        self::assertCount(1, $diagnostics);
        self::assertSame('link_media', $diagnostics[0]['tokenFamily']);
    }

    #[Test]
    public function a_field_that_takes_no_asset_links_is_left_as_it_is_and_reported(): void
    {
        $svc = $this->service();

        self::assertNull($svc->rewriteLink(
            ['value' => '/uploads/media/67c6faf26f8e0/voorwaarden.pdf', 'type' => 'url'],
            6,
            ['entry', 'url'],
        ));
        self::assertCount(1, $svc->consumeUnresolvedDiagnostics());
    }

    #[Test]
    public function a_link_that_is_not_to_a_legacy_file_is_none_of_its_business(): void
    {
        $svc = $this->service();

        self::assertNull($svc->rewriteLink(['value' => 'https://www.berkvens.nl/contact', 'type' => 'url'], 6, self::ALL_TYPES));
        self::assertNull($svc->rewriteLink(['value' => '{entry:12@6:url}', 'type' => 'entry'], 6, self::ALL_TYPES));
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }
}
