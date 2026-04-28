<?php

namespace lameco\kunstmaanmigrator\verify;

use Craft;
use DOMComment;
use DOMDocument;
use DOMXPath;
use Throwable;
use yii\base\Component;

/**
 * SpotCheckUrlFetcher — fetch rendered HTML for a fixed allow-list of URLs
 * and strip volatile markup so pre/post-refactor diffs are stable.
 *
 * Volatile markup stripped (see 05-RESEARCH.md Q9 lines 1216-1236):
 *   - CSRF input + meta tags
 *   - Blitz cache comments
 *   - Vite dev-asset URLs + HMR client script
 *   - Cache-busting query strings (`?v=`, `?ts=`)
 *   - Timestamp data-attrs
 *
 * T-05-01-01 mitigation: this service only fetches URLs from an operator-
 * curated list. Callers pass full URLs — the service does NOT resolve
 * relative paths against a user-controlled base. Downstream Plan 09 will
 * add an allow-list assertion before fetch.
 */
class SpotCheckUrlFetcher extends Component
{
    /** Default User-Agent so server-side logs can identify spot-check traffic. */
    private const USER_AGENT = 'kunstmaan-migrator-spotcheck/1.0';

    /** Regex strip list applied after DOM-level comment removal. */
    private const STRIP_PATTERNS = [
        // CSRF input/meta
        '#<input[^>]*name=["\']CRAFT_CSRF_TOKEN["\'][^>]*>#si',
        '#<meta[^>]*name=["\']csrf-token["\'][^>]*>#si',
        // Blitz markers (any Blitz HTML-comment noise)
        '#<!--\s*Blitz[^>]*?-->#is',
        // Vite HMR client
        '#<script[^>]*src=["\']https?://localhost:3000/@vite/client["\'][^>]*></script>#si',
        // Timestamp data-attrs (ISO-8601-ish)
        '#\s+data-[a-z-]+="\d{4}-\d{2}-\d{2}T[^"]*"#i',
        // Cache-busting query strings
        '#\?(?:v|ts)=\d+#i',
    ];

    /**
     * Fetch a URL and return normalized HTML.
     *
     * @throws \RuntimeException on fetch failure
     */
    public function fetchAndNormalize(string $url): string
    {
        $html = $this->fetch($url);
        return $this->normalize($html);
    }

    /**
     * Diff the live rendering of a URL (or a raw HTML string) against a
     * previously-captured baseline HTML snapshot.
     *
     * Plan 04-07 VerifyController (B1 fix) calls this with either:
     *   - a full URL — fetched + normalized on the fly, then compared
     *   - a raw HTML string — normalized in-place (useful in tests)
     *
     * Returns an empty string when the normalized bodies are equal; otherwise
     * returns a unified-ish textual diff summary. The signature accepts
     * baseline HTML directly so callers that have already loaded the
     * baseline snapshot (e.g. BaselineSnapshotService consumers) don't need
     * to re-read files from disk per URL.
     *
     * @param string $urlOrHtml Either `http(s)://...` URL or raw HTML body.
     * @param string $otherHtml Baseline HTML to diff against (already normalized).
     *
     * @return string Empty string on match; non-empty textual diff on mismatch.
     */
    public function diff(string $urlOrHtml, string $otherHtml): string
    {
        $liveHtml = preg_match('#^https?://#i', $urlOrHtml) === 1
            ? $this->fetchAndNormalize($urlOrHtml)
            : $this->normalize($urlOrHtml);

        $baseHtml = $this->normalize($otherHtml);

        if ($liveHtml === $baseHtml) {
            return '';
        }

        // Compact line-level diff — every line present on exactly one side
        // becomes a prefixed entry. Good enough for spot-check verify output;
        // downstream tooling (diff-so-fancy / delta) can reformat if desired.
        $liveLines = preg_split("/\r\n|\r|\n/", $liveHtml) ?: [];
        $baseLines = preg_split("/\r\n|\r|\n/", $baseHtml) ?: [];
        $liveSet = array_flip($liveLines);
        $baseSet = array_flip($baseLines);

        $out = [];
        foreach ($baseLines as $line) {
            if (!array_key_exists($line, $liveSet)) {
                $out[] = '- ' . $line;
            }
        }
        foreach ($liveLines as $line) {
            if (!array_key_exists($line, $baseSet)) {
                $out[] = '+ ' . $line;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Stub for Plan 09 — reads a URL list file and diffs each URL against
     * a baseline HTML snapshot. Returns an empty array for now so
     * downstream wiring can reference the method.
     *
     * TODO(Plan 09): implement baseline HTML diff. Will read
     * `spot-check-urls.txt`, fetch each URL, normalize, then compare against
     * `.planning/phases/05-refactor-in-place/baseline-html/<slug>.html` files
     * produced during Wave 1's verification capture.
     *
     * @return array<int, array{url: string, diff: string}>
     */
    public function diffAgainstBaseline(string $urlListPath, string $baselineTimestamp): array
    {
        // Intentional: method surface exists for Plan 07 `verify` subcommand
        // wiring and Plan 09 baseline-HTML capture. Real diff lands in Plan 09.
        // Parameters are part of the stable signature — reference them so
        // static analyzers don't flag them as unused stubs.
        unset($urlListPath, $baselineTimestamp);
        return [];
    }

    /**
     * Fetch one URL. Tries Craft's Guzzle client first, then falls back to
     * stream_context_create for environments where Guzzle is unavailable.
     */
    private function fetch(string $url): string
    {
        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 30,
                'verify' => false,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);
            $response = $client->get($url);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 400) {
                throw new \RuntimeException("Non-OK HTTP {$status} for {$url}");
            }
            return (string) $response->getBody();
        } catch (Throwable $guzzleException) {
            // Fall back to the native streams wrapper.
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: ' . self::USER_AGENT . "\r\n",
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                throw new \RuntimeException(
                    "Failed to fetch {$url}: " . $guzzleException->getMessage(),
                    0,
                    $guzzleException,
                );
            }
            return $body;
        }
    }

    /**
     * Apply DOM-level comment removal, regex strip list, and whitespace
     * normalization.
     */
    private function normalize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // 1. Load through DOMDocument and remove all comment nodes.
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $comments = $xpath->query('//comment()');
            if ($comments !== false) {
                foreach ($comments as $comment) {
                    if ($comment instanceof DOMComment && $comment->parentNode !== null) {
                        $comment->parentNode->removeChild($comment);
                    }
                }
            }
            $serialized = $dom->saveHTML();
            if (is_string($serialized) && $serialized !== '') {
                $html = $serialized;
            }
        }

        // 2. Apply regex strip list.
        foreach (self::STRIP_PATTERNS as $pattern) {
            $out = preg_replace($pattern, '', $html);
            if (is_string($out)) {
                $html = $out;
            }
        }

        // 3. Replace Vite dev asset URLs with a stable placeholder.
        $html = (string) preg_replace('#https?://localhost:3000/[^"\s\']+#i', '__DEV_ASSET__', $html);

        // 4. Normalize whitespace — collapse runs of spaces, double-blank lines.
        $html = (string) preg_replace('/[ \t]+/', ' ', $html);
        $html = (string) preg_replace('/\n\s*\n+/', "\n\n", $html);

        return trim($html);
    }
}
