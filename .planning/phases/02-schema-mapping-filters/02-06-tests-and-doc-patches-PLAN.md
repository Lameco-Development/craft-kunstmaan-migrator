---
phase: 02-schema-mapping-filters
plan: 06
type: execute
wave: 5
depends_on:
  - "02-01"
  - "02-02"
  - "02-03"
  - "02-05"
files_modified:
  - tests/filter/MigrationFiltersTest.php
  - tests/filter/FilterFactoryTest.php
  - tests/mapping/MappingFileTest.php
  - tests/mapping/CoverageAuditorTest.php
  - .planning/REQUIREMENTS.md
  - .planning/ROADMAP.md
autonomous: true
requirements:
  - FILT-01
  - MAP-04
  - MAP-06
requirements_addressed:
  - FILT-01
  - MAP-04
  - MAP-06
must_haves:
  truths:
    - "PHPUnit suite covers MigrationFilters value-object shape (3 readonly properties; no maxPerEntity)"
    - "PHPUnit suite covers FilterFactory CLI-merge semantics (null falls through; '' clears default; comma-split trims)"
    - "PHPUnit suite covers MappingFile::merge skip-existing semantics (D-04 — operator decisions sacred)"
    - "PHPUnit suite covers CoverageAuditor data-bearing-column rule + STRUCTURAL_IGNORE filter"
    - "REQUIREMENTS.md FILT-01 patched: --max-per-entity=N clause dropped"
    - "ROADMAP.md Phase 2 success criterion 5 patched: --max-per-entity= dropped from flag list (now three flags)"
    - "composer test exits 0 with all new tests passing"
  artifacts:
    - path: "tests/filter/MigrationFiltersTest.php"
      provides: "VO shape characterization"
      contains: "final class MigrationFiltersTest extends TestCase"
    - path: "tests/filter/FilterFactoryTest.php"
      provides: "CLI-merge semantics characterization"
      contains: "final class FilterFactoryTest extends TestCase"
    - path: "tests/mapping/MappingFileTest.php"
      provides: "Merge + buildRow + writeAtomic characterization"
      contains: "final class MappingFileTest extends TestCase"
    - path: "tests/mapping/CoverageAuditorTest.php"
      provides: "Coverage rule characterization with fixture schema-dump and proposals"
      contains: "final class CoverageAuditorTest extends TestCase"
  key_links:
    - from: ".planning/REQUIREMENTS.md"
      to: "FILT-01 wording"
      via: "Edit: drop '--max-per-entity=N' clause from FILT-01"
      pattern: "FILT-01"
    - from: ".planning/ROADMAP.md"
      to: "Phase 2 success criterion 5"
      via: "Edit: drop '--max-per-entity=' from flag list"
      pattern: "max-per-entity"
---

<objective>
Close Phase 2 by characterizing the cheap-to-test Phase 2 modules with PHPUnit and shipping the doc patches mandated by D-12.

Purpose:
- Tests: Phase 2 ships 4 unit tests covering the modules that don't require Craft / DB bootstrap (MigrationFilters VO, FilterFactory string parsing, MappingFile::merge / buildRow, CoverageAuditor with fixture data). Heavy modules (HeuristicProposer, LlmClassifier, AnalyzeController, MapController, MappingAuditor — which needs FieldLayout) are deferred to Phase 5 / TST-02 characterization fixtures because they require real corpus data or live Craft runtime.
- Doc patches: REQUIREMENTS.md FILT-01 + ROADMAP.md Phase 2 success criterion 5 must be updated per D-12 to reflect the dropped `--max-per-entity` flag.

Output: 4 new test files and 2 patched doc files. No production-source changes.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/REQUIREMENTS.md
@.planning/ROADMAP.md
@.planning/phases/02-schema-mapping-filters/02-CONTEXT.md
@tests/PluginBootstrapTest.php
@phpunit.xml.dist

@src/filter/MigrationFilters.php
@src/filter/FilterFactory.php
@src/mapping/MappingFile.php
@src/mapping/CoverageAuditor.php

