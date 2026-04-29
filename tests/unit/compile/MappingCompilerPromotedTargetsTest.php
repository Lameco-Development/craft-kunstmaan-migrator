<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use lameco\kunstmaanmigrator\source\CraftGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;
use PHPUnit\Framework\TestCase;

final class MappingCompilerPromotedTargetsTest extends TestCase
{
    public function testAcceptedPromotedRelationTargetCompilesWithStateSourceAndTargetContract(): void
    {
        $sourceRef = KunstmaanGraphContract::entityRef('App\\Entity\\Employee');
        $targetRef = CraftGraphContract::craftEntryTypeRef('teamMember');

        $compiled = (new MappingCompiler())->compile([
            'proposals' => [
                [
                    'kind' => 'promotedRelationTarget',
                    'status' => 'accepted',
                    'stateSource' => 'App_Entity_Employee',
                    'sourceRef' => $sourceRef,
                    'targetRef' => $targetRef,
                    'targetSection' => 'team',
                    'targetEntryType' => 'teamMember',
                    'relationIntent' => KunstmaanGraphContract::INTENT_PROMOTE,
                    'fields' => [
                        'name' => ['targetHandle' => 'title'],
                    ],
                ],
            ],
        ], [], ['nl' => 'default']);

        self::assertArrayHasKey('App_Entity_Employee', $compiled['promotedTargets']);
        self::assertSame($sourceRef, $compiled['promotedTargets']['App_Entity_Employee']['sourceRef']);
        self::assertSame($targetRef, $compiled['promotedTargets']['App_Entity_Employee']['targetRef']);
        self::assertSame('teamMember', $compiled['promotedTargets']['App_Entity_Employee']['targetEntryType']);
        self::assertSame(1, $compiled['_compileReport']['promotedTargetsEmitted']);
    }

    public function testIncompletePromotedRelationTargetIsSkippedWithWarning(): void
    {
        $compiled = (new MappingCompiler())->compile([
            'proposals' => [
                [
                    'kind' => 'promotedTarget',
                    'status' => 'accepted',
                    'sourceRef' => KunstmaanGraphContract::entityRef('App\\Entity\\Employee'),
                ],
            ],
        ], [], ['nl' => 'default']);

        self::assertSame([], $compiled['promotedTargets']);
        self::assertSame(0, $compiled['_compileReport']['promotedTargetsEmitted']);
        self::assertStringContainsString('promoted relation target', implode("\n", $compiled['_compileReport']['warnings']));
    }
}
