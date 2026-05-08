<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\load;

use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationReport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/_craft_shim.php';

/**
 * Phase 10 load-boundary fallback coverage.
 *
 * These tests exercise the pure payload-normalization seams without booting a
 * Craft application. Full saveElement validation is covered in the target
 * rehearsal; the generic fallback rules are deterministic PHP transformations.
 */
final class EntryMigrationServiceTest extends TestCase
{
    public function testMatrixNativeTitleFallbackSynthesizesDeterministicTitleAndPreservesFields(): void
    {
        $service = new EntryMigrationService();

        $normalized = $service->normalizeMatrixPayload([
            'contentBuilder' => [
                'new1' => [
                    'type' => 'genericTextBlock',
                    'enabled' => true,
                    'fields' => [
                        '_sourcePartRef' => 'GenericTextPart:42',
                        'body' => 'Keep this source body',
                        'ctaLabel' => 'Keep this source CTA',
                    ],
                ],
            ],
        ]);

        self::assertSame(
            'Migrated genericTextBlock block 1 (GenericTextPart:42)',
            $normalized['contentBuilder']['new1']['title'],
        );
        self::assertSame('Keep this source body', $normalized['contentBuilder']['new1']['fields']['body']);
        self::assertSame('Keep this source CTA', $normalized['contentBuilder']['new1']['fields']['ctaLabel']);
        self::assertArrayNotHasKey('_sourcePartRef', $normalized['contentBuilder']['new1']['fields']);
    }

    public function testMatrixNativeTitleFallbackPrefersPeerTitleThenLiftedTitleOrHeading(): void
    {
        $service = new EntryMigrationService();

        $normalized = $service->normalizeMatrixPayload([
            'contentBuilder' => [
                'new1' => [
                    'type' => 'peerTitleBlock',
                    'title' => 'Peer title wins',
                    'fields' => [
                        'title' => 'Nested title loses',
                    ],
                ],
                'new2' => [
                    'type' => 'fieldTitleBlock',
                    'fields' => [
                        'title' => 'Lifted field title',
                    ],
                ],
                'new3' => [
                    'type' => 'headingTitleBlock',
                    'fields' => [
                        'heading' => 'Lifted heading title',
                    ],
                ],
            ],
        ]);

        self::assertSame('Peer title wins', $normalized['contentBuilder']['new1']['title']);
        self::assertSame('Lifted field title', $normalized['contentBuilder']['new2']['title']);
        self::assertSame('Lifted heading title', $normalized['contentBuilder']['new3']['title']);
        self::assertArrayNotHasKey('title', $normalized['contentBuilder']['new1']['fields']);
        self::assertArrayNotHasKey('title', $normalized['contentBuilder']['new2']['fields']);
        self::assertArrayNotHasKey('heading', $normalized['contentBuilder']['new3']['fields']);
    }

    public function testMatrixNativeTitleFallbackCanBeSuppressedForOptionalBodyTitle(): void
    {
        $service = new EntryMigrationService();

        $normalized = $service->normalizeMatrixPayload([
            'contentBuilder' => [
                'new1' => [
                    'type' => 'textContentBlock',
                    'enabled' => true,
                    'fields' => [
                        '_sourcePartRef' => '__implicit_content__|NewsPage|main:78',
                        '_suppressNativeTitleFallback' => true,
                        'ckeditorDefault' => '<p>Article body.</p>',
                    ],
                ],
            ],
        ]);

        self::assertSame('', $normalized['contentBuilder']['new1']['title']);
        self::assertSame('<p>Article body.</p>', $normalized['contentBuilder']['new1']['fields']['ckeditorDefault']);
        self::assertArrayNotHasKey('_sourcePartRef', $normalized['contentBuilder']['new1']['fields']);
        self::assertArrayNotHasKey('_suppressNativeTitleFallback', $normalized['contentBuilder']['new1']['fields']);
    }

