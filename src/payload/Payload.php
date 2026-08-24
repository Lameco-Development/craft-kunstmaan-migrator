<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\payload;

use InvalidArgumentException;

/**
 * In-memory representation of the loader payload contract documented in
 * docs/loader-contract.md. `fromArray()` only enforces the structural shape
 * (right types, required keys present) — cross-referencing against the
 * actual Craft schema is `PayloadValidator`'s job.
 */
final class Payload
{
    /**
     * @param list<string> $aliases
     * @param array<string, array{enabled: bool, title: ?string, slug: ?string, fieldValues: array<string, mixed>, parentRef: ?string, postDate: ?string}> $sites
     */
    public function __construct(
        public readonly string $sourceUid,
        public readonly array $aliases,
        public readonly string $section,
        public readonly string $entryType,
        public readonly array $sites,
        /**
         * The legacy identity this entry came from: the page class, and the entity row id per
         * locale. `SeoMigrationService` keys `kuma_seo` on exactly these, and nothing in Craft
         * can reconstruct them afterwards.
         *
         * @var array{class: string, refIds: array<string, int>}
         */
        public readonly array $legacy = ['class' => '', 'refIds' => []],
        /**
         * A path segment rather than a page: an ancestor that owns part of a URL but never
         * became content of its own. It is disabled on every site — so Craft still computes
         * its URI and still hands the segment to its descendants, while the URL itself 404s
         * and falls through to Retour. That makes it the one payload allowed to be enabled
         * nowhere; see `PayloadValidator::NO_ENABLED_SITE`.
         */
        public readonly bool $structural = false,
        /**
         * A single-row config source (Kunstmaan `AbstractConfig`) merging into the
         * section's existing entry — Craft's auto-created Single, or whichever
         * contributor saved first. Its sites may carry no title at all: the title
         * an earlier contributor set must survive, so `PayloadValidator` exempts
         * this one shape from `MISSING_TITLE`.
         */
        public readonly bool $single = false,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            sourceUid: self::requireString($raw, 'sourceUid'),
            aliases: self::readAliases($raw),
            section: self::requireString($raw, 'section'),
            entryType: self::requireString($raw, 'entryType'),
            sites: self::readSites($raw),
            legacy: self::readLegacy($raw),
            structural: (bool) ($raw['structural'] ?? false),
            single: (bool) ($raw['single'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{class: string, refIds: array<string, int>}
     */
    private static function readLegacy(array $raw): array
    {
        $legacy = $raw['legacy'] ?? [];

        if (!is_array($legacy)) {
            return ['class' => '', 'refIds' => []];
        }

        $refIds = [];

        foreach ((array) ($legacy['refIds'] ?? []) as $locale => $refId) {
            if (is_numeric($refId) && (int) $refId > 0) {
                $refIds[(string) $locale] = (int) $refId;
            }
        }

        return ['class' => (string) ($legacy['class'] ?? ''), 'refIds' => $refIds];
    }

    private static function requireString(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Payload.%s must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string>
     */
    private static function readAliases(array $raw): array
    {
        $aliases = $raw['aliases'] ?? [];
        if (!is_array($aliases)) {
            throw new InvalidArgumentException('Payload.aliases must be an array of strings.');
        }
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                throw new InvalidArgumentException('Payload.aliases must be an array of strings.');
            }
        }

        return array_values($aliases);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, array{enabled: bool, title: ?string, slug: ?string, fieldValues: array<string, mixed>, parentRef: ?string, postDate: ?string}>
     */
    private static function readSites(array $raw): array
    {
        $rawSites = $raw['sites'] ?? [];
        if (!is_array($rawSites)) {
            throw new InvalidArgumentException('Payload.sites must be an object keyed by site handle.');
        }

        $sites = [];
        foreach ($rawSites as $handle => $data) {
            if (!is_string($handle) || $handle === '' || !is_array($data)) {
                throw new InvalidArgumentException(
                    sprintf('Payload.sites entry "%s" must be an object keyed by a non-empty site handle.', (string) $handle),
                );
            }
            $fieldValues = $data['fieldValues'] ?? [];
            if (!is_array($fieldValues)) {
                throw new InvalidArgumentException(sprintf('Payload.sites.%s.fieldValues must be an array.', $handle));
            }

            $sites[$handle] = [
                'enabled' => (bool) ($data['enabled'] ?? false),
                'title' => isset($data['title']) ? (string) $data['title'] : null,
                'slug' => isset($data['slug']) ? (string) $data['slug'] : null,
                'fieldValues' => $fieldValues,
                'parentRef' => isset($data['parentRef']) ? (string) $data['parentRef'] : null,
                'postDate' => isset($data['postDate']) ? (string) $data['postDate'] : null,
            ];
        }

        return $sites;
    }
}