<interfaces>
<!-- From tests/PluginBootstrapTest.php (Phase 1 / D-21) — test idiom -->
namespace lameco\kunstmaanmigrator\tests;
use PHPUnit\Framework\TestCase;
final class PluginBootstrapTest extends TestCase {
    public function testFooBar(): void {
        self::assertSame(...);
    }
}

<!-- From src/filter/MigrationFilters.php (Plan 01) -->
final class MigrationFilters {
    public function __construct(
        public readonly array $entities = [],
        public readonly array $locales = [],
        public readonly ?string $since = null,
    ) {}
}

<!-- From src/filter/FilterFactory.php (Plan 01) -->
public function fromCli(?string $entitiesArg, ?string $localesArg, ?string $sinceArg): MigrationFilters;
// Reads Plugin::getInstance()->getSettings()->defaultEntities/defaultLocales/defaultSince — must be mocked or skipped

<!-- From src/mapping/MappingFile.php (Plan 02) -->
public function buildRow(array $proposal, string $initialStatus): array;
public function merge(array $existing, array $incoming): array;
public function writeAtomic(string $path, string $contents): bool;

<!-- From src/mapping/CoverageAuditor.php (Plan 05) -->
public function audit(array $schemaDump, array $mappingProposals): array;
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: PHPUnit tests for MigrationFilters VO + FilterFactory (CLI-merge characterization)</name>
  <files>tests/filter/MigrationFiltersTest.php, tests/filter/FilterFactoryTest.php</files>
  <read_first>
    - tests/PluginBootstrapTest.php (Phase 1 idiom — TestCase, namespace, final class, self::assertX)
    - phpunit.xml.dist (test path config — confirm tests/ is the test root)
    - src/filter/MigrationFilters.php (3 readonly properties; constructor signature)
    - src/filter/FilterFactory.php (fromCli signature; the Settings::default* dependency — needs careful test design since FilterFactory calls Plugin::getInstance() which needs the Plugin to be loaded)
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-10 merge semantics — null fall-through / '' clears / comma-split)
  </read_first>
  <action>
**Step A — create `tests/filter/MigrationFiltersTest.php`:**

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\filter;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Characterization tests for the MigrationFilters value object (Plan 01).
 *
 * D-12: VO has THREE properties — entities, locales, since. NOT four.
 *       maxPerEntity must NOT exist on this class.
 *
 * D-13: VO is immutable; readonly enforces this at the language level.
 */
final class MigrationFiltersTest extends TestCase
{
    public function testDefaultConstructorReturnsEmptyEntitiesAndLocalesWithNullSince(): void
    {
        $f = new MigrationFilters();
        self::assertSame([], $f->entities);
        self::assertSame([], $f->locales);
        self::assertNull($f->since);
    }

    public function testNamedArgConstructorPreservesValues(): void
    {
        $f = new MigrationFilters(
            entities: ['NewsPage', 'EventPage'],
            locales:  ['nl', 'fr'],
            since:    '2025-01-01',
        );
        self::assertSame(['NewsPage', 'EventPage'], $f->entities);
        self::assertSame(['nl', 'fr'], $f->locales);
        self::assertSame('2025-01-01', $f->since);
    }

    public function testClassHasExactlyThreePublicProperties(): void
    {
        // D-12: --max-per-entity is DROPPED. The VO must have exactly 3 properties.
        $rc = new ReflectionClass(MigrationFilters::class);
        $publicProps = array_filter(
            $rc->getProperties(),
            static fn(\ReflectionProperty $p): bool => $p->isPublic() && !$p->isStatic(),
        );
        $names = array_map(static fn(\ReflectionProperty $p): string => $p->getName(), $publicProps);
        sort($names);
        self::assertSame(['entities', 'locales', 'since'], array_values($names));
    }

    public function testNoMaxPerEntityProperty(): void
    {
        // D-12 explicit: max-per-entity is dropped. Hard-fail if it ever resurfaces.
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertFalse($rc->hasProperty('maxPerEntity'),
            'D-12: --max-per-entity is dropped from v1.0; MigrationFilters must not declare maxPerEntity.');
    }

