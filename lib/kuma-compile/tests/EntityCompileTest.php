<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\Compiler;
use Lameco\KumaCompile\Compile\Transforms;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\Mapping;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityCompileTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs, nl: comNlNl, sp: ~ }
          LV:
            database: lv
            locales: { lv: comLvLv, en: comLvEn }
        entities:
          CaseCategory:
            table: case_categories
            section: caseCategories
            entryType: caseCategory
            title: name
            softDelete: deleted_at
            dedupe: true
            ignore: []
          BlogCategory:
            table: blog_categories
            section: blogCategories
            entryType: blogCategory
            title: name
            softDelete: deleted_at
            dedupe: false
            ignore: []
        YAML;

    private function db(string $environment): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE case_categories (id INTEGER, name TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE blog_categories (id INTEGER, name TEXT, deleted_at TEXT)');
        $pdo->exec("INSERT INTO case_categories VALUES (1, 'Health', NULL), (2, 'Charity', NULL), (3, 'Gone', '2020-01-01')");
        $pdo->exec("INSERT INTO blog_categories VALUES (17, " . ($environment === 'COM' ? "'Podcast'" : "'Produkte'") . ", NULL)");

        return new LegacyDatabase($pdo, $environment, strtolower($environment));
    }

    /** @return list<array<string, mixed>> */
    private function compile(string $environment): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($this->db($environment), $environment, static function(array $p) use (&$out): void {
                $out[] = $p;
            });

        return $out;
    }

    #[Test]
    public function an_entity_is_written_only_to_the_sites_the_running_environment_maps(): void
    {
        // The loader refuses a payload naming a site outside the environment's own map, and
        // it is right to: locale → site is per environment, so `comLvEn` during a COM run is
        // a handle the run has no map for. A shared entity still reaches every site — by
        // accumulation across the three runs, against the one uid.
        foreach ($this->compile('COM') as $payload) {
            self::assertSame(['comEnUs', 'comNlNl'], array_keys($payload['sites']));
        }

        foreach ($this->compile('LV') as $payload) {
            self::assertSame(['comLvLv', 'comLvEn'], array_keys($payload['sites']));
        }
    }

    #[Test]
    public function the_same_shared_row_gets_the_same_uid_from_either_environment(): void
    {
        $com = array_column($this->compile('COM'), 'sourceUid');
        $lv = array_column($this->compile('LV'), 'sourceUid');

        self::assertContains('kuma:shared:case_categories:1', $com);
        self::assertContains('kuma:shared:case_categories:1', $lv);

        // …while an entity that is not deduplicated stays apart, because id 17 is "Podcast"
        // in one database and something unrelated in the other.
        self::assertContains('kuma:COM:blog_categories:17', $com);
        self::assertContains('kuma:LV:blog_categories:17', $lv);
    }

    #[Test]
    public function a_soft_deleted_row_is_not_migrated(): void
    {
        self::assertNotContains('kuma:shared:case_categories:3', array_column($this->compile('COM'), 'sourceUid'));
    }
}
