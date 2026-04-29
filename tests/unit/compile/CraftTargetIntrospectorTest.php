<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\compile;

use lameco\kunstmaanmigrator\compile\CraftTargetIntrospector;
use PHPUnit\Framework\TestCase;

final class CraftTargetIntrospectorTest extends TestCase
{
    public function testValidCompiledTargetsProduceNoWarnings(): void
    {
        $warnings = (new CraftTargetIntrospector())->validate($this->compiled(), $this->schema());

        self::assertSame([], $warnings);
    }

    public function testMissingSectionAndEntryTypeCompatibilityAreLoadFatal(): void
    {
        $compiled = $this->compiled();
        $compiled['sections']['articlePage']['section'] = 'missingSection';

        $result = (new CraftTargetIntrospector())->validateWithSeverity($compiled, $this->schema());

        $joined = implode("\n", $result['fatal']);
        self::assertStringContainsString('missingSection', $joined);
        self::assertStringContainsString('articlePage', $joined);
    }

    public function testDisallowedSectionEntryTypePairIsLoadFatal(): void
    {
        $compiled = $this->compiled();
        $compiled['sections']['articlePage'] = [
            'section' => 'articles',
            'entryType' => 'newsPage',
        ];

        $result = (new CraftTargetIntrospector())->validateWithSeverity($compiled, $this->schema());

        $joined = implode("\n", $result['fatal']);
        self::assertStringContainsString('articles', $joined);
        self::assertStringContainsString('newsPage', $joined);
        self::assertStringContainsString('does not allow entryType', $joined);
    }

    public function testMissingFieldAndMatrixBlockFieldAreReported(): void
    {
        $compiled = $this->compiled();
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['missing'] = [
            'handler' => 'plain',
            'source' => 'missing',
        ];
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['bodyBlocks.heading'] = [
            'handler' => 'plain',
            'source' => 'heading',
            'blockType' => 'textBlock',
        ];
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['bodyBlocks.notThere'] = [
            'handler' => 'plain',
            'source' => 'not_there',
            'blockType' => 'textBlock',
        ];

        $warnings = (new CraftTargetIntrospector())->validate($compiled, $this->schema());

        $joined = implode("\n", $warnings);
        self::assertStringContainsString('missing', $joined);
        self::assertStringContainsString('bodyBlocks.notThere', $joined);
        self::assertStringNotContainsString('bodyBlocks.heading missing', $joined);
    }

    public function testNativeEntryPropertiesAndTwoPartMatrixTargetsAreAccepted(): void
    {
        $compiled = $this->compiled();
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['postDate'] = [
            'handler' => 'date',
            'source' => 'date',
        ];
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['bodyBlocks.heading'] = [
            'handler' => 'plain',
            'source' => 'heading',
        ];
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['bodyBlocks.title'] = [
            'handler' => 'plain',
            'source' => 'block_title',
        ];

        $warnings = (new CraftTargetIntrospector())->validate($compiled, $this->schema());

        $joined = implode("\n", $warnings);
        self::assertStringNotContainsString('postDate', $joined);
        self::assertStringNotContainsString('bodyBlocks.heading', $joined);
        self::assertStringNotContainsString('bodyBlocks.title', $joined);
    }

    public function testAssetEntriesSeomaticAndRetourTargetsAreValidatedDeterministically(): void
    {
        $compiled = $this->compiled();
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['heroImage']['handlerOptions'] = [
            'volume' => 'missingVolume',
        ];
        $compiled['nodeClasses']['App\\Entity\\ArticlePage']['fields']['related']['handlerOptions'] = [
            'sources' => ['section:missing'],
        ];
        $compiled['seomatic'] = ['enabled' => true, 'target' => 'metaGlobalVars'];
        $compiled['retour'] = ['enabled' => true, 'target' => 'redirects'];

        $warnings = (new CraftTargetIntrospector())->validate($compiled, $this->schema());

        $joined = implode("\n", $warnings);
        self::assertStringContainsString('missingVolume', $joined);
        self::assertStringContainsString('section:missing', $joined);
        self::assertStringContainsString('Retour', $joined);
        self::assertStringContainsString('not enabled', $joined);
        self::assertStringNotContainsString('SEOmatic not enabled', $joined);
    }

    /** @return array<string, mixed> */
    private function compiled(): array
    {
        return [
            'sections' => [
                'articlePage' => ['section' => 'articles', 'entryType' => 'articlePage'],
            ],
            'nodeClasses' => [
                'App\\Entity\\ArticlePage' => [
                    'section' => 'articlePage',
                    'fields' => [
                        'title' => ['handler' => 'plain', 'source' => 'title'],
                        'heroImage' => ['handler' => 'asset', 'source' => 'image_id', 'handlerOptions' => ['volume' => 'images']],
                        'related' => ['handler' => 'relation', 'source' => 'related_id', 'handlerOptions' => ['sources' => ['section:news']]],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'sections' => [
                'articles' => ['entryTypes' => ['articlePage']],
                'news' => ['entryTypes' => ['newsPage']],
            ],
            'entryTypes' => [
                'articlePage' => [
                    'fields' => [
                        'title' => ['type' => 'plain'],
                        'heroImage' => ['type' => 'asset', 'volumes' => ['images']],
                        'related' => ['type' => 'entries', 'sources' => ['section:news']],
                        'bodyBlocks' => [
                            'type' => 'matrix',
                            'blocks' => [
                                'textBlock' => ['fields' => ['heading']],
                            ],
                        ],
                    ],
                ],
                'newsPage' => ['fields' => ['title' => ['type' => 'plain']]],
            ],
            'volumes' => ['images'],
            'plugins' => ['seomatic' => true, 'retour' => false],
        ];
    }
}
