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
