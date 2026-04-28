<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\filter;

use lameco\kunstmaanmigrator\Plugin;
use yii\base\Component;

/**
 * Builds MigrationFilters from CLI flag values + Settings::default*.
 *
 * Per D-10:
 *   - null CLI arg     → fall through to Settings::default* for that filter
 *   - '' CLI arg       → clear the default (operator wants "no filter on this dimension")
 *   - non-empty string → comma-split (entities/locales) or use as-is (since)
 *
 * Each filter is independent — overriding --entities does not touch --locales.
 */
final class FilterFactory extends Component
{
    public function fromCli(
        ?string $entitiesArg,
        ?string $localesArg,
        ?string $sinceArg,
        // Phase 4.1 / D-26 — CLI override flags. Default false preserves Phase 2/3/4 callers.
        bool $noSeo = false,
        bool $noRetour = false,
    ): MigrationFilters {
        $settings = Plugin::getInstance()->getSettings();

        $entities = $entitiesArg !== null
            ? ($entitiesArg === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $entitiesArg)), static fn(string $s): bool => $s !== '')))
            : array_values((array) $settings->defaultEntities);

        $locales = $localesArg !== null
            ? ($localesArg === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $localesArg)), static fn(string $s): bool => $s !== '')))
            : array_values((array) $settings->defaultLocales);

        $since = $sinceArg !== null
            ? ($sinceArg === '' ? null : $sinceArg)
            : $settings->defaultSince;

        return new MigrationFilters(
            entities: $entities,
            locales:  $locales,
            since:    $since,
            noSeo:    $noSeo,
            noRetour: $noRetour,
        );
    }
}
