<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Throwable;

/**
 * Per-run counters + warnings + failures accumulator.
 *
 * Consumed by the load-side adapter services' migrateAll() passes (Seo,
 * Redirect, Navigation, Translation) and AssetMigrationService's batch
 * ingest path — each accumulates incr/warn calls into one report per run.
 *
 * Greenfield-from-v1 VO: the v1 plugin's `Lameco\Kunstmaanmigrator\models\MigrationReport`
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
     * D-66 / D-68: per-asset RCA rows aggregated for the run.
     *
     * Populated via pushAssetRca() — typically called by MigrateController at
     * the end of a migrate run by snapshotting AssetMigrationService::$rcaRows
     * into the MigrationReport so writeReport's `## Asset RCA` table render
     * has a single uniform read site.
     *
     * @var list<array{legacyId: int, reason: string, path: string}>
     */
    public array $assetRcaRows = [];

    /**
     * Structural diagnostics for unresolved CKEditor finalize tokens.
     *
     * Rows are intentionally limited to ids/handles/reasons. They must never
     * contain CKEditor bodies, samples, or rendered HTML.
     *
     * @var list<array<string, mixed>>
     */
    public array $finalizeUnresolvedDiagnostics = [];

    /** @var list<array<string, mixed>> */
    public array $relationCoverageRows = [];

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
     * D-66 / D-68: push a per-asset RCA row into $this->assetRcaRows. Used by
     * MigrateController at the end of a run to fold AssetMigrationService's
     * service-level rcaRows into the MigrationReport so writeReport has one
     * uniform read site for the `## Asset RCA` REPORT.md section.
     */
    public function pushAssetRca(int $legacyId, string $reason, string $path): void
    {
        $this->assetRcaRows[] = [
            'legacyId' => $legacyId,
            'reason' => $reason,
            'path' => $path,
        ];
    }

    /**
     * @param list<array<string, mixed>> $coverageRows
     */
    public function recordRelationCoverage(array $coverageRows): void
    {
        foreach ($coverageRows as $row) {
            if (!is_array($row) || (string) ($row['surfaceType'] ?? '') !== 'relation') {
                continue;
            }
            $this->relationCoverageRows[] = $row;
            $category = (string) ($row['category'] ?? '');
            $reason = (string) ($row['reason'] ?? '');
            if ($category === 'warning' || str_contains($reason, 'relation.unresolved')) {
                $this->incr('relation.unresolved');
            } elseif ($category === 'dropped') {
                $this->incr('relation.intent.drop');
            } elseif ($category === 'out_of_scope') {
                $this->incr('relation.intent.out_of_scope');
            } elseif (str_contains($reason, 'relation.promoted') || str_contains($reason, 'promoted')) {
                $this->incr('relation.promoted');
            }
        }
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
     * Central truth for final command outcome. A run has failed when either
     * detailed failure rows were recorded or a stage merged a failed-count
     * bucket without pushing per-entry rows.
     */
    public function hasFailures(): bool
    {
        return $this->failureCount() > 0;
    }

    /**
     * Concise failure count for CLI summaries. Use the larger of the explicit
     * failures[] rows and the aggregate failed bucket so stage-level merged
     * reports cannot under-report failures.
     */
    public function failureCount(): int
    {
        return max(count($this->failures), (int) ($this->counts['failed'] ?? 0));
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
