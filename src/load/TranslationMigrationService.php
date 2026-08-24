<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use craft\helpers\FileHelper;
use lameco\kunstmaanmigrator\adapters\GatedAdapter;
use lameco\kunstmaanmigrator\adapters\MigrationAdapter;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\run\EnvironmentContext;
use Throwable;
use yii\base\Component;

/**
 * TranslationMigrationService — imports Kunstmaan TranslatorBundle data
 * (`kuma_translation` table) into Craft's site translations PHP catalogs
 * (and optionally enupal-translate's DB tables for CP editing).
 *
 * Source shape: `kuma_translation` (kuma_translator_bundle):
 *   `(id, keyword, locale, text, domain, status)`
 * One row per (keyword, locale) tuple. `domain='messages'` is the standard
 * Symfony default (matching `{% trans %}` calls without explicit domain).
 *
 * Target shape — the runtime path:
 *   Craft's `'foo' | t` filter (default category `'site'`) routes through
 *   Yii2's PhpMessageSource which reads PHP catalog files at
 *   `<basePath>/translations/<lang>/site.php`. Format is a flat
 *   `<?php return ['foo' => 'translation', ...];`. Without these files
 *   the t-filter falls through to the literal key — exactly what dewert
 *   smoke renders for `service.heading` / `footer.headingLeft` etc.
 *
 *   enupal-translate provides a CP UI for editing these catalogs +
 *   parallel DB tables (`enupaltranslate_sourcemessage` + `_message`)
 *   that mirror the file contents. The DB rows are CP-convenience only;
 *   runtime lookups go through the files. We write both — files for
 *   runtime, DB rows so operators can edit through the CP after migrate.
 *
 * Locale mapping: kuma_translation.locale (e.g. 'nl', 'en') maps to
 * Craft sites' `language` field via `$this->sites` (kuma_locale →
 * Craft site handle, populated by Plugin::resolveSitesMap()). Source
 * rows whose locale doesn't match any Craft site are reported and
 * skipped — they'd be invisible at runtime anyway.
 *
 * Optional-plugin gate: enupal-translate's DB writes are gated on
 * the plugin being installed. When absent, the file writes still
 * happen (they're independent of the plugin) — runtime t-filter works
 * either way; only the CP editing UI is gated.
 *
 * State key: `('translation', "kuma_translation:{$kumaId}")` per
 * source row.
 *
 * Domain handling: only `messages` domain (Symfony default — the one
 * `{% trans %}` calls without explicit domain use) is migrated. Other
 * domains (`validators`, `security`, etc.) are skipped with a per-domain
 * report warning. They're rarely used in Lameco sites; if needed,
 * Settings::translationDomains can extend the allowed list later.
 */
class TranslationMigrationService extends Component implements MigrationAdapter
{
    /**
     * The Kunstmaan schema is fixed: these table names are the same in every
     * corpus this migrator targets, so they are constants rather than a
     * settings surface nobody ever used.
     */
    public const TRANSLATION_TABLE = 'kuma_translation';

    public LegacyDbService $legacyDb;
    public MigrationStateService $stateService;



    /**
     * Source domains to migrate. Defaults to `['messages']` — Symfony's
     * default domain, the one `{% trans %}` calls without explicit
     * `from 'X'` use. Other domains (validators, security, etc.) are
     * skipped with a warning.
     *
     * @var list<string>
     */
    public array $allowedDomains = ['messages'];

    private const STATE_SOURCE = 'translation';

    /**
     * Read every `kuma_translation` row, group by locale, write per-locale
     * PHP catalog files at `<base>/translations/<lang>/site.php`, and (if
     * enupal-translate is installed) UPSERT parallel DB rows.
     */
    use GatedAdapter;

    public function handle(): string
    {
        return 'translations';
    }

