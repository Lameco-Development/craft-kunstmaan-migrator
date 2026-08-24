<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\MappingException;
use Lameco\Kunstmaanmigrator\Mapping\MappingInit;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The one `init` engine both surfaces delegate to.
 *
 * `./craft kunstmaan-migrator/mapping/init` and `kuma-compile init` must
 * agree on the pair grammar, the entity ladder and the overwrite refusal —
 * they used to each own a copy, and the copies drifted (the CLI grew
 * introspection support the Craft command never got).
 */
final class MappingInitTest extends TestCase
{
    public function testParsePairsSplitsOnTheFirstEquals(): void
    {
        self::assertSame(
            ['COM' => 'enreach_website', 'DE' => 'enreach_de'],
            MappingInit::parsePairs(['COM=enreach_website', 'DE=enreach_de']),
        );
    }

    public function testParsePairsRefusesAPairWithoutAnEquals(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('expected NAME=database, got `enreach_website`');

        MappingInit::parsePairs(['enreach_website']);
    }

    public function testSkeletonWithNeitherArtifactNorSourceLeavesTablesUnresolved(): void
    {
        $result = MappingInit::skeleton(['COM' => $this->db()]);

        self::assertTrue($result->tablesUnresolved);
        self::assertStringContainsString('environments:', $result->yaml);
        self::assertStringContainsString('database: legacy', $result->yaml);
        self::assertStringContainsString('FooterBox:', $result->yaml);
        self::assertStringContainsString('# TODO: source table', $result->yaml);
    }

    public function testSkeletonPrefersTheIntrospectionArtifactAndResolvesTables(): void
    {
        $artifact = tempnam(sys_get_temp_dir(), 'kuma') . '.json';
        file_put_contents($artifact, json_encode([
            'mode' => 'booted',
            'entities' => [
                'App\Entity\PageParts\FooterBoxPagePart' => [
                    'table' => 'footer_box_parts',
                    'columns' => ['title' => ['column' => 'title'], 'link' => ['column' => 'link']],
                ],
            ],
        ]));

        try {
            $result = MappingInit::skeleton(['COM' => $this->db()], null, $artifact);
        } finally {
            unlink($artifact);
        }

        self::assertFalse($result->tablesUnresolved);
        self::assertStringContainsString("table: footer_box_parts\n", $result->yaml);
    }

    public function testWriteRefusesToOverwriteAnExistingMapping(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, 'version: 1');

        try {
            $this->expectException(MappingException::class);
            $this->expectExceptionMessage('refusing to overwrite a mapping');

            MappingInit::write($path, 'anything');
        } finally {
            unlink($path);
        }
    }

    public function testWriteCreatesTheDirectoryItWritesInto(): void
    {
        $dir = sys_get_temp_dir() . '/kuma-init-' . bin2hex(random_bytes(4));
        $path = $dir . '/mapping.yaml';

        try {
            MappingInit::write($path, "version: 1\n");

            self::assertSame("version: 1\n", file_get_contents($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, deleted INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (pageEntityname TEXT, pageId INTEGER, context TEXT, page_part_entityname TEXT,
                     page_part_id INTEGER, sequencenumber INTEGER)');
        $pdo->exec('CREATE TABLE footer_box_parts (id INTEGER, title TEXT, link TEXT)');

        $pdo->exec('INSERT INTO kuma_nodes VALUES (1, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (11, 'App\\\\Entity\\\\Pages\\\\HomePage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (21, 1, 'en', 'Home', 1, 11)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    ('App\\\\Entity\\\\Pages\\\\HomePage', 100, 'main', 'App\\\\Entity\\\\PageParts\\\\FooterBoxPagePart', 1, 1)");
        $pdo->exec("INSERT INTO footer_box_parts VALUES (1, 'Products', '/products')");

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }
}
