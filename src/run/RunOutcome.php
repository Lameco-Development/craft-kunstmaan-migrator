<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

use yii\console\ExitCode;

/**
 * What a finished run's exit code should be.
 *
 * Pulled out of the controller because it is the part worth being sure about: a migration that
 * drops content has always exited 0, identically to one that dropped none, so noticing a loss
 * was a thing an operator chose to do by reading JSON. `--fail-on-loss` makes ignoring it the
 * choice instead.
 *
 * A failure always wins over a loss — a run that could not write is not merely lossy.
 */
final class RunOutcome
{
    public static function exitCode(
        bool $hasFailures,
        int $invalid,
        bool $failOnLoss,
        int $lossyConversions,
        int $unresolvedAssets,
        int $unresolvedRefs,
    ): int {
        if ($hasFailures || $invalid > 0) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($failOnLoss && self::lost($lossyConversions, $unresolvedAssets, $unresolvedRefs)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /** Whether the run lost anything at all — counted the same way whether or not it gates. */
    public static function lost(int $lossyConversions, int $unresolvedAssets, int $unresolvedRefs): bool
    {
        return $lossyConversions > 0 || $unresolvedAssets > 0 || $unresolvedRefs > 0;
    }
}
