<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\Payload\SourceUid;

/**
 * Resolves a `sourceUid` (grammar: `kuma:<ENV>:<table>:<id>`, see
 * docs/loader-contract.md) to the Craft element id the migration state
 * table has already recorded for it, or `null` when the target hasn't been
 * loaded yet (deferred-ref case — see `PayloadEntrySaver`).
 *
 * State-key encoding (bound exactly, see Task 4 brief): the `sourceUid`
 * `kuma:<ENV>:<table>:<id>` maps to state `source = "<ENV>:<table>"`,
 * `key = "<id>"` — the same (stateSource, stateKey) pair
 * `EntryMigrationService::saveEntryForSites()` records against, so a
 * just-saved entry is resolvable by its own sourceUid immediately.
 *
 * Depends on the `MigrationStateReader` read-only interface (not the
 * concrete `MigrationStateService`) so it's unit-testable with a fake state
 * reader, matching the `SchemaGateway`/`CraftSchemaGateway` convention —
 * no Craft application needs to boot to exercise the grammar-parsing logic.
 */
final class RefResolver
{
    public function __construct(private readonly MigrationStateReader $stateReader)
    {
    }

    public function resolve(string $sourceUid): ?int
    {
        // A form's sourceUid carries one segment more than the entry grammar
        // and its state row is keyed differently: `FormMigrationService`
        // records `source = "form"`, `key = <the whole sourceUid>`.
        if (SourceUid::isForm($sourceUid)) {
            return $this->stateReader->getTargetId('form', $sourceUid);
        }

        $parsed = self::parse($sourceUid);
        if ($parsed === null) {
            return null;
        }

        return $this->stateReader->getTargetId($parsed['source'], $parsed['key']);
    }

    /**
     * Pure grammar parser, reused by `MigrationStateService::resolveSourceUid()`
     * and `recordAlias()` (via static call). The grammar itself is owned by
     * `SourceUid`, next to the constructors that mint every uid — this is a
     * write-half convenience alias, not a second definition.
     *
     * @return array{source: string, key: string}|null null when `$sourceUid` doesn't match the grammar
     */
    public static function parse(string $sourceUid): ?array
    {
        return SourceUid::parse($sourceUid);
    }
}
