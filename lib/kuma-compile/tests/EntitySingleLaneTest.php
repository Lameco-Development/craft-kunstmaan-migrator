<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\Compiler;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `single: true` entity lane: a one-row config table (Kunstmaan
 * `AbstractConfig`) with no `kuma_node` row merges into the section's
 * existing entry. No title travels with it — the entry it merges into
 * already has one, set by an earlier contributor.
 */
final class EntitySingleLaneTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs, nl: comNlNl }
        entities:
          Configuration:
            table: configuration
            section: globalSettings
            entryType: globalSettings
            single: true
            dedupe: false
            map:
              phoneNumber: phone
            children:
              socialLinks:
                table: configuration_social_links
                fk: configuration_id
                map:
                  linkUrl: url
                ignore: []
            ignore: [address]
        YAML;

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE configuration (id INTEGER, phone TEXT, address TEXT)');
        $pdo->exec('CREATE TABLE configuration_social_links (id INTEGER, configuration_id INTEGER, url TEXT, weight INTEGER)');
        $pdo->exec("INSERT INTO configuration VALUES (1, '013-1234567', 'Tilburg')");
        $pdo->exec("INSERT INTO configuration_social_links VALUES (10, 1, 'https://x.example', 2), (11, 1, 'https://a.example', 1)");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return list<array<string, mixed>> */
    private function compile(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        return $out;
    }

    #[Test]
    public function a_single_row_compiles_one_payload_keyed_on_its_primary_key(): void
    {
        $payloads = $this->compile();

        self::assertCount(1, $payloads);
        self::assertSame('kuma:COM:configuration:1', $payloads[0]['sourceUid']);
        self::assertTrue($payloads[0]['single']);
        self::assertSame('globalSettings', $payloads[0]['section']);
    }

    #[Test]
    public function no_title_key_travels_so_the_existing_entry_title_survives(): void
    {
        $payload = $this->compile()[0];

        self::assertSame(['comEnUs', 'comNlNl'], array_keys($payload['sites']));
        foreach ($payload['sites'] as $handle => $site) {
            // Absent key, not null and not '' — an empty string would clear the
            // title the earlier contributor set on the merged entry.
            self::assertArrayNotHasKey('title', $site, $handle);
            self::assertTrue($site['enabled']);
            self::assertSame('013-1234567', $site['fieldValues']['phoneNumber']);
        }
    }

    #[Test]
    public function child_rows_nest_into_the_named_matrix_field_with_source_refs(): void
    {
        $payload = $this->compile()[0];
        $blocks = $payload['sites']['comEnUs']['fieldValues']['socialLinks'];

        // Ordered by weight; every nested block carries its origin so a re-run
        // updates in place instead of rebuilding.
        self::assertSame('https://a.example', $blocks[0]['fields']['linkUrl']);
        self::assertSame('https://x.example', $blocks[1]['fields']['linkUrl']);
        foreach ($blocks as $block) {
            self::assertArrayHasKey('_sourcePartRef', $block['fields']);
        }
    }

    #[Test]
    public function the_schema_accepts_single_without_a_title_and_rejects_a_non_bool(): void
    {
        self::assertSame([], $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            entities:
              Configuration:
                table: configuration
                section: globalSettings
                entryType: globalSettings
                single: true
                dedupe: false
                ignore: []
            YAML));

        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            entities:
              Configuration:
                table: configuration
                section: globalSettings
                entryType: globalSettings
                single: yes please
                dedupe: false
                ignore: []
            YAML);
        self::assertNotSame([], array_filter($errors, static fn(string $e): bool => str_contains($e, '`single:`')));

        // Without single:, title stays required.
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            entities:
              Configuration:
                table: configuration
                section: globalSettings
                entryType: globalSettings
                dedupe: false
                ignore: []
            YAML);
        self::assertNotSame([], array_filter($errors, static fn(string $e): bool => str_contains($e, 'missing `title:`')));
    }

    #[Test]
    public function a_child_missing_its_foreign_key_is_a_mapping_error(): void
    {
        $errors = $this->validate(<<<'YAML'
            version: 1
            environments:
              COM: { database: com, locales: { en: comEnUs } }
            entities:
              Configuration:
                table: configuration
                section: globalSettings
                entryType: globalSettings
                single: true
                dedupe: false
                ignore: []
                children:
                  socialLinks:
                    table: configuration_social_links
            YAML);

        self::assertNotSame([], array_filter($errors, static fn(string $e): bool => str_contains($e, 'missing `fk:`')));
    }

    /** @return list<string> */
    private function validate(string $yaml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return (new Schema())->validate(Mapping::fromFile($path));
    }
}
