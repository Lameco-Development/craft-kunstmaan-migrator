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
}
