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

        $entities = self::normalizeEntityFilters(
            $entitiesArg !== null
                ? ($entitiesArg === '' ? [] : explode(',', $entitiesArg))
                : (array) $settings->defaultEntities,
        );

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

    /**
     * Normalize `--entities` values as Kunstmaan source identities.
     *
     * FQCN inputs retain their exact spelling and also add the class basename so
     * callers can compare either source form. Basename inputs are kept as-is; no
     * Craft handle/camel-case inference happens at this boundary.
     *
     * @param array<int, string> $entities
     * @return list<string>
     */
    public static function normalizeEntityFilters(array $entities): array
    {
        $normalized = [];
        $seen = [];

        foreach ($entities as $entity) {
            $entity = trim($entity);
            if ($entity === '') {
                continue;
            }

            foreach ([$entity, self::sourceBasename($entity)] as $identity) {
                if ($identity === '' || isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $normalized[] = $identity;
            }
        }

        return $normalized;
    }

    private static function sourceBasename(string $entity): string
    {
        $parts = explode('\\', $entity);

        return (string) end($parts);
    }
}
