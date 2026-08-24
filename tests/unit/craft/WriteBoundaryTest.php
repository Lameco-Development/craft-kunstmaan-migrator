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
    /**
     * Each boundary the write half is not allowed to cross directly, the one
     * adapter allowed to cross it, and what to use instead.
     */
    private const BOUNDARIES = [
        [
            'pattern' => '~Craft::\$app->elements->(\w+)~',
            'adapter' => 'src/craft/CraftElementWriter.php',
            'fake' => 'tests/support/InMemoryElementWriter.php',
            'seam' => 'ElementWriter',
            'instead' => 'save/delete/findById/invalidateCaches',
        ],
        [
            'pattern' => '~Navigation::\$plugin->(?:get\w+\(\)->)?(\w+)~',
            'adapter' => 'src/craft/VerbbNavigationGateway.php',
            'fake' => 'tests/support/InMemoryNavigationGateway.php',
            'seam' => 'NavigationGateway',
            'instead' => 'isAvailable/navIdByHandle/registerTempNodes',
        ],
        [
            'pattern' => '~Formie::\$plugin->(?:get\w+\(\)->)?(\w+)~',
            'adapter' => 'src/craft/VerbbFormieGateway.php',
            'fake' => 'tests/support/InMemoryFormGateway.php',
            'seam' => 'FormGateway',
            'instead' => 'isAvailable/formIdByHandle/saveForm',
        ],
        [
            'pattern' => '~\\\\spicyweb\\\\embeddedassets\\\\Plugin::\$plugin(?:->(\w+))?~',
            'adapter' => 'src/craft/SpicywebEmbedGateway.php',
            'fake' => 'tests/support/InMemoryEmbedGateway.php',
            'seam' => 'EmbedGateway',
            'instead' => 'available/createFromUrl',
        ],
    ];

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function boundaries(): iterable
    {
        foreach (self::BOUNDARIES as $boundary) {
            yield $boundary['seam'] => [$boundary];
        }
    }

    /**
     * @param array<string, string> $boundary
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundaries')]
    public function testOnlyTheAdapterCrossesTheBoundary(array $boundary): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder($this->repoRoot() . '/src') as $relative => $contents) {
            if ($relative === $boundary['adapter']) {
                continue;
            }
            // Docblocks name these seams when explaining them; only code counts.
            $contents = preg_replace('~^\s*\*.*$~m', '', $contents) ?? $contents;
            if (!preg_match_all($boundary['pattern'], $contents, $matches)) {
                continue;
            }
            foreach (array_unique($matches[1]) as $method) {
                $offenders[] = $relative . ' → ' . $method . '()';
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "These cross the %s boundary directly instead of going through the seam.\n"
                . "A static call admits no second adapter, so anything behind one of these is testable\n"
                . "only against the real thing. Inject %s and use %s:\n  %s",
                $boundary['seam'],
                $boundary['seam'],
                $boundary['instead'],
                implode("\n  ", $offenders),
            ),
        );
    }

    /**
     * An interface earns its keep only while both adapters exist. One adapter
     * is a hypothetical seam; two make it real.
     *
     * @param array<string, string> $boundary
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boundaries')]
    public function testEachSeamHasATestAdapterAsWellAsAProductionOne(array $boundary): void
    {
        $root = $this->repoRoot();

        self::assertFileExists($root . '/' . $boundary['adapter']);
        self::assertFileExists($root . '/' . $boundary['fake']);
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
