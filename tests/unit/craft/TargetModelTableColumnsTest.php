<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\TargetModel;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\Target\TargetCheck;
use Lameco\Kunstmaanmigrator\tests\kernel\ChildCollectionTargetsTest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The live schema knows a Table field's column handles, so `mapping check` against the running
 * site rejects a misspelt column the way the project-config reader does. Craft's Table field
 * reads a row by colId or handle only: a key that is neither is dropped from every row.
 */
final class TargetModelTableColumnsTest extends TestCase
{
    /** @param array<string, array<string, array<string, mixed>>> $slots entry type => field => placement */
    private function model(array $slots): TargetModel
    {
        return new TargetModel(new class($slots) implements SchemaGateway {
            /** @param array<string, array<string, array<string, mixed>>> $slots */
            public function __construct(private readonly array $slots)
            {
            }
            public function sectionByHandle(string $h): ?array
            {
                return ['id' => 1, 'handle' => $h];
            }
            public function entryTypeByHandle(string $h): ?array
            {
                return isset($this->slots[$h]) ? ['id' => 1, 'handle' => $h, 'hasTitleFormat' => false] : null;
            }
            public function primarySite(): array
            {
                return ['id' => 1, 'handle' => 'berkvensNl'];
            }
            public function siteByHandle(string $h): ?array
            {
                return null;
            }
            public function fieldHandlesFor(string $t): array
            {
                return array_keys($this->slots[$t] ?? []);
            }
            public function blockTypesFor(string $t, string $f): array
            {
                return [];
            }
            public function fieldSlotsFor(string $entryTypeHandle): array
            {
                /** @var array<string, array{type: string, required: bool, nested: list<string>, propagationMethod?: ?string, columns?: ?list<string>}> */
                return $this->slots[$entryTypeHandle] ?? [];
            }
        });
    }

    #[Test]
    public function a_table_slot_carries_the_column_handles_the_gateway_reports(): void
    {
        $model = $this->model(['projectPage' => [
            'openingHours' => ['type' => 'Table', 'required' => false, 'nested' => [], 'columns' => ['day', 'opening', 'closing']],
        ]]);

        self::assertSame(['day', 'opening', 'closing'], $model->slot('projectPage', 'openingHours')?->columns);
    }

    #[Test]
    public function a_gateway_that_reports_no_columns_leaves_them_unknown(): void
    {
        $model = $this->model(['projectPage' => [
            'heroTitle' => ['type' => 'PlainText', 'required' => false, 'nested' => []],
        ]]);

        self::assertNull($model->slot('projectPage', 'heroTitle')?->columns);
    }

    #[Test]
    public function the_live_target_check_rejects_a_column_the_table_lacks(): void
    {
        $model = $this->model([
            'projectPage' => [
                'pageBuilder' => ['type' => 'Matrix', 'required' => false, 'nested' => ['imageGalleryBlock']],
                'sliderImages' => ['type' => 'Assets', 'required' => false, 'nested' => []],
                'galleryImages' => ['type' => 'Assets', 'required' => false, 'nested' => []],
                'openingHours' => ['type' => 'Table', 'required' => false, 'nested' => [], 'columns' => ['day', 'opening', 'closing']],
            ],
            'imageGalleryBlock' => [
                'images' => ['type' => 'Assets', 'required' => false, 'nested' => []],
            ],
        ]);
        $yaml = str_replace(
            "galleryImages:\n        table: project_images\n        fk: project_page_id\n        map: { image: media_id | asset }",
            "openingHours:\n        table: structureddata_openinghour\n        fk: structured_data_id\n        map: { day: day, opens: opening }",
            ChildCollectionTargetsTest::MAPPING,
        );

        self::assertSame(
            ['page `ProjectPage`: Table `projectPage.openingHours` has no column `opens`'],
            (new TargetCheck($model))->check(Mapping::fromFile(ChildCollectionTargetsTest::mappingFile($yaml))),
        );
    }
}
