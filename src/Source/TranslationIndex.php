<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

use PDO;

/**
 * Legacy Symfony translator strings, keyed by (domain, keyword, locale).
 *
 * Some pagepart headings have no column of their own: `FaqPagePart` renders
 * `{{ 'faq.pagepart.title' | trans }}` unless its `hide_title` flag is on, and the visible
 * text lives only in TranslatorBundle's `kuma_translation` table, per locale. A mapping that
 * reads only the pagepart's own row has nothing to fall back to once the field is gone.
 *
 * Loaded once per environment, the same as `MediaIndex`: a typical corpus holds a few hundred
 * rows here, and a per-block query would run for every FaqPagePart on every locale.
 */
final class TranslationIndex
{
    /** @param array<string, string> $texts "domain:keyword:locale" => text */
    private function __construct(private readonly array $texts)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function load(PDO $pdo): self
    {
        $texts = [];

        try {
            $rows = $pdo->query(
                'SELECT keyword, locale, text, domain FROM ' . KunstmaanCoreTables::TRANSLATIONS
                . " WHERE status = 'enabled'",
            );
        } catch (\PDOException) {
            // Not every Kunstmaan vintage carries TranslatorBundle: the table may not exist.
            return new self([]);
        }

        foreach ($rows as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            $locale = (string) ($row['locale'] ?? '');

            if ($keyword === '' || $locale === '') {
                continue;
            }

            $domain = (string) ($row['domain'] ?? 'messages');
            $texts[$domain . ':' . $keyword . ':' . $locale] = (string) $row['text'];
        }

        return new self($texts);
    }

    /** The translated string for one keyword and locale, or null when the corpus has none. */
    public function textFor(string $keyword, string $locale, string $domain = 'messages'): ?string
    {
        return $this->texts[$domain . ':' . $keyword . ':' . $locale] ?? null;
    }
}
