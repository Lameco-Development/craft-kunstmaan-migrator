<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use PHPUnit\Framework\TestCase;

/**
 * Task 4 review — record()'s collision guard: an alias sourceUid (or any
 * other caller) that collides with a row already recorded under a
 * DIFFERENT targetId must not silently repoint that row (last-write-wins),
 * it must skip the overwrite and warn instead. A re-record against the SAME
 * targetId must remain idempotent (no warning, and — in this fake — still
 * reaches the write step).
 *
 * `get()` and `warn()` are the only primitives overridden below — `record()`
 * itself (the guard under test) and `collidesWithDifferentTarget()` are
 * inherited UNCHANGED and genuinely exercised, matching the
 * InMemoryMigrationStateService fakeable-primitive convention used in
 * tests/integration/load/PayloadEntrySaverTest.php. `persistRecord()` is
 * overridden too, purely so a non-colliding call doesn't need a booted
 * Craft application to reach the DB write.
 */
final class RecordCollisionFakeMigrationStateService extends MigrationStateService
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array<string, mixed>> */
    public array $persisted = [];

    public function __construct(private readonly ?array $existingRow)
    {
    }

    public function get(string $source, string $key, ?int $siteId = null): ?array
    {
        return $this->existingRow;
    }

    protected function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    protected function persistRecord(
        ?array $existing,
        string $source,
        string $key,
        string $targetType,
        int $targetId,
        string $targetUidSafe,
        ?int $siteId,
        ?array $meta,
    ): void {
        $this->persisted[] = [
            'existing' => $existing,
            'source' => $source,
            'key' => $key,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetUidSafe' => $targetUidSafe,
            'siteId' => $siteId,
            'meta' => $meta,
        ];
    }
}

final class MigrationStateServiceRecordCollisionTest extends TestCase
{
    public function testRecordSkipsOverwriteAndWarnsWhenExistingRowHasADifferentTargetId(): void
    {
        $svc = new RecordCollisionFakeMigrationStateService([
            'id' => 1,
            'targetId' => 42,
            'meta' => ['alias_of' => 'kuma:COM:nt_page:1'],
        ]);

        $svc->record('DE:nt_page', '87', 'entry', 99);

        self::assertSame([], $svc->persisted, 'A colliding record() call must never reach the DB write.');
        self::assertCount(1, $svc->warnings);
        self::assertStringContainsString('DE:nt_page', $svc->warnings[0]);
        self::assertStringContainsString('87', $svc->warnings[0]);
        self::assertStringContainsString('42', $svc->warnings[0]);
        self::assertStringContainsString('99', $svc->warnings[0]);
    }

    public function testRecordIsIdempotentWithNoWarningWhenReRecordingTheSameTargetId(): void
    {
        $svc = new RecordCollisionFakeMigrationStateService([
            'id' => 1,
            'targetId' => 42,
            'meta' => ['alias_of' => 'kuma:COM:nt_page:1'],
        ]);

        $svc->record('DE:nt_page', '87', 'entry', 42);

        self::assertSame([], $svc->warnings, 'A same-target re-record must not warn.');
        self::assertCount(1, $svc->persisted, 'A same-target re-record must still proceed to the write step.');
        self::assertSame(42, $svc->persisted[0]['targetId']);
    }

    public function testRecordProceedsNormallyWhenNoExistingRowIsPresent(): void
    {
        $svc = new RecordCollisionFakeMigrationStateService(null);

        $svc->record('DE:nt_page', '87', 'entry', 42);

        self::assertSame([], $svc->warnings);
        self::assertCount(1, $svc->persisted);
        self::assertNull($svc->persisted[0]['existing']);
    }
}
