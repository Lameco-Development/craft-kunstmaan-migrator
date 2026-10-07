<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\MappingInit;
use Lameco\Kunstmaanmigrator\Report\FillMeasurer;
use Lameco\Kunstmaanmigrator\Report\Requirement;
use Lameco\Kunstmaanmigrator\Report\Survey;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the database side reports when two live classes share a short name: each class by its
 * fully qualified name, with its own count. Counted under the short name they were one number,
 * and the second class was missing from every total that should have flagged it.
 */
final class PartClassSurveyTest extends TestCase
{
    private const APP_TEXT = 'App\Entity\PageParts\TextPagePart';
    private const KM_TEXT = 'Kunstmaan\PagePartBundle\Entity\TextPagePart';

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
        $pdo->exec('CREATE TABLE app_text_parts (id INTEGER, text TEXT)');
        $pdo->exec('CREATE TABLE kuma_text_page_parts (id INTEGER, content TEXT)');

        $pdo->exec("INSERT INTO app_text_parts VALUES (1, 'app')");
        $pdo->exec("INSERT INTO kuma_text_page_parts VALUES (1, ''), (2, 'kunstmaan')");

        $pdo->exec('INSERT INTO kuma_nodes VALUES (1, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (11, 'App\\\\Entity\\\\Pages\\\\HomePage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (21, 1, 'en', 'Home', 1, 11)");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    ('App\\\\Entity\\\\Pages\\\\HomePage', 100, 'main', 'App\\\\Entity\\\\PageParts\\\\TextPagePart', 1, 1),
                    ('App\\\\Entity\\\\Pages\\\\HomePage', 100, 'main', 'Kunstmaan\\\\PagePartBundle\\\\Entity\\\\TextPagePart', 1, 2),
                    ('App\\\\Entity\\\\Pages\\\\HomePage', 100, 'main', 'Kunstmaan\\\\PagePartBundle\\\\Entity\\\\TextPagePart', 2, 3),
                    ('App\\\\Entity\\\\Pages\\\\HomePage', 100, 'main', 'Kunstmaan\\\\MediaPagePartBundle\\\\Entity\\\\VideoPagePart', 1, 4),
                    ('App\\\\Entity\\\\Pages\\\\HomePage', 999, 'main', 'App\\\\Entity\\\\PageParts\\\\VideoPagePart', 1, 1)");

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }

    #[Test]
    public function live_classes_are_counted_by_their_fully_qualified_name(): void
    {
        self::assertSame(
            [self::KM_TEXT => 2, self::APP_TEXT => 1, 'Kunstmaan\MediaPagePartBundle\Entity\VideoPagePart' => 1],
            $this->db()->livePartClasses(),
        );
    }

    #[Test]
    public function the_survey_lists_each_class_of_a_shared_short_name_with_its_own_count(): void
    {
        // `Video` keeps its short name: the app's VideoPagePart has no live placement, so
        // nothing collides.
        self::assertSame(
            [self::KM_TEXT => 2, self::APP_TEXT => 1, 'Video' => 1],
            Survey::of($this->db())->partClasses,
        );
    }

    #[Test]
    public function the_skeleton_writes_one_qualified_row_per_class_of_a_shared_short_name(): void
    {
        $artifact = tempnam(sys_get_temp_dir(), 'kuma') . '.json';
        file_put_contents($artifact, json_encode([
            'mode' => 'booted',
            'entities' => [
                self::APP_TEXT => ['table' => 'app_text_parts', 'columns' => ['text' => ['column' => 'text']]],
                self::KM_TEXT => ['table' => 'kuma_text_page_parts', 'columns' => ['content' => ['column' => 'content']]],
            ],
        ]));

        try {
            $yaml = MappingInit::skeleton(['COM' => $this->db()], null, $artifact)->yaml;
        } finally {
            unlink($artifact);
        }

        self::assertStringContainsString(
            "  Kunstmaan\\PagePartBundle\\Entity\\TextPagePart:\n    live: 2\n    table: kuma_text_page_parts\n",
            $yaml,
        );
        self::assertStringContainsString(
            "  App\\Entity\\PageParts\\TextPagePart:\n    live: 1\n    table: app_text_parts\n",
            $yaml,
        );
        self::assertStringNotContainsString("  Text:\n", $yaml);
        self::assertStringContainsString("  Video:\n", $yaml);
    }

    #[Test]
    public function readiness_measures_each_class_against_its_own_rows(): void
    {
        $mapping = Mapping::fromArray(['parts' => [
            'Text' => ['table' => 'app_text_parts', 'block' => 'textBlock', 'map' => ['body' => 'text']],
            self::KM_TEXT => ['table' => 'kuma_text_page_parts', 'block' => 'textBlock', 'map' => ['body' => 'content']],
        ]]);
        $app = new Requirement('parts', 'Text', 'textBlock', 'body', 'text', 'map');
        $kunstmaan = new Requirement('parts', self::KM_TEXT, 'textBlock', 'body', 'content', 'map');

        (new FillMeasurer($mapping))->ingest([$app, $kunstmaan], $this->db());

        self::assertSame([1, 0], [$app->rows, $app->empty]);
        self::assertSame([2, 1], [$kunstmaan->rows, $kunstmaan->empty]);
    }
}
