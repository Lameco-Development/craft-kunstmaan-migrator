<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\GraphCompatibilityValidator;
use lameco\kunstmaanmigrator\source\CraftGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;
use lameco\kunstmaanmigrator\tests\support\GraphFixtureFactory;
use PHPUnit\Framework\TestCase;

final class GraphCompatibilityValidatorTest extends TestCase
{
    public function testMissingGraphVersionUnknownRefsAndRequiredRelationIntentAreFatal(): void
    {
        $validator = new GraphCompatibilityValidator();
        $rows = $validator->validate([
            'proposals' => [
                [
                    'sourceRef' => 'kunstmaan.relation:missing',
                    'targetRef' => 'craft.field:missing.nope',
                ],
            ],
        ], [], []);

        $codes = array_column($rows, 'code');
        self::assertContains('missing_graph_version', $codes);
        self::assertContains('unknown_source_ref', $codes);
        self::assertContains('unknown_target_ref', $codes);
    }

    public function testAcceptedRelationIntentSatisfiesRelationEvidence(): void
    {
        $kunstmaanGraph = GraphFixtureFactory::kunstmaanNewsEmployeeGraph();
        $craftGraph = GraphFixtureFactory::craftNewsHomeGraph();
        $newsRef = KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage');

        $rows = (new GraphCompatibilityValidator())->validate([
            'proposals' => [
                [
                    'sourceRef' => $newsRef . '.employee',
                    'targetRef' => CraftGraphContract::craftFieldRef('newsPage', 'caseTeamMembers'),
                    'relationIntent' => KunstmaanGraphContract::INTENT_PROMOTE,
                ],
            ],
        ], $kunstmaanGraph, $craftGraph);

        self::assertSame([], array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['code'] === 'relation_intent_required',
        )));
    }

    public function testUnknownSourceRefOutsideScopedGraphIsWarningNotFatal(): void
    {
        $kunstmaanGraph = GraphFixtureFactory::kunstmaanNewsEmployeeGraph();
        unset($kunstmaanGraph[KunstmaanGraphContract::KEY_ROOTS][KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\HomePage')]);
        unset($kunstmaanGraph[KunstmaanGraphContract::KEY_ENTITIES][KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\HomePage')]);

        $rows = (new GraphCompatibilityValidator())->validate([
            'proposals' => [
                [
                    'sourceRef' => KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\HomePage') . '.bannerImage',
                    'targetRef' => CraftGraphContract::craftFieldRef('homePage', 'headerHome'),
                ],
            ],
        ], $kunstmaanGraph, GraphFixtureFactory::craftNewsHomeGraph());

        $unknownRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['code'] === 'unknown_source_ref',
        ));
        self::assertSame('warning', $unknownRows[0]['severity']);
    }

    public function testUnknownSourceRefInsideScopedGraphRemainsFatal(): void
    {
        $rows = (new GraphCompatibilityValidator())->validate([
            'proposals' => [
                [
                    'sourceRef' => KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage') . '.doesNotExist',
                    'targetRef' => CraftGraphContract::craftFieldRef('newsPage', 'image'),
                ],
            ],
        ], GraphFixtureFactory::kunstmaanNewsEmployeeGraph(), GraphFixtureFactory::craftNewsHomeGraph());

        $unknownRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['code'] === 'unknown_source_ref',
        ));
        self::assertSame('fatal', $unknownRows[0]['severity']);
    }

    public function testMissingRelationIntentIsWarningUntilOperatorDecisionExists(): void
    {
        $rows = (new GraphCompatibilityValidator())->validate([
            'proposals' => [],
        ], GraphFixtureFactory::kunstmaanNewsEmployeeGraph(), GraphFixtureFactory::craftNewsHomeGraph());

        $relationRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['code'] === 'relation_intent_required',
        ));

        self::assertNotEmpty($relationRows);
        self::assertSame('warning', $relationRows[0]['severity']);
    }

    public function testProposedRelationIntentDoesNotSatisfyRelationEvidence(): void
    {
        $kunstmaanGraph = GraphFixtureFactory::kunstmaanNewsEmployeeGraph();
        $craftGraph = GraphFixtureFactory::craftNewsHomeGraph();
        $newsRef = KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage');

        $rows = (new GraphCompatibilityValidator())->validate([
            'proposals' => [
                [
                    'status' => 'proposed',
                    'sourceRef' => $newsRef . '.employee',
                    'targetRef' => CraftGraphContract::craftFieldRef('newsPage', 'caseTeamMembers'),
                    'relationIntent' => KunstmaanGraphContract::INTENT_PROMOTE,
                ],
            ],
        ], $kunstmaanGraph, $craftGraph);

        self::assertNotEmpty(array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['code'] === 'relation_intent_required',
        )));
    }

    public function testMatrixRelationAndAssetCompatibilityAreValidated(): void
    {
        $kunstmaanGraph = GraphFixtureFactory::kunstmaanNewsEmployeeGraph();
        $craftGraph = GraphFixtureFactory::craftNewsHomeGraph();
        $newsRef = KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage');

        $rows = (new GraphCompatibilityValidator())->validate([
            'proposals' => [
                [
                    'sourceRef' => $newsRef . '.employee',
                    'targetRef' => CraftGraphContract::craftFieldRef('homePage', 'pageBuilder'),
                    'targetBlockRef' => CraftGraphContract::matrixBlockRef('wrongMatrix', 'textContentBlock'),
                    'relationIntent' => KunstmaanGraphContract::INTENT_REFERENCE,
                ],
                [
                    'sourceRef' => $newsRef . '.employee',
                    'targetRef' => CraftGraphContract::craftFieldRef('newsPage', 'caseTeamMembers'),
                    'relationTargetRef' => CraftGraphContract::craftEntryTypeRef('missingTarget'),
                    'relationIntent' => KunstmaanGraphContract::INTENT_REFERENCE,
                ],
                [
                    'sourceRef' => $newsRef . '.image_id',
                    'targetRef' => CraftGraphContract::craftFieldRef('newsPage', 'image'),
                    'assetVolumeRef' => 'craft.assetVolume:missing',
                ],
            ],
        ], $kunstmaanGraph, $craftGraph);

        $codes = array_column($rows, 'code');
        self::assertContains('matrix_block_not_allowed', $codes);
        self::assertContains('relation_target_incompatible', $codes);
        self::assertContains('asset_volume_incompatible', $codes);
    }

    public function testFieldOwnershipMismatchIsFatal(): void
    {
        $craftGraph = GraphFixtureFactory::craftNewsHomeGraph();
        $craftGraph[CraftGraphContract::KEY_FIELDS][CraftGraphContract::craftFieldRef('newsPage', 'image')]['entryTypeRef'] =
            CraftGraphContract::craftEntryTypeRef('newsPage');

        $rows = (new GraphCompatibilityValidator())->validate([
            'proposals' => [
                [
                    'sourceRef' => KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\HomePage'),
                    'targetRef' => CraftGraphContract::craftFieldRef('newsPage', 'image'),
                    'targetEntryTypeRef' => CraftGraphContract::craftEntryTypeRef('homePage'),
                ],
            ],
        ], GraphFixtureFactory::kunstmaanHomePagepartGraph(), $craftGraph);

        self::assertContains('field_not_owned_by_entry_type', array_column($rows, 'code'));
    }
}
