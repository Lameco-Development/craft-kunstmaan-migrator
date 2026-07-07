<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\load;

use craft\elements\Entry;
use Generator;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\payload\FixupService;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\payload\SchemaGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/_craft_shim.php';

/**
 * Deterministic fake for section/entryType/site lookups — same convention as
 * `PayloadEntrySaverTest`'s `SaverFakeSchemaGateway`, extended with the
 * `pageBuilder` matrix field this file's nested-ref tests need.
 */
final class FixupFakeSchemaGateway implements SchemaGateway
{
    public function sectionByHandle(string $handle): ?array
    {
        return $handle === 'pages' ? ['id' => 1, 'handle' => 'pages'] : null;
    }

    public function entryTypeByHandle(string $handle): ?array
    {
        return $handle === 'contentPage' ? ['id' => 1, 'handle' => 'contentPage', 'hasTitleFormat' => false] : null;
    }

    public function siteByHandle(string $handle): ?array
    {
        return $handle === 'en' ? ['id' => 1, 'handle' => 'en'] : null;
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        return $entryTypeHandle === 'contentPage' ? ['relatedPages', 'pageBuilder'] : [];
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        return $fieldHandle === 'pageBuilder' ? ['contentBlock'] : [];
    }
}

/**
 * In-memory state-table stand-in — same DB-touching-primitive-only override
 * convention as `PayloadEntrySaverTest`'s `InMemoryMigrationStateService`,
 * plus `entryRows()` (the one additional read primitive `FixupService` needs
 * that pass 1 never did) backed by the same array store.
 */
final class FixupInMemoryMigrationStateService extends MigrationStateService
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    private function rowKey(string $source, string $key, ?int $siteId): string
    {
        return $source . "\0" . $key . "\0" . ($siteId ?? '');
    }

    public function get(string $source, string $key, ?int $siteId = null): ?array
    {
        return $this->rows[$this->rowKey($source, $key, $siteId)] ?? null;
    }

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        $row = $this->get($source, $key, $siteId);

        return ($row !== null && $row['targetId'] !== null) ? (int) $row['targetId'] : null;
    }

    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
    {
        return $this->get($source, $key, $siteId)['targetUid'] ?? null;
    }

    public function record(
        string $source,
        string $key,
        string $targetType,
        int $targetId,
        ?string $targetUid = null,
        ?int $siteId = null,
        ?array $meta = null,
    ): void {
        $rowKey = $this->rowKey($source, $key, $siteId);
        $existing = $this->rows[$rowKey] ?? null;
        $this->rows[$rowKey] = [
            'source' => $source,
            'sourceKey' => $key,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetUid' => $targetUid ?? '',
            'siteId' => $siteId,
            'meta' => $meta !== null ? $meta : ($existing['meta'] ?? null),
        ];
    }

    public function updateMeta(string $source, string $key, ?int $siteId, array $meta): void
    {
        $rowKey = $this->rowKey($source, $key, $siteId);
        if (!isset($this->rows[$rowKey])) {
            return;
        }
        $current = $this->rows[$rowKey]['meta'] ?? [];
        if (!is_array($current)) {
            $current = [];
        }
        $this->rows[$rowKey]['meta'] = array_merge($current, $meta);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function entryRows(): Generator
    {
        foreach ($this->rows as $row) {
            if (($row['targetType'] ?? null) === 'entry') {
                yield $row;
            }
        }
    }
}

/**
 * Fake collaborator standing in for both save boundaries this file touches:
 * `saveEntryForSites()` (pass 1 — same shape as `PayloadEntrySaverTest`'s
 * `FakeEntryMigrationService`, so a real `PayloadEntrySaver` produces genuine
 * `pendingRefs`) and the three new Task 5 methods
 * (`readEntryFieldValueForSite`/`resaveEntryFieldForSite`/
 * `resaveEntryParentForSite`), backed by a simple per-(entryId, site) array
 * store standing in for a real Craft entry's serialized field values.
 */
final class FixupFakeEntryMigrationService extends EntryMigrationService
{
    private int $nextId = 1000;

    /** @var array<string, array<string, mixed>> keyed by "entryId\0site" */
    private array $fieldStore = [];

    /** @var array<string, int> keyed by "entryId\0site" */
    public array $parentStore = [];

    public function saveEntryForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        bool $force = false,
        ?MigrationReport $report = null,
    ): Entry {
        /** @var Entry $entry */
        $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();

        $existingId = $this->stateService->getTargetId($stateSource, (string) $stateKey);
        if ($existingId !== null) {
            $entry->id = $existingId;
            $entry->uid = (string) $this->stateService->getTargetUid($stateSource, (string) $stateKey);
        } else {
            $id = $this->nextId++;
            $entry->id = $id;
            $entry->uid = 'fake-uid-' . $id;
            $this->stateService->record($stateSource, (string) $stateKey, 'entry', $id, $entry->uid);
        }

        foreach ($perSite as $site => $data) {
            $this->fieldStore[$this->key((int) $entry->id, (string) $site)] = (array) ($data['fieldValues'] ?? []);
        }

        return $entry;
    }

    public function readEntryFieldValueForSite(int $entryId, string $siteHandle, string $fieldHandle): ?array
    {
        $value = $this->fieldStore[$this->key($entryId, $siteHandle)][$fieldHandle] ?? null;

        return is_array($value) ? $value : null;
    }

    public function resaveEntryFieldForSite(int $entryId, string $siteHandle, string $fieldHandle, array $value): bool
    {
        $this->fieldStore[$this->key($entryId, $siteHandle)][$fieldHandle] = $value;

        return true;
    }

    public function resaveEntryParentForSite(int $entryId, string $siteHandle, int $parentId): bool
    {
        $this->parentStore[$this->key($entryId, $siteHandle)] = $parentId;

        return true;
    }

    private function key(int $entryId, string $site): string
    {
        return $entryId . "\0" . $site;
    }
}