    public function testEntitiesPropertyIsReadonly(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->getProperty('entities')->isReadOnly(),
            'D-13: VO must be immutable.');
    }

    public function testLocalesPropertyIsReadonly(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->getProperty('locales')->isReadOnly());
    }

    public function testSincePropertyIsReadonly(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->getProperty('since')->isReadOnly());
    }

    public function testClassIsFinal(): void
    {
        $rc = new ReflectionClass(MigrationFilters::class);
        self::assertTrue($rc->isFinal(), 'MigrationFilters must be final.');
    }
}
```

**Step B — create `tests/filter/FilterFactoryTest.php`:**

FilterFactory's `fromCli` reads `Plugin::getInstance()->getSettings()->default*`, which requires the Plugin singleton to exist. Since Phase 1's `tests/bootstrap.php` is `vendor/autoload.php` only (not Craft-bootstrapped per D-21), we cannot call `fromCli` directly without mocking. Two options:
- (Preferred) Test the parse logic at the source-level via a helper static or via copying the parse expressions into the test (decoupling from Plugin). This requires no Plugin refactor.
- (Alternative) Skip the Settings-merge tests and only assert source-level structure (grep-style assertions on the file).

Use the **structural-source-assertion approach** consistent with PluginBootstrapTest's `testPluginDeclaresLegacyDbServiceComponent` (line 41–52), which uses file_get_contents + assertStringContainsString. This keeps tests self-contained without a Craft bootstrap.

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\filter;

use lameco\kunstmaanmigrator\filter\FilterFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Source-level characterization for FilterFactory (Plan 01).
 *
 * Why source-level instead of behavior-level: fromCli() reads Plugin::getInstance()
 * which requires a Craft bootstrap (out of scope for Phase 2 unit suite per D-21).
 * We assert the structural contract here; Phase 5 / TST-01 + TST-02 will exercise
 * fromCli end-to-end against a real Plugin instance.
 *
 * Test surface: D-10 merge semantics live in the source; we verify the source
 * contains the load-bearing patterns (null fall-through, empty-string clear,
 * comma-split with trim, three calls into Settings::default*).
 */
final class FilterFactoryTest extends TestCase
{
    public function testClassIsLoadable(): void
    {
        self::assertTrue(class_exists(FilterFactory::class));
    }

    public function testFromCliMethodSignature(): void
    {
        $rc = new ReflectionClass(FilterFactory::class);
        self::assertTrue($rc->hasMethod('fromCli'));
        $m = new ReflectionMethod(FilterFactory::class, 'fromCli');
        self::assertCount(3, $m->getParameters(), 'fromCli takes 3 args: entitiesArg, localesArg, sinceArg.');
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $m->getParameters());
        self::assertSame(['entitiesArg', 'localesArg', 'sinceArg'], $names);
        self::assertSame(MigrationFilters::class, (string) $m->getReturnType(),
            'fromCli must return MigrationFilters (FQCN).');
    }

    public function testSourceImplementsD10MergeSemantics(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(FilterFactory::class))->getFileName());

        // null fall-through — '!== null' branches with ?: explode patterns
        self::assertStringContainsString("\$entitiesArg !== null", $source,
            'D-10: null CLI arg must fall through to Settings default.');
        self::assertStringContainsString("\$localesArg !== null", $source);
        self::assertStringContainsString("\$sinceArg !== null", $source);

        // empty-string clears the default
        self::assertStringContainsString("=== ''", $source,
            "D-10: empty CLI arg ('') must clear the default.");

        // comma-split with trim for entities and locales
        self::assertStringContainsString("explode(',', \$entitiesArg)", $source);
        self::assertStringContainsString("explode(',', \$localesArg)", $source);
        self::assertStringContainsString("array_map('trim',", $source);

        // Reads from Settings::default* via Plugin::getInstance()
        self::assertStringContainsString('defaultEntities', $source);
        self::assertStringContainsString('defaultLocales', $source);
        self::assertStringContainsString('defaultSince', $source);
        self::assertStringContainsString('Plugin::getInstance()->getSettings()', $source);
    }

    public function testReturnsNewMigrationFilters(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(FilterFactory::class))->getFileName());
        self::assertStringContainsString('new MigrationFilters', $source,
            'fromCli must construct and return a MigrationFilters.');
    }
}
```

