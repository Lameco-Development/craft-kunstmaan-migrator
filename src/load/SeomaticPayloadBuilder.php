<?php

namespace Lameco\Kunstmaanmigrator\load;

use Closure;
use Lameco\Kunstmaanmigrator\Payload\SeomaticValue;
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
 * MigrationStateService::getTargetId('media', '<ENV>:kuma_media:<id>').
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
     * @param string $environment the mapping's key for the legacy environment this
     *                            row was read from — part of the media state key,
     *                            because kuma_media ids collide across databases
     *
     * @return array<string, mixed>
     */
    public function build(?array $seoRow, int $siteId, string $environment): array
    {
        $row = $seoRow ?? [];

        // og_image_id → numeric Craft asset id via state. The twitter image is emitted only
        // when the row has its own and it differs from the og image — `sameAsSeo` covers the
        // rest at render time (see the class docblock).
        //
        // The shaping itself is `Payload\SeomaticValue`'s, shared with the compiler, which
        // builds an entity's SEO from mapped columns the same way.
        return SeomaticValue::fromRow(
            $row,
            $this->resolveMediaId($row['og_image_id'] ?? null, $environment),
            $this->resolveMediaId($row['twitter_image_id'] ?? null, $environment),
        );
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

    private function resolveMediaId(mixed $kumaMediaId, string $environment): ?int
    {
        if ($kumaMediaId === null || $kumaMediaId === '' || $kumaMediaId === 0) {
            return null;
        }
        $id = (int) $kumaMediaId;
        if ($id <= 0) {
            return null;
        }
        return $this->lookupCraftAssetId($id, $environment);
    }

    /**
     * `kuma_media.id` restarts at 1 in every legacy database, so the environment
     * that read the row is part of the asset's identity — `AssetMigrationService`
     * writes the state row under `{ENV}:kuma_media:{id}` for that reason. Looking
     * one up bare returned whichever environment migrated that id first: 23 DE
     * pages on the Enreach corpus took their og:image from a COM asset that
     * merely shared a primary key.
     *
     * An id this environment has not migrated now resolves to null — no image —
     * rather than to the wrong one. That is a miss the asset scanner already
     * warns about, and it is fixed by ingesting that environment's own media.
     */
    private function lookupCraftAssetId(int $kumaMediaId, string $environment): ?int
    {
        if ($this->resolver !== null) {
            $result = ($this->resolver)($kumaMediaId);
            return $result === null ? null : (int) $result;
        }

        if ($this->migrationState === null || $environment === '') {
            return null;
        }

        return $this->migrationState->getTargetId('media', $environment . ':kuma_media:' . $kumaMediaId);
    }
}
