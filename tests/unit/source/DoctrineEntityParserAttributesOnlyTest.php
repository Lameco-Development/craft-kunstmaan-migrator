<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\source;

use lameco\kunstmaanmigrator\source\DoctrineColumnInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityParser;
use lameco\kunstmaanmigrator\source\DoctrineRelationInfo;
use PHPUnit\Framework\TestCase;

/**
 * Plan 04.1-06 / SRC-20 — attribute-only parser regression guard.
 *
 * After Plan 04.1-06, DoctrineEntityParser parses PHP 8 attribute syntax only.
 * The annotation paths (@ORM\Table, @ORM\Column, etc.) have been removed per
 * CONTEXT D-31..D-33. The pre-flight grep across cqm/simac/enreach Entity
 * directories confirmed zero live `@ORM\` annotations before the strip landed.
 *
 * The load-bearing assertion of this file is `testIgnoresAnnotationInput`: an
 * annotation-only fixture file must NOT produce an entity in `getAll()` —
 * that's the proof the docblock fallback was removed (not feature-flagged).
 *
 * The other tests cover the surviving attribute-parsing surface so a future
 * regression that breaks attribute parsing is caught immediately.
 *
 * No Craft bootstrap: tests exercise the parser via its public API
 * (`getAll()` / `getByFqcn()`) against fixture files materialized under a
 * temp directory shaped like a Kunstmaan source checkout
 * (`<tmp>/src/Entity/<Class>.php`).
 */
final class DoctrineEntityParserAttributesOnlyTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/dep-parser-' . bin2hex(random_bytes(6));
        $entityDir = $base . '/src/Entity';
        if (!mkdir($entityDir, 0o755, true) && !is_dir($entityDir)) {
            self::fail("could not create temp Entity dir at {$entityDir}");
        }
        $this->tmpRoot = $base;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
        $this->tmpRoot = '';
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Attribute-input regression guards
    // -----------------------------------------------------------------------

    public function testParsesAttributeBasedTable(): void
    {
        $this->writeEntity('Foo.php', <<<'PHP'
        <?php
        namespace App\Entity;

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'kuma_foo')]
        class Foo
        {
        }
        PHP);

        $parser = $this->newParser();
        $info = $parser->getByFqcn('App\\Entity\\Foo');

        self::assertInstanceOf(DoctrineEntityInfo::class, $info);
        self::assertSame('kuma_foo', $info->tableName);
        self::assertSame('App\\Entity\\Foo', $info->fqcn);
    }

    public function testParsesAttributeBasedColumns(): void
    {
        $this->writeEntity('Bar.php', <<<'PHP'
        <?php
        namespace App\Entity;

        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'kuma_bar')]
        class Bar
        {
            #[ORM\Column(type: 'string', name: 'title_db', nullable: true)]
            private $title;

            #[ORM\Column(type: 'integer')]
            private $count;
        }
        PHP);

        $parser = $this->newParser();
        $info = $parser->getByFqcn('App\\Entity\\Bar');

        self::assertInstanceOf(DoctrineEntityInfo::class, $info);
        self::assertCount(2, $info->columns);

        $title = $this->columnByProperty($info->columns, 'title');
        self::assertSame('title_db', $title->columnName);
        self::assertSame('string', $title->type);
        self::assertTrue($title->nullable);

        $count = $this->columnByProperty($info->columns, 'count');
        self::assertSame('count', $count->columnName); // falls back to property
        self::assertSame('integer', $count->type);
        self::assertFalse($count->nullable);
    }

    public function testCapturesJoinColumnFkName(): void
    {
        // Replaces the plan's `testParsesAttributeBasedJoinTable` — the parser
        // does NOT carry JoinTable/InheritanceType/DiscriminatorMap; what it
        // DOES carry is JoinColumn FK-name capture wired to the relation.
        $this->writeEntity('Owner.php', <<<'PHP'
        <?php
        namespace App\Entity;

        use App\Entity\Employee;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'kuma_owner')]
        class Owner
        {
            #[ORM\ManyToOne(targetEntity: Employee::class)]
            #[ORM\JoinColumn(name: 'employee_id', referencedColumnName: 'id')]
            private $employee;
        }
        PHP);

        $parser = $this->newParser();
        $info = $parser->getByFqcn('App\\Entity\\Owner');

        self::assertInstanceOf(DoctrineEntityInfo::class, $info);
        self::assertCount(1, $info->relations);
        $rel = $info->relations[0];
        self::assertInstanceOf(DoctrineRelationInfo::class, $rel);
        self::assertSame('ManyToOne', $rel->relationType);
        self::assertSame('App\\Entity\\Employee', $rel->targetEntity);
        self::assertSame('employee', $rel->propertyName);
        self::assertSame('employee_id', $rel->fkColumn);
    }

    public function testResolvesManyToOneTargetEntityViaUseMap(): void
    {
        // Distinct from testFqcnUseMapResolution — covers the targetEntity:
        // ShortName::class form specifically with the use-map alias path.
        $this->writeEntity('Article.php', <<<'PHP'
        <?php
        namespace App\Entity;

        use Other\Domain\Author as ArticleAuthor;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'kuma_article')]
        class Article
        {
            #[ORM\ManyToOne(targetEntity: ArticleAuthor::class)]
            private $author;
        }
        PHP);

        $parser = $this->newParser();
        $info = $parser->getByFqcn('App\\Entity\\Article');

        self::assertInstanceOf(DoctrineEntityInfo::class, $info);
        self::assertCount(1, $info->relations);
        self::assertSame('Other\\Domain\\Author', $info->relations[0]->targetEntity);
    }

    public function testFqcnUseMapResolution(): void
    {
        // Phase 02.1 / D-41 invariant: short-name targetEntity resolves via
        // the parsed `use` map. This test must continue to pass after the
        // annotation strip — annotation removal MUST NOT break the use-map
        // FQCN resolver.
        $this->writeEntity('Comment.php', <<<'PHP'
        <?php
        namespace App\Entity;

        use Foo\Bar\Baz;
        use Doctrine\ORM\Mapping as ORM;

        #[ORM\Entity]
        #[ORM\Table(name: 'kuma_comment')]
        class Comment
        {
            #[ORM\ManyToOne(targetEntity: Baz::class)]
            private $baz;
        }
        PHP);

        $parser = $this->newParser();
        $info = $parser->getByFqcn('App\\Entity\\Comment');

        self::assertInstanceOf(DoctrineEntityInfo::class, $info);
        self::assertCount(1, $info->relations);
        self::assertSame('Foo\\Bar\\Baz', $info->relations[0]->targetEntity);
    }

    // -----------------------------------------------------------------------
    // Annotation-input removal proof (load-bearing)
    // -----------------------------------------------------------------------

    public function testIgnoresAnnotationInput(): void
    {
        // Annotation-only fixture (no PHP 8 attributes). Per SRC-20 the
        // parser MUST NOT fall back to annotation parsing — the entity must
        // be absent from getAll() / getByFqcn().
        //
        // Before Plan 04.1-06: docblock @ORM\Table fallback fires, the
        // entity is parsed, this test FAILS — that is the RED gate that
        // proves the fallback existed.
        //
        // After Plan 04.1-06: tableName stays empty, parseFile() returns
        // null at the empty-table guard, getAll() returns [].
        $this->writeEntity('LegacyFoo.php', <<<'PHP'
        <?php
        namespace App\Entity;

        /**
         * @ORM\Entity()
         * @ORM\Table(name="kuma_legacy_foo")
         */
        class LegacyFoo
        {
            /**
             * @ORM\Column(type="string", name="title_db", nullable=true)
             */
            private ?string $title = null;
        }
        PHP);

        $parser = $this->newParser();

        self::assertNull(
            $parser->getByFqcn('App\\Entity\\LegacyFoo'),
            'annotation-only entity must NOT be parsed (annotation paths removed)',
        );
        self::assertNull(
            $parser->getByTable('kuma_legacy_foo'),
            'annotation-only @ORM\\Table must NOT register a table mapping',
        );
        self::assertSame(
            [],
            $parser->getAll(),
            'no entities should be returned for annotation-only fixtures',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function newParser(): DoctrineEntityParser
    {
        $parser = new DoctrineEntityParser();
        $parser->sourceCheckoutPath = $this->tmpRoot;
        return $parser;
    }

    private function writeEntity(string $relativeFilename, string $php): void
    {
        $path = $this->tmpRoot . '/src/Entity/' . $relativeFilename;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            self::fail("could not create entity dir at {$dir}");
        }
        if (file_put_contents($path, $php) === false) {
            self::fail("could not write entity fixture at {$path}");
        }
    }

    /** @param DoctrineColumnInfo[] $columns */
    private function columnByProperty(array $columns, string $property): DoctrineColumnInfo
    {
        foreach ($columns as $col) {
            if ($col->propertyName === $property) {
                return $col;
            }
        }
        self::fail("no column with propertyName={$property} in parsed columns");
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
            if ($file->isDir()) {
                @rmdir((string) $file->getRealPath());
            } else {
                @unlink((string) $file->getRealPath());
            }
        }
        @rmdir($path);
    }
}
