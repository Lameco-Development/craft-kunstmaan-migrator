<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\BlockPropagation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlockPropagationTest extends TestCase
{
    #[Test]
    public function a_shared_block_set_cannot_hold_two_locales(): void
    {
        $problems = BlockPropagation::problems(
            ['commonPageBuilder' => 'all'],
            ['COM' => 4, 'DE' => 1],
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('commonPageBuilder', $problems[0]);
        self::assertStringContainsString('COM', $problems[0]);
        self::assertStringNotContainsString('DE', $problems[0]);
    }

    #[Test]
    public function a_single_locale_corpus_is_not_warned(): void
    {
        // A shared block set is exactly right when one site writes it, and a warning that fires
        // on every single-language corpus is the same as no warning.
        self::assertSame([], BlockPropagation::problems(
            ['commonPageBuilder' => 'all'],
            ['COM' => 1],
        ));
    }

    #[Test]
    public function every_shared_placement_is_named_in_one_finding(): void
    {
        // The explanation is the same sentence for each, and repeating it per placement is how
        // a check's output stops being read.
        $problems = BlockPropagation::problems(
            ['blogPage.pageBuilder' => 'all', 'newsPage.pageBuilder' => 'all'],
            ['COM' => 4],
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('blogPage.pageBuilder, newsPage.pageBuilder', $problems[0]);
    }

    #[Test]
    public function a_field_that_does_not_propagate_is_not_warned(): void
    {
        self::assertSame([], BlockPropagation::problems(
            ['commonPageBuilder' => 'none', 'sidebar' => 'custom'],
            ['COM' => 4],
        ));
    }

    #[Test]
    public function a_field_the_target_does_not_have_has_no_opinion(): void
    {
        // A missing field is a different error and TargetCheck owns it. Guessing here would make
        // doctor fail on a mapping whose real problem is named elsewhere.
        self::assertSame([], BlockPropagation::problems(
            ['commonPageBuilder' => null],
            ['COM' => 4],
        ));
    }

    #[Test]
    public function a_locale_declared_unmapped_writes_no_site_and_is_not_counted(): void
    {
        // Berkvens NL: `en` is `!unmapped`, so only `berkvensNl` is ever written. Counting the
        // declaration as a second locale failed doctor on a single-site group.
        $path = tempnam(sys_get_temp_dir(), 'kkm-locales-');
        file_put_contents($path, <<<'YAML'
            version: 1
            environments:
              NL:
                database: legacy_nl
                locales:
                  nl: berkvensNl
                  en: !unmapped "no English site"
              COM:
                database: legacy_com
                locales:
                  en: comEn
                  de: comDe
            YAML);

        try {
            $counts = BlockPropagation::mappedLocaleCounts(Mapping::fromFile($path)->environments());
        } finally {
            unlink($path);
        }

        self::assertSame(['NL' => 1, 'COM' => 2], $counts);
        self::assertSame([], BlockPropagation::problems(['pageBuilderBerkvensNl' => 'all'], ['NL' => $counts['NL']]));
    }
}
