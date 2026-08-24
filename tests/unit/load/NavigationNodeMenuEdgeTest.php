<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\base\ElementInterface;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\MigrationStateService;
use Lameco\Kunstmaanmigrator\load\NavigationMigrationService;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\ConstructsNoElements;
use Lameco\Kunstmaanmigrator\tests\support\FakeLegacyDb;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryNavigationGateway;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use verbb\navigation\elements\Node as NavNode;

/**
 * The NodeMenu pass's edges — the paths NavigationNodeMenuPassTest leaves
 * open: the update lane of a re-run, the failure lanes around one node, and
 * the fallbacks that keep a degenerate source from taking the pass down.
 * Same driving technique as its sibling: the pass invoked directly with the
 * two primary-site facts it needs, everything else in-memory fakes.
 */
final class NavigationNodeMenuEdgeTest extends TestCase
{
    private const PRIMARY_SITE_ID = 1;
    private const NAV_ID = 7;

    private function service(
        MigrationStateService $state,
        ElementWriter $writer,
        FakeLegacyDb $db,
        ?InMemoryNavigationGateway $navigation = null,
    ): NavigationMigrationService {
        $svc = new class() extends NavigationMigrationService {
            // Craft's element constructor boots the application. The object
            // itself is fine once built, so build it without running that.
            protected function newNavNode(): NavNode
            {
                return (new \ReflectionClass(NavNode::class))->newInstanceWithoutConstructor();
            }
        };
        $svc->elementWriter = $writer;
        $svc->navigationGateway = $navigation ?? new InMemoryNavigationGateway(['mainNav' => self::NAV_ID]);
        $svc->legacyDb = $db;
        $svc->stateService = $state;
        // Both non-default, so navHandle() and excludedInternalNames() answer
        // from the properties instead of reaching for plugin settings.
        $svc->nodeMenuNavHandle = 'mainNav';
        $svc->nodeMenuExcludedInternalNames = ['settings', 'dienst'];

        return $svc;
    }

    private function sites(): SiteMap
    {
        return SiteMap::bind(
            ['nl' => 'default'],
            [(object) ['id' => self::PRIMARY_SITE_ID, 'handle' => 'default', 'language' => 'nl-NL']],
        );
    }

    private function runPass(
        NavigationMigrationService $svc,
        MigrationReport $report,
        string $primarySiteHandle = 'default',
    ): void {
        (new ReflectionMethod($svc, 'migrateNodeMenu'))->invoke(
            $svc,
            ['nl' => self::PRIMARY_SITE_ID],
            $this->sites(),
            self::PRIMARY_SITE_ID,
            $primarySiteHandle,
            'COM',
            new MigrationOptions(),
            $report,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(int $id, int $parentId, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'parent_id' => $parentId,
            'lvl' => 1,
            'lft' => $id,
            'internal_name' => 'page' . $id,
            'ref_entity_name' => 'App\\Entity\\Pages\\ContentPage',
            'ref_id' => 100 + $id,
            'sort_weight' => $id,
        ];
    }

    /** Entry that kuma_node $id's ref_id resolves to, via the FQCN state source. */
    private function entryExistsFor(InMemoryMigrationState $state, int $id, int $entryId): void
    {
        $state->willResolve('App_Entity_Pages_ContentPage', (string) (100 + $id), $entryId);
    }

    public function testAnEmptyNodeTableProducesNoOutputAtAll(): void
    {
        $svc = $this->service(
            new InMemoryMigrationState(),
            $w = new InMemoryElementWriter(),
            new FakeLegacyDb([[]]),
        );
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame([], $w->saved);
        self::assertSame([], $report->warnings, 'a page-tree site without nav-visible nodes is not news');
    }

    public function testAPrimaryHandleUnknownToTheSiteMapFallsBackToTheFirstLocale(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $svc = $this->service($state, $w = new InMemoryElementWriter(), new FakeLegacyDb([[$this->row(2, 1)]]));

        $this->runPass($svc, new MigrationReport(), primarySiteHandle: 'unmapped');

        self::assertCount(1, $w->saved, 'an unmapped primary handle degrades the sort locale, not the pass');
    }

    public function testARowWithoutAValidIdIsIgnoredWithoutCounting(): void
    {
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $svc = $this->service(
            $state,
            $w = new InMemoryElementWriter(),
            new FakeLegacyDb([[$this->row(0, 1), $this->row(2, 1)]]),
        );
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertCount(1, $w->saved);
        self::assertSame(0, $report->counts['skipped'] ?? 0, 'a corrupt id is noise, not a decision to report');
    }

    public function testAStateLookupFailureFailsTheNodeNotThePass(): void
    {
        $state = new class() extends MigrationStateService {
            public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
            {
                throw new RuntimeException('state table gone');
            }
        };
        $svc = $this->service($state, $w = new InMemoryElementWriter(), new FakeLegacyDb([[$this->row(2, 1)]]));
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame([], $w->saved);
        self::assertSame(1, $report->counts['failed'] ?? 0);
        self::assertStringContainsString(
            'NodeMenu node import failed for kuma_node id=2: state table gone',
            implode("\n", $report->warnings),
        );
    }

    public function testARefusedNodeMenuSaveIsCountedAsFailed(): void
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
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $svc = $this->service($state, $refusing, new FakeLegacyDb([[$this->row(2, 1)]]));
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame(1, $report->counts['failed'] ?? 0);
        self::assertSame([], $state->recorded, 'a refused node must not be recorded as migrated');
        self::assertStringContainsString('saveElement refused NodeMenu node for kuma_node id=2', implode("\n", $report->warnings));
    }

