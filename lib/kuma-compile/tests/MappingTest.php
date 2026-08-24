<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\MappingException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappingTest extends TestCase
{
    private function mapping(string $yaml): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    #[Test]
    public function it_rejects_a_missing_file(): void
    {
        $this->expectException(MappingException::class);

        Mapping::fromFile('/nonexistent/mapping.yaml');
    }

    #[Test]
    public function an_unmapped_locale_resolves_to_no_site(): void
    {
        $m = $this->mapping(<<<'YAML'
            version: 1
            environments:
              LV:
                database: legacy_lv
                locales:
                  lv: comLvLv
                  ru: !unmapped "no Craft site"
            YAML);

        $locales = $m->environments()['LV']['locales'];

        self::assertSame('comLvLv', $locales['lv']);
        self::assertNull($locales['ru'], 'an !unmapped locale must read as "no target", not as a truthy reason string');
    }

    #[Test]
    public function it_reports_only_open_conflicts_worst_first(): void
    {
        $m = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Small:
                live: 5
                conflict: { status: open, artifact: a, spec: b }
              Large:
                live: 500
                conflict: { status: open, artifact: c, spec: d }
              Settled:
                live: 999
                conflict: { status: decided, artifact: e, spec: f }
            YAML);

        $open = $m->openConflicts();

        self::assertCount(2, $open);
        self::assertSame('Large', $open[0]->subject, 'conflicts sort by live volume, worst first');
        self::assertSame('Small', $open[1]->subject);
    }

    #[Test]
    public function every_lane_claims_its_parts(): void
    {
        $m = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Text:   { block: contentBlock }
              Header: { consumedBy: sequence }
              Dead:   { drop: "no longer used" }
              Rare:   { manual: "3 placements" }
            forms:
              fields:
                Email: { type: email }
            globals:
              FooterPage:
                parts:
                  FooterBox: { table: t }
            unmapped:
              parts:
                RowStart: "layout bracket"
            YAML);

        self::assertSame([
            'Text' => 'blocks',
            'Header' => 'sequence',
            'Dead' => 'dropped',
            'Rare' => 'manual',
            'Email' => 'forms',
            'FooterBox' => 'globals',
            'RowStart' => 'unmapped',
        ], $m->accountedParts());
    }

    #[Test]
    public function it_collects_todos_from_anywhere_in_the_tree(): void
    {
        $m = $this->mapping(<<<'YAML'
            version: 1
            parts:
              Text:
                block: contentBlock
                todo: "confirm the column"
            forms:
              todo: "submissions not modelled"
            YAML);

        self::assertSame(['confirm the column', 'submissions not modelled'], $m->todos());
    }
}
