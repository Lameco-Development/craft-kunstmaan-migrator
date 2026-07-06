<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

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
        );
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
