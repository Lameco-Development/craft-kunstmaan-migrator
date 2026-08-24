<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\EntityIndex;
use Lameco\KumaCompile\Payload\SourceUid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The idempotency key of the whole migration, pinned at its single owner.
 * Every constructor here replaces an sprintf that used to live at a call
 * site; parse() is the same grammar RefResolver delegates to.
 */
final class SourceUidTest extends TestCase
{
    #[Test]
    public function every_constructor_mints_the_grammar_its_call_site_used_to(): void
    {
        self::assertSame('kuma:COM:case_categories:3', SourceUid::forRow('COM', 'case_categories', 3));
        self::assertSame('kuma:shared:case_categories:3', SourceUid::forRow(SourceUid::SHARED, 'case_categories', 3));
        self::assertSame('kuma:COM:kuma_nodes:912', SourceUid::forNode('COM', 912));
        self::assertSame('kuma:COM:form:ContactPage:7', SourceUid::forForm('COM', 'ContactPage', 7));
        self::assertSame('kuma:COM:global:footer-1:FooterLinkPagePart:9', SourceUid::forGlobalPart('COM', 'footer-1', 'FooterLinkPagePart', 9));
        self::assertSame('kuma:COM:global:footer_links:4', SourceUid::forGlobalChild('COM', 'footer_links', 4));
    }

    #[Test]
    public function entity_uids_round_trip_through_parse_and_from_state_row(): void
    {
        $uid = SourceUid::forRow('COM', 'blog_categories', 17);
        $parsed = SourceUid::parse($uid);

        self::assertSame(['source' => 'COM:blog_categories', 'key' => '17'], $parsed);
        self::assertSame($uid, SourceUid::fromStateRow($parsed['source'], $parsed['key']));

        self::assertSame(
            ['source' => 'shared:case_categories', 'key' => '3'],
            SourceUid::parse(SourceUid::forRow(SourceUid::SHARED, 'case_categories', 3)),
        );
    }

    #[Test]
    public function form_uids_are_recognised_and_deliberately_outside_the_entity_grammar(): void
    {
        $form = SourceUid::forForm('COM', 'ContactPage', 7);

        self::assertTrue(SourceUid::isForm($form));
        // A form's state row is keyed (source "form", key = whole uid), so the
        // entity parse must NOT claim it.
        self::assertNull(SourceUid::parse($form));
        self::assertFalse(SourceUid::isForm(SourceUid::forRow('COM', 'formations', 7)));
    }

    #[Test]
    public function global_uids_are_outside_the_entity_grammar_by_design(): void
    {
        // Globals resolve through their own service, never through the state
        // table's (source, key) pair — parse() refusing them is load-bearing.
        self::assertNull(SourceUid::parse(SourceUid::forGlobalPart('COM', 'footer-1', 'FooterLinkPagePart', 9)));
        self::assertNull(SourceUid::parse(SourceUid::forGlobalChild('COM', 'Footer_links', 4)));
    }

    #[Test]
    public function malformed_uids_are_rejected_not_partially_matched(): void
    {
        self::assertNull(SourceUid::parse('kuma:COM:case_categories:3' . "\n"));
        self::assertNull(SourceUid::parse('kuma:COM:CaseCategories:3'));
        self::assertNull(SourceUid::parse('kuma:COM:case_categories'));
        self::assertNull(SourceUid::parse('craft:COM:case_categories:3'));
        self::assertNull(SourceUid::parse(''));
    }

    #[Test]
    public function entity_index_shares_the_one_shared_token(): void
    {
        self::assertSame(SourceUid::SHARED, EntityIndex::SHARED);
        self::assertSame(SourceUid::forRow('COM', 'blog_categories', 17), EntityIndex::uid('COM', 'blog_categories', 17));
    }
}
