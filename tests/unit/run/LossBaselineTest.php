<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\run\LossBaseline;
use PHPUnit\Framework\TestCase;

/**
 * `acceptedLosses:` against what a run actually lost.
 *
 * Every real corpus loses something on purpose — Berkvens FR drops 19 soft-deleted
 * media and one PDF nobody has. Without a baseline `--fail-on-loss` can never pass
 * on such a corpus, so nobody passes it, and a new loss goes unnoticed. With one,
 * the gate fails on exactly what nobody accepted.
 */
final class LossBaselineTest extends TestCase
{
    /** @return array<string, array<string, int>> as the run report's `losses` */
    private function losses(): array
    {
        return [
            'asset' => ['media:889 -> unresolved' => 1, 'media:892 -> unresolved' => 2],
            'heroColorScheme' => ['chartreuse -> white' => 3],
        ];
    }

    /** @return list<array<string, mixed>> as the fixup pass reports them */
    private function orphans(): array
    {
        return [
            ['sourceUid' => 'kuma:FR:model:12', 'field' => 'utilityCategoryPages', 'ref' => 'kuma:FR:kuma_nodes:491', 'path' => []],
            ['sourceUid' => 'kuma:FR:model:13', 'field' => 'utilityCategoryPages', 'ref' => 'kuma:FR:kuma_nodes:494', 'path' => []],
        ];
    }

    public function testWithoutABaselineEveryLossIsUnaccepted(): void
    {
        $assessment = LossBaseline::fromSpec(null)->assess($this->losses(), ['/uploads/a.pdf'], $this->orphans());

        self::assertSame(6, $assessment->lossyConversions);
        self::assertSame(1, $assessment->unresolvedAssets);
        self::assertSame(2, $assessment->unresolvedReferences);
        self::assertTrue($assessment->lost());
        self::assertSame([], $assessment->stale);
    }

    public function testAnAcceptedKeySubtractsEveryOccurrenceOfIt(): void
    {
        $assessment = LossBaseline::fromSpec([
            'lossyConversions' => ['asset' => ['media:889 -> unresolved', 'media:892 -> unresolved']],
        ])->assess($this->losses(), [], []);

        self::assertSame(3, $assessment->lossyConversions, 'only the unaccepted heroColorScheme fallbacks are left');
        self::assertSame(['heroColorScheme' => ['chartreuse -> white' => 3]], $assessment->report()['unaccepted']['losses']);
        self::assertSame(3, $assessment->report()['accepted']['lossyConversions']);
    }

    public function testARunThatLostOnlyWhatTheMappingAcceptsHasLostNothing(): void
    {
        $assessment = LossBaseline::fromSpec([
            'lossyConversions' => [
                'asset' => ['media:889 -> unresolved', 'media:892 -> unresolved'],
                'heroColorScheme' => ['chartreuse -> white'],
            ],
            'unresolvedAssets' => ['/uploads/a.pdf'],
            'unresolvedReferences' => [
                'kuma:FR:model:12: utilityCategoryPages -> kuma:FR:kuma_nodes:491',
                'kuma:FR:model:13: utilityCategoryPages -> kuma:FR:kuma_nodes:494',
            ],
        ])->assess($this->losses(), ['/uploads/a.pdf', '/uploads/a.pdf'], $this->orphans());

        self::assertFalse($assessment->lost());
        self::assertSame(
            ['lossyConversions' => 6, 'unresolvedAssets' => 2, 'unresolvedReferences' => 2],
            array_intersect_key($assessment->report()['accepted'], array_flip(['lossyConversions', 'unresolvedAssets', 'unresolvedReferences'])),
        );
    }

    public function testAnUnacceptedAssetPathOrReferenceStillCounts(): void
    {
        $assessment = LossBaseline::fromSpec([
            'unresolvedAssets' => ['/uploads/a.pdf'],
            'unresolvedReferences' => ['kuma:FR:model:12: utilityCategoryPages -> kuma:FR:kuma_nodes:491'],
        ])->assess([], ['/uploads/a.pdf', '/uploads/b.pdf'], $this->orphans());

        self::assertSame(1, $assessment->unresolvedAssets);
        self::assertSame(1, $assessment->unresolvedReferences);
        self::assertSame(['/uploads/b.pdf'], $assessment->report()['unaccepted']['unresolvedAssetPaths']);
        self::assertSame(
            ['kuma:FR:model:13: utilityCategoryPages -> kuma:FR:kuma_nodes:494'],
            $assessment->report()['unaccepted']['unresolvedReferenceKeys'],
        );
        self::assertTrue($assessment->lost());
    }

    /**
     * A baseline entry the run no longer loses is a fixed loss the mapping still
     * excuses — left there, it would excuse the same loss coming back unnoticed.
     * Said, but not a failure: the run lost less than it was allowed to.
     */
    public function testAnAcceptedLossThatNoLongerOccursIsReportedStaleWithoutFailing(): void
    {
        $assessment = LossBaseline::fromSpec([
            'lossyConversions' => ['asset' => ['media:1 -> unresolved'], 'fileCategory' => ['overig -> other']],
            'unresolvedAssets' => ['/uploads/gone.pdf'],
            'unresolvedReferences' => ['kuma:FR:model:1: x -> y'],
        ])->assess([], [], []);

        self::assertFalse($assessment->lost());
        self::assertSame([
            'lossyConversions' => ['asset' => ['media:1 -> unresolved'], 'fileCategory' => ['overig -> other']],
            'unresolvedAssets' => ['/uploads/gone.pdf'],
            'unresolvedReferences' => ['kuma:FR:model:1: x -> y'],
        ], $assessment->stale);
        self::assertSame($assessment->stale, $assessment->report()['stale']);
    }

    /**
     * The fixup pass walks the whole state table, which Berkvens NL, Berkvens FR and
     * Xidoor share. An FR run would otherwise gate on — and have to accept — NL's and
     * Xidoor's orphans, and could never pass on their unresolvable references.
     */
    public function testOnlyTheRunsOwnEnvironmentsReferencesCount(): void
    {
        $orphans = [
            ...$this->orphans(),
            ['sourceUid' => 'kuma:NL:model:12', 'field' => 'utilityCategoryPages', 'ref' => 'kuma:NL:kuma_nodes:491', 'path' => []],
            ['sourceUid' => 'kuma:XI:kuma_nodes:38', 'field' => 'link', 'ref' => 'kuma:XI:kuma_nodes:1', 'path' => []],
        ];
        $unresolvable = ['kuma:FR:model:12' => 2, 'kuma:NL:model:7' => 5, 'kuma:FRX:model:1' => 3];

        $assessment = LossBaseline::fromSpec(null, ['FR'])->assess([], [], $orphans, $unresolvable);

        self::assertSame(2, $assessment->unresolvedReferences, 'the two FR orphans, not NL\'s or Xidoor\'s');
        self::assertSame(2, $assessment->unresolvable, 'FR\'s own, not NL\'s nor an environment that merely starts with FR');
        self::assertTrue($assessment->lost());
    }

    public function testAnUnresolvableReferenceIsALossNothingCanAccept(): void
    {
        $assessment = LossBaseline::fromSpec(null)->assess([], [], [], ['kuma:FR:model:12' => 1]);

        self::assertSame(1, $assessment->unresolvable);
        self::assertTrue($assessment->lost());
    }
}
