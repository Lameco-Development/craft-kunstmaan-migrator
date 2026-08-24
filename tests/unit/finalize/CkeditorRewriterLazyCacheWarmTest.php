<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\finalize;

use Generator;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/integration/load/_craft_shim.php';

/**
 * Exercises the lazy cache-warming and legacy-DB classification paths that
 * the seeded-cache tests deliberately bypass: state-driven [M] and URL cache
 * warming, kuma_media URL lookup via the legacy DB, and the out-of-scope
 * classification queries for unresolved [M]/[NT] tokens.
 */
final class CkeditorRewriterLazyCacheWarmTest extends TestCase
{
    public function testMediaPlaceholderResolvesViaStateWarmedCache(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new class extends MigrationStateService {
            public function all(string $source): Generator
            {
                TestCase::assertSame('media', $source);
                yield ['targetType' => 'asset', 'targetId' => 900, 'sourceKey' => 'kuma_media:5'];
                yield ['targetType' => 'entry', 'targetId' => 1, 'sourceKey' => 'kuma_media:9'];
                yield ['targetType' => 'asset', 'targetId' => 2, 'sourceKey' => 'not-media:3'];
            }
        };

        $out = $svc->rewrite('<p><a href="[M5]">Download</a></p>', 3);

        self::assertStringContainsString('{asset:900@3:url}', $out);
        self::assertSame([], $svc->consumeUnresolvedDiagnostics());
    }

    public function testUnresolvedMediaPlaceholderClassifiesHtmlRowsOutOfScope(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->legacyDb = new class extends LegacyDbService {
            public function queryOne(string $sql, array $params = []): ?array
            {
                TestCase::assertStringContainsString('kuma_media', $sql);
                return match ($params[':id']) {
                    6 => ['content_type' => 'text/html', 'url' => '/uploads/media/6/page.htm'],
                    7 => null,
                    default => TestCase::fail('unexpected media id'),
                };
            }
        };

        $out = $svc->rewrite('<p>[M6] [M7]</p>', 2);

        // HTML media row: out of asset scope — token kept verbatim, no marker.
        self::assertStringContainsString('[M6]', $out);
        // Unknown row: unresolved — marker appended for the finalize gate.
        self::assertStringContainsString('[M7]', $out);
        self::assertStringContainsString('MIGRATION:UNRESOLVED', $out);

        $outOfScope = $svc->consumeOutOfScopeDiagnostics();
        self::assertCount(1, $outOfScope);
        self::assertSame('media', $outOfScope[0]['tokenFamily']);
        self::assertSame(6, $outOfScope[0]['legacyId']);

        $unresolved = $svc->consumeUnresolvedDiagnostics();
        self::assertCount(1, $unresolved);
        self::assertSame(7, $unresolved[0]['legacyId']);
    }

    public function testUnresolvedNodeTranslationClassifiesOfflineAndDeletedOutOfScope(): void
    {
        $svc = new CkeditorRewriterService();
        // migrationState stays null: warmNtCache short-circuits to an empty
        // cache, forcing every NT token down the classification path.
        $svc->legacyDb = new class extends LegacyDbService {
            public function queryOne(string $sql, array $params = []): ?array
            {
                TestCase::assertStringContainsString('kuma_node_translations', $sql);
                return match ($params[':id']) {
                    80 => ['translation_online' => 0, 'node_deleted' => 0],
                    81 => ['translation_online' => 1, 'node_deleted' => 1],
                    82 => ['translation_online' => 1, 'node_deleted' => 0],
                    default => TestCase::fail('unexpected nt id'),
                };
            }
        };

        $out = $svc->rewrite('<p>[NT80] [NT81] [NT82]</p>', 1);

        $outOfScope = $svc->consumeOutOfScopeDiagnostics();
        self::assertSame([80, 81], array_column($outOfScope, 'legacyId'));
        self::assertStringContainsString('offline', $outOfScope[0]['reason']);
        self::assertStringContainsString('deleted', $outOfScope[1]['reason']);

        // Live translation with no migrated entry: genuinely unresolved.
        $unresolved = $svc->consumeUnresolvedDiagnostics();
        self::assertSame([82], array_column($unresolved, 'legacyId'));
        self::assertStringContainsString('[NT82]<!-- MIGRATION:UNRESOLVED', $out);
    }

    public function testMediaUrlResolvesViaStateMetaAndLegacyDbLookup(): void
    {
        $svc = new CkeditorRewriterService();
        $svc->migrationState = new class extends MigrationStateService {
            public function all(string $source): Generator
            {
                yield [
                    'targetType' => 'asset',
                    'targetId' => 111,
                    'sourceKey' => 'kuma_media:100',
                    'meta' => json_encode(['originalUrl' => '/uploads/media/100/report.pdf']),
                ];
            }
        };
        $svc->legacyDb = new class extends LegacyDbService {
            public function queryOne(string $sql, array $params = []): ?array
            {
                TestCase::assertStringContainsString('kuma_media WHERE url IN', $sql);
                return in_array('/uploads/media/200/photo.jpg', $params, true) ? ['id' => 7] : null;
            }
        };
        $svc->seedKumaMediaIdCache([7 => 777]);

        $html = '<p>'
            . '<a href="/uploads/media/100/report.pdf">state-warmed</a>'
            . '<img src="/uploads/media/200/photo.jpg?v=2">'
            . '<img src="/uploads/media/300/missing.png">'
            . '</p>';
        $out = $svc->rewrite($html, 4);

        // Warmed from state meta.originalUrl.
        self::assertStringContainsString('href="{asset:111@4:url}"', $out);
        // Resolved via legacy-DB URL lookup (query string stripped) + kuma id cache.
        self::assertStringContainsString('src="{asset:777@4:url}"', $out);
        // Unknown URL: unresolved marker + diagnostic token carries the path.
        self::assertStringContainsString('/uploads/media/300/missing.png', $out);
        $unresolved = $svc->consumeUnresolvedDiagnostics();
        self::assertSame(['media_url'], array_unique(array_column($unresolved, 'tokenFamily')));
        self::assertSame('/uploads/media/300/missing.png', $unresolved[0]['token']);
    }
}
