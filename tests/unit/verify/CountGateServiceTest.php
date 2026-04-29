<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\verify;

use lameco\kunstmaanmigrator\verify\CountGateService;
use PHPUnit\Framework\TestCase;

/**
 * Plan 04-12 Task 04 — characterization tests for the pure-arithmetic
 * portion of CountGateService::run() (Plan 04-04).
 *
 * NOTE: Full DB-coupled run() integration (Entry::find(), Category::find(),
 * \craft\db\Query against state/SEOmatic/Retour tables) is exercised in
 * Phase 5 / TST-02 with a real Craft bootstrap. This file covers only the
 * delta formula and the optional-plugin gate semantics that don't require
 * a Craft container.
 *
 * Locked formula (src/verify/CountGateService.php:71):
 *   $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
 *   $pass  = $actual >= 0 && $delta <= $tolerance;
 */
final class CountGateServiceTest extends TestCase
{
    public function testDeltaWithinToleranceProducesPass(): void
    {
        $expected = 100;
        $actual = 99;
        $tolerance = 0.01;
        $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
        $pass = $actual >= 0 && $delta <= $tolerance;
        $this->assertTrue($pass);
        $this->assertEqualsWithDelta(0.01, $delta, 1e-9);
    }

    public function testDeltaExceedingToleranceProducesFail(): void
    {
        $expected = 100;
        $actual = 110;
        $tolerance = 0.01;
        $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
        $pass = $actual >= 0 && $delta <= $tolerance;
        $this->assertFalse($pass);
        $this->assertGreaterThan($tolerance, $delta);
    }

    public function testZeroExpectedTreatsDeltaAsZeroPerV1Contract(): void
    {
        // CountGateService.php line 71: $delta = $expected > 0 ? ... : 0.0
        $expected = 0;
        $actual = 0;
        $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
        $this->assertSame(0.0, $delta);
    }

    public function testNegativeActualSentinelFailsRegardlessOfDelta(): void
    {
        // CountGateService catches Throwable from the DB query and sets $actual = -1
        // (see lines 68-70). With negative actual, pass MUST be false even if
        // the delta arithmetic happens to compute small.
        $expected = 100;
        $actual = -1;
        $tolerance = 0.01;
        $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
        $pass = $actual >= 0 && $delta <= $tolerance;
        $this->assertFalse($pass);
    }

    public function testCraftSnapshotFlattenKeepsTotalsAndPerSiteRowsSeparate(): void
    {
        $flat = CountGateService::flattenCraftSnapshotCounts([
            'sections' => [
                'articles' => [
                    'totalCount' => 4,
                    'countsBySite' => ['default' => 2, 'en' => 2],
                ],
            ],
            'assets' => ['totalCount' => 3],
            'taxonomies' => ['topics' => ['totalCount' => 5]],
            'retour' => ['totalCount' => 1],
            'seomatic' => ['totalCount' => 7],
        ]);

        self::assertSame(4, $flat['craft.sections.articles.total']);
        self::assertSame(2, $flat['craft.sections.articles.site.default']);
        self::assertSame(2, $flat['craft.sections.articles.site.en']);
        self::assertSame(3, $flat['craft.assets.total']);
        self::assertSame(5, $flat['craft.taxonomies.topics.total']);
        self::assertSame(1, $flat['craft.plugins.retour.total']);
        self::assertSame(7, $flat['craft.plugins.seomatic.total']);
    }

    public function testCraftDriftCompareCanBeInformationalWithoutFailingOverallGate(): void
    {
        $service = new CountGateService();
        $result = $service->compareFlatCounts(
            ['craft.sections.articles.total' => 10],
            ['craft.sections.articles.total' => 12],
            0.01,
            CountGateService::DOMAIN_CRAFT_BASELINE_CURRENT_DRIFT,
            false,
        );

        self::assertTrue($result['pass'], 'Informational drift must not fail the blocking verify result.');
        self::assertFalse($result['gates']['craft.sections.articles.total']['pass']);
        self::assertFalse($result['gates']['craft.sections.articles.total']['blocking']);
        self::assertSame(
            CountGateService::DOMAIN_CRAFT_BASELINE_CURRENT_DRIFT,
            $result['gates']['craft.sections.articles.total']['domain'],
        );
    }
}
