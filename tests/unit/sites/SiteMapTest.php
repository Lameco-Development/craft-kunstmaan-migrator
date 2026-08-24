<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\sites;

use Lameco\Kunstmaanmigrator\sites\SiteMap;
use PHPUnit\Framework\TestCase;

/**
 * The locale-to-site join, in one place and asserted on.
 *
 * Three modules derived this same join independently and projected one field
 * each; a fourth read the raw mapping array directly. None of it was testable,
 * because every version of it started from `Craft::$app->sites->getAllSites()`.
 */
final class SiteMapTest extends TestCase
{
    /** @param array<string, array{int, string}> $sites handle => [id, language] */
    private function craftSites(array $sites): array
    {
        $out = [];

        foreach ($sites as $handle => [$id, $language]) {
            $out[] = new class($id, (string) $handle, $language) {
                public function __construct(
                    public readonly int $id,
                    public readonly string $handle,
                    public readonly string $language,
                ) {
                }
            };
        }

        return $out;
    }

    private function map(): SiteMap
    {
        return SiteMap::bind(
            ['nl' => 'default', 'en' => 'en', 'de' => 'de'],
            $this->craftSites([
                'en' => [2, 'en-GB'],
                'default' => [1, 'nl-NL'],
                'de' => [3, 'de-DE'],
            ]),
        );
    }

    public function testEachLocaleBindsToItsCraftSite(): void
    {
        $map = $this->map();

        self::assertSame(1, $map->siteIdForLocale('nl'));
        self::assertSame(2, $map->siteIdForLocale('en'));
        self::assertSame('de-DE', $map->bindingForLocale('de')?->language);
    }

    /**
     * The first configured locale is canonical and drives primary-first save
     * ordering, so bindings follow the mapping rather than Craft's own site
     * order — which is set in the control panel and can change under you.
     */
    public function testBindingsFollowTheConfiguredOrderNotCraftsSiteOrder(): void
    {
        $locales = array_map(
            static fn($binding): string => $binding->locale,
            $this->map()->bindings(),
        );

        self::assertSame(['nl', 'en', 'de'], $locales);
    }

    public function testHandlesKeepTheirConfiguredOrder(): void
    {
        self::assertSame(['default', 'en', 'de'], $this->map()->handles());
        self::assertSame(['nl', 'en', 'de'], $this->map()->locales());
    }

    public function testTheMappingIsReadableInBothDirections(): void
    {
        $map = $this->map();

        self::assertSame('default', $map->handleForLocale('nl'));
        self::assertSame('nl', $map->localeForHandle('default'));
        self::assertNull($map->handleForLocale('fr'));
        self::assertNull($map->localeForHandle('fr'));
        self::assertNull($map->handleForLocale(null));
        self::assertNull($map->localeForHandle(null));
    }

    /**
     * A configured locale whose Craft site does not exist stays in the
     * mapping — the payload still names it — but produces no binding, so
     * nothing tries to write to a site id that was never resolved.
     */
    public function testALocaleWithNoCraftSiteIsConfiguredButNotBound(): void
    {
        $map = SiteMap::bind(
            ['nl' => 'default', 'fr' => 'fr'],
            $this->craftSites(['default' => [1, 'nl-NL']]),
        );

        self::assertSame(['nl', 'fr'], $map->locales());
        self::assertCount(1, $map->bindings());
        self::assertNull($map->siteIdForLocale('fr'));
        self::assertSame('fr', $map->handleForLocale('fr'));
    }

    public function testACraftSiteNoLocaleClaimsIsReportedRatherThanIgnored(): void
    {
        $map = SiteMap::bind(
            ['nl' => 'default'],
            $this->craftSites(['default' => [1, 'nl-NL'], 'forgotten' => [9, 'es-ES']]),
        );

        self::assertSame(['forgotten'], $map->unboundCraftHandles());
    }

    public function testAnEmptyMappingIsEmptyRatherThanAMapOfNothing(): void
    {
        $map = SiteMap::bind([], $this->craftSites(['default' => [1, 'nl-NL']]));

        self::assertTrue($map->isEmpty());
        self::assertSame([], $map->bindings());
        self::assertSame(['default'], $map->unboundCraftHandles());
    }

