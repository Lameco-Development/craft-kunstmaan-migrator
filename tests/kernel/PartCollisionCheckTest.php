<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\MappingCheck;
use Lameco\Kunstmaanmigrator\Report\Coverage;
use Lameco\Kunstmaanmigrator\Report\CoverageReport;
use Lameco\Kunstmaanmigrator\Source\EntityTableIndex;
use Lameco\Kunstmaanmigrator\Source\Introspection;
use Lameco\Kunstmaanmigrator\Source\LiveSnapshot;
use Lameco\Kunstmaanmigrator\Source\PartClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A short-name row reads one table. When two live classes share that short name, it reads the
 * second class's placements from the first class's table by id — the Berkvens NL `Text` row did
 * that to 405 Kunstmaan placements without counting a single loss. `validate --live` refuses it.
 */
final class PartCollisionCheckTest extends TestCase
{
    private const APP_TEXT = 'App\Entity\PageParts\TextPagePart';
    private const KM_TEXT = 'Kunstmaan\PagePartBundle\Entity\TextPagePart';

    private function mapping(string $parts): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, <<<YAML
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            pages:
              ContentPage: { entryType: contentPage, table: content_pages, section: pages, ignore: { id: key } }
            {$parts}
            YAML);

        return Mapping::fromFile($path);
    }

    #[Test]
    public function a_short_name_row_reading_a_table_for_two_live_classes_is_refused(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
            YAML);

        $verdict = (new MappingCheck(null, [self::APP_TEXT => 133, self::KM_TEXT => 405]))->verdict($mapping);

        self::assertNotNull($verdict);
        self::assertSame('Short-name rows that read one table for several live classes', $verdict[0]);
        self::assertSame([
            '`Text` reads `app_text_parts` for 2 live classes — App\Entity\PageParts\TextPagePart (133), '
            . 'Kunstmaan\PagePartBundle\Entity\TextPagePart (405). Key a row by each class\'s fully qualified name, '
            . 'so each reads its own table',
        ], $verdict[1]);
    }

    #[Test]
    public function a_qualified_row_for_one_class_resolves_the_collision(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
              Kunstmaan\PagePartBundle\Entity\TextPagePart: { table: kuma_text_page_parts, block: textBlock, map: { body: content } }
            YAML);

        self::assertNull((new MappingCheck(null, [self::APP_TEXT => 133, self::KM_TEXT => 405]))->verdict($mapping));
    }

    #[Test]
    public function a_class_with_no_live_placements_is_no_collision(): void
    {
        // Berkvens NL `Video`: the app class exists, but every live placement is Kunstmaan's.
        $mapping = $this->mapping(<<<'YAML'
            parts:
              Text: { table: kuma_text_page_parts, block: textBlock, map: { body: content } }
            YAML);

        self::assertNull((new MappingCheck(null, [self::KM_TEXT => 405]))->verdict($mapping));
    }

    #[Test]
    public function a_short_name_row_that_reads_no_table_may_cover_both_classes(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, manual: "rebuilt by hand, either class" }
            YAML);

        self::assertNull((new MappingCheck(null, [self::APP_TEXT => 133, self::KM_TEXT => 405]))->verdict($mapping));
    }

    #[Test]
    public function without_live_counts_the_verdict_is_what_it_was(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
            YAML);

        self::assertNull((new MappingCheck())->verdict($mapping));
    }

    private function coverage(Mapping $mapping): Coverage
    {
        // What `livePartPlacements()` reports: the colliding classes by their qualified names.
        $coverage = new Coverage($mapping);
        $coverage->ingest(new LiveSnapshot(
            environment: 'COM',
            partPlacements: [self::KM_TEXT => 405, self::APP_TEXT => 133, 'Video' => 38],
            pageTypes: ['ContentPage' => 20],
            pagesByLocale: ['en' => 20],
            allPartRefs: 10_000,
        ));

        return $coverage;
    }

    #[Test]
    public function coverage_counts_each_class_of_a_shared_short_name_under_the_row_that_claims_it(): void
    {
        $coverage = $this->coverage($this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
              Kunstmaan\PagePartBundle\Entity\TextPagePart: { table: kuma_text_page_parts, drop: superseded by the app's text }
              Video: { table: kuma_video_page_parts, manual: rebuilt by hand }
            YAML));

        self::assertFalse($coverage->hasHoles());
        self::assertSame(['dropped' => 405, 'blocks' => 133, 'manual' => 38], $coverage->placementsByLane());
        self::assertSame([], $coverage->staleParts());
        self::assertSame(
            [self::KM_TEXT => 405, 'Video' => 38],
            array_column($coverage->declaredOmissions(), 'placements', 'subject'),
        );
    }

    #[Test]
    public function coverage_reports_both_classes_as_holes_while_one_short_name_row_reads_a_table_for_them(): void
    {
        $coverage = $this->coverage($this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
              Video: { table: kuma_video_page_parts, manual: rebuilt by hand }
            YAML));

        self::assertTrue($coverage->hasHoles());
        self::assertSame([self::KM_TEXT => 405, self::APP_TEXT => 133], $coverage->unclaimedParts());
        self::assertSame(['UNCLAIMED' => 538, 'manual' => 38], $coverage->placementsByLane());
        self::assertSame([], $coverage->staleParts(), 'the row is reached; it is ambiguous, not stale');
        self::assertContains(
            'pagepart  `Text` reads `app_text_parts` for 2 live classes — key a row by each class\'s fully qualified name',
            (new CoverageReport($coverage))->holes(),
        );
    }

    #[Test]
    public function a_qualified_row_on_a_class_that_collides_nowhere_still_claims_it(): void
    {
        $coverage = $this->coverage($this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
              Kunstmaan\PagePartBundle\Entity\TextPagePart: { table: kuma_text_page_parts, drop: superseded }
              Kunstmaan\MediaPagePartBundle\Entity\VideoPagePart: { table: kuma_video_page_parts, manual: rebuilt by hand }
            YAML));

        self::assertFalse($coverage->hasHoles());
        self::assertSame([], $coverage->staleParts());
    }

    #[Test]
    public function a_qualified_key_is_refused_in_a_lane_that_reads_only_short_names(): void
    {
        // The forms and globals lanes read their placements by short name; a qualified key
        // there would match nothing and migrate nothing, silently.
        $mapping = $this->mapping(<<<'YAML'
            forms:
              context: form
              target: formie
              emit: { block: formBlock, field: form }
              fields:
                Kunstmaan\FormBundle\Entity\PageParts\SingleLineTextPagePart:
                  table: kuma_single_line_text_page_parts
                  type: singleLineText
                  map: { label: label }
            YAML);

        $verdict = (new MappingCheck())->verdict($mapping);

        self::assertNotNull($verdict);
        self::assertContains(
            '`Kunstmaan\FormBundle\Entity\PageParts\SingleLineTextPagePart` in `forms`: a fully qualified class key is read in `parts:` and `unmapped.parts:` only',
            $verdict[1],
        );
    }

    #[Test]
    public function an_environment_where_only_one_class_of_the_name_is_live_still_counts_it_under_its_own_row(): void
    {
        // Nothing collides there, so the corpus reports Kunstmaan's Text as `Text`; compile still
        // reads it through its qualified row, and coverage has to agree.
        $coverage = new Coverage($this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
              Kunstmaan\PagePartBundle\Entity\TextPagePart: { table: kuma_text_page_parts, drop: superseded }
            YAML));
        $coverage->ingest(new LiveSnapshot(
            environment: 'COM',
            partPlacements: ['Text' => 36],
            pageTypes: ['ContentPage' => 5],
            pagesByLocale: ['en' => 5],
            allPartRefs: 1_000,
            partClasses: [self::KM_TEXT => 36],
        ));

        self::assertSame(['dropped' => 36], $coverage->placementsByLane());
        self::assertSame(['Text'], $coverage->staleParts());
    }
    #[Test]
    public function a_collision_split_across_two_databases_is_a_hole_to_coverage_and_validate_alike(): void
    {
        // The app's Text is live only in COM, Kunstmaan's only in DE: neither database sees two
        // classes of the name, but the one `Text` row still reads one table for both.
        $mapping = $this->mapping(<<<'YAML'
            parts:
              Text: { table: app_text_parts, block: textBlock, map: { body: text } }
            YAML);
        $environments = [
            'COM' => [self::APP_TEXT => 133],
            'DE' => [self::KM_TEXT => 405],
        ];

        $coverage = new Coverage($mapping);
        $live = [];

        foreach ($environments as $environment => $classes) {
            $coverage->ingest(new LiveSnapshot(
                environment: $environment,
                partPlacements: ['Text' => array_sum($classes)],
                pageTypes: ['ContentPage' => 5],
                pagesByLocale: ['en' => 5],
                allPartRefs: 1_000,
                partClasses: $classes,
            ));
            $live = PartClass::tally($live, $classes);
        }

        self::assertTrue($coverage->hasHoles());
        self::assertSame(
            [['key' => 'Text', 'table' => 'app_text_parts', 'classes' => [self::APP_TEXT => 133, self::KM_TEXT => 405]]],
            $coverage->unresolvedCollisions(),
        );
        self::assertNotNull((new MappingCheck(null, $live))->verdict($mapping));
    }
    private const APP_MAPS = 'App\Entity\PageParts\GoogleMapsPagePart';
    private const MASTER_MAPS = 'Lameco\MasterBundle\Entity\PageParts\GoogleMapsPagePart';

    /** @param array<string, string> $tables fully qualified class => table */
    private static function tables(array $tables): EntityTableIndex
    {
        return EntityTableIndex::fromIntrospection(Introspection::fromArray([
            'entities' => array_map(static fn(string $table): array => ['table' => $table], $tables),
        ]));
    }

    #[Test]
    public function two_classes_reading_the_same_table_are_no_collision(): void
    {
        // The app's GoogleMaps extends the bundle's and keeps its table: one row reads both right.
        $mapping = $this->mapping(<<<'YAML'
            parts:
              GoogleMaps: { table: google_maps_page_parts, block: mapBlock, map: { address: address } }
            YAML);
        $live = [self::APP_MAPS => 12, self::MASTER_MAPS => 30];
        $tables = self::tables([self::APP_MAPS => 'google_maps_page_parts', self::MASTER_MAPS => 'google_maps_page_parts']);

        self::assertNull((new MappingCheck(null, $live, $tables))->verdict($mapping));

        $coverage = new Coverage($mapping, $tables);
        $coverage->ingest(new LiveSnapshot(
            environment: 'COM',
            partPlacements: $live,
            pageTypes: ['ContentPage' => 5],
            pagesByLocale: ['en' => 5],
            allPartRefs: 1_000,
            partClasses: $live,
        ));

        self::assertFalse($coverage->hasHoles());
        self::assertSame(['blocks' => 42], $coverage->placementsByLane());
    }

    #[Test]
    public function a_class_whose_table_the_index_does_not_know_still_collides(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            parts:
              GoogleMaps: { table: google_maps_page_parts, block: mapBlock, map: { address: address } }
            YAML);
        $live = [self::APP_MAPS => 12, self::MASTER_MAPS => 30];

        self::assertNotNull((new MappingCheck(null, $live, self::tables([self::APP_MAPS => 'google_maps_page_parts'])))->verdict($mapping));
        self::assertNotNull((new MappingCheck(null, $live, self::tables([
            self::APP_MAPS => 'app_google_maps_page_parts',
            self::MASTER_MAPS => 'google_maps_page_parts',
        ])))->verdict($mapping));
    }
}