Note on `MigrationFilters::class` in the import-less scope: add `use lameco\kunstmaanmigrator\filter\MigrationFilters;` at the top alongside `FilterFactory` if your test references `MigrationFilters::class` literally. Adjust the file's `use` block accordingly.
  </action>
  <verify>
    <automated>composer test</automated>
  </verify>
  <acceptance_criteria>
    - `composer test` exits 0
    - `php -l tests/filter/MigrationFiltersTest.php` exits 0
    - `php -l tests/filter/FilterFactoryTest.php` exits 0
    - `grep -c 'final class MigrationFiltersTest extends TestCase' tests/filter/MigrationFiltersTest.php` equals 1
    - `grep -c 'final class FilterFactoryTest extends TestCase' tests/filter/FilterFactoryTest.php` equals 1
    - `grep -c 'testNoMaxPerEntityProperty' tests/filter/MigrationFiltersTest.php` equals 1
    - `grep -c 'testClassHasExactlyThreePublicProperties' tests/filter/MigrationFiltersTest.php` equals 1
    - `grep -c "self::assertSame(\['entities', 'locales', 'since'\]" tests/filter/MigrationFiltersTest.php` equals 1
    - `grep -c "testSourceImplementsD10MergeSemantics" tests/filter/FilterFactoryTest.php` equals 1
    - `composer test` output mentions at least 8 new tests passing (across both files)
  </acceptance_criteria>
  <done>MigrationFilters VO + FilterFactory have characterization coverage; D-12 enforced by testNoMaxPerEntityProperty; composer test green.</done>
</task>

<task type="auto">
  <name>Task 2: PHPUnit tests for MappingFile (merge skip-existing) + CoverageAuditor (data-bearing rule)</name>
  <files>tests/mapping/MappingFileTest.php, tests/mapping/CoverageAuditorTest.php</files>
  <read_first>
    - tests/PluginBootstrapTest.php (idiom)
    - tests/filter/MigrationFiltersTest.php (created in Task 1 — namespace pattern reference)
    - src/mapping/MappingFile.php (merge + buildRow signatures and behavior — see Plan 02)
    - src/mapping/CoverageAuditor.php (audit signature and STRUCTURAL_IGNORE constant)
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-04 skip-existing semantics; D-14 STRUCTURAL_IGNORE)
  </read_first>
  <action>
**Step A — create `tests/mapping/MappingFileTest.php`:**

