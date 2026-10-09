<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\BlockBuilder;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\Schema;
use Lameco\Kunstmaanmigrator\Source\PartReader;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `seomatic(title=…, description=…, keywords=…, robots=…)` fills an SEOmatic field from mapped
 * values, in the shape the SEO adapter writes a page's: a catalogue entity keeps its SEO on a
 * row of its own (`modelseo`), which no `kuma_seo` read reaches.
 */
final class SeomaticExpressionTest extends TestCase
{
    private function builder(): BlockBuilder
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE modelseo (id INTEGER, meta_title TEXT, meta_description TEXT, meta_keywords TEXT, meta_robots TEXT)');
        $pdo->exec("INSERT INTO modelseo VALUES
            (47, 'Berkvens | Insert', 'Une porte', 'porte, insert', 'noindex'),
            (48, 'Berkvens | Coupe-feu', NULL, NULL, NULL),
            (49, NULL, NULL, NULL, NULL)");

        return new BlockBuilder(new PartReader($pdo), new Transforms(), 'FR');
    }

    private const SEO = 'seomatic(title=seo_id | lookup(modelseo.meta_title), description=seo_id | lookup(modelseo.meta_description), '
        . 'keywords=seo_id | lookup(modelseo.meta_keywords), robots=seo_id | lookup(modelseo.meta_robots))';

    private function seo(int $seoId): mixed
    {
        return $this->builder()->fieldsFrom(['seo' => self::SEO], ['id' => 1, 'seo_id' => $seoId], 'Model')['seo'] ?? null;
    }

    #[Test]
    public function mapped_columns_compile_to_the_seomatic_shape(): void
    {
        self::assertSame(
            [
                'metaGlobalVars' => [
                    'seoTitle' => 'Berkvens | Insert',
                    'seoDescription' => 'Une porte',
                    'seoImage' => '',
                    'ogTitle' => 'Berkvens | Insert',
                    'ogDescription' => 'Une porte',
                    'ogImage' => '',
                    'robots' => 'noindex',
                    'seoKeywords' => 'porte, insert',
                ],
                'metaBundleSettings' => [
                    'seoTitleSource' => 'fromCustom',
                    'seoDescriptionSource' => 'fromCustom',
                    'ogTitleSource' => 'fromCustom',
                    'ogDescriptionSource' => 'fromCustom',
                    'seoKeywordsSource' => 'fromCustom',
                ],
            ],
            $this->seo(47),
        );
    }

    #[Test]
    public function an_unset_value_is_an_explicit_empty_and_an_unset_robots_or_keywords_is_left_out(): void
    {
        // As a page's: an empty description stays `fromCustom` + '' rather than inheriting the
        // primary site's, while robots and keywords fall through to the sitewide default.
        $seo = $this->seo(48);

        self::assertIsArray($seo);
        self::assertSame('', $seo['metaGlobalVars']['seoDescription']);
        self::assertArrayNotHasKey('robots', $seo['metaGlobalVars']);
        self::assertArrayNotHasKey('seoKeywords', $seo['metaGlobalVars']);
        self::assertArrayNotHasKey('seoKeywordsSource', $seo['metaBundleSettings']);
    }

    #[Test]
    public function a_row_with_no_seo_writes_no_field(): void
    {
        // Nothing to say: SEOmatic's own defaults stand.
        self::assertNull($this->seo(49));
        self::assertNull($this->seo(99));
    }

    #[Test]
    public function an_argument_seomatic_does_not_take_is_rejected_by_the_mapping(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, <<<'YAML'
            version: 1
            environments:
              FR: { database: legacy, locales: { nl: siteNl } }
            entities:
              Model:
                table: model
                section: models
                entryType: modelPage
                title: name
                dedupe: false
                map:
                  seo: seomatic(title=seo_id | lookup(modelseo.meta_title), author=seo_id | lookup(modelseo.meta_author))
            YAML);

        self::assertSame(
            ['entities.Model.map.seo: `seomatic()` takes no `author=` — it takes title, description, keywords, robots, ogTitle, ogDescription, twitterTitle, twitterDescription'],
            (new Schema())->validate(Mapping::fromFile($path)),
        );
    }
}
