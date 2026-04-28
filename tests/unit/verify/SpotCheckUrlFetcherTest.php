<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\verify;

use lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Plan 04-12 Task 02 — characterization tests for the B1 fix in
 * SpotCheckUrlFetcher::diff(). The B1 fix replaced an earlier byte-count
 * proxy with a real line-level diff (lines present on exactly one side
 * are emitted with a `-` or `+` prefix).
 *
 * Tests use literal HTML inputs (NOT URL-prefixed) so diff() goes through
 * the local-normalize branch and never touches Craft::createGuzzleClient.
 */
final class SpotCheckUrlFetcherTest extends TestCase
{
    public function testIdenticalHtmlReturnsEmptyString(): void
    {
        $fetcher = new SpotCheckUrlFetcher();
        $html = "<html>\n<body>foo</body>\n</html>";
        $this->assertSame('', $fetcher->diff($html, $html));
    }

    /**
     * B1 fix invariant — the diff is line-level, NOT a byte-count proxy.
     * diff($current, $baseline): baseline-only lines get `-`, current-only lines get `+`.
     */
    public function testDifferingHtmlReturnsLineLevelDiffNotByteCount(): void
    {
        $fetcher = new SpotCheckUrlFetcher();
        $current = "<html>\n<body>foo</body>\n</html>";
        $baseline = "<html>\n<body>bar</body>\n</html>";
        $diff = $fetcher->diff($current, $baseline);

        $this->assertNotSame('', $diff, 'B1 fix: diff must produce non-empty output for differing inputs');

        // Baseline-only line gets `-` prefix; current-only line gets `+`.
        $this->assertStringContainsString('- <body>bar</body>', $diff, 'B1: baseline-only line must be prefixed with "-"');
        $this->assertStringContainsString('+ <body>foo</body>', $diff, 'B1: current-only line must be prefixed with "+"');

        // It must NOT be a numeric byte-count (the v1 false-pass shape that B1 replaced).
        $this->assertFalse(ctype_digit($diff), 'B1: output must not be a pure numeric byte count');
    }

    public function testStripPatternsRemoveCsrfTokenInput(): void
    {
        $fetcher = new SpotCheckUrlFetcher();
        // Two HTML docs that differ ONLY in CRAFT_CSRF_TOKEN value;
        // STRIP_PATTERNS must normalize those away to an empty diff.
        $a = "<html>\n<input name=\"CRAFT_CSRF_TOKEN\" value=\"abc\">\n<body>same</body>\n</html>";
        $b = "<html>\n<input name=\"CRAFT_CSRF_TOKEN\" value=\"xyz\">\n<body>same</body>\n</html>";
        $this->assertSame('', $fetcher->diff($a, $b));
    }

    public function testStripPatternsRemoveCacheBustingQueryStrings(): void
    {
        $fetcher = new SpotCheckUrlFetcher();
        $a = "<html>\n<link href=\"/style.css?v=12345\">\n<body>same</body>\n</html>";
        $b = "<html>\n<link href=\"/style.css?v=99999\">\n<body>same</body>\n</html>";
        // STRIP_PATTERNS removes ?v=N / ?ts=N — only difference normalizes to nothing.
        $this->assertSame('', $fetcher->diff($a, $b));
    }
}
