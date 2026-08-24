<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The entry saver's own preconditions. Both failures are operator
 * misconfiguration (an empty sites map, a payload keyed by a handle the map
 * never declared) and both must be loud — a silent skip here writes entries
 * into the wrong locale or nowhere at all.
 */
final class EntryMigrationServiceSiteGuardTest extends TestCase
{
    public function testAnEmptySitesMapRefusesToSaveAnything(): void
    {
        $svc = new EntryMigrationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('$sites is empty');

        $svc->saveEntryForSites(1, 1, 'App_Entity_Pages_TextPage', 5, []);
    }

    public function testAPayloadKeyedByAnUnconfiguredHandleIsRejectedByName(): void
    {
        $svc = new EntryMigrationService();
        $svc->sites = ['nl' => 'default', 'en' => 'en'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown site handle "fr"');

        $svc->saveEntryForSites(1, 1, 'App_Entity_Pages_TextPage', 5, [
            'fr' => ['enabled' => true, 'title' => 'Bonjour', 'slug' => 'bonjour', 'fieldValues' => []],
        ]);
    }

    public function testTheRejectionNamesTheConfiguredHandlesSoTheOperatorCanFixTheMap(): void
    {
        $svc = new EntryMigrationService();
        $svc->sites = ['nl' => 'default'];

        try {
            $svc->saveEntryForSites(1, 1, 'App_Entity_Pages_TextPage', 5, ['en' => []]);
            self::fail('expected the unknown-handle rejection');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Configured: default', $e->getMessage());
        }
    }
}
