<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\BlockBuilder;
use Lameco\Kunstmaanmigrator\Compile\EntityIndex;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Source\PartReader;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    private function builder(): BlockBuilder
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE partner_countries (id INTEGER, title TEXT, abbreviation TEXT)');
        $pdo->exec("INSERT INTO partner_countries VALUES (28, 'Germany', 'DE')");

        return new BlockBuilder(
            new PartReader($pdo),
            new Transforms(),
            'DE',
            null,
            null,
            null,
            new EntityIndex(['PartnerCountry' => ['table' => 'partner_countries', 'dedupe' => false]]),
        );
    }

    private const ADDRESS = 'address(addressLine1=street, postalCode=postal_code, locality=city,'
        . ' latitude=latitude, longitude=longitude, countryCode=country_id | lookup(PartnerCountry.abbreviation))';

    #[Test]
    public function five_legacy_columns_and_a_foreign_key_become_one_address(): void
    {
        self::assertSame(
            ['partnerAddress' => ['_address' => [
                'addressLine1' => 'Schlossvorstadt 4',
                'postalCode' => '73479',
                'locality' => 'Ellwangen',
                'latitude' => '48.9607829',
                'longitude' => '10.1360325',
                // Not on the partner's own table: it is the abbreviation on the country row.
                'countryCode' => 'DE',
            ]]],
            $this->builder()->fieldsFrom(
                ['partnerAddress' => self::ADDRESS],
                [
                    'street' => 'Schlossvorstadt 4',
                    'postal_code' => '73479',
                    'city' => 'Ellwangen',
                    'latitude' => '48.9607829',
                    'longitude' => '10.1360325',
                    'country_id' => 28,
                ],
                'PartnerPage',
            ),
        );
    }

    #[Test]
    public function a_partial_address_keeps_the_parts_it_has(): void
    {
        self::assertSame(
            ['partnerAddress' => ['_address' => ['addressLine1' => 'Holzhausenstr. 87']]],
            $this->builder()->fieldsFrom(
                ['partnerAddress' => self::ADDRESS],
                ['street' => 'Holzhausenstr. 87', 'city' => '', 'country_id' => null],
                'PartnerPage',
            ),
        );
    }

    #[Test]
    public function a_row_with_no_address_columns_at_all_emits_no_address(): void
    {
        // An empty Address element is a row an editor has to clean up later; absent is right.
        self::assertSame(
            [],
            $this->builder()->fieldsFrom(['partnerAddress' => self::ADDRESS], ['street' => null], 'PartnerPage'),
        );
    }

    #[Test]
    public function a_lookup_through_a_missing_foreign_key_yields_nothing_rather_than_guessing(): void
    {
        self::assertSame(
            [],
            $this->builder()->fieldsFrom(
                ['countryCode' => 'country_id | lookup(PartnerCountry.abbreviation)'],
                ['country_id' => 999],
                'PartnerPage',
            ),
        );
    }

    #[Test]
    public function a_pages_own_collection_carries_a_ref_so_a_re_run_replaces_it(): void
    {
        // Without this the loader cannot recognise a block it already wrote and appends:
        // three legacy branches became six on the second run and nine on the third.
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE branches (id INTEGER, partner_page_id INTEGER, title TEXT, weight INTEGER)');
        $pdo->exec("INSERT INTO branches VALUES (11, 5, 'bluvo GmbH', 1), (12, 5, 'Telenova GmbH', 2)");

        $builder = new BlockBuilder(new PartReader($pdo), new Transforms(), 'DE');
        $children = ['partnerBranches' => [
            'table' => 'branches', 'fk' => 'partner_page_id', 'order' => 'weight',
            'map' => ['branchName' => 'title'],
        ]];

        $tracked = $builder->childrenOf($children, 'partnerPage', 5, 'PartnerPage', true);
        self::assertSame(
            ['DE:branches:11', 'DE:branches:12'],
            array_column(array_column($tracked['partnerBranches'], 'fields'), '_sourcePartRef'),
        );

        // A pagepart's children carry one too. They used to stay untagged on the theory that
        // their stability followed from the parent block's ref — it does not. Craft matches a
        // block by the payload key, and a nested block with no ref to thread gets a `new` key
        // however stable its parent is. Measured on a forced re-run: every `contentColumn` and
        // `button` destroyed and rebuilt, while the top-level blocks around them survived.
        $nested = $builder->childrenOf($children, 'someBlock', 5, 'SomePart');
        self::assertSame(
            ['DE:branches:11', 'DE:branches:12'],
            array_column(array_column($nested['partnerBranches'], 'fields'), '_sourcePartRef'),
        );
    }
}
