<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

use PDO;

/**
 * Legacy origin URL => the manual `kuma_redirects` target it 301s to.
 *
 * An internal `[NT<id>]` link can address a node translation that never becomes a Craft
 * entry — the node is `deleted`, or its page type only compiles into the `redirects:` lane,
 * never `pages:`. Kunstmaan still served that translation's URL, and on the old site an
 * operator-maintained `kuma_redirects` row is what carried a visitor on to the real page —
 * the same table `RedirectMigrationService` imports into Retour verbatim. `BlockBuilder::
 * oneLink()` reuses it as the fallback value for a link whose `_linkRef` never resolves,
 * rather than dropping the link (and its label) entirely.
 *
 * Loaded once per environment, from the same table and columns
 * `RedirectMigrationService::importDirectRedirects()` reads for the live Retour import — this
 * class does not duplicate that import, only the read, and only origin/target: the loader-side
 * locale-prefix stripping and entry-URI re-resolution it also does are Craft-runtime concerns
 * with nowhere to run this early, at compile time.
 */
final class RedirectIndex
{
    /** Same fixed Kunstmaan table name `RedirectMigrationService::REDIRECTS_TABLE` reads. */
    public const TABLE = 'kuma_redirects';

    /** @param array<string, string> $targets normalised origin path => redirect target */
    private function __construct(private readonly array $targets)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function load(PDO $pdo): self
    {
        $targets = [];

        try {
            $rows = $pdo->query(sprintf('SELECT origin, target FROM `%s` ORDER BY id', self::TABLE));
        } catch (\PDOException) {
            return new self([]);
        }

        foreach ($rows as $row) {
            $origin = self::normalise((string) ($row['origin'] ?? ''));
            $target = trim((string) ($row['target'] ?? ''));

            // First row wins on a duplicate origin — the same precedence an id-ordered import
            // gives Retour, whose `saveRedirect()` updates the existing row in place.
            if ($origin !== '' && $target !== '' && !isset($targets[$origin])) {
                $targets[$origin] = $target;
            }
        }

        return new self($targets);
    }

    /** The redirect target for a legacy URL, or null when no manual redirect covers it. */
    public function targetFor(?string $legacyUrl): ?string
    {
        if ($legacyUrl === null) {
            return null;
        }

        $origin = self::normalise($legacyUrl);

        return $origin === '' ? null : ($this->targets[$origin] ?? null);
    }

    private static function normalise(string $path): string
    {
        $trimmed = trim($path);

        return $trimmed === '' ? '' : '/' . ltrim($trimmed, '/');
    }
}