    public function testBlankLocalesAndHandlesAreDroppedFromTheMapping(): void
    {
        $map = SiteMap::bind(
            ['nl' => 'default', '' => 'orphan', 'de' => ''],
            $this->craftSites(['default' => [1, 'nl-NL']]),
        );

        self::assertSame(['nl'], $map->locales());
    }

    public function testTwoEnvironmentsProduceIndependentMaps(): void
    {
        $com = SiteMap::bind(['en' => 'comEn'], $this->craftSites(['comEn' => [1, 'en-GB']]));
        $de = SiteMap::bind(['de' => 'deDe'], $this->craftSites(['deDe' => [7, 'de-DE']]));

        self::assertSame(1, $com->siteIdForLocale('en'));
        self::assertSame(7, $de->siteIdForLocale('de'));
        self::assertNull($com->siteIdForLocale('de'), 'one environment must not see another\'s sites');
        self::assertNull($de->siteIdForLocale('en'));
    }

    /**
     * Enreach points both `br` and `pt` at comBrPt, which is a normal thing for
     * a corpus to do: Brazilian Portuguese and Portuguese content share one
     * Craft site.
     *
     * The join used to run the other way — walk Craft's sites, reverse-look-up
     * each handle with array_search — which finds only the FIRST locale that
     * claims a site. `br` bound, `pt` silently did not, and every pass keyed by
     * locale skipped Portuguese without reporting anything.
     */
    public function testTwoLocalesMayShareOneCraftSite(): void
    {
        $map = SiteMap::bind(
            ['en' => 'comEnUs', 'br' => 'comBrPt', 'pt' => 'comBrPt'],
            $this->craftSites(['comEnUs' => [1, 'en-US'], 'comBrPt' => [8, 'pt-BR']]),
        );

        self::assertSame(8, $map->siteIdForLocale('br'));
        self::assertSame(8, $map->siteIdForLocale('pt'), 'the second locale must bind too');
        self::assertSame(['en' => 1, 'br' => 8, 'pt' => 8], $map->localeToSiteId());
        self::assertSame(['en' => 'en-US', 'br' => 'pt-BR', 'pt' => 'pt-BR'], $map->localeToLanguage());
    }

    public function testASharedSiteIsNotReportedAsUnclaimed(): void
    {
        $map = SiteMap::bind(
            ['br' => 'comBrPt', 'pt' => 'comBrPt'],
            $this->craftSites(['comBrPt' => [8, 'pt-BR'], 'orphan' => [9, 'es-ES']]),
        );

        self::assertSame(['orphan'], $map->unboundCraftHandles());
        self::assertCount(2, $map->bindings());
    }

    public function testBindingsForASharedSiteKeepConfiguredOrder(): void
    {
        $map = SiteMap::bind(
            ['pt' => 'comBrPt', 'en' => 'comEnUs', 'br' => 'comBrPt'],
            $this->craftSites(['comEnUs' => [1, 'en-US'], 'comBrPt' => [8, 'pt-BR']]),
        );

        self::assertSame(
            ['pt', 'en', 'br'],
            array_map(static fn($binding): string => $binding->locale, $map->bindings()),
        );
    }

    /**
     * handleForLocale is many-to-one and localeForHandle is one-to-many, so the
     * reverse lookup can only answer with the first locale. That is fine for
     * what reads it — reporting a Craft site nobody mapped — but it is worth
     * pinning so nobody builds a fan-out on it.
     */
    public function testTheReverseLookupAnswersWithTheFirstLocaleForASharedSite(): void
    {
        $map = SiteMap::bind(
            ['br' => 'comBrPt', 'pt' => 'comBrPt'],
            $this->craftSites(['comBrPt' => [8, 'pt-BR']]),
        );

        self::assertSame('br', $map->localeForHandle('comBrPt'));
        self::assertSame('comBrPt', $map->handleForLocale('pt'));
    }
}
