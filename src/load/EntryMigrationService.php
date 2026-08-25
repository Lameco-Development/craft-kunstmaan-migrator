<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\fields\Matrix;
use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\sites\SiteMap;
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
 * Which Craft sites an entry is written to is a fact about the environment
 * being migrated — COM's `en` is comEnUs while LV's is comLvEn — so it arrives
 * per call as the environment's `SiteMap`, never as a property. The map's
 * configured order is the save order; Craft's primary flag on a binding picks
 * the site that is saved first.
 *
 * API:
 *   public function saveEntryForSites(
 *       int $sectionId,
 *       int $typeId,
 *       string $stateSource,
 *       string|int $stateKey,
 *       array $perSite,
 *       SiteMap $sites,
 *   ): \craft\elements\Entry
 *
 * $perSite is keyed by Craft site handle (the handles the SiteMap binds).
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
 * Returns the primary-site Entry instance (for follow-on SEO/redirect writes).
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

    // --------------------------------------------------------------------------
    // Public API
    // --------------------------------------------------------------------------

    private function elements(): ElementWriter
    {
        return $this->elementWriter ??= new CraftElementWriter();
    }

    /**
     * Save or update an Entry across every site the environment binds, in one call.
     *
     * @param array<string, array{enabled: bool, title: ?string, slug: string, fieldValues: array, parentId: ?int}> $perSite
     * @param SiteMap $sites the environment's sites; configured order is save order
     * @param RunTally|null $tally where a loss this install cannot represent is counted
     * @throws RuntimeException on unknown site handle or primary-site save failure
     */
    public function saveEntryForSites(
        int $sectionId,
        int $typeId,
        string $stateSource,
        string|int $stateKey,
        array $perSite,
        SiteMap $sites,
        bool $force = false,
        ?MigrationReport $report = null,
        ?RunTally $tally = null,
    ): Entry {
        $configuredHandles = $sites->handles();
        if ($configuredHandles === []) {
            throw new RuntimeException(
                'EntryMigrationService: the site map is empty — the mapping declares no locales '
                . 'for this environment (e.g. locales: { nl: default }).',
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

        // Only the handles Craft actually has a site for; the map already
        // dropped the rest when it was bound.
        $targets = $sites->targets();
        if ($targets === []) {
            throw new RuntimeException(
                'EntryMigrationService: none of the configured site handles resolve to '
                . 'a Craft site. Configured: ' . implode(', ', $configuredHandles),
            );
        }

        // Craft's primary site from the configured set, not array-position 0:
        // the mapping's `locales:` order is the operator's, and an `en` listed
        // before `nl` once made the loader save EN first with an empty payload
        // (the transform only populates perSite[<primary>] for online
        // translations) — every page failed with "Title cannot be blank."
        //
        // No configured locale on Craft's primary site is an unusual setup;
        // the first target stands in, loud-but-running rather than throwing.
        $primarySite = $sites->primary() ?? $targets[0];

        // ------------------------------------------------------------------ 1
        // Look up existing entry via state table
        // ------------------------------------------------------------------ 1
        $existingId = $this->stateService->getTargetId($stateSource, (string) $stateKey);
        $entry = null;

        if ($existingId !== null) {
            $entry = $this->elements()->findById($existingId, Entry::class, $primarySite->siteId);

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
        // A Single's one entry is reused rather than created beside — a second
        // fails URI validation. Subsequent runs find it through the state row.
        $entry ??= $this->elements()->singleEntry($sectionId, $primarySite->siteId)
            ?? $this->elements()->createEntry($sectionId, $typeId, $primarySite->siteId);

        // ------------------------------------------------------------------ 3
        // Pre-seed per-site enablement map BEFORE the first save (DEC-06)
        // ------------------------------------------------------------------ 3
        $enabledMap = [];
        foreach ($targets as $site) {
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
            $enabledMap[$site->siteId] = (bool) ($siteData['enabled'] ?? $siteData['online'] ?? false);
        }

        // The entry is always created *in* the primary site (`$entry->siteId` above), so a row
        // there is unavoidable even when the payload never mentions it. It must be explicitly
        // disabled: `primarySiteDataForSave()` borrows another locale's title, slug and
        // `enabled` to fill the gap, so leaving this unset publishes a French page at an English
        // URL. Measured: 8 of 29 entries in a slice went live in EN carrying FR/DK/NL content.
        //
        // One disabled row per entry, on the primary site only — not one per configured locale.
        if (!isset($perSite[$primarySite->handle])) {
            $enabledMap[$primarySite->siteId] = false;
        }

        $entry->setEnabledForSite($enabledMap);

        // ------------------------------------------------------------------ 4
        // Block identity: the ids the previous run recorded, threaded back in
        // so re-runs update nested entries in place (Pitfall 3)
        // ------------------------------------------------------------------ 4
        $existingMeta = $existingId !== null
            ? ($this->stateService->get($stateSource, (string) $stateKey)['meta'] ?? null)
            : null;
        $blocks = BlockIdentity::fromMeta($this->elements(), $existingMeta, $primarySite->handle);

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
        // Say so before writing, not after: on a field that shares one block set across sites,
        // locales carrying different legacy parts cannot all survive the save.
        $this->reportUnrepresentablePerSiteBlocks(
            $entry,
            $perSite,
            $report,
            $stateSource,
            (string) $stateKey,
            $tally,
        );
        $this->applyPerSiteData(
            $entry,
            $primaryData,
            $blocks,
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

        $blocks->record($primarySite->handle, $entry);

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
        foreach ($targets as $site) {
            if ($site->siteId === $primarySite->siteId) {
                continue;
            }
            if (!isset($perSite[$site->handle])) {
                continue;
            }
            // Critical: re-load scoped to siteId (Pitfall 2)
            $localised = $this->elements()->findById((int) $entry->id, Entry::class, $site->siteId);

            if ($localised === null) {
                continue;
            }

            // Ghost blocks the primary save propagated onto this site would otherwise sit
            // beside the blocks this save is about to write; the secondary save adds, it does
            // not replace. Anything here that no run tracked goes first.
            $blocks->reconcile($localised, $site->handle, (array) ($perSite[$site->handle]['fieldValues'] ?? []));

            $this->applyPerSiteData(
                $localised,
                $perSite[$site->handle],
                $blocks,
                $report,
                $stateSource,
                (string) $stateKey,
                $site->handle,
            );

            // Critical: propagateChanges=false (Pitfall 2)
            $localised->resaving = true;
            if (!$this->elements()->save($localised)) {
                $this->warn(sprintf(
                    'site "%s" save failed for %s:%s — %s',
                    $site->handle,
                    $stateSource,
                    $stateKey,
                    json_encode($localised->getErrors()),
                ));
                continue;
            }

            $blocks->record($site->handle, $localised);
        }

        // ------------------------------------------------------------------ 7b
        // Blocks on sites this payload never addressed: the loop above only
        // visits sites the payload names, so only those are pruned by their
        // own save. A locale the page does not exist in is never visited.
        // ------------------------------------------------------------------ 7b
        $blocks->prune($entry, $perSite, $sites);

        // ------------------------------------------------------------------ 8
        // Persist the per-site block id map so the NEXT re-run can thread
        // the ids back in and update blocks in place (Pitfall 3).
        // ------------------------------------------------------------------ 8
        if ($blocks->tracked() !== []) {
            $this->stateService->updateMeta($stateSource, (string) $stateKey, null, ['blockIds' => $blocks->tracked()]);
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
    public function readEntryFieldValueForSite(int $entryId, int $siteId, string $fieldHandle): ?array
    {
        $entry = $this->loadEntryForSite($entryId, $siteId);
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
    public function resaveEntryFieldForSite(int $entryId, int $siteId, string $fieldHandle, array $value): bool
    {
        $entry = $this->loadEntryForSite($entryId, $siteId);
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
    public function resaveEntryParentForSite(int $entryId, int $siteId, int $parentId): bool
    {
        $entry = $this->loadEntryForSite($entryId, $siteId);
        if ($entry === null) {
            return false;
        }

        $entry->setParentId($parentId);
        $entry->resaving = true;

        return (bool) $this->elements()->save($entry);
    }

    private function loadEntryForSite(int $entryId, int $siteId): ?Entry
    {
        return $this->elements()->findById($entryId, Entry::class, $siteId);
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
     * @param BlockIdentity|null $blocks threads the site's known block ids into the payload; null writes it as is
     */
    private function applyPerSiteData(
        Entry $entry,
        array $data,
        ?BlockIdentity $blocks,
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
        // Only overwrite the title when a value is available — same rule as the
        // slug branch below. A merged Single receives multiple contributors
        // (a page entity plus a `single:` config row); the config row carries
        // no title of its own and must not blank the one already set.
        $resolvedTitle = $this->firstNonEmpty($data['title'] ?? null, $fieldValues['title'] ?? null);
        if ($resolvedTitle !== null) {
            $entry->title = (string) $resolvedTitle;
        }
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

        if ($blocks !== null && $siteHandle !== null) {
            $fieldValues = $blocks->thread($siteHandle, $fieldValues);
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
        $nativeDate = static function(mixed $raw): ?\DateTime {
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
                // Through the guarded helper: this is the one warning on the
                // apply path that called Craft::warning() directly, fataling
                // wherever the Craft alias is not loaded (unit tier, early CLI).
                $this->warn(sprintf(
                    'Dropped unknown field "%s" on entry type "%s" — not in the entry type\'s field layout. Clean up mapping.yaml.',
                    $handle,
                    $entry->getType()->handle,
                ));
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
     *    It is a compile-side marker, not a Craft field: the project config
     *    never added it to the 50 matrix block entry types, so Craft's
     *    CustomFieldBehavior rejects it as an unknown property.
     *
     *    Stripping it does *not* cost the UID threading, though this docblock
     *    said so for as long as that was true. The sourceRef→blockId map is
     *    read off the marker before the strip and persisted in the state row's
     *    `meta.blockIds`, per site; `BlockIdentity::thread()` reads it
     *    back on the next run and keys each block by its existing id, so Craft
     *    updates in place. Adding the field to the entry types would change
     *    nothing here — the marker never needed to survive the save, only the
     *    map does.
     *
     *    What does still rebuild a block set on every run is `propagationMethod:
     *    all` on the page-builder Matrix fields: Craft keeps one block set for
     *    the owner shared across every site, so two locales naming different
     *    parts overwrite each other's blocks each save, whatever this thread.
     *    That is a Craft-side field configuration, not a loader bug — see
     *    `PerSiteBlockDivergence`, which names the entries it happens to.
     *
     * 2. Lift `title` from `fields` to peer-level. Matrix block entry types with
     *    `hasTitleField: true` expect `title` as a native entry property — it must
     *    be a peer of `type`/`enabled`/`fields`, not nested inside `fields`.
     *
     *    `heading` is lifted the same way, but only on entry types that do not
     *    declare a `heading` field. It was added for CasesMigration's newsGridBlock
     *    payload, where `heading` was a typo for the native title. On a target whose
     *    blocks do have a `heading` field, lifting unconditionally moved editorial
     *    copy out of that field and onto a native title those types do not have, so
     *    Craft dropped it — measured at 4,196 lost headings on the Enreach corpus.
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
                $blockType = is_string($block['type'] ?? null) ? $block['type'] : null;
                foreach (['title', 'heading'] as $nativeKey) {
                    if (!array_key_exists($nativeKey, $block['fields'])) {
                        continue;
                    }
                    // `heading` is a real custom field on most block types. Lifting it there
                    // would move editorial copy onto a native title the entry type usually
                    // does not even have, and Craft would drop it.
                    if ($nativeKey === 'heading' && $this->entryTypeHasField($blockType, 'heading')) {
                        continue;
                    }
                    $lifted = $block['fields'][$nativeKey];
                    unset($block['fields'][$nativeKey]);
                    $peerKey = $nativeKey === 'heading' ? 'title' : $nativeKey;
                    if (!array_key_exists($peerKey, $block)) {
                        $block[$peerKey] = $lifted;
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
     * `entryTypeHandle:fieldHandle` pairs the project config declares, or null until read.
     *
     * @var array<string, bool>|null
     */
    private ?array $entryTypeFieldHandles = null;

    /**
     * Override the entry-type field lookup. Tests use this to describe a target's shape
     * without a Craft bootstrap; production leaves it null and reads the real layouts.
     *
     * @param callable(string, string): bool $probe
     */
    public function setEntryTypeFieldProbe(callable $probe): void
    {
        $this->entryTypeFieldProbe = $probe;
    }

    /** @var (callable(string, string): bool)|null */
    private $entryTypeFieldProbe = null;

    /**
     * Does the entry type behind a matrix block declare a custom field with this handle?
     *
     * A field instance may re-handle its field on the layout, so the layout is the only
     * authority — the global field handle can differ from the one the payload names.
     */
    private function entryTypeHasField(?string $entryTypeHandle, string $fieldHandle): bool
    {
        if ($entryTypeHandle === null || $entryTypeHandle === '') {
            return false;
        }

        if ($this->entryTypeFieldProbe !== null) {
            return ($this->entryTypeFieldProbe)($entryTypeHandle, $fieldHandle);
        }

        if ($this->entryTypeFieldHandles === null) {
            $this->entryTypeFieldHandles = [];
            foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
                $layout = $entryType->getFieldLayout();
                if ($layout === null) {
                    continue;
                }
                foreach ($layout->getCustomFieldElements() as $element) {
                    $handle = $element->attribute();
                    if ($handle !== '') {
                        $this->entryTypeFieldHandles[$entryType->handle . ':' . $handle] = true;
                    }
                }
            }
        }

        return isset($this->entryTypeFieldHandles[$entryTypeHandle . ':' . $fieldHandle]);
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
     * Report per-locale block content that this Craft install cannot hold.
     *
     * A Matrix field with `propagationMethod: all` keeps **one** block set for the owner, shared
     * across every site. That is fine while each locale's payload names the same legacy parts —
     * 753 entries on the reference corpus do — but when the locales name *different* parts, the
     * two sets cannot both exist. Each site's save replaces the other's, forever: measured at 61
     * blocks replaced per forced run with the live count flat, and the losing locale left showing
     * the winner's content — a Latvian page serving English blocks.
     *
     * The loader cannot fix this. The block set is global by the field's own configuration, so
     * representing divergent locales needs `propagationMethod: none` (or a per-site
     * `propagationKeyFormat`) on the Craft side. What the loader can do is stop doing it quietly:
     * the run reports the loss, and `--fail-on-loss` turns it into a non-zero exit.
     *
     * @param array<string, mixed> $perSite payload data keyed by site handle
     */
    private function reportUnrepresentablePerSiteBlocks(
        Entry $entry,
        array $perSite,
        ?MigrationReport $report,
        string $stateSource,
        string $stateKey,
        ?RunTally $tally = null,
    ): void {
        // sourceRefs per matrix handle per site, from the payload the compiler produced.
        $refsByField = [];

        foreach ($perSite as $siteHandle => $siteData) {
            foreach (BlockIdentity::sourceRefs((array) ($siteData['fieldValues'] ?? [])) as $fieldHandle => $refs) {
                $refsByField[$fieldHandle][(string) $siteHandle] = $refs;
            }
        }

        foreach ($refsByField as $fieldHandle => $perSiteRefs) {
            if (count($perSiteRefs) < 2) {
                continue;
            }

            // Identical refs across locales are representable in one shared set — that is the
            // common case, and warning on it would make the warning worthless.
            if (!PerSiteBlockDivergence::isUnrepresentable($perSiteRefs)) {
                continue;
            }

            if ($this->matrixPropagatesToAllSites($entry, (string) $fieldHandle) !== true) {
                continue;
            }

            $tally?->perSiteBlockLoss(sprintf(
                '%s:%s field "%s": %d locales carry different legacy parts, but the field '
                . 'propagates one block set to all sites — only the last locale written survives.',
                $stateSource,
                $stateKey,
                $fieldHandle,
                count($perSiteRefs),
            ));

            $this->recordFallback(
                $report,
                'perSiteBlocksNotRepresentable',
                sprintf(
                    '%s:%s field "%s": %d locales carry different legacy parts, but the field '
                    . 'propagates one block set to all sites — only the last locale written survives. '
                    . 'Set propagationMethod to none (or a per-site propagationKeyFormat) on this field.',
                    $stateSource,
                    $stateKey,
                    $fieldHandle,
                    count($perSiteRefs),
                ),
            );
        }
    }

    /**
     * Whether a Matrix field keeps one block set across every site.
     *
     * Resolved through the entry's field layout rather than the field handle: page-builder
     * fields are field *instances*, so `getFieldByHandle('pageBuilder')` finds nothing while
     * the layout knows exactly which field that instance points at.
     */
    private function matrixPropagatesToAllSites(Entry $entry, string $fieldHandle): ?bool
    {
        try {
            $field = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);

            if (!$field instanceof Matrix) {
                return null;
            }

            return $field->propagationMethod === PropagationMethod::All;
        } catch (\Throwable) {
            return null;
        }
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
}