    public function migrateAll(MigrationOptions $opts, EnvironmentContext $context): MigrationReport
    {
        $report = new MigrationReport();

        if (!$this->isGateOpen($report)) {
            return $report;
        }

        $sites = $context->sites;

        $localeToLanguage = $sites->localeToLanguage();
        if ($localeToLanguage === []) {
            $report->warn('No Craft sites mapped; translation migration aborted.');
            return $report;
        }

        try {
            $rows = $this->legacyDb->queryAll(
                'SELECT id, keyword, locale, text, domain, status
                 FROM ' . self::TRANSLATION_TABLE . '
                 WHERE status = \'enabled\'
                 ORDER BY keyword, locale',
            );
        } catch (Throwable $e) {
            $report->warn(sprintf(
                'Could not read %s (%s); translation migration skipped (table may not exist on this Kunstmaan vintage).',
                self::TRANSLATION_TABLE,
                $e->getMessage(),
            ));
            return $report;
        }

        if ($rows === []) {
            $report->warn(sprintf(
                'No rows in %s; translation migration skipped (NB: TranslatorBundle is optional in Kunstmaan, sites without it use yaml-only translations).',
                self::TRANSLATION_TABLE,
            ));
            return $report;
        }

        // Bucket source rows by Craft language code, dropping rows whose
        // locale doesn't map to any configured Craft site or whose
        // domain isn't in the allow-list.
        /** @var array<string, array<string, string>> $catalogs  language → keyword → translation */
        $catalogs = [];
        $skippedDomains = [];
        $skippedLocales = [];
        foreach ($rows as $row) {
            $domain = (string) ($row['domain'] ?? '');
            if (!in_array($domain, $this->domains(), true)) {
                $skippedDomains[$domain] = ($skippedDomains[$domain] ?? 0) + 1;
                $report->incr('skipped');
                continue;
            }

            $locale = (string) ($row['locale'] ?? '');
            $craftLang = $localeToLanguage[$locale] ?? null;
            if ($craftLang === null) {
                $skippedLocales[$locale] = ($skippedLocales[$locale] ?? 0) + 1;
                $report->incr('skipped');
                continue;
            }

            $keyword = (string) ($row['keyword'] ?? '');
            $text = (string) ($row['text'] ?? '');
            if ($keyword === '') {
                $report->incr('skipped');
                continue;
            }

            $catalogs[$craftLang][$keyword] = $text;
        }

        foreach ($skippedDomains as $domain => $count) {
            $report->warn(sprintf(
                'Translation domain "%s" is outside Settings::translationDomains; skipped %d row(s). Extend the setting if these need to migrate.',
                $domain,
                $count,
            ));
        }
        foreach ($skippedLocales as $locale => $count) {
            $report->warn(sprintf(
                'Translation locale "%s" has no matching Craft site; skipped %d row(s). Add a Craft site for this language or remove the source rows.',
                $locale,
                $count,
            ));
        }

        if ($catalogs === []) {
            $report->warn('No translation rows survived domain/locale filtering; nothing to write.');
            return $report;
        }

        // Write per-locale PHP catalogs. This is what Craft's t-filter
        // reads at runtime — without these files, `{{ "foo" | t }}`
        // returns the literal key.
        $sitePath = Craft::$app->getPath()->getSiteTranslationsPath();
        foreach ($catalogs as $craftLang => $keywords) {
            if ($opts->dryRun) {
                $report->incr('skipped');
                $report->warn(sprintf('[dry-run] would write %d translations for locale=%s', count($keywords), $craftLang));
                continue;
            }
            try {
                $this->writeCatalog($sitePath, $craftLang, $keywords);
                $report->incr('created');
            } catch (Throwable $e) {
                $report->incr('failed');
                $report->warn(sprintf(
                    'Failed to write site.php catalog for locale=%s: %s',
                    $craftLang,
                    $e->getMessage(),
                ));
            }
        }

        // Persist state per kuma_translation row (one row per
        // (keyword, locale) tuple) so truncate can wipe everything we
        // own. We don't save targetIds — the catalog file path is
        // implicit and idempotent.
        if (!$opts->dryRun) {
            foreach ($rows as $row) {
                $kumaId = (int) ($row['id'] ?? 0);
                if ($kumaId <= 0) {
                    continue;
                }
                $locale = (string) ($row['locale'] ?? '');
                $craftLang = $localeToLanguage[$locale] ?? null;
                if ($craftLang === null) {
                    continue;
                }
                $stateKey = 'kuma_translation:' . $kumaId;
                // targetId carries the kuma row id (the source-side row
                // is the canonical id for translations — there's no
                // single Craft element id since the data lives in
                // per-locale PHP catalog files). MigrationStateService
                // requires int, so kumaId doubles as both the
                // sourceKey suffix and a meaningful targetId pointer.
                $this->stateService->record(
                    source: self::STATE_SOURCE,
                    key: $stateKey,
                    targetType: 'site_translation',
                    targetId: $kumaId,
                    meta: [
                        'kumaId' => $kumaId,
                        'keyword' => (string) ($row['keyword'] ?? ''),
                        'locale' => $locale,
                        'craftLang' => $craftLang,
                        'domain' => (string) ($row['domain'] ?? ''),
                    ],
                );
            }
        }

        // Optional: enupal-translate DB rows. Runtime t-filter doesn't
        // need these — it reads the files. But populating the DB lets
        // operators edit translations through the CP after migrate
        // (the CP rewrites the file on save).
        if (!$opts->dryRun
            && Craft::$app->plugins->getPlugin('enupal-translate') !== null
        ) {
            $this->upsertEnupalRows($catalogs, $report);
        }

        return $report;
    }

