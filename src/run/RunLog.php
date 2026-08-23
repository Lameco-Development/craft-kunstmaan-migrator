<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

use Craft;
use Throwable;

/**
 * The permanent record of what ran: one JSON line per event, in a file.
 *
 * A queue job's counts die with the job — success deletes the row — so
 * "what happened last Tuesday" had no answer. A file rather than a table
 * because the log must survive the migrations it describes: a migration
 * that wipes and reruns the database must not wipe its own history.
 *
 * Append-only and forgiving: a log that can throw turns a migration
 * failure into a logging failure, so a line that cannot be written is
 * dropped, and a line that cannot be parsed back is skipped.
 */
final class RunLog
{
    public function __construct(private readonly string $path)
    {
    }

    public static function default(): self
    {
        return new self(Craft::getAlias('@storage') . '/kunstmaan-migrator/runs.jsonl');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The started/failed/finished envelope every job shares.
     *
     * The work receives `$extra` by reference and fills it as facts become
     * known (counts, problem totals); both outcome events carry it, so a run
     * that fails after counting still reports what it counted.
     *
     * @param array<string, mixed> $context
     * @param callable(array<string, mixed> &$extra): void $work
     */
    public function track(string $job, array $context, callable $work): void
    {
        $this->append(['event' => 'started', 'job' => $job, ...$context]);
        $extra = [];

        try {
            $work($extra);
        } catch (Throwable $e) {
            $this->append(['event' => 'failed', 'job' => $job, ...$context, ...$extra, 'message' => $e->getMessage()]);

            throw $e;
        }

        $this->append(['event' => 'finished', 'job' => $job, ...$context, ...$extra]);
    }

    /** @param array<string, mixed> $entry */
    public function append(array $entry): void
    {
        try {
            @mkdir(dirname($this->path), 0o775, true);

            $line = json_encode(
                ['time' => date('c'), ...$entry],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (is_string($line)) {
                @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable) {
            // The run matters more than its record.
        }
    }

    /**
     * The most recent entries, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(int $limit = 100): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $lines = @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $out = [];

        foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
