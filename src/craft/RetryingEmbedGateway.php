<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Closure;

/**
 * Asks the wrapped gateway again when an embed could not be created.
 *
 * The oEmbed fetch is a network call to the video provider, and a provider that times out once
 * usually answers the next time: Berkvens NL node 1585's YouTube video landed on one run and
 * not on the next ("Connection timed out after 10003 milliseconds"), so the same mapping gave a
 * different result per run. A run that still fails after the last attempt leaves the reference
 * unresolved, and the entry saver records it in `unresolvedAssets`, where the gate sees it.
 */
final class RetryingEmbedGateway implements EmbedGateway
{
    /** @var Closure(int): void */
    private Closure $pause;

    /**
     * @param int $attempts how many times one URL is asked for, the first included
     * @param ?Closure(int): void $pause called before a retry with the number of the attempt
     *        that failed; defaults to sleeping one second per failed attempt
     */
    public function __construct(
        private readonly EmbedGateway $inner,
        private readonly int $attempts = 3,
        ?Closure $pause = null,
    ) {
        $this->pause = $pause ?? static function(int $failed): void {
            sleep($failed);
        };
    }

    public function available(): bool
    {
        return $this->inner->available();
    }

    public function createFromUrl(string $url, int $folderId): ?int
    {
        for ($attempt = 1; ; $attempt++) {
            $assetId = $this->inner->createFromUrl($url, $folderId);

            if ($assetId !== null || $attempt >= $this->attempts) {
                return $assetId;
            }

            ($this->pause)($attempt);
        }
    }
}
