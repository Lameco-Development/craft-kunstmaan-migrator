<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\console;

use lameco\kunstmaanmigrator\console\DoctorController;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.1 / Plan 04.1-05 / Task 4 — characterization for the D-30 doctor 8th
 * check escalation: baseline.json's captured filterScope vs current run's
 * --entities / --locales / --since.
 *
 * Three states (D-30 contract):
 *   - no-scope: captured baseline has no filterScope (pre-Phase-4.1 or null
 *               capture) — silent in caller (no WARN row).
 *   - matches:  captured == current — silent in caller (preserves OK behavior).
 *   - mismatch: any divergence on entities (set-equality), locales (set-equality),
 *               or since (strict ===) — caller emits WARN with verbatim D-30 copy.
 *
 * Pure-helper extraction enables direct unit tests without a Craft bootstrap.
 * Mirrors LocalePreflight::compareEnvDefaultLocaleToLocaleMap from Plan 04.1-03.
 */
final class DoctorControllerVerifyBaselineFilterScopeTest extends TestCase
{
    public function testNoScopeCapturedReturnsNoScope(): void
    {
        $r = DoctorController::compareBaselineFilterScope(
            null,
            ['entities' => ['blogPosts'], 'locales' => [], 'since' => null],
        );
        self::assertSame('no-scope', $r['status']);
    }

    public function testNoScopeStillReturnsNoScopeEvenWhenCurrentIsAlsoNull(): void
    {
        $r = DoctorController::compareBaselineFilterScope(null, null);
        self::assertSame('no-scope', $r['status']);
    }

    public function testScopeMatchesReturnsMatches(): void
    {
        $captured = ['entities' => ['blogPosts'], 'locales' => [], 'since' => null];
        $current  = ['entities' => ['blogPosts'], 'locales' => [], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('matches', $r['status']);
    }

    public function testEmptyArraysOnBothSidesReturnsMatches(): void
    {
        // The "all" sentinel: both sides captured with default filters.
        $captured = ['entities' => [], 'locales' => [], 'since' => null];
        $current  = ['entities' => [], 'locales' => [], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('matches', $r['status']);
    }

    public function testEntitiesMismatchReturnsMismatch(): void
    {
        $captured = ['entities' => ['blogPosts'], 'locales' => [], 'since' => null];
        $current  = ['entities' => ['events'],    'locales' => [], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('mismatch', $r['status']);
        self::assertStringContainsString('blogPosts', $r['capturedSummary']);
        self::assertStringContainsString('events',    $r['currentSummary']);
    }

    public function testLocalesMismatchReturnsMismatch(): void
    {
        $captured = ['entities' => [], 'locales' => ['nl'], 'since' => null];
        $current  = ['entities' => [], 'locales' => ['fr'], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('mismatch', $r['status']);
    }

    public function testSinceMismatchReturnsMismatch(): void
    {
        $captured = ['entities' => [], 'locales' => [], 'since' => '2026-01-01'];
        $current  = ['entities' => [], 'locales' => [], 'since' => '2026-02-01'];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('mismatch', $r['status']);
    }

    public function testSinceNullVsSetIsAMismatch(): void
    {
        $captured = ['entities' => [], 'locales' => [], 'since' => '2026-01-01'];
        $current  = ['entities' => [], 'locales' => [], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('mismatch', $r['status']);
    }

    public function testEntityOrderingIsIgnoredViaSetEquality(): void
    {
        // D-30: entities use set-equality; ['blogPosts','events'] ≡ ['events','blogPosts'].
        $captured = ['entities' => ['blogPosts', 'events'], 'locales' => [], 'since' => null];
        $current  = ['entities' => ['events', 'blogPosts'], 'locales' => [], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('matches', $r['status'], 'Set-equality on entities should ignore order');
    }

    public function testLocaleOrderingIsIgnoredViaSetEquality(): void
    {
        $captured = ['entities' => [], 'locales' => ['nl', 'fr'], 'since' => null];
        $current  = ['entities' => [], 'locales' => ['fr', 'nl'], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('matches', $r['status']);
    }

    public function testMismatchSummaryFormatsForVerbatimD30Copy(): void
    {
        $captured = ['entities' => ['blogPosts'], 'locales' => ['nl'], 'since' => '2026-01-01'];
        $current  = ['entities' => ['events'],    'locales' => [],     'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);

        // D-30 uses an em-dash (U+2014), not a hyphen.
        $line = sprintf(
            'filter-scope mismatch — baseline was captured with `%s`, current run is `%s`. Re-run capture-baseline or re-run verify with matching filters.',
            $r['capturedSummary'],
            $r['currentSummary'],
        );

        self::assertStringContainsString('filter-scope mismatch — baseline was captured with `entities=blogPosts;', $line);
        self::assertStringContainsString('current run is `entities=events;', $line);
        self::assertStringContainsString('Re-run capture-baseline or re-run verify with matching filters.', $line);
    }

    public function testEmptyEntitiesRendersAsAllInSummary(): void
    {
        $captured = ['entities' => [], 'locales' => [], 'since' => null];
        $current  = ['entities' => ['blogPosts'], 'locales' => [], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('mismatch', $r['status']);
        self::assertStringContainsString('entities=all', $r['capturedSummary']);
        self::assertStringContainsString('entities=blogPosts', $r['currentSummary']);
    }

    public function testNullSinceRendersAsNoneInSummary(): void
    {
        $captured = ['entities' => ['blogPosts'], 'locales' => [], 'since' => null];
        $current  = ['entities' => ['blogPosts'], 'locales' => [], 'since' => '2026-01-01'];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('mismatch', $r['status']);
        self::assertStringContainsString('since=none',       $r['capturedSummary']);
        self::assertStringContainsString('since=2026-01-01', $r['currentSummary']);
    }

    public function testMalformedCapturedFiltersDoNotThrow(): void
    {
        // T-04.1-05-08 mitigation: defensively coerce missing keys to empty
        // arrays / null. A captured array with non-string entities falls
        // through to set-equality on whatever could be normalised.
        $captured = ['entities' => [123, null, 'blogPosts'], 'locales' => [], 'since' => null];
        $current  = ['entities' => ['blogPosts'], 'locales' => [], 'since' => null];
        $r = DoctorController::compareBaselineFilterScope($captured, $current);
        self::assertSame('matches', $r['status'], 'Non-string entries are dropped during normalisation.');
    }
}