    // --------------------------------------------------------------------------
    // Private helpers
    // --------------------------------------------------------------------------


    /**
     * Write a flat-key PHP catalog at
     * `<sitePath>/<lang>/site.php` for runtime t-filter consumption.
     *
     * Format mirrors enupal-translate's writeToFile (and Craft core's
     * own translation files): a `<?php return [...]` PHP file with
     * single-quoted keys and tab indentation. Idempotent — overwrites
     * any existing file from scratch.
     *
     * Pre-existing operator edits to the same file are CLOBBERED. The
     * migration owns the file end-to-end: re-runs are a complete
     * rebuild from `kuma_translation`. If operators want to add custom
     * translations post-migrate, they should edit through the CP
     * (enupal-translate writes back to the same file).
     *
     * @param array<string, string> $keywords
     */
    private function writeCatalog(string $sitePath, string $lang, array $keywords): void
    {
        $dir = $sitePath . DIRECTORY_SEPARATOR . $lang;
        $file = $dir . DIRECTORY_SEPARATOR . 'site.php';

        if (!is_dir($dir)) {
            FileHelper::createDirectory($dir, 0775);
        }

        ksort($keywords, SORT_NATURAL);
        $php = "<?php\n\nreturn " . var_export($keywords, true) . ";\n";
        // Convert var_export's two-space indent to tab to match Craft's
        // own translation file convention (cosmetic only — runtime
        // doesn't care).
        $php = (string) preg_replace("/^  '/m", "\t'", $php);

        FileHelper::writeToFile($file, $php);
    }

