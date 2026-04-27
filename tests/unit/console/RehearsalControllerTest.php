<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\RehearsalController;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / TST-04 / D-21..D-22 — direct unit tests for the three rehearsal
 * gate parsers. The parsers are public static helpers on RehearsalController;
 * the tests call them directly without Reflection or Craft bootstrap.
 *
 * The actionCheck() integration path (read directory + run all three gates
 * + emit cyan/green/red console output) is exercised once end-to-end by the
 * v1.0 ship gate (Plan 05-08 RELEASE-CHECKLIST step 5: CQM rehearsal-check
 * exits 0). The unit-tier here covers the deterministic parsing surface.
 */
final class RehearsalControllerTest extends TestCase
{
    // -----------------------------------------------------------------
    // Gate 1 — parseCountMatchBlock
    // -----------------------------------------------------------------

    public function testParseCountMatchBlockAcceptsAllOk(): void
    {
        $verify = <<<MD
        # Verify report
        Some prose.

        ## [1/2] Count-match gate
        OK PageNode 142 (delta -1 within tolerance 5)
        OK NewsPage 36 (delta 0 within tolerance 5)
        SKIP ContactPage (no baseline)

        ## [2/2] URL diff gate
        ...
        MD;

        self::assertSame([], RehearsalController::parseCountMatchBlock($verify));
    }

    public function testParseCountMatchBlockAcceptsSkip(): void
    {
        $verify = <<<MD
        ## [1/2] Count-match gate
        SKIP ContactPage (no baseline)
        OK PageNode 142 (delta -1 within tolerance 5)
        MD;

        self::assertSame([], RehearsalController::parseCountMatchBlock($verify));
    }

    public function testParseCountMatchBlockFailsOnWarn(): void
    {
        $verify = <<<MD
        ## [1/2] Count-match gate
        WARN PageNode 142 (delta -7 outside tolerance 5)
        OK NewsPage 36 (delta 0 within tolerance 5)
        MD;

        $failures = RehearsalController::parseCountMatchBlock($verify);
        self::assertCount(1, $failures);
        self::assertStringContainsString('PageNode 142', $failures[0]);
        self::assertStringContainsString('outside tolerance', $failures[0]);
        self::assertStringStartsWith('Count gate WARN', $failures[0]);
    }

    public function testParseCountMatchBlockFailsOnFail(): void
    {
        $verify = <<<MD
        ## [1/2] Count-match gate
        OK PageNode 142 (delta 0 within tolerance 5)
        FAIL EventPage 7 (catastrophic discrepancy)
        MD;

        $failures = RehearsalController::parseCountMatchBlock($verify);
        self::assertCount(1, $failures);
        self::assertStringContainsString('EventPage 7', $failures[0]);
        self::assertStringStartsWith('Count gate FAIL', $failures[0]);
    }

    public function testParseCountMatchBlockMissingSection(): void
    {
        $verify = <<<MD
        # Verify report

        ## [2/2] URL diff gate
        OK /home
        MD;

        $failures = RehearsalController::parseCountMatchBlock($verify);
        self::assertSame(['Count gate section not found in VERIFY.md'], $failures);
    }

    // -----------------------------------------------------------------
    // Gate 2 — findUnresolvedCkeditorTokens
    // -----------------------------------------------------------------

    public function testFindUnresolvedCkeditorTokensZero(): void
    {
        $report = <<<MD
        # Migrate report
        ## Skipped stages
        (none)
        ## Asset RCA
        _No asset RCA entries — every referenced asset migrated successfully._
        MD;

        self::assertSame([], RehearsalController::findUnresolvedCkeditorTokens($report));
    }

    public function testFindUnresolvedCkeditorTokensFindsNt(): void
    {
        $report = "Some prose [NT123] more prose.";

        self::assertSame(['[NT123]'], RehearsalController::findUnresolvedCkeditorTokens($report));
    }

    public function testFindUnresolvedCkeditorTokensFindsM(): void
    {
        $report = "Block reference [M456] left dangling.";

        self::assertSame(['[M456]'], RehearsalController::findUnresolvedCkeditorTokens($report));
    }

    public function testFindUnresolvedCkeditorTokensFindsAsset(): void
    {
        $report = "Deferred asset:789 not resolved.";

        self::assertSame(['asset:789'], RehearsalController::findUnresolvedCkeditorTokens($report));
    }

    public function testFindUnresolvedCkeditorTokensRespectsAllowList(): void
    {
        $report = "[NT123] still referenced because target dropped";

        self::assertSame([], RehearsalController::findUnresolvedCkeditorTokens($report, ['[NT123]']));
    }

    public function testFindUnresolvedCkeditorTokensIgnoresCommentLinesInAllowList(): void
    {
        $allowText = "# this is a comment\n[NT123]\n# trailing comment\n";
        $allowed = RehearsalController::parseAllowTokens($allowText);
        self::assertSame(['[NT123]'], $allowed);

        $report = "[NT123] dropped on purpose.";
        self::assertSame([], RehearsalController::findUnresolvedCkeditorTokens($report, $allowed));
    }

    public function testFindUnresolvedCkeditorTokensReturnsDistinctSorted(): void
    {
        $report = "[M2] then [NT1] then asset:3 then [NT1] again";

        self::assertSame(
            ['[M2]', '[NT1]', 'asset:3'],
            RehearsalController::findUnresolvedCkeditorTokens($report),
        );
    }

    // -----------------------------------------------------------------
    // Gate 3 — parseAssetRcaTable
    // -----------------------------------------------------------------

    public function testParseAssetRcaTableAllRowsHaveReason(): void
    {
        $report = <<<MD
        # Migrate report

        ## Asset RCA
        | Asset | Reason | Count |
        |-------|--------|-------|
        | asset:42 | source-not-found | 1 |
        | asset:43 | mime-rejected | 2 |

        ## Other section
        ...
        MD;

        self::assertSame([], RehearsalController::parseAssetRcaTable($report));
    }

    public function testParseAssetRcaTableEmptySectionPasses(): void
    {
        $report = <<<MD
        # Migrate report

        ## Asset RCA
        _No asset RCA entries — every referenced asset migrated successfully._

        ## Skipped stages
        (none)
        MD;

        self::assertSame([], RehearsalController::parseAssetRcaTable($report));
    }

    public function testParseAssetRcaTableEmptyReasonFails(): void
    {
        $report = <<<MD
        ## Asset RCA
        | Asset | Reason | Count |
        |-------|--------|-------|
        | asset:42 |  | 1 |
        MD;

        $failures = RehearsalController::parseAssetRcaTable($report);
        self::assertSame(['Asset RCA row missing reason: asset:42'], $failures);
    }

    public function testParseAssetRcaTableMissingSection(): void
    {
        $report = <<<MD
        # Migrate report

        ## Skipped stages
        (none)
        MD;

        $failures = RehearsalController::parseAssetRcaTable($report);
        self::assertSame(['Asset RCA section not found in REPORT.md'], $failures);
    }

    // -----------------------------------------------------------------
    // parseAllowTokens
    // -----------------------------------------------------------------

    public function testParseAllowTokensIgnoresEmptyAndCommentLines(): void
    {
        $contents = "# header comment\n\n[NT1]\n   \n[M2]\n# trailing\n";

        self::assertSame(['[NT1]', '[M2]'], RehearsalController::parseAllowTokens($contents));
    }
}
