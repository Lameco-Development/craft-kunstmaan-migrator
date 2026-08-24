<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedirectLaneTest extends TestCase
{
    /** @return list<string> */
    private function validate(string $yaml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new Schema())->validate(Mapping::fromFile($path));
    }

    #[Test]
    public function a_redirect_without_a_destination_column_is_rejected(): void
    {
        // The loader turns `to` into the migrated entry's current URI. With no column to
        // read it from, every compiled redirect points nowhere and the lane looks migrated.
        self::assertContains(
            'redirect `RedirectPage`: no `map.destination:` — without it every redirect points nowhere',
            $this->validate(<<<'YAML'
                version: 1
                environments:
                  COM: { database: legacy, locales: { en: comEnUs } }
                redirects:
                  RedirectPage:
                    table: redirect_pages
                    map: { source: node.url }
                YAML),
        );
    }

    #[Test]
    public function a_complete_redirect_spec_validates(): void
    {
        self::assertSame([], $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: comEnUs } }
            redirects:
              RedirectPage:
                table: redirect_pages
                map:
                  source:      node.url
                  destination: url
                  type:        type
            YAML));
    }
}
