<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use PHPUnit\Framework\TestCase;

/**
 * MySQL hands the `meta` JSON column back as a string.
 *
 * `get()` returned the raw row, and every caller that tested `is_array($row['meta'])`
 * read a written row as having none: Berkvens FR's re-run refused every submission
 * group it had written itself. Only the database read is faked here; `get()`,
 * `record()` and `updateMeta()` are the real ones.
 */
final class MigrationStateServiceMetaTest extends TestCase
{
    private function state(?array $row): MetaFakeMigrationStateService
    {
        return new MetaFakeMigrationStateService($row);
    }

    public function testGetDecodesTheMetaTheDatabaseHandsBackAsAString(): void
    {
        $row = $this->state(['id' => 1, 'targetId' => 9, 'meta' => '{"archived":true,"fieldMap":{"P1":{"handle":"naam"}}}'])
            ->get('form', 'kuma:FR:form:FormPage:3');

        self::assertSame(['archived' => true, 'fieldMap' => ['P1' => ['handle' => 'naam']]], $row['meta'] ?? null);
    }

    public function testMetaThatIsAbsentOrNotAnObjectIsNull(): void
    {
        self::assertSame(['id' => 1, 'meta' => null], $this->state(['id' => 1, 'meta' => null])->get('a', 'b'));
        self::assertSame(['id' => 1, 'meta' => null], $this->state(['id' => 1, 'meta' => 'not json'])->get('a', 'b'));
        self::assertNull($this->state(null)->get('a', 'b'));
    }

    /**
     * A re-record without new meta writes the existing meta back. Handed the string,
     * Yii's JSON typecast stored it as a JSON string literal — meta nothing could read.
     */
    public function testARerecordWithoutMetaWritesTheExistingMetaBackAsAnArray(): void
    {
        $state = $this->state(['id' => 1, 'targetId' => 9, 'targetUid' => null, 'meta' => '{"handle":"kumaFrFormpage3"}']);
        $state->record('form', 'kuma:FR:form:FormPage:3', 'formie_form', 9);

        self::assertSame(['handle' => 'kumaFrFormpage3'], $state->persisted[0]['existing']['meta']);
    }

    /**
     * Xidoor ticket 23, gap E: a `--force` save re-records every entry with no meta, and
     * `persistRecord()` wrote the raw JSON string back, which Yii's JSON typecast stored as
     * a string scalar. `structurePlaced` was then gone, the order pass took every member as
     * unplaced, and a hand-dragged brand order went back to the legacy order. The fake
     * stores what Yii would: the value `persistRecord()` is handed, JSON-encoded.
     */
    public function testStructurePlacedSurvivesTheReRecordOfAForceRun(): void
    {
        $state = new MetaFakeMigrationStateService(
            ['id' => 1, 'targetId' => 47, 'targetUid' => 'uid-47', 'meta' => '{"structurePlaced":true,"blockIds":{"xidoorEn":[5]}}'],
            storesWrites: true,
        );

        $state->record('XI:kuma_nodes', '47', 'entry', 47, 'uid-47');
        $state->record('XI:kuma_nodes', '47', 'entry', 47, 'uid-47');

        self::assertSame(['structurePlaced' => true, 'blockIds' => ['xidoorEn' => [5]]], $state->get('XI:kuma_nodes', '47')['meta'] ?? null);
    }
}

/** @internal */
final class MetaFakeMigrationStateService extends MigrationStateService
{
    /** @var list<array<string, mixed>> */
    public array $persisted = [];

    public function __construct(private ?array $row, private readonly bool $storesWrites = false)
    {
    }

    protected function fetchRow(string $source, string $key, ?int $siteId): ?array
    {
        return $this->row;
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
        $this->persisted[] = ['existing' => $existing, 'meta' => $meta];

        if ($this->storesWrites && $this->row !== null) {
            // Yii's JSON column typecast: whatever it is handed, encoded — a string included.
            $this->row['meta'] = json_encode($meta !== null ? $meta : $existing['meta'] ?? null);
        }
    }
}
