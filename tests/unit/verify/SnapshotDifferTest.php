<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\verify;

use lameco\kunstmaanmigrator\verify\SnapshotDiffer;
use PHPUnit\Framework\TestCase;

/**
 * Plan 04-12 Task 01 — characterization tests for the pure-function
 * SnapshotDiffer ported in Plan 04-03.
 *
 * Path delimiter conventions read from src/verify/SnapshotDiffer.php:
 *   - associative segments joined with '.' (compareAssoc)
 *   - list segments emitted as '[N]' (compareList)
 *   - meta is processed specially: META_IGNORE keys (generatedAt, gitSha)
 *     are stripped from BOTH meta arrays before comparison; they are NOT
 *     ignored when present at the top level.
 */
final class SnapshotDifferTest extends TestCase
{
    public function testIdenticalArraysReturnEmptyDiff(): void
    {
        $differ = new SnapshotDiffer();
        $a = ['sections' => ['news' => ['totalCount' => 10]]];
        $this->assertSame([], $differ->diff($a, $a));
    }

    public function testDifferingScalarProducesPathTriple(): void
    {
        $differ = new SnapshotDiffer();
        $a = ['sections' => ['news' => ['totalCount' => 10]]];
        $b = ['sections' => ['news' => ['totalCount' => 11]]];
        $diff = $differ->diff($a, $b);
        $this->assertCount(1, $diff);
        $this->assertSame('sections.news.totalCount', $diff[0]['path']);
        $this->assertSame(10, $diff[0]['baseline']);
        $this->assertSame(11, $diff[0]['current']);
    }

    public function testMetaIgnoreSkipsGeneratedAtAndGitSha(): void
    {
        $differ = new SnapshotDiffer();
        // META_IGNORE applies to keys nested under 'meta' specifically.
        $a = [
            'meta' => ['generatedAt' => '2026-04-26T00:00:00Z', 'gitSha' => 'abc'],
            'sections' => [],
        ];
        $b = [
            'meta' => ['generatedAt' => '2026-04-26T01:00:00Z', 'gitSha' => 'def'],
            'sections' => [],
        ];
        $this->assertSame([], $differ->diff($a, $b));
    }

    public function testListDiffEmitsBracketPath(): void
    {
        $differ = new SnapshotDiffer();
        $a = ['entries' => ['x', 'y', 'z']];
        $b = ['entries' => ['x', 'Y', 'z']];
        $diff = $differ->diff($a, $b);
        $this->assertCount(1, $diff);
        $this->assertSame('entries[1]', $diff[0]['path']);
        $this->assertSame('y', $diff[0]['baseline']);
        $this->assertSame('Y', $diff[0]['current']);
    }
}
