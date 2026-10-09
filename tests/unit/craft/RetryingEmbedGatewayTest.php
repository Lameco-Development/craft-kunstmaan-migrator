<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\RetryingEmbedGateway;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryEmbedGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A remote video's oEmbed fetch is a network call, and one that times out once is not a video
 * that cannot be migrated. Berkvens NL node 1585's YouTube video landed on one run and not the
 * next: "Connection timed out after 10003 milliseconds".
 */
final class RetryingEmbedGatewayTest extends TestCase
{
    private const URL = 'https://www.youtube.com/watch?v=km2_2TaUq0c';

    /** @var list<int> */
    private array $pauses = [];

    private function gateway(InMemoryEmbedGateway $inner, int $attempts = 3): RetryingEmbedGateway
    {
        return new RetryingEmbedGateway($inner, $attempts, function(int $attempt): void {
            $this->pauses[] = $attempt;
        });
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
        $inner->refuse = [self::URL];

        self::assertNull($this->gateway($inner)->createFromUrl(self::URL, 5));
        self::assertCount(3, $inner->requested);
        self::assertSame([1, 2], $this->pauses);
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
    public function availability_is_the_wrapped_plugins(): void
    {
        self::assertFalse($this->gateway(new InMemoryEmbedGateway(false))->available());
        self::assertTrue($this->gateway(new InMemoryEmbedGateway())->available());
    }
}
