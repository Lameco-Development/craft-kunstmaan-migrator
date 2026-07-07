<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use lameco\kunstmaanmigrator\load\MigrationStateReader;
use lameco\kunstmaanmigrator\payload\RefResolver;
use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use Generator;
use JsonException;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * CRUD for the migrator state table — the idempotency map.
 *
 * Unique key on (source, sourceKey, siteId) guarantees one row per legacy
 * record per target site — installed by `src/migrations/Install.php`
 * (Phase 1 / FND-02).
 *
 * Per CONTEXT D-48: v2 commits to the state-table-only resume model — the
 * state table is canonical for entries, assets, redirects and SEO bundles
 * alike. The v1 hedge that an alternative `kunstmaanSourceId` custom-field
 * path replaced state lookups for entries no longer applies.
 */
class MigrationStateService extends Component implements MigrationStateReader
{
    /**
     * Schema-sync invariant: $statePrefix MUST stay aligned with src/migrations/Install.php's
     * STATE_TABLE constant ('{{%kunstmaanmigrator_state}}'). Any rename breaks both DDL and CRUD.
     * Phase 1 / FND-02 shipped the DDL; Phase 3 / Plan 03-03 ships the CRUD.
     */
    public string $statePrefix = 'kunstmaanmigrator_state';

    private ?string $tableName = null;

    /**
     * Returns the Yii-placeholder-wrapped table name for every query.
     * Cached per instance so repeated reads don't rebuild the placeholder.
     */
    private function table(): string
    {
        return $this->tableName ??= '{{%' . $this->statePrefix . '}}';
    }

    private function db(): Connection
    {
        return Craft::$app->db;
    }

    public function has(string $source, string $key, ?int $siteId = null): bool
    {
        return (new Query())
            ->from($this->table())
            ->where($this->keyCondition($source, $key, $siteId))
            ->exists($this->db());
    }

