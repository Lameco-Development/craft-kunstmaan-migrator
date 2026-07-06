<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use craft\console\Controller;
use craft\helpers\Console;
use InvalidArgumentException;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\payload\CraftSchemaGateway;
use lameco\kunstmaanmigrator\payload\Payload;
use lameco\kunstmaanmigrator\payload\PayloadValidator;
use lameco\kunstmaanmigrator\payload\Violation;
use yii\base\NotSupportedException;
use yii\console\ExitCode;

/**
 * Payload-driven loader (docs/loader-contract.md). Task 3 ships `load/entry`
 * dry-run validation only — the live-save branch throws until Task 4 wires
 * `PayloadEntrySaver`.
 *
 * `buildReport()`/`exitCodeFor()`/`readRecords()` are static and take their
 * `SchemaGateway`-backed `PayloadValidator` as a parameter so they can be
 * exercised in tests against a fake gateway without booting a Craft
 * application — `actionEntry()` is the only place that wires the real
 * `CraftSchemaGateway`.
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

        if (!$this->dryRun) {
            throw new NotSupportedException('live load lands in Task 4');
        }

        $report = self::buildReport($this->payload, new PayloadValidator(new CraftSchemaGateway()));
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
