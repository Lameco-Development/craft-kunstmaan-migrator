<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use craft\elements\Entry;
use craft\queue\jobs\UpdateElementSlugsAndUris;
use craft\queue\jobs\UpdateSearchIndex;
use Lameco\Kunstmaanmigrator\craft\UriJobGuard;

/**
 * The second adapter: a queue that is a list, and a veto that is a flag.
 *
 * A test pushes what Craft would have pushed and reads back what got
 * through — so the rule "entry slug jobs, and nothing else, while armed" is
 * asserted on the decision rather than on a queue table afterwards.
 */
final class InMemoryUriJobGuard implements UriJobGuard
{
    /** @var list<array{class: string, elementType: string}> what reached the queue */
    public array $queued = [];

    public bool $armed = false;

    /** Every arm()/disarm() in call order, for asserting the pairing. */
    public array $transitions = [];

    private int $vetoed = 0;

    public function arm(): void
    {
        $this->armed = true;
        $this->vetoed = 0;
        $this->transitions[] = 'arm';
    }

    public function disarm(): int
    {
        $this->armed = false;
        $this->transitions[] = 'disarm';

        return $this->vetoed;
    }

    public function release(): int
    {
        $before = count($this->queued);
        $this->queued = array_values(array_filter(
            $this->queued,
            static fn(array $job): bool => !self::isEntryUriJob($job),
        ));

        return $before - count($this->queued);
    }

    /**
     * What Craft's queue would have done with this push: through, or vetoed.
     *
     * @param class-string $class
     */
    public function push(string $class = UpdateElementSlugsAndUris::class, string $elementType = Entry::class): bool
    {
        $job = ['class' => $class, 'elementType' => $elementType];

        if ($this->armed && self::isEntryUriJob($job)) {
            $this->vetoed++;

            return false;
        }

        $this->queued[] = $job;

        return true;
    }

    public function pushSearchIndex(): bool
    {
        return $this->push(UpdateSearchIndex::class);
    }

    /** @param array{class: string, elementType: string} $job */
    private static function isEntryUriJob(array $job): bool
    {
        return $job['class'] === UpdateElementSlugsAndUris::class && $job['elementType'] === Entry::class;
    }
}
