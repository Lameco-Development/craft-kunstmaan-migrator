<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\finalize;

use craft\elements\Category;
use craft\elements\GlobalSet;
use Lameco\Kunstmaanmigrator\finalize\ReferenceFinalizeService;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Which element a shortlisted `elements_sites` row is.
 *
 * The shortlist is every element whose content still carries a legacy marker, whatever its
 * type. Loading it as an Entry dropped a global set or a category holding the same rich text
 * without a word.
 */
final class ReferenceFinalizeServiceCandidateTest extends TestCase
{
    private InMemoryElementWriter $writer;

    private MigrationReport $report;

    private function service(): ReferenceFinalizeService
    {
        $this->writer = new InMemoryElementWriter();
        $this->report = new MigrationReport();
        $service = new ReferenceFinalizeService();
        $service->elementWriter = $this->writer;

        return $service;
    }

    #[Test]
    public function a_global_set_is_loaded_as_the_type_its_row_names(): void
    {
        $service = $this->service();
        $globals = (new ReflectionClass(GlobalSet::class))->newInstanceWithoutConstructor();
        $this->writer->willFind(31, $globals, 6);

        self::assertSame($globals, $service->candidate(31, GlobalSet::class, 6, $this->report));
        self::assertSame([['id' => 31, 'siteId' => 6]], $this->writer->reads);
        self::assertSame([], $this->report->warnings);
    }

    #[Test]
    public function an_element_type_this_install_no_longer_has_is_warned_not_dropped(): void
    {
        $service = $this->service();

        self::assertNull($service->candidate(40, 'verbb\\gone\\elements\\Thing', 6, $this->report));
        self::assertSame([], $this->writer->reads);
        self::assertCount(1, $this->report->warnings);
        self::assertStringContainsString('verbb\\gone\\elements\\Thing', $this->report->warnings[0]);
    }

    #[Test]
    public function a_row_whose_element_cannot_be_loaded_is_warned(): void
    {
        $service = $this->service();

        self::assertNull($service->candidate(41, Category::class, 6, $this->report));
        self::assertCount(1, $this->report->warnings);
        self::assertStringContainsString('element 41 site 6', $this->report->warnings[0]);
    }
}
