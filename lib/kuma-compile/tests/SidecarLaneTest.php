<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\Compiler;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Target\Slot;
use Lameco\KumaCompile\Target\TargetSchema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sidecars are per-page entities keyed by Kunstmaan's polymorphic `(ref_entity_name, ref_id)`
 * pair — the header tab, structured data — living outside both the page's table and the
 * pagepart tree. The lane keys on that column signature, not on a table name, so a corpus
 * that calls its tab something else maps the same way.
 *
 * The per-locale test is the one that matters: like `kuma_seo`, every translation points at
 * its own page-entity clone with its own sidecar row, and resolving through a shared id would
 * leak one locale's hero into every other site.
 */
final class SidecarLaneTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs, nl: comNlNl }
        pages:
          CasePage:
            table: case_pages
            section: pages
            entryType: casePage
            map:
              summary: summary
        sidecars:
          headerTab:
            table: header_tabs
            map:
              heroTitle:   title | inlineHtml
              heroImage:   image_path
              heroButtons: "links(link(link_url, link_text, link_new_window), link(secondary_link_url, secondary_link_text, secondary_link_new_window))"
            ignore: []
        YAML;

    /** The same sidecar aiming at a target the page map already fills. */
    private const MAPPING_COLLISION = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs }
        pages:
          CasePage:
            table: case_pages
            section: pages
            entryType: casePage
            map:
              heroTitle: summary
        sidecars:
          headerTab:
            table: header_tabs
            map:
              heroTitle: title | inlineHtml
            ignore: []
        YAML;

    private function schema(bool $withImage = true): TargetSchema
    {
        return new class($withImage) implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types;

            public function __construct(bool $withImage)
            {
                $this->types = [
                    'casePage' => array_filter([
                        'summary' => new Slot('summary', 'PlainText', false),
                        'heroTitle' => new Slot('heroTitle', 'CKEditor', false),
                        'heroImage' => $withImage ? new Slot('heroImage', 'Assets', false) : null,
                        'heroButtons' => new Slot('heroButtons', 'Matrix', false, ['button']),
                    ]),
                    'button' => [
                        'commonLink' => new Slot('commonLink', 'Link', false),
                    ],
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
        $pdo->exec('CREATE TABLE case_pages (id INTEGER, summary TEXT)');
        $pdo->exec('CREATE TABLE header_tabs
                    (id INTEGER, ref_id INTEGER, ref_entity_name TEXT, title TEXT, image_path TEXT,
                     link_url TEXT, link_text TEXT, link_new_window INTEGER,
                     secondary_link_url TEXT, secondary_link_text TEXT, secondary_link_new_window INTEGER)');

        // One node, two locales, each translation on its own page-entity clone (600 EN, 601 NL)
        // — the exact shape that makes a shared-id read leak content across sites.
        $pdo->exec("INSERT INTO kuma_nodes VALUES (17, NULL, 0, 1, 'App\\Entity\\Pages\\CasePage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (91, 'App\\Entity\\Pages\\CasePage', 600),
                    (92, 'App\\Entity\\Pages\\CasePage', 601)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (4, 17, 'en', 'Voiceworks',    'voiceworks',    NULL, NULL, 1, 91),
                    (5, 17, 'nl', 'Voiceworks NL', 'voiceworks-nl', NULL, NULL, 1, 92)");
        $pdo->exec("INSERT INTO case_pages VALUES (600, 'EN summary'), (601, 'NL summary')");

        // The NL row has no links at all; the EN row has two of two. Doubled backslashes,
        // matching the suite's sqlite convention for LIKE-matched entity columns (the `%\\`
        // pattern escapes to one backslash on MySQL and stays two literal ones on sqlite).
        $pdo->exec("INSERT INTO header_tabs VALUES
                    (1, 600, 'App\\\\Entity\\\\Pages\\\\CasePage', '<p>EN hero</p>', '/uploads/hero-en.jpg',
                     'https://example.com/a', 'Primary', 0, 'https://example.com/b', 'Secondary', 1),
                    (2, 601, 'App\\\\Entity\\\\Pages\\\\CasePage', 'NL hero', NULL,
                     '', NULL, 0, '', NULL, 0)");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return list<array<string, mixed>> */
    private function compile(string $mapping, ?TargetSchema $schema): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $mapping);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms(), $schema))
            ->compile($this->db(), 'COM', static function (array $p) use (&$out): void {
                $out[] = $p;
            });

        return $out;
    }

    #[Test]
    public function each_locale_gets_its_own_sidecar_row(): void
    {
        [$entry] = $this->compile(self::MAPPING, $this->schema());

        self::assertSame('EN hero', $entry['sites']['comEnUs']['fieldValues']['heroTitle']);
        self::assertSame('NL hero', $entry['sites']['comNlNl']['fieldValues']['heroTitle']);
        self::assertSame('EN summary', $entry['sites']['comEnUs']['fieldValues']['summary']);
    }

    #[Test]
    public function grouped_link_columns_become_one_button_each(): void
    {
        [$entry] = $this->compile(self::MAPPING, $this->schema());

        self::assertSame(
            [
                ['type' => 'button', 'fields' => ['commonLink' => ['value' => 'https://example.com/a', 'label' => 'Primary']]],
                ['type' => 'button', 'fields' => ['commonLink' => ['value' => 'https://example.com/b', 'label' => 'Secondary', 'target' => '_blank']]],
            ],
            $entry['sites']['comEnUs']['fieldValues']['heroButtons'],
        );

        // The NL row's link columns are empty: no buttons field at all, not an empty Matrix.
        self::assertArrayNotHasKey('heroButtons', $entry['sites']['comNlNl']['fieldValues']);
    }

    #[Test]
    public function a_field_the_entry_type_does_not_carry_is_dropped_not_emitted(): void
    {
        // `heroImage` exists on eight of the corpus's entry types, not all. Emitting it
        // anyway would make Craft reject the whole entry.
        [$entry] = $this->compile(self::MAPPING, $this->schema(withImage: false));

        self::assertArrayNotHasKey('heroImage', $entry['sites']['comEnUs']['fieldValues']);
        self::assertSame('EN hero', $entry['sites']['comEnUs']['fieldValues']['heroTitle']);
    }

    #[Test]
    public function the_page_map_wins_a_target_collision(): void
    {
        // A sidecar decorates; it does not override what the page's own map says.
        [$entry] = $this->compile(self::MAPPING_COLLISION, $this->schema());

        self::assertSame('EN summary', $entry['sites']['comEnUs']['fieldValues']['heroTitle']);
    }
}
