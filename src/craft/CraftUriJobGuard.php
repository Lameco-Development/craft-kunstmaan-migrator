<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use Closure;
use Craft;
use craft\elements\Entry;
use craft\queue\jobs\UpdateElementSlugsAndUris;
use craft\queue\QueueInterface;
use yii\base\InvalidArgumentException;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * The production adapter: a handler on the queue's before-push event, and
 * the queue's own job listing and release for what was pushed without it.
 *
 * `yii\queue\Queue::push()` returns without pushing when a before-push
 * handler marks the event handled; Craft's queue calls that `push()`. The
 * handler is process-global on a singleton, which is exactly what ADR-0009
 * keeps out of the services — so it is installed and removed by the pipeline
 * around the run, never left behind on purpose. A process that dies armed
 * takes the handler with it.
 */
final class CraftUriJobGuard implements UriJobGuard
{
    private ?Closure $handler = null;

    private int $vetoed = 0;

    public function arm(): void
    {
        if ($this->handler !== null) {
            return;
        }

        $this->vetoed = 0;
        $this->handler = function(PushEvent $event): void {
            if (self::isEntryUriJob($event->job)) {
                $event->handled = true;
                $this->vetoed++;
            }
        };

        Craft::$app->getQueue()->on(Queue::EVENT_BEFORE_PUSH, $this->handler);
    }

    public function disarm(): int
    {
        if ($this->handler === null) {
            return 0;
        }

        Craft::$app->getQueue()->off(Queue::EVENT_BEFORE_PUSH, $this->handler);
        $this->handler = null;

        return $this->vetoed;
    }

    public function release(): int
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof QueueInterface) {
            return 0;
        }

        $released = 0;

        foreach ($queue->getJobInfo() as $info) {
            if ((int) ($info['status'] ?? 0) !== Queue::STATUS_WAITING) {
                continue;
            }

            $id = (string) $info['id'];

            try {
                $details = $queue->getJobDetails($id);
            } catch (InvalidArgumentException) {
                // A worker took it between the listing and here.
                continue;
            }

            if (!self::isEntryUriJob($details['job'] ?? null)) {
                continue;
            }

            $queue->release($id);
            $released++;
        }

        return $released;
    }

    /**
     * The one job the URI pass makes redundant. Categories and other
     * Structure elements are not walked by the pass, so their jobs stand.
     */
    private static function isEntryUriJob(mixed $job): bool
    {
        return $job instanceof UpdateElementSlugsAndUris
            && isset($job->elementType)
            && $job->elementType === Entry::class;
    }
}
