<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Legacy\LiveSnapshot;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Report\Coverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: legacy
            locales:
              en: comEnUs
              sp: ~
        pages:
          ContentPage: { entryType: contentPage }
        parts:
          Text:   { block: contentBlock }
          Header: { consumedBy: sequence }
        unmapped:
          parts:
            RowStart: "layout bracket"
          pageTypes:
            FooterPage: "globals lane"
        YAML;

    private function coverage(LiveSnapshot $snapshot): Coverage
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        $coverage = new Coverage(Mapping::fromFile($path));
        $coverage->ingest($snapshot);

        return $coverage;
    }

    #[Test]
    public function a_fully_claimed_corpus_has_no_holes(): void
    {
        $c = $this->coverage(new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['Text' => 100, 'Header' => 40, 'RowStart' => 5],
            pageTypes: ['ContentPage' => 20, 'FooterPage' => 1],
            pagesByLocale: ['en' => 21],
            allPartRefs: 3_000,
        ));

        self::assertFalse($c->hasHoles());
        self::assertSame(['blocks' => 100, 'sequence' => 40, 'unmapped' => 5], $c->placementsByLane());
        self::assertSame(145, $c->totalPlacements());
    }

    #[Test]
    public function live_content_no_lane_claims_is_a_hole(): void
    {
        $c = $this->coverage(new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['Text' => 100, 'Salesforce' => 39],
            pageTypes: ['ContentPage' => 20, 'ZipcodeCheckPage' => 5],
            pagesByLocale: ['en' => 25],
            allPartRefs: 3_000,
        ));

        self::assertTrue($c->hasHoles());
        self::assertSame(['Salesforce' => 39], $c->unclaimedParts());
        self::assertSame(['ZipcodeCheckPage' => 5], $c->unclaimedPageTypes());
    }

    #[Test]
    public function a_locale_with_no_craft_site_is_reported_with_the_pages_it_strands(): void
    {
        $c = $this->coverage(new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['Text' => 10],
            pageTypes: ['ContentPage' => 30],
            pagesByLocale: ['en' => 25, 'sp' => 5],
            allPartRefs: 100,
        ));

        self::assertSame(['COM:sp' => 5], $c->strandedLocales());
    }

    #[Test]
    public function live_share_exposes_how_much_of_the_table_is_superseded_versions(): void
    {
        $c = $this->coverage(new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['Text' => 50],
            pageTypes: ['ContentPage' => 10],
            pagesByLocale: ['en' => 10],
            allPartRefs: 1_000,
        ));

        self::assertSame(0.05, $c->liveShare());
    }

    #[Test]
    public function a_declared_omission_carries_its_reason_and_its_cost(): void
    {
        // The client-facing half. "RowStart is unmapped" is a mapping fact; "76 placements, and
        // here is the reason it was declared under" is something a client can decide about.
        $omissions = $this->coverage(new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['Text' => 100, 'RowStart' => 76],
            pageTypes: ['ContentPage' => 20, 'FooterPage' => 1],
            pagesByLocale: ['en' => 21],
            allPartRefs: 3_000,
        ))->declaredOmissions();

        self::assertSame(
            [
                ['subject' => 'RowStart', 'kind' => 'pagepart, not migrated', 'reason' => 'layout bracket', 'placements' => 76],
                ['subject' => 'FooterPage', 'kind' => 'page type, not migrated', 'reason' => 'globals lane', 'placements' => 1],
            ],
            $omissions,
        );
    }

    #[Test]
    public function omissions_lead_with_the_one_that_costs_most(): void
    {
        $omissions = $this->coverage(new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['RowStart' => 2],
            pageTypes: ['FooterPage' => 900],
            pagesByLocale: ['en' => 900],
            allPartRefs: 10,
        ))->declaredOmissions();

        self::assertSame('FooterPage', $omissions[0]['subject']);
    }
}
