<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\RedirectMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 2026-09-11, the service-provider site on the Enreach staging copy. Two redirects pointed at
 * `/__home__`, the home page's URI sentinel written as a path, and an `--add-sites=comSp` run
 * also wrote an English redirect nobody asked for.
 */
final class RedirectHomeAndAddSitesTest extends TestCase
{
    public function testTheHomePageIsTheSiteRoot(): void
    {
        self::assertSame('/', RedirectMigrationService::destinationForUri('__home__'));
    }

    public function testAnyOtherUriBecomesARootRelativePath(): void
    {
        self::assertSame('/contact/partnerconnect', RedirectMigrationService::destinationForUri('contact/partnerconnect'));
        self::assertSame('/fr-fr/actualites', RedirectMigrationService::destinationForUri('/fr-fr/actualites'));
    }

    public function testAnEntryWithoutAUriHasNoDestination(): void
    {
        self::assertNull(RedirectMigrationService::destinationForUri(null));
    }

    public function testWithoutAddSitesEveryRecordIsWritten(): void
    {
        $records = [self::record('comEnGlobal'), self::record('comSp')];

        self::assertSame([$records, 0], RedirectMigrationService::recordsForAddedSites($records, []));
    }

    public function testAnAddSitesRunWritesOnlyTheRecordsOfTheSitesItAdds(): void
    {
        $records = [self::record('comEnGlobal'), self::record('comSp'), self::record('comFrFr'), self::record('comSp')];

        [$kept, $leftOut] = RedirectMigrationService::recordsForAddedSites($records, ['comSp']);

        self::assertSame([self::record('comSp'), self::record('comSp')], $kept);
        self::assertSame(2, $leftOut);
    }

    public function testAMalformedRecordStillReachesTheLoadSoItIsReported(): void
    {
        $records = ['not a record', ['from' => '/x', 'to' => '/y', 'type' => 301]];

        self::assertSame([$records, 0], RedirectMigrationService::recordsForAddedSites($records, ['comSp']));
    }

    public function testAGlobalOrOtherSiteRedirectIsNotBeingAdded(): void
    {
        self::assertTrue(RedirectMigrationService::siteIsBeingAdded(16, [16]));
        self::assertFalse(RedirectMigrationService::siteIsBeingAdded(11, [16]));
        self::assertFalse(RedirectMigrationService::siteIsBeingAdded(null, [16]));
    }

    public function testEveryLaneAppliesTheHomePageRuleAndTheAddSitesScope(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(RedirectMigrationService::class))->getFileName());

        // Both lanes that turn an entry into a destination go through destinationForUri().
        self::assertStringNotContainsString("'/' . ltrim(\$entry->uri, '/')", $source);
        self::assertSame(2, substr_count($source, 'self::destinationForUri($entry->uri)'));

        // The redirect-page records are narrowed before the load writes them.
        $filter = strpos($source, 'self::recordsForAddedSites($records, $opts->addSites)');
        $load = strpos($source, 'LoadController::reportForRedirects(');
        self::assertNotFalse($filter);
        self::assertNotFalse($load);
        self::assertLessThan($load, $filter);

        // A kuma_redirects row is gated on its site before its destination is looked up.
        $method = (int) strpos($source, 'private function importOneKumaRedirect(');
        $gate = strpos($source, 'self::siteIsBeingAdded($srcSiteId, $addedSiteIds)', $method);
        $resolve = strpos($source, '$this->resolveDestUrl($target', $method);
        self::assertNotFalse($gate);
        self::assertNotFalse($resolve);
        self::assertLessThan($resolve, $gate);

        // Section moves stay on the added sites.
        $moves = (int) strpos($source, 'private function emitSectionMoveForNode(');
        self::assertNotFalse(strpos($source, 'in_array((string) $handle, $opts->addSites, true)', $moves));
    }

    /**
     * @return array{from: string, to: string, siteHandle: string, type: int}
     */
    private static function record(string $siteHandle): array
    {
        return ['from' => '/from', 'to' => '/to', 'siteHandle' => $siteHandle, 'type' => 301];
    }
}
