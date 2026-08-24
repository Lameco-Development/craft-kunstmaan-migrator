<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Report\Survey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SurveyTest extends TestCase
{
    private function survey(int $livePlacements, int $allPartRefs): Survey
    {
        return new Survey(
            environment: 'COM',
            database: 'legacy',
            partClasses: ['Text' => $livePlacements],
            pageTypes: ['ContentPage' => 10],
            locales: ['en' => 10],
            contexts: ['main' => $livePlacements],
            volumes: ['media' => 42, 'formSubmissions' => null],
            livePages: 10,
            livePlacements: $livePlacements,
            allPartRefs: $allPartRefs,
        );
    }

    #[Test]
    public function the_live_share_is_what_keeps_an_estimate_off_the_raw_row_count(): void
    {
        // Kunstmaan clones the pagepart graph per node version, so the raw table runs roughly
        // twenty times the live content. This ratio is the whole reason the figure is reported.
        self::assertSame(0.05, $this->survey(500, 10_000)->liveShare());
    }

    #[Test]
    public function an_empty_corpus_does_not_divide_by_zero(): void
    {
        self::assertSame(0.0, $this->survey(0, 0)->liveShare());
    }

    #[Test]
    public function a_table_the_install_does_not_have_stays_null_rather_than_zero(): void
    {
        // "No form bundle installed" and "the form bundle collected nothing" are different
        // answers to a scoping question, and flattening them to 0 loses the one that matters.
        self::assertNull($this->survey(1, 1)->toArray()['volumes']['formSubmissions']);
        self::assertSame(42, $this->survey(1, 1)->toArray()['volumes']['media']);
    }

    #[Test]
    public function the_json_shape_carries_the_four_figures_a_comparison_ranks_on(): void
    {
        $array = $this->survey(500, 10_000)->toArray();

        foreach (['partClassCount', 'pageTypeCount', 'localeCount', 'volumes'] as $key) {
            self::assertArrayHasKey($key, $array);
        }

        self::assertSame(1, $array['partClassCount']);
    }
}
