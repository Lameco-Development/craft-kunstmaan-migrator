<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\extract;

use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\extract\ExtractService;
use lameco\kunstmaanmigrator\source\DetailTableResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ExtractServicePagePartAllowListTest extends TestCase
{
    public const HOME_FQCN = 'App\\Entity\\Pages\\HomePage';
    public const ALLOWED_PART = 'App\\Entity\\PageParts\\NewsPagePart';
    public const DISALLOWED_PART = 'App\\Entity\\PageParts\\SidebarPagePart';

    public function testPagePartAllowMapUsesScannedPageEntityContextsAndClasses(): void
    {
        $service = new ExtractService();
        $service->pageStructureSnapshot = [
            self::HOME_FQCN => [
                'contexts' => [
                    [
                        'name' => 'home',
                        'allowedPagePartClasses' => [
                            ['class' => self::ALLOWED_PART, 'table' => 'news_page_parts'],
                        ],
                    ],
                ],
            ],
        ];

        $allowMap = $this->invokeAllowMap($service, self::HOME_FQCN, []);

        self::assertSame(['home'], array_keys($allowMap));
        self::assertSame([self::ALLOWED_PART => true], $allowMap['home']);
    }

    public function testLoadPagePartsKeepsOnlyAllowedPageEntityRegionsAndClasses(): void
    {
        $service = new ExtractService();
        $service->legacyDb = new PagePartAllowListDbStub();
        $resolver = new DetailTableResolver();
        $resolver->overrides = [
            self::ALLOWED_PART => 'allowed_page_parts',
            self::DISALLOWED_PART => 'disallowed_page_parts',
        ];
        $service->detailTableResolver = $resolver;

        $parts = $this->invokeLoadPageParts($service, 1, self::HOME_FQCN, [
            'home' => [self::ALLOWED_PART => true],
        ]);

        self::assertCount(1, $parts);
        self::assertSame(self::ALLOWED_PART, $parts[0]['fqcn']);
        self::assertSame('home', $parts[0]['context']);
        self::assertSame(11, $parts[0]['sourcePartId']);
    }

    public function testLoadPagePartsPreservesLegacyBehaviorWithoutAllowMap(): void
    {
        $service = new ExtractService();
        $service->legacyDb = new PagePartAllowListDbStub();
        $resolver = new DetailTableResolver();
        $resolver->overrides = [
            self::ALLOWED_PART => 'allowed_page_parts',
            self::DISALLOWED_PART => 'disallowed_page_parts',
        ];
        $service->detailTableResolver = $resolver;

        $parts = $this->invokeLoadPageParts($service, 1, self::HOME_FQCN, null);

        self::assertCount(3, $parts);
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, array<string, true>|null>
     */
    private function invokeAllowMap(ExtractService $service, string $fqcn, array $mapping): array
    {
        $method = new ReflectionMethod(ExtractService::class, 'pagePartAllowMapFor');
        /** @var array<string, array<string, true>|null> $result */
        $result = $method->invoke($service, $fqcn, $mapping);
        return $result;
    }

    /**
     * @param array<string, array<string, true>|null>|null $allowMap
     * @return list<array<string, mixed>>
     */
    private function invokeLoadPageParts(
        ExtractService $service,
        int $refId,
        string $fqcn,
        ?array $allowMap,
    ): array {
        $method = new ReflectionMethod(ExtractService::class, 'loadPageParts');
        /** @var list<array<string, mixed>> $result */
        $result = $method->invoke($service, $refId, $fqcn, $allowMap);
        return $result;
    }
}

final class PagePartAllowListDbStub extends LegacyDbService
{
    /** @return array<int, array<string, mixed>> */
    public function queryAll(string $sql, array $params = []): array
    {
        return [
            [
                'context' => 'home',
                'sequencenumber' => 1,
                'page_part_id' => 11,
                'page_part_entityname' => ExtractServicePagePartAllowListTest::ALLOWED_PART,
            ],
            [
                'context' => 'left_column',
                'sequencenumber' => 2,
                'page_part_id' => 12,
                'page_part_entityname' => ExtractServicePagePartAllowListTest::ALLOWED_PART,
            ],
            [
                'context' => 'home',
                'sequencenumber' => 3,
                'page_part_id' => 13,
                'page_part_entityname' => ExtractServicePagePartAllowListTest::DISALLOWED_PART,
            ],
        ];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return [
            'id' => (int) ($params[':id'] ?? 0),
            'title' => 'Page part ' . (string) ($params[':id'] ?? ''),
        ];
    }
}
