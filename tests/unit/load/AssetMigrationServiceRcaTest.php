<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\AssetMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Plan 04-12 Task 06 — characterization tests for the D-66 closed-set RCA
 * reason classifier in AssetMigrationService::classifyAssetFailureReason().
 *
 * Classifier is private; we exercise it via Reflection to avoid widening
 * production surface for testability. The closed set is:
 *   filesystem_404 | mime_mismatch | too_large | deferred_unresolved.
 *
 * String-matching is intentionally loose per the source comment — operators
 * grep REPORT.md by reason, so over-classification is preferable to dropping
 * into the catch-all 'deferred_unresolved' bucket too eagerly.
 */
final class AssetMigrationServiceRcaTest extends TestCase
{
    /**
     * @param array<string, mixed> $row
     */
    private function classify(\Throwable $e, array $row = []): string
    {
        $svc = new AssetMigrationService();
        // PHP 8.1+: private methods are accessible via Reflection by default;
        // ReflectionMethod::setAccessible() is deprecated since PHP 8.5.
        $m = new ReflectionMethod($svc, 'classifyAssetFailureReason');
        return (string) $m->invoke($svc, $e, $row);
    }

    public function testNotFoundExceptionMapsToFilesystem404(): void
    {
        $this->assertSame('filesystem_404', $this->classify(new RuntimeException('No such file or directory')));
        $this->assertSame('filesystem_404', $this->classify(new RuntimeException('asset not found')));
        $this->assertSame('filesystem_404', $this->classify(new RuntimeException('Copy failed for /path')));
    }

    public function testMimeWordingMapsToMimeMismatch(): void
    {
        $this->assertSame('mime_mismatch', $this->classify(new RuntimeException('invalid mime')));
        $this->assertSame('mime_mismatch', $this->classify(new RuntimeException('content_type unknown')));
        $this->assertSame('mime_mismatch', $this->classify(new RuntimeException('allowedFileExtensions does not permit')));
    }

    public function testTooLargeMapsToTooLarge(): void
    {
        $this->assertSame('too_large', $this->classify(new RuntimeException('file too large')));
        $this->assertSame('too_large', $this->classify(new RuntimeException('PostMaxSize exceeded')));
    }

    public function testFallbackMapsToDeferredUnresolved(): void
    {
        $this->assertSame('deferred_unresolved', $this->classify(new RuntimeException('something else')));
        $this->assertSame('deferred_unresolved', $this->classify(new RuntimeException('')));
    }
}