`MappingFile` is a Yii Component that calls `Plugin::getInstance()` only in `resolvePath` (which we don't test here). The pure methods (`buildRow`, `merge`, `writeAtomic`, `writeAtomicJson`, `load`) can be exercised directly by instantiating with `new MappingFile()`.

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\mapping;

use lameco\kunstmaanmigrator\mapping\MappingFile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MappingFile pure methods (Plan 02).
 *
 * Tests buildRow + merge + writeAtomic + writeAtomicJson + load. resolvePath() is
 * skipped — it requires Plugin::getInstance() (out of scope for unit context per D-21).
 */
final class MappingFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kunstmaan-migrator-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up tmpDir.
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testBuildRowAppliesInitialStatusAndDefaults(): void
    {
        $mf = new MappingFile();
        $row = $mf->buildRow(
            [
                'table'  => 'kuma_news_page',
                'column' => 'body_richtext',
                'targetEntryType' => 'newsArticle',
                'targetHandle'    => 'body',
                'handler'    => 'ckeditor',
                'confidence' => 'high',
                'rationale'  => 'auto-match',
                'fillRate'   => 0.94,
                'sqlType'    => 'LONGTEXT',
                'samples'    => ['<p>a</p>', '<p>b</p>', '<p>c</p>', '<p>d</p>'],
            ],
            'accepted',
        );
        self::assertSame('accepted', $row['status']);
        self::assertSame('kuma_news_page', $row['table']);
        self::assertSame('body_richtext', $row['column']);
        self::assertSame(0.94, $row['fillRate']);
        self::assertCount(3, $row['samples'], 'Samples must be capped at 3.');
    }

    public function testMergePreservesExistingRowsVerbatimOnD04SkipExisting(): void
    {
        $mf = new MappingFile();
        // Operator already accepted this row — must not be overwritten.
        $existing = ['proposals' => [
            [
                'table' => 'kuma_news_page',
                'column' => 'body',
                'targetEntryType' => 'newsArticle',
                'targetHandle' => 'body',
                'handler' => 'ckeditor',
                'confidence' => 'high',
                'rationale' => 'OPERATOR DECISION',
                'fillRate' => 0.9,
                'sqlType' => 'LONGTEXT',
                'samples' => [],
                'status' => 'accepted',
            ],
        ]];
        // Re-running analyze produces a DIFFERENT proposal for the same tuple — must not overwrite.
        $incoming = [
            [
                'table' => 'kuma_news_page',
                'column' => 'body',
                'targetEntryType' => 'newsArticle',
                'targetHandle' => 'somewhere_else',  // different! Operator's pick must win.
                'handler' => 'plain',
                'confidence' => 'medium',
                'rationale' => 'fresh proposal',
                'fillRate' => 0.9,
                'sqlType' => 'LONGTEXT',
                'samples' => [],
                'status' => 'proposed',
            ],
        ];
        $merged = $mf->merge($existing, $incoming);
        self::assertCount(1, $merged['proposals']);
        // Operator's row kept verbatim
        self::assertSame('accepted', $merged['proposals'][0]['status']);
        self::assertSame('OPERATOR DECISION', $merged['proposals'][0]['rationale']);
        self::assertSame('body', $merged['proposals'][0]['targetHandle']);
    }

    public function testMergeAppendsNewTuples(): void
    {
        $mf = new MappingFile();
        $existing = ['proposals' => [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'accepted'],
        ]];
        $incoming = [
            ['table' => 'kuma_news_page', 'column' => 'subtitle', 'targetEntryType' => 'newsArticle', 'status' => 'proposed'],
        ];
        $merged = $mf->merge($existing, $incoming);
        self::assertCount(2, $merged['proposals']);
        self::assertSame('subtitle', $merged['proposals'][1]['column']);
    }

    public function testMergeIdentityIsTableColumnEntryTypeTuple(): void
    {
        // Same (table, column) but DIFFERENT targetEntryType → must be treated as a new row.
        $mf = new MappingFile();
        $existing = ['proposals' => [
            ['table' => 'kuma_event_page', 'column' => 'body', 'targetEntryType' => 'event', 'status' => 'accepted'],
        ]];
        $incoming = [
            ['table' => 'kuma_event_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'proposed'],
        ];
        $merged = $mf->merge($existing, $incoming);
        self::assertCount(2, $merged['proposals'], 'Identity tuple is (table, column, targetEntryType) — different entry type must add a row.');
    }

    public function testWriteAtomicTmpRenameLeavesOriginalIntactOnError(): void
    {
        $mf = new MappingFile();
        $path = $this->tmpDir . '/x.txt';
        self::assertTrue($mf->writeAtomic($path, "first\n"));
        self::assertSame("first\n", file_get_contents($path));
        self::assertTrue($mf->writeAtomic($path, "second\n"));
        self::assertSame("second\n", file_get_contents($path));
        // No leftover .tmp.* siblings (the rename consumed them).
        $leftovers = glob($this->tmpDir . '/x.txt.tmp.*') ?: [];
        self::assertSame([], $leftovers, 'No tmp leftovers after a successful atomic write.');
    }

    public function testLoadReturnsEmptyProposalsForMissingFile(): void
    {
        $mf = new MappingFile();
        $data = $mf->load($this->tmpDir . '/does-not-exist.yaml');
        self::assertSame(['proposals' => []], $data);
    }

    public function testLoadParsesYamlAndReturnsListOfProposals(): void
    {
        $mf = new MappingFile();
        $path = $this->tmpDir . '/m.yaml';
        $yaml = "proposals:\n  - table: kuma_news_page\n    column: body\n    status: accepted\n";
        file_put_contents($path, $yaml);
        $data = $mf->load($path);
        self::assertCount(1, $data['proposals']);
        self::assertSame('kuma_news_page', $data['proposals'][0]['table']);
    }
}
```

**Step B — create `tests/mapping/CoverageAuditorTest.php`:**

CoverageAuditor's `audit` is pure — fixture in, list out. Easy to test.

```php
<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\mapping;

