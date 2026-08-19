<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use InvalidArgumentException;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\FixupService;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\RefResolver;
use lameco\kunstmaanmigrator\payload\Violation;
use Throwable;
use yii\console\ExitCode;

/**
 * Payload-driven loader (docs/loader-contract.md). `--dry-run` only ever
 * validates (`buildReport()`); the live branch validates the whole batch
 * first too and refuses to save anything when any violation exists — there
 * is no partial-force override — then saves each valid payload via
 * `PayloadEntrySaver::save()` (`buildLiveReport()`), fail-forward per
 * payload into `failed[]`.
 *
 * `buildReport()`/`buildLiveReport()`/`exitCodeFor()`/`readRecords()` are
 * static and take their `SchemaGateway`-backed `PayloadValidator` (and, for
 * the live path, `PayloadEntrySaver`) as parameters so they can be exercised
 * in tests against fakes without booting a Craft application —
 * `actionEntry()` is the only place that wires the real `CraftSchemaGateway`
 * / `PayloadEntrySaver`.
 */
class LoadController extends Controller
{
    use NeverProductionTrait;

    public ?string $payload = null;
    public bool $dryRun = false;

    /**
     * Refresh entries that already exist.
     *
     * Off by default so an interrupted load can be resumed cheaply. Turn it on after the
     * payload has changed — without it an existing entry is left exactly as it was, and the
     * run still reports it as handled.
     */
    public bool $force = false;

