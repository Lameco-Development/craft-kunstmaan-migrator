<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An entity row's `slug:` target is the entry's native slug, not a field.
 *
 * A catalogue keeps its legacy URLs only if the slug survives: Craft otherwise slugifies the
 * title, and three FR models whose titles repeat others' (`insert-metallique-1`) would take a
 * different URL — and their products' URLs with them.
 */
final class EntitySlugTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          FR: { database: fr, locales: { nl: berkvensFr } }
          BE: { database: be, locales: { nl: berkvensFr } }
        entities:
          Model:
            table: model
            section: models
            entryType: modelPage
            title: name
            dedupe: false
            map: { slug: slug, subname: sub_name }
        YAML;

    private function db(string $environment = 'FR', ?string $rows = null): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE model (id INTEGER, name TEXT, slug TEXT, sub_name TEXT)');
        $pdo->exec($rows !== null ? 'INSERT INTO model VALUES ' . $rows : ($environment === 'FR'
            ? "INSERT INTO model VALUES
                (59, 'Insert métallique', 'insert-metallique-1', 'Pro'),
                (60, 'Insert métallique', 'insert-metallique', NULL),
                (61, 'Garniture', NULL, NULL),
                (62, 'Coupe-feu', 'insert-metallique', NULL)"
            : "INSERT INTO model VALUES (1, 'Deur', 'deur', NULL), (62, 'Ander', 'deur', NULL)"));

        return new LegacyDatabase($pdo, $environment, strtolower($environment));
    }

    private function compiler(): Compiler
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        return new Compiler(Mapping::fromFile($path), new Transforms());
    }

    /** @return array<string, array<string, mixed>> sourceUid => the one site's data */
    private static function sites(array $payloads): array
    {
        $out = [];

        foreach ($payloads as $payload) {
            $out[(string) $payload['sourceUid']] = $payload['sites']['berkvensFr'];
        }

        return $out;
    }

    #[Test]
    public function the_mapped_slug_becomes_the_sites_native_slug(): void
    {
        $out = [];
        $this->compiler()->compile($this->db(), 'FR', static function(array $p) use (&$out): void {
            $out[] = $p;
        });

        $sites = self::sites($out);

        self::assertSame('insert-metallique-1', $sites['kuma:FR:model:59']['slug']);
        // Lifted, not left behind: the loader refuses a field value no layout carries.
        self::assertSame(['subname' => 'Pro'], $sites['kuma:FR:model:59']['fieldValues']);
        self::assertArrayNotHasKey('fieldValues', $sites['kuma:FR:model:60']);

        // No legacy slug: no key at all, so Craft slugifies the title as it always did.
        self::assertArrayNotHasKey('slug', $sites['kuma:FR:model:61']);
    }

    #[Test]
    public function a_slug_two_rows_of_one_section_share_is_reported(): void
    {
        // Craft would suffix the second one `-1` without a word, and its URL would move.
        $compiler = $this->compiler();
        $compiler->compile($this->db(), 'FR', static function(): void {
        });

        self::assertSame(
            ['Model: row 62 repeats slug `insert-metallique` of row 60 in section `models` — Craft may suffix it' => 1],
            $compiler->skipped(),
        );
    }

    #[Test]
    public function a_batched_run_reports_a_shared_slug_once_whichever_slice_holds_it(): void
    {
        // A queue job compiles a lane in slices, each in a fresh process: the duplicate has to
        // be found from the whole lane, or a slice boundary between the two rows hides it.
        $compiler = $this->compiler();
        $run = $compiler->begin($this->db(), 'FR');

        foreach ([0, 1, 2, 3] as $offset) {
            $compiler->compileEntitySlice($run, 'Model', $offset, 1, static function(): void {
            });
        }

        self::assertSame(
            ['Model: row 62 repeats slug `insert-metallique` of row 60 in section `models` — Craft may suffix it' => 1],
            $compiler->skipped(),
        );
    }

    #[Test]
    public function each_environment_is_judged_on_its_own_rows(): void
    {
        // One compiler walks every environment of a console run; row 62 repeats a slug in BE
        // for a reason of its own, not because of what row 62 held in FR.
        $compiler = $this->compiler();

        foreach (['FR', 'BE'] as $environment) {
            $compiler->compile($this->db($environment), $environment, static function(): void {
            });
        }

        self::assertSame(
            [
                'Model: row 62 repeats slug `insert-metallique` of row 60 in section `models` — Craft may suffix it' => 1,
                'Model: row 62 repeats slug `deur` of row 1 in section `models` — Craft may suffix it' => 1,
            ],
            $compiler->skipped(),
        );
    }

    #[Test]
    public function a_row_skipped_for_its_missing_title_holds_no_slug(): void
    {
        // The untitled row never becomes an entry, so the slug is free for the next one.
        $compiler = $this->compiler();
        $compiler->compile($this->db('FR', "(1, NULL, 'deur', NULL), (2, 'Deur', 'deur', NULL)"), 'FR', static function(): void {
        });

        self::assertSame(['Model: row 1 has no `name`' => 1], $compiler->skipped());
    }
}
