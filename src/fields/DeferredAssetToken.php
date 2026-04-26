<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields;

/**
 * Emits the deferred-asset token format `asset:{legacyId}` shared by
 * AssetHandler (relation path) + RelationHandler (resolveViaJoinTable).
 *
 * Tokens are consumed at load time by AtomicMigrationService::
 * collectReferencedMediaIds() — see the /^asset:\d+$/ regex there.
 *
 * This helper is a 2→1 REDUCTION of duplicated string emission, not a new
 * abstraction: the format already had a single regex consumer; centralising
 * the producer side matches that shape (Phase 10.1-08, CONTEXT.md D-01).
 *
 * Scope: string-form deferred tokens only. The imgTag bracket-form
 * `[M{legacyId}]` emitted by AssetHandler's imgTag path has a different
 * consumer (CKEditor inline-asset handling) and is intentionally NOT
 * covered by this helper.
 */
final class DeferredAssetToken
{
    public static function emit(int $legacyId): string
    {
        return 'asset:' . $legacyId;
    }
}

// PAIRED REGEX CONTRACT (load-bearing): src/load/AtomicMigrationService.php (Plan 03-13)
// matches the emitted 'asset:N' string with /^asset:\d+$/ and captures the legacy id with
// /^asset:(\d+)$/. The format and the consumer regexes are tightly coupled — any change
// here MUST update AtomicMigrationService::ingestAndResolveAssets() at the same time.
