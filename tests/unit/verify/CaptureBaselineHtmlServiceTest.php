<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\verify;

use lameco\kunstmaanmigrator\verify\CaptureBaselineHtmlService;
use lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Plan 04-12 Task 03 — characterization test for the URL-list filter
 * in CaptureBaselineHtmlService::capture(). The filter must skip lines
 * starting with `#` (comments) and blank lines per Plan 04-08 contract.
 *
 * Uses a stub fetcher that overrides fetchAndNormalize to avoid any
 * Craft / Guzzle dependency in unit tests.
 */
final class CaptureBaselineHtmlServiceTest extends TestCase
{
    public function testCommentsAndBlankLinesAreSkipped(): void
    {
        $tmp = sys_get_temp_dir() . '/kmm-test-' . uniqid();
        mkdir($tmp);
        $listPath = $tmp . '/urls.txt';
        $outDir = $tmp . '/out';
        // Three non-URL lines (comment, blank, comment) + one valid URL.
        file_put_contents($listPath, "# comment line\n\n# another comment\nhttps://example.test/page1\n");

        $stub = new class extends SpotCheckUrlFetcher {
            public function fetchAndNormalize(string $url): string
            {
                return '<html>' . htmlspecialchars($url) . '</html>';
            }
        };
        $service = new CaptureBaselineHtmlService();
        $service->fetcher = $stub;

        $count = $service->capture($listPath, $outDir);

        $this->assertSame(1, $count, 'Only the single non-comment, non-blank URL should be captured');
        $files = glob($outDir . '/*.html');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);

        // Cleanup.
        @unlink($files[0]);
        @rmdir($outDir);
        @unlink($listPath);
        @rmdir($tmp);
    }

    public function testMissingUrlListThrows(): void
    {
        $service = new CaptureBaselineHtmlService();
        $this->expectException(\RuntimeException::class);
        $service->capture(sys_get_temp_dir() . '/kmm-no-such-' . uniqid(), sys_get_temp_dir() . '/kmm-out-' . uniqid());
    }
}
