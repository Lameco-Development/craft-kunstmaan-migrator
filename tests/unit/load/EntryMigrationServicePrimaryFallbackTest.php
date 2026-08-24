<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Craft requires the primary site to save first, but a sparse source payload
 * may legitimately omit the primary locale. The first save then borrows the
 * best available native values from another locale — without mutating the
 * source-keyed map, and saying so in the report.
 */
final class EntryMigrationServicePrimaryFallbackTest extends TestCase
{
    private function resolve(array $perSite, ?MigrationReport $report = null): array
    {
        return (new ReflectionMethod(EntryMigrationService::class, 'primarySiteDataForSave'))
            ->invoke(new EntryMigrationService(), $perSite, 'default', $report, 'App_Entity_Pages_TextPage', '5');
    }

    public function testACompletePrimaryPayloadIsReturnedUntouched(): void
    {
        $report = new MigrationReport();
        $primary = ['title' => 'Home', 'slug' => 'home', 'fieldValues' => []];

        $out = $this->resolve([
            'default' => $primary,
            'en' => ['title' => 'Other', 'slug' => 'other', 'fieldValues' => []],
        ], $report);

        self::assertSame($primary, $out);
        self::assertSame([], $report->warnings, 'nothing was borrowed, so nothing to report');
    }

    public function testAMissingPrimarySlugIsBorrowedFromAnotherLocale(): void
    {
        $report = new MigrationReport();

        $out = $this->resolve([
            'default' => ['title' => 'Thuis', 'slug' => '', 'fieldValues' => []],
            'en' => ['title' => 'Home', 'slug' => 'home', 'fieldValues' => []],
        ], $report);

        self::assertSame('home', $out['slug']);
        self::assertSame('Thuis', $out['title'], 'only the missing native value is borrowed');
        self::assertSame(1, $report->counts['fallback.sparse_locale_primary'] ?? 0);
    }

    public function testALocaleWithNoUsableNativeValuesIsSkippedForTheNextCandidate(): void
    {
        $out = $this->resolve([
            'default' => ['title' => '', 'slug' => '', 'fieldValues' => []],
            'en' => ['title' => '', 'slug' => '', 'fieldValues' => []],
            'fr' => ['title' => 'Accueil', 'slug' => 'accueil', 'fieldValues' => []],
        ]);

        self::assertSame('Accueil', $out['title']);
        self::assertSame('accueil', $out['slug']);
    }

    public function testAnAbsentPrimaryPayloadTakesTheWholeFallbackPayload(): void
    {
        $out = $this->resolve([
            'en' => ['title' => 'Home', 'slug' => 'home', 'fieldValues' => ['body' => 'x']],
        ]);

        self::assertSame('Home', $out['title']);
        self::assertSame(['body' => 'x'], $out['fieldValues']);
    }

    public function testNoUsableFallbackLeavesTheSparsePrimaryAsIs(): void
    {
        $report = new MigrationReport();
        $primary = ['title' => '', 'slug' => '', 'fieldValues' => []];

        $out = $this->resolve([
            'default' => $primary,
            'en' => ['title' => '', 'slug' => '', 'fieldValues' => []],
        ], $report);

        self::assertSame($primary, $out);
        self::assertSame([], $report->warnings);
    }
}