    /** @return array<string, mixed>|null */
    public function get(string $source, string $key, ?int $siteId = null): ?array
    {
        $row = (new Query())
            ->from($this->table())
            ->where($this->keyCondition($source, $key, $siteId))
            ->one($this->db());
        return $row ?: null;
    }

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        $row = $this->get($source, $key, $siteId);
        if (!$row || $row['targetId'] === null) {
            return null;
        }
        return (int) $row['targetId'];
    }

    /**
     * Find a target id by sourceKey alone, scanning across the supplied
     * `source` prefix(es). Used for parent-id resolution: a Kunstmaan
     * `kuma_node_id` is unique across every `App_Entity_Pages_*` source, so
     * the caller can hand over a single sourceKey and let the lookup pick
     * whichever Page entity rendered the parent. Returns null when the
     * parent has not yet been migrated (caller can defer to a fix-up pass).
     *
     * @param  list<string> $sourcePrefixes Match any state row whose
     *                                       `source` column starts with one
     *                                       of these prefixes.
     */
    public function getTargetIdByKey(string $key, array $sourcePrefixes): ?int
    {
        $query = (new Query())
            ->select(['targetId'])
            ->from($this->table())
            ->where(['sourceKey' => $key])
            ->andWhere(['not', ['targetId' => null]]);

        if ($sourcePrefixes !== []) {
            $orConditions = ['or'];
            foreach ($sourcePrefixes as $prefix) {
                $orConditions[] = ['like', 'source', $prefix . '%', false];
            }
            $query->andWhere($orConditions);
        }

        $row = $query->limit(1)->one($this->db());
        if (!$row || $row['targetId'] === null) {
            return null;
        }
        return (int) $row['targetId'];
    }

    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
    {
        $row = $this->get($source, $key, $siteId);
        return $row['targetUid'] ?? null;
    }

    /**
     * Insert (or update-in-place) the mapping row.
     *
     * Guards against last-write-wins repointing: when a row already exists
     * at `(source, key, siteId)` recorded against a DIFFERENT `targetId`,
     * the overwrite is skipped and a warning logged instead — this is the
     * path `recordAlias()` hits when an alias `sourceUid` collides with a
     * row already recorded under another primary's target. A re-record
     * against the SAME `targetId` (the normal re-save/idempotent case)
     * proceeds as before, including refreshing `meta`.
     *
     * @param array<string, mixed>|null $meta arbitrary JSON-serialised payload
     */
    public function record(
        string $source,
        string $key,
        string $targetType,
        int $targetId,
        ?string $targetUid = null,
        ?int $siteId = null,
        ?array $meta = null,
    ): void {
        $existing = $this->get($source, $key, $siteId);

        if ($existing !== null && $this->collidesWithDifferentTarget($existing, $targetId)) {
            $this->warn(sprintf(
                'MigrationStateService::record(): refusing to repoint %s:%s (siteId=%s) from targetId=%d to targetId=%d'
                . ' — a different target is already recorded for this key. Skipping overwrite.',
                $source,
                $key,
                $siteId !== null ? (string) $siteId : 'null',
                (int) $existing['targetId'],
                $targetId,
            ));

            return;
        }

        // Schema note: targetUid is declared via Craft's $this->uid() migration
        // helper (src/migrations/Install.php) which renders as
        // `char(36) NOT NULL DEFAULT '0'` on MySQL. Passing null for non-element
        // records (remote-video media rows, etc.) violates the NOT NULL
        // constraint. Coerce null → '' so the DEFAULT applies and the insert
        // succeeds. Callers that do carry a real Craft uid continue to pass it
        // through verbatim.
        $targetUidSafe = $targetUid ?? '';

        $this->persistRecord($existing, $source, $key, $targetType, $targetId, $targetUidSafe, $siteId, $meta);
    }

    /**
     * Pure predicate for the record() collision guard — true when an
     * existing row's targetId is set and differs from the one about to be
     * written.
     *
     * @param array<string, mixed> $existing
     */
    private function collidesWithDifferentTarget(array $existing, int $targetId): bool
    {
        return $existing['targetId'] !== null && (int) $existing['targetId'] !== $targetId;
    }

    /**
     * The actual DB write for record() — split out so tests can override
     * just this primitive (see MigrationStateServiceRecordCollisionTest)
     * and exercise the real collision-guard logic above it without a
     * booted Craft application.
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed>|null $meta
     */
    protected function persistRecord(
        ?array $existing,
        string $source,
        string $key,
        string $targetType,
        int $targetId,
        string $targetUidSafe,
        ?int $siteId,
        ?array $meta,
    ): void {
        $now = Db::prepareDateForDb(new DateTime());

        // Meta column is MySQL JSON; Yii's ColumnSchema auto-encodes arrays on
        // write and auto-decodes on read — pass the array straight through.
        if ($existing) {
            $this->db()->createCommand()->update(
                $this->table(),
                [
                    'targetType' => $targetType,
                    'targetId' => $targetId,
                    'targetUid' => $targetUidSafe,
                    'meta' => $meta !== null ? $meta : $existing['meta'],
                    'dateUpdated' => $now,
                ],
                ['id' => $existing['id']],
            )->execute();
            return;
        }

        $this->db()->createCommand()->insert($this->table(), [
            'source' => $source,
            'sourceKey' => $key,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetUid' => $targetUidSafe,
            'siteId' => $siteId,
            'meta' => $meta,
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ])->execute();
    }

    /**
     * Overridable so tests can capture the message instead of routing
     * through Craft::warning(), which needs a booted Craft application this
     * repo's test suite doesn't provide (mirrors the fakeable-primitive
     * convention used throughout tests/ — e.g. SchemaGateway fakes). The
     * try/catch is a defensive fallback for any other bootless caller;
     * production code always has Craft loaded.
     */
    protected function warn(string $message): void
    {
        try {
            Craft::warning($message, 'kunstmaan-migrator');
        } catch (Throwable) {
        }
    }

    /**
     * Merge additional keys into the existing meta JSON (does not replace).
     *
     * @param array<string, mixed> $meta
     */
    public function updateMeta(string $source, string $key, ?int $siteId, array $meta): void
    {
        $existing = $this->get($source, $key, $siteId);
        if (!$existing) {
            return;
        }

        // Yii's MySQL driver returns JSON columns already decoded to arrays,
        // but be defensive in case a row was written by a different path.
        $currentMeta = [];
        if (!empty($existing['meta'])) {
            if (is_array($existing['meta'])) {
                $currentMeta = $existing['meta'];
            } else {
                $decoded = json_decode((string) $existing['meta'], true);
                if (is_array($decoded)) {
                    $currentMeta = $decoded;
                }
            }
        }
        $merged = array_merge($currentMeta, $meta);

        $this->db()->createCommand()->update(
            $this->table(),
            [
                'meta' => $merged,
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ],
            ['id' => $existing['id']],
        )->execute();
    }

    /**
     * Task 4 — resolve a `sourceUid` (`kuma:<ENV>:<table>:<id>`) straight to
     * the Craft target id it currently resolves to, or null when the target
     * hasn't been migrated yet. Delegates the grammar parsing to
     * `RefResolver::parse()` (single source of truth for the regex — see
     * that class) and only adds the state-table lookup.
     */
    public function resolveSourceUid(string $uid): ?int
    {
        $parsed = RefResolver::parse($uid);
        if ($parsed === null) {
            return null;
        }

        return $this->getTargetId($parsed['source'], $parsed['key']);
    }

    /**
     * Task 4 — record an extra state row for an alias `sourceUid` (e.g. a
     * duplicated node across environments/locales, per docs/loader-contract.md)
     * pointing at the same Craft target as its primary `sourceUid`. Meta
     * carries `alias_of` so a state dump can tell an alias row from a
     * primary one.
     *
     * Silently no-ops on a malformed `aliasUid` — validation of the
     * `sourceUid` grammar for every alias already happened upstream in
     * `PayloadValidator` before a live save is ever attempted.
     */
    public function recordAlias(string $aliasUid, string $primaryUid, int $targetId): void
    {
        $parsed = RefResolver::parse($aliasUid);
        if ($parsed === null) {
            return;
        }

        $this->record(
            source: $parsed['source'],
            key: $parsed['key'],
            targetType: 'entry',
            targetId: $targetId,
            meta: ['alias_of' => $primaryUid],
        );
    }

    public function forget(string $source, string $key, ?int $siteId = null): void
    {
        $this->db()->createCommand()->delete(
            $this->table(),
            $this->keyCondition($source, $key, $siteId),
        )->execute();
    }

    /**
     * Phase 4.1 / D-37 — write the terminal-state marker into the existing
     * meta JSON column. No schema migration (PATTERNS recommendation b).
     *
     * Sync recovery commands (REC-01 `migrate/sync-assets` and any future
     * REC-02 `migrate/sync-relations` surface) call this after they classify
     * a row's failure as permanent (filesystem_404 / too_large) so subsequent
     * re-runs never retry the row. Reuses updateMeta() (lines 149-179) so the
     * existing meta payload is preserved (array_merge semantic) — only the
     * three terminal-state keys are added/overwritten.
     *
     * No-ops on missing rows (mirrors updateMeta's existence check).
     */
    public function markTerminal(
        string $source,
        string $key,
        ?int $siteId,
        string $reason,
    ): void {
        $this->updateMeta($source, $key, $siteId, $this->buildTerminalMeta($reason));
    }

    /**
     * Phase 4.1 / D-37 — check whether a row carries the terminal-state
     * marker. Reads via get(); returns false when the row doesn't exist OR
     * when the marker is absent.
     *
     * Sync recovery commands call this from their candidate-collection loop
     * to skip permanently-failed rows (T-04.1-07-01 mitigation — prevents
     * the retry-loop DoS).
     */
    public function isTerminal(string $source, string $key, ?int $siteId): bool
    {
        $row = $this->get($source, $key, $siteId);
        if (!$row) {
            return false;
        }
        return $this->isTerminalMarker($row['meta'] ?? null);
    }

    /**
     * Phase 4.1 / D-37 — pure shape builder for the terminal-state marker
     * meta payload. Extracted as a private helper so the shape contract can
     * be characterized via Reflection without a Craft DB bootstrap (mirrors
     * the AssetMigrationService::classifyAssetFailureReason testability
     * pattern from Phase 4 / Plan 12).
     *
     * @return array<string, string>
     */
    private function buildTerminalMeta(string $reason): array
    {
        return [
            'terminalState'  => 'permanently_failed',
            'terminalReason' => $reason,
            'terminalAt'     => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Phase 4.1 / D-37 — pure predicate for the terminal-state marker.
     * Accepts the raw meta value as Yii's MySQL JSON-column reader hands it
     * back (decoded array on the happy path) AND defensively as a JSON string
     * (rows written through a different path may bypass the auto-decode).
     *
     * Returns false for null / empty / non-array-non-decodable / arrays
     * without the sentinel — only an exact `terminalState='permanently_failed'`
     * match counts.
     */
    private function isTerminalMarker(mixed $meta): bool
    {
        if ($meta === null || $meta === '') {
            return false;
        }
        if (is_array($meta)) {
            return ($meta['terminalState'] ?? null) === 'permanently_failed';
        }
        if (!is_string($meta)) {
            return false;
        }
        $decoded = json_decode($meta, true);
        if (!is_array($decoded)) {
            return false;
        }
        return ($decoded['terminalState'] ?? null) === 'permanently_failed';
    }

    /**
     * Return the most recent state-row meta as an associative array, or null
     * if the state table is empty / the newest row has no meta payload.
     *
     * Used by migrate/check D-17 drift detection (Plan 05.5-06 Task 3) to
     * compare last-run filter settings against current CP settings. Reads
     * the newest row by `dateUpdated DESC` — matches the column declared in
     * the install migration.
     *
     * Decodes JSON only; never calls PHP native deserialize (central-decode policy
     * preserved). Yii's MySQL driver returns JSON columns already decoded,
     * but we defensively re-decode in case a row was written through a
     * different path.
     *
     * IMPORTANT: the meta column must be set by the run recorder at migrate
     * time for this method to return non-null. If no service records a
     * "run start" row with filter meta, the drift-detection caller falls
     * back to a warning-only path (see MigrateController::actionCheck).
     *
     * @see .planning/phases/05.5-harden-migrator-plugin-error-handling/05.5-CONTEXT.md §D-17
     * @return array<string, mixed>|null
     */
    public function getLastRunMeta(): ?array
    {
        $table = $this->table();
        $row = $this->db()->createCommand(
            "SELECT meta FROM {$table} ORDER BY dateUpdated DESC LIMIT 1"
        )->queryOne();
        if (!$row || !array_key_exists('meta', $row) || $row['meta'] === null) {
            return null;
        }

        // Yii may hand back an already-decoded array for MySQL JSON columns.
        if (is_array($row['meta'])) {
            return $row['meta'];
        }

        $raw = (string) $row['meta'];
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Craft::warning("getLastRunMeta: JSON decode failed: {$e->getMessage()}", __METHOD__);
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Count rows for a given source — used by the `migrate/report` action
     * to summarise per-source counts without streaming every row through PHP.
     */
    public function countBySource(string $source): int
    {
        return (int) (new Query())
            ->from($this->table())
            ->where(['source' => $source])
            ->count('*', $this->db());
    }

    /**
     * Stream all rows for a given source (e.g. 'media', 'page', 'seo').
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function all(string $source): Generator
    {
        $reader = $this->db()->createCommand(
            'SELECT * FROM ' . $this->db()->schema->quoteTableName($this->table())
            . ' WHERE source = :s',
            [':s' => $source],
        )->query();
        try {
            foreach ($reader as $row) {
                yield $row;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Stream every entry-producing state row, regardless of source alias.
     *
     * The migration pipeline now records entries under FQCN-derived source
     * names, so consumers that operate across all migrated entries should not
     * depend on historic section aliases such as news/cases/team.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function entryRows(): Generator
    {
        $reader = $this->db()->createCommand(
            'SELECT * FROM ' . $this->db()->schema->quoteTableName($this->table())
            . ' WHERE targetType = :targetType',
            [':targetType' => 'entry'],
        )->query();
        try {
            foreach ($reader as $row) {
                yield $row;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Executes `$callback` only if `(source, key, siteId)` has not already
     * been recorded. The callback must return an array with keys
     * `targetType` + `targetId`, optionally `targetUid` + `meta`.
     *
     * If the callback throws, state is NOT recorded — so the failed row
     * will be retried on the next run.
     *
     * @param callable(): array{targetType: string, targetId: int, targetUid?: string|null, meta?: array<string, mixed>|null} $callback
     */
    public function runOnce(
        string $source,
        string $key,
        callable $callback,
        ?int $siteId = null,
    ): void {
        if ($this->has($source, $key, $siteId)) {
            return;
        }

        $result = $callback();

        if (!is_array($result) || !isset($result['targetType'], $result['targetId'])) {
            throw new RuntimeException(
                "runOnce callback for {$source}:{$key} must return array with targetType+targetId",
            );
        }

        $this->record(
            $source,
            $key,
            (string) $result['targetType'],
            (int) $result['targetId'],
            $result['targetUid'] ?? null,
            $siteId,
            $result['meta'] ?? null,
        );
    }

    /**
     * Builds the hash-format where-clause shared by has/get/forget/updateMeta.
     *
     * Yii's Query hash-format treats `['col' => null]` as `col IS NULL`, so a
     * null siteId is representable as a flat associative entry — no numeric
     * sub-expression is needed (and a numeric entry would be mis-parsed as
     * an operator invocation).
     *
     * Pitfall 5 — DB UNIQUE doesn't protect NULL-site rows in MySQL, so
     * record()'s pre-lookup via this condition is the correctness gate.
     *
     * @return array<string, int|string|null>
     */
    private function keyCondition(string $source, string $key, ?int $siteId): array
    {
        return [
            'source' => $source,
            'sourceKey' => $key,
            'siteId' => $siteId,
        ];
    }
}
