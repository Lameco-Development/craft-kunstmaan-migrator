<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\load\SaveResult;
use Lameco\Kunstmaanmigrator\Payload\Payload;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\run\WriteConflictRetry;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use yii\db\Exception as DbException;

/**
 * A payload save that lost a write race runs again from the top.
 *
 * The retry used to sit inside the writer adapter, around the one element
 * save that raised the error. InnoDB rolls the whole transaction back on a
 * deadlock, so that retry committed one element on top of an entry whose
 * earlier statements were already gone. The payload is the unit that is safe
 * to run twice; these pin that the retry happens there, is bounded, is
 * counted, and says what it means when it gives up.
 */
final class WriteConflictRetryTest extends TestCase
{
    private const UID = 'kuma:COM:nt_page:7';

    /** @var list<int> the attempt each backoff was asked to wait after */
    private array $waited = [];

    private function payload(): Payload
    {
        return Payload::fromArray([
            'sourceUid' => self::UID,
            'section' => 'pages',
            'entryType' => 'contentPage',
            'sites' => ['en' => ['enabled' => true, 'title' => 'Seven', 'slug' => 'seven', 'fieldValues' => []]],
        ]);
    }

    private function env(): EnvironmentContext
    {
        return EnvironmentFactory::make('COM', ['en' => 'en'], ['en' => [1, 'en-GB', true]]);
    }

    private static function deadlock(): DbException
    {
        return new DbException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'],
        );
    }

    private static function saved(): SaveResult
    {
        return new SaveResult(sourceUid: self::UID, entryId: 600, created: true, deferredRefs: []);
    }

    /**
     * A save that throws each exception in `$failures` in turn, then succeeds.
     *
     * @param list<Throwable> $failures
     * @return callable(Payload, EnvironmentContext, RunTally): SaveResult
     */
    private function saveFailing(array $failures, int &$calls): callable
    {
        return static function() use ($failures, &$calls): SaveResult {
            $calls++;

            if ($failures !== [] && $calls <= count($failures)) {
                throw $failures[$calls - 1];
            }

            return self::saved();
        };
    }

    private function retry(callable $save, int $attempts = 3): WriteConflictRetry
    {
        return new WriteConflictRetry($save, $attempts, function(int $attempt): void {
            $this->waited[] = $attempt;
        });
    }

    public function testASaveThatSucceedsIsNotRetriedAndNotCounted(): void
    {
        $calls = 0;
        $tally = new RunTally();

        $result = $this->retry($this->saveFailing([], $calls))->save($this->payload(), $this->env(), $tally);

        self::assertSame(600, $result->entryId);
        self::assertSame(1, $calls);
        self::assertSame(0, $tally->counts['writeConflictRetries']);
        self::assertSame([], $this->waited);
    }

    public function testADeadlockRunsTheWholePayloadAgainAndIsCountedOnTheTally(): void
    {
        $calls = 0;
        $tally = new RunTally();

        $result = $this->retry($this->saveFailing([self::deadlock(), self::deadlock()], $calls))
            ->save($this->payload(), $this->env(), $tally);

        self::assertSame(600, $result->entryId, 'the third attempt is the one that landed');
        self::assertSame(3, $calls);
        self::assertSame(2, $tally->counts['writeConflictRetries']);
        self::assertSame([1, 2], $this->waited, 'the backoff grows with the attempt that just failed');
        self::assertSame([], $tally->problems, 'a retry that landed is not a problem');
    }

    public function testADeadlockWrappedByCraftIsStillRecognised(): void
    {
        // Craft's saveElement rethrows from inside its own transaction; the
        // driver error is the `previous` of whatever reaches the pipeline.
        $calls = 0;
        $wrapped = new RuntimeException('save failed', 0, self::deadlock());

        $this->retry($this->saveFailing([$wrapped], $calls))->save($this->payload(), $this->env(), new RunTally());

        self::assertSame(2, $calls);
    }

    public function testAMariaDbRecordChangedSinceLastReadIsAWriteConflictToo(): void
    {
        self::assertTrue(WriteConflictRetry::isWriteConflict(new DbException(
            'SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'structureelements\'',
            ['HY000', 1020, 'Record has changed since last read'],
        )));
    }

    public function testAnythingElseIsNotRetriedAndPropagatesUnchanged(): void
    {
        $calls = 0;
        $tally = new RunTally();
        $refused = new RuntimeException('Primary-site save failed for COM:nt_page:7');

        try {
            $this->retry($this->saveFailing([$refused], $calls))->save($this->payload(), $this->env(), $tally);
            self::fail('a refused save is not a write conflict');
        } catch (RuntimeException $e) {
            self::assertSame($refused, $e, 'the caller sees the exception the save threw, not a wrapper');
        }

        self::assertSame(1, $calls);
        self::assertSame(0, $tally->counts['writeConflictRetries']);
    }

    public function testRetriesAreBoundedAndExhaustionSaysTheEntryWasRolledBackWhole(): void
    {
        $calls = 0;
        $tally = new RunTally();
        $save = $this->saveFailing([self::deadlock(), self::deadlock(), self::deadlock(), self::deadlock()], $calls);

        try {
            $this->retry($save)->save($this->payload(), $this->env(), $tally);
            self::fail('three deadlocks in a row exhaust three attempts');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('rolled back whole after 3 write conflicts', $e->getMessage());
            self::assertStringContainsString('nothing of it was written', $e->getMessage());
            self::assertStringContainsString('1213 Deadlock', $e->getMessage(), 'the driver message travels with it');
            self::assertInstanceOf(DbException::class, $e->getPrevious());
        }

        self::assertSame(3, $calls, 'the bound is total attempts, first try included');
        self::assertSame(2, $tally->counts['writeConflictRetries'], 'the attempt that gave up is not a retry');
        self::assertSame([1, 2], $this->waited);
        self::assertSame(0, $tally->counts['created'], 'nothing was absorbed for a payload that never landed');
    }
}
