<?php

namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\load\MigrationStateService;
use Closure;
use yii\base\Component;

/**
 * Builds the associative array passed to $entry->setFieldValue('seo', ...)
 * from a legacy kuma_seo row.
 *
 * The `seo` field has translationMethod=site, so callers build per-site
 * payloads and pass each during the per-site entry save.
 *
 * Emitted keys (per SEO-COVERAGE-DIAGNOSTIC.md, kunstmaan-craft-scaffolder):
 *   metaGlobalVars (always 6, +1 conditional):
 *     seoTitle, seoDescription, seoImage, ogTitle, ogDescription, ogImage
 *     robots          ← only when meta_robots is non-empty
 *   metaBundleSettings (always 4, +1 conditional, +2 image):
 *     seoTitleSource, seoDescriptionSource, ogTitleSource, ogDescriptionSource = 'fromCustom'
 *     robotsSource = 'fromCustom'      ← only when robots is emitted
 *     seoImageSource = 'fromAsset', seoImageIds, ogImageSource = 'sameAsSeo'
 *                                        ← only when og_image resolves
 *
 * Fallback chains:
 *   ogTitle        → og_title ?: meta_title
 *   ogDescription  → og_description ?: meta_description
 *   twitter image  → og_image_id used as fallback for twitter (not yet emitted; see P2 in diagnostic)
 *
 * Why robots is conditionally emitted (vs title/desc which are always 'fromCustom'):
 *   - title/desc need explicit 'fromCustom' + '' to prevent SEOmatic from
 *     resolving the Twitter-fallback into the per-site content JSON,
 *     which would propagate NL copy into EN. There's no equivalent
 *     fallback chain for robots — the sitewide default is the literal
 *     string 'all', not "the primary site's robots".
 *   - Forcing robotsSource = 'fromCustom' with an empty value would
 *     explicitly clear robots and render no robots meta at all, which
 *     is wrong; pages that didn't override should fall through to the
 *     sitewide default. Conditional emit gives that behavior.
 *
 * Image id resolution: og_image_id / twitter_image_id are numeric
 * kuma_media primary keys; resolved to Craft numeric asset ids via
 * MigrationStateService::getTargetId('media', 'kuma_media:<id>').
 * Unresolvable ids return null (caller is already warned via the Plan 03
 * asset scanner).
 *
 * Other kuma_seo columns (og_type / og_url / meta_author / og_article_* /
 * twitter_site / twitter_creator / extra_metadata) are intentionally
 * dropped per SEO-COVERAGE-DIAGNOSTIC.md — population is 0–25 rows
 * portfolio-wide or values are unresolved Kunstmaan placeholders.
 * twitter_title / twitter_description / twitter_image_id remain dropped
 * pending P2 (~70 unique twitter override rows on dewert).
 */
class SeomaticPayloadBuilder extends Component
{
    private ?Closure $resolver = null;

    /** DI slot: MigrationStateService for fallback asset-id resolution. */
    public ?MigrationStateService $migrationState = null;

    /**
     * @param array<string, mixed>|null $seoRow
     *
     * @return array<string, mixed>
     */
    public function build(?array $seoRow, int $siteId): array
    {
        $row = $seoRow ?? [];

        $metaTitle = $this->str($row, 'meta_title');
        $metaDescription = $this->str($row, 'meta_description');

        // og_image_id and twitter_image_id → numeric Craft asset id via state
        $ogImageId = $this->resolveMediaId($row['og_image_id'] ?? null);
        $twitterImageId = $this->resolveMediaId($row['twitter_image_id'] ?? null);

        // Twitter image falls back to og image
        if ($twitterImageId === null && $ogImageId !== null) {
            $twitterImageId = $ogImageId;
        }

        $ogTitle = $this->str($row, 'og_title') ?: $metaTitle;
        $ogDescription = $this->str($row, 'og_description') ?: $metaDescription;

        // SEOmatic per-entry field expects a nested metaGlobalVars +
        // metaBundleSettings structure; each override requires a *Source key
        // set to 'fromCustom' (text) or 'fromAsset' (image) to take effect
        // at render time.
        //
        // Per-column drop / map decisions are documented in
        // SEO-COVERAGE-DIAGNOSTIC.md (kunstmaan-craft-scaffolder repo).
        $metaGlobalVars = [
            'seoTitle' => $metaTitle,
            'seoDescription' => $metaDescription,
            'seoImage' => $ogImageId !== null ? (string) $ogImageId : '',
            'ogTitle' => $ogTitle,
            'ogDescription' => $ogDescription,
            'ogImage' => $ogImageId !== null ? (string) $ogImageId : '',
        ];

        // Always use 'fromCustom' for title/description sources — including
        // when the value is empty. Using 'sameAsSiteTwitter' for empty values
        // causes SEOmatic to resolve the Twitter-fallback description (which
        // may be the NL description propagated from the primary site), writing
        // that back into the per-site content JSON and making EN pages show NL
        // SEO content. An explicit 'fromCustom' + empty string correctly clears
        // any propagated content and stores a true empty for that locale.
        $metaBundleSettings = [
            'seoTitleSource' => 'fromCustom',
            'seoDescriptionSource' => 'fromCustom',
            'ogTitleSource' => 'fromCustom',
            'ogDescriptionSource' => 'fromCustom',
        ];

        // Conditional: meta_robots is only emitted when the source row has
        // an explicit override. Empty source → omit, so SEOmatic falls
        // through to the sitewide default ('all'). Without this the noindex
        // editorial choice is silently discarded (228 / 1 291 rows on
        // deklerk / simac).
        $metaRobots = $this->str($row, 'meta_robots');
        if ($metaRobots !== '') {
            $metaGlobalVars['robots'] = $metaRobots;
            $metaBundleSettings['robotsSource'] = 'fromCustom';
        }

        if ($ogImageId !== null) {
            $metaBundleSettings['seoImageSource'] = 'fromAsset';
            $metaBundleSettings['seoImageIds'] = [$ogImageId];
            $metaBundleSettings['ogImageSource'] = 'sameAsSeo';
        }

        return [
            'metaGlobalVars' => $metaGlobalVars,
            'metaBundleSettings' => $metaBundleSettings,
        ];
    }

    /**
     * Internal test seam — inject a resolver closure so unit tests don't
     * need a Craft bootstrap / state table. Production leaves this unset
     * and falls through to the injected MigrationStateService.
     *
     * The resolver receives a kuma_media id (int) and returns the Craft
     * asset id (int) or null when unresolvable.
     *
     * @internal used by tests
     */
    public function setResolver(callable $resolver): void
    {
        $this->resolver = Closure::fromCallable($resolver);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        return $v === null ? '' : (string) $v;
    }

    private function resolveMediaId(mixed $kumaMediaId): ?int
    {
        if ($kumaMediaId === null || $kumaMediaId === '' || $kumaMediaId === 0) {
            return null;
        }
        $id = (int) $kumaMediaId;
        if ($id <= 0) {
            return null;
        }
        return $this->lookupCraftAssetId($id);
    }

    private function lookupCraftAssetId(int $kumaMediaId): ?int
    {
        if ($this->resolver !== null) {
            $result = ($this->resolver)($kumaMediaId);
            return $result === null ? null : (int) $result;
        }

        if ($this->migrationState === null) {
            return null;
        }

        return $this->migrationState->getTargetId('media', 'kuma_media:' . $kumaMediaId);
    }
}
