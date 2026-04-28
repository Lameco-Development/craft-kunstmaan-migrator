<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\transform;

use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\handlers\AssetHandler;
use lameco\kunstmaanmigrator\fields\handlers\MatrixHandler;
use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\fields\handlers\RelationHandler;
use lameco\kunstmaanmigrator\fields\handlers\SplitNameHandler;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\transform\TransformService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 — end-to-end loop closure for the implicit-content pipeline.
 *
 * Drives TransformService::run() with a synthetic page-part record exactly
 * shaped as ExtractService::buildImplicitContentPageParts() emits, against
 * a mapping shaped exactly as MappingCompiler::compileImplicitBlocks()
 * emits. Asserts a Matrix block with the right type + _sourcePartRef
 * lands on the page entry's pageBuilder field. This is the test that
 * actually validates "implicit-content rows migrate" — unit tests on the
 * compile and extract halves alone do not prove the contract holds across
 * the seam.
 */
final class TransformImplicitContentTest extends TestCase
{
    public function testSyntheticImplicitPagePartProducesMatrixBlock(): void
    {
        $fqcn = 'App\\Entity\\Pages\\HomePage';
        $implicitKey = '__implicit_content__|HomePage|main';

        $mapping = [
            'sites' => ['nl' => 'default'],
            'sections' => [
                'homePage' => ['entryType' => 'homePage', 'section' => 'homePages'],
            ],
            'nodeClasses' => [
                $fqcn => [
                    'sourceTable' => 'home_pages',
                    'section' => 'homePage',
                    'fields' => [],
                    'pageBuilderHandle' => 'pageBuilder',
                    'pageBuilderContexts' => ['main'],
                    'bodyColumn' => '',
                    'headerBlock' => null,
                    'bodyWrapBlock' => null,
                    'joins' => [],
                ],
            ],
            'pageParts' => [
                $implicitKey => [
                    'target' => 'textContentBlock',
                    'fields' => [
                        'html' => ['source' => 'content', 'handler' => 'plain'],
                    ],
                ],
            ],
        ];

        // Shaped exactly as ExtractService writes:
        //   perSite[lang] => { detail, pageParts: list<{ fqcn, sourcePartId, sequence, context, row }> }
        // The synthetic pagePart's row IS the page detail row (where the
        // content-like columns live by definition for content-only pages).
        $detail = ['id' => 42, 'content' => '<p>Welcome to the homepage.</p>'];
        $extracted = [
            'kunstmaanSourceId' => 'App_Entity_Pages_HomePage:42',
            'fqcn' => $fqcn,
            'kuma_node_id' => 100,
            'kuma_parent_id' => null,
            'ref_id' => 42,
            'refIdsByLocale' => ['nl' => 42],
            'sourceTable' => 'home_pages',
            'perSite' => [
                'nl' => [
                    'online' => true,
                    'title' => 'Home',
                    'slug' => 'home',
                    'url' => '/',
                    'refId' => 42,
                    'detail' => $detail,
                    'pageParts' => [
                        [
                            'fqcn' => $implicitKey,
                            'sourcePartId' => 42,
                            'sequence' => 1_000_000,
                            'context' => 'main',
                            'row' => $detail,
                        ],
                    ],
                ],
            ],
        ];

        $payloads = [];
        foreach ($this->makeService()->run([$extracted], $mapping, new MigrationFilters(), []) as $yielded) {
            if (!is_array($yielded) || array_key_exists('__report', $yielded)) {
                continue;
            }
            $payloads[] = $yielded;
        }

        $this->assertCount(1, $payloads);

        // perSite is keyed by Craft site handle (mapping.sites['nl'] => 'default'),
        // not the legacy locale code.
        $perSite = (array) ($payloads[0]['perSite'] ?? []);
        $this->assertArrayHasKey('default', $perSite, 'Expected default-site payload: ' . json_encode($payloads[0]));
        $fieldValues = (array) ($perSite['default']['fieldValues'] ?? []);
        $this->assertNotEmpty($fieldValues, 'Expected non-empty fieldValues: ' . json_encode($perSite['default']));

        $this->assertArrayHasKey('pageBuilder', $fieldValues);
        $blocks = (array) $fieldValues['pageBuilder'];
        $this->assertCount(1, $blocks);

        $block = (array) reset($blocks);
        $this->assertSame('textContentBlock', $block['type']);
        $this->assertTrue($block['enabled']);
        $this->assertSame('<p>Welcome to the homepage.</p>', $block['fields']['html']);
        $this->assertStringContainsString(
            '__implicit_content__|HomePage|main:42',
            (string) $block['fields']['_sourcePartRef'],
        );
    }

    private function makeService(): TransformService
    {
        $registry = new FieldHandlerRegistry();
        $registry->register(new PlainTextHandler('plain'));
        $registry->register(new PlainTextHandler('ckeditor'));
        $registry->register(new PlainTextHandler('link'));
        $registry->register(new PlainTextHandler('dropdown'));
        $registry->register(new AssetHandler());
        $registry->register(new RelationHandler());
        $registry->register(new MatrixHandler());
        $registry->register(new SplitNameHandler());

        $service = new TransformService();
        $service->handlerRegistry = $registry;
        $service->ckeditorRewriter = new CkeditorRewriterService();
        return $service;
    }
}
