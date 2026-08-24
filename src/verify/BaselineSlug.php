<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\verify;

/**
 * URL → safe baseline filename slug. One implementation: the capture side
 * writes filenames with it and the verify side reads them back, so a change
 * here must stay compatible with previously captured baseline directories.
 */
final class BaselineSlug
{
    public static function of(string $url): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline';
    }
}
