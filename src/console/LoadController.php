<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use craft\console\Controller;
use craft\helpers\Console;
use InvalidArgumentException;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadEntrySaver;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
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

    /** @see beforeAction() */
    private ?int $neverProductionExitCode = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['payload', 'dryRun']);
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
        $saver = new PayloadEntrySaver($gateway, $plugin->entryMigrationService, $plugin->migrationStateService);
        $report = self::buildLiveReport($this->payload, $validator, $saver);
        $this->stdout(json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return self::exitCodeFor($report);
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
     * @return array{processed: int, violations: list<array{sourceUid: string, code: string, message: string}>, saved: int, failed: list<array{sourceUid: string, error: string}>}
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
        $failed = [];

        if ($violations === []) {
            foreach ($payloads as $p) {
                try {
                    $saver->save($p);
                    $saved++;
                } catch (Throwable $e) {
                    $failed[] = ['sourceUid' => $p->sourceUid, 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'processed' => count($records),
            'violations' => $violations,
            'saved' => $saved,
            'failed' => $failed,
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
