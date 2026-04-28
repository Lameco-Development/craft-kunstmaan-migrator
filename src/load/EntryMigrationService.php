<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\Site;
use RuntimeException;
use yii\base\Component;

/**
 * Shared per-site entry save helper.
 *
 * Implements the verified Craft 5 multi-site save pattern from §1 of
 * 04-RESEARCH.md, Pitfall 2 (propagate=false + re-load before every
 * non-primary-site save), and Pitfall 3 (matrix block UID persistence
 * across re-runs).
 *
 * Generic surface (CORE-06):
 *
 *   `public array $sites` is a legacy-locale → Craft-site-handle map,
 *   populated via Plugin.php fallback-config dict (Plan 07) or the
 *   config file (Plan 09). CQM ships `['nl' => 'default', 'en' => 'en']`;
 *   enreach's design stress test ships a 7-locale map. The loop iterates
 *   `array_values($this->sites)` (handles only) preserving first-write =
 *   canonical save. Callers supply per-site data keyed by the same handles.
 *
 * API:
 *   public function saveEntryForSites(
 *       int $sectionId,
 *       int $typeId,
 *       string $stateSource,
 *       string|int $stateKey,
 *       array $perSite,
 *   ): \craft\elements\Entry
 *
 * $perSite is keyed by Craft site handle (the values from `$sites`).
 * Each value:
 *   [
 *       'enabled'     => bool,
 *       'title'       => string,
 *       'slug'        => string,
 *       'fieldValues' => array,
 *       'parentId'    => ?int,
 *       'postDate'    => ?\DateTimeInterface,
 *   ]
 *
 * Returns the primary-site (first `array_values($sites)`) Entry instance
 * (for follow-on SEO/redirect writes).
 *
 * Security note (T-04-05-03): This service intentionally passes
 * propagateChanges=false to every saveElement() call and always re-loads the
 * entry scoped to the target siteId before saving non-primary site content
 * (Pitfall 2 avoidance). Any code that calls saveElement() directly from a
 * migration service bypasses this safety — the grep-check in the acceptance
 * criteria enforces there are no direct saveElement calls outside this class.
 */
class EntryMigrationService extends Component
{
    /**
     * kuma_locale (string) → Craft site handle (string).
     * Populated by Plugin::init() (Plan 03-14) from LocalePreflight::detect() + Settings::$localeMap.
     * Empty default — saveEntryForSites() throws if accessed while empty.
     *
     * Example for CQM: `['nl' => 'default', 'en' => 'en']`
     * Example for enreach: 7-locale map (design stress test).
     *
     * Handle order determines primary-first save ordering — first handle is
     * canonical, others reload-before-save.
     *
     * @var array<string, string>
     */
    public array $sites = [];

    /**
     * MigrationStateService — sibling-DI wired in Plugin::init() (Plan 03-14).
     */
    public ?MigrationStateService $stateService = null;

    /** @var array<string, Site> cached Site instances by handle */
    private array $siteCache = [];

    // --------------------------------------------------------------------------
    // Public API
    // --------------------------------------------------------------------------

