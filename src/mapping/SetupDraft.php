<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\mapping;

/**
 * What the operator has chosen so far, carried between the wizard's steps.
 *
 * In the URL rather than the session, deliberately: a half-finished setup you
 * can bookmark, share, or come back to after checking a path on disk is worth
 * more than one that quietly expires. None of it is secret — database names
 * and environment labels — and the credentials it will be used with live in
 * the plugin settings, not here.
 */
final class SetupDraft
{
    /** @param array<string, string> $environments label => legacy database */
    private function __construct(public readonly array $environments)
    {
    }

    /**
     * Parse `LV:enreach_website_lv,DE:enreach_website_de`.
     *
     * One flat parameter rather than `env[LV]=…`, so the URL carries no square
     * brackets. They survive a query string, but they have to be encoded to
     * survive being a form's target, and a step that silently posts nowhere is
     * a worse bug than a slightly less pretty URL.
     */
    public static function fromString(string $raw): self
    {
        $environments = [];

        foreach (array_filter(array_map(trim(...), explode(',', $raw))) as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }

            [$label, $database] = array_map(trim(...), explode(':', $pair, 2));

            // A label has to be usable as a mapping key and as the `<ENV>` half
            // of every state row's source, so it is constrained here rather
            // than at the point a migration fails on it.
            if ($label === '' || $database === '' || preg_match('~^[A-Za-z][A-Za-z0-9_-]*$~', $label) !== 1) {
                continue;
            }

            $environments[$label] = $database;
        }

        return new self($environments);
    }

    public function toString(): string
    {
        $pairs = [];

        foreach ($this->environments as $label => $database) {
            $pairs[] = $label . ':' . $database;
        }

        return implode(',', $pairs);
    }

    public function isEmpty(): bool
    {
        return $this->environments === [];
    }

    /**
     * Words that name what a database *is*, not which site it holds.
     *
     * Almost every Kunstmaan database ends in one, so taking the last segment
     * naively labels half a server `WEBSITE`.
     */
    private const GENERIC_SUFFIXES = ['website', 'site', 'db', 'cms', 'live', 'prod'];

    /**
     * A label for a database, when the operator has not typed one.
     *
     * Kunstmaan multi-site installs name their databases by suffix almost
     * without exception — `enreach_website`, `enreach_website_de`,
     * `enreach_website_lv` — and that suffix is what people call the
     * environment. So the generic tail comes off first, and what remains is
     * either a locale (`de`) or the site itself (`enreach`).
     *
     * Derived from the name alone, deliberately. An earlier version compared
     * node counts to find "the primary", which is meaningless on a shared
     * server holding thirteen unrelated projects — and was wrong anyway,
     * comparing each database against the first rather than the largest.
     *
     * Guessing saves typing; being wrong costs a correction in a text box.
     */
    public static function suggestLabel(string $database): string
    {
        $segments = array_values(array_filter(explode('_', strtolower($database))));

        while (count($segments) > 1 && in_array(end($segments), self::GENERIC_SUFFIXES, true)) {
            array_pop($segments);
        }

        $label = end($segments) ?: $database;

        return strtoupper(preg_replace('~[^A-Za-z0-9]~', '', $label) ?: 'ENV');
    }
}
