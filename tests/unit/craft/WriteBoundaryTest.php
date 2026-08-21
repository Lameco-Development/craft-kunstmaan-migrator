<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\craft;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The seam, enforced.
 *
 * A seam nothing stops you bypassing is a convention, and this codebase has
 * already shown what happens to those: in the three hours after the write half
 * was first reviewed, `Craft::$app` call sites went from 73 to 77 and a fourth
 * copy of a duplicated site loop became a fifth. Nobody did anything wrong —
 * reaching for the static was simply the shortest path.
 *
 * So the boundary is a test rather than a note. Adding a direct element call to
 * a migration module fails here, with the reason and the alternative.
 */
final class WriteBoundaryTest extends TestCase
{
    /** The one place allowed to talk to Craft's elements service. */
    private const ADAPTER = 'src/craft/CraftElementWriter.php';

    public function testOnlyTheAdapterTalksToCraftsElementsService(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder($this->repoRoot() . '/src') as $relative => $contents) {
            if ($relative === self::ADAPTER) {
                continue;
            }
            if (!preg_match_all('~Craft::\$app->elements->(\w+)~', $contents, $matches)) {
                continue;
            }
            foreach (array_unique($matches[1]) as $method) {
                $offenders[] = $relative . ' → ' . $method . '()';
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These reach Craft's elements service directly instead of going through the ElementWriter seam.\n"
            . "A static call admits no second adapter, so anything behind one of these is testable only\n"
            . "against a real database. Inject ElementWriter and use save/delete/findById/invalidateCaches:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /**
     * The interface earns its keep only while both adapters exist. One adapter
     * is a hypothetical seam; two make it real.
     */
    public function testTheSeamHasATestAdapterAsWellAsAProductionOne(): void
    {
        $root = $this->repoRoot();

        self::assertFileExists($root . '/' . self::ADAPTER);
        self::assertFileExists($root . '/tests/support/InMemoryElementWriter.php');
    }

    /** @return array<string, string> relative path => contents */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $root = $this->repoRoot();

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getRealPath();
            $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
        }

        ksort($files);

        return $files;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
