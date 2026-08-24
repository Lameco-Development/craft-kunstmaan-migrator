<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

use Lameco\Kunstmaanmigrator\Payload\SourceUid;

/**
 * Turns a legacy foreign key into the `sourceUid` of the entry it points at.
 *
 * A raw FK is not a Craft element id, so `ref(<Entity>)` needs the entity's table to build
 * the uid the loader resolves against — `kuma:<ENV>:<table>:<id>`. That is also why the uid
 * grammar cannot be loosened: it is the idempotency key of the entry on the other end.
 *
 * `SHARED` is the environment token of a deduplicated entity. Three legacy databases holding
 * the same fourteen case categories are one taxonomy, and giving them one uid is what makes
 * them one entry instead of three; giving them their environment's uid is what would make
 * them three.
 */
final readonly class EntityIndex
{
    public const SHARED = SourceUid::SHARED;

    /** The node tree is addressable as an entity without being declared as one. */
    public const NODES = 'kuma_nodes';

    /**
     * @param array<string, array<string, mixed>> $entities the mapping's `entities:` lane
     * @param array<int, int> $nodeOfTranslation node translation id => node id, for `ref(nodeLink)`
     */
    public function __construct(
        private array $entities = [],
        private array $nodeOfTranslation = [],
    ) {
    }

    /**
     * Kunstmaan writes an internal link as `[NT<node translation id>]`, and some columns hold
     * either that or a literal URL. `ref(nodeLink)` reads the first form; `ref(node)` reads a
     * bare node id.
     */
    public const INTERNAL_LINK = '/^\[NT(\d+)\]$/';

    public function has(string $entity): bool
    {
        return in_array($entity, ['node', 'nodeLink'], true) || isset($this->entities[$entity]);
    }

    /** @return list<string> every entity name a `ref()` may name, for error messages */
    public function names(): array
    {
        return ['node', 'nodeLink', ...array_keys($this->entities)];
    }

    /** The uid of the row `$id` in `$entity`, or null when the FK is empty or undeclared. */
    public function uidFor(string $entity, mixed $id, string $environment): ?string
    {
        // `[NT<id>]` addresses a node *translation*, and the node is what becomes an entry.
        if ($entity === 'nodeLink') {
            if (preg_match(self::INTERNAL_LINK, trim((string) ($id ?? '')), $m) !== 1) {
                return null;
            }

            $nodeId = $this->nodeOfTranslation[(int) $m[1]] ?? null;

            return $nodeId === null ? null : self::uid($environment, self::NODES, $nodeId);
        }

        if ($id === null || $id === '' || !ctype_digit((string) $id)) {
            return null;
        }

        if ($entity === 'node') {
            return self::uid($environment, self::NODES, (int) $id);
        }

        $spec = $this->entities[$entity] ?? null;

        if (!is_array($spec) || ($spec['table'] ?? '') === '') {
            return null;
        }

        return self::uid(
            ($spec['dedupe'] ?? false) === true ? self::SHARED : $environment,
            (string) $spec['table'],
            (int) $id,
        );
    }

    /** The legacy table an entity reads, so a `lookup()` can reach a column on the row it points at. */
    public function tableFor(string $entity): ?string
    {
        if ($entity === 'node') {
            return self::NODES;
        }

        $table = $this->entities[$entity]['table'] ?? null;

        return is_string($table) && $table !== '' ? $table : null;
    }

    public static function uid(string $environment, string $table, int $id): string
    {
        return SourceUid::forRow($environment, $table, $id);
    }
}