    public function testARerunFindsTheExistingNodeAndCountsAnUpdate(): void
    {
        $existing = (new \ReflectionClass(NavNode::class))->newInstanceWithoutConstructor();
        $existing->id = 900;
        $state = new InMemoryMigrationState();
        $state->willResolve('navigation', 'kuma_node:2', 900);
        $this->entryExistsFor($state, 2, 500);
        $writer = new InMemoryElementWriter();
        $writer->willFind(900, $existing);
        $svc = $this->service($state, $writer, new FakeLegacyDb([[$this->row(2, 1)]]));
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertSame(1, $report->counts['updated'] ?? 0);
        self::assertSame(0, $report->counts['created'] ?? 0);
        self::assertCount(1, $writer->saved);
        self::assertSame($existing, $writer->saved[0]['element'], 'the existing node is re-saved, not replaced');
        self::assertSame(500, $existing->elementId, 'the re-run refreshes the link to the entry');
    }

    public function testALinkageFailureIsReportedPerNodeAndSparesTheRest(): void
    {
        $writer = new class(new InMemoryElementWriter()) implements ElementWriter {
            use ConstructsNoElements;

            public int $savesAllowed = 2;

            public function __construct(public readonly InMemoryElementWriter $inner)
            {
            }

            public function save(ElementInterface $element, bool $runValidation = true, bool $propagate = false): bool
            {
                if ($this->savesAllowed-- <= 0) {
                    throw new RuntimeException('structure move exploded');
                }

                return $this->inner->save($element, $runValidation, $propagate);
            }

            public function delete(ElementInterface $element, bool $hardDelete = false): void
            {
                $this->inner->delete($element, $hardDelete);
            }

            public function findById(int $id, string $class, ?int $siteId = null): ?ElementInterface
            {
                return $this->inner->findById($id, $class, $siteId);
            }

            public function invalidateCaches(): void
            {
                $this->inner->invalidateCaches();
            }
        };
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $this->entryExistsFor($state, 3, 501);
        $svc = $this->service($state, $writer, new FakeLegacyDb([[$this->row(2, 1), $this->row(3, 2)]]));
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertCount(2, $writer->inner->saved, 'both nodes were created before linkage broke');
        self::assertStringContainsString(
            'parent linkage failed for kuma_node id=3: structure move exploded',
            implode("\n", $report->warnings),
        );
    }

    public function testAChildWhoseNodeVanishedBeforeLinkageIsLeftAlone(): void
    {
        // Saves succeed but nothing is findable afterwards — the shape of a
        // node deleted out from under the pass between its two halves.
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
                return null;
            }

            public function invalidateCaches(): void
            {
            }
        };
        $state = new InMemoryMigrationState();
        $this->entryExistsFor($state, 2, 500);
        $this->entryExistsFor($state, 3, 501);
        $svc = $this->service($state, $writer, new FakeLegacyDb([[$this->row(2, 1), $this->row(3, 2)]]));
        $report = new MigrationReport();

        $this->runPass($svc, $report);

        self::assertCount(2, $writer->saved, 'linkage found nothing to move, so nothing was written twice');
        self::assertSame([], $report->warnings);
    }
}
