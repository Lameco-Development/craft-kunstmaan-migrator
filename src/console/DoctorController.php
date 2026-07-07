<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use Throwable;
use yii\console\ExitCode;

/**
 * Doctor — preflight diagnostics for the v2 loader-only core (Task 6
 * rewrite). Emits a single JSON array of `{check, ok, detail}` rows on
 * stdout and exits non-zero if any row's `ok` is false — every other
 * console command in this plugin already emits machine-readable
 * JSON/NDJSON; doctor now matches instead of writing ANSI-colored prose.
 *
 * v2 loader prune: the legacy-DB reachability check, the SEOmatic
 * informational check, and the ext_translations presence check are all
 * removed — analyze/mapping/taxonomy-stage machinery that has no place in
 * a loader-only world (the Anthropic-key check and the mapping-file check
 * never existed in this class to begin with; both were pruned before
 * Task 6). Four checks remain, each independent — no early-exit, every
 * check always runs so the operator gets the full report in one pass:
 *
 *   1. checkStateTable      — the plugin is installed (Craft's plugin
 *                             service has it registered) AND the
 *                             kunstmaanmigrator_state table exists and is
 *                             reachable.
 *   2. checkStorageWritable — storage/migration/ exists (auto-creating it
 *                             if missing) and is writable.
 *   3. checkNotProduction   — CRAFT_ENVIRONMENT must not be 'production'.
 *                             Reuses NeverProductionTrait::enforceNeverProduction()
 *                             as the single source of truth for that
 *                             predicate — still "the production guard",
 *                             just surfaced as a check row instead of an
 *                             early hard-exit (every write command still
 *                             hard-refuses via the trait itself).
 *   4. checkRetourPresence  — informational only (always ok=true): reports
 *                             whether the Retour plugin is installed —
 *                             `load/redirects` reports SKIPPED_NO_RETOUR
 *                             per row when it isn't.
 */
class DoctorController extends Controller
{
    use NeverProductionTrait;

    public function actionIndex(): int
    {
        $checks = [
            $this->checkStateTable(),
            $this->checkStorageWritable(),
            $this->checkNotProduction(),
            $this->checkRetourPresence(),
        ];

        $this->stdout(json_encode($checks, JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return self::exitCodeFor($checks);
    }

    /**
     * @param list<array{check: string, ok: bool, detail: string}> $checks
     */
    public static function exitCodeFor(array $checks): int
    {
        foreach ($checks as $check) {
            if (($check['ok'] ?? true) === false) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return ExitCode::OK;
    }

    /**
     * Check #1: the plugin is registered with Craft AND the migrator state
     * table exists and is reachable.
     */
    private function checkStateTable(): array
    {
        $tableName = '{{%kunstmaanmigrator_state}}';

        try {
            if (Craft::$app->plugins->getPlugin('kunstmaan-migrator') === null) {
                return $this->result('state_table', false, 'kunstmaan-migrator plugin is not installed.');
            }

            if (!Craft::$app->db->getTableSchema($tableName)) {
                return $this->result(
                    'state_table',
                    false,
                    "State table '{$tableName}' is missing — run `./craft plugin/install kunstmaan-migrator` "
                    . '(Craft\'s native plugin install runs the migration that creates it).',
                );
            }

            Craft::$app->db->createCommand("SELECT COUNT(*) FROM {$tableName}")->queryScalar();

            return $this->result('state_table', true, "kunstmaanmigrator_state table reachable ({$tableName}).");
        } catch (Throwable $e) {
            return $this->result('state_table', false, "state table check failed: {$e->getMessage()}");
        }
    }

    /**
     * Check #2: storage/migration/ exists and is writable, auto-creating it
     * under Craft's storage tree if missing.
     */
    private function checkStorageWritable(): array
    {
        $dir = Craft::$app->path->getStoragePath() . '/migration';

        try {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return $this->result('storage_writable', false, "could not create {$dir}");
                }
            }
            if (!is_writable($dir)) {
                return $this->result('storage_writable', false, "{$dir} is not writable");
            }

            return $this->result('storage_writable', true, "{$dir} is writable");
        } catch (Throwable $e) {
            return $this->result('storage_writable', false, "storage check failed: {$e->getMessage()}");
        }
    }

    /**
     * Check #3: NOT production. Delegates the predicate to
     * NeverProductionTrait::enforceNeverProduction() (which also writes the
     * refusal line to stderr on a hit) rather than re-reading
     * CRAFT_ENVIRONMENT independently — one canonical guard, surfaced here
     * as a check row instead of an early hard-exit.
     */
    private function checkNotProduction(): array
    {
        $blocked = $this->enforceNeverProduction() !== null;

        return $this->result(
            'not_production',
            !$blocked,
            $blocked
                ? 'CRAFT_ENVIRONMENT=production — migrator commands refuse to run here.'
                : 'CRAFT_ENVIRONMENT is not production.',
        );
    }

    /**
     * Check #4: Retour presence — informational only, always ok=true.
     */
    private function checkRetourPresence(): array
    {
        $retour = Craft::$app->plugins->getPlugin('retour');
        if ($retour !== null) {
            return $this->result('retour_presence', true, 'retour v' . (string) $retour->getVersion() . ' installed.');
        }

        return $this->result(
            'retour_presence',
            true,
            'retour not installed — load/redirects will report SKIPPED_NO_RETOUR for every row.',
        );
    }

    /**
     * @return array{check: string, ok: bool, detail: string}
     */
    private function result(string $check, bool $ok, string $detail): array
    {
        return ['check' => $check, 'ok' => $ok, 'detail' => $detail];
    }
}
