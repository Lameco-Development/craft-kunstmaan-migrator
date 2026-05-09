<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use craft\elements\Entry;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\mapping\MappingFile;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Phase 8 / D-08 — verbatim port of v1's TaxonomyMigrationService (443 LOC at
 * `~/Sites/craft-kunstmaan-migrator/src/bridge/load/TaxonomyMigrationService.php`).
 *
 * Load-stage service for Doctrine standalone taxonomy entities (NewsCategory,
 * CaseStudyCategory, Employee-style standalone tables). Walks each accepted
 * row in `mapping.taxonomies`, queries the row's flat legacy table, upserts
 * one Craft entry per source row into the declared section + entry-type, and
 * applies a per-site Gedmo Translatable overlay where `ext_translations`
 * carries translated values.
 *
 * v1 → v2 reshape (5 points — see also `.planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md` (Plan 17)):
 *   1. Single mapping.yaml — v1's `MappingLoader` (3-file merge) replaced with
 *      v2's `MappingFile->load()` (single file). D-08.
 *   2. Atomic-always-on — per-row `Craft::$app->db->transaction(...)` is the
 *      only mode. v1 also wrapped writes; v2 drops the `--atomic` flag check.
 *      PROJECT.md ground rule.
 *   3. D-09 empty-`ext_translations` fallback — when extTranslationsFor()
 *      returns [], copy source-locale row across every site in mapping.sites.
 *      NEW v2 behavior, not in v1. (NB: Craft's canonical save already
 *      propagates to all sites; the explicit per-site re-save with
 *      propagateChanges=false is belt-and-suspenders to keep the contract
 *      visible and to align with the per-site overlay branch's shape.)
 *   4. taxonomies block shape — v1's row carried v1-shaped fields like
 *      `{ section, entryType, sourceTable, fields: { handle => { source, handler } }, gedmoFqcns, action }`.
 *      v2 row is compiler-emitted (Plan 09 / `compileTaxonomies`):
 *      `{ sourceTable, targetSection, targetEntryType, fields: { legacyCol => craftHandle } }`.
 *      The fields shape inverted: legacy column is now the KEY, Craft handle
 *      is the VALUE. v2 has no `gedmoFqcns` (default to [$fqcn]) and no
 *      `action: SKIP` (compiler only emits accepted rows; defensive check
 *      preserved nonetheless).
 *   5. Detection-inside-the-service short-circuit (Phase 4 / D-56) — empty
 *      taxonomies block emits a single WARN line via `$report->warn(...)` +
 *      `Craft::warning(...)` and returns. Mirrors SeoMigrationService::migrateAll
 *      lines 131-149.
 *
 * Site-agnostic state rows (siteId=null): per v1 docblock, taxonomy entries
 * are language-neutral and the state table is the only identity record.
 *
 * Taxonomy entry types do NOT have the legacy-id custom field attached
 * (v1 docblock lines 41-45 preserved here verbatim). The state-table is
 * the only identity record for taxonomy rows.
 *
 * NeverProductionTrait is NOT applied at the service level — applied at the
 * controller seam (Plan 12 / MigrateController::actionTaxonomies) per the
 * controller-gates-legacy-reads convention.
 *
 * Idempotent: re-runs upsert via `migrationState->getTargetId()`; same Craft
 * entry id is reused across runs.
 *
 * Source-table regex whitelist preserved verbatim from v1: SQL injection
 * defense for the `SELECT * FROM <sourceTable>` query that interpolates the
 * table name into raw SQL.
 *
 * @see ~/Sites/craft-kunstmaan-migrator/src/bridge/load/TaxonomyMigrationService.php (v1 verbatim-port reference)
 * @see .planning/phases/08-taxonomies-and-proposers/08-PATTERNS.md (TaxonomyMigrationService — verbatim port)
 * @see .planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md (Plan 17 — full v1→v2 reshape table)
 */
class TaxonomyMigrationService extends Component
{
    public ?MigrationStateService $migrationState = null;
    public ?LegacyDbService $legacyDb = null;
    /** D-08 reshape #1: v1's MappingLoader → v2's MappingFile (single mapping.yaml). */
    public ?MappingFile $mappingFile = null;
    public ?MigrationFilters $filters = null;

    /**
     * Resolve a single page-referenced taxonomy row on demand.
     *
     * RelationHandler calls this only after the normal state-table lookup has
     * missed and only when handlerOptions identify the relation as taxonomy-
     * backed. This service remains the only owner of taxonomy source reads,
     * Craft entry upserts, locale fallback, and state recording.
     */
    public function resolveReferenced(
        string $taxonomySource,
        int|string $legacyId,
        MigrationOptions $opts,
        ?MigrationReport $report = null,
    ): ?int {
        $legacyId = (int) $legacyId;
        if ($taxonomySource === '' || $legacyId <= 0) {
            return null;
        }

        $mapping = $this->mappingFile->load();
        $match = $this->findTaxonomyMapping($mapping, $taxonomySource);
        if ($match === null) {
            $report?->warn(sprintf(
                'taxonomy resolver unresolved: no mapping for source=%s legacyId=%d',
                $taxonomySource,
                $legacyId,
            ));
            return null;
        }

        [$fqcn, $row] = $match;
        $row = $this->validatedTaxonomyRow($fqcn, $row);
        $stateSource = $this->fqcnToSlug($fqcn);

        $existingCraftId = $this->migrationState->getTargetId($stateSource, (string) $legacyId, null);
        if ($existingCraftId !== null) {
            $report?->incr($opts->dryRun ? 'taxonomy.wouldLink' : 'taxonomy.linkedExisting');
            return $existingCraftId;
        }

        $legacyRow = $this->loadLegacyTaxonomyRow($fqcn, (string) $row['sourceTable'], $legacyId);
        if ($legacyRow === null) {
            $report?->warn(sprintf(
                'taxonomy resolver unresolved: %s id=%d source row not found',
                $stateSource,
                $legacyId,
            ));
            return null;
        }

        if ($opts->dryRun) {
            $report?->incr('taxonomy.wouldCreate');
            $report?->incr('taxonomy.wouldLink');
            $report?->warn(sprintf(
                'taxonomy resolver dry-run: would-create %s id=%d and would-link relation',
                $stateSource,
                $legacyId,
            ));
            return null;
        }

        $section = Craft::$app->getEntries()->getSectionByHandle((string) $row['targetSection']);
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle((string) $row['targetEntryType']);
        if ($section === null || $entryType === null) {
            throw new RuntimeException(
                "taxonomies[$fqcn]: section or entryType not found in Craft "
                . "(section={$row['targetSection']} type={$row['targetEntryType']})",
            );
        }

        $createdOrUpdatedId = null;
        Craft::$app->db->transaction(function () use (
            $fqcn,
            $stateSource,
            $legacyId,
            $legacyRow,
            $row,
            $mapping,
            $opts,
            $report,
            $section,
            $entryType,
            &$createdOrUpdatedId,
        ): void {
            $createdOrUpdatedId = $this->upsertOneEntry(
                (int) $section->id,
                (int) $entryType->id,
                $stateSource,
                $fqcn,
                [],
                $legacyId,
                $legacyRow,
                (array) ($row['fields'] ?? []),
                $mapping,
                $opts,
                $report ?? new MigrationReport(),
            );
        });

        if ($createdOrUpdatedId !== null) {
            $report?->incr('taxonomy.linked');
        }

        return $createdOrUpdatedId;
    }

    public function migrateAll(MigrationOptions $opts): MigrationReport
    {
        $report = new MigrationReport();

        $mapping = $this->mappingFile->load();
        $taxonomies = (array) ($mapping['taxonomies'] ?? []);

        // D-08 reshape #5 — detection-inside-the-service short-circuit
        // (Phase 4 / D-56). Mirrors SeoMigrationService::migrateAll lines
        // 131-149. Empty / missing taxonomies block: WARN once + return.
        if ($taxonomies === []) {
            Craft::warning(
                'No taxonomies in mapping; taxonomy migration skipped.',
                'kunstmaanmigrator',
            );
            $report->warn('No taxonomies in mapping; taxonomy migration skipped.');
            return $report;
        }

        foreach ($taxonomies as $fqcn => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->filters !== null && !$this->filters->allows((string) $fqcn)) {
                $report->incr('skipped');
                $report->warn(sprintf(
                    'taxonomies[%s] skipped: filtered out by source entity scope',
                    (string) $fqcn,
                ));
                continue;
            }
            // D-08 reshape #4 — v2 compiler never emits `action: SKIP`
            // (compileTaxonomies only emits accepted rows). Defensive check
            // preserved nonetheless: future hand-edits might still add it.
            if (($row['action'] ?? null) === 'SKIP') {
                $report->incr('skipped');
                continue;
            }

            // Phase 8.1 / D-08a — soft-skip on incomplete rows. Compile (with
            // 8.1 / D-07a in place) refuses to emit incomplete rows, but the
            // operator may still hand-edit mapping.yaml. Skipping with WARN
            // (instead of throwing) lets the rest of the taxonomies stage
            // continue and surfaces the gap operator-actionably in REPORT.md.
            $taxSection = (string) ($row['targetSection'] ?? '');
            $taxEntryType = (string) ($row['targetEntryType'] ?? '');
            $taxSourceTable = (string) ($row['sourceTable'] ?? '');
            if ($taxSection === '' || $taxEntryType === '' || $taxSourceTable === '') {
                $report->incr('skipped');
                $report->warn(sprintf(
                    'taxonomies[%s] skipped: incomplete (sourceTable=%s, targetSection=%s, targetEntryType=%s) — re-run analyze or fix mapping.yaml',
                    (string) $fqcn,
                    $taxSourceTable !== '' ? $taxSourceTable : '∅',
                    $taxSection !== '' ? $taxSection : '∅',
                    $taxEntryType !== '' ? $taxEntryType : '∅',
                ));
                continue;
            }

            $this->migrateOneTaxonomy((string) $fqcn, $row, $mapping, $opts, $report);
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $row     Compiler-emitted taxonomy row (D-08 reshape #4)
     * @param array<string, mixed> $mapping Full mapping.yaml — needed for D-09 sites lookup
     */
    private function migrateOneTaxonomy(
        string $fqcn,
        array $row,
        array $mapping,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void {
        // D-08 reshape #4: v1 row used `section` / `entryType`; v2 compiler
        // emits `targetSection` / `targetEntryType` (mirrors the nodeClass
        // row shape — see compileTaxonomies in MappingCompiler).
        $row = $this->validatedTaxonomyRow($fqcn, $row);
        $sectionHandle = (string) $row['targetSection'];
        $entryTypeHandle = (string) $row['targetEntryType'];
        $sourceTable = (string) $row['sourceTable'];
        // D-08 reshape #4: v2 fields shape is `{ legacyCol => craftHandle }`
        // (flat string→string), inverted from v1's
        // `{ craftHandle => { source: legacyCol, handler: 'plain' } }`.
        $fieldsMap = (array) ($row['fields'] ?? []);

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($entryTypeHandle);
        if ($section === null || $entryType === null) {
            throw new RuntimeException(
                "taxonomies[$fqcn]: section or entryType not found in Craft "
                . "(section=$sectionHandle type=$entryTypeHandle)",
            );
        }

        $stateSource = $this->fqcnToSlug($fqcn);

        // D-08 reshape #4: v1 carried explicit `gedmoFqcns` aliases on the
        // row payload (legacy bundle-namespaced FQCNs to try when canonical
        // had no ext_translations rows). v2 compiler emits no such field —
        // default to [$fqcn] (canonical only). Future enhancement: derive
        // alias chain from the parent class chain if needed.
        $gedmoFqcns = [];

        // Read flat legacy rows via LegacyDbService.
        $sql = sprintf('SELECT * FROM %s', $sourceTable);
        $rows = $this->legacyDb->queryAll($sql);

        foreach ($rows as $legacyRow) {
            $legacyId = (int) ($legacyRow['id'] ?? 0);
            if ($legacyId <= 0) {
                $report->incr('failed');
                continue;
            }

            try {
                // D-08 reshape #2 — atomic-always-on per row. No --atomic flag.
                Craft::$app->db->transaction(function () use (
                    $fqcn, $gedmoFqcns,
                    $section, $entryType, $stateSource, $legacyId, $legacyRow,
                    $fieldsMap, $mapping, $opts, $report,
                ): void {
                    $this->upsertOneEntry(
                        $section->id,
                        $entryType->id,
                        $stateSource,
                        $fqcn,
                        $gedmoFqcns,
                        $legacyId,
                        $legacyRow,
                        $fieldsMap,
                        $mapping,
                        $opts,
                        $report,
                    );
                });
            } catch (Throwable $e) {
                $report->incr('failed');
                Craft::warning(
                    sprintf(
                        'TaxonomyMigrationService: %s id=%d failed: %s',
                        $stateSource,
                        $legacyId,
                        $e->getMessage(),
                    ),
                    __METHOD__,
                );
            }
        }
    }

    /**
     * Primary-site upsert followed by a per-site pass for Gedmo Translatable
     * fields. Craft propagates the canonical save to all sites automatically;
     * we then reload each non-primary site entry and overwrite any fields
     * that have translations in `ext_translations`.
     *
     * @param string[]             $gedmoFqcns Legacy FQCN aliases supplementing $fqcn.
     * @param array<string, string> $fieldsMap  v2 shape: `{ legacyCol => craftHandle }`.
     * @param array<string, mixed>  $mapping    Full mapping.yaml — for D-09 sites lookup.
     */
    private function upsertOneEntry(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string $fqcn,
        array $gedmoFqcns,
        int $legacyId,
        array $legacyRow,
        array $fieldsMap,
        array $mapping,
        MigrationOptions $opts,
        MigrationReport $report,
    ): ?int {
        // Idempotency: look up existing Craft entry via state (site-agnostic).
        // siteId=null on the lookup mirrors the siteId=null on record() below.
        $existingCraftId = $this->migrationState->getTargetId($stateSource, (string) $legacyId, null);

        $entry = null;
        if ($existingCraftId !== null) {
            $entry = Entry::find()->id($existingCraftId)->site('*')->unique()->one();
        }
        if ($entry === null) {
            $entry = new Entry();
            $entry->sectionId = $sectionId;
            $entry->typeId = $typeId;
        }

        // Taxonomy entries are language-neutral (single name column, no
        // per-locale translation in the legacy system). Enable for ALL sites
        // so they appear in EN as well — sections use enabledByDefault=false
        // for per-site editorial control, but migrated taxonomy terms should
        // be globally active. v1 lines 268-272 verbatim.
        $enabledMap = [];
        foreach (Craft::$app->sites->getAllSites() as $site) {
            $enabledMap[$site->id] = true;
        }
        $entry->setEnabledForSite($enabledMap);

        // Apply field map. Title is a native field; other targets go into
        // setFieldValues(). Taxonomy rows are flat — only `plain` resolution
        // is expected (title/slug from flat columns). v2 reshape #4: v1
        // iterated `targetHandle => spec` and read `$spec['source']`; v2
        // iterates `legacyCol => craftHandle` directly (the legacy column
        // is the key, the Craft handle is the value).
        //
        // Note: the legacy-id custom field is intentionally NOT touched —
        // taxonomy entry types do NOT have it attached (v1 docblock lines
        // 41-45). State-table is the only identity record.
        $title = '';
        $fieldValues = [];
        foreach ($fieldsMap as $legacyCol => $craftHandle) {
            $legacyCol = (string) $legacyCol;
            $craftHandle = (string) $craftHandle;
            if ($legacyCol === '' || $craftHandle === '') {
                continue;
            }
            $rawVal = $legacyRow[$legacyCol] ?? null;

            // Inline plain resolution: null → '', scalar → string.
            $resolved = $rawVal === null ? '' : (string) $rawVal;

            if ($craftHandle === 'title') {
                $title = $resolved;
                continue;
            }
            if ($resolved !== '') {
                $fieldValues[$craftHandle] = $resolved;
            }
        }

        if ($title === '') {
            $title = sprintf('[legacy id %d]', $legacyId);
        }
        $entry->title = $title;
        if ($fieldValues !== []) {
            $entry->setFieldValues($fieldValues);
        }

        if ($opts->dryRun) {
            if ($existingCraftId === null) {
                $report->incr('taxonomy.wouldCreate');
            } else {
                $report->incr('taxonomy.wouldUpdate');
            }
            return $existingCraftId;
        }

        // resaving=true short-circuits Entry::_shouldSaveRevision so the
        // canonical save + per-site Gedmo passes don't bloat each taxonomy
        // entry with one revision per saveElement. Migration is a re-save.
        $entry->resaving = true;
        if (!Craft::$app->elements->saveElement($entry, true, true)) {
            throw new RuntimeException(sprintf(
                'saveElement failed for %s id=%d: %s',
                $stateSource,
                $legacyId,
                implode('; ', $entry->getErrorSummary(true)),
            ));
        }

        // State row — site-agnostic (siteId=null), targetType: entry. v1
        // contract verbatim — the state table is the only identity record
        // for taxonomy entries.
        $this->migrationState->record(
            $stateSource,
            (string) $legacyId,
            'entry',
            (int) $entry->id,
            $entry->uid,
            null,
        );

        // Gedmo Translatable per-site pass. After the canonical save, Craft
        // has propagated NL values to all sites — EXCEPT for fields with
        // translationMethod=site (e.g. caseCategory's title). For those, the
        // per-site copy must be done explicitly here. We pass the canonical
        // title + fieldValues so the per-locale logic can fall back to them
        // when a non-primary site has no ext_translations data of its own
        // (Phase 8.7 / issue A — "default-language fallback" rule).
        //
        // D-08 reshape #3 — D-09 empty-`ext_translations` fallback inside.
        $this->applyGedmoTranslations(
            $entry->id,
            $fqcn,
            $gedmoFqcns,
            $legacyId,
            $fieldsMap,
            $mapping,
            $title,
            $fieldValues,
            $report,
        );

        if ($existingCraftId === null) {
            $report->incr('created');
        } else {
            $report->incr('updated');
        }

        return (int) $entry->id;
    }

    /**
     * Reads ext_translations for `$legacyId` and, for each Craft site whose
     * language code has entries, reloads the localized Craft entry and saves
     * the translated field values with `propagateChanges=false`.
     *
     * D-08 reshape #3 — D-09 empty-table fallback: when extTranslationsFor()
     * returns [] (monolingual Kunstmaan install — no Gedmo Translatable rows
     * for this entity at all), copy the source-locale row across every site
     * in `mapping.sites`. Craft's canonical save already propagated the row
     * to every site, so the "copy" here is effectively a per-site re-save
     * with propagateChanges=false to make the contract explicit and to align
     * with the per-site overlay branch's shape. NEW v2 behavior, not in v1.
     *
     * Phase 8.7 / issue A — "default-language fallback" rule: site-translated
     * Craft fields (e.g. `caseCategory`'s title with translationMethod=site)
     * are NOT propagated by the canonical save, so the localized entry's
     * title would otherwise default to empty — or, worse, retain stale data
     * from a previous wrong-mapping run (the `[legacy id N]` symptom). To
     * prevent that, every non-primary-site save in BOTH branches now seeds
     * the localized entry with the canonical title + fieldValues, then lets
     * any per-locale overlay override on a per-field basis. SEO writers
     * (RetourMigrationService, the SEOmatic adapter) intentionally do NOT
     * follow this rule — empty per-locale SEO values are operator-meaningful.
     *
     * @param string[]              $gedmoFqcns
     * @param array<string, string> $fieldsMap            v2 shape: `{ legacyCol => craftHandle }`.
     * @param array<string, mixed>  $mapping              Full mapping.yaml — for sites: block lookup.
     * @param string                $canonicalTitle       Title written to the primary-site entry above.
     * @param array<string, mixed>  $canonicalFieldValues Custom-field values written to the primary-site entry above.
     */
    private function applyGedmoTranslations(
        int $craftEntryId,
        string $fqcn,
        array $gedmoFqcns,
        int $legacyId,
        array $fieldsMap,
        array $mapping,
        string $canonicalTitle,
        array $canonicalFieldValues,
        MigrationReport $report,
    ): void {
        $allFqcns = array_merge([$fqcn], $gedmoFqcns);
        $translations = $this->legacyDb->extTranslationsFor($allFqcns, $legacyId);

        $primarySite = Craft::$app->sites->getPrimarySite();

        if ($translations === []) {
            // D-09 — empty ext_translations: monolingual Kunstmaan install.
            // Re-save each non-primary site in mapping.sites with the
            // canonical title + fieldValues. This is the default-language
            // fallback: when no per-locale data exists at all, EN/etc. mirror
            // NL exactly. propagateChanges=false on each per-site re-save
            // keeps the writes scoped to one site.
            $sites = (array) ($mapping['sites'] ?? []);
            foreach ($sites as $legacyLocale => $siteCfg) {
                $siteHandle = $this->siteHandleFromMappingSite((string) $legacyLocale, $siteCfg);
                if ($siteHandle === '') {
                    continue;
                }
                $site = Craft::$app->sites->getSiteByHandle($siteHandle);
                if ($site === null) {
                    continue;
                }
                if ($site->id === $primarySite->id) {
                    continue;
                }
                $localized = Entry::find()
                    ->id($craftEntryId)
                    ->siteId($site->id)
                    ->one();
                if ($localized === null) {
                    continue;
                }
                $localized->title = $canonicalTitle;
                if ($canonicalFieldValues !== []) {
                    $localized->setFieldValues($canonicalFieldValues);
                }
                // propagateChanges=false: only update this one site.
                $localized->resaving = true;
                if (!Craft::$app->elements->saveElement($localized, true, false)) {
                    throw new RuntimeException($this->localizedTaxonomySaveFailureMessage(
                        $this->fqcnToSlug($fqcn),
                        $legacyId,
                        $siteHandle,
                        (string) $legacyLocale,
                        $localized,
                    ));
                }
                $report->incr('fallback.taxonomy_locale');
                $report->warn(sprintf(
                    'fallback: taxonomy locale values for %s id=%d site=%s locale=%s use default-language values',
                    $this->fqcnToSlug($fqcn),
                    $legacyId,
                    $siteHandle,
                    (string) $legacyLocale,
                ));
            }
            return;
        }

        // Per-locale Gedmo overlay branch (v1 lines 379-432, adjusted for v2
        // fields shape). v2 reshape: $fieldsMap IS the source→target map
        // already (v1 needed a reverse map because its shape was inverted).
        $sourceToTarget = [];
        foreach ($fieldsMap as $legacyCol => $craftHandle) {
            $legacyCol = (string) $legacyCol;
            $craftHandle = (string) $craftHandle;
            if ($legacyCol !== '' && $craftHandle !== '') {
                $sourceToTarget[$legacyCol] = $craftHandle;
            }
        }

        foreach (Craft::$app->sites->getAllSites() as $site) {
            if ($site->id === $primarySite->id) {
                continue; // Primary site already has NL values from canonical save.
            }

            // Normalize Craft language to the base locale Gedmo uses:
            // "en-US" → "en", "nl-NL" → "nl", "en" → "en". v1 line 396 verbatim.
            $locale = strtolower(explode('-', $site->language)[0]);
            $localeData = $translations[$locale] ?? [];

            $localized = Entry::find()
                ->id($craftEntryId)
                ->siteId($site->id)
                ->one();

            if ($localized === null) {
                continue;
            }

            // Phase 8.7 / issue A — default-language fallback: seed the
            // localized entry with canonical values BEFORE applying any
            // per-locale overlay. Fields the legacy ext_translations row has
            // for this locale will override; fields it lacks (or has set to
            // empty content — `$content === ''` continue below) keep the
            // canonical fallback. Fixes the `[legacy id N]` symptom on EN
            // sites for legacy ids with no en-locale ext_translations row.
            $localized->title = $canonicalTitle;
            $translatedFields = $canonicalFieldValues;
            $usedFallback = $localeData === [];

            foreach ($localeData as $sourceField => $content) {
                $targetHandle = $sourceToTarget[$sourceField] ?? null;
                if ($targetHandle === null || $content === '') {
                    if ($targetHandle !== null) {
                        $usedFallback = true;
                    }
                    continue;
                }
                if ($targetHandle === 'title') {
                    $localized->title = $content;
                } else {
                    $translatedFields[$targetHandle] = $content;
                }
            }

            if ($translatedFields !== []) {
                $localized->setFieldValues($translatedFields);
            }
            // propagateChanges=false: only update this one site.
            $localized->resaving = true;
            if (!Craft::$app->elements->saveElement($localized, true, false)) {
                throw new RuntimeException($this->localizedTaxonomySaveFailureMessage(
                    $this->fqcnToSlug($fqcn),
                    $legacyId,
                    (string) $site->handle,
                    $locale,
                    $localized,
                ));
            }
            if ($usedFallback) {
                $report->incr('fallback.taxonomy_locale');
                $report->warn(sprintf(
                    'fallback: taxonomy locale values for %s id=%d site=%s locale=%s use default-language values',
                    $this->fqcnToSlug($fqcn),
                    $legacyId,
                    (string) $site->handle,
                    $locale,
                ));
            }
        }
    }

    private function siteHandleFromMappingSite(string $legacyLocale, mixed $siteCfg): string
    {
        if (is_array($siteCfg)) {
            return (string) ($siteCfg['siteHandle'] ?? $legacyLocale);
        }

        if ($siteCfg === null) {
            return '';
        }

        if (is_scalar($siteCfg) || $siteCfg instanceof \Stringable) {
            return (string) $siteCfg;
        }

        return '';
    }

    private function localizedTaxonomySaveFailureMessage(
        string $taxonomySlug,
        int $legacyId,
        string $siteHandle,
        string $legacyLocale,
        object $element,
    ): string {
        $errorSummary = [];
        if (method_exists($element, 'getErrorSummary')) {
            $summary = $element->getErrorSummary(true);
            $errorSummary = is_array($summary) ? array_map('strval', $summary) : [(string) $summary];
        }
        $errors = $errorSummary === [] ? 'no element errors reported' : implode('; ', $errorSummary);

        return sprintf(
            'localized taxonomy saveElement failed for %s id=%d site=%s locale=%s: %s',
            $taxonomySlug,
            $legacyId,
            $siteHandle,
            $legacyLocale,
            $errors,
        );
    }

    /**
     * `App\Entity\NewsCategory` → `App_Entity_NewsCategory`. Keep in sync
     * with the FQCN-slug convention used by RelationHandler state lookups
     * and by TransformService's `target:` shorthand resolver. v1 lines
     * 439-442 verbatim.
     */
    private function fqcnToSlug(string $fqcn): string
    {
        return str_replace('\\', '_', $fqcn);
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function findTaxonomyMapping(array $mapping, string $taxonomySource): ?array
    {
        foreach ((array) ($mapping['taxonomies'] ?? []) as $fqcn => $row) {
            if (!is_string($fqcn) || !is_array($row)) {
                continue;
            }
            if ($taxonomySource === $fqcn || $taxonomySource === $this->fqcnToSlug($fqcn)) {
                return [$fqcn, $row];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function validatedTaxonomyRow(string $fqcn, array $row): array
    {
        $sectionHandle = (string) ($row['targetSection'] ?? '');
        $entryTypeHandle = (string) ($row['targetEntryType'] ?? '');
        $sourceTable = (string) ($row['sourceTable'] ?? '');
        if ($sectionHandle === '' || $entryTypeHandle === '' || $sourceTable === '') {
            throw new RuntimeException(
                "taxonomies[$fqcn]: missing targetSection/targetEntryType/sourceTable "
                . "(should have been caught by MappingAuditor)",
            );
        }

        // Defense-in-depth: validator already guards this regex, but
        // re-check here so the service is safe to call standalone.
        // SQL injection defense — sourceTable is interpolated raw into SQL.
        if (preg_match('/^[a-z0-9_]+$/', $sourceTable) !== 1) {
            throw new RuntimeException(
                "taxonomies[$fqcn]: sourceTable whitelist failed: $sourceTable",
            );
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLegacyTaxonomyRow(string $fqcn, string $sourceTable, int $legacyId): ?array
    {
        if (preg_match('/^[a-z0-9_]+$/', $sourceTable) !== 1) {
            throw new RuntimeException(
                "taxonomies[$fqcn]: sourceTable whitelist failed: $sourceTable",
            );
        }
        $row = $this->legacyDb->queryOne(
            sprintf('SELECT * FROM %s WHERE id = :id LIMIT 1', $sourceTable),
            [':id' => $legacyId],
        );
        return is_array($row) ? $row : null;
    }
}
