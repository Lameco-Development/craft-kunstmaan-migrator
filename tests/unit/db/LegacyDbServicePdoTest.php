<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\db;

use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The one-connection contract: the pipeline hands this service the PDO the
 * compile half opened (`usePdo()`), and every query method reads through it.
 */
final class LegacyDbServicePdoTest extends TestCase
{
    private function service(): LegacyDbService
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE ext_translations (object_class TEXT, locale TEXT, field TEXT, content TEXT, foreign_key INTEGER)');
        $pdo->exec("INSERT INTO ext_translations VALUES
            ('App\\Entity\\Sector', 'en', 'name', 'Health', 7),
            ('App\\Entity\\Sector', 'de', 'name', 'Gesundheit', 7),
            ('App\\Entity\\Other', 'en', 'name', 'Noise', 7)");

        $service = new LegacyDbService();
        $service->usePdo($pdo);

        return $service;
    }

    public function testQueryMethodsReadThroughTheAdoptedConnection(): void
    {
        $service = $this->service();

        self::assertSame(
            'Health',
            $service->queryOne("SELECT content FROM ext_translations WHERE locale = 'en' AND object_class = :c", [':c' => 'App\\Entity\\Sector'])['content'] ?? null,
        );
        self::assertNull($service->queryOne("SELECT * FROM ext_translations WHERE locale = 'xx'"));
        self::assertCount(3, $service->queryAll('SELECT * FROM ext_translations'));
        self::assertSame('3', (string) $service->queryScalar('SELECT COUNT(*) FROM ext_translations'));
        self::assertCount(3, iterator_to_array($service->streamQuery('SELECT * FROM ext_translations')));
    }

    public function testExtTranslationsForKeysByLocaleAndFieldWithFqcnScoping(): void
    {
        $result = $this->service()->extTranslationsFor('App\\Entity\\Sector', 7);

        self::assertSame(['en' => ['name' => 'Health'], 'de' => ['name' => 'Gesundheit']], $result);
        self::assertSame([], $this->service()->extTranslationsFor([], 7));
    }
}
