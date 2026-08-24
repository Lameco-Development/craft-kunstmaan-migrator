<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\craft;

use Craft;
use Throwable;

/**
 * spicyweb/craft-embedded-assets: `requestUrl()` fetches the oEmbed data (a
 * network call to the provider), `createAsset()` shapes it into an unsaved
 * `.json` Asset, and the element save lands it in the target folder like any
 * other migrated file.
 */
final class SpicywebEmbedGateway implements EmbedGateway
{
    public function available(): bool
    {
        return class_exists(\spicyweb\embeddedassets\Plugin::class)
            && \spicyweb\embeddedassets\Plugin::$plugin !== null;
    }

    public function createFromUrl(string $url, int $folderId): ?int
    {
        $plugin = \spicyweb\embeddedassets\Plugin::$plugin;
        $folder = Craft::$app->getAssets()->getFolderById($folderId);

        if ($plugin === null || $folder === null) {
            return null;
        }

        try {
            $embedded = $plugin->methods->requestUrl($url);
            $asset = $plugin->methods->createAsset($embedded, $folder);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                Craft::warning(
                    sprintf('Embedded asset save failed for %s: %s', $url, json_encode($asset->getErrors())),
                    __METHOD__,
                );

                return null;
            }

            return (int) $asset->id;
        } catch (Throwable $e) {
            // A provider that stopped answering for one URL must not take the
            // whole asset lane down — the caller falls back to the id-only
            // state row this branch used to write.
            Craft::warning(sprintf('Embed fetch failed for %s: %s', $url, $e->getMessage()), __METHOD__);

            return null;
        }
    }
}
