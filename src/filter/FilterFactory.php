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
        array $relationGraph = [],
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
            relationGraph: $relationGraph,
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

    /**
     * Normalize the analyzer's relation-graph artifact into the reachability map
     * consumed by MigrationFilters.
     *
     * Supported inputs:
     * - Phase 8.5/9 artifact rows:
     *   `FQCN => ['manyToOne' => [['targetEntity' => TargetFqcn], ...]]`
     * - Already-normalized maps:
     *   `FQCN => [TargetFqcn, ...]`
     *
     * @param array<string, mixed> $artifact
     * @return array<string, list<string>>
     */
    public static function relationGraphFromArtifact(array $artifact): array
    {
        $graph = [];

        foreach ($artifact as $source => $row) {
            $source = (string) $source;
            if ($source === '') {
                continue;
            }

            $targets = [];
            if (is_array($row) && array_is_list($row)) {
                foreach ($row as $target) {
                    $target = is_string($target) ? trim($target) : '';
                    if ($target !== '' && !isset($targets[$target])) {
                        $targets[$target] = true;
                    }
                }
            } elseif (is_array($row)) {
                foreach ((array) ($row['manyToOne'] ?? []) as $relation) {
                    if (!is_array($relation)) {
                        continue;
                    }
                    $target = trim((string) ($relation['targetEntity'] ?? ''));
                    if ($target !== '' && !isset($targets[$target])) {
                        $targets[$target] = true;
                    }
                }
                foreach ((array) ($row['manyToMany'] ?? []) as $relation) {
                    if (!is_array($relation)) {
                        continue;
                    }
                    $target = trim((string) ($relation['targetEntity'] ?? ''));
                    if ($target !== '' && !isset($targets[$target])) {
                        $targets[$target] = true;
                    }
                }
                foreach ((array) ($row['oneToMany'] ?? []) as $relation) {
                    if (!is_array($relation)) {
                        continue;
                    }
                    $target = trim((string) ($relation['targetEntity'] ?? ''));
                    if ($target !== '' && !isset($targets[$target])) {
                        $targets[$target] = true;
                    }
                }
            }

            if ($targets !== []) {
                $graph[$source] = array_keys($targets);
            }
        }

        return $graph;
    }

    private static function sourceBasename(string $entity): string
    {
        $parts = explode('\\', $entity);

        return (string) end($parts);
    }
}
