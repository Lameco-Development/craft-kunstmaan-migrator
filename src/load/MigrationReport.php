<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Throwable;

/**
 * Per-run counters + warnings + failures accumulator.
 *
 * Consumed by:
 *  - AtomicMigrationService::migrateOneEntry (Plan 03-12) — inline incr/warn during per-entry pipeline.
 *  - AssetMigrationService (Plan 03-05) — re-binds deferred markers to incr/warn calls (Plan 03-14 wires).
 *  - MigrateController (Plan 03-14) — renders REPORT.md per D-50 (failures) + D-52 (counts).
 *
 * Greenfield-from-v1 VO: the v1 plugin's `lameco\kunstmaanmigrator\models\MigrationReport`
 * carried per-run counters + warnings; this file rebuilds the same minimal surface in v2's
 * flattened layout and adds the D-50 `recordFailure(...)` schema (last-5-frames stack
 * excerpt) which v1 did not expose explicitly.
 */
final class MigrationReport
{
    /**
     * @var array<string, int> bucketName → count
     */
    public array $counts = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @var list<array{legacyId: string|int, slug: ?string, handler: ?string, message: string, trace: ?string}>
     */
    public array $failures = [];

    /**
     * Increment a named bucket by `$by` (default 1). Idempotent: a missing
     * bucket initialises to 0 then accumulates.
     */
    public function incr(string $bucket, int $by = 1): void
    {
        $this->counts[$bucket] = ($this->counts[$bucket] ?? 0) + $by;
    }

    /**
     * Append a free-form warning string to the per-run warnings list.
     */
    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Record a per-entry failure with a 5-frame stack-trace excerpt (D-50).
     *
     * Also increments the `'failed'` bucket so REPORT.md can show a single
     * total without re-counting `$this->failures`.
     */
    public function recordFailure(string|int $legacyId, ?string $slug, ?string $handler, Throwable $e): void
    {
        // Trace excerpt: first 5 frames per CONTEXT D-50.
        $trace = $e->getTrace();
        $excerpt = array_slice($trace, 0, 5);
        $this->failures[] = [
            'legacyId' => $legacyId,
            'slug' => $slug,
            'handler' => $handler,
            'message' => $e->getMessage(),
            'trace' => $this->renderTrace($excerpt),
        ];
        $this->incr('failed');
    }

    /**
     * Render a list of stack frames as `file:line class::method` lines —
     * preserves the D-50 stack-trace excerpt format.
     *
     * @param list<array{file?: string, line?: int, class?: string, type?: string, function?: string}> $frames
     */
    private function renderTrace(array $frames): string
    {
        $lines = [];
        foreach ($frames as $f) {
            $file = $f['file'] ?? '?';
            $line = $f['line'] ?? '?';
            $cls = $f['class'] ?? '';
            $type = $f['type'] ?? '';
            $fn = $f['function'] ?? '?';
            $lines[] = "{$file}:{$line} {$cls}{$type}{$fn}";
        }
        return implode("\n", $lines);
    }
}
