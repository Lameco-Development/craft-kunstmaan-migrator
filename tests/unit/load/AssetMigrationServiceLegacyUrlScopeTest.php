<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PHPUnit\Framework\TestCase;

/**
 * `legacy_url:` state keys are scoped to the volume the asset lands in.
 *
 * They were global: `legacy_url:<sha1(path)>`. Berkvens NL and Berkvens FR are separate
 * installs whose `/uploads/media/<hash>/<file>` paths coincide (FR media 870 and 874 carry NL's
 * paths), and they migrate into separate volumes. The FR run, after NL's, found NL's rows and
 * handed 12 FR entries 49 assets in the `berkvensNl` volume.
 *
 * Scoping by environment would break Enreach, whose DE pages resolve files under COM's media
 * root through the mediaRoots chain on purpose — but COM and DE land in one volume. The volume
 * is the line both projects agree on: one volume, one asset per path; two volumes, two.
 */
final class AssetMigrationServiceLegacyUrlScopeTest extends TestCase
{
    private const PATH = '/uploads/media/5a2fd47948f7a/berkvens-deursystemen-hotelvandervalk-5.jpg';

    private string $mediaRoot;

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/kmig-scope-' . uniqid();
        mkdir($this->mediaRoot . '/5a2fd47948f7a', 0777, true);
        file_put_contents($this->mediaRoot . '/5a2fd47948f7a/berkvens-deursystemen-hotelvandervalk-5.jpg', 'jpg-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->mediaRoot . '/5a2fd47948f7a/berkvens-deursystemen-hotelvandervalk-5.jpg');
        @rmdir($this->mediaRoot . '/5a2fd47948f7a');
        @rmdir($this->mediaRoot);
    }

    private function env(string $name = 'FR'): EnvironmentContext
    {
        return EnvironmentFactory::make($name, mediaRoots: [$this->mediaRoot]);
    }

    private function service(string $volume, InMemoryMigrationState $state, ?InMemoryElementWriter $writer = null): AssetMigrationService
    {
        $service = new AssetMigrationService();
        $service->targetVolume = $volume;
        $service->migrationState = $state;
        $service->elementWriter = $writer ?? new InMemoryElementWriter();

        return $service;
    }

    public function testTheKeyNamesTheVolumeAndNotTheEnvironment(): void
    {
        $nl = $this->service('berkvensNl', new InMemoryMigrationState());
        $fr = $this->service('berkvensFr', new InMemoryMigrationState());
        $uploads = $this->service('uploads', new InMemoryMigrationState());

        self::assertSame('legacy_url:berkvensNl:' . sha1(self::PATH), $nl->legacyUrlStateKey(self::PATH));
        self::assertNotSame($nl->legacyUrlStateKey(self::PATH), $fr->legacyUrlStateKey(self::PATH));
        // COM and DE run against one service and one volume: one key, so one asset.
        self::assertSame('legacy_url:uploads:' . sha1(self::PATH), $uploads->legacyUrlStateKey(self::PATH));
    }

    public function testTheBoundResolverNamesTheVolumeForTheRewritersCache(): void
    {
        $service = $this->service('berkvensFr', new InMemoryMigrationState());

        self::assertSame('berkvensFr', $service->resolverFor($this->env(), new MigrationOptions())->targetVolume());
    }

    public function testAScopedRowIsReusedWithoutAskingCraftForItsVolume(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:berkvensFr:' . sha1(self::PATH), 2001);
        $writer = new InMemoryElementWriter();

        self::assertSame(2001, $this->service('berkvensFr', $state, $writer)->resolveFromLegacyUrl(self::PATH, $this->env()));
        self::assertSame([], $writer->volumeLookups);
    }

    public function testAnotherVolumesScopedRowIsNotReused(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:berkvensNl:' . sha1(self::PATH), 1577);

        // skipAssets stops the ingest it falls through to: the NL asset must not come back.
        self::assertSame(0, $this->service('berkvensFr', $state)->resolveFromLegacyUrl(
            self::PATH,
            $this->env(),
            new MigrationOptions(skipAssets: true),
        ));
    }

    public function testAPreScopingRowInAnotherVolumeIsNotReused(): void
    {
        // The berkvens_cutover state: NL wrote `legacy_url:f387169f…` → asset 1577 in berkvensNl.
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:' . sha1(self::PATH), 1577);
        $writer = new InMemoryElementWriter();
        $writer->willHoldAsset(1577, 'berkvensNl');

        self::assertSame(0, $this->service('berkvensFr', $state, $writer)->resolveFromLegacyUrl(
            self::PATH,
            $this->env(),
            new MigrationOptions(skipAssets: true),
        ));
        self::assertSame([1577], $writer->volumeLookups);
        self::assertSame([], $state->recorded, 'NL still owns its row; FR writes nothing over it');
    }

    public function testAPreScopingRowInThisVolumeIsReusedAndCarriedOverToTheScopedKey(): void
    {
        // Every row already written keeps working: a re-run of the same project finds its own
        // assets rather than fetching every file a second time.
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:' . sha1(self::PATH), 1577, ['kind' => 'local-file', 'originalUrl' => self::PATH]);
        $writer = new InMemoryElementWriter();
        $writer->willHoldAsset(1577, 'berkvensNl');
        $service = $this->service('berkvensNl', $state, $writer);

        self::assertSame(1577, $service->resolveFromLegacyUrl(self::PATH, $this->env('NL')));

        self::assertCount(1, $state->recorded);
        self::assertSame('legacy_url:berkvensNl:' . sha1(self::PATH), $state->recorded[0]['key']);
        self::assertSame(1577, $state->recorded[0]['targetId']);
        self::assertSame('asset', $state->recorded[0]['targetType']);
        self::assertSame(self::PATH, $state->metaOf('media', 'legacy_url:berkvensNl:' . sha1(self::PATH))['originalUrl'] ?? null);

        // Carried over once: the next lookup is a scoped hit and asks Craft nothing.
        self::assertSame(1577, $service->resolveFromLegacyUrl(self::PATH, $this->env('NL')));
        self::assertSame([1577], $writer->volumeLookups);
    }

    public function testAPreScopingRowWhoseAssetIsGoneIsAMiss(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:' . sha1(self::PATH), 1577);

        self::assertSame(0, $this->service('berkvensNl', $state)->resolveFromLegacyUrl(
            self::PATH,
            $this->env('NL'),
            new MigrationOptions(skipAssets: true),
        ));
        self::assertSame([], $state->recorded);
    }

    public function testEnreachDeStillSharesComsAssetThroughOneVolume(): void
    {
        // DE resolves a file under COM's root; COM and DE share the `uploads` volume. The row COM
        // wrote answers DE, scoped or not, exactly as before.
        $state = new InMemoryMigrationState();
        $state->willResolve('media', 'legacy_url:uploads:' . sha1(self::PATH), 900);

        self::assertSame(900, $this->service('uploads', $state)->resolveFromLegacyUrl(self::PATH, $this->env('DE')));

        $legacy = new InMemoryMigrationState();
        $legacy->willResolve('media', 'legacy_url:' . sha1(self::PATH), 901);
        $writer = new InMemoryElementWriter();
        $writer->willHoldAsset(901, 'uploads');

        self::assertSame(901, $this->service('uploads', $legacy, $writer)->resolveFromLegacyUrl(self::PATH, $this->env('DE')));
    }
}