    public function testSparseLocalePrimaryFallbackBorrowsBestAvailablePayloadWithoutMutatingSourceSites(): void
    {
        $service = new EntryMigrationService();
        $report = new MigrationReport();
        $method = new ReflectionMethod(EntryMigrationService::class, 'primarySiteDataForSave');

        $perSite = [
            'en' => [
                'enabled' => true,
                'title' => 'English source title',
                'slug' => 'english-source-title',
                'fieldValues' => [
                    'summary' => 'English source summary',
                ],
            ],
        ];
        $original = $perSite;

        $primaryData = $method->invoke(
            $service,
            $perSite,
            'default',
            $report,
            'App\\Entity\\GenericTextPage',
            '1001',
        );

        self::assertSame('English source title', $primaryData['title']);
        self::assertSame('english-source-title', $primaryData['slug']);
        self::assertSame('English source summary', $primaryData['fieldValues']['summary']);
        self::assertSame($original, $perSite, 'Primary-save fallback must not fake a source primary-site payload.');
        self::assertSame(1, $report->counts['fallback.sparse_locale_primary'] ?? 0);
        self::assertSame(0, $report->counts['failed'] ?? 0);
        self::assertStringContainsString('Sparse-locale primary-save fallback', implode("\n", $report->warnings));
        self::assertStringContainsString('primarySite=default', implode("\n", $report->warnings));
        self::assertStringContainsString('fallbackSite=en', implode("\n", $report->warnings));
    }

    public function testSparseLocalePrimaryFallbackOnlyBorrowsMissingNativeValues(): void
    {
        $service = new EntryMigrationService();
        $report = new MigrationReport();
        $method = new ReflectionMethod(EntryMigrationService::class, 'primarySiteDataForSave');

        $primaryData = $method->invoke(
            $service,
            [
                'default' => [
                    'enabled' => false,
                    'title' => '',
                    'slug' => 'primary-slug',
                    'fieldValues' => [
                        'body' => 'Primary body remains source truth',
                    ],
                ],
                'en' => [
                    'enabled' => true,
                    'title' => 'English fallback title',
                    'slug' => 'english-fallback-slug',
                    'fieldValues' => [
                        'body' => 'English body must not overwrite primary body',
                    ],
                ],
            ],
            'default',
            $report,
            'App\\Entity\\GenericTextPage',
            '1002',
        );

        self::assertSame('English fallback title', $primaryData['title']);
        self::assertSame('primary-slug', $primaryData['slug']);
        self::assertSame('Primary body remains source truth', $primaryData['fieldValues']['body']);
        self::assertSame(1, $report->counts['fallback.sparse_locale_primary'] ?? 0);
        self::assertSame(0, $report->counts['failed'] ?? 0);
    }

    /**
     * Mapping rows whose `targetHandle` is `title` or `slug` (the scaffolder
     * marks these `craft_target: builtin_attribute`) must reach the native
     * Entry attribute, not be silently dropped at the fieldValues strip. The
     * extract-supplied native value still wins when both are present.
     */
    public function testFirstNonEmptySkipsNullAndEmptyStringsAndKeepsExtractWinner(): void
    {
        $service = new EntryMigrationService();
        $method = new ReflectionMethod(EntryMigrationService::class, 'firstNonEmpty');

        // Extract supplied a non-empty value → wins.
        self::assertSame('extract title', $method->invoke($service, 'extract title', 'fieldValues title'));

        // Extract empty/whitespace-only → fall through to fieldValues.
        self::assertSame('mapping title', $method->invoke($service, '', 'mapping title'));
        self::assertSame('mapping title', $method->invoke($service, '   ', 'mapping title'));
        self::assertSame('mapping title', $method->invoke($service, null, 'mapping title'));

        // Both absent → null (caller decides default).
        self::assertNull($method->invoke($service, null, null));
        self::assertNull($method->invoke($service, '', ''));

        // Non-string values pass through (e.g. integer ids for parentId/authorId reuse).
        self::assertSame(42, $method->invoke($service, null, 42));
    }
}
