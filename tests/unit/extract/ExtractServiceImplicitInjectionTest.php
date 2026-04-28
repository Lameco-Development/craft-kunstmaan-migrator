<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\extract;

use lameco\kunstmaanmigrator\extract\ExtractService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 — synthetic page-part injection. Verifies ExtractService produces
 * synthetic pagePart records for pages whose mapping.pageParts has any
 * '__implicit_content__|<short>|<context>' entries. The synthetic record
 * carries the page's own detail row as `row`, so transformPageBuilder can
 * resolve the declared field handlers against the page-table content columns.
 */
final class ExtractServiceImplicitInjectionTest extends TestCase
{
    public function testEmitsSyntheticRecordPerImplicitKeyForMatchingFqcn(): void
    {
        $mapping = [
            'pageParts' => [
                '__implicit_content__|NewsPage|main' => [
                    'target' => 'textContentBlock',
                    'fields' => ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
                ],
                '__implicit_content__|NewsPage|sidebar' => [
                    'target' => 'sidebarBlock',
                    'fields' => ['html' => ['source' => 'sidebar', 'handler' => 'ckeditor']],
                ],
            ],
        ];
        $detail = ['id' => 42, 'content' => '<p>Hello</p>', 'sidebar' => '<p>Side</p>'];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            $detail,
            $mapping,
        );

        $this->assertCount(2, $synthetic);

        $byFqcn = [];
        foreach ($synthetic as $rec) {
            $byFqcn[$rec['fqcn']] = $rec;
        }
        $this->assertArrayHasKey('__implicit_content__|NewsPage|main', $byFqcn);
        $this->assertArrayHasKey('__implicit_content__|NewsPage|sidebar', $byFqcn);

        $main = $byFqcn['__implicit_content__|NewsPage|main'];
        $this->assertSame('main', $main['context']);
        $this->assertSame(42, $main['sourcePartId']);
        $this->assertSame($detail, $main['row']);
        $this->assertGreaterThanOrEqual(1_000_000, $main['sequence']);
    }

    public function testReturnsEmptyWhenNoImplicitKeysMatchFqcn(): void
    {
        $mapping = [
            'pageParts' => [
                '__implicit_content__|HomePage|main' => [
                    'target' => 'textContentBlock',
                    'fields' => ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
                ],
            ],
        ];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['id' => 1, 'content' => 'X'],
            $mapping,
        );

        $this->assertSame([], $synthetic);
    }

    public function testReturnsEmptyWhenMappingHasNoPagePartsBlock(): void
    {
        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['id' => 1],
            [],
        );

        $this->assertSame([], $synthetic);
    }

    public function testIgnoresNonImplicitPagePartKeys(): void
    {
        $mapping = [
            'pageParts' => [
                'App\\Entity\\PageParts\\TextPart' => [
                    'target' => 'textBlock',
                    'fields' => ['html' => ['source' => 'text', 'handler' => 'ckeditor']],
                ],
            ],
        ];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['id' => 1],
            $mapping,
        );

        $this->assertSame([], $synthetic);
    }

    public function testFallsBackToSequenceWhenDetailIdMissing(): void
    {
        $mapping = [
            'pageParts' => [
                '__implicit_content__|NewsPage|main' => [
                    'target' => 'textContentBlock',
                    'fields' => ['html' => ['source' => 'content', 'handler' => 'ckeditor']],
                ],
            ],
        ];

        $synthetic = ExtractService::buildImplicitContentPageParts(
            'App\\Entity\\Pages\\NewsPage',
            ['content' => 'X'], // no id
            $mapping,
        );

        $this->assertCount(1, $synthetic);
        $this->assertGreaterThanOrEqual(1_000_000, $synthetic[0]['sourcePartId']);
    }
}
