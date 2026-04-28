<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanPageWalker;
use PHPUnit\Framework\TestCase;

final class KunstmaanPageWalkerTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/kunstmaan-page-walker-' . bin2hex(random_bytes(6));
        $entityDir = $base . '/src/Entity/Pages';
        $pagepartDir = $base . '/src/Entity/PageParts';
        if (!mkdir($entityDir, 0o755, true) && !is_dir($entityDir)) {
            self::fail("could not create temp Entity/Pages dir at {$entityDir}");
        }
        if (!mkdir($pagepartDir, 0o755, true) && !is_dir($pagepartDir)) {
            self::fail("could not create temp Entity/PageParts dir at {$pagepartDir}");
        }
        $this->tmpRoot = $base;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
        $this->tmpRoot = '';
        parent::tearDown();
    }

    public function testRootRelationEmployeeContentAndDepthGraphIsNormalized(): void
    {
        $this->writeEntity('Pages/NewsPage.php', <<<'PHP'
        <?php
        namespace App\Entity\Pages;

        use App\Entity\Employee;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'lameco_websitebundle_newspages')]
        class NewsPage
        {
            #[ORM\Column(type: 'integer')]
            private $id;

            #[ORM\Column(type: 'string')]
            private $title;

            #[ORM\Column(type: 'integer', name: 'employee_id', nullable: true)]
            private $employeeId;

            #[ORM\Column(type: 'text', nullable: true)]
            private $content;

            #[ORM\ManyToOne(targetEntity: Employee::class)]
            #[ORM\JoinColumn(name: 'employee_id', referencedColumnName: 'id')]
            private $employee;
        }
        PHP);
        $this->writeEntity('Employee.php', <<<'PHP'
        <?php
        namespace App\Entity;

        use App\Entity\Department;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'lameco_websitebundle_employee_employees')]
        class Employee
        {
            #[ORM\Column(type: 'integer')]
            private $id;

            #[ORM\Column(type: 'string')]
            private $name;

            #[ORM\ManyToOne(targetEntity: Department::class)]
            #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id')]
            private $department;
        }
        PHP);
        $this->writeEntity('Department.php', <<<'PHP'
        <?php
        namespace App\Entity;

        use App\Entity\Employee;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'lameco_websitebundle_departments')]
        class Department
        {
            #[ORM\Column(type: 'integer')]
            private $id;

            #[ORM\ManyToOne(targetEntity: Employee::class)]
            #[ORM\JoinColumn(name: 'manager_id', referencedColumnName: 'id')]
            private $manager;
        }
        PHP);

        $graph = $this->walker([
            'mediaFks' => [
                ['table' => 'lameco_websitebundle_newspages', 'column' => 'image_id'],
            ],
        ], sourceSchema: [
            'columns' => [
                'lameco_websitebundle_newspages' => [
                    ['column' => 'employee_id', 'samples' => [42]],
                    ['column' => 'content', 'samples' => ['Body text']],
                ],
                'lameco_websitebundle_employee_employees' => [
                    ['column' => 'name', 'samples' => ['Jane Doe']],
                ],
            ],
        ])->walk(['NewsPage'], 2);

        $newsRef = KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\NewsPage');
        $employeeRef = KunstmaanGraphContract::entityRef('App\\Entity\\Employee');

        self::assertSame(KunstmaanGraphContract::GRAPH_VERSION, $graph[KunstmaanGraphContract::KEY_GRAPH_VERSION]);
        self::assertArrayHasKey($newsRef, $graph[KunstmaanGraphContract::KEY_ROOTS]);
        self::assertArrayHasKey($employeeRef, $graph[KunstmaanGraphContract::KEY_ENTITIES]);
        self::assertSame(
            'employee_id',
            $graph[KunstmaanGraphContract::KEY_ENTITIES][$employeeRef]['inboundOwners'][0]['fkColumn'],
        );
        self::assertContains(
            KunstmaanGraphContract::INTENT_OUT_OF_SCOPE,
            $graph[KunstmaanGraphContract::KEY_RELATIONS][$newsRef . '.employee']['intentCandidates'],
        );
        self::assertArrayHasKey($newsRef . '.content', $graph[KunstmaanGraphContract::KEY_SAMPLES]);
        self::assertArrayHasKey($employeeRef . '.name', $graph[KunstmaanGraphContract::KEY_SAMPLES]);
        self::assertArrayHasKey($newsRef . '.image_id', $graph[KunstmaanGraphContract::KEY_ASSETS]);
        self::assertLessThanOrEqual(3, count($graph[KunstmaanGraphContract::KEY_RELATIONS]));
    }

    public function testHomePagepartUsageAssetAndSampleGraphIsNormalized(): void
    {
        $this->writeEntity('Pages/HomePage.php', <<<'PHP'
        <?php
        namespace App\Entity\Pages;

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'lameco_websitebundle_homepages')]
        class HomePage
        {
            #[ORM\Column(type: 'integer')]
            private $id;
        }
        PHP);
        $this->writeEntity('PageParts/TextPagePart.php', <<<'PHP'
        <?php
        namespace App\Entity\PageParts;

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'lameco_websitebundle_text_pageparts')]
        class TextPagePart
        {
            #[ORM\Column(type: 'integer')]
            private $id;

            #[ORM\Column(type: 'integer')]
            private $weight;

            #[ORM\Column(type: 'text')]
            private $content;

            #[ORM\Column(type: 'integer', name: 'image_id', nullable: true)]
            private $imageId;
        }
        PHP);

        $graph = $this->walker([
            'mediaFks' => [
                ['table' => 'lameco_websitebundle_text_pageparts', 'column' => 'image_id'],
            ],
        ], pageStructure: [
            'App\\Entity\\Pages\\HomePage' => [
                'contexts' => [
                    [
                        'name' => 'main',
                        'allowedPagePartClasses' => [
                            [
                                'class' => 'App\\Entity\\PageParts\\TextPagePart',
                                'table' => 'lameco_websitebundle_text_pageparts',
                            ],
                        ],
                    ],
                ],
            ],
        ], sourceSchema: [
            'columns' => [
                'lameco_websitebundle_text_pageparts' => [
                    ['column' => 'content', 'samples' => ['Intro body']],
                ],
            ],
        ])->walk(['HomePage']);

        $homeRef = KunstmaanGraphContract::pageRootRef('App\\Entity\\Pages\\HomePage');
        $pagepartRef = KunstmaanGraphContract::pagepartRef('App\\Entity\\PageParts\\TextPagePart');

        self::assertArrayHasKey($pagepartRef, $graph[KunstmaanGraphContract::KEY_PAGEPARTS]);
        self::assertCount(1, $graph[KunstmaanGraphContract::KEY_PAGEPART_USAGES]);
        self::assertSame(
            $homeRef,
            array_values($graph[KunstmaanGraphContract::KEY_PAGEPART_USAGES])[0]['pageRootRef'],
        );
        self::assertSame(
            ['weight'],
            array_values($graph[KunstmaanGraphContract::KEY_PAGEPART_USAGES])[0]['orderingEvidence'],
        );
        self::assertArrayHasKey($pagepartRef . '.image_id', $graph[KunstmaanGraphContract::KEY_ASSETS]);
        self::assertArrayHasKey($pagepartRef . '.content', $graph[KunstmaanGraphContract::KEY_SAMPLES]);
    }

    public function testMissingParserReturnsVersionedEmptyGraph(): void
    {
        $graph = (new KunstmaanPageWalker())->walk(['NewsPage']);

        self::assertSame(KunstmaanGraphContract::GRAPH_VERSION, $graph[KunstmaanGraphContract::KEY_GRAPH_VERSION]);
        self::assertSame([], $graph[KunstmaanGraphContract::KEY_ROOTS]);
    }

    /**
     * @param array<string, mixed> $sourceScanExtras
     * @param array<string, mixed> $pageStructure
     * @param array<string, mixed> $sourceSchema
     */
    private function walker(array $sourceScanExtras = [], array $pageStructure = [], array $sourceSchema = []): KunstmaanPageWalker
    {
        $parser = new DoctrineEntityParser();
        $parser->sourceCheckoutPath = $this->tmpRoot;
        $entities = $parser->getAll();

        $walker = new KunstmaanPageWalker();
        $walker->entityParser = $parser;
        $walker->sourceScanSnapshot = array_replace([
            'tables' => array_values(array_map(static fn($entity) => $entity->tableName, $entities)),
            'entities' => $entities,
            'm2mJoins' => [],
            'bodyCols' => [],
            'mediaFks' => [],
            'drift' => ['dbHasButScanMissing' => [], 'scanHasButDbMissing' => []],
        ], $sourceScanExtras);
        $walker->pageStructureSnapshot = $pageStructure;
        $walker->sourceSchemaSnapshot = $sourceSchema;

        return $walker;
    }

    private function writeEntity(string $relativePath, string $code): void
    {
        $path = $this->tmpRoot . '/src/Entity/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            self::fail("could not create entity fixture dir at {$dir}");
        }
        file_put_contents($path, $code);
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
