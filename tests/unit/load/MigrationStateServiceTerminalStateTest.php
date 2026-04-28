<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\MigrationStateService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 4.1 / Plan 04.1-07 / Task 2 — characterization tests for the D-37
 * terminal-state marker primitives on MigrationStateService.
 *
 * The sync-assets recovery command (REC-01) uses these helpers to skip rows
 * that have been classified as permanently failed (filesystem_404 / too_large)
 * so a re-run never retries them. The contract:
 *
 * - markTerminal() writes three keys into the existing meta JSON column via
 *   updateMeta() — no schema migration (D-37 / PATTERNS recommendation b).
 *   Keys: terminalState='permanently_failed', terminalReason=$reason,
 *   terminalAt=ISO-8601 UTC timestamp.
 * - isTerminal() reads the meta JSON and returns true when terminalState
 *   matches the sentinel; false for missing rows or rows without the marker.
 *
 * The DB-touching surface (markTerminal / isTerminal) is exercised at smoke
 * time. The pure-helper surface (buildTerminalMeta / isTerminalMarker) is
 * exercised here via Reflection — same pattern as
 * AssetMigrationServiceRcaTest::classify(). No Craft bootstrap needed.
 */
final class MigrationStateServiceTerminalStateTest extends TestCase
{
    /** @return array<string, mixed> */
    private function buildMeta(string $reason): array
    {
        $svc = new MigrationStateService();
        $m = new ReflectionMethod($svc, 'buildTerminalMeta');
        $result = $m->invoke($svc, $reason);
        self::assertIsArray($result);
        /** @var array<string, mixed> $result */
        return $result;
    }

    private function isTerminalMarker(mixed $meta): bool
    {
        $svc = new MigrationStateService();
        $m = new ReflectionMethod($svc, 'isTerminalMarker');
        return (bool) $m->invoke($svc, $meta);
    }

    public function testBuildTerminalMetaCarriesPermanentlyFailedSentinel(): void
    {
        $meta = $this->buildMeta('filesystem_404 — file gone');

        self::assertArrayHasKey('terminalState', $meta);
        self::assertSame('permanently_failed', $meta['terminalState']);
    }

    public function testBuildTerminalMetaPreservesReasonString(): void
    {
        $meta = $this->buildMeta('filesystem_404 — file gone');

        self::assertArrayHasKey('terminalReason', $meta);
        self::assertSame('filesystem_404 — file gone', $meta['terminalReason']);
    }

    public function testBuildTerminalMetaCarriesIsoUtcTimestamp(): void
    {
        $meta = $this->buildMeta('too_large');

        self::assertArrayHasKey('terminalAt', $meta);
        self::assertIsString($meta['terminalAt']);
        // ISO-8601 UTC: YYYY-MM-DDTHH:MM:SSZ
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $meta['terminalAt'],
        );
    }

    public function testIsTerminalMarkerTrueForArrayShape(): void
    {
        self::assertTrue($this->isTerminalMarker([
            'terminalState' => 'permanently_failed',
            'terminalReason' => 'filesystem_404',
            'terminalAt' => '2026-04-26T10:00:00Z',
        ]));
    }

    public function testIsTerminalMarkerTrueForJsonStringShape(): void
    {
        // Defensive path — Yii's MySQL JSON-column reader returns arrays, but a
        // row written by a different path may hand back a raw JSON string.
        $json = json_encode([
            'terminalState' => 'permanently_failed',
            'terminalReason' => 'too_large',
        ]);
        self::assertNotFalse($json);
        self::assertTrue($this->isTerminalMarker($json));
    }

    public function testIsTerminalMarkerFalseForNullMeta(): void
    {
        self::assertFalse($this->isTerminalMarker(null));
    }

    public function testIsTerminalMarkerFalseForEmptyString(): void
    {
        self::assertFalse($this->isTerminalMarker(''));
    }

    public function testIsTerminalMarkerFalseForArrayWithoutMarker(): void
    {
        self::assertFalse($this->isTerminalMarker([
            'ownerEntity' => 'blogPosts',
            'lastError' => 'transient network blip',
        ]));
    }

    public function testIsTerminalMarkerFalseForJunkScalar(): void
    {
        self::assertFalse($this->isTerminalMarker('not json at all'));
    }

    public function testIsTerminalMarkerFalseForOtherTerminalStateValue(): void
    {
        // Defensive: only the exact sentinel 'permanently_failed' counts.
        // Future code that wants e.g. 'transiently_failed' must not be treated
        // as terminal by REC-01's candidate filter.
        self::assertFalse($this->isTerminalMarker([
            'terminalState' => 'transiently_failed',
        ]));
    }
}
