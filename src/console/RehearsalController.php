<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * `kunstmaan-migrator/rehearsal/check` console controller — Phase 5 / TST-04 / D-21..D-23.
 *
 * Three mechanical gates over a committed rehearsal directory:
 *   1. Counts within tolerance — parses [1/2] Count-match block from VERIFY.md
 *   2. Zero unresolved CKEditor tokens — greps REPORT.md for [NT<id>] / [M<id>] /
 *      asset:<n> patterns (allow-list at <dir>/allow-tokens.txt, optional)
 *   3. All assets RCA-tagged — every row in REPORT.md ## Asset RCA has a non-
 *      empty reason
 *
 * IMPORTANT: This controller does NOT carry NeverProductionTrait (D-22, Phase 5
 * PATTERNS callout 6). The command is read-only over committed artifacts on disk
 * — never opens the legacy DB connection, never reads KUNSTMAAN_SOURCE_PATH,
 * never touches kunstmaanmigrator_state. Applying the trait would gate a CI step
 * on CRAFT_ENVIRONMENT != production for no purposeful reason. Deliberate
 * departure from the "every CLI carries the trait" pattern; documented here so
 * a future maintainer doesn't reflexively add it.
 */
class RehearsalController extends Controller
{
    // INTENTIONALLY not importing the trait — see class docblock (D-22).

    public bool $verbose = false;