    /**
     * UPSERT enupal-translate's parallel DB rows so operators can
     * edit translations through the CP. Runtime t-filter reads from
     * the files (already written above), so this is purely for
     * editor convenience.
     *
     * Strategy mirrors enupal-translate's own SyncTranslationsWithDb
     * job: one sourcemessage row per unique key, then one message row
     * per (key, language) where `enupaltranslate_message.id ==
     * enupaltranslate_sourcemessage.id` (parallel-id, no FK column).
     *
     * @param array<string, array<string, string>> $catalogs language → keyword → text
     */
    private function upsertEnupalRows(array $catalogs, MigrationReport $report): void
    {
        $sourceMessageTable = '{{%enupaltranslate_sourcemessage}}';
        $messageTable = '{{%enupaltranslate_message}}';
        $db = Craft::$app->db;

        // Collect every distinct keyword across all locales — these
        // become the sourcemessage rows. category='site' matches
        // enupal-translate's default for "site translations".
        $keywords = [];
        foreach ($catalogs as $byKey) {
            foreach (array_keys($byKey) as $kw) {
                $keywords[$kw] = true;
            }
        }
        $keywords = array_keys($keywords);
        if ($keywords === []) {
            return;
        }

        try {
            // Existing sourcemessage rows by message → id
            $existing = (new \craft\db\Query())
                ->select(['message', 'id'])
                ->from($sourceMessageTable)
                ->where(['category' => 'site'])
                ->all($db);
        } catch (Throwable $e) {
            $report->warn(sprintf(
                'Could not read enupaltranslate_sourcemessage (%s); enupal CP-editing parallel skipped, runtime translations still work via files.',
                $e->getMessage(),
            ));
            return;
        }

        $idByMessage = [];
        foreach ($existing as $row) {
            $idByMessage[(string) $row['message']] = (int) $row['id'];
        }

        $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // INSERT new sourcemessage rows
        $rowsToInsert = [];
        foreach ($keywords as $kw) {
            if (!isset($idByMessage[$kw])) {
                $rowsToInsert[] = [
                    'category' => 'site',
                    'message' => $kw,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ];
            }
        }
        if ($rowsToInsert !== []) {
            try {
                $db->createCommand()
                    ->batchInsert(
                        $sourceMessageTable,
                        ['category', 'message', 'dateCreated', 'dateUpdated', 'uid'],
                        array_map('array_values', $rowsToInsert),
                    )
                    ->execute();
            } catch (Throwable $e) {
                $report->warn(sprintf(
                    'enupaltranslate_sourcemessage batchInsert failed: %s',
                    $e->getMessage(),
                ));
                return;
            }
            // Re-read to pick up the new ids
            try {
                $rows = (new \craft\db\Query())
                    ->select(['message', 'id'])
                    ->from($sourceMessageTable)
                    ->where(['category' => 'site'])
                    ->all($db);
                foreach ($rows as $row) {
                    $idByMessage[(string) $row['message']] = (int) $row['id'];
                }
            } catch (Throwable) {
                // non-fatal
            }
        }

        // UPSERT message rows per (sourceId, language)
        foreach ($catalogs as $lang => $byKey) {
            foreach ($byKey as $kw => $text) {
                $sourceId = $idByMessage[$kw] ?? null;
                if ($sourceId === null) {
                    continue;
                }
                try {
                    $db->createCommand()
                        ->upsert(
                            $messageTable,
                            [
                                'id' => $sourceId,
                                'language' => $lang,
                                'translation' => $text,
                                'dateCreated' => $now,
                                'dateUpdated' => $now,
                                'uid' => \craft\helpers\StringHelper::UUID(),
                            ],
                            [
                                'translation' => $text,
                                'dateUpdated' => $now,
                            ],
                        )
                        ->execute();
                } catch (Throwable $e) {
                    $report->warn(sprintf(
                        'enupaltranslate_message upsert failed for (id=%d, lang=%s): %s',
                        $sourceId,
                        $lang,
                        $e->getMessage(),
                    ));
                }
            }
        }
    }


    /**
     * The Symfony domains this pass migrates.
     *
     * Declared by the adapter rather than patched onto this service from
     * Plugin::init(); `$allowedDomains` remains as the override a caller sets.
     *
     * @return list<string>
     */
    private function domains(): array
    {
        if ($this->allowedDomains !== ['messages']) {
            return $this->allowedDomains;
        }

        $configured = $this->config()['domains'] ?? null;

        return is_array($configured) && $configured !== []
            ? array_values(array_map(strval(...), $configured))
            : $this->allowedDomains;
    }
}
