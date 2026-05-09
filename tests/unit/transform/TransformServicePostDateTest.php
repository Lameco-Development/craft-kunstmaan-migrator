<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\transform;

use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\transform\TransformService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/integration/load/_craft_shim.php';

/**
 * Covers TransformService::resolvePostDate — the per-entity-type override that
 * routes Kunstmaan's editorial `date` column (inherited from
 * AbstractArticlePage) through to Craft's postDate, instead of using
 * kuma_node_translations.created. Without this News/Blog/Event entries
 * sort their indexes by drafting date instead of publish date.
 */
final class TransformServicePostDateTest extends TestCase
{
    public function testEditorialDateOverridesTranslationCreated(): void
    {
        $payload = $this->runOne(
            nodeClassExtra: ['postDateColumn' => 'date'],
            siteData: [
                'created' => '2016-05-02 20:31:14',
                'detail' => ['id' => 1, 'date' => '2016-06-20 11:59:00'],
            ],
        );

        self::assertSame('2016-06-20 11:59:00', $payload['perSite']['default']['postDate']);
    }

    public function testFallsBackToTranslationCreatedWhenColumnMissing(): void
    {
        $payload = $this->runOne(
            nodeClassExtra: ['postDateColumn' => 'date'],
            siteData: [
                'created' => '2016-05-02 20:31:14',
                'detail' => ['id' => 1],
            ],
        );

        self::assertSame('2016-05-02 20:31:14', $payload['perSite']['default']['postDate']);
    }

    public function testFallsBackToTranslationCreatedWhenColumnEmpty(): void
    {
        $payload = $this->runOne(
            nodeClassExtra: ['postDateColumn' => 'date'],
            siteData: [
                'created' => '2016-05-02 20:31:14',
                'detail' => ['id' => 1, 'date' => ''],
            ],
        );

        self::assertSame('2016-05-02 20:31:14', $payload['perSite']['default']['postDate']);
    }

    public function testNoOverrideWhenPostDateColumnUnset(): void
    {
        $payload = $this->runOne(
            nodeClassExtra: [],
            siteData: [
                'created' => '2016-05-02 20:31:14',
                'detail' => ['id' => 1, 'date' => '2016-06-20 11:59:00'],
            ],
        );

        self::assertSame('2016-05-02 20:31:14', $payload['perSite']['default']['postDate']);
    }

    public function testReturnsNullWhenNeitherSourceAvailable(): void
    {
        $payload = $this->runOne(
            nodeClassExtra: ['postDateColumn' => 'date'],
            siteData: [
                'detail' => ['id' => 1],
            ],
        );

        self::assertNull($payload['perSite']['default']['postDate']);
    }

    public function testDateTimeInstanceIsFormattedToString(): void
    {
        $payload = $this->runOne(
            nodeClassExtra: ['postDateColumn' => 'date'],
            siteData: [
                'created' => '2016-05-02 20:31:14',
                'detail' => ['id' => 1, 'date' => new \DateTimeImmutable('2018-01-15 09:30:00')],
            ],
        );

        self::assertSame('2018-01-15 09:30:00', $payload['perSite']['default']['postDate']);
    }

    /**
     * @param array<string, mixed> $nodeClassExtra extra keys merged into the NewsPage nodeClass spec
     * @param array<string, mixed> $siteData per-site extract payload (online/title/slug auto-stubbed)
     * @return array<string, mixed>
     */
    private function runOne(array $nodeClassExtra, array $siteData): array
    {
        $registry = new FieldHandlerRegistry();
        $registry->register(new PlainTextHandler('plain'));

        $service = new TransformService();
        $service->handlerRegistry = $registry;
        $service->ckeditorRewriter = new CkeditorRewriterService();

        $rows = [
            [
                'fqcn' => 'App\\Entity\\Pages\\NewsPage',
                'kunstmaanSourceId' => 'App_Entity_Pages_NewsPage:13',
                'kuma_node_id' => 13,
                'refIdsByLocale' => ['nl' => 13],
                'perSite' => [
                    'nl' => array_merge([
                        'online' => true,
                        'title' => 'A news entry',
                        'slug' => 'a-news-entry',
                    ], $siteData),
                ],
            ],
        ];
        $mapping = [
            'sites' => ['nl' => 'default'],
            'nodeClasses' => [
                'App\\Entity\\Pages\\NewsPage' => array_merge([
                    'section' => 'newsPage',
                    'fields' => [],
                ], $nodeClassExtra),
            ],
            'sections' => ['newsPage' => ['section' => 'pages', 'entryType' => 'newsPage']],
        ];

        $payloads = iterator_to_array($service->run($rows, $mapping, new MigrationFilters()));
        return $payloads[0];
    }
}
