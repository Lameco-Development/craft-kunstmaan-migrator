<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\load;

use lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Plan 04-12 Task 05 — characterization tests for the column → payload
 * contract in SeomaticPayloadBuilder::build() (Plan 04-02).
 *
 * Uses the public setResolver() test seam so unit tests don't need a
 * Craft container or a populated migration state table.
 *
 * Locked metaGlobalVars 6-key contract (src/load/SeomaticPayloadBuilder.php
 * lines 81-88):
 *   seoTitle, seoDescription, seoImage, ogTitle, ogDescription, ogImage
 *
 * Locked input column names (src/load/SeomaticPayloadBuilder.php lines 56-69):
 *   meta_title, meta_description, og_title, og_description,
 *   og_image_id, twitter_image_id.
 */
final class SeomaticPayloadBuilderTest extends TestCase
{
    public function testNullSeoRowProducesEmptyPayload(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);
        $payload = $builder->build(null, 1);

        // Null row → 6-key shape with empty string scalars (no asset id).
        $this->assertArrayHasKey('metaGlobalVars', $payload);
        $vars = $payload['metaGlobalVars'];
        $this->assertSame('', $vars['seoTitle']);
        $this->assertSame('', $vars['seoDescription']);
        $this->assertSame('', $vars['seoImage']);
        $this->assertSame('', $vars['ogTitle']);
        $this->assertSame('', $vars['ogDescription']);
        $this->assertSame('', $vars['ogImage']);
    }

    public function testSeoRowProducesSixKeyMetaGlobalVars(): void
    {
        $builder = new SeomaticPayloadBuilder();
        // Resolver returns Craft asset id 999 for any kuma_media id (test seam).
        $builder->setResolver(static fn(int $id): int => 999);

        $row = [
            'meta_title' => 'Title',
            'meta_description' => 'Desc',
            'og_title' => 'OG',
            'og_description' => 'OGD',
            'og_image_id' => 42,
        ];
        $payload = $builder->build($row, 1);

        $this->assertArrayHasKey('metaGlobalVars', $payload);
        $vars = $payload['metaGlobalVars'];
        // The 6-key locked contract from the source.
        $this->assertArrayHasKey('seoTitle', $vars);
        $this->assertArrayHasKey('seoDescription', $vars);
        $this->assertArrayHasKey('seoImage', $vars);
        $this->assertArrayHasKey('ogTitle', $vars);
        $this->assertArrayHasKey('ogDescription', $vars);
        $this->assertArrayHasKey('ogImage', $vars);

        $this->assertSame('Title', $vars['seoTitle']);
        $this->assertSame('Desc', $vars['seoDescription']);
        $this->assertSame('OG', $vars['ogTitle']);
        $this->assertSame('OGD', $vars['ogDescription']);
        // Image id is the resolver's return cast to string.
        $this->assertSame('999', $vars['seoImage']);
        $this->assertSame('999', $vars['ogImage']);
    }

    public function testOgTitleFallsBackToMetaTitleWhenAbsent(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);

        $row = [
            'meta_title' => 'MetaTitle',
            'meta_description' => 'MetaDesc',
            // og_title and og_description deliberately absent
        ];
        $payload = $builder->build($row, 1);
        $vars = $payload['metaGlobalVars'];

        // Fallback chain: og_title ?: meta_title; og_description ?: meta_description.
        $this->assertSame('MetaTitle', $vars['ogTitle']);
        $this->assertSame('MetaDesc', $vars['ogDescription']);
    }

    public function testMetaBundleSettingsAlwaysSetsSourceFromCustom(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);
        $payload = $builder->build(null, 1);

        $this->assertArrayHasKey('metaBundleSettings', $payload);
        $settings = $payload['metaBundleSettings'];
        // Always 'fromCustom' — even for empty values (fix for cross-locale leakage).
        $this->assertSame('fromCustom', $settings['seoTitleSource']);
        $this->assertSame('fromCustom', $settings['seoDescriptionSource']);
        $this->assertSame('fromCustom', $settings['ogTitleSource']);
        $this->assertSame('fromCustom', $settings['ogDescriptionSource']);
    }
}
