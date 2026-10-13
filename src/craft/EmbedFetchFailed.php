<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use RuntimeException;
use Throwable;

/**
 * The embed provider could not be reached: a timeout, an unresolvable host, a 5xx.
 *
 * Thrown by an `EmbedGateway` for the one kind of failure worth asking again. A URL the
 * provider does not support, a missing folder or an asset that fails validation is not:
 * the gateway returns null for those, and asking again would only spend another timeout.
 */
final class EmbedFetchFailed extends RuntimeException
{
    /** Whether a failure from the provider's HTTP client is the network, not the request. */
    public static function isTransient(Throwable $e): bool
    {
        if (interface_exists(\Psr\Http\Client\NetworkExceptionInterface::class)
            && $e instanceof \Psr\Http\Client\NetworkExceptionInterface
        ) {
            return true;
        }

        return preg_match(
            '/timed? ?out|could not resolve|connection (?:refused|reset|closed)|cURL error|\b5\d\d\b/i',
            $e->getMessage(),
        ) === 1;
    }
}
