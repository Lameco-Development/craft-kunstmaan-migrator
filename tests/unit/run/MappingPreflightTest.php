<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\run;

use lameco\kunstmaanmigrator\run\MappingPreflight;
use lameco\kunstmaanmigrator\tests\support\InMemoryPreflightProbe;
use PHPUnit\Framework\TestCase;

/**
 * The questions worth asking before a run that takes hours.
 *
 * Shaped after Enreach, which is the hardest case this migrator has: three
 * legacy databases, upload directories that fall back to each other, and
 * locale maps that differ per environment — COM's `en` is comEnUs while LV's
 * is comLvEn.
 */
final class MappingPreflightTest extends TestCase
{
    /** @param array<string, array{int, string}> $sites */
    private function craftSites(array $sites): array
    {
        $out = [];

        foreach ($sites as $handle => [$id, $language]) {
            $out[] = new class ($id, (string) $handle, $language) {
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

    private function enreachSites(): array
    {
        return $this->craftSites([
            'comEnUs' => [1, 'en-US'],
            'comDeDe' => [2, 'de-DE'],
            'comLvLv' => [3, 'lv-LV'],
            'comLvEn' => [4, 'en-GB'],
        ]);
    }

    public function testAHealthyEnvironmentIsReady(): void
    {
        $probe = new InMemoryPreflightProbe(
            nodeCounts: ['enreach_website' => 1244],
            readableDirectories: ['/uploads/com'],
        );

        $checks = (new MappingPreflight($probe))->inspect([
            'COM' => [
                'database' => 'enreach_website',
                'mediaRoot' => ['/uploads/com'],
                'locales' => ['en' => 'comEnUs'],
            ],
        ], $this->enreachSites());

        self::assertTrue($checks[0]->isReady());
        self::assertFalse($checks[0]->isBlocked());
        self::assertSame(1244, $checks[0]->nodeCount);
    }

    /**
     * And it says why. "Cannot connect" on its own sends someone reading
     * configuration files; the driver's own message names the field to fix —
     * which is how a mistyped env-var reference costs seconds instead of
     * minutes.
     */
    public function testAnUnreachableDatabaseIsTheOnlyThingReportedAndItSaysWhy(): void
    {
        $probe = new InMemoryPreflightProbe(unreachable: ['enreach_website_de']);

        $checks = (new MappingPreflight($probe))->inspect([
            'DE' => ['database' => 'enreach_website_de', 'mediaRoot' => ['/gone'], 'locales' => ['de' => 'nope']],
        ], $this->enreachSites());

        self::assertCount(1, $checks[0]->problems(), 'nothing else is worth saying until it connects');
        self::assertStringContainsString('Cannot connect to enreach_website_de', $checks[0]->problems()[0]);
        self::assertStringContainsString('Access denied', $checks[0]->problems()[0]);
        self::assertTrue($checks[0]->isBlocked());
    }

    /**
     * The mistake people actually make: right server, right credentials,
     * wrong database.
     */
    public function testAReachableDatabaseWithNoKumaNodesReadsAsTheWrongDatabase(): void
    {
        $probe = new InMemoryPreflightProbe(nodeCounts: [], readableDirectories: ['/uploads/com']);

        $checks = (new MappingPreflight($probe))->inspect([
            'COM' => ['database' => 'some_other_db', 'mediaRoot' => ['/uploads/com'], 'locales' => ['en' => 'comEnUs']],
        ], $this->enreachSites());

        self::assertStringContainsString('wrong database?', $checks[0]->problems()[0]);
        self::assertTrue($checks[0]->isBlocked());
    }

    public function testAMissingPrimaryUploadsDirectoryBlocksTheRun(): void
    {
        $probe = new InMemoryPreflightProbe(nodeCounts: ['db' => 10], readableDirectories: []);

        $checks = (new MappingPreflight($probe))->inspect([
            'COM' => ['database' => 'db', 'mediaRoot' => ['/moved'], 'locales' => ['en' => 'comEnUs']],
        ], $this->enreachSites());

        self::assertStringContainsString('Uploads directory is missing', $checks[0]->problems()[0]);
        self::assertTrue($checks[0]->isBlocked());
    }

    /**
     * DE looks in its own directory first and falls back to COM's. A missing
     * fallback is worth saying out loud, but the primary answers most lookups
     * — blocking on it would stop a run that would largely have worked.
     */
    public function testAMissingFallbackUploadsDirectoryIsReportedButDoesNotBlock(): void
    {
        $probe = new InMemoryPreflightProbe(
            nodeCounts: ['enreach_website_de' => 300],
            readableDirectories: ['/legacy-media/de'],
        );

        $checks = (new MappingPreflight($probe))->inspect([
            'DE' => [
                'database' => 'enreach_website_de',
                'mediaRoot' => ['/legacy-media/de', '/uploads/com'],
                'locales' => ['de' => 'comDeDe'],
            ],
        ], $this->enreachSites());

        self::assertNotEmpty($checks[0]->problems());
        self::assertStringContainsString('Fallback uploads', $checks[0]->problems()[0]);
        self::assertFalse($checks[0]->isBlocked(), 'the primary root answers most lookups');
    }

    /**
     * The failure this catches is the one MigrateController's own docblock
     * records: every LV entry failing with "unknown site handle comLvEn".
     */
    public function testALocalePointingAtAMissingCraftSiteBlocksTheRun(): void
    {
        $probe = new InMemoryPreflightProbe(
            nodeCounts: ['enreach_website_lv' => 130],
            readableDirectories: ['/legacy-media/lv'],
        );

        $checks = (new MappingPreflight($probe))->inspect([
            'LV' => [
                'database' => 'enreach_website_lv',
                'mediaRoot' => ['/legacy-media/lv'],
                'locales' => ['lv' => 'comLvLv', 'en' => 'comLvEn', 'ru' => 'siteNobodyCreated'],
            ],
        ], $this->enreachSites());

        self::assertSame(['ru'], $checks[0]->localesWithoutSite);
        self::assertTrue($checks[0]->isBlocked());
    }

    /**
     * `!unmapped "no Craft site exists for this locale"` is a decision with a
     * reason attached. Reporting it as a fault would train people to ignore
     * this list.
     */
    public function testADeliberatelyUnmappedLocaleIsNotAProblem(): void
    {
        $probe = new InMemoryPreflightProbe(
            nodeCounts: ['enreach_website' => 1244],
            readableDirectories: ['/uploads/com'],
        );

        $checks = (new MappingPreflight($probe))->inspect([
            'COM' => [
                'database' => 'enreach_website',
                'mediaRoot' => ['/uploads/com'],
                'locales' => ['en' => 'comEnUs', 'sp' => null],
            ],
        ], $this->enreachSites());

        self::assertSame(['sp'], $checks[0]->localesNotMigrated);
        self::assertSame([], $checks[0]->problems());
        self::assertTrue($checks[0]->isReady());
    }

    public function testEveryEnvironmentIsInspectedAndKeepsItsName(): void
    {
        $probe = new InMemoryPreflightProbe(
            nodeCounts: ['a' => 1, 'b' => 2, 'c' => 3],
            readableDirectories: ['/x'],
        );

        $checks = (new MappingPreflight($probe))->inspect([
            'COM' => ['database' => 'a', 'mediaRoot' => ['/x'], 'locales' => ['en' => 'comEnUs']],
            'DE' => ['database' => 'b', 'mediaRoot' => ['/x'], 'locales' => ['de' => 'comDeDe']],
            'LV' => ['database' => 'c', 'mediaRoot' => ['/x'], 'locales' => ['lv' => 'comLvLv']],
        ], $this->enreachSites());

        self::assertSame(['COM', 'DE', 'LV'], array_map(static fn ($c): string => $c->name, $checks));
        self::assertSame([true, true, true], array_map(static fn ($c): bool => $c->isReady(), $checks));
    }

    public function testAnEnvironmentWithNoNodesIsWorthStopping(): void
    {
        $probe = new InMemoryPreflightProbe(nodeCounts: ['empty_db' => 0], readableDirectories: ['/x']);

        $checks = (new MappingPreflight($probe))->inspect([
            'COM' => ['database' => 'empty_db', 'mediaRoot' => ['/x'], 'locales' => ['en' => 'comEnUs']],
        ], $this->enreachSites());

        self::assertStringContainsString('no nodes to migrate', $checks[0]->problems()[0]);
        self::assertTrue($checks[0]->isBlocked());
    }
}
