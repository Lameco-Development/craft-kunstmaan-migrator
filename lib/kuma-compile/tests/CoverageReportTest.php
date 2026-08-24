<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Legacy\LiveSnapshot;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Report\Coverage;
use Lameco\KumaCompile\Report\CoverageReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One measurement, three readings, rendered identically by the standalone
 * `coverage`, the Craft `mapping/coverage` and the migrate preflight.
 */
final class CoverageReportTest extends TestCase
{
    private function mapping(): Mapping
    {
        return Mapping::fromArray([
            'version' => 1,
            'environments' => [
                'COM' => ['database' => 'legacy', 'locales' => ['en' => 'comEnUs', 'sp' => null]],
                'ARCHIVE' => ['locales' => []],
            ],
            'pages' => ['ContentPage' => ['entryType' => 'contentPage']],
            'parts' => ['Text' => ['block' => 'contentBlock'], 'Legacy' => ['drop' => 'obsolete widget']],
            'unmapped' => ['parts' => ['RowStart' => 'layout bracket'], 'pageTypes' => []],
        ]);
    }

    private function snapshot(): LiveSnapshot
    {
        return new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['Text' => 100, 'RowStart' => 5, 'Legacy' => 3, 'Hero' => 12],
            pageTypes: ['ContentPage' => 20, 'NewsPage' => 4],
            pagesByLocale: ['en' => 21, 'sp' => 3],
            allPartRefs: 3_000,
        );
    }

    #[Test]
    public function the_mapping_names_only_the_environments_it_can_read(): void
    {
        self::assertSame(['COM' => 'legacy'], $this->mapping()->databases());
    }

    #[Test]
    public function the_holes_are_one_line_per_unclaimed_class(): void
    {
        $coverage = new Coverage($this->mapping());
        $coverage->ingest($this->snapshot());

        $holes = (new CoverageReport($coverage))->holes();

        self::assertCount(2, $holes);
        self::assertStringStartsWith('pagepart  Hero', $holes[0]);
        self::assertStringContainsString('12 live placements', $holes[0]);
        self::assertStringStartsWith('page      NewsPage', $holes[1]);
    }

    #[Test]
    public function the_array_view_is_the_whole_measurement(): void
    {
        $coverage = new Coverage($this->mapping());
        $coverage->ingest($this->snapshot());

        $view = (new CoverageReport($coverage))->toArray();

        self::assertSame(
            ['placements', 'pages', 'liveShare', 'byLane', 'unclaimedParts', 'unclaimedPageTypes', 'staleParts', 'strandedLocales', 'omissions', 'holes'],
            array_keys($view),
        );
        self::assertSame(120, $view['placements']);
        self::assertSame(0.04, $view['liveShare']);
        self::assertSame(['Hero' => 12], $view['unclaimedParts']);
        self::assertSame(['COM:sp' => 3], $view['strandedLocales']);
        self::assertTrue($view['holes']);
    }

    #[Test]
    public function the_markdown_names_its_measurer_and_speaks_the_clients_language(): void
    {
        $coverage = new Coverage($this->mapping());
        $coverage->ingest($this->snapshot());

        $markdown = (new CoverageReport($coverage))->markdown('craft kunstmaan-migrator/mapping/coverage', '2026-08-24');

        self::assertStringContainsString('on 2026-08-24 by `craft kunstmaan-migrator/mapping/coverage`', $markdown);
        self::assertStringContainsString('| page content | 100 |', $markdown);
        self::assertStringContainsString('| `Legacy` | pagepart, dropped | 3 | obsolete widget |', $markdown);
        self::assertStringContainsString('## Languages with nowhere to go', $markdown);
        self::assertStringContainsString('## Unaccounted for — not yet decided', $markdown);
        self::assertStringContainsString('| `Hero` (content block) | 12 |', $markdown);
        self::assertStringNotContainsString('sequence', $markdown, 'lane names are translated for the client');
    }

    #[Test]
    public function a_clean_measurement_says_nothing_is_unaccounted_for(): void
    {
        $coverage = new Coverage($this->mapping());
        $coverage->ingest(new LiveSnapshot('COM', ['Text' => 10], ['ContentPage' => 2], ['en' => 2], 10));

        $markdown = (new CoverageReport($coverage))->markdown('kuma-compile coverage', '2026-08-24');

        self::assertStringContainsString('## Nothing is unaccounted for', $markdown);
        self::assertSame([], (new CoverageReport($coverage))->holes());
    }
}
