<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

/**
 * What one run has done so far.
 *
 * Replaces four by-reference accumulators threaded through a closure. The
 * references worked while a single console process owned the whole run; a job
 * that handles one environment and hands the next to another job needs
 * something it can hold, merge and report.
 */
final class RunTally
{
    /** @var array<string, int> */
    public array $counts = [
        'compiled' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'invalid' => 0,
        'failed' => 0,
    ];

    /** @var list<string> */
    public array $problems = [];

    /** @var list<string> */
    public array $unresolvedAssets = [];

    /** @var array<string, int> */
    public array $droppedAddresses = [];

    /** @var array<string, array<string, mixed>> */
    public array $adapters = [];

    /**
     * Per-locale block content the target's field configuration cannot hold,
     * one message per entry+field.
     *
     * These lived on `EntryMigrationService` as a public accumulator that only
     * the console read, so a queued run — the control panel's only path — lost
     * the count `--fail-on-loss` gates on. On the tally, both callers see it.
     *
     * @var list<string>
     */
    public array $perSiteBlockLosses = [];

    /**
     * Per-asset failures classified into the closed reason set
     * (filesystem_404 | mime_mismatch | too_large | deferred_unresolved).
     *
     * @var list<array{legacyId: int, reason: string, path: string}>
     */
    public array $assetFailures = [];

    public function count(string $bucket): void
    {
        $this->counts[$bucket] = ($this->counts[$bucket] ?? 0) + 1;
    }

    /**
     * Called for every problem as it happens.
     *
     * The JSON summary only reaches stdout once every environment has been walked, which on
     * a real corpus is hours. Twenty-two taxonomy entries once failed to save on every one of
     * them and nothing showed it until the run ended — a caught exception, a counter, and
     * silence. The console sets this to write stderr; a queue job sets it to log.
     *
     * @var (callable(string): void)|null
     */
    public $onProblem = null;

    public function problem(string $message): void
    {
        $this->problems[] = $message;

        if ($this->onProblem !== null) {
            ($this->onProblem)($message);
        }
    }

    public function unresolvedAsset(string $reference): void
    {
        $this->unresolvedAssets[] = $reference;
    }

    public function droppedAddress(string $field, string $site): void
    {
        $key = sprintf('%s on %s', $field, $site);
        $this->droppedAddresses[$key] = ($this->droppedAddresses[$key] ?? 0) + 1;
    }

    public function perSiteBlockLoss(string $message): void
    {
        $this->perSiteBlockLosses[] = $message;
    }

    public function assetFailure(int $legacyId, string $reason, string $path): void
    {
        $this->assetFailures[] = ['legacyId' => $legacyId, 'reason' => $reason, 'path' => $path];
    }

    public function hasFailures(): bool
    {
        return ($this->counts['failed'] ?? 0) > 0;
    }
}
