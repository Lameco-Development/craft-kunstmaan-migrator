<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\craft;

/**
 * Turns a remote-media URL into a saved embedded-asset element.
 *
 * An interface for the same reason FormGateway is one: the concrete
 * implementation talks to a plugin and the network, and the asset lane's
 * remote-video branch wants exercising without either.
 */
interface EmbedGateway
{
    public function available(): bool;

    /** The saved Asset element's id, or null when the embed could not be created. */
    public function createFromUrl(string $url, int $folderId): ?int;
}
