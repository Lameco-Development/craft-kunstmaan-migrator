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
}

/** @internal */
final class MetaFakeMigrationStateService extends MigrationStateService
{
    /** @var list<array<string, mixed>> */
    public array $persisted = [];

    public function __construct(private readonly ?array $row)
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
    }
}
