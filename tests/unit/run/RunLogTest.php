<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\run;

use lameco\kunstmaanmigrator\run\RunLog;
use PHPUnit\Framework\TestCase;

/**
 * The permanent record of what ran. Append-only, newest first, and forgiving —
 * a log that can throw turns a migration failure into a logging failure.
 */
final class RunLogTest extends TestCase
{
    public function testEntriesComeBackNewestFirstAndStamped(): void
    {
        $log = new RunLog(tempnam(sys_get_temp_dir(), 'runlog') . '.jsonl');

        $log->append(['event' => 'started', 'job' => 'migrate']);
        $log->append(['event' => 'finished', 'job' => 'migrate', 'counts' => ['pages' => 3]]);

        $entries = $log->entries();

        self::assertCount(2, $entries);
        self::assertSame('finished', $entries[0]['event'], 'newest first — the question is always "what just happened"');
        self::assertSame(['pages' => 3], $entries[0]['counts']);
        self::assertNotEmpty($entries[0]['time'], 'an unstamped log line answers nothing');
    }

    public function testTheTailIsBoundedAndStillNewestFirst(): void
    {
        $log = new RunLog(tempnam(sys_get_temp_dir(), 'runlog') . '.jsonl');

        for ($i = 1; $i <= 150; $i++) {
            $log->append(['event' => 'finished', 'n' => $i]);
        }

        $entries = $log->entries(100);

        self::assertCount(100, $entries);
        self::assertSame(150, $entries[0]['n'], 'newest first');
        self::assertSame(51, $entries[99]['n'], 'the oldest shown is the 51st-newest');
    }

    public function testACorruptLineIsSkippedNotFatal(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'runlog') . '.jsonl';
        $log = new RunLog($path);

        $log->append(['event' => 'started']);
        file_put_contents($path, "not json at all\n", FILE_APPEND);
        $log->append(['event' => 'finished']);

        self::assertSame(
            ['finished', 'started'],
            array_column($log->entries(), 'event'),
            'a half-written line must not take the readable history with it',
        );
    }

    public function testAnUnwritablePathIsSilent(): void
    {
        $log = new RunLog('/nonexistent-root/nope/runs.jsonl');

        $log->append(['event' => 'started']);

        self::assertSame([], $log->entries(), 'the run matters more than its record');
    }
}
