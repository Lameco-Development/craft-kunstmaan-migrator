<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\load;

use Craft;
use craft\helpers\StringHelper;
use lameco\kunstmaanmigrator\migrations\Install;
use yii\base\Component;

/**
 * Single source of truth for attaching the `kunstmaanSourceId` tracking
 * field to operator-selected entry types (D-08-07).
 *
 * Consumed from two call sites:
 *   - `Install.php` (optional post-install pass once settings are populated)
 *   - `Plugin::EVENT_AFTER_SAVE_PLUGIN_SETTINGS` listener (runtime path
 *     whenever an operator updates the `entryTypeUids` multiselect).
 *
 * Semantics:
 *   - Idempotent: re-attach is a no-op when the field is already on a layout.
 *   - Additive only: never detaches from entry types that were previously
 *     attached but have been dropped from the settings multiselect. This
 *     protects historical `kunstmaanSourceId` data on entries whose entry
 *     type was later un-selected.
 */
class AttachService extends Component
{
    /**
     * Attach `$fieldUid` to a single entry type's field layout.
     *
     * Returns true when an attach actually occurred, false when the field
     * was already present (idempotent no-op) or the entry type could not
     * be resolved.
     */
    public function attachFieldToEntryType(string $entryTypeUid, string $fieldUid): bool
    {
        $entryType = Craft::$app->entries->getEntryTypeByUid($entryTypeUid);
        if ($entryType === null) {
            Craft::warning(
                "kuma-loader AttachService: entryType UID {$entryTypeUid} not found; skipping attach",
                'kuma-loader'
            );
            return false;
        }

        // Fast-path: check the in-memory field layout first. If the field
        // is already present we can return without touching project-config.
        $layout = $entryType->getFieldLayout();
        if ($layout !== null) {
            foreach ($layout->getTabs() as $tab) {
                foreach ($tab->getElements() as $element) {
                    if (method_exists($element, 'getField')) {
                        $field = $element->getField();
                        if ($field !== null && $field->uid === $fieldUid) {
                            return false; // already attached — idempotent no-op
                        }
                    }
                }
            }
        }

        // Locate the entry type's fieldLayout in project-config. Craft 5
        // stores it under `entryTypes.{entryTypeUid}.fieldLayouts.{layoutUid}`
        // where the layout UID is a dynamic key (only one per entry type).
        $projectConfig = Craft::$app->projectConfig;
        $entryTypePath = "entryTypes.{$entryTypeUid}";
        $entryTypeCfg = $projectConfig->get($entryTypePath, true);

        if (!is_array($entryTypeCfg) || !isset($entryTypeCfg['fieldLayouts']) || !is_array($entryTypeCfg['fieldLayouts'])) {
            Craft::warning(
                "kuma-loader AttachService: entryType {$entryTypeUid} has no fieldLayouts in project-config; skipping",
                'kuma-loader'
            );
            return false;
        }

        // Single-layout convention: take the first (and only) layout UID.
        $layoutUid = array_key_first($entryTypeCfg['fieldLayouts']);
        $layoutCfg = $entryTypeCfg['fieldLayouts'][$layoutUid];

        if (!isset($layoutCfg['tabs']) || !is_array($layoutCfg['tabs']) || $layoutCfg['tabs'] === []) {
            Craft::warning(
                "kuma-loader AttachService: entryType {$entryTypeUid} layout has no tabs; skipping",
                'kuma-loader'
            );
            return false;
        }

        // Re-check against project-config (handles the case where in-memory
        // layout was null but config already has the field).
        foreach ($layoutCfg['tabs'] as $tab) {
            if (!isset($tab['elements']) || !is_array($tab['elements'])) {
                continue;
            }
            foreach ($tab['elements'] as $element) {
                if (($element['fieldUid'] ?? null) === $fieldUid) {
                    return false; // already attached in project-config
                }
            }
        }

        // Append a CustomField element to the first tab, referencing $fieldUid.
        $newElement = [
            'dateAdded' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP'),
            'editCondition' => null,
            'elementCondition' => null,
            'elementEditCondition' => null,
            'fieldUid' => $fieldUid,
            'handle' => null,
            'instructions' => null,
            'label' => null,
            'required' => false,
            'tip' => null,
            'type' => 'craft\\fieldlayoutelements\\CustomField',
            'uid' => StringHelper::UUID(),
            'userCondition' => null,
            'warning' => null,
            'width' => 100,
        ];

        $firstTabKey = array_key_first($layoutCfg['tabs']);
        if (!isset($layoutCfg['tabs'][$firstTabKey]['elements']) || !is_array($layoutCfg['tabs'][$firstTabKey]['elements'])) {
            $layoutCfg['tabs'][$firstTabKey]['elements'] = [];
        }
        $layoutCfg['tabs'][$firstTabKey]['elements'][] = $newElement;

        $entryTypeCfg['fieldLayouts'][$layoutUid] = $layoutCfg;
        $projectConfig->set($entryTypePath, $entryTypeCfg);

        Craft::info(
            "kuma-loader AttachService: attached field UID {$fieldUid} to entryType {$entryTypeUid}",
            'kuma-loader'
        );
        return true;
    }

    /**
     * DEFERRED to Phase 4 / CFG-01 — Settings::$entryTypeUids is not declared yet
     * (v2 Settings only ships connection + AI fields per Phase 1 / D-15). The CP
     * Settings form (CFG-01) introduces the field; this method is reinstated in
     * the same phase with the v1 body.
     *
     * For Phase 3, throw to make the omission explicit if anything calls this.
     */
    public function attachAllFromSettings(): void
    {
        throw new \RuntimeException(
            'AttachService::attachAllFromSettings() is deferred to Phase 4 / CFG-01. '
            . 'Phase 3 only ships attachFieldToEntryType($entryTypeUid, $fieldUid).',
        );
    }
}
