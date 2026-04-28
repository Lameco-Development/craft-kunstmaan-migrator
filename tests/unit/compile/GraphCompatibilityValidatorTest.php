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
