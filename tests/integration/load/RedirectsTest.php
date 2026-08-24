<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\integration\load;

use Lameco\Kunstmaanmigrator\console\LoadController;
use Lameco\Kunstmaanmigrator\load\MigrationStateReader;
use Lameco\Kunstmaanmigrator\load\RefResolver;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Task 6 — `load/redirects --payload=<file.ndjson>`. Exercises
 * LoadController::buildRedirectsReport(), the pure Craft-app-free report
 * builder behind actionRedirects(), the same convention FixupTest and
 * PayloadEntrySaverTest use: real collaborators for the pieces that are
 * pure logic (RefResolver, against a fake MigrationStateReader), fakes for
 * the two Craft-touching boundaries actionRedirects() wires for real
 * (resolving an entry id + site handle to a URI, and saving through
 * RedirectMigrationService::importOne()) — this repo's test suite cannot
 * boot a live Craft application (see FixupTest's docblock).
 */
final class RedirectsTest extends TestCase
{
    private function writeNdjson(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma-redirects-') . '.ndjson';
        file_put_contents($path, $contents);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/kuma-redirects-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<string, int> $targets "<source>|<key>" => entryId
     */
    private function refResolverWithTargets(array $targets): RefResolver
    {
        $reader = new class($targets) implements MigrationStateReader {
            /** @param array<string, int> $targets */
            public function __construct(private readonly array $targets)
            {
            }

            public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
            {
                return $this->targets[$source . '|' . $key] ?? null;
            }

            public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
            {
                return null;
            }

            public function get(string $source, string $key, ?int $siteId = null): ?array
            {
                return null;
            }
        };

        return new RefResolver($reader);
    }

    /**
     * @param array<string, string> $map "<entryId>|<siteHandle>" => uri
     */
    private function stubUriResolver(array $map): callable
    {
        return static fn(int $entryId, string $siteHandle): ?string => $map[$entryId . '|' . $siteHandle] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $calls
     */
    private function recordingSaver(array &$calls, string $outcome = 'created'): callable
    {
        return static function(string $from, string $to, int $type, string $stateKey, array $extraMeta) use (&$calls, $outcome): array {
            $calls[] = compact('from', 'to', 'type', 'stateKey', 'extraMeta');

            return ['outcome' => $outcome];
        };
    }

    public function testSourceUidToRefIsResolvedViaRefResolverAndPassedToTheSaver(): void
    {
        $path = $this->writeNdjson(json_encode([
            'from' => '/old-page',
            'to' => 'kuma:COM:nt_page:143',
            'siteHandle' => 'en',
            'type' => 301,
        ]) . "\n");

        $calls = [];
        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets(['COM:nt_page|143' => 881]),
            true,
            $this->stubUriResolver(['881|en' => '/new-page']),
            $this->recordingSaver($calls),
        );

        self::assertSame(1, $report['processed']);
        self::assertSame(1, $report['created']);
        self::assertSame(1, $report['resolved']);
        self::assertSame([], $report['report']);
        self::assertCount(1, $calls);
        self::assertSame('/old-page', $calls[0]['from']);
        self::assertSame('/new-page', $calls[0]['to']);
        self::assertSame(301, $calls[0]['type']);
        self::assertSame(ExitCode::OK, LoadController::exitCodeForRedirects($report));
    }

    public function testPlainPathToIsUsedAsIsWithoutRefResolution(): void
    {
        $path = $this->writeNdjson(json_encode([
            'from' => '/old-page',
            'to' => '/already-new',
            'siteHandle' => 'en',
            'type' => 302,
        ]) . "\n");

        $calls = [];
        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets([]),
            true,
            $this->stubUriResolver([]),
            $this->recordingSaver($calls),
        );

        self::assertSame(0, $report['resolved'], 'a plain path needs no ref resolution');
        self::assertSame(1, $report['created']);
        self::assertSame([], $report['report']);
        self::assertSame('/already-new', $calls[0]['to']);
        self::assertSame(302, $calls[0]['type']);
        self::assertSame(ExitCode::OK, LoadController::exitCodeForRedirects($report));
    }

    public function testRetourAbsentSkipsEveryRowWithoutFailingTheRun(): void
    {
        $path = $this->writeNdjson(implode("\n", [
            json_encode(['from' => '/a', 'to' => '/b', 'siteHandle' => 'en', 'type' => 301]),
            json_encode(['from' => '/c', 'to' => 'kuma:COM:nt_page:1', 'siteHandle' => 'en', 'type' => 301]),
        ]) . "\n");

        $calls = [];
        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets(['COM:nt_page|1' => 5]),
            false,
            $this->stubUriResolver(['5|en' => '/d']),
            $this->recordingSaver($calls),
        );

        self::assertSame(2, $report['processed']);
        self::assertSame(2, $report['skipped']);
        self::assertSame(0, $report['created']);
        self::assertSame([], $calls, 'the saver must never be invoked when Retour is unavailable');
        self::assertCount(2, $report['report']);
        self::assertSame('SKIPPED_NO_RETOUR', $report['report'][0]['status']);
        self::assertSame('SKIPPED_NO_RETOUR', $report['report'][1]['status']);

        // "do not fail" per the brief — SKIPPED_NO_RETOUR must not flip the exit code.
        self::assertSame(ExitCode::OK, LoadController::exitCodeForRedirects($report));
    }

    public function testUnresolvedSourceUidIsReportedAndCausesNonZeroExit(): void
    {
        $path = $this->writeNdjson(json_encode([
            'from' => '/old-page',
            'to' => 'kuma:COM:nt_page:999',
            'siteHandle' => 'en',
            'type' => 301,
        ]) . "\n");

        $calls = [];
        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets([]), // 999 not migrated yet
            true,
            $this->stubUriResolver([]),
            $this->recordingSaver($calls),
        );

        self::assertSame(0, $report['created']);
        self::assertSame([], $calls, 'the saver must not run for an unresolved ref');
        self::assertCount(1, $report['report']);
        self::assertSame('UNRESOLVED_REF', $report['report'][0]['status']);
        self::assertSame('kuma:COM:nt_page:999', $report['report'][0]['to']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeForRedirects($report));
    }

    public function testResolvedEntryWithoutAUriForTheRequestedSiteIsUnresolvedRef(): void
    {
        $path = $this->writeNdjson(json_encode([
            'from' => '/old-page',
            'to' => 'kuma:COM:nt_page:143',
            'siteHandle' => 'nl',
            'type' => 301,
        ]) . "\n");

        $calls = [];
        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets(['COM:nt_page|143' => 881]),
            true,
            $this->stubUriResolver([]), // no URI for 881|nl
            $this->recordingSaver($calls),
        );

        self::assertSame([], $calls);
        self::assertSame('UNRESOLVED_REF', $report['report'][0]['status']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeForRedirects($report));
    }

    public function testMalformedRowIsReportedAndCausesNonZeroExit(): void
    {
        // Missing `to`.
        $path = $this->writeNdjson(json_encode(['from' => '/a', 'siteHandle' => 'en', 'type' => 301]) . "\n");

        $calls = [];
        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets([]),
            true,
            $this->stubUriResolver([]),
            $this->recordingSaver($calls),
        );

        self::assertSame(1, $report['processed']);
        self::assertSame([], $calls);
        self::assertSame('MALFORMED', $report['report'][0]['status']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeForRedirects($report));
    }

    public function testSaverFailureIsReportedAndCausesNonZeroExit(): void
    {
        $path = $this->writeNdjson(json_encode(['from' => '/a', 'to' => '/b', 'siteHandle' => 'en', 'type' => 301]) . "\n");

        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets([]),
            true,
            $this->stubUriResolver([]),
            static fn(string $from, string $to, int $type, string $stateKey, array $extraMeta): array
                => ['outcome' => 'failed', 'message' => 'Retour saveRedirect refused'],
        );

        self::assertSame(0, $report['created']);
        self::assertSame('SAVE_FAILED', $report['report'][0]['status']);
        self::assertSame('Retour saveRedirect refused', $report['report'][0]['message']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeForRedirects($report));
    }

    public function testUpdatedOutcomeIsCountedSeparatelyFromCreated(): void
    {
        $path = $this->writeNdjson(json_encode(['from' => '/a', 'to' => '/b', 'siteHandle' => 'en', 'type' => 301]) . "\n");

        $calls = [];
        $report = LoadController::buildRedirectsReport(
            $path,
            $this->refResolverWithTargets([]),
            true,
            $this->stubUriResolver([]),
            $this->recordingSaver($calls, 'updated'),
        );

        self::assertSame(0, $report['created']);
        self::assertSame(1, $report['updated']);
        self::assertSame([], $report['report']);
        self::assertSame(ExitCode::OK, LoadController::exitCodeForRedirects($report));
    }

    public function testCompiledRecordsTakeTheSameResolutionPathAsAPayloadFile(): void
    {
        // `migrate` compiles redirects from the mapping instead of reading a file. The file
        // was never a contract worth keeping between the two halves, but the ref resolution
        // and the reporting around it are — so both paths have to meet in one place.
        $records = [
            ['from' => '/news-knowledge', 'to' => 'kuma:COM:kuma_nodes:15', 'siteHandle' => 'en', 'type' => 301],
            ['from' => '/old-partner', 'to' => 'https://example.test/partner', 'siteHandle' => 'en', 'type' => 302],
        ];

        $saved = [];

        $report = LoadController::reportForRedirects(
            $records,
            $this->refResolverWithTargets(['COM:kuma_nodes|15' => 4321]),
            true,
            static fn(int $entryId, string $site): ?string => $entryId === 4321 ? '/company/news' : null,
            static function(string $from, string $to, int $code, string $key, array $meta) use (&$saved): array {
                $saved[] = [$from, $to, $code];

                return ['outcome' => 'created'];
            },
        );

        self::assertSame(2, $report['processed']);
        self::assertSame(2, $report['created']);
        self::assertSame(1, $report['resolved'], 'Only the sourceUid destination needs resolving.');
        self::assertSame([
            ['/news-knowledge', '/company/news', 301],
            ['/old-partner', 'https://example.test/partner', 302],
        ], $saved, 'A sourceUid becomes the entry\'s current URI; a literal URL is passed through.');
    }

    public function testCompiledRecordWithAnUnmigratedTargetIsReportedRatherThanSavedWrong(): void
    {
        $report = LoadController::reportForRedirects(
            [['from' => '/gone', 'to' => 'kuma:COM:kuma_nodes:99', 'siteHandle' => 'en', 'type' => 301]],
            $this->refResolverWithTargets([]),
            true,
            static fn(int $entryId, string $site): ?string => null,
            static function(): array {
                self::fail('An unresolved destination must never reach Retour.');
            },
        );

        self::assertSame(0, $report['created']);
        self::assertSame('UNRESOLVED_REF', $report['report'][0]['status']);
    }
}