final class FixupTest extends TestCase
{
    private function makeSaver(EntryMigrationService $entryService, MigrationStateService $stateService): PayloadEntrySaver
    {
        return new PayloadEntrySaver(
            new FixupFakeSchemaGateway(),
            $entryService,
            $stateService,
            static fn (callable $fn) => $fn(),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payloadArray(string $sourceUid, array $overrides = []): array
    {
        return array_replace_recursive([
            'sourceUid' => $sourceUid,
            'section' => 'pages',
            'entryType' => 'contentPage',
            'sites' => [
                'en' => [
                    'enabled' => true,
                    'title' => 'Entry ' . $sourceUid,
                    'slug' => 'entry-' . str_replace(':', '-', $sourceUid),
                    'fieldValues' => [],
                ],
            ],
        ], $overrides);
    }

    public function testFlatUnresolvedRefIsOrphanBeforeTargetExistsThenPatchesOnceTargetExists(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService);

        // A references B (kuma:COM:nt_page:999), which does not exist yet.
        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:100', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:999']],
            ]]],
        ]));
        $result = $saver->save($a);
        $entryAId = $result->entryId;

        $report = $fixup->run();

        self::assertSame(0, $report['patched']);
        self::assertSame([[
            'sourceUid' => 'kuma:COM:nt_page:100',
            'field' => 'relatedPages',
            'ref' => 'kuma:COM:nt_page:999',
            'path' => ['relatedPages'],
        ]], $report['orphans']);

        // B is now loaded.
        $state->record('COM:nt_page', '999', 'entry', 555);

        $report = $fixup->run();

        self::assertSame(1, $report['patched']);
        self::assertSame([], $report['orphans']);
        self::assertSame(
            [555],
            $entryService->readEntryFieldValueForSite($entryAId, 'en', 'relatedPages'),
            "A's relation field on site en must now contain B's resolved id.",
        );
    }

    public function testNestedRefInsideMatrixBlockPatchesIntoTheExactNestedSlot(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:101', [
            'sites' => ['en' => ['fieldValues' => [
                'pageBuilder' => [[
                    'type' => 'contentBlock',
                    'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:nt_page:900']]],
                ]],
            ]]],
        ]));
        $result = $saver->save($a);
        $entryAId = $result->entryId;

        $before = $fixup->run();
        self::assertSame(0, $before['patched']);
        self::assertCount(1, $before['orphans']);
        self::assertSame(['pageBuilder', 0, 'fields', 'relatedEntries'], $before['orphans'][0]['path']);

        $state->record('COM:nt_page', '900', 'entry', 777);

        $after = $fixup->run();

        self::assertSame(1, $after['patched']);
        self::assertSame([], $after['orphans']);

        $pageBuilder = $entryService->readEntryFieldValueForSite($entryAId, 'en', 'pageBuilder');
        self::assertSame(
            [777],
            $pageBuilder[0]['fields']['relatedEntries'],
            'The resolved id must land in the nested relatedEntries slot inside the pageBuilder block, not a top-level field.',
        );
    }

    public function testParentRefPatchesEntryParentWhenPathIsEmpty(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:102', [
            'sites' => ['en' => ['parentRef' => 'kuma:COM:nt_page:5']],
        ]));
        $result = $saver->save($a);
        $entryAId = $result->entryId;

        $before = $fixup->run();
        self::assertSame(0, $before['patched']);
        self::assertSame([[
            'sourceUid' => 'kuma:COM:nt_page:102',
            'field' => 'parentId',
            'ref' => 'kuma:COM:nt_page:5',
            'path' => [],
        ]], $before['orphans']);

        $state->record('COM:nt_page', '5', 'entry', 42);
        $report = $fixup->run();

        self::assertSame(1, $report['patched']);
        self::assertSame([], $report['orphans']);
        self::assertSame(42, $entryService->parentStore[$entryAId . "\0" . 'en']);
    }

    public function testMultipleSimultaneouslyUnresolvedRefsInSameContainerAreBothPatchedNotDropped(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:103', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [
                    ['_ref' => 'kuma:COM:nt_page:901'],
                    ['_ref' => 'kuma:COM:nt_page:902'],
                ],
            ]]],
        ]));
        $result = $saver->save($a);
        $entryAId = $result->entryId;

        $state->record('COM:nt_page', '901', 'entry', 111);
        $state->record('COM:nt_page', '902', 'entry', 222);

        $report = $fixup->run();

        self::assertSame(2, $report['patched']);
        self::assertSame([], $report['orphans']);
        $ids = $entryService->readEntryFieldValueForSite($entryAId, 'en', 'relatedPages');
        sort($ids);
        self::assertSame([111, 222], $ids, 'Neither ref may be silently dropped, even though both share one container.');
    }

    public function testRunIsIdempotentWhenNoStateRowsHavePendingRefs(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService);

        $saver->save(Payload::fromArray($this->payloadArray('kuma:COM:nt_page:104')));

        $report = $fixup->run();

        self::assertSame(['patched' => 0, 'orphans' => []], $report);
    }
}
