<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\PerSiteBlockDivergence;
use PHPUnit\Framework\TestCase;

/**
 * The predicate behind the per-locale block warning.
 *
 * It has to stay quiet on the 753 entries whose locales carry the same parts, or the warning
 * is noise and gets ignored — which is the failure mode it exists to end.
 */
final class PerSiteBlockDivergenceTest extends TestCase
{
    public function testOneSiteIsNeverDivergent(): void
    {
        self::assertFalse(PerSiteBlockDivergence::isUnrepresentable(['comEnUs' => ['a' => true]]));
    }

    public function testIdenticalRefSetsCollapseHarmlessly(): void
    {
        self::assertFalse(PerSiteBlockDivergence::isUnrepresentable([
            'comEnUs' => ['a' => true, 'b' => true],
            'comNlNl' => ['a' => true, 'b' => true],
        ]));
    }

    /** Order is an artefact of how the payload was walked, not a difference in content. */
    public function testOrderDoesNotCountAsDivergence(): void
    {
        self::assertFalse(PerSiteBlockDivergence::isUnrepresentable([
            'comEnUs' => ['b' => true, 'a' => true],
            'comNlNl' => ['a' => true, 'b' => true],
        ]));
    }

    public function testDisjointRefSetsAreUnrepresentable(): void
    {
        self::assertTrue(PerSiteBlockDivergence::isUnrepresentable([
            'comLvLv' => ['LV:text:1660' => true],
            'comLvEn' => ['LV:text:1687' => true],
        ]));
    }

    /** Partial overlap still loses whichever parts are not in the surviving set. */
    public function testPartialOverlapIsUnrepresentable(): void
    {
        self::assertTrue(PerSiteBlockDivergence::isUnrepresentable([
            'comEnUs' => ['a' => true, 'b' => true],
            'comNlNl' => ['a' => true],
        ]));
    }

    public function testAThirdDivergentLocaleIsCaught(): void
    {
        self::assertTrue(PerSiteBlockDivergence::isUnrepresentable([
            'comEnUs' => ['a' => true],
            'comNlNl' => ['a' => true],
            'comFrFr' => ['z' => true],
        ]));
    }
}
