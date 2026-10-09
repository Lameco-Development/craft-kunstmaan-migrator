<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\load\StructureOrderService;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Payload\PayloadValidator;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\queue\MigrateEnvironmentJob;
use Lameco\Kunstmaanmigrator\queue\RunAdaptersJob;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryUriJobGuard;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The structure-order step, as both callers run it: the console after its
 * compile walk, the batched job as its environment's last unit. One pipeline
 * method, so the two cannot settle differently.
 */
final class StructureOrderStepTest extends TestCase
{
    private const ORDERED = <<<'YAML'
        version: 1
        environments:
          NL: { database: nl, locales: { nl: berkvensNl } }
        entities:
          FaqCategory:
            table: faq_category
            section: faqCategories
            entryType: faqCategory
            title: title
            dedupe: false
            order: weight
            ignore: {}
        YAML;

    private InMemoryMigrationState $state;

    private InMemoryElementWriter $elements;

    protected function setUp(): void
    {
        $this->state = new InMemoryMigrationState();
        $this->elements = new InMemoryElementWriter();

        // Loaded in id order 1, 2, 3; the weights want 3, 1, 2.
        foreach ([1, 2, 3] as $id) {
            $this->state->willResolve('NL:faq_category', (string) $id, 100 + $id);
        }

        $this->elements->willHoldInStructure([101, 102, 103]);
    }

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER)');
        $pdo->exec('CREATE TABLE faq_category (id INTEGER, title TEXT, weight INTEGER)');
        $pdo->exec("INSERT INTO faq_category VALUES (1, 'Kleur', 2), (2, 'Prijs', 3), (3, 'Verdi', 1)");

        return new LegacyDatabase($pdo, 'NL', 'nl');
    }

    private function pipeline(string $yaml, bool $dryRun = false): EnvironmentPipeline
    {
        $transforms = new Transforms([]);

        return new EnvironmentPipeline(
            new PayloadValidator($this->createStub(SchemaGateway::class)),
            null,
            new Compiler(Mapping::fromArray(\Symfony\Component\Yaml\Yaml::parse($yaml)), $transforms),
            $transforms,
            new InMemoryUriJobGuard(),
            $this->elements,
            $dryRun ? null : new StructureOrderService($this->state, $this->elements),
        );
    }

    private function settle(string $yaml = self::ORDERED, RunSettings $settings = new RunSettings(), bool $dryRun = false): RunTally
    {
        $pipeline = $this->pipeline($yaml, $dryRun);
        $tally = new RunTally();
        $pipeline->settleStructureOrder($this->db(), 'NL', $settings, $tally);

        return $tally;
    }

    public function testItSettlesTheEnvironmentsStructuresInTheirKeyOrder(): void
    {
        $tally = $this->settle();

        self::assertSame([103, 101, 102], $this->elements->structureOrder());
        self::assertSame(2, $tally->counts['structureMoves']);
    }

    public function testReorderReachesTheService(): void
    {
        $this->settle();
        $this->elements->willHoldInStructure([102, 101, 103]);

        $this->settle(settings: new RunSettings(reorder: true));

        self::assertSame([103, 101, 102], $this->elements->structureOrder());
    }

    public function testAMappingWithoutAnOrderKeyMovesNothingAndReadsNothing(): void
    {
        $tally = $this->settle((string) preg_replace('/^ +order: weight$\n?/m', '', self::ORDERED));

        self::assertSame([], $this->elements->moves);
        self::assertArrayNotHasKey('structureMoves', $tally->counts);
    }

    public function testADryRunMovesNothing(): void
    {
        $this->settle(dryRun: true);

        self::assertSame([], $this->elements->moves);
    }

    /**
     * The flag has to survive every hand-off of the queued chain, or a queued
     * `--reorder` silently becomes a plain run after the first environment.
     */
    public function testTheQueuedChainCarriesReorderAndEndsEachEnvironmentWithTheStep(): void
    {
        $job = (string) file_get_contents((string) (new ReflectionClass(MigrateEnvironmentJob::class))->getFileName());
        $adapters = (string) file_get_contents((string) (new ReflectionClass(RunAdaptersJob::class))->getFileName());

        self::assertArrayHasKey('reorder', (new ReflectionClass(MigrateEnvironmentJob::class))->getDefaultProperties());
        self::assertArrayHasKey('reorder', (new ReflectionClass(RunAdaptersJob::class))->getDefaultProperties());
        self::assertStringContainsString("'reorder' => \$this->reorder", $job);
        self::assertStringContainsString("'reorder' => \$this->reorder", $adapters);
        self::assertStringContainsString('settleStructureOrder', $job);
    }
}