    /**
     * @param string $actionID
     * @return string[]
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['verbose']);
    }

    /**
     * Run the three rehearsal gates against a committed rehearsal directory.
     *
     * @param string $dir Path to the rehearsal directory (e.g.
     *                    `.planning/rehearsal/v1.0/cqm`). Required files:
     *                    REPORT.md, VERIFY.md. Optional: allow-tokens.txt.
     */
    public function actionCheck(string $dir): int
    {
        $this->stdout("Rehearsal check: {$dir}\n", Console::FG_CYAN);

        if (!is_dir($dir)) {
            $this->stderr("FAIL: directory not found: {$dir}\n", Console::FG_RED);
            return 2;
        }

        $reportPath = rtrim($dir, '/') . '/REPORT.md';
        $verifyPath = rtrim($dir, '/') . '/VERIFY.md';
        $allowPath  = rtrim($dir, '/') . '/allow-tokens.txt';

        if (!is_file($reportPath)) {
            $this->stderr("FAIL: missing {$reportPath}\n", Console::FG_RED);
            return 2;
        }
        if (!is_file($verifyPath)) {
            $this->stderr("FAIL: missing {$verifyPath}\n", Console::FG_RED);
            return 2;
        }

        $reportMd = (string) file_get_contents($reportPath);
        $verifyMd = (string) file_get_contents($verifyPath);
        $allowed  = is_file($allowPath)
            ? self::parseAllowTokens((string) file_get_contents($allowPath))
            : [];

        $failures = [];

        $g1 = self::parseCountMatchBlock($verifyMd);
        if ($g1 === []) {
            $this->stdout("  OK   Gate 1 — counts within tolerance\n", Console::FG_GREEN);
        } else {
            $this->stderr("  FAIL Gate 1 — counts within tolerance:\n", Console::FG_RED);
            foreach ($g1 as $f) {
                $this->stderr("       - {$f}\n", Console::FG_RED);
            }
            $failures = array_merge($failures, $g1);
        }

        $tokens = self::findUnresolvedCkeditorTokens($reportMd, $allowed);
        if ($tokens === []) {
            $this->stdout("  OK   Gate 2 — zero unresolved CKEditor tokens\n", Console::FG_GREEN);
        } else {
            $this->stderr(
                '  FAIL Gate 2 — unresolved CKEditor tokens (' . count($tokens) . "):\n",
                Console::FG_RED,
            );
            foreach ($tokens as $t) {
                $this->stderr("       - {$t}\n", Console::FG_RED);
            }
            $failures = array_merge(
                $failures,
                array_map(static fn(string $t) => "Unresolved CKEditor token: {$t}", $tokens),
            );
        }

        $g3 = self::parseAssetRcaTable($reportMd);
        if ($g3 === []) {
            $this->stdout("  OK   Gate 3 — all assets RCA-tagged\n", Console::FG_GREEN);
        } else {
            $this->stderr("  FAIL Gate 3 — asset RCA gaps:\n", Console::FG_RED);
            foreach ($g3 as $f) {
                $this->stderr("       - {$f}\n", Console::FG_RED);
            }
            $failures = array_merge($failures, $g3);
        }

        if ($failures !== []) {
            $this->stderr(
                sprintf("\nFAIL: %d gate failure(s).\n", count($failures)),
                Console::FG_RED,
            );
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("\nAll three rehearsal gates passed.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    // -----------------------------------------------------------------
    //  Gate parsers — public static so tests can call directly.
    //  PATTERNS Shared Patterns "static-helper-extraction" — these were
    //  intentionally extracted to avoid Reflection-on-private noise in
    //  tests. The mechanical-gate signal lives entirely in pure functions.
    // -----------------------------------------------------------------

    /**
     * Gate 1 — parse the [1/2] Count-match gate block from VERIFY.md.
     * Returns failure messages (empty array = pass).
     *
     * @return list<string>
     */
    public static function parseCountMatchBlock(string $verifyMd): array
    {
        // Expected heading shape (Phase 4 / Plan 04 VerifyController output):
        //   "[1/2] Count-match gate" or "## [1/2] Count-match gate" — match either.
        $lines = preg_split('/\R/', $verifyMd) ?: [];
        $inBlock = false;
        $sawBlock = false;
        $failures = [];
        foreach ($lines as $line) {
            if (preg_match('/\[1\/2\][\s\-]*Count-match gate/i', $line)) {
                $inBlock = true;
                $sawBlock = true;
                continue;
            }
            if ($inBlock) {
                // Block ends at the next markdown heading or `[2/2]` marker.
                $trimmedForEnd = ltrim($line);
                if (preg_match('/^(#{1,6}\s|\[2\/2\])/', $trimmedForEnd)) {
                    break;
                }
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                // Match status prefix: OK / SKIP / WARN / FAIL.
                if (preg_match('/^(WARN|FAIL)\b\s*(.*)$/', $trimmed, $m)) {
                    $failures[] = "Count gate {$m[1]}: {$m[2]}";
                }
                // OK / SKIP / non-status lines: ignore (pass).
            }
        }
        if (!$sawBlock) {
            $failures[] = 'Count gate section not found in VERIFY.md';
        }
        return $failures;
    }

    /**
     * Gate 2 — find unresolved CKEditor token literals in REPORT.md.
     * Patterns: [NT<id>], [M<id>], asset:<n>. Allow-listed tokens excluded.
     *
     * @param list<string> $allowedTokens
     * @return list<string>  Distinct unresolved tokens, sorted.
     */
    public static function findUnresolvedCkeditorTokens(string $reportMd, array $allowedTokens = []): array
    {
        $found = [];
        // [NT123], [M456]
        if (preg_match_all('/\[(?:NT|M)\d+\]/', $reportMd, $m1)) {
            $found = array_merge($found, $m1[0]);
        }
        // asset:789 (followed by non-digit boundary)
        if (preg_match_all('/\basset:\d+\b/', $reportMd, $m2)) {
            $found = array_merge($found, $m2[0]);
        }
        $found = array_values(array_unique($found));
        sort($found);
        $allowed = array_flip($allowedTokens);
        return array_values(array_filter($found, static fn(string $t) => !isset($allowed[$t])));
    }

    /**
     * Gate 3 — parse `## Asset RCA` table from REPORT.md; return failure
     * messages for rows with empty reason cell. Empty section (placeholder
     * copy from Phase 4.1 / CFG-07) passes.
     *
     * @return list<string>
     */
    public static function parseAssetRcaTable(string $reportMd): array
    {
        $lines = preg_split('/\R/', $reportMd) ?: [];
        $failures = [];
        $inSection = false;
        $sawSection = false;
        $headerSeen = false;
        $separatorSeen = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*##\s+Asset RCA\b/', $line)) {
                $inSection = true;
                $sawSection = true;
                continue;
            }
            if ($inSection) {
                $trimmed = trim($line);
                // Section ends at next ## heading.
                if (preg_match('/^##\s+/', $trimmed)) {
                    break;
                }
                if ($trimmed === '' || str_starts_with($trimmed, '_')) {
                    // Empty line or italicized placeholder copy (CFG-07): not a row.
                    continue;
                }
                if (!str_starts_with($trimmed, '|')) {
                    continue;
                }
                if (!$headerSeen) {
                    $headerSeen = true;  // The | Asset | Reason | ... | header line.
                    continue;
                }
                if (!$separatorSeen) {
                    $separatorSeen = true;  // The |---|---|...| separator.
                    continue;
                }
                // Data row: split on |, trim each cell.
                $cells = array_map('trim', array_slice(explode('|', $trimmed), 1, -1));
                if (count($cells) < 2) {
                    continue;
                }
                $assetCell = $cells[0];
                $reasonCell = $cells[1] ?? '';
                if ($reasonCell === '') {
                    $failures[] = "Asset RCA row missing reason: {$assetCell}";
                }
            }
        }
        if (!$sawSection) {
            $failures[] = 'Asset RCA section not found in REPORT.md';
        }
        return $failures;
    }

    /**
     * Parse <dir>/allow-tokens.txt. One token per line; lines starting with `#` are comments.
     *
     * @return list<string>
     */
    public static function parseAllowTokens(string $contents): array
    {
        $out = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $out[] = $trimmed;
        }
        return $out;
    }
}
