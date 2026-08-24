<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\MappingCompiler;
use PHPUnit\Framework\TestCase;

/**
 * 2026-05-18 — `MappingCompiler` must surface flat-row nodeClass
 * proposals as their own `nodeClasses[]` entries even though the FQCN
 * is absent from `pageStructure.json`. Flat-row entities (berkvens
 * `App\Entity\Configuration`, an `AbstractConfig` subclass) have no
 * `kuma_node` row, so the scanner that builds pageStructure skips them.
 * Without the dedicated post-pass, the flat-row nodeClass proposal
 * (emitted by the scaffolder with `flatRow: true`) would never compile
 * into a downstream `nodeClasses[]` entry and ExtractService would have
 * nothing to walk.
 */
final class MappingCompilerFlatRowTest extends TestCase
{
    public function testFlatRowNodeClassWithoutPageStructureCompilesIntoNodeClassesEntry(): void
    {
        $compiled = (new MappingCompiler())->compile(
            mapping: [
                'proposals' => [
                    [
                        'kind' => 'nodeClass',
                        'fqcn' => 'App\\Entity\\Configuration',
                        'sourceTable' => 'lameco_websitebundle_configuration',
                        'targetSection' => 'globalSettings',
                        'targetEntryType' => 'globalSettings',
                        'flatRow' => true,
                        'status' => 'accepted',
                    ],
                    [
                        'kind' => 'column',
                        'table' => 'lameco_websitebundle_configuration',
                        'column' => 'application_procedure_title',
                        'targetEntryType' => 'globalSettings',
                        'targetHandle' => 'applicationProcedureTitle',
                        'handler' => 'plain',
                        'status' => 'accepted',
                    ],
                ],
            ],
            pageStructure: [],
            sites: ['nl' => 'default'],
        );

        self::assertArrayHasKey(
            'App\\Entity\\Configuration',
            $compiled['nodeClasses'],
            'flat-row AbstractConfig must surface as a nodeClasses entry even without pageStructure.',
        );
        $nc = $compiled['nodeClasses']['App\\Entity\\Configuration'];
        self::assertTrue($nc['flatRow'] ?? false);
        self::assertSame('globalSettings', $nc['section']);
        self::assertSame('lameco_websitebundle_configuration', $nc['sourceTable']);
        self::assertSame([
            'handler' => 'plain',
            'source'  => 'application_procedure_title',
        ], $nc['fields']['applicationProcedureTitle']);
    }

    public function testFlatRowFalseRowsAreNotSurfacedFromTheFlatRowPostPass(): void
    {
        // Regression guard: a nodeClass proposal without `flatRow: true`
        // and without a matching pageStructure entry should NOT show up.
        // The pageStructure-driven loop is the only legitimate compile
        // path for non-flat node classes.
        $compiled = (new MappingCompiler())->compile(
            mapping: [
                'proposals' => [
                    [
                        'kind' => 'nodeClass',
                        'fqcn' => 'App\\Entity\\OrphanCustom',
                        'sourceTable' => 'orphan_custom',
                        'targetSection' => 'orphan',
                        'targetEntryType' => 'orphan',
                        'status' => 'accepted',
                    ],
                ],
            ],
            pageStructure: [],
            sites: ['nl' => 'default'],
        );

        self::assertArrayNotHasKey('App\\Entity\\OrphanCustom', $compiled['nodeClasses']);
    }
}
