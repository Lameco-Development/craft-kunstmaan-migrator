<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\ExplainContext;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which contexts a node streams and which fill its page is a per-page fact: a page's own
 * `contexts:` replaces `defaults.contexts`, and explain has to judge each node by its page's.
 */
final class ExplainContextTest extends TestCase
{
    private static function context(): ExplainContext
    {
        return ExplainContext::fromMapping('NL', Mapping::fromArray([
            'version' => 1,
            'environments' => ['NL' => ['database' => 'nl', 'locales' => ['nl' => 'berkvensNl', 'es' => null]]],
            'defaults' => ['contexts' => ['header' => ['target' => 'page'], 'main' => ['field' => 'pageBuilder']]],
            'pages' => [
                'TextPage' => ['table' => 'text_pages', 'entryType' => 'contentPage'],
                // Its own `contexts:` drops `header` and makes `banner` the page context.
                'HeroPage' => [
                    'table' => 'hero_pages',
                    'entryType' => 'contentPage',
                    'contexts' => ['banner' => ['target' => 'page'], 'main' => ['field' => 'pageBuilder']],
                ],
            ],
            'parts' => [
                'Header' => ['table' => 'header_page_parts', 'consumedBy' => 'page', 'map' => ['heroTitle' => 'title']],
                'Text' => ['table' => 'text_parts', 'block' => 'textBlock', 'map' => ['content' => 'content']],
            ],
        ]));
    }

    /** @return list<array{lang: string, context: string, part: string, entity: string, id: int, sequence: int}> */
    private static function header(string $context): array
    {
        return [[
            'lang' => 'nl',
            'context' => $context,
            'part' => 'Header',
            'entity' => 'App\\Entity\\PageParts\\HeaderPagePart',
            'id' => 3,
            'sequence' => 1,
        ]];
    }

    /** @param array<string, mixed> $result */
    private static function why(array $result): string
    {
        return (string) $result['accountedFor'][0]['why'];
    }

    #[Test]
    public function a_pages_own_page_context_is_reported_as_written(): void
    {
        $result = self::context()->reconcile(
            [],
            self::header('banner'),
            'HeroPage',
            ['nl' => ['banner' => ['part' => 'Header', 'id' => 3]]],
        );

        self::assertSame('written to the page\'s own fields from the `banner` page context, not as a block', self::why($result));
    }

    #[Test]
    public function a_default_page_context_the_page_drops_is_not(): void
    {
        $result = self::context()->reconcile([], self::header('header'), 'HeroPage', ['nl' => ['banner' => null]]);

        self::assertSame(
            'not written: `header` is not a `target: page` context, and a `consumedBy: page` part becomes no block',
            self::why($result),
        );
    }

    #[Test]
    public function a_page_with_no_contexts_of_its_own_takes_the_defaults(): void
    {
        $result = self::context()->reconcile(
            [],
            self::header('header'),
            'TextPage',
            ['nl' => ['header' => ['part' => 'Header', 'id' => 3]]],
        );

        self::assertSame('written to the page\'s own fields from the `header` page context, not as a block', self::why($result));
    }

    #[Test]
    public function a_block_part_in_a_pages_own_page_context_is_not_a_hole(): void
    {
        $text = self::header('banner');
        $text[0]['part'] = 'Text';

        $result = self::context()->reconcile([], $text, 'HeroPage', ['nl' => ['banner' => null]]);

        self::assertSame([], $result['unexplained']);
        self::assertSame('`banner` is not one of the contexts the mapping streams into blocks', self::why($result));
    }

    #[Test]
    public function the_lanes_tables_and_locales_come_from_the_mapping(): void
    {
        $context = self::context();

        self::assertSame(['Header' => 'page', 'Text' => 'blocks'], $context->lanes);
        self::assertSame(['Header' => 'header_page_parts', 'Text' => 'text_parts'], $context->tables);
        self::assertSame(['main'], $context->contexts);
        self::assertSame(['header'], $context->pageContexts);
        self::assertSame(['nl'], $context->locales);
    }
}
