<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

use lameco\kunstmaanmigrator\load\MigrationStateReader;

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
    private const SOURCE_UID_PATTERN = '/^kuma:([A-Za-z0-9_-]+):([a-z0-9_]+):(\d+)$/D';

    /**
     * A form's sourceUid (`kuma:<ENV>:form:<Entity>:<id>`, minted by
     * `FormCompiler`) carries one segment more than the entry grammar. Its
     * state row is keyed differently too: `FormMigrationService` records
     * `source = "form"`, `key = <the whole sourceUid>`.
     */
    private const FORM_UID_PATTERN = '/^kuma:[A-Za-z0-9_-]+:form:[A-Za-z0-9_]+:\d+$/D';

    public function __construct(private readonly MigrationStateReader $stateReader)
    {
    }

    public function resolve(string $sourceUid): ?int
    {
        if (preg_match(self::FORM_UID_PATTERN, $sourceUid) === 1) {
            return $this->stateReader->getTargetId('form', $sourceUid);
        }

        $parsed = self::parse($sourceUid);
        if ($parsed === null) {
            return null;
        }

        return $this->stateReader->getTargetId($parsed['source'], $parsed['key']);
    }

    /**
     * Pure grammar parser — the single source of truth for the `sourceUid`
     * encoding, reused by `MigrationStateService::resolveSourceUid()` and
     * `recordAlias()` (via static call) so the regex is defined in exactly
     * one place.
     *
     * @return array{source: string, key: string}|null null when `$sourceUid` doesn't match the grammar
     */
    public static function parse(string $sourceUid): ?array
    {
        if (preg_match(self::SOURCE_UID_PATTERN, $sourceUid, $m) !== 1) {
            return null;
        }

        return ['source' => $m[1] . ':' . $m[2], 'key' => $m[3]];
    }
}
