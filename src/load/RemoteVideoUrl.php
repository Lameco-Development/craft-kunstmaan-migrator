<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

/**
 * The watchable URL a legacy remote-video row points at.
 *
 * Kunstmaan's RemoteVideoHandler stores `{code, type}` as a PHP-serialized
 * blob in `kuma_media.metadata` — 281 live rows across the Enreach corpus,
 * none of which carried a URL column worth trusting. Pure so the derivation
 * is testable byte-for-byte; `unserialize` runs with classes forbidden.
 */
final class RemoteVideoUrl
{
    /** @param array<string, mixed> $row a kuma_media row */
    public static function fromRow(array $row): ?string
    {
        $metadata = $row['metadata'] ?? null;

        if (is_string($metadata) && $metadata !== '') {
            $decoded = @unserialize($metadata, ['allowed_classes' => false]);

            if (is_array($decoded)) {
                $code = trim((string) ($decoded['code'] ?? ''));
                $type = strtolower(trim((string) ($decoded['type'] ?? '')));
                $url = self::fromCode($type, $code);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        // Some flavours store the URL on the row itself instead of metadata.
        $raw = trim((string) ($row['url'] ?? ''));

        return preg_match('#^https?://#i', $raw) === 1 ? $raw : null;
    }

    /**
     * The code box held whatever an editor pasted, not only a provider id: on the Berkvens corpora
     * every live FR code carries `?rel=0`, and NL holds `watch?v=…` tails and whole URLs, some
     * filed under the wrong provider. A pasted URL names its provider by its host, so the host
     * wins over `type`; a query is dropped, never carried into the URL built from the id.
     */
    private static function fromCode(string $type, string $code): ?string
    {
        if (preg_match('#^https?://#i', $code) === 1) {
            return self::fromPastedUrl($code);
        }

        // A Vimeo code may carry an unlisted video's privacy hash (`<id>/<hash>`, `<id>?h=<hash>`),
        // which only the URL route reads; the host is fixed, so the code cannot name another.
        if ($type === 'vimeo') {
            return self::fromPastedUrl('https://vimeo.com/' . ltrim($code, '/'));
        }

        if (str_starts_with($code, 'watch?')) {
            parse_str((string) parse_url($code, PHP_URL_QUERY), $query);
            $code = is_string($query['v'] ?? null) ? $query['v'] : '';
        }

        return self::fromId($type, (string) preg_replace('/[?#].*$/s', '', $code));
    }

    private static function fromPastedUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), static fn(string $s): bool => $s !== ''));

        if ($host === 'youtu.be') {
            return self::fromId('youtube', $segments[0] ?? '');
        }

        if ($host === 'youtube.com' || str_ends_with($host, '.youtube.com')) {
            if (($segments[0] ?? '') === 'watch') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return self::fromId('youtube', is_string($query['v'] ?? null) ? $query['v'] : '');
            }

            return in_array($segments[0] ?? '', ['embed', 'shorts'], true) ? self::fromId('youtube', $segments[1] ?? '') : null;
        }

        if ($host === 'vimeo.com' || str_ends_with($host, '.vimeo.com')) {
            return self::fromVimeo($segments, (string) parse_url($url, PHP_URL_QUERY));
        }

        return null;
    }

    /**
     * Vimeo's shapes: `vimeo.com/<id>`, `vimeo.com/channels/<name>/<id>`, an unlisted
     * `vimeo.com/<id>/<hash>` and `player.vimeo.com/video/<id>?h=<hash>`. The id is the first
     * all-digit segment — the last one is the hash on an unlisted link. The hash is the one
     * thing kept beside it: without it an unlisted video does not play. Nothing else of the
     * input reaches the URL, and a hash that is not plain alphanumeric is dropped.
     *
     * @param list<string> $segments
     */
    private static function fromVimeo(array $segments, string $query): ?string
    {
        $at = null;

        foreach ($segments as $i => $segment) {
            if (ctype_digit($segment)) {
                $at = $i;
                break;
            }
        }

        if ($at === null) {
            return null;
        }

        parse_str($query, $params);
        $hash = $params['h'] ?? ($segments[$at + 1] ?? null);
        $url = 'https://vimeo.com/' . $segments[$at];

        return is_string($hash) && preg_match('/^[A-Za-z0-9]+$/D', $hash) === 1 ? $url . '/' . $hash : $url;
    }

    private static function fromId(string $type, string $code): ?string
    {
        if ($code === '' || preg_match('/^[A-Za-z0-9_\/-]+$/', $code) !== 1) {
            return null;
        }

        return match ($type) {
            'youtube' => 'https://www.youtube.com/watch?v=' . $code,
            'vimeo' => 'https://vimeo.com/' . $code,
            'dailymotion' => 'https://www.dailymotion.com/video/' . $code,
            default => null,
        };
    }
}
