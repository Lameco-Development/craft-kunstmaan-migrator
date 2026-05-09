<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

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

    public function testMetaRobotsEmittedWhenSourceProvidesOverride(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);

        $row = [
            'meta_title' => 'T',
            'meta_robots' => 'noindex,nofollow',
        ];
        $payload = $builder->build($row, 1);

        // Non-empty meta_robots flows through to metaGlobalVars.robots
        // verbatim. SEOmatic reads metaGlobalVars.robots directly at
        // render time — no source-toggle indirection (robotsSource is
        // NOT a valid MetaBundleSettings property; emitting it would
        // trigger UnknownPropertyException and silently abort the entry's
        // SEO persistence).
        $this->assertSame('noindex,nofollow', $payload['metaGlobalVars']['robots']);
        $this->assertArrayNotHasKey('robotsSource', $payload['metaBundleSettings']);
    }

    public function testMetaRobotsOmittedWhenSourceIsEmpty(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);

        // Null row → no robots key (SEOmatic falls back to sitewide default).
        $nullPayload = $builder->build(null, 1);
        $this->assertArrayNotHasKey('robots', $nullPayload['metaGlobalVars']);

        // Empty-string meta_robots is treated identically.
        $emptyPayload = $builder->build(['meta_robots' => ''], 1);
        $this->assertArrayNotHasKey('robots', $emptyPayload['metaGlobalVars']);

        // Row missing the key entirely (older callers) — same.
        $absentPayload = $builder->build(['meta_title' => 'T'], 1);
        $this->assertArrayNotHasKey('robots', $absentPayload['metaGlobalVars']);
    }

    public function testRobotsSourceKeyNeverEmittedRegardlessOfMetaRobotsValue(): void
    {
        // Defensive contract — `robotsSource` is not a valid MetaBundleSettings
        // property; emitting it triggers Yii's __set throw and silently kills
        // the entire SEO field's persistence. Lock the contract so a future
        // refactor can't accidentally re-introduce it.
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);

        $populated = $builder->build(['meta_robots' => 'noindex'], 1);
        $this->assertArrayNotHasKey('robotsSource', $populated['metaBundleSettings']);

        $empty = $builder->build(['meta_robots' => ''], 1);
        $this->assertArrayNotHasKey('robotsSource', $empty['metaBundleSettings']);

        $null = $builder->build(null, 1);
        $this->assertArrayNotHasKey('robotsSource', $null['metaBundleSettings']);
    }

    // ---- Twitter overrides (P8 / P2) ----
    //
    // dewert has 466/447/464 populated twitter_title/description/image_id rows;
    // ~70 are unique overrides that previously rendered as og copy at the
    // editor's expense. Conditional emit pattern mirrors meta_robots — only
    // emit when the source row has an explicit value, so SEOmatic falls
    // through to its `sameAsSeo` / `sameAsSeoTwitter` defaults for the
    // ~85-92% of rows where twitter matches og.

    public function testTwitterTitleEmittedWhenSourceProvidesOverride(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);

        $payload = $builder->build([
            'meta_title' => 'Page meta title',
            'twitter_title' => 'Custom twitter title',
        ], 1);

        $this->assertSame('Custom twitter title', $payload['metaGlobalVars']['twitterTitle']);
        $this->assertSame('fromCustom', $payload['metaBundleSettings']['twitterTitleSource']);
    }

    public function testTwitterDescriptionEmittedWhenSourceProvidesOverride(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);

        $payload = $builder->build([
            'meta_description' => 'Page meta desc',
            'twitter_description' => 'Custom twitter desc',
        ], 1);

        $this->assertSame('Custom twitter desc', $payload['metaGlobalVars']['twitterDescription']);
        $this->assertSame('fromCustom', $payload['metaBundleSettings']['twitterDescriptionSource']);
    }

    public function testTwitterFieldsOmittedWhenSourceIsEmpty(): void
    {
        // Empty source → no twitter keys (SEOmatic falls back to its own
        // sameAsSeoTwitter defaults at render time). Mirrors the meta_robots
        // omit-on-empty contract — emitting `fromCustom + ''` would
        // force-clear the field on the per-site entry, propagating empty
        // twitter content downstream of the migration.
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): ?int => null);

        $nullPayload = $builder->build(null, 1);
        $this->assertArrayNotHasKey('twitterTitle', $nullPayload['metaGlobalVars']);
        $this->assertArrayNotHasKey('twitterDescription', $nullPayload['metaGlobalVars']);
        $this->assertArrayNotHasKey('twitterTitleSource', $nullPayload['metaBundleSettings']);
        $this->assertArrayNotHasKey('twitterDescriptionSource', $nullPayload['metaBundleSettings']);

        $emptyPayload = $builder->build([
            'twitter_title' => '',
            'twitter_description' => '',
        ], 1);
        $this->assertArrayNotHasKey('twitterTitle', $emptyPayload['metaGlobalVars']);
        $this->assertArrayNotHasKey('twitterDescription', $emptyPayload['metaGlobalVars']);
    }

    public function testTwitterImageEmittedWhenAssetDiffersFromOg(): void
    {
        $builder = new SeomaticPayloadBuilder();
        // og_image_id=10 → asset 100; twitter_image_id=20 → asset 200.
        // Different assets → twitter override should emit.
        $builder->setResolver(static fn(int $id): int => $id * 10);

        $payload = $builder->build([
            'og_image_id' => 10,
            'twitter_image_id' => 20,
        ], 1);

        $this->assertSame('200', $payload['metaGlobalVars']['twitterImage']);
        $this->assertSame('fromAsset', $payload['metaBundleSettings']['twitterImageSource']);
        $this->assertSame([200], $payload['metaBundleSettings']['twitterImageIds']);
    }

    public function testTwitterImageOmittedWhenIdenticalToOg(): void
    {
        // Editor manually copied og_image into twitter_image (or the source
        // CMS auto-populated it). ~91% of dewert's 464 twitter_image_id rows
        // hit this case. Suppress the emit so SEOmatic's `sameAsSeo` default
        // covers it at render time — avoids redundant content-JSON entries.
        $builder = new SeomaticPayloadBuilder();
        // Both ids resolve to the SAME craft asset id 999.
        $builder->setResolver(static fn(int $id): int => 999);

        $payload = $builder->build([
            'og_image_id' => 10,
            'twitter_image_id' => 20,
        ], 1);

        $this->assertArrayNotHasKey('twitterImage', $payload['metaGlobalVars']);
        $this->assertArrayNotHasKey('twitterImageSource', $payload['metaBundleSettings']);
        $this->assertArrayNotHasKey('twitterImageIds', $payload['metaBundleSettings']);
    }

    public function testTwitterImageOmittedWhenSourceIsEmpty(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): int => 999);

        // og has an image, twitter doesn't — let sameAsSeo cover it.
        $payload = $builder->build([
            'og_image_id' => 10,
            'twitter_image_id' => null,
        ], 1);

        $this->assertArrayNotHasKey('twitterImage', $payload['metaGlobalVars']);
        $this->assertArrayNotHasKey('twitterImageSource', $payload['metaBundleSettings']);
    }

    public function testTwitterImageEmittedWhenOgImageIsAbsent(): void
    {
        // Edge case: twitter has its own image but og doesn't. The image is
        // unique-from-og by definition (og is null). Should emit the twitter
        // override so render time has something to use.
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id): int => 200);

        $payload = $builder->build([
            'og_image_id' => null,
            'twitter_image_id' => 20,
        ], 1);

        $this->assertSame('200', $payload['metaGlobalVars']['twitterImage']);
        $this->assertSame('fromAsset', $payload['metaBundleSettings']['twitterImageSource']);
        $this->assertSame([200], $payload['metaBundleSettings']['twitterImageIds']);
    }
}
