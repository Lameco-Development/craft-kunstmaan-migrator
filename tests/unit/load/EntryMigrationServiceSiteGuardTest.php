<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The entry saver's own preconditions. All three failures are operator
 * misconfiguration (an empty locale block, a payload keyed by a handle the
 * block never declared, handles Craft has no site for) and all must be loud —
 * a silent skip here writes entries into the wrong locale or nowhere at all.
 */
final class EntryMigrationServiceSiteGuardTest extends TestCase
{
    public function testAnEmptySiteMapRefusesToSaveAnything(): void
    {
        $svc = new EntryMigrationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the site map is empty');

        $svc->saveEntryForSites(1, 1, 'App_Entity_Pages_TextPage', 5, [], SiteMap::bind([], []));
    }

    public function testAPayloadKeyedByAnUnconfiguredHandleIsRejectedByName(): void
    {
        $svc = new EntryMigrationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown site handle "fr"');

        $svc->saveEntryForSites(1, 1, 'App_Entity_Pages_TextPage', 5, [
            'fr' => ['enabled' => true, 'title' => 'Bonjour', 'slug' => 'bonjour', 'fieldValues' => []],
        ], EnvironmentFactory::sites());
    }

    public function testTheRejectionNamesTheConfiguredHandlesSoTheOperatorCanFixTheMap(): void
    {
        $svc = new EntryMigrationService();

        try {
            $svc->saveEntryForSites(1, 1, 'App_Entity_Pages_TextPage', 5, ['en' => []], EnvironmentFactory::sites(['nl' => 'default']));
            self::fail('expected the unknown-handle rejection');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Configured: default', $e->getMessage());
        }
    }

    public function testAMapWhoseHandlesCraftDoesNotKnowIsRejectedBeforeAnyWrite(): void
    {
        $svc = new EntryMigrationService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('none of the configured site handles resolve to a Craft site');

        $svc->saveEntryForSites(
            1,
            1,
            'App_Entity_Pages_TextPage',
            5,
            ['default' => []],
            EnvironmentFactory::sites(['nl' => 'default'], ['en' => [2, 'en-GB', true]]),
        );
    }
}
