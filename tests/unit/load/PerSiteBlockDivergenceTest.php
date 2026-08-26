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

    /**
     * The blind spot this predicate was shipped with.
     *
     * Only page-builder blocks carry `_sourcePartRef`; a Matrix built by `link()`, `links()`
     * or the sidecar lane carries none — measured on the reference corpus, 0 of 102
     * `heroButtons` blocks against 3,298 of 3,316 page-builder blocks. Identifying a block
     * only by its ref made every one of those fields invisible here, so the one warning that
     * would have caught an English page serving a Danish button never fired.
     */
    public function testRefLessBlocksAreIdentifiedByTheirContent(): void
    {
        $english = ['heroButtons' => [['type' => 'button', 'fields' => [
            'commonLink' => ['value' => 'https://enreach.com/en/contact', 'label' => 'More information'],
        ]]]];
        $danish = ['heroButtons' => [['type' => 'button', 'fields' => [
            'commonLink' => ['value' => 'https://enreach.com/dk/kontakt', 'label' => 'Kontakt os'],
        ]]]];

        self::assertTrue(PerSiteBlockDivergence::isUnrepresentable([
            'comEnUs' => PerSiteBlockDivergence::identities($english)['heroButtons'],
            'comDkDa' => PerSiteBlockDivergence::identities($danish)['heroButtons'],
        ]));
    }

    /** The same button in both locales collapses into one shared set without complaint. */
    public function testRefLessBlocksWithEqualContentAreRepresentable(): void
    {
        $block = ['heroButtons' => [['type' => 'button', 'fields' => [
            'commonLink' => ['value' => 'https://enreach.com/en/contact', 'label' => 'More information'],
        ]]]];

        self::assertFalse(PerSiteBlockDivergence::isUnrepresentable([
            'comEnUs' => PerSiteBlockDivergence::identities($block)['heroButtons'],
            'comNlNl' => PerSiteBlockDivergence::identities($block)['heroButtons'],
        ]));
    }

    /**
     * Key order is a serialisation artefact, not a difference in content — hashing the raw
     * JSON would make two identical buttons look divergent and reintroduce the noise.
     */
    public function testRefLessIdentityIgnoresKeyOrder(): void
    {
        $a = ['heroButtons' => [['type' => 'button', 'fields' => [
            'commonLink' => ['value' => 'https://x/c', 'label' => 'Contact'],
        ]]]];
        $b = ['heroButtons' => [['fields' => [
            'commonLink' => ['label' => 'Contact', 'value' => 'https://x/c'],
        ], 'type' => 'button']]];

        self::assertSame(
            PerSiteBlockDivergence::identities($a)['heroButtons'],
            PerSiteBlockDivergence::identities($b)['heroButtons'],
        );
    }

    /**
     * A block that does carry a ref keeps identifying by it. Two locales holding the same
     * legacy part with translated content is the common case — 753 entries on the reference
     * corpus — and treating that as divergence would make the warning worthless.
     */
    public function testABlockWithARefStillIdentifiesByTheRef(): void
    {
        $english = ['pageBuilder' => [['type' => 'contentBlock', 'fields' => [
            '_sourcePartRef' => 'COM:text:1660', 'heading' => 'Hello',
        ]]]];
        $dutch = ['pageBuilder' => [['type' => 'contentBlock', 'fields' => [
            '_sourcePartRef' => 'COM:text:1660', 'heading' => 'Hallo',
        ]]]];

        self::assertFalse(PerSiteBlockDivergence::isUnrepresentable([
            'comEnUs' => PerSiteBlockDivergence::identities($english)['pageBuilder'],
            'comNlNl' => PerSiteBlockDivergence::identities($dutch)['pageBuilder'],
        ]));
    }

    /**
     * A nested Matrix rides inside its parent block's identity rather than appearing as one of
     * the field's own. The owner already differs whenever a child does, and hoisting a child's
     * ref to the top level would compare a column against a block.
     */
    public function testNestedBlocksBelongToTheirParentNotTheField(): void
    {
        $identities = PerSiteBlockDivergence::identities([
            'pageBuilder' => [
                ['type' => 'text', 'fields' => [
                    '_sourcePartRef' => 'Text:5',
                    'columns' => [['type' => 'column', 'fields' => ['_sourcePartRef' => 'Column:9']]],
                ]],
                ['type' => 'text', 'fields' => []],
            ],
            'body' => 'not a matrix payload',
        ]);

        self::assertSame(['pageBuilder'], array_keys($identities), 'a non-matrix value is not a field of blocks');
        self::assertCount(2, $identities['pageBuilder']);
        self::assertArrayHasKey('ref:Text:5', $identities['pageBuilder']);

        foreach (array_keys($identities['pageBuilder']) as $identity) {
            self::assertStringNotContainsString('Column:9', $identity);
        }
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