    /** @see beforeAction() */
    private ?int $neverProductionExitCode = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['payload', 'dryRun', 'force']);
    }

    /**
     * Craft's own `ControllerTrait::runAction()` treats a `null` action
     * result as `ExitCode::OK` — so a `beforeAction()` that merely returns
     * `false` would make a production refusal look like success. Stashing
     * the gate's exit code here and re-asserting it in `runAction()` below
     * is what makes the refusal actually observable to the caller.
     */
    public function beforeAction($action): bool
    {
        $this->neverProductionExitCode = $this->enforceNeverProduction();
        if ($this->neverProductionExitCode !== null) {
            return false;
        }

        return parent::beforeAction($action);
    }

    public function runAction($id, $params = []): int
    {
        $result = parent::runAction($id, $params);

        return $this->neverProductionExitCode ?? $result;
    }

    public function actionEntry(): int
    {
        if ($this->payload === null || $this->payload === '') {
            $this->stderr("Missing required --payload=<file.json|file.ndjson>\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!is_file($this->payload)) {
            $this->stderr(sprintf("Payload file not found: %s\n", $this->payload), Console::FG_RED);

            return ExitCode::USAGE;
        }

        $gateway = new CraftSchemaGateway();
        $validator = new PayloadValidator($gateway);

        if ($this->dryRun) {
            $report = self::buildReport($this->payload, $validator);
            $this->stdout(json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);

            return self::exitCodeFor($report);
        }

        $plugin = Plugin::getInstance();
        $saver = new PayloadEntrySaver(
            $gateway,
            $plugin->entryMigrationService,
            $plugin->migrationStateService,
            $plugin->assetMigrationService,
            $plugin->ckeditorRewriterService,
            null,
            $this->force,
        );
        $report = self::buildLiveReport($this->payload, $validator, $saver);
        $this->stdout(json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return self::exitCodeFor($report);
    }

    /**
     * Second pass (docs/loader-contract.md "Two-pass `_ref` resolution
     * semantics") — drains every state row's `pendingRefs` left behind by
     * `load/entry`, re-resolving and patching whatever's now resolvable.
     * Run once every payload in a batch has been through `load/entry`.
     */
    public function actionFixup(): int
    {
        $plugin = Plugin::getInstance();
        $service = new FixupService($plugin->migrationStateService, $plugin->entryMigrationService);
        $report = $service->run();
        $this->stdout(json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return self::exitCodeForFixup($report);
    }

    /**
     * Task 6 — payload-driven redirect loader. Each NDJSON line is
     * `{"from":"/old","to":"kuma:<ENV>:<table>:<id>"|"/new","siteHandle":"en","type":301}`.
     * A `to` matching the sourceUid grammar is resolved via `RefResolver` to
     * the target entry's URI on `siteHandle`; a plain path is used verbatim.
     * Retour is used when installed; when it isn't, every row is reported
     * `SKIPPED_NO_RETOUR` rather than failing the run (docs/loader-contract.md
     * optional-adapter convention — see `RedirectMigrationService::isRetourAvailable()`,
     * reused here rather than re-derived).
     */
    public function actionRedirects(): int
    {
        if ($this->payload === null || $this->payload === '') {
            $this->stderr("Missing required --payload=<file.ndjson>\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!is_file($this->payload)) {
            $this->stderr(sprintf("Payload file not found: %s\n", $this->payload), Console::FG_RED);

            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();
        $refResolver = new RefResolver($plugin->migrationStateService);
        $retourAvailable = RedirectMigrationService::isRetourAvailable();

        $report = self::buildRedirectsReport(
            $this->payload,
            $refResolver,
            $retourAvailable,
            static function (int $entryId, string $siteHandle): ?string {
                $site = Craft::$app->sites->getSiteByHandle($siteHandle);
                if ($site === null) {
                    return null;
                }

                $entry = Entry::find()->id($entryId)->siteId((int) $site->id)->status(null)->one();
                if ($entry === null || $entry->uri === null) {
                    return null;
                }

                return '/' . ltrim($entry->uri, '/');
            },
            static function (string $srcUrl, string $destUrl, int $httpCode, string $stateKey, array $extraMeta) use ($plugin): array {
                $result = $plugin->redirectMigrationService->importOne($srcUrl, $destUrl, $httpCode, $stateKey, $extraMeta);
                if (($result->counts['created'] ?? 0) > 0) {
                    return ['outcome' => 'created'];
                }
                if (($result->counts['updated'] ?? 0) > 0) {
                    return ['outcome' => 'updated'];
                }

                return ['outcome' => 'failed', 'message' => $result->warnings[0] ?? 'Retour refused to save the redirect.'];
            },
        );

        $this->stdout(json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return self::exitCodeForRedirects($report);
    }

    /**
     * @param callable(int, string): ?string $resolveEntryUri Resolves an
     *   already-resolved sourceUid's entry id + siteHandle to a destination
     *   URI, or null when the entry has no URI on that site.
     * @param callable(string, string, int, string, array<string, mixed>): array{outcome: string, message?: string} $saveRedirect
     *   Persists one already-resolved (srcUrl, destUrl) pair — only invoked
     *   when `$retourAvailable` is true.
     * @return array{processed: int, created: int, updated: int, resolved: int, skipped: int, report: list<array{from: ?string, to: ?string, siteHandle: ?string, status: string, message?: string}>}
     */
    public static function buildRedirectsReport(
        string $path,
        RefResolver $refResolver,
        bool $retourAvailable,
        callable $resolveEntryUri,
        callable $saveRedirect,
    ): array {
        $records = self::readRecords($path);

        $created = 0;
        $updated = 0;
        $resolved = 0;
        $skipped = 0;
        $report = [];

        foreach ($records as $raw) {
            $row = self::parseRedirectRow($raw);
            if ($row === null) {
                $report[] = [
                    'from' => self::stringOrNull($raw, 'from'),
                    'to' => self::stringOrNull($raw, 'to'),
                    'siteHandle' => self::stringOrNull($raw, 'siteHandle'),
                    'status' => 'MALFORMED',
                    'message' => 'Redirect record must be a JSON object with string from/to/siteHandle and an int type.',
                ];
                continue;
            }

            ['from' => $from, 'to' => $to, 'siteHandle' => $siteHandle, 'type' => $type] = $row;

            if (!$retourAvailable) {
                $skipped++;
                $report[] = ['from' => $from, 'to' => $to, 'siteHandle' => $siteHandle, 'status' => 'SKIPPED_NO_RETOUR'];
                continue;
            }

            $destUrl = $to;
            if (RefResolver::parse($to) !== null) {
                $entryId = $refResolver->resolve($to);
                if ($entryId === null) {
                    $report[] = [
                        'from' => $from,
                        'to' => $to,
                        'siteHandle' => $siteHandle,
                        'status' => 'UNRESOLVED_REF',
                        'message' => 'sourceUid has not been migrated yet.',
                    ];
                    continue;
                }

                $uri = $resolveEntryUri($entryId, $siteHandle);
                if ($uri === null) {
                    $report[] = [
                        'from' => $from,
                        'to' => $to,
                        'siteHandle' => $siteHandle,
                        'status' => 'UNRESOLVED_REF',
                        'message' => sprintf('Entry %d has no URI on site "%s".', $entryId, $siteHandle),
                    ];
                    continue;
                }

                $destUrl = $uri;
                $resolved++;
            }

            $stateKey = sprintf('payload:%s:%s', $siteHandle, $from);
            $outcome = $saveRedirect($from, $destUrl, $type, $stateKey, ['siteHandle' => $siteHandle, 'to' => $to]);

            if (($outcome['outcome'] ?? null) === 'created') {
                $created++;
            } elseif (($outcome['outcome'] ?? null) === 'updated') {
                $updated++;
            } else {
                $report[] = [
                    'from' => $from,
                    'to' => $to,
                    'siteHandle' => $siteHandle,
                    'status' => 'SAVE_FAILED',
                    'message' => (string) ($outcome['message'] ?? 'Retour refused to save the redirect.'),
                ];
            }
        }

        return [
            'processed' => count($records),
            'created' => $created,
            'updated' => $updated,
            'resolved' => $resolved,
            'skipped' => $skipped,
            'report' => $report,
        ];
    }

    /**
     * @return array{from: string, to: string, siteHandle: string, type: int}|null
     */
    private static function parseRedirectRow(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $from = $raw['from'] ?? null;
        $to = $raw['to'] ?? null;
        $siteHandle = $raw['siteHandle'] ?? null;
        $type = $raw['type'] ?? null;

        if (!is_string($from) || $from === '' || !is_string($to) || $to === '' || !is_string($siteHandle) || $siteHandle === '') {
            return null;
        }
        if (!is_int($type) && !(is_string($type) && ctype_digit($type))) {
            return null;
        }

        return ['from' => $from, 'to' => $to, 'siteHandle' => $siteHandle, 'type' => (int) $type];
    }

    private static function stringOrNull(mixed $raw, string $key): ?string
    {
        return (is_array($raw) && is_string($raw[$key] ?? null)) ? $raw[$key] : null;
    }

    /**
     * @param array{report: list<array{status: string}>} $report
     */
    public static function exitCodeForRedirects(array $report): int
    {
        foreach ($report['report'] as $entry) {
            if (($entry['status'] ?? null) !== 'SKIPPED_NO_RETOUR') {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return ExitCode::OK;
    }

    /**
     * @return array{processed: int, violations: list<array{sourceUid: string, code: string, message: string}>, saved: int, failed: list<mixed>}
     */
    public static function buildReport(string $path, PayloadValidator $validator): array
    {
        $records = self::readRecords($path);
        $violations = [];

        foreach ($records as $raw) {
            try {
                if (!is_array($raw)) {
                    throw new InvalidArgumentException('Record is not a JSON object.');
                }
                $p = Payload::fromArray($raw);
            } catch (InvalidArgumentException $e) {
                $sourceUid = (is_array($raw) && is_string($raw['sourceUid'] ?? null)) ? $raw['sourceUid'] : 'unknown';
                $violations[] = (new Violation($sourceUid, 'UNPARSEABLE', $e->getMessage()))->toArray();
                continue;
            }

            foreach ($validator->validate($p) as $violation) {
                $violations[] = $violation->toArray();
            }
        }

        return [
            'processed' => count($records),
            'violations' => $violations,
            'saved' => 0,
            'failed' => [],
        ];
    }

    /**
     * Live-path counterpart to `buildReport()`. Parses and validates every
     * record exactly like the dry-run path, but refuses to save anything —
     * `saved` stays 0, `violations` populated, exit 1 via `exitCodeFor()` —
     * when ANY record is unparseable or fails validation (no partial-force
     * override). Only once the whole batch is clean does it loop the parsed
     * payloads through `PayloadEntrySaver::save()`, fail-forward: a thrown
     * exception for one payload is recorded in `failed[]` and the loop
     * continues rather than aborting the run.
     *
     * @return array{processed: int, violations: list<array{sourceUid: string, code: string, message: string}>, saved: int, failed: list<array{sourceUid: string, error: string}>, unresolvedAssets: list<array<string, mixed>>, mediaTokenIssues: list<array<string, mixed>>}
     */
    public static function buildLiveReport(string $path, PayloadValidator $validator, PayloadEntrySaver $saver): array
    {
        $records = self::readRecords($path);
        $violations = [];
        $payloads = [];

        foreach ($records as $raw) {
            try {
                if (!is_array($raw)) {
                    throw new InvalidArgumentException('Record is not a JSON object.');
                }
                $p = Payload::fromArray($raw);
            } catch (InvalidArgumentException $e) {
                $sourceUid = (is_array($raw) && is_string($raw['sourceUid'] ?? null)) ? $raw['sourceUid'] : 'unknown';
                $violations[] = (new Violation($sourceUid, 'UNPARSEABLE', $e->getMessage()))->toArray();
                continue;
            }

            $payloadViolations = $validator->validate($p);
            if ($payloadViolations !== []) {
                foreach ($payloadViolations as $violation) {
                    $violations[] = $violation->toArray();
                }
                continue;
            }

            $payloads[] = $p;
        }

        $saved = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = [];
        $unresolvedAssets = [];
        $mediaTokenIssues = [];

        if ($violations === []) {
            foreach ($payloads as $p) {
                try {
                    $result = $saver->save($p);
                    $saved++;

                    // "saved" alone hid the case this whole flag exists for: an existing
                    // entry short-circuiting, reported as a success while nothing was
                    // written. Split the count so a no-op is visible.
                    if ($result->created) {
                        $created++;
                    } elseif ($saver->refreshesExisting()) {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    foreach ($result->unresolvedAssets as $entry) {
                        $unresolvedAssets[] = ['sourceUid' => $p->sourceUid] + $entry;
                    }
                    foreach ($result->mediaTokenIssues as $entry) {
                        $mediaTokenIssues[] = ['sourceUid' => $p->sourceUid] + $entry;
                    }
                } catch (Throwable $e) {
                    $failed[] = ['sourceUid' => $p->sourceUid, 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'processed' => count($records),
            'violations' => $violations,
            'saved' => $saved,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'unresolvedAssets' => $unresolvedAssets,
            'mediaTokenIssues' => $mediaTokenIssues,
        ];
    }

    /**
     * @param array{violations: list<mixed>, failed: list<mixed>} $report
     */
    public static function exitCodeFor(array $report): int
    {
        return ($report['violations'] === [] && $report['failed'] === []) ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @param array{orphans: list<mixed>} $report
     */
    public static function exitCodeForFixup(array $report): int
    {
        return $report['orphans'] === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Reads `.ndjson` (one JSON object per line) or `.json` (a single object,
     * or a top-level array of objects) into a flat list of decoded records.
     * Anything that fails to decode, or doesn't decode to an array, is kept
     * as `null` — `buildReport()` turns that into an `UNPARSEABLE` violation
     * rather than aborting the whole file.
     *
     * @return list<mixed>
     */
    private static function readRecords(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Payload file not found: %s', $path));
        }

        if (str_ends_with(strtolower($path), '.ndjson')) {
            $records = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                $records[] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            return $records;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null];
        }
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        return [$decoded];
    }
}
