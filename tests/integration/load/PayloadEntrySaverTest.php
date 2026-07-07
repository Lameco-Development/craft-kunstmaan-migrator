<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\integration\load;

use craft\elements\Entry;
use lameco\kunstmaanmigrator\console\LoadController;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationReport;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\SchemaGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use yii\console\ExitCode;

require_once __DIR__ . '/_craft_shim.php';

/**
 * Deterministic fake for the section/entryType/site lookups
 * `PayloadEntrySaver` needs — same convention as
 * `DryRunFakeSchemaGateway`/`PayloadValidatorTest`'s `FakeSchemaGateway`.
 */
final class SaverFakeSchemaGateway implements SchemaGateway
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
        return $entryTypeHandle === 'contentPage' ? ['relatedPages'] : [];
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        return [];
    }
}

/**
 * In-memory stand-in for the state table's DB-touching primitives
 * (`get`/`getTargetId`/`getTargetUid`/`record`/`updateMeta`) — everything
 * else on `MigrationStateService` (`resolveSourceUid`, `recordAlias`) is
 * inherited UNCHANGED and genuinely exercised, since both only delegate to
 * the primitives overridden here. Matches this repo's established
 * "no live Craft/DB in tests" convention (see
 * `MigrationStateServiceTerminalStateTest`'s docblock) while still giving
 * `PayloadEntrySaver`'s own new logic a real collaborator to run against.
 */
final class InMemoryMigrationStateService extends MigrationStateService
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
}

/**
 * Fake save path standing in for `EntryMigrationService::saveEntryForSites()`
 * — the real method is Craft-app-dependent (`Entry::find()`,
 * `Craft::$app->elements->saveElement()`, ...), which this repo's test suite
 * never boots (see `EntryMigrationServiceTest`'s own docblock: "Full
 * saveElement validation is covered in the target rehearsal"). This fake
 * preserves the ONE contract `PayloadEntrySaver` actually depends on:
 * idempotent lookup-or-create keyed by (stateSource, stateKey), recorded via
 * the injected `MigrationStateService`. It returns a real `craft\elements\Entry`
 * built via `ReflectionClass::newInstanceWithoutConstructor()` — `id`/`uid`
 * are plain public typed properties on `ElementTrait` (confirmed by reading
 * vendor/craftcms/cms/src/base/ElementTrait.php), so setting them directly
 * never touches Craft::$app; only the real constructor's `Element::init()`
 * does (`Craft::$app->getIsInstalled()`), and `newInstanceWithoutConstructor()`
 * skips it entirely.
 */
final class FakeEntryMigrationService extends EntryMigrationService
{
    private int $nextId = 1000;

    /** @var array<string, mixed> last $perSite this fake received, for assertions */
    public array $lastPerSite = [];

    /**
     * Test-only fail-forward hook — when set, `saveEntryForSites()` throws
     * for exactly this stateKey instead of saving, so LoadController's
     * per-payload fail-forward loop has something real to catch.
     */
    public ?string $throwForStateKey = null;

    public function saveEntryForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        bool $force = false,
        ?MigrationReport $report = null,
    ): Entry {
        if ($this->throwForStateKey !== null && (string) $stateKey === $this->throwForStateKey) {
            throw new RuntimeException('simulated save failure for stateKey ' . $stateKey);
        }

        $this->lastPerSite = $perSite;

        /** @var Entry $entry */
        $entry = (new ReflectionClass(Entry::class))->newInstanceWithoutConstructor();

        $existingId = $this->stateService->getTargetId($stateSource, (string) $stateKey);
        if ($existingId !== null) {
            $entry->id = $existingId;
            $entry->uid = (string) $this->stateService->getTargetUid($stateSource, (string) $stateKey);

            return $entry;
        }

        $id = $this->nextId++;
        $entry->id = $id;
        $entry->uid = 'fake-uid-' . $id;
        $this->stateService->record($stateSource, (string) $stateKey, 'entry', $id, $entry->uid);

        return $entry;
    }
}

