<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\integration\load;

use craft\elements\Entry;
use Generator;
use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\load\FixupService;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use Lameco\Kunstmaanmigrator\load\PayloadEntrySaver;
use Lameco\Kunstmaanmigrator\load\SaveResult;
use Lameco\Kunstmaanmigrator\Payload\Payload;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

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

    public function primarySite(): array
    {
        return ['id' => 1, 'handle' => 'en'];
    }

    public function siteByHandle(string $handle): ?array
    {
        return $handle === 'en' ? ['id' => 1, 'handle' => 'en'] : null;
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        return $entryTypeHandle === 'contentPage' ? ['relatedPages', 'pageBuilder'] : [];
    }

    /** Derived from the same fixtures the other lookups use, so fakes stay consistent. */
    public function fieldSlotsFor(string $entryTypeHandle): array
    {
        $slots = [];

        foreach ($this->fieldHandlesFor($entryTypeHandle) as $handle) {
            $nested = $this->blockTypesFor($entryTypeHandle, $handle);
            $slots[$handle] = [
                'type' => $nested === [] ? 'PlainText' : 'Matrix',
                'required' => false,
                'nested' => $nested,
            ];
        }

        return $slots;
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

    /** A row whose target is not there yet — what a target mid-write looks like. */
    public function recordWithoutTarget(string $source, string $key): void
    {
        $this->rows[$this->rowKey($source, $key, null)] = [
            'source' => $source,
            'sourceKey' => $key,
            'targetType' => 'entry',
            'targetId' => null,
            'targetUid' => '',
            'siteId' => null,
            'meta' => null,
        ];
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

    /** Element saves the fixup pass asked for — what a patch costs. */
    public int $resaves = 0;

    /** Element loads the fixup pass asked for. */
    public int $reads = 0;

    /**
     * @var array<string, true> keyed by "entryId\0site\0field" — set by a
     *   test to make `resaveEntryFieldForSite()` throw for that exact
     *   (entry, site, field), exercising `FixupService::run()`'s per-ref
     *   fail-forward isolation without needing a real Craft save failure.
     */
    public array $throwOnResave = [];

    public function saveEntryForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        SiteMap $sites,
        bool $force = false,
        ?MigrationReport $report = null,
        ?RunTally $tally = null,
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
            $siteId = $sites->siteIdForHandle((string) $site) ?? 0;
            $this->fieldStore[$this->key((int) $entry->id, $siteId)] = (array) ($data['fieldValues'] ?? []);
        }

        return $entry;
    }

    public function readEntryFieldValueForSite(int $entryId, int $siteId, string $fieldHandle): ?array
    {
        $this->reads++;
        $value = $this->fieldStore[$this->key($entryId, $siteId)][$fieldHandle] ?? null;

        return is_array($value) ? $value : null;
    }

    public function resaveEntryFieldForSite(int $entryId, int $siteId, string $fieldHandle, array $value): bool
    {
        if (isset($this->throwOnResave[$this->key($entryId, $siteId) . "\0" . $fieldHandle])) {
            throw new RuntimeException('simulated resave failure');
        }

        $this->resaves++;
        $this->fieldStore[$this->key($entryId, $siteId)][$fieldHandle] = $value;

        return true;
    }

    public function resaveEntryParentForSite(int $entryId, int $siteId, int $parentId): bool
    {
        $this->resaves++;
        $this->parentStore[$this->key($entryId, $siteId)] = $parentId;

        return true;
    }

    /** The fake gateway and the fixup resolver both know one site: `en` = 1. */
    public function key(int $entryId, int|string $site): string
    {
        return $entryId . "\0" . ($site === 'en' ? 1 : $site);
    }
}

final class FixupTest extends TestCase
{
    private function env(): EnvironmentContext
    {
        return EnvironmentFactory::make('COM', ['en' => 'en'], ['en' => [1, 'en-GB', true]]);
    }

    private function save(PayloadEntrySaver $saver, Payload $payload): SaveResult
    {
        return $saver->save($payload, $this->env(), new RunTally());
    }

