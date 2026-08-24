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
 * The forms lane compiles a page's `form` context into a Formie form — and stopped there.
 * On the first full Enreach run, 70 forms existed and no page referenced any of them: the
 * form-owning pages compiled their `main` context and nothing said "and the form goes here".
 *
 * Now a page whose form context holds at least one mappable field gets a form block at the
 * foot of its builder, carrying `{"_form": <form sourceUid>}` — the loader resolves it
 * against the form lane's state row, deferring like a `_ref` when the form is not there yet.
 */
final class FormBlockLaneTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs }
        defaults:
          contexts:
            main: { field: pageBuilder }
        pages:
          PotionsLandingPage:
            table: landing_pages
            section: pages
            entryType: contentPage
        forms:
          context: form
          fields:
            SingleLineText:
              table: single_line_text_parts
              type: singleLineText
              map:
                label: label
        YAML;

    private function schema(bool $withFormsSlot = true): TargetSchema
    {
        return new class($withFormsSlot) implements TargetSchema {
            /** @var array<string, array<string, Slot>> */
            private array $types;

            public function __construct(bool $withFormsSlot)
            {
                $this->types = [
                    'contentPage' => [
                        'pageBuilder' => new Slot('pageBuilder', 'Matrix', false, ['contentBlock', 'formBlock']),
                    ],
                    'contentBlock' => [
                        'content' => new Slot('content', 'CKEditor', false),
                    ],
                    'formBlock' => array_filter([
                        'heading' => new Slot('heading', 'CKEditor', false),
                        'commonForm' => $withFormsSlot ? new Slot('commonForm', 'Forms', true) : null,
                    ]),
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
        $pdo->exec('CREATE TABLE landing_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE single_line_text_parts (id INTEGER, label TEXT)');

        // Node 17 owns a form-context field; node 18 has no form context at all.
        $pdo->exec("INSERT INTO kuma_nodes VALUES
                    (17, NULL, 0, 1, 'App\\Entity\\Pages\\PotionsLandingPage'),
                    (18, NULL, 0, 3, 'App\\Entity\\Pages\\PotionsLandingPage')");
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (91, 'App\\Entity\\Pages\\PotionsLandingPage', 100),
                    (92, 'App\\Entity\\Pages\\PotionsLandingPage', 200)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (4, 17, 'en', 'Contact', 'contact', NULL, NULL, 1, 91),
                    (5, 18, 'en', 'Plain',   'plain',   NULL, NULL, 1, 92)");
        $pdo->exec('INSERT INTO landing_pages VALUES (100), (200)');
        // Doubled backslashes: the suite's sqlite convention for LIKE-matched entity columns.
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    (1, 100, 'App\\\\Entity\\\\Pages\\\\PotionsLandingPage', 'form', 1, 1,
                     'App\\\\Entity\\\\PageParts\\\\SingleLineTextPagePart')");
        $pdo->exec("INSERT INTO single_line_text_parts VALUES (1, 'First name')");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return array{0: list<array<string, mixed>>, 1: Compiler} */
    private function compile(?TargetSchema $schema): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $out = [];

        $compiler = new Compiler(Mapping::fromFile($path), new Transforms(), $schema);
        $compiler->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        return [$out, $compiler];
    }

    #[Test]
    public function a_form_owning_page_gets_a_form_block_referencing_its_form(): void
    {
        [$entries] = $this->compile($this->schema());
        $contact = $entries[0];

        self::assertSame(
            [[
                'type' => 'formBlock',
                'fields' => ['commonForm' => [['_form' => 'kuma:COM:form:PotionsLandingPage:100']]],
            ]],
            $contact['sites']['comEnUs']['fieldValues']['pageBuilder'],
        );
    }

    #[Test]
    public function a_page_without_form_context_parts_gets_no_form_block(): void
    {
        [$entries] = $this->compile($this->schema());
        $plain = $entries[1];

        self::assertArrayNotHasKey('fieldValues', $plain['sites']['comEnUs']);
    }

    #[Test]
    public function no_block_with_a_forms_field_means_a_counted_skip_not_a_broken_page(): void
    {
        [$entries, $compiler] = $this->compile($this->schema(withFormsSlot: false));

        self::assertArrayNotHasKey('fieldValues', $entries[0]['sites']['comEnUs']);
        self::assertArrayHasKey('form on contentPage: no allowed block carries a Forms field', $compiler->skipped());
    }
}