final class PayloadEntrySaverTest extends TestCase
{
    private function makeSaver(EntryMigrationService $entryService, MigrationStateService $stateService): PayloadEntrySaver
    {
        // Passthrough transaction runner — the production default wraps
        // Craft::$app->getDb()->transaction(), which this Craft-app-free
        // suite never boots.
        return new PayloadEntrySaver(
            new SaverFakeSchemaGateway(),
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

    /**
     * @param list<array<string, mixed>> $records
     */
    private function writeTempNdjson(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma-loader-live-') . '.ndjson';
        $lines = array_map(static fn (array $r): string => json_encode($r), $records);
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/kuma-loader-live-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testSavingSamePayloadTwiceReturnsTheSameEntryId(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:143'));

        $first = $saver->save($payload);
        $second = $saver->save($payload);

        self::assertSame($first->entryId, $second->entryId);
        self::assertTrue($first->created, 'First save of a never-before-seen sourceUid must be reported as created.');
        self::assertFalse($second->created, 'Re-saving the same sourceUid must be reported as an update, not a create.');
    }

    public function testUnresolvedRefIsOmittedFromFieldValuesAndRecordedAsPendingRef(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:200', [
            'sites' => [
                'en' => [
                    'fieldValues' => [
                        'relatedPages' => [['_ref' => 'kuma:COM:nt_page:999']],
                    ],
                ],
            ],
        ]));

        $result = $saver->save($payload);

        $expectedDeferred = [[
            'field' => 'relatedPages',
            'site' => 'en',
            'ref' => 'kuma:COM:nt_page:999',
            'path' => ['relatedPages'],
        ]];
        self::assertSame($expectedDeferred, $result->deferredRefs);

        // The entry was still saved (fail-forward at the field level, not the payload level) —
        // no bogus id was written for the unresolved ref, just an empty relation list.
        self::assertSame([], $entryService->lastPerSite['en']['fieldValues']['relatedPages']);

        $row = $state->get('COM:nt_page', '200');
        self::assertNotNull($row);
        self::assertSame($expectedDeferred, $row['meta']['pendingRefs']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payloadWithNestedRef(string $sourceUid, array $overrides = []): array
    {
        return $this->payloadArray($sourceUid, array_replace_recursive([
            'sites' => [
                'en' => [
                    'fieldValues' => [
                        'pageBuilder' => [
                            [
                                'type' => 'contentBlock',
                                'fields' => [
                                    'relatedEntries' => [['_ref' => 'kuma:COM:nt_page:900']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }

    public function testNestedRefInsideMatrixBlockResolvesIntoCorrectNestedSlotWhenTargetAlreadyExists(): void
    {
        $state = new InMemoryMigrationStateService();
        // Pre-seed the target so the nested _ref resolves directly at save time.
        $state->record('COM:nt_page', '900', 'entry', 555);
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = Payload::fromArray($this->payloadWithNestedRef('kuma:COM:nt_page:300'));

        $result = $saver->save($payload);

        self::assertSame([], $result->deferredRefs, 'A resolvable nested ref must not be recorded as pending.');
        self::assertSame(
            555,
            $entryService->lastPerSite['en']['fieldValues']['pageBuilder'][0]['fields']['relatedEntries'][0],
            'The resolved element id must land in the exact nested slot the _ref occupied.',
        );
    }

    public function testNestedRefInsideMatrixBlockRecordsPendingRefWithExactPathWhenUnresolved(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = Payload::fromArray($this->payloadWithNestedRef('kuma:COM:nt_page:301'));

        $result = $saver->save($payload);

        // Entry still saves — fail-forward at the field level, not the payload level.
        self::assertCount(1, $result->deferredRefs);
        $deferred = $result->deferredRefs[0];
        self::assertSame('pageBuilder', $deferred['field']);
        self::assertSame('en', $deferred['site']);
        self::assertSame('kuma:COM:nt_page:900', $deferred['ref']);
        self::assertSame(['pageBuilder', 0, 'fields', 'relatedEntries'], $deferred['path']);

        // No bogus id written — the unresolved node is dropped, leaving an empty relation list.
        self::assertSame(
            [],
            $entryService->lastPerSite['en']['fieldValues']['pageBuilder'][0]['fields']['relatedEntries'],
        );

        $row = $state->get('COM:nt_page', '301');
        self::assertNotNull($row);
        self::assertSame([$deferred], $row['meta']['pendingRefs']);
    }

    public function testAliasSourceUidResolvesToTheSameEntryIdAsThePrimarySourceUid(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:143', [
            'aliases' => ['kuma:DE:nt_page:87'],
        ]));

        $result = $saver->save($payload);

        self::assertSame($result->entryId, $state->resolveSourceUid('kuma:COM:nt_page:143'));
        self::assertSame($result->entryId, $state->resolveSourceUid('kuma:DE:nt_page:87'));
    }

    // --- LoadController::buildLiveReport() wiring ---------------------------
    //
    // Craft-app-free, same fakes as above — covers the two behaviors
    // buildLiveReport() adds on top of the already-tested buildReport():
    // the whole-batch violations gate, and the per-payload fail-forward loop.

    public function testBuildLiveReportRefusesToSaveAnythingWhenAnyPayloadHasAViolation(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $validator = new PayloadValidator(new SaverFakeSchemaGateway());

        $valid = $this->payloadArray('kuma:COM:nt_page:1');
        $mutant = $this->payloadArray('kuma:COM:nt_page:2', [
            'sites' => ['en' => ['fieldValues' => ['bogusField' => 'x']]],
        ]);
        $path = $this->writeTempNdjson([$valid, $mutant]);

        $report = LoadController::buildLiveReport($path, $validator, $saver);

        self::assertSame(0, $report['saved']);
        self::assertNotSame([], $report['violations']);
        self::assertSame([], $report['failed']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
        self::assertNull(
            $state->getTargetId('COM:nt_page', '1'),
            'A clean payload in the same batch as a violating one must NOT be saved.',
        );
    }

    public function testBuildLiveReportSavesEveryValidPayloadWhenTheBatchIsClean(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $validator = new PayloadValidator(new SaverFakeSchemaGateway());

        $one = $this->payloadArray('kuma:COM:nt_page:1');
        $two = $this->payloadArray('kuma:COM:nt_page:2');
        $path = $this->writeTempNdjson([$one, $two]);

        $report = LoadController::buildLiveReport($path, $validator, $saver);

        self::assertSame(2, $report['saved']);
        self::assertSame([], $report['violations']);
        self::assertSame([], $report['failed']);
        self::assertSame(ExitCode::OK, LoadController::exitCodeFor($report));
        self::assertNotNull($state->getTargetId('COM:nt_page', '1'));
        self::assertNotNull($state->getTargetId('COM:nt_page', '2'));
    }

    public function testBuildLiveReportRecordsSaveFailureAndContinuesFailForward(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $entryService->throwForStateKey = '2';
        $saver = $this->makeSaver($entryService, $state);
        $validator = new PayloadValidator(new SaverFakeSchemaGateway());

        $ok = $this->payloadArray('kuma:COM:nt_page:1');
        $boom = $this->payloadArray('kuma:COM:nt_page:2');
        $path = $this->writeTempNdjson([$ok, $boom]);

        $report = LoadController::buildLiveReport($path, $validator, $saver);

        self::assertSame(1, $report['saved']);
        self::assertCount(1, $report['failed']);
        self::assertSame('kuma:COM:nt_page:2', $report['failed'][0]['sourceUid']);
        self::assertStringContainsString('simulated save failure', $report['failed'][0]['error']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
        self::assertNotNull(
            $state->getTargetId('COM:nt_page', '1'),
            'A save failure for one payload must not stop the others in the same batch from saving (fail-forward).',
        );
    }
}
