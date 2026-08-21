<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\run;

/**
 * The two things a preflight has to go outside for.
 *
 * Kept as a seam for the usual reason: the decisions worth testing are which
 * environment is ready and what to say about the ones that are not, and those
 * decisions are unreachable behind a live MySQL connection and a real
 * filesystem.
 */
interface PreflightProbe
{
    /**
     * Rows in the legacy `kuma_nodes` table, or null when the database cannot
     * be reached or has no such table.
     *
     * Both failures collapse into null on purpose — the caller distinguishes
     * them with reachable() rather than by reading a count.
     */
    public function nodeCount(string $database): ?int;

    public function reachable(string $database): bool;

    public function directoryReadable(string $path): bool;
}
