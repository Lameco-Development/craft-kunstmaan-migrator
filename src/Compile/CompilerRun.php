<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

use Lameco\Kunstmaanmigrator\Mapping\PageRow;
use Lameco\Kunstmaanmigrator\Source\PageReader;
use Lameco\Kunstmaanmigrator\Source\PartReader;

/**
 * One environment's compile context, held open between units of work.
 *
 * `Compiler::compile()` walks a whole environment in one call, which is the
 * right shape for a console run and the wrong one for a queue: a batched job
 * compiles fifty nodes, ends its process, and resumes in a fresh one. This
 * object is everything the walk had in scope — the readers, the builders, the
 * parentable map, the structural backlog — so a resumed batch stands exactly
 * where the previous one stopped.
 *
 * Mutable on purpose: `parentable` and `pendingStructural` are the walk's
 * running state, and the unit methods on `Compiler` advance them.
 */
final class CompilerRun
{
    /**
     * @param array<string, ?string> $locales
     * @param array<string, PageRow> $pageRows
     * @param array<int, string> $parentable
     * @param array<int, array<string, mixed>> $ancestry
     * @param array<int, array<string, mixed>> $nodesById lft-ordered
     * @param array<int, int> $pendingStructural nodeId => lft, ascending
     */
    public function __construct(
        public readonly string $environment,
        public readonly \PDO $pdo,
        public readonly PageReader $pages,
        public readonly PartReader $parts,
        public readonly BlockBuilder $builder,
        public readonly SequenceEngine $sequencer,
        public readonly array $locales,
        public readonly array $pageRows,
        public array $parentable,
        public readonly array $ancestry,
        public readonly array $nodesById,
        public array $pendingStructural,
    ) {
    }

    public function lftOf(int $nodeId): int
    {
        return (int) ($this->ancestry[$nodeId]['lft'] ?? PHP_INT_MAX);
    }
}
