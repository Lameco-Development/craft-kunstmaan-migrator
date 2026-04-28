<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\load;

use lameco\kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\TestCase;

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
}
