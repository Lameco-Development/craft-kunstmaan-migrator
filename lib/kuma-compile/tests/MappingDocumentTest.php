<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Mapping\MappingDocument;
use Lameco\KumaCompile\Mapping\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * The mapping, edited and written back.
 *
 * The file stays the single source of truth — a mapping is reviewed in a pull
 * request, and an edit that did not appear in the diff would not be. So the
 * risk this class carries is not "does the edit apply" but "does saving lose
 * something nobody looked at".
 */
final class MappingDocumentTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: legacy
            mediaRoot: [$KUMA_MEDIA_ROOT_COM]
            locales:
              en: comEnUs
              sp: !unmapped "no Craft site exists for this locale"
        pages:
          ContentPage:
            live: 412
            table: content_pages
            section: pages
            entryType: contentPage
        parts:
          Text:
            live: 82
            table: text_parts
            block: ~
            map: {}
            unreviewed: [body, alignment]
        unmapped:
          parts:
            RowStart: "layout bracket, no Craft equivalent"
        YAML;

    private function document(): MappingDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        return MappingDocument::fromFile($path);
    }

    /**
     * The one that would have destroyed data. Mapping::fromFile resolves
     * `!unmapped "reason"` to null, because the compiler wants the absence of a
     * target rather than the reason for it. Saving that back would erase every
     * deliberate non-goal in the file, and the reason someone wrote down.
     */
    #[Test]
    public function a_deliberate_non_goal_survives_a_save(): void
    {
        $reloaded = Yaml::parse($this->document()->toYaml(), Yaml::PARSE_CUSTOM_TAGS);
        $sp = $reloaded['environments']['COM']['locales']['sp'];

        self::assertInstanceOf(TaggedValue::class, $sp);
        self::assertSame('unmapped', $sp->getTag());
        self::assertSame('no Craft site exists for this locale', $sp->getValue());
    }

    #[Test]
    public function a_save_changes_nothing_that_was_not_edited(): void
    {
        $document = $this->document();
        $before = $document->all();

        $reloaded = Yaml::parse($document->toYaml(), Yaml::PARSE_CUSTOM_TAGS);

        self::assertEquals($before, $reloaded);
    }

    /**
     * An editor shows a handful of a row's keys, and a row carries more than
     * that. Replacing the row with what a form posted would drop `live`,
     * `unreviewed` and `children` every time somebody set a block handle.
     */
    #[Test]
    public function patching_a_row_leaves_the_keys_it_did_not_name(): void
    {
        $row = $this->document()
            ->patch('parts', 'Text', ['block' => 'contentBlock'])
            ->row('parts', 'Text');

        self::assertSame('contentBlock', $row['block']);
        self::assertSame(82, $row['live']);
        self::assertSame(['body', 'alignment'], $row['unreviewed']);
    }

    #[Test]
    public function a_null_clears_a_key(): void
    {
        $row = $this->document()
            ->patch('parts', 'Text', ['unreviewed' => null])
            ->row('parts', 'Text');

        self::assertArrayNotHasKey('unreviewed', $row);
    }

    #[Test]
    public function patching_an_unknown_row_creates_it(): void
    {
        $row = $this->document()
            ->patch('parts', 'Quote', ['table' => 'quote_parts', 'block' => 'quoteBlock'])
            ->row('parts', 'Quote');

        self::assertSame(['table' => 'quote_parts', 'block' => 'quoteBlock'], $row);
    }

    /**
     * The file is read in a pull request. A diff that reorders the whole
     * document because a hash iterated differently is a diff nobody reviews.
     */
    #[Test]
    public function the_written_file_keeps_the_dsl_key_order(): void
    {
        $written = array_keys(Yaml::parse($this->document()->toYaml(), Yaml::PARSE_CUSTOM_TAGS));
        $expected = array_values(array_intersect(Schema::topLevelKeys(), $written));

        self::assertSame($expected, $written);
    }

    /** An edit is validated against the schema before anyone writes it. */
    #[Test]
    public function the_document_can_be_compiled_from(): void
    {
        $mapping = $this->document()->patch('parts', 'Text', ['block' => 'contentBlock'])->mapping();

        self::assertSame('contentBlock', $mapping->parts()['Text']['block']);
        self::assertNull($mapping->all()['environments']['COM']['locales']['sp']);
    }

    private const ANNOTATED = <<<'YAML'
        # Enreach — Kunstmaan → Craft migration mapping
        #
        # Counts are live placements, measured 2026-08-19.
        version: 1
        parts:
          # The workhorse: half the corpus is one of these.
          Text:
            live: 82
            table: text_parts
            block: contentBlock
            source: [A, S]
            ignore: [alignment, background_color]

          Header:
            live: 40
            table: header_parts
            block: ~
            unreviewed: [title, niv]
        YAML;

    private function annotated(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::ANNOTATED);

        return [MappingDocument::fromFile($path), $path];
    }

    /**
     * The reason to write to the file at all is that a mapping is reviewed in a
     * pull request. Dumping the whole document is correct and useless: on the
     * real Enreach mapping one added `ignore:` reason produced a 1,652-line
     * diff, because every comment went and every inline list became a block.
     * Nobody reviews that.
     */
    #[Test]
    public function editing_one_row_leaves_every_other_line_alone(): void
    {
        [$document, $path] = $this->annotated();
        $before = explode("\n", (string) file_get_contents($path));

        $document->patch('parts', 'Header', ['block' => 'headingBlock'])->save();

        $after = explode("\n", (string) file_get_contents($path));
        $changed = [];

        foreach ($before as $i => $line) {
            if (($after[$i] ?? null) !== $line) {
                $changed[] = $line;
            }
        }

        // Only the edited row's own lines move.
        foreach ($changed as $line) {
            self::assertStringNotContainsString('#', $line, 'a comment was rewritten');
            self::assertStringNotContainsString('Text:', $line, 'an untouched row was rewritten');
        }
    }

    #[Test]
    public function the_file_header_and_row_comments_survive(): void
    {
        [$document, $path] = $this->annotated();

        $document->patch('parts', 'Header', ['block' => 'headingBlock'])->save();
        $written = (string) file_get_contents($path);

        self::assertStringContainsString('# Enreach — Kunstmaan → Craft migration mapping', $written);
        self::assertStringContainsString('# The workhorse: half the corpus is one of these.', $written);
        self::assertStringContainsString('# Counts are live placements, measured 2026-08-19.', $written);
    }

    #[Test]
    public function the_edit_is_actually_written(): void
    {
        [$document, $path] = $this->annotated();

        $document->patch('parts', 'Header', ['block' => 'headingBlock'])->save();

        self::assertSame(
            'headingBlock',
            MappingDocument::fromFile($path)->row('parts', 'Header')['block'],
        );
    }

    /**
     * `source: [A, S]` is how every hand-written row in a mapping reads, and
     * the dumper expands it — four lines of noise in a diff whose point is one
     * decision.
     */
    #[Test]
    public function short_scalar_lists_stay_on_one_line(): void
    {
        [$document, $path] = $this->annotated();

        $document->patch('parts', 'Text', ['block' => 'richTextBlock'])->save();

        self::assertStringContainsString('source: [A, S]', (string) file_get_contents($path));
        self::assertStringContainsString('ignore: [alignment, background_color]', (string) file_get_contents($path));
    }

    /**
     * A row the writer cannot locate must not be guessed at. Falling back to a
     * full dump keeps the edit rather than losing it, and the size of the diff
     * says loudly that something unusual happened.
     */
    #[Test]
    public function a_row_that_is_not_in_the_file_yet_still_saves(): void
    {
        [$document, $path] = $this->annotated();

        $document->patch('parts', 'Quote', ['table' => 'quote_parts', 'block' => 'quoteBlock'])->save();

        self::assertSame(
            'quoteBlock',
            MappingDocument::fromFile($path)->row('parts', 'Quote')['block'],
        );
    }
}
