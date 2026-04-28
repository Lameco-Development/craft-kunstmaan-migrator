<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\support;

use lameco\kunstmaanmigrator\source\CraftGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;

final class GraphFixtureFactory
{
    /**
     * Covers the normalized pagepartUsages registry without duplicating pagepart definitions per page.
     *
     * @return array<string, mixed>
     */
    public static function kunstmaanNewsEmployeeGraph(): array
    {
        $newsRef = KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage');
        $employeeRef = KunstmaanGraphContract::entityRef('App\\Entity\\Employee');
        $relationRef = $newsRef . '.employee';

        return [
            KunstmaanGraphContract::KEY_GRAPH_VERSION => KunstmaanGraphContract::GRAPH_VERSION,
            KunstmaanGraphContract::KEY_ROOTS => [
                $newsRef => [
                    'fqcn' => 'App\\Entity\\Pages\\NewsPage',
                    'entityRef' => $newsRef,
                    'table' => 'lameco_websitebundle_newspages',
                ],
            ],
            KunstmaanGraphContract::KEY_ENTITIES => [
                $newsRef => [
                    'fqcn' => 'App\\Entity\\Pages\\NewsPage',
                    'table' => 'lameco_websitebundle_newspages',
                    'columns' => ['id', 'title', 'employee_id', 'image_id'],
                ],
                $employeeRef => [
                    'fqcn' => 'App\\Entity\\Employee',
                    'table' => 'lameco_websitebundle_employee_employees',
                    'columns' => ['id', 'name', 'function'],
                    'inboundOwners' => [
                        [
                            'ownerRef' => $newsRef,
                            'ownerFqcn' => 'App\\Entity\\Pages\\NewsPage',
                            'property' => 'employee',
                            'fkColumn' => 'employee_id',
                            'relationRef' => $relationRef,
                        ],
                    ],
                ],
            ],
            KunstmaanGraphContract::KEY_RELATIONS => [
                $relationRef => [
                    'sourceRef' => $newsRef,
                    'targetRef' => $employeeRef,
                    'relationType' => 'ManyToOne',
                    'property' => 'employee',
                    'fkColumn' => 'employee_id',
                    'intentCandidates' => [
                        KunstmaanGraphContract::INTENT_REFERENCE,
                        KunstmaanGraphContract::INTENT_PROMOTE,
                        KunstmaanGraphContract::INTENT_DROP,
                        KunstmaanGraphContract::INTENT_OUT_OF_SCOPE,
                    ],
                ],
            ],
            KunstmaanGraphContract::KEY_ASSETS => [
                $newsRef . '.image_id' => [
                    'ownerRef' => $newsRef,
                    'column' => 'image_id',
                ],
            ],
            KunstmaanGraphContract::KEY_TABLES => [
                'lameco_websitebundle_newspages' => ['entityRef' => $newsRef],
                'lameco_websitebundle_employee_employees' => ['entityRef' => $employeeRef],
            ],
            KunstmaanGraphContract::KEY_SAMPLES => [
                $newsRef . '.employee_id' => [97],
                $employeeRef . '.name' => ['Jane Doe'],
            ],
            KunstmaanGraphContract::KEY_PAGEPARTS => [],
            KunstmaanGraphContract::KEY_PAGEPART_USAGES => [],
            KunstmaanGraphContract::KEY_CONSTRAINTS => [],
        ];
    }

