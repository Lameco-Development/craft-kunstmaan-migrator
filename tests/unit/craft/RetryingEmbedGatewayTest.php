<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\EmbedFetchFailed;
use Lameco\Kunstmaanmigrator\craft\RetryingEmbedGateway;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryEmbedGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A remote video's oEmbed fetch is a network call, and one that times out once is not a video
 * that cannot be migrated. Berkvens NL node 1585's YouTube video landed on one run and not the
 * next: "Connection timed out after 10003 milliseconds".
 *
 * Only a network failure is worth asking again, and a provider that is down for the whole run
 * must not cost every video three timeouts.
 */
final class RetryingEmbedGatewayTest extends TestCase
{
    private const URL = 'https://www.youtube.com/watch?v=km2_2TaUq0c';

    /** @var list<int> */
    private array $pauses = [];

    private function gateway(InMemoryEmbedGateway $inner, int $breakAfter = 3): RetryingEmbedGateway
    {
        return new RetryingEmbedGateway($inner, 3, function(int $attempt): void {
            $this->pauses[] = $attempt;
        }, $breakAfter);
    }

    #[Test]
    public function a_fetch_that_times_out_once_is_retried_and_lands(): void
    {
        $inner = new InMemoryEmbedGateway();
        $inner->flaky = 1;

        self::assertSame(700, $this->gateway($inner)->createFromUrl(self::URL, 5));
        self::assertSame([self::URL, self::URL], $inner->requested);
        self::assertSame([1], $this->pauses);
    }

    #[Test]
    public function a_provider_that_never_answers_is_given_up_on_after_the_last_attempt(): void
    {
        $inner = new InMemoryEmbedGateway();
        $inner->unreachable = [self::URL];

        self::assertNull($this->gateway($inner)->createFromUrl(self::URL, 5));
        self::assertCount(3, $inner->requested);
        self::assertSame([1, 2], $this->pauses);
    }

    #[Test]
    public function a_refusal_that_is_not_a_network_failure_is_not_retried(): void
    {
        $inner = new InMemoryEmbedGateway();
        $inner->refuse = [self::URL];

        self::assertNull($this->gateway($inner)->createFromUrl(self::URL, 5));
        self::assertCount(1, $inner->requested);
        self::assertSame([], $this->pauses);
    }

    #[Test]
    public function a_first_time_success_is_fetched_once(): void
    {
        $inner = new InMemoryEmbedGateway();

        self::assertSame(700, $this->gateway($inner)->createFromUrl(self::URL, 5));
        self::assertCount(1, $inner->requested);
        self::assertSame([], $this->pauses);
    }

    #[Test]
    public function after_consecutive_unreachable_videos_the_rest_are_tried_once(): void
    {
        $inner = new InMemoryEmbedGateway();
        $inner->unreachable = ['https://a', 'https://b', 'https://c'];
        $gateway = $this->gateway($inner, 2);

        $gateway->createFromUrl('https://a', 5);
        $gateway->createFromUrl('https://b', 5);
        $this->pauses = [];
        $inner->requested = [];

        self::assertNull($gateway->createFromUrl('https://c', 5));
        self::assertSame(['https://c'], $inner->requested);
        self::assertSame([], $this->pauses);
    }

    #[Test]
    public function a_video_that_lands_closes_the_breaker_again(): void
    {
        $inner = new InMemoryEmbedGateway();
        $inner->unreachable = ['https://a', 'https://b'];
        $gateway = $this->gateway($inner, 2);

        $gateway->createFromUrl('https://a', 5);
        $gateway->createFromUrl('https://b', 5);
        // Tripped: one try, but it answers — the provider is back.
        self::assertSame(700, $gateway->createFromUrl('https://ok', 5));

        $inner->flaky = 1;
        $inner->requested = [];
        self::assertSame(701, $gateway->createFromUrl('https://ok2', 5));
        self::assertCount(2, $inner->requested);
    }

    #[Test]
    public function availability_is_the_wrapped_plugins(): void
    {
        self::assertFalse($this->gateway(new InMemoryEmbedGateway(false))->available());
        self::assertTrue($this->gateway(new InMemoryEmbedGateway())->available());
    }

    #[Test]
    public function a_timeout_or_unreachable_host_is_transient_and_a_bad_url_is_not(): void
    {
        self::assertTrue(EmbedFetchFailed::isTransient(new RuntimeException('Connection timed out after 10003 milliseconds')));
        self::assertTrue(EmbedFetchFailed::isTransient(new RuntimeException('cURL error 6: Could not resolve host: www.youtube.com')));
        self::assertTrue(EmbedFetchFailed::isTransient(new RuntimeException('Server error: 503 Service Unavailable')));
        self::assertFalse(EmbedFetchFailed::isTransient(new RuntimeException('Invalid URL: not-a-url')));
        self::assertFalse(EmbedFetchFailed::isTransient(new RuntimeException('Client error: 404 Not Found')));
    }
}
