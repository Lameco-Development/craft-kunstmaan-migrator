<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Report\Readiness;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\TargetCheck;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each context's `field:` is where that context's blocks land (#89).
 *
 * The field was read for the slot check and the allow-list check, and then every block from
 * every context was written to the first context's field — so a legacy `right_column` could not
 * reach a sidebar Matrix, and a catalogue's `footer_content` could not reach the Matrix below
 * its listing. Blocks are now grouped by their context's field, legacy order kept per field,
 * `prepend` working within the field it names; the form block stays on the main builder.
 *
 * Mirrors the Berkvens NL model: `content → pageBuilderBerkvensNl`, `right_column →
 * berkvensNlSidebar`, and on catalogue pages `footer_content → berkvensNlBelowListing`.
 */
final class PerContextFieldsTest extends TestCase
{
    private const HEAD = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { nl: berkvensNl }

        YAML;

    private const MULTI = <<<'YAML'
        defaults:
          contexts:
            top: { field: pageBuilderBerkvensNl, prepend: true }
            content: { field: pageBuilderBerkvensNl }
            right_column: { field: berkvensNlSidebar }
        pages:
          ContentPage:
            table: content_pages
            section: pages
            entryType: contentPage
          CataloguePage:
            table: catalogue_pages
            section: pages
            entryType: cataloguePage
            contexts:
              content: { field: pageBuilderBerkvensNl }
              footer_content: { field: berkvensNlBelowListing }

        YAML;

    private const SINGLE = <<<'YAML'
        defaults:
          contexts:
            top: { field: pageBuilderBerkvensNl, prepend: true }
            content: { field: pageBuilderBerkvensNl }
            right_column: { field: pageBuilderBerkvensNl }
        pages:
          ContentPage:
            table: content_pages
            section: pages
            entryType: contentPage
          CataloguePage:
            table: catalogue_pages
            section: pages
            entryType: cataloguePage

        YAML;

    private const PARTS = <<<'YAML'
        parts:
          Text:
            table: text_parts
            block: textBlock
            map: { content: content }
          Cta:
            table: cta_parts
            block: ctaBlock
            map: { label: label }

        YAML;

    private const FORMS = <<<'YAML'
        forms:
          context: form
          fields:
            SingleLineText:
              table: single_line_text_parts
              type: singleLineText
              map:
                label: label

        YAML;

    /** The single-field payload, captured from the compiler at 983d620 — before #89. */
    private const GOLDEN = <<<'JSON'
        [{"sourceUid":"kuma:COM:kuma_nodes:17","section":"pages","entryType":"contentPage","sites":{"berkvensNl":{"enabled":true,"title":"Over ons","slug":"over-ons","fieldValues":{"pageBuilderBerkvensNl":[{"type":"textBlock","fields":{"content":"hero","_sourcePartRef":"COM:text_parts:4"}},{"type":"textBlock","fields":{"content":"intro","_sourcePartRef":"COM:text_parts:1"}},{"type":"ctaBlock","fields":{"label":"Offerte","_sourcePartRef":"COM:cta_parts:1"}},{"type":"textBlock","fields":{"content":"openingstijden","_sourcePartRef":"COM:text_parts:2"}},{"type":"ctaBlock","fields":{"label":"Bel ons","_sourcePartRef":"COM:cta_parts:2"}},{"type":"textBlock","fields":{"content":"adres","_sourcePartRef":"COM:text_parts:3"}},{"type":"formBlock","fields":{"commonForm":[{"_form":"kuma:COM:form:ContentPage:100"}]}}]}}},"legacy":{"class":"App\\Entity\\Pages\\ContentPage","refIds":{"nl":100}}},{"sourceUid":"kuma:COM:kuma_nodes:18","section":"pages","entryType":"cataloguePage","sites":{"berkvensNl":{"enabled":true,"title":"Binnendeuren","slug":"binnendeuren","fieldValues":{"pageBuilderBerkvensNl":[{"type":"textBlock","fields":{"content":"catalogus intro","_sourcePartRef":"COM:text_parts:6"}},{"type":"textBlock","fields":{"content":"niet gestreamd","_sourcePartRef":"COM:text_parts:7"}}]}}},"legacy":{"class":"App\\Entity\\Pages\\CataloguePage","refIds":{"nl":200}}}]
        JSON;

    private function schema(): TargetSchema
    {
        return new class() implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types;

            public function __construct()
            {
                $builder = new Slot('pageBuilderBerkvensNl', 'Matrix', false, ['textBlock', 'ctaBlock', 'formBlock']);

                $this->types = [
                    'contentPage' => [
                        'pageBuilderBerkvensNl' => $builder,
                        // The sidebar takes text, not calls to action.
                        'berkvensNlSidebar' => new Slot('berkvensNlSidebar', 'Matrix', false, ['textBlock']),
                    ],
                    'cataloguePage' => [
                        'pageBuilderBerkvensNl' => $builder,
                        'berkvensNlBelowListing' => new Slot('berkvensNlBelowListing', 'Matrix', false, ['textBlock', 'ctaBlock']),
                    ],
                    'textBlock' => ['content' => new Slot('content', 'CKEditor', false)],
                    'ctaBlock' => ['label' => new Slot('label', 'PlainText', false)],
                    'formBlock' => ['commonForm' => new Slot('commonForm', 'Forms', true)],
                ];
            }

            public function hasEntryType(string $handle): bool
            {
                return isset($this->types[$handle]);
            }

            public function hasSection(string $handle): bool
            {
                return true;
            }

            public function sectionType(string $handle): ?string
            {
                return null;
            }

            public function sectionEntryTypes(string $handle): ?array
            {
                return null;
            }

            public function slots(string $entryType): array
            {
                return $this->types[$entryType] ?? [];
            }

            public function slot(string $entryType, string $field): ?Slot
            {
                return $this->slots($entryType)[$field] ?? null;
            }

            public function requiredFields(string $entryType): array
            {
                return [];
            }

            public function pathFor(string $entryType, string $field): ?string
            {
                return $this->slot($entryType, $field) !== null ? '' : null;
            }

            public function nestedTypeOf(string $entryType, string $field): ?string
            {
                $slot = $this->slot($entryType, $field);

                return $slot !== null && count($slot->nested) === 1 ? $slot->nested[0] : null;
            }
        };
    }

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (id INTEGER, pageId INTEGER, pageEntityname TEXT, context TEXT,
                     sequencenumber INTEGER, page_part_id INTEGER, page_part_entityname TEXT)');
        $pdo->exec('CREATE TABLE content_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE catalogue_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE text_parts (id INTEGER, content TEXT)');
        $pdo->exec('CREATE TABLE cta_parts (id INTEGER, label TEXT)');
        $pdo->exec('CREATE TABLE single_line_text_parts (id INTEGER, label TEXT)');

        $pdo->exec("INSERT INTO kuma_nodes VALUES
                    (17, NULL, 0, 1, 'App\\Entity\\Pages\\ContentPage'),
                    (18, NULL, 0, 3, 'App\\Entity\\Pages\\CataloguePage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (91, 'App\\Entity\\Pages\\ContentPage', 100),
                    (92, 'App\\Entity\\Pages\\CataloguePage', 200)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (4, 17, 'nl', 'Over ons', 'over-ons', NULL, NULL, 1, 91),
                    (5, 18, 'nl', 'Binnendeuren', 'binnendeuren', NULL, NULL, 1, 92)");
        $pdo->exec('INSERT INTO content_pages VALUES (100)');
        $pdo->exec('INSERT INTO catalogue_pages VALUES (200)');

        // Doubled backslashes: the suite's sqlite convention for LIKE-matched entity columns.
        $content = 'App\\\\Entity\\\\Pages\\\\ContentPage';
        $catalogue = 'App\\\\Entity\\\\Pages\\\\CataloguePage';
        $text = 'App\\\\Entity\\\\PageParts\\\\TextPagePart';
        $cta = 'App\\\\Entity\\\\PageParts\\\\CtaPagePart';
        $field = 'App\\\\Entity\\\\PageParts\\\\SingleLineTextPagePart';

        // Contexts interleaved on purpose: grouping must not depend on the order refs arrive in.
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, '$content', 'content', 1, 1, '$text'),
                    (2, 100, '$content', 'right_column', 1, 2, '$text'),
                    (3, 100, '$content', 'content', 2, 1, '$cta'),
                    (4, 100, '$content', 'right_column', 2, 2, '$cta'),
                    (5, 100, '$content', 'right_column', 3, 3, '$text'),
                    (6, 100, '$content', 'top', 1, 4, '$text'),
                    (7, 100, '$content', 'form', 1, 1, '$field'),
                    (8, 200, '$catalogue', 'footer_content', 1, 5, '$text'),
                    (9, 200, '$catalogue', 'content', 1, 6, '$text'),
                    (10, 200, '$catalogue', 'right_column', 1, 7, '$text')");
        $pdo->exec("INSERT INTO text_parts VALUES
                    (1, 'intro'), (2, 'openingstijden'), (3, 'adres'), (4, 'hero'),
                    (5, 'onder de lijst'), (6, 'catalogus intro'), (7, 'niet gestreamd')");
        $pdo->exec("INSERT INTO cta_parts VALUES (1, 'Offerte'), (2, 'Bel ons')");
        $pdo->exec("INSERT INTO single_line_text_parts VALUES (1, 'Naam')");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    private function mappingFile(string $yaml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    /** @return array{0: list<array<string, mixed>>, 1: Compiler} */
    private function compile(string $yaml): array
    {
        $out = [];
        $compiler = new Compiler(Mapping::fromFile($this->mappingFile($yaml)), new Transforms(), $this->schema());
        $compiler->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        return [$out, $compiler];
    }

    /** @return array<string, mixed> */
    private static function fieldsOf(array $entry): array
    {
        return $entry['sites']['berkvensNl']['fieldValues'];
    }

    /** @return array{type:string, fields:array<string,mixed>} */
    private static function text(int $id, string $content): array
    {
        return ['type' => 'textBlock', 'fields' => ['content' => $content, '_sourcePartRef' => 'COM:text_parts:' . $id]];
    }

    /** @return array{type:string, fields:array<string,mixed>} */
    private static function cta(int $id, string $label): array
    {
        return ['type' => 'ctaBlock', 'fields' => ['label' => $label, '_sourcePartRef' => 'COM:cta_parts:' . $id]];
    }

    /** @return array{type:string, fields:array<string,mixed>} */
    private static function form(int $pageId): array
    {
        return ['type' => 'formBlock', 'fields' => ['commonForm' => [['_form' => 'kuma:COM:form:ContentPage:' . $pageId]]]];
    }

    #[Test]
    public function each_context_lands_in_its_own_field_in_legacy_order(): void
    {
        [$entries] = $this->compile(self::HEAD . self::MULTI . self::PARTS);

        self::assertSame(
            [
                'pageBuilderBerkvensNl' => [self::text(4, 'hero'), self::text(1, 'intro'), self::cta(1, 'Offerte')],
                'berkvensNlSidebar' => [self::text(2, 'openingstijden'), self::text(3, 'adres')],
            ],
            self::fieldsOf($entries[0]),
        );
    }

    #[Test]
    public function a_block_the_sidebar_rejects_is_skipped_there_only(): void
    {
        [, $compiler] = $this->compile(self::HEAD . self::MULTI . self::PARTS);

        // The builder took its call to action; the sidebar's was the only one dropped.
        self::assertSame(1, $compiler->skipped()['ctaBlock not allowed on contentPage.berkvensNlSidebar'] ?? null);
        self::assertArrayNotHasKey('ctaBlock not allowed on contentPage.pageBuilderBerkvensNl', $compiler->skipped());
    }

    #[Test]
    public function a_catalogue_pages_own_contexts_fill_the_below_listing_matrix_separately(): void
    {
        // `footer_content` is not a default context: only the page's own `contexts:` streams it,
        // and the same override takes `right_column` away from this page.
        [$entries] = $this->compile(self::HEAD . self::MULTI . self::PARTS);

        self::assertSame(
            [
                'pageBuilderBerkvensNl' => [self::text(6, 'catalogus intro')],
                'berkvensNlBelowListing' => [self::text(5, 'onder de lijst')],
            ],
            self::fieldsOf($entries[1]),
        );
    }

    #[Test]
    public function the_form_block_closes_the_main_builder_not_the_sidebar(): void
    {
        [$entries] = $this->compile(self::HEAD . self::MULTI . self::PARTS . self::FORMS);
        $fields = self::fieldsOf($entries[0]);

        self::assertSame(self::form(100), $fields['pageBuilderBerkvensNl'][3]);
        self::assertCount(4, $fields['pageBuilderBerkvensNl']);
        self::assertSame([self::text(2, 'openingstijden'), self::text(3, 'adres')], $fields['berkvensNlSidebar']);
    }

    #[Test]
    public function an_explicit_forms_field_places_the_form_block_elsewhere(): void
    {
        $yaml = str_replace('  context: form', "  context: form\n  field: berkvensNlSidebar", self::FORMS);
        $schema = $this->schema();
        $out = [];

        // The sidebar must carry a form block to receive one; widen it for this case.
        $wide = new class($schema) implements TargetSchema {
            public function __construct(private readonly TargetSchema $inner)
            {
            }

            public function hasEntryType(string $handle): bool
            {
                return $this->inner->hasEntryType($handle);
            }

            public function hasSection(string $handle): bool
            {
                return true;
            }

            public function sectionType(string $handle): ?string
            {
                return null;
            }

            public function sectionEntryTypes(string $handle): ?array
            {
                return null;
            }

            public function slots(string $entryType): array
            {
                $slots = $this->inner->slots($entryType);

                if ($entryType === 'contentPage') {
                    $slots['berkvensNlSidebar'] = new Slot('berkvensNlSidebar', 'Matrix', false, ['textBlock', 'formBlock']);
                }

                return $slots;
            }

            public function slot(string $entryType, string $field): ?Slot
            {
                return $this->slots($entryType)[$field] ?? null;
            }

            public function requiredFields(string $entryType): array
            {
                return [];
            }

            public function pathFor(string $entryType, string $field): ?string
            {
                return $this->inner->pathFor($entryType, $field);
            }

            public function nestedTypeOf(string $entryType, string $field): ?string
            {
                return $this->inner->nestedTypeOf($entryType, $field);
            }
        };

        $compiler = new Compiler(Mapping::fromFile($this->mappingFile(self::HEAD . self::MULTI . self::PARTS . $yaml)), new Transforms(), $wide);
        $compiler->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
            $out[] = $p;
        });
        $fields = self::fieldsOf($out[0]);

        self::assertSame([self::text(4, 'hero'), self::text(1, 'intro'), self::cta(1, 'Offerte')], $fields['pageBuilderBerkvensNl']);
        self::assertSame([self::text(2, 'openingstijden'), self::text(3, 'adres'), self::form(100)], $fields['berkvensNlSidebar']);
    }

    #[Test]
    public function a_single_field_mapping_compiles_exactly_as_before(): void
    {
        // Every context on one field: the payload captured from the compiler before #89, verbatim.
        // `top` prepended, then `content`, then `right_column` — context order, not ref order —
        // and the form block at the foot.
        [$entries] = $this->compile(self::HEAD . self::SINGLE . self::PARTS . self::FORMS);

        self::assertSame(self::GOLDEN, json_encode($entries, JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function live_placements_are_read_per_context_for_the_placement_report(): void
    {
        // `BlockPlacement` judges each context by its own field, so the corpus read it is fed
        // has to keep the context apart. Single backslashes here: this query joins on equality,
        // as MySQL holds the names, not through LIKE.
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, deleted INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations (node_id INTEGER, lang TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (pageId INTEGER, pageEntityname TEXT, context TEXT, page_part_entityname TEXT)');
        $pdo->exec('INSERT INTO kuma_nodes VALUES (17, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (91, 'App\\Entity\\Pages\\ContentPage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (17, 'nl', 1, 91), (17, 'fr', 1, 91)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (100, 'App\\Entity\\Pages\\ContentPage', 'content', 'App\\Entity\\PageParts\\TextPagePart'),
                    (100, 'App\\Entity\\Pages\\ContentPage', 'right_column', 'App\\Entity\\PageParts\\TextPagePart'),
                    (100, 'App\\Entity\\Pages\\ContentPage', 'right_column', 'App\\Entity\\PageParts\\CtaPagePart')");

        // Two live translations of one page: each placement counts once per live translation.
        self::assertSame(
            ['ContentPage' => [
                'content' => ['App\\Entity\\PageParts\\TextPagePart' => 2],
                'right_column' => ['App\\Entity\\PageParts\\CtaPagePart' => 2, 'App\\Entity\\PageParts\\TextPagePart' => 2],
            ]],
            $this->sorted((new LegacyDatabase($pdo, 'COM', 'com'))->livePlacementsByPageType()),
        );
    }

    /**
     * @param array<string, array<string, array<string, int>>> $pairs
     * @return array<string, array<string, array<string, int>>>
     */
    private function sorted(array $pairs): array
    {
        foreach ($pairs as &$contexts) {
            ksort($contexts);

            foreach ($contexts as &$parts) {
                ksort($parts);
            }
        }

        return $pairs;
    }

    #[Test]
    public function the_batched_unit_path_writes_the_same_fields(): void
    {
        [$entries] = $this->compile(self::HEAD . self::MULTI . self::PARTS . self::FORMS);

        $compiler = new Compiler(
            Mapping::fromFile($this->mappingFile(self::HEAD . self::MULTI . self::PARTS . self::FORMS)),
            new Transforms(),
            $this->schema(),
        );
        $run = $compiler->begin($this->db(), 'COM');
        $batched = [];
        $emit = static function(array $p) use (&$batched): void {
            $batched[] = $p;
        };

        foreach ([17, 18] as $nodeId) {
            $compiler->compileNodeUnit($run, $nodeId, $emit);
        }

        self::assertSame($entries, $batched);
    }

    private static function formsField(string $field): string
    {
        return str_replace('  context: form', "  context: form\n  field: " . $field, self::FORMS);
    }

    private function mapping(string $yaml): Mapping
    {
        return Mapping::fromFile($this->mappingFile($yaml));
    }

    #[Test]
    public function a_forms_field_no_page_type_has_fails_validate(): void
    {
        // A typo here passed validate and dropped every form block at compile, one skip per page.
        $mapping = $this->mapping(self::HEAD . self::MULTI . self::PARTS . self::formsField('berkvensNlSidbar'));

        self::assertSame(
            ['forms.field: no page entry type has a Matrix `berkvensNlSidbar` — every form block would be dropped'],
            (new TargetCheck($this->schema()))->check($mapping),
        );
    }

    #[Test]
    public function a_forms_field_some_page_types_lack_is_a_warning_for_those_types(): void
    {
        // `forms.field` is lane-wide; a page type without it may simply never hold a form.
        $mapping = $this->mapping(self::HEAD . self::MULTI . self::PARTS . self::formsField('berkvensNlSidebar'));
        $check = new TargetCheck($this->schema());

        self::assertSame([], $check->check($mapping));
        $warning = 'page `CataloguePage` lands its form block in `berkvensNlSidebar`, which `cataloguePage`'
            . ' does not have — a form on these pages is dropped';

        self::assertSame([$warning], $check->pagesWithNoBlockField($mapping));
    }

    #[Test]
    public function readiness_credits_the_forms_field_to_the_forms_lane(): void
    {
        // Every context on the builder: the sidebar is filled by the form block alone.
        $mapping = $this->mapping(self::HEAD . self::SINGLE . self::PARTS . self::formsField('berkvensNlSidebar'));
        $suppliers = [];

        foreach ((new Readiness($mapping, $this->schema()))->all() as $r) {
            if ($r->subject === 'ContentPage') {
                $suppliers[$r->field] = $r->supplier;
            }
        }

        self::assertSame('forms', $suppliers['berkvensNlSidebar'] ?? null);
        self::assertSame('blocks', $suppliers['pageBuilderBerkvensNl'] ?? null);
    }

    /** MULTI with ContentPage's own `map:` also writing the sidebar. */
    private static function mapsTheSidebar(): string
    {
        return str_replace(
            "    entryType: contentPage\n",
            "    entryType: contentPage\n    map: { berkvensNlSidebar: id }\n",
            self::MULTI,
        );
    }

    #[Test]
    public function blocks_replacing_a_mapped_value_on_the_same_field_are_reported(): void
    {
        // The block stream has always won the builder; now any context field — or `forms.field` —
        // can collide with what `map:`, `children:`, a sidecar or a page part put there. The stream still wins,
        // but the value it replaces is counted, not lost in silence.
        [$entries, $compiler] = $this->compile(self::HEAD . self::mapsTheSidebar() . self::PARTS);

        self::assertSame([self::text(2, 'openingstijden'), self::text(3, 'adres')], self::fieldsOf($entries[0])['berkvensNlSidebar']);
        self::assertSame(1, $compiler->skipped()['contentPage.berkvensNlSidebar: blocks replace a value from map/children/sidecars/page parts'] ?? null);
    }

    #[Test]
    public function a_page_mapping_a_field_its_blocks_land_in_fails_validate(): void
    {
        $mapping = $this->mapping(self::HEAD . self::mapsTheSidebar() . self::PARTS);

        self::assertSame(
            ['page `ContentPage`: `berkvensNlSidebar` is both mapped and a block field — the blocks replace the mapped value'],
            (new TargetCheck($this->schema()))->check($mapping),
        );
    }
}
