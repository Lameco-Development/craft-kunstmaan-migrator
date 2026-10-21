<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * One legacy file is one asset, whichever route reaches it first.
 *
 * A file is recorded under one of two keys: `<ENV>:kuma_media:<id>` when it is asked for by id
 * (a block, a `[M<id>]` token, an og:image) and `legacy_url:<volume>:<sha1(path)>` when it is
 * asked for by its `/uploads/media/…` path (rich text, a Link field). Each route used to look
 * only at its own key and then fall back on a folder + filename + size match, which a renamed
 * copy defeats: on Berkvens NL, kuma_media 1407 `icoon-inbraakwerend.jpg` was ingested by path
 * as asset 1260 under Craft's collision suffix, and again by id as asset 10047.
 *
 * Each route now asks the other's key before it ingests, and records its own key on the asset
 * it finds.
 */
final class AssetMigrationServiceOneFileOneAssetTest extends TestCase
{
    private const URL = '/uploads/media/5a2fd4b1a4c3e/icoon-inbraakwerend.jpg';

    private function env(?PDO $legacy = null): EnvironmentContext
    {
        $legacy ??= self::legacyDb();

        return new EnvironmentContext(
            name: 'NL',
            database: 'legacy_nl',
            sites: EnvironmentFactory::sites(),
            legacy: new LegacyDatabase($legacy, 'NL', 'legacy_nl'),
        );
    }

    private static function legacyDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER, content_type TEXT, location TEXT, folder_id INTEGER)');
        // A soft-deleted row sharing the path must not win over the live one.
        $pdo->exec("INSERT INTO kuma_media VALUES (1390, '" . self::URL . "', 1, 'image/jpeg', 'local', 0)");
        $pdo->exec("INSERT INTO kuma_media VALUES (1407, '" . self::URL . "', 0, 'image/jpeg', 'local', 0)");

        return $pdo;
    }

    private function service(InMemoryMigrationState $state): AssetMigrationService
    {
        $service = new AssetMigrationService();
        $service->targetVolume = 'berkvensNl';
        $service->migrationState = $state;
        $service->elementWriter = new InMemoryElementWriter();

        return $service;
    }

    public function testAnIdLookupReusesTheAssetThePathAlreadyBecame(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:berkvensNl:' . sha1(self::URL), 1260, ['kind' => 'local-file', 'originalUrl' => self::URL]);

        // skipAssets: an ingest would have to stop here, so a hit can only be the reuse.
        self::assertSame(1260, $this->service($state)->resolveFromLegacyId(1407, $this->env(), new MigrationOptions(skipAssets: true)));

        self::assertCount(1, $state->recorded);
        self::assertSame('NL:kuma_media:1407', $state->recorded[0]['key']);
        self::assertSame(1260, $state->recorded[0]['targetId']);
        self::assertSame('asset', $state->recorded[0]['targetType']);
        // `originalUrl` is what the rewriter's URL cache and the state export read.
        self::assertSame(self::URL, $state->metaOf('media', 'NL:kuma_media:1407')['originalUrl'] ?? null);
        self::assertSame('legacy_url:berkvensNl:' . sha1(self::URL), $state->metaOf('media', 'NL:kuma_media:1407')['sameFileAs'] ?? null);
    }

    public function testAPathLookupReusesTheAssetTheIdAlreadyBecame(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'NL:kuma_media:1407', 10047, ['kind' => 'local-file', 'originalUrl' => self::URL]);

        self::assertSame(10047, $this->service($state)->resolveFromLegacyUrl(self::URL, $this->env(), new MigrationOptions(skipAssets: true)));

        self::assertCount(1, $state->recorded);
        self::assertSame('legacy_url:berkvensNl:' . sha1(self::URL), $state->recorded[0]['key']);
        self::assertSame(10047, $state->recorded[0]['targetId']);
        self::assertSame(self::URL, $state->metaOf('media', 'legacy_url:berkvensNl:' . sha1(self::URL))['originalUrl'] ?? null);
    }

    public function testAPathLookupDoesNotAdoptAnotherEnvironmentsIdRow(): void
    {
        // kuma_media ids restart in every database: FR's 1407 is another file.
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'FR:kuma_media:1407', 20047);

        self::assertSame(0, $this->service($state)->resolveFromLegacyUrl(self::URL, $this->env(), new MigrationOptions(skipAssets: true)));
        self::assertSame([], $state->recorded);
    }

    public function testAnIdRowThatIsNotAnAssetIsNotReused(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:berkvensNl:' . sha1(self::URL), 0);

        self::assertSame(0, $this->service($state)->resolveFromLegacyId(1407, $this->env(), new MigrationOptions(skipAssets: true)));
        self::assertSame([], $state->recorded);
    }

    public function testADryRunRecordsNothing(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:berkvensNl:' . sha1(self::URL), 1260);

        self::assertNull($this->service($state)->ingestOne(1407, new MigrationOptions(dryRun: true), $this->env()));
        self::assertSame([], $state->recorded);
    }
}
