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

    private static function fromCode(string $type, string $code): ?string
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
