<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Payload;

/**
 * The value an SEOmatic field is set to, from a `kuma_seo`-shaped row.
 *
 * One shaping, two callers: the SEO adapter (`load\SeomaticPayloadBuilder`) builds a page's
 * from its `kuma_seo` row after resolving the image ids, and the compiler builds an entity's
 * from the columns a `seomatic(...)` expression maps — a catalogue keeps its SEO on a table of
 * its own. Why each key is emitted, or left out, is the builder's docblock; the rules live here
 * so the two cannot drift.
 *
 * `meta_keywords` reaches this only from an entity's `seomatic(keywords=…)`: the SEO adapter
 * drops it from a `kuma_seo` row, whatever columns that table has, so a page's SEO stays what it
 * always was. It is emitted only when set, with its `fromCustom` source, as robots and the
 * Twitter overrides are: an empty one falls through to the sitewide default.
 */
final class SeomaticValue
{
    /**
     * What a mapping's `seomatic(...)` takes, by name => the row key it fills. No images: an
     * SEOmatic image is a Craft asset id, which only the loader knows.
     */
    public const ARGUMENTS = [
        'title' => 'meta_title',
        'description' => 'meta_description',
        'keywords' => 'meta_keywords',
        'robots' => 'meta_robots',
        'ogTitle' => 'og_title',
        'ogDescription' => 'og_description',
        'twitterTitle' => 'twitter_title',
        'twitterDescription' => 'twitter_description',
    ];

    /**
     * @param array<string, mixed> $row `meta_title`, `meta_description`, `meta_keywords`,
     *        `meta_robots`, `og_title`, `og_description`, `twitter_title`, `twitter_description`
     * @param ?int $ogImageId the Craft asset id of the row's og image, when it resolved
     * @param ?int $twitterImageId the Craft asset id of the row's own Twitter image, when it
     *        resolved — emitted only when it differs from the og image
     * @return array{metaGlobalVars: array<string, string>, metaBundleSettings: array<string, mixed>}
     */
    public static function fromRow(array $row, ?int $ogImageId = null, ?int $twitterImageId = null): array
    {
        $metaTitle = self::str($row, 'meta_title');
        $metaDescription = self::str($row, 'meta_description');

        $metaGlobalVars = [
            'seoTitle' => $metaTitle,
            'seoDescription' => $metaDescription,
            'seoImage' => $ogImageId !== null ? (string) $ogImageId : '',
            'ogTitle' => self::str($row, 'og_title') ?: $metaTitle,
            'ogDescription' => self::str($row, 'og_description') ?: $metaDescription,
            'ogImage' => $ogImageId !== null ? (string) $ogImageId : '',
        ];

        // `fromCustom` even when empty: any other source lets SEOmatic resolve a fallback (the
        // primary site's copy) into this site's stored value.
        $metaBundleSettings = [
            'seoTitleSource' => 'fromCustom',
            'seoDescriptionSource' => 'fromCustom',
            'ogTitleSource' => 'fromCustom',
            'ogDescriptionSource' => 'fromCustom',
        ];

        // No `robotsSource`: MetaBundleSettings has no such property, and the unknown key
        // silently aborts the whole field's save.
        $metaRobots = self::str($row, 'meta_robots');
        if ($metaRobots !== '') {
            $metaGlobalVars['robots'] = $metaRobots;
        }

        $metaKeywords = self::str($row, 'meta_keywords');
        if ($metaKeywords !== '') {
            $metaGlobalVars['seoKeywords'] = $metaKeywords;
            $metaBundleSettings['seoKeywordsSource'] = 'fromCustom';
        }

        if ($ogImageId !== null) {
            $metaBundleSettings['seoImageSource'] = 'fromAsset';
            $metaBundleSettings['seoImageIds'] = [$ogImageId];
            $metaBundleSettings['ogImageSource'] = 'sameAsSeo';
        }

        $twitterTitle = self::str($row, 'twitter_title');
        if ($twitterTitle !== '') {
            $metaGlobalVars['twitterTitle'] = $twitterTitle;
            $metaBundleSettings['twitterTitleSource'] = 'fromCustom';
        }

        $twitterDescription = self::str($row, 'twitter_description');
        if ($twitterDescription !== '') {
            $metaGlobalVars['twitterDescription'] = $twitterDescription;
            $metaBundleSettings['twitterDescriptionSource'] = 'fromCustom';
        }

        if ($twitterImageId !== null && $twitterImageId !== $ogImageId) {
            $metaGlobalVars['twitterImage'] = (string) $twitterImageId;
            $metaBundleSettings['twitterImageSource'] = 'fromAsset';
            $metaBundleSettings['twitterImageIds'] = [$twitterImageId];
        }

        return [
            'metaGlobalVars' => $metaGlobalVars,
            'metaBundleSettings' => $metaBundleSettings,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
