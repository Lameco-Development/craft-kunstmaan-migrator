<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\integration\load;

use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\console\LoadController;
use Lameco\Kunstmaanmigrator\finalize\CkeditorRewriterService;
use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use Lameco\Kunstmaanmigrator\load\PayloadEntrySaver;
use Lameco\Kunstmaanmigrator\load\SaveResult;
use Lameco\Kunstmaanmigrator\Payload\Payload;
use Lameco\Kunstmaanmigrator\Payload\PayloadValidator;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
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
        return $entryTypeHandle === 'contentPage' ? ['relatedPages'] : [];
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

    /** How many bookkeeping rows exist — a re-run must not grow this. */
    public function rowCount(): int
    {
        return count($this->rows);
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

    /**
     * What an already-migrated entry currently holds, keyed `<entryId>|<site>|<field>`.
     *
     * `_address` resolution reads this to decide whether it is updating the address the
     * entry already owns or creating one.
     *
     * @var array<string, array<array-key, mixed>>
     */
    public array $currentFieldValues = [];

    public function readEntryFieldValueForSite(int $entryId, int $siteId, string $fieldHandle): ?array
    {
        return $this->currentFieldValues[sprintf('%d|%d|%s', $entryId, $siteId, $fieldHandle)] ?? null;
    }

    /** @var list<array{entryId: int, site: int, field: string, value: array<array-key, mixed>}> */
    public array $resaved = [];

    public function resaveEntryFieldForSite(int $entryId, int $siteId, string $fieldHandle, array $value): bool
    {
        $this->resaved[] = ['entryId' => $entryId, 'site' => $siteId, 'field' => $fieldHandle, 'value' => $value];

        return true;
    }

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
    /** The fake gateway knows one site, `en` = 1, and it is the primary. */
    private function env(): EnvironmentContext
    {
        return EnvironmentFactory::make('COM', ['en' => 'en'], ['en' => [1, 'en-GB', true]]);
    }

    private function save(PayloadEntrySaver $saver, Payload $payload): SaveResult
    {
        return $saver->save($payload, $this->env(), new RunTally());
    }

    private function makeSaver(
        EntryMigrationService $entryService,
        MigrationStateService $stateService,
        ?AssetMigrationService $assetService = null,
        ?CkeditorRewriterService $ckeditorRewriter = null,
    ): PayloadEntrySaver {
        // Passthrough transaction runner — the production default wraps
        // Craft::$app->getDb()->transaction(), which this Craft-app-free
        // suite never boots. A bare AssetMigrationService/CkeditorRewriterService
        // pair is safe by default here: most of this file's payloads carry no
        // `_asset` node or `{{kuma:media:}}` token, so neither collaborator is
        // ever actually invoked — see AssetResolutionTest.php /
        // MediaTokenRewriteTest.php for the Task 8 coverage of those paths.
        return new PayloadEntrySaver(
            new SaverFakeSchemaGateway(),
            $entryService,
            $stateService,
            $assetService ?? new AssetMigrationService(),
            $ckeditorRewriter ?? new CkeditorRewriterService(),
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

    /**
     * @param list<array<string, mixed>> $records
     */
    private function writeTempNdjson(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kunstmaan-migrator-live-') . '.ndjson';
        $lines = array_map(static fn(array $r): string => json_encode($r), $records);
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/kunstmaan-migrator-live-*') ?: [] as $file) {
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

        $first = $this->save($saver, $payload);
        $second = $this->save($saver, $payload);

        self::assertSame($first->entryId, $second->entryId);
        self::assertTrue($first->created, 'First save of a never-before-seen sourceUid must be reported as created.');
        self::assertFalse($second->created, 'Re-saving the same sourceUid must be reported as an update, not a create.');
    }

    public function testSavingSamePayloadTwiceWritesIdenticalFieldValues(): void
    {
        // Same entry id is not the same as same result. The one re-run defect found so far was
        // nested Matrix block identity drifting between runs — same entry, different block ids,
        // partially updated rows. That is invisible to an entry-id assertion, so compare what
        // the save path was actually handed, and check the bookkeeping did not grow.
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:143'));

        $this->save($saver, $payload);
        $afterFirst = $entryService->lastPerSite;
        $rowsAfterFirst = $state->rowCount();

        $this->save($saver, $payload);
        $afterSecond = $entryService->lastPerSite;

        self::assertNotSame([], $afterFirst, 'the fixture wrote nothing — the check would pass vacuously');
        self::assertSame(
            json_encode($afterFirst),
            json_encode($afterSecond),
            'A second load of the same payload handed the save path different values.',
        );
        self::assertSame($rowsAfterFirst, $state->rowCount(), 'A re-run must not add bookkeeping rows.');
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

        $result = $this->save($saver, $payload);

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
     * The failed deferral that wrote `pendingRefs` empty: a resumed run met an entry that
     * already existed, left it untouched, and still recorded that its refs now resolved —
     * against a field the save never wrote.
     */
    public function testAnUntouchedExistingEntryKeepsThePendingRefsItsOwnSaveRecorded(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:202', [
            'sites' => ['en' => ['parentRef' => 'kuma:COM:nt_page:9']],
        ]));

        $this->save($saver, $payload);
        $deferred = [['field' => 'parentId', 'site' => 'en', 'ref' => 'kuma:COM:nt_page:9', 'path' => []]];
        self::assertSame($deferred, $state->get('COM:nt_page', '202')['meta']['pendingRefs']);

        // The parent exists by the time the resumed run comes back round to this payload.
        $state->record('COM:nt_page', '9', 'entry', 42);
        $this->save($saver, $payload);

        self::assertSame(
            $deferred,
            $state->get('COM:nt_page', '202')['meta']['pendingRefs'],
            'A save that wrote nothing must not record a resolution the entry never received.',
        );

        // A forced save does write the entry, so its verdict on the refs stands.
        $forced = new PayloadEntrySaver(
            new SaverFakeSchemaGateway(),
            $entryService,
            $state,
            new AssetMigrationService(),
            new CkeditorRewriterService(),
            static fn(callable $fn) => $fn(),
            new MigrationOptions(force: true),
        );
        $this->save($forced, $payload);

        self::assertSame([], $state->get('COM:nt_page', '202')['meta']['pendingRefs']);
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

        $result = $this->save($saver, $payload);

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

        $result = $this->save($saver, $payload);

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

        $result = $this->save($saver, $payload);

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

        $report = LoadController::buildLiveReport($path, $validator, $saver, $this->env());

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

        $report = LoadController::buildLiveReport($path, $validator, $saver, $this->env());

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

        $report = LoadController::buildLiveReport($path, $validator, $saver, $this->env());

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

    public function testBuildLiveReportAggregatesUnresolvedAssetsAcrossTheBatchWithSourceUidAttached(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new class() extends AssetMigrationService {
            public function resolveFromLegacyUrl(string $legacyUrl, EnvironmentContext $env, ?MigrationOptions $opts = null): int
            {
                return 0; // every _asset in this test is genuinely missing
            }
        };
        $saver = $this->makeSaver($entryService, $state, $assetService);
        $validator = new PayloadValidator(new SaverFakeSchemaGateway());

        $payload = $this->payloadArray('kuma:COM:nt_page:500', [
            'sites' => ['en' => ['fieldValues' => ['relatedPages' => ['_asset' => '/uploads/media/gone.jpg']]]],
        ]);
        $path = $this->writeTempNdjson([$payload]);

        $report = LoadController::buildLiveReport($path, $validator, $saver, $this->env());

        self::assertSame(1, $report['saved'], 'An unresolved asset is reported, not fatal — the entry still saves.');
        self::assertSame(ExitCode::OK, LoadController::exitCodeFor($report), 'Unresolved assets do not flip the exit code, matching deferred _ref semantics.');
        self::assertSame([[
            'sourceUid' => 'kuma:COM:nt_page:500',
            'field' => 'relatedPages',
            'site' => 'en',
            'path' => [],
            'asset' => '/uploads/media/gone.jpg',
        ]], $report['unresolvedAssets']);
        self::assertSame([], $report['mediaTokenIssues']);
    }

    // --- Task 8 review Finding 1/3 — media-token rewrite must be scoped ----
    //
    // rewriteMediaTokens() must call ONLY CkeditorRewriterService's narrow
    // curly-token rewrite, never the full rewrite() pipeline — a normal body
    // sharing a paragraph with a `{{kuma:media:N}}` token must not have its
    // `[NT<id>]` internal links, `kma-*` classes, or raw
    // `<img src="/uploads/media/...">` markup touched.

    public function testMediaTokenRewriteIsScopedToCurlyTokensAndLeavesNtBracketClassAndRawImgMarkupByteIdentical(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new class() extends AssetMigrationService {
            public function resolveFromLegacyId(int $legacyId, EnvironmentContext $env, ?MigrationOptions $opts = null): int
            {
                return $legacyId === 5 ? 501 : 0;
            }
        };
        $ckeditorRewriter = new CkeditorRewriterService();
        $ckeditorRewriter->assetResolver = $assetService->resolverFor($this->env(), new MigrationOptions());
        $saver = $this->makeSaver($entryService, $state, $assetService, $ckeditorRewriter);

        $html = '<p>See {{kuma:media:5}}. <a href="[NT80]">next</a> <span class="kma-foo">x</span> <img src="/uploads/media/x.jpg"></p>';
        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:600', [
            'sites' => ['en' => ['fieldValues' => ['body' => $html]]],
        ]));

        $result = $this->save($saver, $payload);
        $rewritten = $entryService->lastPerSite['en']['fieldValues']['body'];

        self::assertStringContainsString('{asset:501@1:url}', $rewritten);
        self::assertStringNotContainsString('{{kuma:media:5}}', $rewritten);

        // Everything else must be byte-identical to the source — proves the
        // full [NT]/[M]/img/class-strip pipeline never runs on payload load.
        self::assertStringContainsString('<a href="[NT80]">next</a>', $rewritten);
        self::assertStringContainsString('<span class="kma-foo">x</span>', $rewritten);
        self::assertStringContainsString('<img src="/uploads/media/x.jpg">', $rewritten);
        self::assertSame([], $result->mediaTokenIssues);
    }

    public function testCurlyMediaTokenNestedInsideMatrixBlockRecordsFieldSiteAndPathContextOnAnUnresolvedId(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new class() extends AssetMigrationService {
            public function resolveFromLegacyId(int $legacyId, EnvironmentContext $env, ?MigrationOptions $opts = null): int
            {
                return 0; // genuinely unresolvable
            }
        };
        $saver = $this->makeSaver($entryService, $state, $assetService);

        $payload = Payload::fromArray($this->payloadArray('kuma:COM:nt_page:601', [
            'sites' => [
                'en' => [
                    'fieldValues' => [
                        'pageBuilder' => [
                            ['type' => 'contentBlock', 'fields' => ['body' => '<p>{{kuma:media:777}}</p>']],
                        ],
                    ],
                ],
            ],
        ]));

        $result = $this->save($saver, $payload);

        self::assertCount(1, $result->mediaTokenIssues);
        $issue = $result->mediaTokenIssues[0];
        self::assertSame('pageBuilder', $issue['field']);
        self::assertSame('en', $issue['site']);
        self::assertSame(['pageBuilder', 0, 'fields', 'body'], $issue['path']);
        self::assertSame('media_token', $issue['tokenFamily']);
        self::assertSame(777, $issue['legacyId']);
    }

    /**
     * The hour a real corpus takes has to split into phases, and the split is
     * only trustworthy if every phase is closed on every save — a phase left at
     * zero would read as "free", which is the most convincing way to be wrong.
     */
    public function testASavePutsItsAssetEntrySaveAndStateTimeOnTheTallyKeyedByType(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $assetService = new class() extends AssetMigrationService {
            public function resolveFromLegacyUrl(string $legacyUrl, EnvironmentContext $env, ?MigrationOptions $opts = null): int
            {
                return 77;
            }
        };
        $saver = $this->makeSaver($entryService, $state, $assetService);
        $tally = new RunTally();

        $raw = $this->payloadArray('kuma:COM:nt_page:700', [
            'sites' => ['en' => ['fieldValues' => ['relatedPages' => ['_asset' => '/uploads/media/a.png']]]],
        ]);
        $raw['legacy'] = ['class' => 'App\\Entity\\Pages\\PartnerPage', 'refIds' => ['en' => 1]];

        $saver->save(Payload::fromArray($raw), $this->env(), $tally);

        foreach (['assets', 'entrySave', 'state'] as $phase) {
            self::assertSame(1, $tally->timings[$phase]['count'], $phase);
            self::assertGreaterThan(0.0, $tally->timings[$phase]['seconds'], $phase . ' was not timed');
        }

        self::assertSame(['PartnerPage'], array_keys($tally->timingsByType), 'keyed by the name --only takes');
        self::assertSame(1, $tally->timingsByType['PartnerPage']['count']);
        self::assertGreaterThan(0.0, $tally->timingsByType['PartnerPage']['seconds']);
    }

    /** An entity-lane payload has no page class; its legacy table is the name that narrows a run. */
    public function testAnEntityPayloadIsAttributedToItsLegacyTable(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);
        $tally = new RunTally();

        $saver->save(Payload::fromArray($this->payloadArray('kuma:COM:kuma_tags:3')), $this->env(), $tally);

        self::assertSame(['COM:kuma_tags'], array_keys($tally->timingsByType));
        self::assertSame(0, $tally->timings['assets']['count'], 'no asset node, no asset call — the phase still closes');
    }

    public function testTheLegacyIdentityIsRecordedSoTheSeoPassCanFindItsRows(): void
    {
        // Without this the SEO pass has nothing to look up: `refIdsByLocale` is what decides
        // which `kuma_seo` row belongs to which site, and one shared id would leak the primary
        // locale's meta into every other. 204 entries were written with empty SEO because
        // nothing recorded it.
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $raw = $this->payloadArray('kuma:LV:kuma_nodes:2');
        $raw['legacy'] = [
            'class' => 'App\\Entity\\Pages\\ContentPage',
            'refIds' => ['en' => 1, 'lv' => 19, 'ru' => 20],
        ];

        $this->save($saver, Payload::fromArray($raw));

        $meta = $state->get('LV:kuma_nodes', '2')['meta'] ?? [];
        self::assertSame('App\\Entity\\Pages\\ContentPage', $meta['legacyClass'] ?? null);
        self::assertSame(['en' => 1, 'lv' => 19, 'ru' => 20], $meta['refIdsByLocale'] ?? null);
        self::assertSame(1, $meta['legacyEntityId'] ?? null);
    }

    public function testAnAddressOnANewEntryIsWrittenAsANewAddress(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $this->save($saver, Payload::fromArray($this->payloadArray('kuma:DE:partner_pages:1', [
            'sites' => ['en' => ['fieldValues' => [
                'partnerAddress' => ['_address' => ['addressLine1' => 'Schlossvorstadt 4', 'countryCode' => 'DE']],
            ]]],
        ])));

        self::assertSame(
            ['new1' => [
                'addressLine1' => 'Schlossvorstadt 4',
                'countryCode' => 'DE',
                'title' => 'Entry kuma:DE:partner_pages:1',
            ]],
            $entryService->lastPerSite['en']['fieldValues']['partnerAddress'],
        );
    }

    public function testAnAddressOnAnExistingEntryReusesTheAddressItAlreadyOwns(): void
    {
        // Craft's Addresses field deletes whatever key it does not recognise. Left at `new1`,
        // every re-load would destroy and recreate the element — same values, new id, a row
        // of garbage per run.
        $state = new InMemoryMigrationStateService();
        $state->record('DE:partner_pages', '1', 'entry', 777);
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $entryService->currentFieldValues['777|1|partnerAddress'] = [
            4242 => ['addressLine1' => 'Schlossvorstadt 3', 'countryCode' => 'DE'],
        ];
        $saver = $this->makeSaver($entryService, $state);

        $this->save($saver, Payload::fromArray($this->payloadArray('kuma:DE:partner_pages:1', [
            'sites' => ['en' => ['fieldValues' => [
                'partnerAddress' => ['_address' => ['addressLine1' => 'Schlossvorstadt 4', 'countryCode' => 'DE']],
            ]]],
        ])));

        self::assertSame(
            [4242 => [
                'addressLine1' => 'Schlossvorstadt 4',
                'countryCode' => 'DE',
                'title' => 'Entry kuma:DE:partner_pages:1',
            ]],
            $entryService->lastPerSite['en']['fieldValues']['partnerAddress'],
        );
    }

    public function testAnAddressIsOmittedOnEverySiteButThePrimary(): void
    {
        // A Craft Address supports the primary site and no other, so saving one against any
        // other site throws and takes the whole entry with it — 423 partner pages did exactly
        // that. Omitting it elsewhere is what "not translatable" means.
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $payload = $this->payloadArray('kuma:DE:partner_pages:9', [
            'sites' => [
                'en' => ['fieldValues' => ['partnerAddress' => ['_address' => ['addressLine1' => 'Schlossvorstadt 4']]]],
                'de' => ['fieldValues' => ['partnerAddress' => ['_address' => ['addressLine1' => 'Schlossvorstadt 4']]]],
            ],
        ]);

        $this->save($saver, Payload::fromArray($payload));

        self::assertArrayHasKey('partnerAddress', $entryService->lastPerSite['en']['fieldValues']);
        self::assertArrayNotHasKey(
            'partnerAddress',
            $entryService->lastPerSite['de']['fieldValues'],
            'A non-primary site must not carry the address.',
        );
    }

    public function testAnAddressOnAPayloadThatNeverNamesThePrimarySiteIsStillWritten(): void
    {
        // Every DE partner page is published in `de` alone, so the per-site pass has nowhere
        // to put the address — and dropping it produced a clean run that migrated no address
        // at all. The entry exists on the primary site by propagation, so it goes there.
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $result = $this->save($saver, Payload::fromArray($this->payloadArray('kuma:DE:partner_pages:10', [
            'sites' => ['de' => ['fieldValues' => [
                'partnerAddress' => ['_address' => ['addressLine1' => 'Holzhausenstr. 87', 'title' => 'Hauptsitz']],
            ]]],
        ])));

        self::assertCount(1, $entryService->resaved, 'The address is written once, against the primary site.');
        self::assertSame(1, $entryService->resaved[0]['site']);
        self::assertSame('partnerAddress', $entryService->resaved[0]['field']);
        self::assertSame(
            ['new1' => ['addressLine1' => 'Holzhausenstr. 87', 'title' => 'Hauptsitz']],
            $entryService->resaved[0]['value'],
        );
        self::assertSame([], $result->droppedAddresses, 'Nothing is left unwritten to report.');
    }

    public function testANestedAddressThatCannotBePlacedIsReportedRatherThanLostSilently(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $result = $this->save($saver, Payload::fromArray($this->payloadArray('kuma:DE:partner_pages:11', [
            'sites' => ['de' => ['fieldValues' => [
                'partnerBranches' => [[
                    'type' => 'partnerBranch',
                    'fields' => ['branchAddress' => ['_address' => ['addressLine1' => 'Am Gierath 20d']]],
                ]],
            ]]],
        ])));

        self::assertSame([], $entryService->resaved, 'A nested address has no owner to write against yet.');
        self::assertSame(
            [['field' => 'branchAddress', 'site' => 'de']],
            $result->droppedAddresses,
        );
    }

    public function testALabelInThePayloadIsKeptRatherThanOverwrittenByTheOwnersTitle(): void
    {
        $state = new InMemoryMigrationStateService();
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $this->save($saver, Payload::fromArray($this->payloadArray('kuma:DE:partner_pages:3', [
            'sites' => ['en' => ['fieldValues' => [
                'partnerAddress' => ['_address' => ['title' => 'Hauptsitz', 'addressLine1' => 'Schlossvorstadt 4']],
            ]]],
        ])));

        self::assertSame(
            ['new1' => ['title' => 'Hauptsitz', 'addressLine1' => 'Schlossvorstadt 4']],
            $entryService->lastPerSite['en']['fieldValues']['partnerAddress'],
            'The mapping owns the label; the fallback only fills a blank one.',
        );
    }

    public function testAnAddressNestedInAMatrixBlockIsWrittenAsNew(): void
    {
        // Its owner is a block whose identity is not known until the block is saved, so
        // there is no id to reuse. Asserted so the limitation is visible rather than found.
        $state = new InMemoryMigrationStateService();
        $state->record('DE:partner_pages', '2', 'entry', 778);
        $entryService = new FakeEntryMigrationService();
        $entryService->stateService = $state;
        $saver = $this->makeSaver($entryService, $state);

        $this->save($saver, Payload::fromArray($this->payloadArray('kuma:DE:partner_pages:2', [
            'sites' => ['en' => ['fieldValues' => [
                'partnerBranches' => [[
                    'type' => 'partnerBranch',
                    'fields' => ['branchAddress' => ['_address' => ['addressLine1' => 'Am Gierath 20d']]],
                ]],
            ]]],
        ])));

        self::assertSame(
            ['new1' => ['addressLine1' => 'Am Gierath 20d', 'title' => 'Entry kuma:DE:partner_pages:2']],
            $entryService->lastPerSite['en']['fieldValues']['partnerBranches'][0]['fields']['branchAddress'],
        );
    }
}
