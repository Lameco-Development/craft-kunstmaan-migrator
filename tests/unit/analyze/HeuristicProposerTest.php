<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\analyze;

use lameco\kunstmaanmigrator\analyze\HeuristicProposer;
use lameco\kunstmaanmigrator\source\DoctrineColumnInfo;
use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for the deterministic heuristics
 * + confidence routing in HeuristicProposer.
 *
 * Pure-PHPUnit: HeuristicProposer is a Yii Component but its autoMatch() method
 * takes data structures (violations + craftFieldIndex) and returns arrays — no
 * Craft container needed. Direct instantiation; the `entityIndex` and
 * `acceptedRows` public properties are populated for heuristic 1.5 cases.
 *
 * Coverage target: ≥ 70.0% line coverage on src/analyze/HeuristicProposer.php
 * (TST-01 / D-08 gate enforced by tools/check-coverage.php in CI).
 *
 * Refactor abstinence: this test exercises the production surface verbatim;
 * no source-code changes required.
 */
final class HeuristicProposerTest extends TestCase
{
    /**
     * Build a single violation row with sensible defaults overridable per test.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function violation(array $overrides = []): array
    {
        return $overrides + [
            'table'           => 'kuma_news_page',
            'column'          => 'unused_col',
            'fillRate'        => 0.5,
            'rows'            => 100,
            'samples'         => [],
            'sqlType'         => 'VARCHAR(255)',
            'targetEntryType' => 'newsPage',
        ];
    }

    public function testHeuristic1FillRateZeroDropsColumn(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'legacy_unused', 'fillRate' => 0.0])],
            [],
        );

        self::assertCount(1, $matched);
        self::assertSame('drop', $matched[0]['decision']);
        self::assertSame('high', $matched[0]['confidence']);
        self::assertSame('legacy_unused', $matched[0]['column']);
        self::assertSame('fill-rate is 0 — no data in source', $matched[0]['rationale']);
        self::assertSame([], $residual);
    }

    public function testHeuristic1aEntityAwareMatchPromotesToHighWhenEntityResolvesProperty(): void
    {
        $proposer = new HeuristicProposer();
        $proposer->entityIndex = [
            'App\\Entity\\NewsPage' => new DoctrineEntityInfo(
                fqcn: 'App\\Entity\\NewsPage',
                tableName: 'kuma_news_page',
                columns: [
                    new DoctrineColumnInfo(
                        columnName: 'subtitle',
                        type: 'string',
                        nullable: true,
                        propertyName: 'subTitle',
                    ),
                ],
                relations: [],
            ),
        ];
        $proposer->acceptedRows = [
            ['table' => 'kuma_news_page', 'targetEntryType' => 'newsArticle'],
        ];

        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'subtitle', 'targetEntryType' => 'newsPage'])],
            [
                'newsArticle' => [
                    ['handle' => 'subTitle', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('map', $matched[0]['decision']);
        self::assertSame('high', $matched[0]['confidence']);
        self::assertSame('subTitle', $matched[0]['targetHandle']);
        // Entity-aware re-targets the violation to the resolved entry type.
        self::assertSame('newsArticle', $matched[0]['targetEntryType']);
        self::assertStringContainsString('heuristic 1.5', $matched[0]['rationale']);
        self::assertSame([], $residual);
    }

    public function testHeuristic3ExactNameMatchHigh(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'slug'])],
            [
                'newsPage' => [
                    ['handle' => 'slug', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('map', $matched[0]['decision']);
        self::assertSame('high', $matched[0]['confidence']);
        self::assertSame('slug', $matched[0]['targetHandle']);
        self::assertSame('plain', $matched[0]['handler']);
        self::assertSame('auto-match: name equality', $matched[0]['rationale']);
        self::assertSame([], $residual);
    }

    public function testHeuristic3ExactNameMatchIsCaseInsensitive(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'TITLE'])],
            [
                'newsPage' => [
                    ['handle' => 'title', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('title', $matched[0]['targetHandle']);
        self::assertSame([], $residual);
    }

    public function testHeuristic4IdSuffixMapsToAssetByStem(): void
    {
        // findAssetByStem does case-insensitive `str_contains($h, $stem)`; the
        // stem comes from the column literally (snake_case kept), so the field
        // handle must also contain that snake_case substring (or equal it).
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'header_image_id'])],
            [
                'newsPage' => [
                    ['handle' => 'header_image', 'type' => 'Assets', 'classification' => 'asset'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('map', $matched[0]['decision']);
        self::assertSame('high', $matched[0]['confidence']);
        self::assertSame('header_image', $matched[0]['targetHandle']);
        self::assertSame('asset', $matched[0]['handler']);
        self::assertStringContainsString('*_id → asset field', $matched[0]['rationale']);
        self::assertSame([], $residual);
    }

    public function testHeuristic4BareIdColumnDoesNotMatchAssetWildcard(): void
    {
        // Empty stem would otherwise match every asset field — guarded by `> 3` length check.
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => '_id'])],
            [
                'newsPage' => [
                    ['handle' => 'headerImage', 'type' => 'Assets', 'classification' => 'asset'],
                ],
            ],
        );

        self::assertSame([], $matched);
        self::assertCount(1, $residual);
    }

    public function testHeuristic5TextSqlTypeMapsToCkeditorRichtext(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'body', 'sqlType' => 'LONGTEXT'])],
            [
                'newsPage' => [
                    ['handle' => 'bodyContent', 'type' => 'CkEditor', 'classification' => 'richtext'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('bodyContent', $matched[0]['targetHandle']);
        self::assertSame('ckeditor', $matched[0]['handler']);
        self::assertStringContainsString('LONGTEXT', $matched[0]['rationale']);
        self::assertStringContainsString('richtext field', $matched[0]['rationale']);
        self::assertSame([], $residual);
    }

    public function testHeuristic6ImageSuffixMapsToAsset(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'hero_image'])],
            [
                'newsPage' => [
                    ['handle' => 'heroAsset', 'type' => 'Assets', 'classification' => 'asset'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('heroAsset', $matched[0]['targetHandle']);
        self::assertSame('asset', $matched[0]['handler']);
        self::assertStringContainsString('hero_image → asset field', $matched[0]['rationale']);
        self::assertSame([], $residual);
    }

    public function testHeuristic6PhotoSuffixMapsToAsset(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'staff_photo'])],
            [
                'newsPage' => [
                    ['handle' => 'photo', 'type' => 'Assets', 'classification' => 'asset'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('photo', $matched[0]['targetHandle']);
        self::assertSame([], $residual);
    }

    public function testHeuristic7DateSuffixMapsToDateField(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'publish_date'])],
            [
                'newsPage' => [
                    ['handle' => 'publishedOn', 'type' => 'Date', 'classification' => 'date'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('publishedOn', $matched[0]['targetHandle']);
        self::assertSame('date', $matched[0]['handler']);
        self::assertSame([], $residual);
    }

    public function testHeuristic7AtSuffixFallsBackToHandleKeywordWhenNoClassification(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'created_at'])],
            [
                'newsPage' => [
                    // No classification=date; relies on handle-keyword 'date' fallback.
                    ['handle' => 'postDate', 'type' => 'Date'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('postDate', $matched[0]['targetHandle']);
        self::assertSame([], $residual);
    }

    public function testHeuristic8UrlSuffixMapsToUrlField(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'external_url'])],
            [
                'newsPage' => [
                    ['handle' => 'sourceUrl', 'type' => 'Url', 'classification' => 'url'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('sourceUrl', $matched[0]['targetHandle']);
        self::assertSame('url', $matched[0]['handler']);
        self::assertSame([], $residual);
    }

    public function testHeuristic8LinkSuffixFallsBackToHandleKeyword(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'related_link'])],
            [
                'newsPage' => [
                    ['handle' => 'relatedLink', 'type' => 'PlainText'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('relatedLink', $matched[0]['targetHandle']);
        self::assertSame([], $residual);
    }

    public function testHeuristic9EmailSuffixMapsToEmailField(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'contact_email'])],
            [
                'newsPage' => [
                    ['handle' => 'contactEmail', 'type' => 'Email', 'classification' => 'email'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('contactEmail', $matched[0]['targetHandle']);
        self::assertSame('email', $matched[0]['handler']);
        self::assertSame([], $residual);
    }

    public function testHeuristic9BareEmailColumnMatches(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'email'])],
            [
                'newsPage' => [
                    ['handle' => 'authorEmail', 'type' => 'Email', 'classification' => 'email'],
                ],
            ],
        );

        // The bare 'email' column gets caught by exact-name match ONLY if a
        // field named 'email' exists; here it does not, so it falls through to
        // heuristic 9 (bare 'email' column → handle containing 'email').
        self::assertCount(1, $matched);
        self::assertSame('authorEmail', $matched[0]['targetHandle']);
        self::assertSame([], $residual);
    }

    public function testResidualHandoffWhenNoHeuristicMatches(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'mystery_column', 'sqlType' => 'INT'])],
            [
                'newsPage' => [
                    ['handle' => 'unrelatedField', 'type' => 'PlainText'],
                ],
            ],
        );

        self::assertSame([], $matched);
        self::assertCount(1, $residual);
        self::assertSame('mystery_column', $residual[0]['column']);
    }

    public function testReturnShapeIsTwoTupleMatchedAndResidual(): void
    {
        $proposer = new HeuristicProposer();
        $result = $proposer->autoMatch(
            [
                $this->violation(['column' => 'slug']),
                $this->violation(['column' => 'mystery']),
            ],
            [
                'newsPage' => [
                    ['handle' => 'slug', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        self::assertCount(2, $result);
        self::assertIsArray($result[0]);
        self::assertIsArray($result[1]);
        self::assertCount(1, $result[0]);
        self::assertCount(1, $result[1]);
        self::assertSame('slug', $result[0][0]['column']);
        self::assertSame('mystery', $result[1][0]['column']);
    }

    public function testProposalCarriesAllExpectedKeys(): void
    {
        $proposer = new HeuristicProposer();
        [$matched, ] = $proposer->autoMatch(
            [$this->violation([
                'column'   => 'slug',
                'fillRate' => 0.85,
                'samples'  => ['a', 'b'],
            ])],
            [
                'newsPage' => [
                    ['handle' => 'slug', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        $expectedKeys = [
            'table', 'column', 'targetEntryType', 'decision',
            'targetHandle', 'handler', 'rationale', 'confidence',
            'fillRate', 'sqlType', 'samples',
        ];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $matched[0], "Missing key: $key");
        }
        self::assertSame(0.85, $matched[0]['fillRate']);
        self::assertSame(['a', 'b'], $matched[0]['samples']);
    }

    public function testHandlerForClassificationCoversAllBranches(): void
    {
        // Drive the match() inside handlerForClassification through every branch via
        // distinct violations + field classifications. Each violation triggers a
        // different heuristic that calls handlerForClassification.
        $proposer = new HeuristicProposer();
        [$matched,] = $proposer->autoMatch(
            [
                // 'asset' classification — hit via heuristic 4 (*_id) or exact name
                $this->violation(['column' => 'avatar', 'targetEntryType' => 'a']),
                // 'richtext' via exact-name match
                $this->violation(['column' => 'body', 'targetEntryType' => 'b']),
                // 'date' via exact-name match
                $this->violation(['column' => 'publishedAt', 'targetEntryType' => 'c']),
                // 'email' via exact-name match
                $this->violation(['column' => 'contact', 'targetEntryType' => 'd']),
                // 'url' via exact-name match
                $this->violation(['column' => 'website', 'targetEntryType' => 'e']),
                // 'relation' via exact-name match
                $this->violation(['column' => 'topics', 'targetEntryType' => 'f']),
                // 'plugin' via exact-name match
                $this->violation(['column' => 'seo', 'targetEntryType' => 'g']),
                // 'richtext-container' via exact-name match (matrix handler)
                $this->violation(['column' => 'sections', 'targetEntryType' => 'h']),
                // unknown classification → 'plain' default
                $this->violation(['column' => 'note', 'targetEntryType' => 'i']),
            ],
            [
                'a' => [['handle' => 'avatar', 'type' => 'Assets', 'classification' => 'asset']],
                'b' => [['handle' => 'body', 'type' => 'CkEditor', 'classification' => 'richtext']],
                'c' => [['handle' => 'publishedAt', 'type' => 'Date', 'classification' => 'date']],
                'd' => [['handle' => 'contact', 'type' => 'Email', 'classification' => 'email']],
                'e' => [['handle' => 'website', 'type' => 'Url', 'classification' => 'url']],
                'f' => [['handle' => 'topics', 'type' => 'Entries', 'classification' => 'relation']],
                'g' => [['handle' => 'seo', 'type' => 'Plugin', 'classification' => 'plugin']],
                'h' => [['handle' => 'sections', 'type' => 'Matrix', 'classification' => 'richtext-container']],
                'i' => [['handle' => 'note', 'type' => 'PlainText', 'classification' => 'unknown-thing']],
            ],
        );

        $byColumn = [];
        foreach ($matched as $m) {
            $byColumn[$m['column']] = $m['handler'];
        }

        self::assertSame('asset', $byColumn['avatar']);
        self::assertSame('ckeditor', $byColumn['body']);
        self::assertSame('date', $byColumn['publishedAt']);
        self::assertSame('email', $byColumn['contact']);
        self::assertSame('url', $byColumn['website']);
        self::assertSame('relation', $byColumn['topics']);
        self::assertSame('seomatic', $byColumn['seo']);
        self::assertSame('matrix', $byColumn['sections']);
        self::assertSame('plain', $byColumn['note']);
    }

    public function testHeuristicOrderFillRateZeroWinsOverNameMatch(): void
    {
        // A column with fillRate=0 AND a perfectly matching field handle should
        // still be dropped (heuristic 1 fires first).
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'slug', 'fillRate' => 0.0])],
            [
                'newsPage' => [
                    ['handle' => 'slug', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('drop', $matched[0]['decision']);
        self::assertSame([], $residual);
    }

    public function testEntityAwareFallsThroughToHeuristic3WhenNoAcceptedRow(): void
    {
        // entityIndex set, but acceptedRows empty → heuristic 1.5 cannot resolve
        // an entry type; should fall through to heuristic 3 (exact name match).
        $proposer = new HeuristicProposer();
        $proposer->entityIndex = [
            'App\\Entity\\NewsPage' => new DoctrineEntityInfo(
                fqcn: 'App\\Entity\\NewsPage',
                tableName: 'kuma_news_page',
                columns: [
                    new DoctrineColumnInfo(
                        columnName: 'slug',
                        type: 'string',
                        nullable: false,
                        propertyName: 'slug',
                    ),
                ],
                relations: [],
            ),
        ];
        $proposer->acceptedRows = [];

        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'slug'])],
            [
                'newsPage' => [
                    ['handle' => 'slug', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        self::assertCount(1, $matched);
        self::assertSame('auto-match: name equality', $matched[0]['rationale']);
        self::assertSame([], $residual);
    }

    public function testHeuristic5DoesNotFireWhenNoRichtextField(): void
    {
        // sqlType=TEXT but no richtext-classified field on the entry type → no map.
        $proposer = new HeuristicProposer();
        [$matched, $residual] = $proposer->autoMatch(
            [$this->violation(['column' => 'description', 'sqlType' => 'TEXT'])],
            [
                'newsPage' => [
                    ['handle' => 'somethingElse', 'type' => 'PlainText', 'classification' => 'plain'],
                ],
            ],
        );

        self::assertSame([], $matched);
        self::assertCount(1, $residual);
    }
}