use lameco\kunstmaanmigrator\mapping\CoverageAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CoverageAuditor (Plan 05) — D-14 data-bearing-column rule.
 */
final class CoverageAuditorTest extends TestCase
{
    public function testZeroFillRateColumnIsNotAViolation(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body',     'sqlType' => 'TEXT',    'fillRate' => 0.0, 'samples' => []],
                ],
            ],
        ];
        // No mapping rows. fillRate=0 columns are NOT data-bearing → no violation.
        self::assertSame([], $a->audit($dump, []));
    }

    public function testStructuralColumnsAreIgnoredEvenWhenFilled(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'id',         'sqlType' => 'int',  'fillRate' => 1.0],
                    ['column' => 'parent_id',  'sqlType' => 'int',  'fillRate' => 0.5],
                    ['column' => 'lft',        'sqlType' => 'int',  'fillRate' => 1.0],
                    ['column' => 'rgt',        'sqlType' => 'int',  'fillRate' => 1.0],
                    ['column' => 'created',    'sqlType' => 'datetime', 'fillRate' => 1.0],
                ],
            ],
        ];
        // No mapping rows, but all columns are structural — no violations.
        self::assertSame([], $a->audit($dump, []));
    }

    public function testDataBearingColumnWithoutMappingRowIsAViolation(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $violations = $a->audit($dump, []);
        self::assertCount(1, $violations);
        self::assertSame('kuma_news_page', $violations[0]['table']);
        self::assertSame('body', $violations[0]['column']);
        self::assertSame(0.94, $violations[0]['fillRate']);
        self::assertSame(100, $violations[0]['rows']);
    }

    public function testAcceptedRowCoversTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'accepted'],
        ];
        self::assertSame([], $a->audit($dump, $proposals));
    }

    public function testDroppedRowCoversTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'dropped', 'rationale' => 'no Craft target'],
        ];
        self::assertSame([], $a->audit($dump, $proposals), 'D-14: dropped also counts as covered.');
    }

    public function testProposedRowDoesNotCoverTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'proposed'],
        ];
        $violations = $a->audit($dump, $proposals);
        self::assertCount(1, $violations, 'D-14: proposed/needs-review do NOT count as covered.');
    }

    public function testNeedsReviewDoesNotCoverTheColumn(): void
    {
        $a = new CoverageAuditor();
        $dump = [
            'tables'  => ['kuma_news_page' => 100],
            'columns' => [
                'kuma_news_page' => [
                    ['column' => 'body', 'sqlType' => 'TEXT', 'fillRate' => 0.94],
                ],
            ],
        ];
        $proposals = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'targetEntryType' => 'newsArticle', 'status' => 'needs-review'],
        ];
        self::assertCount(1, $a->audit($dump, $proposals));
    }

    public function testRenderViolationsProducesGroupedStderrBlock(): void
    {
        $a = new CoverageAuditor();
        $violations = [
            ['table' => 'kuma_news_page', 'column' => 'body', 'fillRate' => 0.94, 'rows' => 100],
            ['table' => 'kuma_news_page', 'column' => 'lead', 'fillRate' => 0.50, 'rows' => 100],
            ['table' => 'kuma_event_page', 'column' => 'body', 'fillRate' => 0.80, 'rows' => 50],
        ];
        $rendered = $a->renderViolations($violations);
        self::assertStringContainsString('FAIL kuma_news_page: 2 unmapped data-bearing column(s)', $rendered);
        self::assertStringContainsString('FAIL kuma_event_page: 1 unmapped data-bearing column(s)', $rendered);
        self::assertStringContainsString('- body (fill=94.0%, rows=100)', $rendered);
    }
}
```

**Step C — confirm phpunit.xml.dist captures the new directories:**

Phase 1's phpunit.xml.dist likely lists `tests/` as a single suite path. Verify it picks up `tests/filter/*Test.php` and `tests/mapping/*Test.php` automatically. If the config is path-globbed (`<directory>tests</directory>`), no change needed. If it's specific-files, add the four new files. Read the file first.
  </action>
  <verify>
    <automated>composer test</automated>
  </verify>
  <acceptance_criteria>
    - `composer test` exits 0
    - `php -l tests/mapping/MappingFileTest.php` exits 0
    - `php -l tests/mapping/CoverageAuditorTest.php` exits 0
    - `grep -c 'final class MappingFileTest extends TestCase' tests/mapping/MappingFileTest.php` equals 1
    - `grep -c 'final class CoverageAuditorTest extends TestCase' tests/mapping/CoverageAuditorTest.php` equals 1
    - `grep -c 'testMergePreservesExistingRowsVerbatim' tests/mapping/MappingFileTest.php` equals 1
    - `grep -c 'testMergeIdentityIsTableColumnEntryTypeTuple' tests/mapping/MappingFileTest.php` equals 1
    - `grep -c 'testStructuralColumnsAreIgnored' tests/mapping/CoverageAuditorTest.php` equals 1
    - `grep -c 'testDataBearingColumnWithoutMappingRow' tests/mapping/CoverageAuditorTest.php` equals 1
    - `grep -c 'testProposedRowDoesNotCoverTheColumn' tests/mapping/CoverageAuditorTest.php` equals 1
    - composer test reports >= 18 total tests passing (3 from Phase 1 + 15+ new across all 4 Phase 2 test files)
  </acceptance_criteria>
  <done>MappingFile + CoverageAuditor have characterization coverage for D-04 skip-existing and D-14 data-bearing-column rules; composer test green.</done>
</task>

<task type="auto">
  <name>Task 3: Patch REQUIREMENTS.md FILT-01 + ROADMAP.md Phase 2 success criterion 5 (D-12)</name>
  <files>.planning/REQUIREMENTS.md, .planning/ROADMAP.md</files>
  <read_first>
    - .planning/REQUIREMENTS.md (FILT-01 line — currently includes "--max-per-entity=N cap" clause)
    - .planning/ROADMAP.md (Phase 2 success criterion 5 — currently lists 4 flags including --max-per-entity=)
    - .planning/phases/02-schema-mapping-filters/02-CONTEXT.md (D-12 mandate — exact wording change)
  </read_first>
  <action>
**Edit 1 — REQUIREMENTS.md FILT-01:**

Find the exact line in `.planning/REQUIREMENTS.md`:
```
- [ ] **FILT-01**: A `MigrationFilters` value object captures: included entity types (allow-list), locale subset, `--since=YYYY-MM-DD` floor, `--max-per-entity=N` cap.
```

Replace with:
```
- [ ] **FILT-01**: A `MigrationFilters` value object captures: included entity types (allow-list), locale subset, `--since=YYYY-MM-DD` floor. _(D-12 / Phase 2: `--max-per-entity=N` cap dropped from v1.0 scope per operator decision; rehearsal scoping is covered by `--entities` + `--since`.)_
```

**Edit 2 — ROADMAP.md Phase 2 success criterion 5:**

Find the exact line in `.planning/ROADMAP.md` under "### Phase 2: Schema, Mapping & Filters" → "**Success criteria:**":
```
5. The five top-level CLI commands all accept `--entities=`, `--locales=`, `--since=`, `--max-per-entity=` and produce identical filter behaviour at every stage.
```

Replace with:
```
5. The five top-level CLI commands all accept `--entities=`, `--locales=`, `--since=` and produce identical filter behaviour at every stage. _(D-12: `--max-per-entity=` dropped from v1.0 — three flags, not four.)_
```

These edits are doc-only and have no functional impact. They bring the requirement and roadmap into alignment with the production code shipped by Plans 01–05 (3-property MigrationFilters, 3-flag controllers).

Note: The ROADMAP.md already shows Phase 2 as "active" — do NOT alter the Plans list (it tracks per-plan status which Phase 2 close-out maintenance updates separately).
  </action>
  <verify>
    <automated>! grep -F -- '--max-per-entity' .planning/REQUIREMENTS.md && ! grep -F -- '--max-per-entity' .planning/ROADMAP.md && grep -F 'D-12' .planning/REQUIREMENTS.md && grep -F 'D-12' .planning/ROADMAP.md</automated>
  </verify>
  <acceptance_criteria>
    - Command `grep -c -F -- '--max-per-entity' .planning/REQUIREMENTS.md` outputs `0` (FILT-01 cap clause is gone)
    - Command `grep -c -F -- '--max-per-entity' .planning/ROADMAP.md` outputs `0` (success criterion 5 fourth flag is gone)
    - Command `grep -c -F 'D-12' .planning/REQUIREMENTS.md` outputs a value `>= 1` (the patch reference is present)
    - Command `grep -c -F 'D-12' .planning/ROADMAP.md` outputs a value `>= 1` (the patch reference is present)
    - Command `grep -nE '^- \[ \] \*\*FILT-01\*\*' .planning/REQUIREMENTS.md` returns exactly one matching line and that line does NOT contain `--max-per-entity`
  </acceptance_criteria>
  <done>REQUIREMENTS.md FILT-01 + ROADMAP.md Phase 2 success criterion 5 reflect the D-12 dropped --max-per-entity decision.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Filesystem (test tmp dir) → MappingFileTest | Test creates and reads its own files in `sys_get_temp_dir()/kunstmaan-migrator-test-*`. Standard PHPUnit test isolation. |
| Source files → reflection-based tests | Tests read FQCN-resolved file paths via Reflection. No untrusted input crosses any boundary in the test surface. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-2-23 | T (Tampering) | Test fixture YAML files leak between tests | mitigate | Each test uses a unique `sys_get_temp_dir() . '/kunstmaan-migrator-test-' . bin2hex(random_bytes(4))` directory created in `setUp` and cleaned in `tearDown`. No cross-test contamination. |
| T-2-24 | I (Information Disclosure) | Test files contain literal sample data | accept | Test fixtures use synthetic data ('OPERATOR DECISION', 'kuma_news_page', etc.). No real PII or secrets. |
</threat_model>

<verification>
- `php -l` passes on all 4 new test files
- `composer test` exits 0
- The total assertion count grows by at least 30 across the 4 new test files (8 in MigrationFiltersTest, 4 in FilterFactoryTest, 7 in MappingFileTest, 8 in CoverageAuditorTest — approximately 27–30 assertions total)
- REQUIREMENTS.md FILT-01 no longer references `--max-per-entity=N`
- ROADMAP.md Phase 2 success criterion 5 no longer references `--max-per-entity=`
- Both doc patches reference D-12 for traceability
</verification>

<success_criteria>
1. `MigrationFiltersTest` enforces the 3-property shape and explicitly fails if `maxPerEntity` resurfaces (D-12 hardening).
2. `FilterFactoryTest` characterizes the D-10 merge semantics (null fall-through, '' clears, comma-split with trim) at the source level — Phase 5 will exercise behavior end-to-end with a real Plugin instance.
3. `MappingFileTest` characterizes D-04 skip-existing semantics with concrete fixture rows (operator decision sacred), the (table,column,targetEntryType) identity tuple, and the atomic-write contract.
4. `CoverageAuditorTest` characterizes the D-14 data-bearing-column rule (fillRate>0 AND not in STRUCTURAL_IGNORE) and the coverage rule (status ∈ {accepted, dropped} = covered; proposed/needs-review = not covered).
5. `composer test` exits 0 across the full suite (Phase 1 + Phase 2).
6. REQUIREMENTS.md FILT-01 and ROADMAP.md Phase 2 success criterion 5 are doc-aligned with the shipped 3-property `MigrationFilters`.
</success_criteria>

<output>
After completion, create `.planning/phases/02-schema-mapping-filters/02-06-SUMMARY.md` documenting:
- 4 test files created with assertion counts
- 2 doc patches with before/after excerpts
- Total composer test count before/after this plan
- Confirmation that no production source was modified by this plan (tests + docs only)
- Phase 2 close-out checklist (every Phase 2 requirement ID covered, Plans 01-06 complete)
</output>
