<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Closure;

/**
 * Asks the wrapped gateway again when the embed provider could not be reached.
 *
 * The oEmbed fetch is a network call to the video provider, and a provider that times out once
 * usually answers the next time: Berkvens NL node 1585's YouTube video landed on one run and
 * not on the next ("Connection timed out after 10003 milliseconds"), so the same mapping gave a
 * different result per run. Only `EmbedFetchFailed` is retried; a refusal (null) is final.
 *
 * A provider that is down for the whole run would cost every video `attempts` timeouts, so
 * after `breakAfter` videos in a row could not be reached, each further video is tried once,
 * until one lands. A video that still fails is left unresolved, and the entry saver records it
 * in `unresolvedAssets`, where the gate sees it.
 */
final class RetryingEmbedGateway implements EmbedGateway
{
    /** @var Closure(int): void */
    private Closure $pause;

    /** Videos in a row that could not be reached at all. */
    private int $unreachableInARow = 0;

    /**
     * @param int $attempts how many times one URL is asked for, the first included
     * @param ?Closure(int): void $pause called before a retry with the number of the attempt
     *        that failed; defaults to sleeping one second per failed attempt
     * @param int $breakAfter unreachable videos in a row after which retrying stops
     */
    public function __construct(
        private readonly EmbedGateway $inner,
        private readonly int $attempts = 3,
        ?Closure $pause = null,
        private readonly int $breakAfter = 3,
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
        $attempts = $this->unreachableInARow >= $this->breakAfter ? 1 : $this->attempts;

        for ($attempt = 1; ; $attempt++) {
            try {
                $assetId = $this->inner->createFromUrl($url, $folderId);
            } catch (EmbedFetchFailed) {
                if ($attempt < $attempts) {
                    ($this->pause)($attempt);

                    continue;
                }

                $this->unreachableInARow++;

                return null;
            }

            // The provider answered, whatever it said.
            $this->unreachableInARow = 0;

            return $assetId;
        }
    }
}
