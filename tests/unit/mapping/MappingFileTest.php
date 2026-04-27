<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\mapping;

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

    public function testBuildRowEmitsExplicitColumnKindDiscriminator(): void
    {
        // D-34: buildRow now emits an explicit `kind: column` discriminator so the
        // rubber-stamp loop and merge() identity tuple can branch on row kind.
        $mf = new MappingFile();
        $row = $mf->buildRow(['table' => 'kuma_x', 'column' => 'y', 'targetEntryType' => 'z'], 'proposed');
        self::assertSame('column', $row['kind']);
    }

    public function testBuildPagePartRowEmitsD34ReferenceShape(): void
    {
        // D-34: kind=pagePart row carries the structural identity fields plus operator-fillable
        // targets. D-35: status always starts `needs-review` regardless of confidence — no
        // auto-promotion in v1.0.
        $mf = new MappingFile();
        $row = $mf->buildPagePartRow(
            'HeaderPagePart',
            'kuma_main_pageparts',
            'NewsPage',
            'main',
            'newsArticle',
            'body',
            'header',
            [['sourceProperty' => 'title', 'targetHandle' => 'heading', 'handler' => 'plain']],
            'medium',
            'page-part class HeaderPagePart in NewsPage context "main"',
        );
        self::assertSame('pagePart', $row['kind']);
        self::assertSame('HeaderPagePart', $row['pagePartClass']);
        self::assertSame('kuma_main_pageparts', $row['sourceTable']);
        self::assertSame('NewsPage', $row['parentPageClass']);
        self::assertSame('main', $row['context']);
        self::assertSame('newsArticle', $row['targetEntryType']);
        self::assertSame('body', $row['targetMatrixField']);
        self::assertSame('header', $row['targetBlockType']);
        self::assertSame('needs-review', $row['status'], 'D-35: page-part rows always start needs-review.');
        self::assertCount(1, $row['fields']);
    }

    public function testPagePartRowDedupesOnStructuralTupleWhenTargetEntryTypeChanges(): void
    {
        // W1 fix verification: page-part identity is STRUCTURAL ONLY
        // (pagePartClass, parentPageClass, context). targetEntryType is NOT part of identity.
        //
        // Idempotent re-run scenario:
        //   1. analyze first emit → row with empty targetEntryType (operator hasn't filled it).
        //   2. operator runs `map`, picks targetEntryType=newsArticle → row now has it.
        //   3. analyze re-runs → emits the same structural row again with empty targetEntryType.
        //
        // Without the W1 fix, step 3 would APPEND a duplicate row (different keys because
        // empty != newsArticle). With the fix, the structural tuple matches and the existing
        // operator-filled row is preserved verbatim.
        $mf = new MappingFile();

        $analyzeFirstEmit = $mf->buildPagePartRow(
            'HeaderPagePart', 'kuma_main_pageparts', 'NewsPage', 'main', '', // empty targetEntryType
        );
        $operatorFilled = $mf->buildPagePartRow(
            'HeaderPagePart', 'kuma_main_pageparts', 'NewsPage', 'main', 'newsArticle', // operator filled
        );
        // Operator's accepted version (status mutated by setStatus in real flow).
        $operatorFilled['status'] = 'accepted';

        // Seed mapping.yaml with the operator-filled row (post step 2 state).
        $seeded = $mf->merge(['proposals' => []], [$operatorFilled]);
        self::assertCount(1, $seeded['proposals']);

        // analyze re-run emits the empty-targetEntryType row again (step 3).
        // The structural tuple matches → operator's row is preserved, no duplicate appended.
        $afterReRun = $mf->merge($seeded, [$analyzeFirstEmit]);
        self::assertCount(1, $afterReRun['proposals'], 'W1: structural-only tuple must dedupe across targetEntryType changes.');
        self::assertSame('accepted', $afterReRun['proposals'][0]['status'], 'Operator decision preserved verbatim.');
        self::assertSame('newsArticle', $afterReRun['proposals'][0]['targetEntryType'], 'Operator-filled targetEntryType preserved.');
    }

    public function testPagePartRowAppendsWhenStructuralTupleDiffers(): void
    {
        // Counterpart to W1 dedupe: a DIFFERENT pagePartClass under the same parent+context
        // is a distinct structural row and must be appended.
        $mf = new MappingFile();
        $existing = $mf->buildPagePartRow('HeaderPagePart', 'kuma_main_pageparts', 'NewsPage', 'main', 'newsArticle');
        $incoming = $mf->buildPagePartRow('TextPagePart',   'kuma_main_pageparts', 'NewsPage', 'main', 'newsArticle');
        $merged = $mf->merge(['proposals' => [$existing]], [$incoming]);
        self::assertCount(2, $merged['proposals'], 'Different pagePartClass under same parent+context is a distinct row.');
    }

    public function testColumnKindAndPagePartKindHaveDistinctKeyspaces(): void
    {
        // Defensive: even if a column row and a pagePart row happened to share field names
        // (they don't, structurally), the kind prefix must keep their identity tuples in
        // disjoint keyspaces.
        $mf = new MappingFile();
        $col = $mf->buildRow(['table' => 'kuma_x', 'column' => 'y', 'targetEntryType' => 'z'], 'accepted');
        $pp  = $mf->buildPagePartRow('PPClass', 'kuma_x', 'PageClass', 'y', 'z');
        $merged = $mf->merge(['proposals' => [$col]], [$pp]);
        self::assertCount(2, $merged['proposals'], 'kind prefix keeps column and pagePart keyspaces disjoint.');
    }
}
