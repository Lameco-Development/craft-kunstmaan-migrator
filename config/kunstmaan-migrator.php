<?php

/**
 * Kunstmaan Migrator configuration.
 *
 * Locale -> Craft site is NOT here. It is a per-environment fact — COM and LV both publish `en`
 * to different Craft sites — and `migration/mapping/enreach.yaml` already states it per
 * environment under `environments.<ENV>.locales`. The loader reads it from there for whichever
 * environment is being run, so there is nothing to keep in sync and nothing to remember to edit
 * between runs.
 */

return [
    /**
     * Where the mapping lives.
     *
     * Here rather than in the settings screen, because a value saved there is
     * written into project config — which is committed and deployed, and an
     * absolute path to one developer's home directory does not survive either.
     * A config file wins over project config in Craft, so this is also the
     * value the control panel shows.
     */
    'mappingPath' => '@root/migration/mapping/enreach.yaml',

    /**
     * The starter kit rejects images over 5 MB via an Asset::EVENT_BEFORE_SAVE listener, and
     * a legacy library predates that rule. Without this the exception propagates and takes
     * the entire entry down over one oversized image; with it the asset is skipped and
     * reported, and the rest of the page still migrates.
     */
    'skipAssetSizeValidation' => true,
];
