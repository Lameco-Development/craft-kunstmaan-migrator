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
 * class does not duplicate that import, only the read, and only origin/target. It does still
 * have to strip the origin's locale prefix, the same way that service's own
 * `stripLegacyLocalePrefix()` does before it queries `kuma_node_translations`: an origin row
 * reads `/nl/over-ons/team`, and `EntityIndex::legacyUrlOfNodeLink()` — the value this class is
 * matched against — reads the translation's bare `over-ons/team`, with no prefix at all. Left
 * unstripped, an origin on any environment with more than one locale never matches.
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

    /** @param list<string> $locales the environment's legacy locale codes, for stripping an origin's `/{locale}/` prefix */
    public static function load(PDO $pdo, array $locales = []): self
    {
        $targets = [];

        try {
            $rows = $pdo->query(sprintf('SELECT origin, target FROM `%s` ORDER BY id', self::TABLE));
        } catch (\PDOException) {
            return new self([]);
        }

        foreach ($rows as $row) {
            $origin = self::normalise(self::stripLocalePrefix((string) ($row['origin'] ?? ''), $locales));
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

    /**
     * Strips a leading `/{locale}/` (or a bare `/{locale}`) segment — the same shape
     * `RedirectMigrationService::stripLegacyLocalePrefix()` strips loader-side, kept in step
     * with it rather than duplicated blindly: locale codes come from the mapping's own
     * `environments.<ENV>.locales`, not a hardcoded guess.
     *
     * @param list<string> $locales
     */
    private static function stripLocalePrefix(string $path, array $locales): string
    {
        $stripped = ltrim(trim($path), '/');

        foreach ($locales as $locale) {
            if (!is_string($locale) || $locale === '') {
                continue;
            }

            if ($stripped === $locale) {
                return '';
            }

            $prefix = $locale . '/';

            if (str_starts_with($stripped, $prefix)) {
                return substr($stripped, strlen($prefix));
            }
        }

        return $stripped;
    }

    private static function normalise(string $path): string
    {
        $trimmed = trim($path);

        return $trimmed === '' ? '' : '/' . ltrim($trimmed, '/');
    }
}
