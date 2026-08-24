<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\AssetFolderPath;
use PHPUnit\Framework\TestCase;

/**
 * Where a migrated asset lands.
 *
 * The `year` default is a bucket keyed on the file's own created date; `legacy-tree` mirrors
 * the folder the file sat in on the legacy side. The interesting cases are the ones where the
 * tree cannot answer — an unfiled file must not end up at the volume root, where it is loose
 * among the client's own media.
 */
final class AssetFolderPathTest extends TestCase
{
    public function testYearStrategyBucketsByYearAndIgnoresAnyChain(): void
    {
        self::assertSame(
            'migrated/2024',
            AssetFolderPath::compose('year', 'migrated', 'Media/Afbeeldingen', '2024'),
        );
    }

    public function testLegacyTreeMirrorsTheFolderChain(): void
    {
        self::assertSame(
            'migrated/Media/Afbeeldingen/Visuals',
            AssetFolderPath::compose('legacy-tree', 'migrated', 'Media/Afbeeldingen/Visuals', '2024'),
        );
    }

    public function testASecondSourceIsRootedUnderItsOwnEnvironment(): void
    {
        self::assertSame(
            'migrated/DE/Media/Afbeeldingen',
            AssetFolderPath::compose('legacy-tree', 'migrated', 'Media/Afbeeldingen', '2024', 'DE', true),
        );
    }

    public function testASingleSourceGetsNoEnvironmentSegment(): void
    {
        self::assertSame(
            'migrated/Media/Afbeeldingen',
            AssetFolderPath::compose('legacy-tree', 'migrated', 'Media/Afbeeldingen', '2024', 'COM', false),
        );
    }

    /**
     * The whole point of the fallback: a file with no resolvable folder is still filed
     * somewhere findable rather than dropped at the top of the volume.
     */
    public function testAnUnresolvedChainFallsBackToTheYearBucketNotTheVolumeRoot(): void
    {
        self::assertSame(
            'migrated/2024',
            AssetFolderPath::compose('legacy-tree', 'migrated', null, '2024', 'COM', true),
        );

        self::assertSame(
            'migrated/2024',
            AssetFolderPath::compose('legacy-tree', 'migrated', '', '2024', 'COM', true),
        );
    }

    public function testAnEmptySubfolderPlacesTheTreeAtTheVolumeRoot(): void
    {
        self::assertSame(
            'Media/Afbeeldingen',
            AssetFolderPath::compose('legacy-tree', '', 'Media/Afbeeldingen', '2024'),
        );
    }

    /**
     * A legacy folder name is client copy, not a handle: it can hold a slash, a colon or
     * trailing whitespace, none of which a volume folder may carry.
     */
    public function testSegmentSanitisationKeepsTheNameAndDropsWhatAPathCannotHold(): void
    {
        self::assertSame('Afbeeldingen', AssetFolderPath::sanitizeSegment('  Afbeeldingen  '));
        self::assertSame('Cases-2024', AssetFolderPath::sanitizeSegment('Cases/2024'));
        self::assertSame('Enreach Labels', AssetFolderPath::sanitizeSegment('Enreach: Labels'));
        self::assertSame('', AssetFolderPath::sanitizeSegment('   '));
    }

    public function testAnEnvironmentNameIsSanitisedLikeAnyOtherSegment(): void
    {
        self::assertSame(
            'migrated/COM-EU/Media',
            AssetFolderPath::compose('legacy-tree', 'migrated', 'Media', '2024', 'COM/EU', true),
        );
    }
}
