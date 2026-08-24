<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Payload;

/**
 * The sourceUid grammar — the migration's idempotency key — minted and parsed
 * in exactly one place.
 *
 * Before this class the grammar lived as five sprintf sites in the compile
 * half and two regexes plus three string-concatenation sites in the write
 * half; `RefResolver` called itself "the single source of truth" while
 * producing none of the uids it parsed. Every variant is now a named
 * constructor here, and the parsers delegate here.
 *
 * Variants:
 *   entity / node   kuma:<ENV>:<table>:<id>            (ENV may be `shared` — a deduplicated entity)
 *   form            kuma:<ENV>:form:<Entity>:<id>      (state-keyed as source `form`, key = whole uid)
 *   global part     kuma:<ENV>:global:<context>:<part>:<id>
 *   global child    kuma:<ENV>:global:<table>:<id>
 *
 * NOT this grammar: `RedirectMigrationService`'s `kuma:<id>` Retour state key —
 * a two-segment key under the redirect state source, never a sourceUid.
 */
final class SourceUid
{
    /** The environment token of a deduplicated entity — one uid across all databases. */
    public const SHARED = 'shared';

    private const ENTITY_PATTERN = '/^kuma:([A-Za-z0-9_-]+):([a-z0-9_]+):(\d+)$/D';
    private const FORM_PATTERN = '/^kuma:[A-Za-z0-9_-]+:form:[A-Za-z0-9_]+:\d+$/D';

    public static function forRow(string $environment, string $table, int $id): string
    {
        return sprintf('kuma:%s:%s:%d', $environment, $table, $id);
    }

    public static function forNode(string $environment, int $nodeId): string
    {
        return self::forRow($environment, 'kuma_nodes', $nodeId);
    }

    public static function forForm(string $environment, string $entity, int $id): string
    {
        return sprintf('kuma:%s:form:%s:%d', $environment, $entity, $id);
    }

    public static function forGlobalPart(string $environment, string $context, string $part, int $id): string
    {
        return sprintf('kuma:%s:global:%s:%s:%d', $environment, $context, $part, $id);
    }

    public static function forGlobalChild(string $environment, string $table, int $id): string
    {
        return sprintf('kuma:%s:global:%s:%d', $environment, $table, $id);
    }

    /**
     * Rebuild a uid from a state row's (source, key) pair — the inverse of
     * parse(), used when walking the state table back into payload space.
     */
    public static function fromStateRow(string $source, string $key): string
    {
        return sprintf('kuma:%s:%s', $source, $key);
    }

    /**
     * A form uid carries one segment more than the entity grammar and its
     * state row is keyed differently: source `form`, key = the whole uid.
     */
    public static function isForm(string $sourceUid): bool
    {
        return preg_match(self::FORM_PATTERN, $sourceUid) === 1;
    }

    /**
     * Entity-grammar parse into the state table's (source, key) pair.
     *
     * @return array{source: string, key: string}|null null when `$sourceUid` doesn't match the grammar
     */
    public static function parse(string $sourceUid): ?array
    {
        if (preg_match(self::ENTITY_PATTERN, $sourceUid, $m) !== 1) {
            return null;
        }

        return ['source' => $m[1] . ':' . $m[2], 'key' => $m[3]];
    }
}
