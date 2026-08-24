<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use Lameco\Kunstmaanmigrator\load\SaveResult;
use Lameco\Kunstmaanmigrator\Payload\Payload;
use RuntimeException;
use Throwable;
use yii\db\Exception as DbException;

/**
 * Retry a payload save that lost a write race, from the top.
 *
 * A structure insert shifts nested-set boundaries, and the moment a CP tab is
 * open Craft's web runner processes slug/search jobs against the same rows —
 * the loser gets a 1213 deadlock (SQLSTATE 40001) or, on MariaDB, a 1020
 * "record has changed since last read". Both are retry-after-backoff
 * conditions, not errors: the migration is correct, it just lost a race a
 * normal operator's open browser tab creates.
 *
 * The retry has to be the whole payload. InnoDB answers a deadlock by rolling
 * back the entire transaction, not the statement, and the entry's transaction
 * is the outer one: the state pre-check, the primary save, every secondary
 * site row and the state writes are all inside it. Retrying the one element
 * save that raised the error — which this plugin once did inside the writer
 * adapter — committed that save on top of an entry whose earlier statements
 * had already been discarded, while the transaction runner reported success.
 * A payload save, by contrast, is idempotent through the state table and the
 * sourceUid, so running it again from the top is exactly what a rollback asks
 * for.
 */
final class WriteConflictRetry
{
    private const RETRYABLE = ['1020', '1213', '40001'];

    /** @var callable(Payload, EnvironmentContext, RunTally): SaveResult */
    private $save;

    /** @var callable(int): void */
    private $backoff;

    /**
     * @param callable(Payload, EnvironmentContext, RunTally): SaveResult $save the save being retried, normally `PayloadEntrySaver::save(...)`
     * @param int $attempts how many times the save may run in total, first try included
     * @param ?callable(int): void $backoff what to do between attempts, given the attempt that just failed; defaults to a short sleep that grows per attempt
     */
    public function __construct(callable $save, private readonly int $attempts = 3, ?callable $backoff = null)
    {
        $this->save = $save;
        $this->backoff = $backoff ?? static function(int $attempt): void {
            usleep($attempt * 200_000);
        };
    }

    /**
     * @throws Throwable anything that is not a write conflict, unchanged; a write conflict that
     *   survived every attempt, as a RuntimeException naming the payload and carrying the last one
     */
    public function save(Payload $payload, EnvironmentContext $context, RunTally $tally): SaveResult
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return ($this->save)($payload, $context, $tally);
            } catch (Throwable $e) {
                if (!self::isWriteConflict($e)) {
                    throw $e;
                }

                if ($attempt >= $this->attempts) {
                    throw new RuntimeException(sprintf(
                        'rolled back whole after %d write conflicts, nothing of it was written: %s',
                        $attempt,
                        $e->getMessage(),
                    ), 0, $e);
                }

                $tally->count('writeConflictRetries');
                ($this->backoff)($attempt);
            }
        }
    }

    public static function isWriteConflict(Throwable $e): bool
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof DbException) {
                $code = (string) ($cursor->errorInfo[1] ?? '');
                $state = (string) ($cursor->errorInfo[0] ?? '');

                if (in_array($code, self::RETRYABLE, true) || in_array($state, self::RETRYABLE, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