    /**
     * Save or update an Entry across every site in `$this->sites` in one call.
     *
     * @param array<string, array{enabled: bool, title: string, slug: string, fieldValues: array, parentId: ?int}> $perSite
     * @throws RuntimeException on unknown site handle or primary-site save failure
     */
    public function saveEntryForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        bool $force = false,
        ?MigrationReport $report = null,
    ): Entry {
        // Resolve the configured site handles (values of $this->sites).
        $configuredHandles = array_values($this->sites);
        if ($configuredHandles === []) {
            throw new RuntimeException(
                'EntryMigrationService: $sites is empty — populate via Plugin setComponents '
                . 'with a locale→siteHandle map (e.g., [\'nl\' => \'default\']).',
            );
        }

        // Validate every handle in $perSite is present in the configured set.
        foreach (array_keys($perSite) as $handle) {
            if (!in_array($handle, $configuredHandles, true)) {
                throw new RuntimeException(
                    sprintf(
                        'EntryMigrationService: unknown site handle "%s". Configured: %s',
                        $handle,
                        implode(', ', $configuredHandles),
                    ),
                );
            }
        }

        // Resolve every configured handle to a Craft Site — skip any that don't
        // exist on this install. (Plan 09 config validator will throw earlier.)
        /** @var list<Site> $sites */
        $sites = [];
        foreach ($configuredHandles as $handle) {
            $site = $this->getSiteByHandle($handle);
            if ($site !== null) {
                $sites[] = $site;
            }
        }
        if ($sites === []) {
            throw new RuntimeException(
                'EntryMigrationService: none of the configured site handles resolve to '
                . 'a Craft site. Configured: ' . implode(', ', $configuredHandles),
            );
        }

        // Phase 8.7 — pick Craft's actual primary site from the configured set,
        // not array-position 0. Previously $sites[0] depended on the operator's
        // ordering of mapping.yaml's `sites:` block — when that block ordered
        // `en: en` before `nl: default` (e.g. after an analyze run rewrote it),
        // the loader treated EN as primary and called applyPerSiteData with
        // an empty payload (the transform only populates perSite[<primary>] for
        // online translations). Result: every page failed with "Title cannot be
        // blank." Selecting by Craft's `primary` flag is order-agnostic.
        $primarySite = null;
        foreach ($sites as $s) {
            if ($s->primary) {
                $primarySite = $s;
                break;
            }
        }
        if ($primarySite === null) {
            // None of the configured handles resolved to Craft's primary site —
            // fall back to first-resolved (legacy behavior). Operator setup is
            // unusual; leave it loud-but-running rather than throwing.
            $primarySite = $sites[0];
        }

        // ------------------------------------------------------------------ 1
        // Look up existing entry via state table
        // ------------------------------------------------------------------ 1
        $existingId = $this->stateService->getTargetId($stateSource, (string) $stateKey);
        $entry = null;

        if ($existingId !== null) {
            $entry = Entry::find()
                ->id($existingId)
                ->siteId($primarySite->id)
                ->status(null)
                ->one();

            // Idempotent re-run: if the entry already exists and the caller
            // didn't pass force=true, short-circuit. Saves the per-site
            // setFieldValues + saveElement + revision-creation cost, which
            // dominates the Load: Entries stage on re-runs. Callers pass
            // force=true (typically threaded from MigrationOptions->force)
            // to refresh field values from a newer extract/transform output.
            if ($entry !== null && !$force) {
                return $entry;
            }
        }

        // ------------------------------------------------------------------ 2
        // Construct fresh entry when not found
        // ------------------------------------------------------------------ 2
        if ($entry === null) {
            // Single-section sections: Craft auto-creates exactly one entry when
            // the section is applied via project-config; its URI is the magic
            // '__home__' value. Creating a second entry fails validation with
            // "Could not generate a unique URI based on the URI format." Reuse
            // the existing auto-created entry (subsequent runs re-use via the
            // state-table lookup above). Single-section migrations land here on
            // first run because no state row has been recorded yet.
            $section = Craft::$app->getEntries()->getSectionById($sectionId);
            if ($section !== null && $section->type === Section::TYPE_SINGLE) {
                $existingSingle = Entry::find()
                    ->sectionId($sectionId)
                    ->siteId($primarySite->id)
                    ->status(null)
                    ->one();
                if ($existingSingle !== null) {
                    $entry = $existingSingle;
                }
            }

            if ($entry === null) {
                $entry = new Entry();
                $entry->sectionId = $sectionId;
                $entry->typeId = $typeId;
                $entry->siteId = $primarySite->id;
            }
        }

        // ------------------------------------------------------------------ 3
        // Pre-seed per-site enablement map BEFORE the first save (DEC-06)
        // ------------------------------------------------------------------ 3
        $enabledMap = [];
        foreach ($sites as $site) {
            // Transform emits `online` (from kuma_node_translations.online),
            // but earlier mapping docs referred to `enabled`. Read both so
            // the loader tolerates either key without a breaking change.
            $siteData = $perSite[$site->handle] ?? [];
            $enabledMap[$site->id] = (bool) ($siteData['enabled'] ?? $siteData['online'] ?? false);
        }
        $entry->setEnabledForSite($enabledMap);

        // ------------------------------------------------------------------ 4
        // Read existing block UIDs and thread them into pageBuilder payload
        // (Pitfall 3 avoidance — keeps matrix rows stable across re-runs)
        // ------------------------------------------------------------------ 4
        $existingMeta = $existingId !== null
            ? ($this->stateService->get($stateSource, (string) $stateKey)['meta'] ?? null)
            : null;

        $blockUidMap = [];
        if (!empty($existingMeta)) {
            if (is_string($existingMeta)) {
                $existingMeta = json_decode($existingMeta, true);
            }
            $blockUidMap = (array) ($existingMeta['blockIds'] ?? $existingMeta['blockUids'] ?? []);
        }

        // Inject the kunstmaanSourceId custom-field value on each per-site
        // payload BEFORE applyPerSiteData runs. Every migrated entry carries
        // its legacy origin id as "<stateSource>:<stateKey>" — same format as the state-table
        // row key, so the field joinably maps to {{%kunstmaanmigrator_state}}.
        //
        // This is set programmatically (not declared in mapping.yaml under
        // fields:) so it can't drift; mapping-audit explicitly excludes it.
        $kunstmaanSourceId = $stateSource . ':' . (string) $stateKey;
        foreach ($perSite as $handle => $siteData) {
            if (is_array($siteData)) {
                $perSite[$handle]['fieldValues']['kunstmaanSourceId']
                    = $siteData['fieldValues']['kunstmaanSourceId'] ?? $kunstmaanSourceId;
            }
        }

        // ------------------------------------------------------------------ 5
        // First save — PRIMARY site (propagate=false, per Pitfall 2)
        // ------------------------------------------------------------------ 5
        $primaryData = $this->primarySiteDataForSave(
            $perSite,
            $primarySite->handle,
            $report,
            $stateSource,
            (string) $stateKey,
        );
        // Extract source-ref positions BEFORE applyPerSiteData strips them.
        $primarySourceRefPositions = $this->extractSourceRefPositions(
            (array) ($primaryData['fieldValues'] ?? []),
        );
        $this->applyPerSiteData(
            $entry,
            $primaryData,
            $blockUidMap,
            $report,
            $stateSource,
            (string) $stateKey,
            $primarySite->handle,
        );

        // Critical: propagateChanges=false (Pitfall 2)
        if (!Craft::$app->elements->saveElement($entry, true, false)) {
            throw new RuntimeException(
                sprintf(
                    'Primary-site save failed for %s:%s — %s',
                    $stateSource,
                    $stateKey,
                    json_encode($entry->getErrors()),
                ),
            );
        }

        // Collect primary-site block UIDs immediately after save.
        $blockUidMap = array_merge(
            $blockUidMap,
            $this->collectBlockUidsByPosition($entry, $primarySourceRefPositions),
        );

        // ------------------------------------------------------------------ 6
        // Record / update state row
        // ------------------------------------------------------------------ 6
        $this->stateService->record(
            source: $stateSource,
            key: (string) $stateKey,
            targetType: 'entry',
            targetId: $entry->id,
            targetUid: $entry->uid,
        );

        // ------------------------------------------------------------------ 7
        // Subsequent saves — every non-primary site. Reload-before-save per
        // Pitfall 2. Each site save stays independent; a per-site failure is
        // a warning, not fatal — don't abort the whole migration.
        // ------------------------------------------------------------------ 7
        foreach ($sites as $site) {
            if ($site->id === $primarySite->id) {
                continue;
            }
            if (!isset($perSite[$site->handle])) {
                continue;
            }
            // Extract source-ref positions BEFORE applyPerSiteData strips them.
            $siteSourceRefPositions = $this->extractSourceRefPositions(
                (array) ($perSite[$site->handle]['fieldValues'] ?? []),
            );

            // Critical: re-load scoped to siteId (Pitfall 2)
            /** @var Entry|null $localised */
            $localised = Entry::find()
                ->id($entry->id)
                ->siteId($site->id)
                ->status(null)
                ->one();

            if ($localised === null) {
                continue;
            }

            $this->applyPerSiteData(
                $localised,
                $perSite[$site->handle],
                $blockUidMap,
                $report,
                $stateSource,
                (string) $stateKey,
                $site->handle,
            );

            // Critical: propagateChanges=false (Pitfall 2)
            if (!Craft::$app->elements->saveElement($localised, true, false)) {
                Craft::warning(
                    sprintf(
                        'EntryMigrationService: site "%s" save failed for %s:%s — %s',
                        $site->handle,
                        $stateSource,
                        $stateKey,
                        json_encode($localised->getErrors()),
                    ),
                    __METHOD__,
                );
                continue;
            }

            // Merge this site's block UIDs into the map so subsequent sites
            // (and next run's state lookup) can thread them correctly.
            $blockUidMap = array_merge(
                $blockUidMap,
                $this->collectBlockUidsByPosition($localised, $siteSourceRefPositions),
            );
        }

        // ------------------------------------------------------------------ 8
        // Persist the accumulated block UID map to state so the NEXT re-run
        // can thread all UIDs back in and update blocks in place (Pitfall 3).
        // ------------------------------------------------------------------ 8
        if (!empty($blockUidMap)) {
            $this->stateService->updateMeta($stateSource, (string) $stateKey, null, ['blockIds' => $blockUidMap]);
        }

        // ------------------------------------------------------------------ 9
        // Return the primary-site Entry instance
        // ------------------------------------------------------------------ 9
        return $entry;
    }

    /**
     * Save a promoted/shared relation target through the same idempotent
     * stateSource/stateKey state-row path as owner entries.
     *
     * @param array<string, array<string, mixed>> $perSite
     */
    public function savePromotedTargetForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        bool $force = false,
        ?MigrationReport $report = null,
    ): Entry {
        return $this->saveEntryForSites($sectionId, $typeId, $stateSource, $stateKey, $perSite, $force, $report);
    }

    // --------------------------------------------------------------------------
    // Private helpers
    // --------------------------------------------------------------------------

    /**
     * Apply per-site title/slug/parent/postDate/fieldValues onto an Entry.
     * Centralises the field-write logic for primary-site first-save AND for
     * each reload-before-save of subsequent sites.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $blockUidMap
     */
    private function applyPerSiteData(
        Entry $entry,
        array $data,
        array $blockUidMap,
        ?MigrationReport $report = null,
        ?string $stateSource = null,
        ?string $stateKey = null,
        ?string $siteHandle = null,
    ): void {
        $entry->title = (string) ($data['title'] ?? '');
        // Only overwrite slug when the Transform emitted a non-empty value.
        // Singleton sections (HomePage, ErrorPage, overview pages) have a
        // meaningful pre-existing slug that Kunstmaan doesn't expose; blanking
        // it on --overwrite re-runs breaks URI generation (uriFormat={slug}
        // collapses to empty, collides with root URI, save fails validation).
        if (!empty($data['slug'])) {
            $entry->slug = (string) $data['slug'];
        }

        if (!empty($data['parentId'])) {
            $entry->setParentId((int) $data['parentId']);
        }

        $fieldValues = (array) ($data['fieldValues'] ?? []);
        // Thread existing block UIDs into every matrix field payload so re-runs
        // update existing blocks in place instead of duplicating them.
        foreach ($fieldValues as $handle => $payload) {
            if (is_array($payload) && $payload !== [] && $this->looksLikeMatrixPayload($payload)) {
                $fieldValues[$handle] = $this->threadBlockUidsIntoPageBuilder(
                    $payload,
                    $blockUidMap,
                );
            }
        }
        $fieldValues = $this->stripSourcePartRefs(
            $fieldValues,
            $report,
            [
                'stateSource' => $stateSource,
                'stateKey' => $stateKey,
                'site' => $siteHandle,
            ],
        );

        // Native Entry properties are NOT custom fields — CustomFieldBehavior
        // rejects them. mapping.yaml can list `postDate`/`expiryDate`/
        // `authorId`/etc. under `fields:` as a documentation convention; the
        // plain/asset/relation handler produces a scalar value which we now
        // promote from fieldValues onto the native Entry property.
        //
        // Order of precedence:
        //   1. $data['postDate'] (already-typed DateTimeInterface from caller)
        //   2. $fieldValues['postDate'] (string from plain handler — parsed)
        // Same pattern for expiryDate.
        // Craft's Entry::$postDate / $expiryDate are typed ?DateTime (mutable),
        // NOT ?DateTimeInterface — so PHP's strict typing rejects
        // DateTimeImmutable. Always return a DateTime instance, coercing from
        // DateTimeImmutable when needed.
        $nativeDate = static function (mixed $raw): ?\DateTime {
            if ($raw === null || $raw === '') {
                return null;
            }
            if ($raw instanceof \DateTime) {
                return $raw;
            }
            if ($raw instanceof \DateTimeInterface) {
                return \DateTime::createFromInterface($raw);
            }
            try {
                return new \DateTime((string) $raw);
            } catch (\Throwable) {
                return null;
            }
        };

        $postDate = $nativeDate($data['postDate'] ?? $fieldValues['postDate'] ?? null);
        if ($postDate !== null) {
            $entry->postDate = $postDate;
        }
        $expiryDate = $nativeDate($data['expiryDate'] ?? $fieldValues['expiryDate'] ?? null);
        if ($expiryDate !== null) {
            $entry->expiryDate = $expiryDate;
        }

        // authorId — only set when mapping.yaml supplies a concrete Craft user
        // id (handler path or literal). Most migrations leave this to Craft's
        // default (admin / first user) which is fine.
        $authorId = (int) ($data['authorId'] ?? $fieldValues['authorId'] ?? 0);
        if ($authorId > 0) {
            $entry->setAuthorIds([$authorId]);
        }

        // Filter out native Entry properties that may appear in fieldValues
        // (now promoted to native props above, or explicitly dropped per the
        // self-documentation convention in mapping.yaml).
        $fieldValues = array_diff_key(
            $fieldValues,
            array_flip(['title', 'slug', 'postDate', 'expiryDate', 'enabled', 'parentId', 'authorId']),
        );
        // Filter custom-field keys against the entry's actual field layout.
        // Unknown handles (mapping.yaml drift, stale schema, Kunstmaan-only
        // columns with no Craft equivalent) silently drop rather than throwing
        // 'unknown property' from CustomFieldBehavior. The migration report
        // surfaces the dropped handles once per unique (entryType, handle) pair.
        $layout = $entry->getFieldLayout();
        if ($layout !== null) {
            $validHandles = [];
            foreach ($layout->getCustomFields() as $field) {
                $validHandles[$field->handle] = true;
            }
            $dropped = array_diff_key($fieldValues, $validHandles);
            $fieldValues = array_intersect_key($fieldValues, $validHandles);
            foreach (array_keys($dropped) as $handle) {
                Craft::warning(
                    sprintf(
                        'Dropped unknown field "%s" on entry type "%s" — not in the entry type\'s field layout. Clean up mapping.yaml.',
                        $handle,
                        $entry->getType()->handle,
                    ),
                    __METHOD__,
                );
            }
        }
        $entry->setFieldValues($fieldValues);
    }

    /**
     * Craft requires the primary site to save first. Sparse source payloads may
     * legitimately omit the Craft primary site while still carrying valid native
     * values on another source locale. For that first save only, borrow the best
     * available native payload without mutating the source-keyed perSite map.
     *
     * @param array<string, array<string, mixed>> $perSite
     * @return array<string, mixed>
     */
    private function primarySiteDataForSave(
        array $perSite,
        string $primaryHandle,
        ?MigrationReport $report,
        string $stateSource,
        string $stateKey,
    ): array {
        $primaryData = (array) ($perSite[$primaryHandle] ?? []);
        if (!$this->primaryNativeValuesNeedFallback($primaryData)) {
            return $primaryData;
        }

        $fallbackHandle = null;
        $fallbackData = null;
        foreach ($perSite as $handle => $candidate) {
            if ($handle === $primaryHandle || !is_array($candidate)) {
                continue;
            }
            if (!$this->payloadHasUsableNativeValues($candidate)) {
                continue;
            }
            $fallbackHandle = (string) $handle;
            $fallbackData = $candidate;
            break;
        }

        if ($fallbackData === null) {
            return $primaryData;
        }

        $borrowed = [];
        if ($primaryData === []) {
            $primaryData = $fallbackData;
            $borrowed = ['payload'];
        } else {
            foreach (['title', 'slug'] as $nativeKey) {
                if (!$this->hasNonEmptyString($primaryData[$nativeKey] ?? null)
                    && $this->hasNonEmptyString($fallbackData[$nativeKey] ?? null)
                ) {
                    $primaryData[$nativeKey] = $fallbackData[$nativeKey];
                    $borrowed[] = $nativeKey;
                }
            }
        }

        if ($borrowed !== []) {
            $this->recordFallback(
                $report,
                'sparse_locale_primary',
                sprintf(
                    'Sparse-locale primary-save fallback: source=%s:%s primarySite=%s fallbackSite=%s borrowed=%s',
                    $stateSource,
                    $stateKey,
                    $primaryHandle,
                    (string) $fallbackHandle,
                    implode(',', $borrowed),
                ),
            );
        }

        return $primaryData;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function primaryNativeValuesNeedFallback(array $data): bool
    {
        return !$this->hasNonEmptyString($data['title'] ?? null)
            || !$this->hasNonEmptyString($data['slug'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function payloadHasUsableNativeValues(array $data): bool
    {
        return $this->hasNonEmptyString($data['title'] ?? null)
            || $this->hasNonEmptyString($data['slug'] ?? null);
    }

    /**
     * Normalize every matrix-block payload across the whole fieldValues hash.
     *
     * Two Phase 04 bugs we clean up here, centrally:
     *
     * 1. Strip `_sourcePartRef` from every block's `fields` hash.
     *    `_sourcePartRef` was designed as a hidden re-run-tracking field on
     *    each block, but the project config never added it to the 50 matrix
     *    block entry types — so Craft's CustomFieldBehavior rejects it as
     *    an unknown property. Stripping it loses idempotent UID threading
     *    on re-runs, but MIGRATION-README already mandates
     *    `migrate/truncate --confirm` before any re-run, so this is OK.
     *
     * 2. Lift `title` (and `heading`, which was a typo in CasesMigration's
     *    newsGridBlock payload) from `fields` to peer-level. Matrix block
     *    entry types with `hasTitleField: true` expect `title` as a native
     *    entry property — it must be a peer of `type`/`enabled`/`fields`,
     *    not nested inside `fields`. `heading` on newsGridBlock is not a
     *    real custom field either; it was intended for the native title.
     *
     * @param array<string, mixed> $fieldValues
     * @return array<string, mixed>
     */
    private function stripSourcePartRefs(
        array $fieldValues,
        ?MigrationReport $report = null,
        array $context = [],
    ): array {
        foreach ($fieldValues as $handle => $value) {
            if (!is_array($value)) {
                continue;
            }
            $position = 0;
            foreach ($value as $blockKey => $block) {
                if (!is_array($block) || !isset($block['fields']) || !is_array($block['fields'])) {
                    ++$position;
                    continue;
                }
                $sourceRef = isset($block['fields']['_sourcePartRef'])
                    ? (string) $block['fields']['_sourcePartRef']
                    : null;
                // Strip the hidden ref tag.
                unset($block['fields']['_sourcePartRef']);

                // Lift native-property keys from fields → peer.
                // Prefer existing peer-level value if the caller already set one.
                foreach (['title', 'heading'] as $nativeKey) {
                    if (array_key_exists($nativeKey, $block['fields'])) {
                        $lifted = $block['fields'][$nativeKey];
                        unset($block['fields'][$nativeKey]);
                        $peerKey = $nativeKey === 'heading' ? 'title' : $nativeKey;
                        if (!array_key_exists($peerKey, $block)) {
                            $block[$peerKey] = $lifted;
                        }
                    }
                }

                if (!$this->hasNonEmptyString($block['title'] ?? null)) {
                    $block['title'] = $this->synthesiseMatrixBlockTitle($block, $position, $sourceRef);
                    $this->recordFallback(
                        $report,
                        'matrix_native_title',
                        sprintf(
                            'Matrix native-title fallback: source=%s:%s site=%s field=%s blockType=%s position=%d%s title="%s"',
                            (string) ($context['stateSource'] ?? '?'),
                            (string) ($context['stateKey'] ?? '?'),
                            (string) ($context['site'] ?? '?'),
                            $handle,
                            (string) ($block['type'] ?? 'unknown'),
                            $position + 1,
                            $sourceRef !== null ? ' sourceRef=' . $sourceRef : '',
                            (string) $block['title'],
                        ),
                    );
                }

                $value[$blockKey] = $block;
                ++$position;
            }
            $fieldValues[$handle] = $value;
        }
        return $fieldValues;
    }

    /**
     * Public accessor so services that bypass saveEntryForSites() (e.g.
     * SingletonMigrationService which does its own setFieldValues + saveElement)
     * can reuse the same normalization without duplication.
     *
     * @param array<string, mixed> $fieldValues
     * @return array<string, mixed>
     */
    public function normalizeMatrixPayload(array $fieldValues): array
    {
        return $this->stripSourcePartRefs($fieldValues);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function synthesiseMatrixBlockTitle(array $block, int $position, ?string $sourceRef): string
    {
        $type = trim((string) ($block['type'] ?? 'matrixBlock'));
        $type = $type !== '' ? $type : 'matrixBlock';
        $base = sprintf('Migrated %s block %d', $type, $position + 1);

        return $sourceRef !== null && trim($sourceRef) !== ''
            ? sprintf('%s (%s)', $base, trim($sourceRef))
            : $base;
    }

    private function hasNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function recordFallback(?MigrationReport $report, string $category, string $message): void
    {
        if ($report !== null) {
            $report->warn($message);
            $report->incr('fallback.' . $category);
        }
        Craft::warning('EntryMigrationService: ' . $message, __METHOD__);
    }

    /**
     * Rewrite pageBuilder payload outer keys from "new{n}" to the matching
     * existing block UID when the block's `fields._sourcePartRef` value is
     * found in $uidMap.
     *
     * Shape of $uidMap: ['TextPagePart:123' => 'uid-abc', ...]
     *
     * @param array<string, array> $payload
     * @param array<string, string> $uidMap
     * @return array<string, array>
     */
    private function threadBlockUidsIntoPageBuilder(array $payload, array $uidMap): array
    {
        if (empty($uidMap)) {
            return $payload;
        }

        $rewritten = [];
        foreach ($payload as $key => $block) {
            $sourceRef = $block['fields']['_sourcePartRef'] ?? null;
            if ($sourceRef !== null && isset($uidMap[$sourceRef])) {
                // Use the persisted UID as the key so Craft updates in place
                $rewritten[$uidMap[$sourceRef]] = $block;
            } else {
                $rewritten[$key] = $block;
            }
        }
        return $rewritten;
    }

    /**
     * Returns true if $payload looks like a Craft matrix block payload:
     * an array where every value is itself an array containing at least a `type` key.
     *
     * @param array<mixed> $payload
     */
    private function looksLikeMatrixPayload(array $payload): bool
    {
        $first = reset($payload);
        return is_array($first) && isset($first['type']);
    }

    /**
     * Extract `_sourcePartRef` values from a fieldValues payload by position,
     * keyed by field handle. Must be called BEFORE stripSourcePartRefs() removes
     * the refs. Returns `[fieldHandle => [0 => 'ref0', 1 => 'ref1', ...]]`.
     *
     * @param array<string, mixed> $fieldValues
     * @return array<string, list<string|null>>
     */
    private function extractSourceRefPositions(array $fieldValues): array
    {
        $positions = [];
        foreach ($fieldValues as $handle => $value) {
            if (!is_array($value) || !$this->looksLikeMatrixPayload($value)) {
                continue;
            }
            $refs = [];
            foreach (array_values($value) as $block) {
                $refs[] = is_array($block) ? ($block['fields']['_sourcePartRef'] ?? null) : null;
            }
            $positions[$handle] = $refs;
        }
        return $positions;
    }

    /**
     * Match just-saved matrix blocks by their position index against the
     * source-ref positions map built before saving. Returns `[sourceRef => elementId]`.
     *
     * Craft 5's Matrix._createEntriesFromSerializedData() indexes existing entries
     * by element ID (integer) in the flat-array format we use. UIDs as keys are
     * only supported in the `uid:` prefixed / sortOrder format. We therefore store
     * element IDs so the next re-run can pass them as keys and Craft updates the
     * existing nested entry in place instead of creating a new one.
     *
     * Ordering contract: `threadBlockUidsIntoPageBuilder` rekeys blocks but
     * preserves order; `stripSourcePartRefs` only removes a field key. Craft
     * persists blocks in the order supplied to `setFieldValues`, so position 0
     * in the payload === index 0 in `->all()` after save.
     *
     * @param array<string, list<string|null>> $sourceRefPositions
     * @return array<string, string> sourceRef → elementId (string-cast integer)
     */
    private function collectBlockUidsByPosition(Entry $entry, array $sourceRefPositions): array
    {
        $map = [];
        foreach ($sourceRefPositions as $fieldHandle => $sourceRefs) {
            try {
                $blocks = $entry->getFieldValue($fieldHandle);
                if (!$blocks || !method_exists($blocks, 'all')) {
                    continue;
                }
                foreach (array_values($blocks->all()) as $idx => $block) {
                    $sourceRef = $sourceRefs[$idx] ?? null;
                    if ($sourceRef !== null && $block->id) {
                        $map[$sourceRef] = (string) $block->id;
                    }
                }
            } catch (\Throwable) {
                // Field may not be a matrix — skip
            }
        }
        return $map;
    }

    /**
     * Resolve a Craft site by handle with per-instance caching. Returns null
     * when the handle doesn't exist on this install (caller decides whether
     * that's fatal).
     */
    private function getSiteByHandle(string $handle): ?Site
    {
        if (isset($this->siteCache[$handle])) {
            return $this->siteCache[$handle];
        }
        $site = Craft::$app->sites->getSiteByHandle($handle);
        if ($site !== null) {
            $this->siteCache[$handle] = $site;
        }
        return $site;
    }
}
