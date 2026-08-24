<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use Lameco\Kunstmaanmigrator\load\SaveResult;

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
        // A payload save that lost a write race and ran again from the top. Not a
        // failure by itself, but a run that shows many of them had a CP tab open.
        'writeConflictRetries' => 0,
    ];

    /** @var list<string> */
    public array $problems = [];

    /**
     * One entry per unresolved `_asset` node, with the payload it came from.
     *
     * @var list<array<string, mixed>>
     */
    public array $unresolvedAssets = [];

    /** @var array<string, int> */
    public array $droppedAddresses = [];

    /**
     * One entry per unresolvable `{{kuma:media:<id>}}` token, with the payload
     * it came from. A `migrate` run never reported these: the pipeline read
     * three of `SaveResult`'s five fields and the standalone loader a different
     * three, so each surface dropped what the other showed.
     *
     * @var list<array<string, mixed>>
     */
    public array $mediaTokenIssues = [];

    /**
     * Refs a save could not resolve yet and left for the fixup pass, with the
     * payload they came from.
     *
     * @var list<array<string, mixed>>
     */
    public array $deferredRefs = [];

    /**
     * What the compiler skipped and why, and what the transforms could not
     * carry across — folded from the compile half at the end of an environment
     * (console) or a batch (queue), so a queued run reports them too.
     *
     * @var array<string, int>
     */
    public array $skippedSources = [];

    /** @var array<string, array<string, int>> transform => "from -> to" => count */
    public array $losses = [];

    public int $lossyConversions = 0;

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

    /**
     * Everything one save reported, in one place — so no reader can drop half
     * of it. Which count a non-created entry lands in depends on whether the
     * saver refreshes existing entries (`--force`).
     */
    public function absorb(SaveResult $result, bool $refreshesExisting): void
    {
        $this->count($result->created ? 'created' : ($refreshesExisting ? 'updated' : 'skipped'));

        foreach ($result->unresolvedAssets as $entry) {
            $this->unresolvedAssets[] = ['sourceUid' => $result->sourceUid] + $entry;
        }

        foreach ($result->mediaTokenIssues as $entry) {
            $this->mediaTokenIssues[] = ['sourceUid' => $result->sourceUid] + $entry;
        }

        foreach ($result->deferredRefs as $entry) {
            $this->deferredRefs[] = ['sourceUid' => $result->sourceUid] + $entry;
        }

        foreach ($result->droppedAddresses as $dropped) {
            $this->droppedAddress((string) $dropped['field'], (string) $dropped['site']);
        }
    }

    /**
     * @param array<string, int>                $skipped    source => count, as `Compiler::skipped()` gives it
     * @param array<string, array<string, int>> $losses     as `Transforms::losses()` gives it
     */
    public function absorbCompileReport(array $skipped, array $losses, int $lossCount): void
    {
        foreach ($skipped as $source => $count) {
            $this->skippedSources[$source] = ($this->skippedSources[$source] ?? 0) + $count;
        }

        foreach ($losses as $transform => $pairs) {
            foreach ($pairs as $pair => $count) {
                $this->losses[$transform][$pair] = ($this->losses[$transform][$pair] ?? 0) + $count;
            }
        }

        $this->lossyConversions += $lossCount;
    }

    /** @return list<string> the distinct asset references, for a sample */
    public function unresolvedAssetReferences(): array
    {
        return array_values(array_unique(array_map(
            static fn(array $entry): string => (string) ($entry['asset'] ?? '?'),
            $this->unresolvedAssets,
        )));
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