    private function makeSaver(EntryMigrationService $entryService, MigrationStateService $stateService): PayloadEntrySaver
    {
        return new PayloadEntrySaver(
            new FixupFakeSchemaGateway(),
            $entryService,
            $stateService,
            new AssetMigrationService(),
            new CkeditorRewriterService(),
            static fn(callable $fn) => $fn(),
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
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        // A references B (kuma:COM:nt_page:999), which does not exist yet.
        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:100', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:999']],
            ]]],
        ]));
        $result = $this->save($saver, $a);
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
            $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'),
            "A's relation field on site en must now contain B's resolved id.",
        );

        // A third run must be a true no-op: the drained ref was removed from
        // pendingRefs, so it must not be re-resolved and re-appended.
        $report = $fixup->run();

        self::assertSame(0, $report['patched']);
        self::assertSame([], $report['orphans']);
        self::assertSame(
            [555],
            $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'),
            'A third run must not double-append the already-patched id.',
        );
    }

    public function testNestedRefInsideMatrixBlockPatchesIntoTheExactNestedSlot(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:101', [
            'sites' => ['en' => ['fieldValues' => [
                'pageBuilder' => [[
                    'type' => 'contentBlock',
                    'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:nt_page:900']]],
                ]],
            ]]],
        ]));
        $result = $this->save($saver, $a);
        $entryAId = $result->entryId;

        $before = $fixup->run();
        self::assertSame(0, $before['patched']);
        self::assertCount(1, $before['orphans']);
        self::assertSame(['pageBuilder', 0, 'fields', 'relatedEntries'], $before['orphans'][0]['path']);

        $state->record('COM:nt_page', '900', 'entry', 777);

        $after = $fixup->run();

        self::assertSame(1, $after['patched']);
        self::assertSame([], $after['orphans']);

        $pageBuilder = $entryService->readEntryFieldValueForSite($entryAId, 1, 'pageBuilder');
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
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:102', [
            'sites' => ['en' => ['parentRef' => 'kuma:COM:nt_page:5']],
        ]));
        $result = $this->save($saver, $a);
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
        self::assertSame(42, $entryService->parentStore[$entryService->key($entryAId, 'en')]);
    }

    public function testMultipleSimultaneouslyUnresolvedRefsInSameContainerAreBothPatchedNotDropped(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:103', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [
                    ['_ref' => 'kuma:COM:nt_page:901'],
                    ['_ref' => 'kuma:COM:nt_page:902'],
                ],
            ]]],
        ]));
        $result = $this->save($saver, $a);
        $entryAId = $result->entryId;

        $state->record('COM:nt_page', '901', 'entry', 111);
        $state->record('COM:nt_page', '902', 'entry', 222);

        $report = $fixup->run();

        self::assertSame(2, $report['patched']);
        self::assertSame([], $report['orphans']);
        $ids = $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages');
        sort($ids);
        self::assertSame([111, 222], $ids, 'Neither ref may be silently dropped, even though both share one container.');
    }

    public function testRunIsIdempotentWhenNoStateRowsHavePendingRefs(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $this->save($saver, Payload::fromArray($this->payloadArray('kuma:COM:nt_page:104')));

        $report = $fixup->run();

        self::assertSame(['patched' => 0, 'orphans' => [], 'unresolvable' => 0, 'unresolvableTargets' => []], $report);
    }

    public function testAFullCorpusPassClassifiesATargetWithNoStateRowAsUnresolvableAndStopsWalkingIt(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        // The home page names two nodes whose page type the mapping declares unmapped —
        // and names one of them twice, from two blocks.
        $home = Payload::fromArray($this->payloadArray('kuma:COM:kuma_nodes:1', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:kuma_nodes:22']],
                'pageBuilder' => [
                    ['type' => 'contentBlock', 'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:kuma_nodes:22']]]],
                    ['type' => 'contentBlock', 'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:kuma_nodes:16']]]],
                ],
            ]]],
        ]));
        $this->save($saver, $home);

        $report = $fixup->run(fullCorpus: true);

        self::assertSame(0, $report['patched']);
        self::assertSame([], $report['orphans'], 'An unresolvable ref is not an orphan: orphans are what is still pending.');
        self::assertSame(3, $report['unresolvable']);
        self::assertSame([
            [
                'ref' => 'kuma:COM:kuma_nodes:22',
                'count' => 2,
                'reason' => FixupService::REASON_TARGET_NEVER_MIGRATED,
                'from' => ['kuma:COM:kuma_nodes:1'],
            ],
            [
                'ref' => 'kuma:COM:kuma_nodes:16',
                'count' => 1,
                'reason' => FixupService::REASON_TARGET_NEVER_MIGRATED,
                'from' => ['kuma:COM:kuma_nodes:1'],
            ],
        ], $report['unresolvableTargets'], 'Reported once, grouped by target, most-referenced first.');

        $meta = $state->get('COM:kuma_nodes', '1')['meta'] ?? [];
        self::assertSame([], $meta['pendingRefs']);
        self::assertCount(3, $meta['unresolvableRefs']);
        self::assertSame('kuma:COM:kuma_nodes:22', $meta['unresolvableRefs'][0]['ref']);
        self::assertSame(['relatedPages'], $meta['unresolvableRefs'][0]['path']);
        self::assertSame(FixupService::REASON_TARGET_NEVER_MIGRATED, $meta['unresolvableRefs'][0]['reason']);

        // The next pass has nothing to walk: reported once, not on every run.
        $again = $fixup->run(fullCorpus: true);

        self::assertSame(['patched' => 0, 'orphans' => [], 'unresolvable' => 0, 'unresolvableTargets' => []], $again);
        self::assertSame(0, $entryService->reads, 'A ref that cannot resolve never costs an element load.');
    }

    public function testANarrowedPassLeavesATargetWithNoStateRowPending(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:105', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:999']],
            ]]],
        ]));
        $this->save($saver, $a);

        $report = $fixup->run(fullCorpus: false);

        self::assertSame(0, $report['unresolvable']);
        self::assertCount(1, $report['orphans'], 'A narrowed run cannot tell a forward reference from a missing target.');

        $meta = $state->get('COM:nt_page', '105')['meta'] ?? [];
        self::assertCount(1, $meta['pendingRefs']);
        self::assertArrayNotHasKey('unresolvableRefs', $meta);

        // The target arrives in a later, full run — and the ref, still pending, is patched.
        $state->record('COM:nt_page', '999', 'entry', 555);
        $report = $fixup->run(fullCorpus: true);

        self::assertSame(1, $report['patched']);
        self::assertSame(0, $report['unresolvable']);
    }

    public function testAFullCorpusPassKeepsARecordedTargetWithoutAnIdPending(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:106', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:998']],
            ]]],
        ]));
        $this->save($saver, $a);
        $state->recordWithoutTarget('COM:nt_page', '998');

        $report = $fixup->run(fullCorpus: true);

        self::assertSame(0, $report['unresolvable'], 'A state row without a target id is a target mid-write, not one that never existed.');
        self::assertCount(1, $report['orphans']);
    }

    public function testRefsIntoOneFieldOfOneEntryCostOneReadAndOneSave(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:107', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:901']],
                'pageBuilder' => [
                    ['type' => 'contentBlock', 'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:nt_page:902']]]],
                    ['type' => 'contentBlock', 'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:nt_page:903']]]],
                    ['type' => 'contentBlock', 'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:nt_page:901']]]],
                ],
            ]]],
        ]));
        $entryAId = $this->save($saver, $a)->entryId;

        $state->record('COM:nt_page', '901', 'entry', 111);
        $state->record('COM:nt_page', '902', 'entry', 222);
        $state->record('COM:nt_page', '903', 'entry', 333);

        $report = $fixup->run();

        self::assertSame(4, $report['patched']);
        self::assertSame([], $report['orphans']);
        self::assertSame(2, $entryService->reads, 'One read per (site, top-level field), not one per ref.');
        self::assertSame(2, $entryService->resaves, 'One element save per (site, top-level field), not one per ref.');

        $pageBuilder = $entryService->readEntryFieldValueForSite($entryAId, 1, 'pageBuilder');
        self::assertSame([222], $pageBuilder[0]['fields']['relatedEntries']);
        self::assertSame([333], $pageBuilder[1]['fields']['relatedEntries']);
        self::assertSame([111], $pageBuilder[2]['fields']['relatedEntries']);
        self::assertSame([111], $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'));
    }

    public function testARefWhoseIdIsAlreadyInTheContainerIsDrainedWithoutASave(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:108', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:904']],
            ]]],
        ]));
        $entryAId = $this->save($saver, $a)->entryId;

        // A save landed the id but the meta update behind it did not: the ref is still pending.
        $state->record('COM:nt_page', '904', 'entry', 444);
        $entryService->resaveEntryFieldForSite($entryAId, 1, 'relatedPages', [444]);
        $entryService->resaves = 0;

        $report = $fixup->run();

        self::assertSame(1, $report['patched']);
        self::assertSame(0, $entryService->resaves, 'The stored value already equals the patched one; nothing to save.');
        self::assertSame([444], $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'));
        self::assertSame([], $state->get('COM:nt_page', '108')['meta']['pendingRefs']);
    }

    public function testRefWhoseResaveThrowsIsOrphanedButOtherResolvableRefsInTheSameRunStillGetPatched(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        // A references B (999) — its re-save will be made to throw below.
        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:200', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:999']],
            ]]],
        ]));
        $entryAId = $this->save($saver, $a)->entryId;

        // C references D (998) — this one must patch cleanly in the same run.
        $c = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:201', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:998']],
            ]]],
        ]));
        $entryCId = $this->save($saver, $c)->entryId;

        $state->record('COM:nt_page', '999', 'entry', 555);
        $state->record('COM:nt_page', '998', 'entry', 556);

        $entryService->throwOnResave[$entryAId . "\0" . 1 . "\0" . 'relatedPages'] = true;

        $report = $fixup->run();

        self::assertSame(1, $report['patched'], "C's ref must still be patched even though A's re-save threw.");
        self::assertCount(1, $report['orphans']);
        self::assertSame('kuma:COM:nt_page:200', $report['orphans'][0]['sourceUid']);
        self::assertSame('relatedPages', $report['orphans'][0]['field']);
        self::assertSame('kuma:COM:nt_page:999', $report['orphans'][0]['ref']);
        self::assertArrayHasKey('error', $report['orphans'][0]);
        self::assertNotSame(
            [],
            $report['orphans'],
            'Non-empty orphans is what makes LoadController::exitCodeForFixup() (see LoadControllerTest) return exit 1.',
        );

        self::assertSame(
            [556],
            $entryService->readEntryFieldValueForSite($entryCId, 1, 'relatedPages'),
            "C's relation field must genuinely be patched, not skipped because A threw first.",
        );
        self::assertSame(
            [],
            $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'),
            "A's field must be untouched by the failed resave attempt.",
        );

        // The pass must always finish and print its report — the whole run
        // did not abort — and A's ref stays pending, retryable next time.
        unset($entryService->throwOnResave[$entryAId . "\0" . 1 . "\0" . 'relatedPages']);
        $retry = $fixup->run();

        self::assertSame(1, $retry['patched']);
        self::assertSame([], $retry['orphans']);
        self::assertSame([555], $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'));
    }

    public function testPartialDrainAcrossDifferentContainersLeavesTheOtherPendingWithoutCorruptingEitherEntry(): void
    {
        $state = new FixupInMemoryMigrationStateService();
        $entryService = new FixupFakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $fixup = new FixupService($state, $entryService, static fn(string $handle): ?int => $handle === 'en' ? 1 : null);

        // One entry, two pending refs in two DIFFERENT containers: a
        // top-level relation field and a nested Matrix-block field.
        $a = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:300', [
            'sites' => ['en' => ['fieldValues' => [
                'relatedPages' => [['_ref' => 'kuma:COM:nt_page:910']],
                'pageBuilder' => [[
                    'type' => 'contentBlock',
                    'fields' => ['relatedEntries' => [['_ref' => 'kuma:COM:nt_page:920']]],
                ]],
            ]]],
        ]));
        $entryAId = $this->save($saver, $a)->entryId;

        // Only the relatedPages target exists so far; pageBuilder's does not.
        $state->record('COM:nt_page', '910', 'entry', 610);

        $report = $fixup->run();

        self::assertSame(1, $report['patched']);
        self::assertCount(1, $report['orphans']);
        self::assertSame('pageBuilder', $report['orphans'][0]['field']);
        self::assertSame(['pageBuilder', 0, 'fields', 'relatedEntries'], $report['orphans'][0]['path']);

        self::assertSame(
            [610],
            $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'),
        );
        $pageBuilder = $entryService->readEntryFieldValueForSite($entryAId, 1, 'pageBuilder');
        self::assertSame(
            [],
            $pageBuilder[0]['fields']['relatedEntries'],
            'The still-unresolved container must not be corrupted by the neighboring container patching.',
        );

        // The second target now resolves; the previously-patched container
        // must be left exactly as it was — no double-append.
        $state->record('COM:nt_page', '920', 'entry', 620);
        $report = $fixup->run();

        self::assertSame(1, $report['patched']);
        self::assertSame([], $report['orphans']);
        $pageBuilder = $entryService->readEntryFieldValueForSite($entryAId, 1, 'pageBuilder');
        self::assertSame([620], $pageBuilder[0]['fields']['relatedEntries']);
        self::assertSame(
            [610],
            $entryService->readEntryFieldValueForSite($entryAId, 1, 'relatedPages'),
            'The container patched in the first run must remain untouched by the second.',
        );
    }
}