    /**
     * Covers the normalized matrixUsages registry without duplicating Matrix block definitions per entry type.
     *
     * @return array<string, mixed>
     */
    public static function kunstmaanHomePagepartGraph(): array
    {
        $homeRef = KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\HomePage');
        $textPartRef = KunstmaanGraphContract::pagepartRef('Kunstmaan\\PagePartBundle\\Entity\\TextPagePart');
        $usageRef = $homeRef . '.main.' . $textPartRef;

        return [
            KunstmaanGraphContract::KEY_GRAPH_VERSION => KunstmaanGraphContract::GRAPH_VERSION,
            KunstmaanGraphContract::KEY_ROOTS => [
                $homeRef => [
                    'fqcn' => 'App\\Entity\\Pages\\HomePage',
                    'entityRef' => $homeRef,
                    'table' => 'lameco_websitebundle_homepages',
                ],
            ],
            KunstmaanGraphContract::KEY_ENTITIES => [
                $homeRef => [
                    'fqcn' => 'App\\Entity\\Pages\\HomePage',
                    'table' => 'lameco_websitebundle_homepages',
                    'columns' => ['id', 'title'],
                ],
            ],
            KunstmaanGraphContract::KEY_PAGEPARTS => [
                $textPartRef => [
                    'fqcn' => 'Kunstmaan\\PagePartBundle\\Entity\\TextPagePart',
                    'table' => 'kuma_main_pageparts',
                    'columns' => ['title', 'content'],
                ],
            ],
            KunstmaanGraphContract::KEY_PAGEPART_USAGES => [
                $usageRef => [
                    'pageRootRef' => $homeRef,
                    'context' => 'main',
                    'pagepartRef' => $textPartRef,
                    'sourceTable' => 'kuma_main_pageparts',
                    'orderColumn' => 'weight',
                ],
            ],
            KunstmaanGraphContract::KEY_RELATIONS => [],
            KunstmaanGraphContract::KEY_ASSETS => [],
            KunstmaanGraphContract::KEY_TABLES => [
                'lameco_websitebundle_homepages' => ['entityRef' => $homeRef],
                'kuma_main_pageparts' => ['pagepartRef' => $textPartRef],
            ],
            KunstmaanGraphContract::KEY_SAMPLES => [
                $textPartRef . '.content' => ['Intro body'],
            ],
            KunstmaanGraphContract::KEY_CONSTRAINTS => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function craftNewsHomeGraph(): array
    {
        $newsRoot = CraftGraphContract::craftEntryTypeRef('newsPage');
        $homeRoot = CraftGraphContract::craftEntryTypeRef('homePage');
        $teamField = CraftGraphContract::craftFieldRef('newsPage', 'caseTeamMembers');
        $imageField = CraftGraphContract::craftFieldRef('newsPage', 'image');
        $pageBuilderField = CraftGraphContract::craftFieldRef('homePage', 'pageBuilder');
        $textBlock = CraftGraphContract::matrixBlockRef('pageBuilder', 'textContentBlock');

        return [
            CraftGraphContract::KEY_GRAPH_VERSION => CraftGraphContract::GRAPH_VERSION,
            CraftGraphContract::KEY_ROOTS => [
                $newsRoot => ['handle' => 'newsPage', 'section' => 'news'],
                $homeRoot => ['handle' => 'homePage', 'section' => 'home'],
            ],
            CraftGraphContract::KEY_ENTRY_TYPES => [
                $newsRoot => [
                    'handle' => 'newsPage',
                    'nativeFields' => ['title', 'slug'],
                    'fieldRefs' => [$teamField, $imageField],
                ],
                $homeRoot => [
                    'handle' => 'homePage',
                    'nativeFields' => ['title', 'slug'],
                    'fieldRefs' => [$pageBuilderField],
                ],
            ],
            CraftGraphContract::KEY_FIELDS => [
                $teamField => [
                    'handle' => 'caseTeamMembers',
                    'type' => 'Entries',
                    'targetRefs' => [CraftGraphContract::craftEntryTypeRef('teamMember')],
                ],
                $imageField => [
                    'handle' => 'image',
                    'type' => 'Assets',
                    'volumeRefs' => ['craft.assetVolume:images'],
                ],
                $pageBuilderField => [
                    'handle' => 'pageBuilder',
                    'type' => 'Matrix',
                    'matrixBlockRefs' => [$textBlock],
                ],
            ],
            CraftGraphContract::KEY_MATRIX_BLOCK_TYPES => [
                $textBlock => [
                    'matrixField' => 'pageBuilder',
                    'entryType' => 'textContentBlock',
                    'fields' => ['heading', 'content'],
                ],
            ],
            CraftGraphContract::KEY_MATRIX_USAGES => [
                $homeRoot . '.pageBuilder.textContentBlock' => [
                    'entryTypeRef' => $homeRoot,
                    'fieldRef' => $pageBuilderField,
                    'blockRef' => $textBlock,
                ],
            ],
            CraftGraphContract::KEY_RELATION_TARGETS => [
                CraftGraphContract::craftEntryTypeRef('teamMember') => [
                    'section' => 'team',
                    'entryType' => 'teamMember',
                ],
            ],
            CraftGraphContract::KEY_ASSET_VOLUMES => [
                'craft.assetVolume:images' => ['handle' => 'images'],
            ],
            CraftGraphContract::KEY_CONSTRAINTS => [
                $newsRoot . '.required' => ['title', 'slug'],
                $homeRoot . '.required' => ['title', 'slug'],
            ],
        ];
    }
}
