<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\CompilerRun;

/**
 * What compile writes from each node's `target: page` contexts, for `state/explain`.
 *
 * A page part leaves no block id in the state row, and which one filled a page context depends
 * on its data — a slider whose `requires:` came out empty lets the next part write — so the
 * answer is asked of the compiler rather than re-derived. One compiler and one run serve a whole
 * sweep; its run report is never read.
 */
final readonly class PageFills
{
    public function __construct(private Compiler $compiler, private CompilerRun $run)
    {
    }

    /** The short page entity a node is, or null for a node the run does not hold. */
    public function pageOf(int $nodeId): ?string
    {
        $entity = $this->run->nodesById[$nodeId]['entity'] ?? null;

        return is_string($entity) ? $entity : null;
    }

    /**
     * @return array<string, array<string, array{part: string, id: int}|null>>|null lang => context => part
     */
    public function of(int $nodeId): ?array
    {
        return $this->compiler->pageContextFills($this->run, $nodeId);
    }
}
