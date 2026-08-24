<?php

namespace lameco\kunstmaanmigrator\load;

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
 *   metaGlobalVars (always 6, +up to 4 conditional):
 *     seoTitle, seoDescription, seoImage, ogTitle, ogDescription, ogImage
 *     robots             ← only when meta_robots is non-empty
 *     twitterTitle       ← only when twitter_title is non-empty
 *     twitterDescription ← only when twitter_description is non-empty
 *     twitterImage       ← only when twitter_image_id resolves
 *   metaBundleSettings (always 4, +up to 4 conditional, +2 og-image, +2 twitter-image):
 *     seoTitleSource, seoDescriptionSource, ogTitleSource, ogDescriptionSource = 'fromCustom'
 *     twitterTitleSource = 'fromCustom'        ← only when twitterTitle is emitted
 *     twitterDescriptionSource = 'fromCustom'  ← only when twitterDescription is emitted
 *     seoImageSource = 'fromAsset', seoImageIds, ogImageSource = 'sameAsSeo'
 *                                        ← only when og_image resolves
 *     twitterImageSource = 'fromAsset', twitterImageIds
 *                                        ← only when twitter_image_id resolves to a unique-from-og asset
 *
 * Fallback chains:
 *   ogTitle        → og_title ?: meta_title
 *   ogDescription  → og_description ?: meta_description
 *   twitter image  → falls back to og_image at SEOmatic render time via the
 *                    sameAsSeo source default. We only emit twitterImage when
 *                    the source row has its own override AND it differs from
 *                    og_image — emitting an identical id would force a
 *                    redundant content-JSON entry on every page (~85-92% of
 *                    twitter rows match og per SEO-COVERAGE-DIAGNOSTIC.md).
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

        // og_image_id → numeric Craft asset id via state. twitter_image_id
        // is resolved separately below in the twitter-overrides block (and
        // gated on differing from og_image — see comment there).
        $ogImageId = $this->resolveMediaId($row['og_image_id'] ?? null);

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
        //
        // CRITICAL: do NOT emit `metaBundleSettings.robotsSource` — that
        // property doesn't exist on `MetaBundleSettings`. SEOmatic's per-
        // field source-toggle pattern only covers seoTitle / seoDescription /
        // ogTitle / ogDescription / seoImage; robots is read directly from
        // metaGlobalVars.robots without indirection. Including a
        // `robotsSource` key triggers `UnknownPropertyException` inside
        // SEOmatic's normalizeValue → MetaBundleSettings::create chain,
        // which silently aborts the whole field's persistence (Craft's
        // saveElement returns true but the seo field never lands in
        // elements_sites.content). Verified with a tinker-style debug
        // script against the dewert smoke target on 2026-05-09.
        $metaRobots = $this->str($row, 'meta_robots');
        if ($metaRobots !== '') {
            $metaGlobalVars['robots'] = $metaRobots;
        }

        if ($ogImageId !== null) {
            $metaBundleSettings['seoImageSource'] = 'fromAsset';
            $metaBundleSettings['seoImageIds'] = [$ogImageId];
            $metaBundleSettings['ogImageSource'] = 'sameAsSeo';
        }

        // Twitter overrides — conditional, mirrors the meta_robots pattern.
        // Only emit when the source row has an explicit value; otherwise let
        // SEOmatic fall through to its defaults (sameAsSeo for image,
        // sameAsSeoTwitter for title/description). Emitting a `fromCustom`
        // toggle with empty value would force-clear the field on the per-site
        // entry, propagating empty twitter content downstream. Verified
        // against MetaBundleSettings.php — twitterTitleSource /
        // twitterDescriptionSource / twitterImageSource / twitterImageIds
        // exist on the model (unlike the fictional `robotsSource` from the
        // 58ee7f6 bug fix). Closes P8 / P2 (SEO-COVERAGE-DIAGNOSTIC.md):
        // ~70 unique twitter overrides per-page on dewert that previously
        // rendered as og copy at the editor's expense.
        $twitterTitle = $this->str($row, 'twitter_title');
        if ($twitterTitle !== '') {
            $metaGlobalVars['twitterTitle'] = $twitterTitle;
            $metaBundleSettings['twitterTitleSource'] = 'fromCustom';
        }
        $twitterDescription = $this->str($row, 'twitter_description');
        if ($twitterDescription !== '') {
            $metaGlobalVars['twitterDescription'] = $twitterDescription;
            $metaBundleSettings['twitterDescriptionSource'] = 'fromCustom';
        }
        // Twitter image is the trickier case. The original code resolved
        // twitter_image_id INTO `$twitterImageId`, then fell back to
        // `$ogImageId` when the explicit twitter_image_id was empty — and
        // then never used the result. The og-fallback was wrong-direction
        // (twitter falling back to og is what SEOmatic's `sameAsSeo` source
        // already does at render time). Drop the unused fallback; emit
        // twitterImage only when the source row has its own twitter image
        // AND it differs from og_image (avoids ~91% of redundant rows per
        // SEO-COVERAGE-DIAGNOSTIC.md — the `sameAsSeo` default covers them).
        $rawTwitterImageId = $this->resolveMediaId($row['twitter_image_id'] ?? null);
        if ($rawTwitterImageId !== null && $rawTwitterImageId !== $ogImageId) {
            $metaGlobalVars['twitterImage'] = (string) $rawTwitterImageId;
            $metaBundleSettings['twitterImageSource'] = 'fromAsset';
            $metaBundleSettings['twitterImageIds'] = [$rawTwitterImageId];
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
