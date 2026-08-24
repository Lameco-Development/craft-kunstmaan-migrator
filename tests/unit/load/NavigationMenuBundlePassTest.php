<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\base\ElementInterface;
use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\adapters\AdapterGate;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\db\LegacyDbService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\NavigationMigrationService;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\ConstructsNoElements;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryNavigationGateway;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryPluginRegistry;
use Lameco\Kunstmaanmigrator\tests\support\SettingsFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use verbb\navigation\elements\Node as NavNode;

/**
 * The MenuBundle pass, driven through the public migrateAll() entry point.
 *
 * migrateAll() reads Craft's primary site and — on the save path — Craft's
 * own database, both through Craft::$app. The unit bootstrap never loads the
 * Craft helper class, so this file loads it once and stands in a plain object
 * for the two components the pass reads (sites, db), restoring the previous
 * application after every test. Everything else runs through the same seams
 * the neighbouring nav tests use: the legacy database is a queue of result
 * sets, Craft's element reads and writes an in-memory recorder, verbb an
 * in-memory gateway.
 *
 * What is pinned is the pass's judgement per kuma_menu_item row — which menus
 * it refuses and why, how a url_link and a page_link differ, and what a saved
 * node leaves behind in the state map — because the legacy reads are about to
 * move behind a shared reader and this file is the net under that move.
 *
 * Process isolation is load-bearing: other unit files install a minimal Craft
 * shim without a $app property, and whichever Craft loads first serves the
 * whole process. These tests need the real one.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NavigationMenuBundlePassTest extends TestCase
{
    private const SITE_ID = 1;
    private const NAV_ID = 5;

    private mixed $previousApp = null;

    /** The Craft::$app stand-in of the running test, kept for assertions. */
    private object $app;

    protected function setUp(): void
    {
        if (!class_exists(\Craft::class, false)) {
            require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
        }

        $this->previousApp = \Craft::$app;
        $this->installCraftApp();
    }

    protected function tearDown(): void
    {
        \Craft::$app = $this->previousApp;
    }

    private function installCraftApp(bool $dbThrows = false): void
    {
        $app = new \stdClass();

        $app->sites = new class() {
            public function getPrimarySite(): object
            {
                return (object) ['id' => 1, 'handle' => 'default'];
            }
        };

        $app->db = new class($dbThrows) {
            /** @var list<array{string, array<string, mixed>, array<mixed>}> */
            public array $updates = [];

            public function __construct(private readonly bool $throws)
            {
            }

            public function createCommand(): object
            {
                if ($this->throws) {
                    throw new RuntimeException('craft db unavailable');
                }

                return new class($this) {
                    public function __construct(private readonly object $owner)
                    {
                    }

                    /**
                     * @param array<string, mixed> $columns
                     * @param array<mixed> $condition
                     */
                    public function update(string $table, array $columns, array $condition): static
                    {
                        $this->owner->updates[] = [$table, $columns, $condition];

                        return $this;
                    }

                    public function execute(): int
                    {
                        return 1;
                    }
                };
            }
        };

        $this->app = $app;
        \Craft::$app = $app;
    }

    private function service(
        LegacyDbService $db,
        ElementWriter $writer,
        InMemoryNavigationGateway $navigation,
        InMemoryMigrationState $state,
        bool $enabled = true,
    ): NavigationMigrationService {
        $svc = new class() extends NavigationMigrationService {
            // Craft's element constructor boots the application. The object
            // itself is fine once built, so build it without running that.
            protected function newNavNode(): NavNode
            {
                return (new \ReflectionClass(NavNode::class))->newInstanceWithoutConstructor();
            }
        };
        $svc->legacyDb = $db;
        $svc->elementWriter = $writer;
        $svc->navigationGateway = $navigation;
        $svc->stateService = $state;
        $svc->adapterGate = new AdapterGate(
            new InMemoryPluginRegistry(['navigation' => '2.0.0']),
            SettingsFactory::make(['navigationEnabled' => $enabled]),
        );
        // Both non-default, so navHandle() and excludedInternalNames() answer
        // from the properties instead of reaching for plugin settings.
        $svc->nodeMenuNavHandle = 'mainNav';
        $svc->nodeMenuExcludedInternalNames = ['settings', 'dienst'];

        return $svc;
    }

    /**
     * @param list<list<array<string, mixed>>|\Throwable> $resultSets
     * @param list<array<string, mixed>|\Throwable|null> $oneRows
     */
    private function legacyDb(array $resultSets = [], array $oneRows = []): LegacyDbService
    {
        return new class($resultSets, $oneRows) extends LegacyDbService {
            /**
             * @param list<list<array<string, mixed>>|\Throwable> $resultSets
             * @param list<array<string, mixed>|\Throwable|null> $oneRows
             */
            public function __construct(private array $resultSets, private array $oneRows)
            {
                parent::__construct();
            }

            public function queryAll(string $sql, array $params = []): array
            {
                $next = array_shift($this->resultSets) ?? [];
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }

            public function queryOne(string $sql, array $params = []): ?array
            {
                $next = array_shift($this->oneRows);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        };
    }

    private function context(bool $emptySites = false): EnvironmentContext
    {
        $sites = $emptySites
            ? SiteMap::bind([], [])
            : SiteMap::bind(
                ['nl' => 'default'],
                [(object) ['id' => self::SITE_ID, 'handle' => 'default', 'language' => 'nl-NL']],
            );

        return new EnvironmentContext('COM', 'legacy_com', $sites);
    }

    /** @return array<string, mixed> */
    private function menu(int $id = 1, string $name = 'top', string $locale = 'nl'): array
    {
        return ['id' => $id, 'name' => $name, 'locale' => $locale];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function item(int $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'parent_id' => null,
            'node_translation_id' => null,
            'type' => 'url_link',
            'title' => 'Item ' . $id,
            'url' => 'https://example.test/' . $id,
            'new_window' => 0,
            'lft' => $id,
            'lvl' => 1,
        ];
    }

    private function bareNode(int $id): NavNode
    {
        $node = (new \ReflectionClass(NavNode::class))->newInstanceWithoutConstructor();
        $node->id = $id;

        return $node;
    }

    public function testADisabledAdapterIsRefusedAtTheGateBeforeAnythingIsRead(): void
    {
        $svc = $this->service(
            $this->legacyDb([new RuntimeException('must never be reached')]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
            enabled: false,
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertCount(1, $report->warnings, 'the refusal is the only thing the pass says');
        self::assertStringContainsString('navigationEnabled', $report->warnings[0]);
    }

    public function testAMissingNavigationPluginSkipsTheWholePass(): void
    {
        $svc = $this->service(
            $this->legacyDb([new RuntimeException('must never be reached')]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(available: false),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertStringContainsString('verbb/navigation not available', implode("\n", $report->warnings));
    }

    public function testAnEmptySiteMapAbortsBeforeReadingTheLegacyDatabase(): void
    {
        $svc = $this->service(
            $this->legacyDb([new RuntimeException('must never be reached')]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context(emptySites: true));

        self::assertSame([], $w->saved);
        self::assertCount(1, $report->warnings);
        self::assertStringContainsString('No Craft sites mapped', $report->warnings[0]);
    }

    public function testAnUnreadableMenuTableFallsThroughToTheNodeMenuPass(): void
    {
        $svc = $this->service(
            $this->legacyDb([new RuntimeException('table gone')]),
            $w = new InMemoryElementWriter(),
            $nav = new InMemoryNavigationGateway(),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        $all = implode("\n", $report->warnings);
        self::assertStringContainsString('Could not read kuma_menu', $all);
        self::assertStringContainsString('No rows in kuma_menu', $all);
        self::assertContains('mainNav', $nav->handlesLookedUp, 'the NodeMenu pass still runs for page-tree sites');
    }

    public function testAMenuWhoseNavDoesNotExistIsSkipped(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [], // NodeMenu pass reads kuma_nodes
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['mainNav' => 9]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertStringContainsString('has no matching verbb nav', implode("\n", $report->warnings));
    }

    public function testAMenuWhoseLocaleHasNoCraftSiteIsSkipped(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu(locale: 'de')],
                [], // NodeMenu pass reads kuma_nodes
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID, 'mainNav' => 9]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertStringContainsString('has no matching Craft site', implode("\n", $report->warnings));
    }

    public function testAnUnreadableMenuItemTableSkipsThatMenuOnly(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                new RuntimeException('items gone'),
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertStringContainsString('Could not read kuma_menu_item', implode("\n", $report->warnings));
    }

    public function testAUrlLinkItemBecomesAUrlNodeScopedToItsSite(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10, ['title' => 'Extern', 'url' => 'https://extern.test', 'new_window' => 1])],
                [], // parent linkage: no rows with a parent
            ]),
            $w = new InMemoryElementWriter(),
            $nav = new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            $state = new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertCount(1, $w->saved);
        $node = $w->saved[0]['element'];
        self::assertInstanceOf(NavNode::class, $node);
        self::assertSame(self::NAV_ID, $node->navId);
        self::assertSame(self::SITE_ID, $node->siteId);
        // Read the raw url: Node::getUrl() consults verbb's plugin singleton,
        // which does not exist here.
        self::assertSame('https://extern.test', $node->getRawUrl());
        self::assertSame('Extern', $node->title);
        self::assertNull($node->type, 'a url_link carries no element type');
        self::assertNull($node->elementId);
        self::assertTrue($node->newWindow);
        self::assertSame([(int) $node->id], $nav->registeredNodeIds(), 'registration precedes the save');

        self::assertSame(1, $report->counts['created'] ?? 0);
        self::assertCount(1, $state->recorded);
        self::assertSame('kuma_menu_item:10', $state->recorded[0]['key']);
        self::assertSame(self::NAV_ID, $state->recorded[0]['meta']['navId'] ?? null);

        // Per-locale isolation: every site other than the source one is
        // disabled directly in elements_sites.
        self::assertCount(1, $this->app->db->updates);
        [$table, $columns, $condition] = $this->app->db->updates[0];
        self::assertSame('{{%elements_sites}}', $table);
        self::assertFalse($columns['enabled']);
        self::assertContains(['elementId' => (int) $node->id], $condition);
    }

    public function testAUrlLinkWithNothingToSayGetsPlaceholders(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10, ['title' => null, 'url' => null])],
                [],
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertCount(1, $w->saved);
        $node = $w->saved[0]['element'];
        self::assertSame('#', $node->getRawUrl(), 'a link without a url still needs a href to render');
        self::assertSame('(URL)', $node->title);
    }

    public function testAPageLinkPointsAtTheMigratedEntryAndBorrowsItsTitle(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('COM:kuma_nodes', '7', 500);
        $w = new InMemoryElementWriter();
        /** @var Entry $overOns */
        $overOns = (new \ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
        $overOns->id = 500;
        $overOns->title = 'Over ons';
        $w->willFind(500, $overOns, self::SITE_ID);
        $svc = $this->service(
            $this->legacyDb(
                [
                    [$this->menu()],
                    [$this->item(10, ['type' => 'page_link', 'node_translation_id' => 44, 'title' => null, 'url' => null])],
                    [],
                ],
                [['node_id' => 7, 'ref_id' => 3, 'ref_entity_name' => 'App\\Entity\\Pages\\ContentPage']],
            ),
            $w,
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            $state,
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertCount(1, $w->saved);
        $node = $w->saved[0]['element'];
        self::assertSame(500, $node->elementId, 'the node points at the migrated entry');
        self::assertSame(Entry::class, $node->type);
        self::assertNull($node->getRawUrl(), 'an entry-typed node routes through its element');
        self::assertSame('Over ons', $node->title, 'no override means the entry names the node');
        self::assertSame(1, $report->counts['created'] ?? 0);
    }

    public function testAPageLinkWhoseEntryHasNotMigratedYetIsSkippedWithAWarning(): void
    {
        $svc = $this->service(
            $this->legacyDb(
                [
                    [$this->menu()],
                    [$this->item(10, ['type' => 'page_link', 'node_translation_id' => 44])],
                ],
                [['node_id' => 7, 'ref_id' => 3, 'ref_entity_name' => 'App\\Entity\\Pages\\ContentPage']],
            ),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertSame(1, $report->counts['skipped'] ?? 0);
        self::assertStringContainsString('has no migrated entry yet', implode("\n", $report->warnings));
    }

    public function testAPageLinkWithoutANodeTranslationIsCorruptAndSkippedNotAHashNode(): void
    {
        // This row used to fall into the url branch and mint an enabled '#'
        // node titled '(URL)' — a live dead menu item — with no warning.
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10, ['type' => 'page_link', 'node_translation_id' => null, 'title' => null, 'url' => null])],
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertSame(1, $report->counts['skipped'] ?? 0);
        self::assertStringContainsString('corrupt legacy row', implode("\n", $report->warnings));
    }

    public function testADryRunWritesNothingAnywhere(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10)],
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            $state = new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(dryRun: true), $this->context());

        self::assertSame([], $w->saved);
        self::assertSame([], $state->recorded);
        self::assertSame([], $this->app->db->updates);
        self::assertSame(1, $report->counts['skipped'] ?? 0);
    }

    public function testARefusedSaveIsCountedAsFailedAndLeavesNoStateBehind(): void
    {
        $refusing = new class() implements ElementWriter {
            use ConstructsNoElements;

            public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
            {
                return false;
            }

            public function delete(ElementInterface $element, bool $hardDelete = false): void
            {
            }

            public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function invalidateCaches(): void
            {
            }
        };
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10)],
            ]),
            $refusing,
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            $state = new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame(1, $report->counts['failed'] ?? 0);
        self::assertSame([], $state->recorded, 'a refused node must not be recorded as migrated');
        self::assertStringContainsString('saveElement refused nav node', implode("\n", $report->warnings));
    }

    public function testAFailedPerSiteScopeUpdateIsReportedButDoesNotLoseTheNode(): void
    {
        $this->installCraftApp(dbThrows: true);
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10)],
                [],
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            $state = new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertCount(1, $w->saved);
        self::assertCount(1, $state->recorded, 'the node itself survives a failed scope tweak');
        self::assertSame(1, $report->counts['created'] ?? 0);
        self::assertStringContainsString('per-site enabled flag update failed', implode("\n", $report->warnings));
    }

    public function testAnItemWithoutAValidIdIsSkipped(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(0)],
            ]),
            $w = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame([], $w->saved);
        self::assertSame(1, $report->counts['skipped'] ?? 0);
    }

    public function testARerunUpdatesTheExistingNodeInsteadOfCreatingASecond(): void
    {
        $state = new InMemoryMigrationState();
        $state->willResolve('navigation', 'kuma_menu_item:10', 900);
        $writer = new InMemoryElementWriter();
        $writer->willFind(900, $this->bareNode(900));
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10)],
                [],
            ]),
            $writer,
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            $state,
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertSame(1, $report->counts['updated'] ?? 0);
        self::assertSame(0, $report->counts['created'] ?? 0);
        self::assertCount(1, $writer->saved);
        self::assertSame(900, (int) $writer->saved[0]['element']->id, 'the existing node is re-saved, not replaced');
    }

    public function testAChildItemIsRelinkedUnderItsParentAfterTheFirstPass(): void
    {
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10), $this->item(11, ['parent_id' => 10])],
                [['id' => 11, 'parent_id' => 10]],
            ]),
            $writer = new InMemoryElementWriter(),
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        $saveCounts = [];
        $byTitle = [];
        foreach (array_column($writer->saved, 'element') as $node) {
            $byTitle[$node->title] = $node;
            $saveCounts[$node->title] = ($saveCounts[$node->title] ?? 0) + 1;
        }

        self::assertSame($byTitle['Item 10']->id, $byTitle['Item 11']->getParentId());
        self::assertSame(2, $saveCounts['Item 11'], 'the child is written on create and again with its parent');
        self::assertSame(1, $saveCounts['Item 10']);
        self::assertStringNotContainsString('failed', implode("\n", $report->warnings));
    }

    public function testALinkageLookupFailureIsReportedPerItem(): void
    {
        $writer = new class() implements ElementWriter {
            use ConstructsNoElements;

            /** @var list<ElementInterface> */
            public array $saved = [];

            private int $nextId = 900;

            public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
            {
                $element->id ??= $this->nextId++;
                $this->saved[] = $element;

                return true;
            }

            public function delete(ElementInterface $element, bool $hardDelete = false): void
            {
            }

            public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
            {
                throw new RuntimeException('lookup exploded');
            }

            public function invalidateCaches(): void
            {
            }
        };
        $svc = $this->service(
            $this->legacyDb([
                [$this->menu()],
                [$this->item(10), $this->item(11, ['parent_id' => 10])],
                [['id' => 11, 'parent_id' => 10]],
            ]),
            $writer,
            new InMemoryNavigationGateway(['top' => self::NAV_ID]),
            new InMemoryMigrationState(),
        );

        $report = $svc->migrateAll(new MigrationOptions(), $this->context());

        self::assertCount(2, $writer->saved, 'both nodes were created before linkage broke');
        self::assertStringContainsString(
            'parent linkage failed for kuma_menu_item id=11: lookup exploded',
            implode("\n", $report->warnings),
        );
    }
}
