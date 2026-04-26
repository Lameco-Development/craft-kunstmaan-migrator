<?php

namespace lameco\kunstmaanmigrator\verify;

use lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher;
use Craft;
use yii\base\Component;

/**
 * CaptureBaselineHtmlService — snapshot HTML from a list of URLs into a
 * baseline directory using SpotCheckUrlFetcher normalization so diffs are
 * byte-stable against the migrated-site fetch.
 *
 * B1 fix (plan 04-07): D-17 golden-URL gate requires a pre-captured
 * baseline. Operator runs `./craft verify/capture-baseline-html` BEFORE
 * the first migration rehearsal, pointing at the LEGACY Kunstmaan site
 * URLs. Baselines are written to `storage/migration/baseline/<slug>.html`
 * and read by VerifyController::actionIndex.
 */
class CaptureBaselineHtmlService extends Component
{
    public ?SpotCheckUrlFetcher $fetcher = null;

    /**
     * @return int number of URLs captured
     */
    public function capture(string $urlListPath, string $outputDir): int
    {
        if (!is_file($urlListPath)) {
            throw new \RuntimeException("URL list not found: {$urlListPath}");
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Cannot create baseline dir: {$outputDir}");
        }

        $fetcher = $this->fetcher ?? new SpotCheckUrlFetcher();

        $lines = file($urlListPath);
        if ($lines === false) {
            throw new \RuntimeException("Cannot read URL list: {$urlListPath}");
        }

        $urls = array_filter(
            array_map('trim', $lines),
            static fn(string $l): bool => $l !== '' && !str_starts_with($l, '#'),
        );

        $count = 0;
        foreach ($urls as $url) {
            try {
                $html = $fetcher->fetchAndNormalize($url);
                $slug = $this->urlToSlug($url);
                $destination = rtrim($outputDir, '/') . '/' . $slug . '.html';
                if (file_put_contents($destination, $html) === false) {
                    throw new \RuntimeException("Write failed: {$destination}");
                }
                $count++;
            } catch (\Throwable $e) {
                Craft::warning(
                    "Baseline capture failed for {$url}: {$e->getMessage()}",
                    __METHOD__,
                );
            }
        }

        return $count;
    }

    private function urlToSlug(string $url): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline';
    }
}
