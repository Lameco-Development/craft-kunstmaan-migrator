<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use craft\base\Element;
use craft\fields\Matrix;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\Site;
use lameco\kunstmaanmigrator\craft\CraftElementWriter;
use lameco\kunstmaanmigrator\craft\ElementWriter;
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
 *   config file (Plan 09). A small bilingual site might use
 *   `['nl' => 'default', 'en' => 'en']`; a larger multilingual site can use
 *   any number of locales. The loop iterates
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
     * Populated by Plugin::init() (Plan 03-14) via Plugin::resolveSitesMap(),
     * which reads only the operator-curated Settings::$localeMap (v2 loader
     * prune — locale auto-detection was analyze-stage machinery, removed).
     * Empty default — saveEntryForSites() throws if accessed while empty.
     *
     * Example: `['nl' => 'default', 'en' => 'en']`
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

    /**
     * The seam at Craft's element writes.
     *
     * Wired in Plugin::init() for production; left settable so a test can pass
     * an in-memory adapter and assert on what this module asked Craft to do.
     * Read through elements() rather than directly, so no call site has to
     * cope with "not wired yet" — the nullable property is a seam, not a
     * precondition.
     */
    public ?ElementWriter $elementWriter = null;

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
    private function elements(): ElementWriter
    {
        return $this->elementWriter ??= new CraftElementWriter();
    }

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
            // A site the payload says nothing about is LEFT OUT of the map, not set to false.
            //
            // The section is `propagationMethod: custom`, and Craft decides propagation with
            // `getEnabledForSite($siteId) !== null` (craft\elements\Entry::getSupportedSites).
            // `false` is not null — so assigning false to every configured site, as this loop
            // used to, propagated every entry into every locale of the environment. That one
            // line produced three separate defects: a control panel full of disabled entries in
            // locales the page never existed in, nested entries duplicated onto sites no payload
            // ever wrote (28,108 blocks from 6,285 compiled), and slug collisions where a
            // propagated primary slug met the real entry for that locale (`labels/botsquad-2`).
            //
            // Omitting the site leaves `getEnabledForSite()` null and Craft does not propagate.
            // Deliberate disabled rows are unaffected: a structural placeholder and an offline
            // translation both *name* their sites in the payload, which is what lets them own a
            // path segment without publishing anything.
            if (!isset($perSite[$site->handle])) {
                continue;
            }

            // Transform emits `online` (from kuma_node_translations.online),
            // but earlier mapping docs referred to `enabled`. Read both so
            // the loader tolerates either key without a breaking change.
            $siteData = $perSite[$site->handle];
            $enabledMap[$site->id] = (bool) ($siteData['enabled'] ?? $siteData['online'] ?? false);
        }

        // The entry is always created *in* the primary site (`$entry->siteId` above), so a row
        // there is unavoidable even when the payload never mentions it. It must be explicitly
        // disabled: `primarySiteDataForSave()` borrows another locale's title, slug and
        // `enabled` to fill the gap, so leaving this unset publishes a French page at an English
        // URL. Measured: 8 of 29 entries in a slice went live in EN carrying FR/DK/NL content.
        //
        // One disabled row per entry, on the primary site only — not one per configured locale.
        if (!isset($perSite[$primarySite->handle])) {
            $enabledMap[$primarySite->id] = false;
        }

        $entry->setEnabledForSite($enabledMap);

        // ------------------------------------------------------------------ 4
        // Read existing block UIDs and thread them into pageBuilder payload
        // (Pitfall 3 avoidance — keeps matrix rows stable across re-runs)
        // ------------------------------------------------------------------ 4
        $existingMeta = $existingId !== null
            ? ($this->stateService->get($stateSource, (string) $stateKey)['meta'] ?? null)
            : null;

        // Per-site block-UID map. Shape:
        //   ['<siteHandle>' => ['<sourceRef>' => '<blockId>', ...], ...]
        // Matrix fields with `propagationMethod: none` get separate block
        // elements per site, so a flat sourceRef→blockId map (the v1 shape)
        // collapses across sites and only the last-written site's block ids
        // survive — breaking the deferred-entry-relation fix-up pass for
        // every other site. Phase 12 / Gap [C]-followup: nested per-site.
        $blockUidMap = [];
        if (!empty($existingMeta)) {
            if (is_string($existingMeta)) {
                $existingMeta = json_decode($existingMeta, true);
            }
            $rawBlockIds = (array) ($existingMeta['blockIds'] ?? $existingMeta['blockUids'] ?? []);
            // Back-compat: detect the flat v1 shape (scalar values) and
            // promote it to a per-primary-site submap so re-runs against
            // pre-Phase-12 state rows still thread block UIDs correctly
            // for the primary site at minimum.
            if ($rawBlockIds !== []) {
                $first = reset($rawBlockIds);
                if (is_array($first)) {
                    $blockUidMap = $rawBlockIds;
                } else {
                    $blockUidMap = [$primarySite->handle => $rawBlockIds];
                }
            }
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
            (array) ($blockUidMap[$primarySite->handle] ?? []),
            $report,
            $stateSource,
            (string) $stateKey,
            $primarySite->handle,
        );

        // Critical: propagateChanges=false (Pitfall 2)
        // resaving=true short-circuits Entry::_shouldSaveRevision so the
        // primary + per-site save cycle doesn't bloat each entry with one
        // revision per call (and one snapshot of every propagationMethod=none
        // matrix-block set per revision). Migration is a re-save, not an edit.
        $entry->resaving = true;
        if (!$this->elements()->save($entry)) {
            throw new RuntimeException(
                sprintf(
                    'Primary-site save failed for %s:%s — %s',
                    $stateSource,
                    $stateKey,
                    json_encode($entry->getErrors()),
                ),
            );
        }

        // Collect primary-site block UIDs immediately after save. Per-site
        // bucket so secondary sites (with propagationMethod=none) can keep
        // their distinct block ids without overwriting.
        $blockUidMap[$primarySite->handle] = array_merge(
            (array) ($blockUidMap[$primarySite->handle] ?? []),
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

            // Phase 12 / propagationMethod=none cleanup: when the primary
            // save enables this entry on multiple sites, Craft auto-creates
            // ghost matrix-block mirrors on the non-primary sites with NULL
            // content. The secondary save then ADDS new blocks alongside
            // the ghosts (sortOrders 1-N for ghosts + 1-M for real EN data),
            // bloating the rendered matrix with empty rows. Hard-delete any
            // block on this site that isn't in our tracked blockUidMap for
            // this site BEFORE applyPerSiteData runs setFieldValues — the
            // tracked set carries real blocks from previous runs (re-runs)
            // or is empty (first runs), so the wipe targets only ghosts.
            $this->wipeStaleSecondarySiteBlocks(
                $localised,
                (array) ($perSite[$site->handle]['fieldValues'] ?? []),
                (array) ($blockUidMap[$site->handle] ?? []),
            );

            $this->applyPerSiteData(
                $localised,
                $perSite[$site->handle],
                (array) ($blockUidMap[$site->handle] ?? []),
                $report,
                $stateSource,
                (string) $stateKey,
                $site->handle,
            );

            // Critical: propagateChanges=false (Pitfall 2)
            $localised->resaving = true;
            if (!$this->elements()->save($localised)) {
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

            // Merge this site's block UIDs into its own bucket so the
            // post-load fix-up pass (and next run's threading) can find
            // each site's block ids independently.
            $blockUidMap[$site->handle] = array_merge(
                (array) ($blockUidMap[$site->handle] ?? []),
                $this->collectBlockUidsByPosition($localised, $siteSourceRefPositions),
            );
        }

        // ------------------------------------------------------------------ 7b
        // Blocks on sites this payload never addressed.
        //
        // `commonPageBuilder` is propagationMethod: none, so every site owns its own nested
        // entries. On the primary save Craft duplicates the primary site's blocks to every
        // site the entry propagates to, and the loop above only visits sites the payload
        // names — so only those get pruned by their own save. A locale the page does not
        // exist in is never visited, and keeps a full copy of the primary's blocks forever.
        // Measured on a clean run: 28,108 blocks written from 6,285 compiled, the surplus
        // sitting on 7,729 (entry, site) pairs the payload never wrote.
        //
        // A site with no payload has no content by definition, so anything found there is a
        // propagation artefact and is removed.
        // ------------------------------------------------------------------ 7b
        $this->wipeBlocksOnUnpayloadedSites($entry, $perSite);

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

    // --------------------------------------------------------------------------
    // Task 5 (`load/fixup`) — single-field/parent patch-and-resave support
    // --------------------------------------------------------------------------

    /**
     * Read a single field's CURRENT serialized value for one entry/site, so
     * `FixupService` can navigate into it by `path` and append a
     * newly-resolved relation id without touching the rest of the entry.
     * Returns null when the entry/site doesn't resolve or the field has no
     * array-shaped value yet (caller treats it as an empty container).
     */
    public function readEntryFieldValueForSite(int $entryId, string $siteHandle, string $fieldHandle): ?array
    {
        $entry = $this->loadEntryForSite($entryId, $siteHandle);
        if ($entry === null) {
            return null;
        }

        $value = $entry->getSerializedFieldValues([$fieldHandle])[$fieldHandle] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * Write a single already-saved field back for one entry/site and re-save
     * through the same Craft save call every other write in this class uses
     * (propagateChanges=false, resaving=true — Pitfall 2). `setFieldValues()`
     * with only this one handle leaves every other field on the entry
     * untouched (`ElementTrait::setFieldValues()` loops just the given
     * keys). Callers are expected to pass back `$value` built from
     * `readEntryFieldValueForSite()`'s own output with only the target
     * container mutated — Craft's Matrix field serializes nested blocks
     * keyed by their real element id, so round-tripping that shape (rather
     * than rebuilding it from scratch) is what keeps block identity stable
     * across this re-save.
     */
    public function resaveEntryFieldForSite(int $entryId, string $siteHandle, string $fieldHandle, array $value): bool
    {
        $entry = $this->loadEntryForSite($entryId, $siteHandle);
        if ($entry === null) {
            return false;
        }

        $entry->setFieldValues([$fieldHandle => $value]);
        $entry->resaving = true;

        return (bool) $this->elements()->save($entry);
    }

    /**
     * Set/patch the parent link for one entry/site — the `path === []` case
     * (an unresolved `parentRef`) — and re-save the same way.
     */
    public function resaveEntryParentForSite(int $entryId, string $siteHandle, int $parentId): bool
    {
        $entry = $this->loadEntryForSite($entryId, $siteHandle);
        if ($entry === null) {
            return false;
        }

        $entry->setParentId($parentId);
        $entry->resaving = true;

        return (bool) $this->elements()->save($entry);
    }

    private function loadEntryForSite(int $entryId, string $siteHandle): ?Entry
    {
        $site = Craft::$app->sites->getSiteByHandle($siteHandle);
        if ($site === null) {
            return null;
        }

        return Entry::find()->id($entryId)->siteId($site->id)->status(null)->one();
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
        $fieldValues = (array) ($data['fieldValues'] ?? []);

        // Native title/slug come from the extract per-site `title`/`slug`
        // (NodeTranslation for Pages). When extract has nothing, fall back to
        // `fieldValues[title|slug]` — that's where mapping rows whose
        // `targetHandle` is `title` or `slug` land. The scaffolder marks such
        // rows with `craft_target: builtin_attribute` (intent), but the
        // runtime contract is "targetHandle in {title, slug, postDate,
        // expiryDate, enabled, parentId, authorId} routes to native". The
        // strip at line ~520 then drops them from the custom-field hash so
        // they don't double-write.
        $entry->title = (string) ($this->firstNonEmpty($data['title'] ?? null, $fieldValues['title'] ?? null) ?? '');
        // Only overwrite slug when a non-empty value is available. Singleton
        // sections (HomePage, ErrorPage, overview pages) have a meaningful
        // pre-existing slug that Kunstmaan doesn't expose; blanking it on
        // --overwrite re-runs breaks URI generation (uriFormat={slug}
        // collapses to empty, collides with root URI, save fails validation).
        $resolvedSlug = $this->firstNonEmpty($data['slug'] ?? null, $fieldValues['slug'] ?? null);
        if ($resolvedSlug !== null) {
            $entry->slug = (string) $resolvedSlug;
        } elseif ($stateSource !== null && self::isHomePageStateSource($stateSource)) {
            // Kunstmaan HomePage rows have NULL slug/url because they ARE
            // the site root — `kuma_node_translations` doesn't store a slug
            // for the lvl=0 homepage. Without this branch, Craft auto-derives
            // `slug = "home"` from the entry title, and the migrated entry
            // serves at `/nl/home` instead of `/nl/`.
            //
            // `__home__` is Craft's HOMEPAGE_URI marker (Element.php:171). Craft's
            // UrlManager (UrlManager.php:412) routes `/` to the entry whose URI
            // is `__home__`, and ElementHelper::normalizeSlug special-cases the
            // value so the slug survives validation. Works on Structure sections
            // with `uriFormat: {slug}` — no need to special-case the section.
            $entry->slug = Element::HOMEPAGE_URI;
        }

        if (!empty($data['parentId'])) {
            $entry->setParentId((int) $data['parentId']);
        }

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
        } elseif ($entry->postDate === null) {
            // CRITICAL routability fallback. The migrator sets
            // `$entry->resaving = true` to suppress per-save revision
            // creation, but `Entry::maybeSetDefaultAttributes()` (Craft
            // 5 — Entry.php:3010) short-circuits the auto-postDate when
            // resaving is true:
            //
            //     if ($this->resaving || $this->getIsRevision()) {
            //         return;  // ← skips the postDate default below
            //     }
            //
            // Net effect without this elseif: every migrated entry has
            // postDate=NULL → status=STATUS_PENDING → Entry::route()
            // returns null → UrlManager::_getMatchedElementRoute fails
            // → 404 on the frontend even though slug/uri are correct.
            //
            // Use `now()` rather than leaving the entry pending. The
            // primary path above (kuma_node_translations.created via
            // extract → transform) supplies the real source date when
            // available; this is the safety net for entries where the
            // source date is unparseable, missing, or the entry was
            // migrated via a path that doesn't carry NodeTranslation
            // dates (singletons, AbstractConfigs, promoted targets).
            $entry->postDate = new \DateTime();
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
     *    on re-runs; a clean re-run currently requires resetting the
     *    affected Craft elements + state rows by hand.
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
                $suppressNativeTitleFallback = ($block['fields']['_suppressNativeTitleFallback'] ?? false) === true;

                // Strip hidden migration-only tags before assigning Matrix fields.
                unset($block['fields']['_sourcePartRef']);
                unset($block['fields']['_suppressNativeTitleFallback']);

                // Nested Matrix fields carry blocks of their own. A tag left behind in one
                // reaches setFieldValues and Craft rejects it as an unknown custom field, so
                // the strip has to follow the nesting rather than stop at the first level.
                $block['fields'] = $this->stripSourcePartRefs($block['fields'], $report, $context);

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

                if ($suppressNativeTitleFallback && !$this->hasNonEmptyString($block['title'] ?? null)) {
                    $block['title'] = '';
                } elseif (!$this->hasNonEmptyString($block['title'] ?? null)) {
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

    /**
     * Recognises a Kunstmaan HomePage state source from its FQCN-derived slug
     * form (e.g. `App_Entity_Pages_HomePage`). Lameco's portfolio convention
     * names the homepage entity literally `HomePage` across all 3 sampled
     * sites (dewert / deklerk / simac); the suffix check is robust against
     * project-namespace drift.
     *
     * Used to special-case the `__home__` slug fallback when source
     * `kuma_node_translations.slug` is NULL — the homepage IS the site root,
     * Kunstmaan stores no slug for it, and Craft's title-derived auto-slug
     * `home` would route the migrated entry to `/nl/home` instead of `/nl/`.
     */
    private static function isHomePageStateSource(string $stateSource): bool
    {
        return str_ends_with($stateSource, '_HomePage');
    }

    /**
     * Pick the first scalar argument that is neither null nor an empty/whitespace
     * string. Used to resolve native attributes (title/slug) where extract is
     * the canonical source but a mapping row pointing `targetHandle` at the
     * native handle is the legitimate fallback.
     */
    private function firstNonEmpty(mixed ...$candidates): mixed
    {
        foreach ($candidates as $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            return $value;
        }
        return null;
    }

    private function recordFallback(?MigrationReport $report, string $category, string $message): void
    {
        if ($report !== null) {
            $report->warn($message);
            $report->incr('fallback.' . $category);
        }
        $this->warn($message);
    }

    /**
     * Log a warning when Craft is there to log it.
     *
     * The write paths run under PHPUnit as well as under a console command,
     * and the logger is the only part of a failure branch that needs Craft —
     * without this check a `catch` block fatals in a test instead of reporting
     * the failure it was written to report.
     */
    private function warn(string $message): void
    {
        if (class_exists(Craft::class, false)) {
            Craft::warning('EntryMigrationService: ' . $message, __METHOD__);
        }
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
     * Phase 12 / propagationMethod=none ghost-block cleanup. Hard-delete
     * matrix-block elements on the secondary site that aren't in our
     * tracked sourceRef→blockId map for this site. Background:
     *
     * Craft 5's matrix-block save with `propagationMethod: none` creates
     * empty mirror blocks on every non-primary site enabled on the parent
     * during the PRIMARY save (EntryMigrationService::saveEntryForSites
     * sets `setEnabledForSite([1=>true, 2=>true, ...])` before the primary
     * save). When the secondary site's save then runs with that site's
     * actual data, Craft adds NEW blocks alongside the ghosts instead of
     * replacing them — so the EN site's matrix ends up with both the
     * 5 NL-mirror ghosts (sortOrder 1-5, NULL content) and the 3 real EN
     * blocks (sortOrder 1-3) co-existing. Without this cleanup, the
     * rendered EN matrix is a bloated mess.
     *
     * Cleanup contract:
     *   - $perSiteFieldValues: this site's fieldValues map; we only wipe
     *     blocks for matrix fields the secondary save is about to populate.
     *     Other matrix fields (which this site's payload doesn't touch)
     *     are left alone.
     *   - $trackedBlockIds: blockUidMap[$siteHandle] — sourceRef → blockId
     *     for blocks this migration tracked as real on this site. On first
     *     runs the bucket is empty (no real blocks yet); on re-runs it
     *     carries the previous run's real EN block ids.
     *
     * @param array<string, mixed>  $perSiteFieldValues
     * @param array<string, string> $trackedBlockIds  sourceRef → blockId
     */
    private function wipeStaleSecondarySiteBlocks(
        Entry $localised,
        array $perSiteFieldValues,
        array $trackedBlockIds,
    ): void {
        $trackedIds = [];
        foreach ($trackedBlockIds as $blockId) {
            $intId = (int) $blockId;
            if ($intId > 0) {
                $trackedIds[$intId] = true;
            }
        }

        foreach ($perSiteFieldValues as $handle => $payload) {
            if (!is_array($payload) || $payload === [] || !$this->looksLikeMatrixPayload($payload)) {
                continue;
            }
            try {
                $existing = $localised->getFieldValue((string) $handle);
            } catch (\Throwable) {
                continue;
            }
            if (!$existing || !method_exists($existing, 'all')) {
                continue;
            }
            // Use ->siteId() to scope the query to this site's blocks.
            // Without this scope we'd potentially see blocks from other
            // sites and mistakenly delete them.
            try {
                if (method_exists($existing, 'siteId')) {
                    $existing->siteId($localised->siteId);
                }
                if (method_exists($existing, 'status')) {
                    $existing->status(null);
                }
                $blocks = $existing->all();
            } catch (\Throwable) {
                continue;
            }
            foreach ($blocks as $block) {
                if (!is_object($block) || empty($block->id)) {
                    continue;
                }
                if (isset($trackedIds[(int) $block->id])) {
                    continue;
                }
                try {
                    $this->elements()->delete($block, true);
                } catch (\Throwable $e) {
                    $this->warn(sprintf(
                        'wipeStaleSecondarySiteBlocks: deleteElement(%d) failed: %s',
                        (int) $block->id,
                        $e->getMessage(),
                    ));
                }
            }
        }
    }

    /**
     * Delete nested entries sitting on sites this payload said nothing about.
     *
     * Craft's own pruning (`NestedElementManager::deleteOtherNestedElements()`) is scoped to
     * the owner's site, so it only ever runs for a site the loader actually saves. This closes
     * the gap for the rest.
     *
     * @param array<string, mixed> $perSite the payload's per-site data, keyed by site handle
     */
    private function wipeBlocksOnUnpayloadedSites(Entry $entry, array $perSite): void
    {
        $keep = [];

        foreach (array_keys($perSite) as $handle) {
            $site = $this->getSiteByHandle((string) $handle);

            if ($site !== null) {
                $keep[$site->id] = true;
            }
        }

        if ($keep === []) {
            return;
        }

        foreach (Craft::$app->sites->getAllSites() as $site) {
            if (isset($keep[$site->id])) {
                continue;
            }

            /** @var Entry|null $localised */
            $localised = Entry::find()
                ->id($entry->id)
                ->siteId($site->id)
                ->status(null)
                ->one();

            if ($localised === null) {
                continue;
            }

            foreach ($this->nestedEntriesOn($localised) as $block) {
                try {
                    $this->elements()->delete($block, true);
                } catch (\Throwable $e) {
                    $this->warn(sprintf(
                        'wipeBlocksOnUnpayloadedSites: deleteElement(%d) failed: %s',
                        (int) $block->id,
                        $e->getMessage(),
                    ));
                }
            }
        }
    }

    /**
     * Every nested entry owned by this entry on its own site, across all Matrix fields.
     *
     * @return list<Entry>
     */
    private function nestedEntriesOn(Entry $localised): array
    {
        $out = [];

        foreach ($localised->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            // Matrix only. An Entries *relation* field also answers `all()`, and its entries
            // are other people's content — deleting those would be catastrophic rather than
            // tidy. Ownership is re-checked per block below as a second line of defence.
            if (!$field instanceof Matrix) {
                continue;
            }

            try {
                $value = $localised->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if (!is_object($value) || !method_exists($value, 'all')) {
                continue;
            }

            try {
                if (method_exists($value, 'siteId')) {
                    $value->siteId($localised->siteId);
                }
                if (method_exists($value, 'status')) {
                    $value->status(null);
                }

                foreach ($value->all() as $block) {
                    // Only ever delete a block this entry actually owns.
                    if ($block instanceof Entry
                        && $block->id
                        && $block->getPrimaryOwnerId() === $localised->id
                    ) {
                        $out[] = $block;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
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
