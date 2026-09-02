<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\adapters\AdapterGate;
use Lameco\Kunstmaanmigrator\load\GlobalsMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryNavigationGateway;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryPluginRegistry;
use Lameco\Kunstmaanmigrator\tests\support\SettingsFactory;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use verbb\navigation\elements\Node as NavNode;

/**
 * Bug 1 — a footer link built from the `globals:` lane (`kuma_page_part_refs` /
 * `footer_box_parts`, not `kuma_menu_item` — MenuBundle is a *different* nav
 * source `NavigationMigrationService` covers) whose `link` column carries a
 * raw `[NT<id>]` token. QA found the literal token surviving verbatim in a
 * live footer href when the token's node never became a Craft entry;
 * `GlobalsMigrationService::upsertNode()` wrote `$url` straight onto the nav
 * node without ever trying the `kuma_redirects` fallback
 * `BlockBuilder::oneLink()` already tries for the exact same case on the
 * compile side (page-content links).
 *
 * Process isolation matches `NavigationMenuBundlePassTest`: constructing a
 * real `verbb\navigation\elements\Node` boots Craft's element base class,
 * which reaches for `Craft::$app` — this file loads the real `Craft` class
 * once and stands in for the one thing it reads (`getIsInstalled()`),
 * restoring the previous application after every test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GlobalsMigrationServiceTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: legacy
            locales: { en: comEnUs }
        globals:
          FooterPage:
            contexts:
              footer-column: { target: 'nav:footerMain' }
            parts:
              FooterBox:
                table: footer_box_parts
                map:
                  title:     title
                  url:       link
                  newWindow: link_new_window | bool
        YAML;

    private mixed $previousApp = null;

    protected function setUp(): void
    {
        if (!class_exists(\Craft::class, false)) {
            require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
        }

        $this->previousApp = \Craft::$app;
        \Craft::$app = new class() {
            public function getIsInstalled(): bool
            {
                return true;
            }
        };
    }

    protected function tearDown(): void
    {
        \Craft::$app = $this->previousApp;
    }

    /**
     * One footer page (node 1, translation 1000) whose only FooterBox link
     * addresses node translation 2000 via `[NT2000]`. `$linkedUrl` is that
     * second translation's own legacy URL, for the redirect-origin match;
     * `$redirect` — when given — is inserted into `kuma_redirects` verbatim
     * as the origin (no locale prefix, matching how a Kunstmaan environment
     * with one locale stores it — same shape `RedirectIndex`'s own docblock
     * describes for a single-locale environment).
     */
    private function db(string $linkedUrl, ?string $redirectTarget = null): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, deleted INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, online INTEGER, public_node_version_id INTEGER, url TEXT)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (pageEntityname TEXT, pageId INTEGER, context TEXT, page_part_entityname TEXT,
                     page_part_id INTEGER, sequencenumber INTEGER)');
        $pdo->exec('CREATE TABLE footer_box_parts (id INTEGER, title TEXT, link TEXT, link_new_window INTEGER)');
        $pdo->exec('CREATE TABLE kuma_redirects (id INTEGER, origin TEXT, target TEXT)');

        // The footer page itself: node 1, translation 1000, English.
        $pdo->exec('INSERT INTO kuma_nodes VALUES (1, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES (11, 'App\\\\Entity\\\\Pages\\\\FooterPage', 100)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES (1000, 1, 'en', 'Footer', 1, 11, 'footer')");
        $pdo->exec("INSERT INTO kuma_page_part_refs VALUES
                    ('App\\\\Entity\\\\Pages\\\\FooterPage', 100, 'footer-column', 'App\\\\Entity\\\\PageParts\\\\FooterBoxPagePart', 1, 1)");
        $pdo->exec("INSERT INTO footer_box_parts VALUES (1, 'Enreach Contact', '[NT2000]', 0)");

        // The node the `[NT2000]` token addresses — node 2, translation 2000.
        // No kuma_nodes/kuma_node_versions row is needed for it: entryFor()
        // only reads kuma_node_translations for the node id, and redirectFallback()
        // only reads it for the legacy url.
        $stmt = $pdo->prepare("INSERT INTO kuma_node_translations VALUES (2000, 2, 'en', 'Target', 1, NULL, :url)");
        $stmt->execute([':url' => $linkedUrl]);

        if ($redirectTarget !== null) {
            $stmt = $pdo->prepare('INSERT INTO kuma_redirects (origin, target) VALUES (:origin, :target)');
            $stmt->execute([':origin' => $linkedUrl, ':target' => $redirectTarget]);
        }

        return new LegacyDatabase($pdo, 'COM', 'legacy');
    }

    private function mapping(): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        return Mapping::fromFile($path);
    }

    private function context(LegacyDatabase $legacy): EnvironmentContext
    {
        $sites = SiteMap::bind(
            ['en' => 'en'],
            [(object) ['id' => 1, 'handle' => 'en', 'language' => 'en-US']],
        );

        return new EnvironmentContext('COM', 'legacy', $sites, mapping: $this->mapping(), legacy: $legacy);
    }

    /** @return array{0: GlobalsMigrationService, 1: InMemoryElementWriter} */
    private function service(LegacyDatabase $legacy): array
    {
        $svc = new class() extends GlobalsMigrationService {
            protected function newNavNode(): NavNode
            {
                return (new \ReflectionClass(NavNode::class))->newInstanceWithoutConstructor();
            }
        };
        $svc->elementWriter = $w = new InMemoryElementWriter();
        $svc->navigationGateway = new InMemoryNavigationGateway(['footerMain' => 5]);
        $svc->stateService = new InMemoryMigrationState();
        $svc->adapterGate = new AdapterGate(
            new InMemoryPluginRegistry(['navigation' => '2.0.0']),
            SettingsFactory::make(['globalsEnabled' => true]),
        );

        return [$svc, $w];
    }

    public function testAnInternalLinkThatResolvesToAMigratedEntryLinksTheEntry(): void
    {
        $legacy = $this->db('old/path/that/is/irrelevant/here');
        [$svc, $w] = $this->service($legacy);
        /** @var InMemoryMigrationState $state */
        $state = $svc->stateService;
        $state->willResolve('COM:kuma_nodes', '2', 777);

        $svc->migrateAll(new MigrationOptions(), $this->context($legacy));

        self::assertCount(1, $w->saved);
        $node = $w->saved[0]['element'];
        self::assertSame(777, $node->elementId);
        self::assertNull($node->getRawUrl());
    }

    public function testAnInternalLinkWithNoMigratedEntryFallsBackToItsManualRedirect(): void
    {
        // The exact real-world reproduction shape: an NT token whose node never migrated,
        // but the legacy site had a manual kuma_redirects 301 on file for it.
        $legacy = $this->db('products/enreach-contact', '/products/enreach-contact-alt');
        [$svc, $w] = $this->service($legacy);

        $report = $svc->migrateAll(new MigrationOptions(), $this->context($legacy));

        self::assertCount(1, $w->saved);
        $node = $w->saved[0]['element'];
        self::assertNull($node->elementId);
        self::assertSame('/products/enreach-contact-alt', $node->getRawUrl());
        self::assertStringNotContainsString('[NT2000]', implode("\n", $report->warnings));
    }

    public function testAnInternalLinkWithNoMigratedEntryAndNoRedirectLinksToHashInsteadOfTheRawToken(): void
    {
        $legacy = $this->db('products/enreach-contact'); // no matching kuma_redirects row
        [$svc, $w] = $this->service($legacy);

        $report = $svc->migrateAll(new MigrationOptions(), $this->context($legacy));

        self::assertCount(1, $w->saved);
        $node = $w->saved[0]['element'];
        self::assertNull($node->elementId);
        self::assertSame('#', $node->getRawUrl(), 'no raw "[NT2000]" token may ever reach the saved href');
        self::assertStringContainsString('[NT2000]', implode("\n", $report->warnings));
        self::assertStringContainsString('no kuma_redirects fallback', implode("\n", $report->warnings));
    }
}
