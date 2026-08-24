<?php

/**
 * Example plugin config — copy to `config/kunstmaan-migrator.php` in the
 * consumer Craft site to override Settings defaults.
 *
 * Multi-env keys: '*' is the base, then '.dev' / '.production' overlays
 * merge over '*' in that order (Craft's standard `getConfigFromFile()` shape).
 *
 * Values shown are the plugin defaults — copy and edit only what needs to
 * change. The essentials live in the CP Settings page; the advanced fields
 * below are file-only.
 *
 * Phase 4.1 / CFG-05: advanced fields stripped from the CP Settings page
 * and documented here as the canonical override surface.
 *
 * @see Lameco\Kunstmaanmigrator\models\Settings — source of truth for defaults.
 */

return [
    '*' => [
        // Connectivity (advanced — CP holds host/port/db/user/password)
        'legacyDbCharset'         => 'utf8mb4',
        'legacyDbTablePrefix'     => '',

        // AI proposal stage (analyze only) — null means "use plugin default"
        'llmModel'                => null,
        'llmTimeout'              => null,
        'llmInterChunkDelay'      => null,

        // Default filter values (used when CLI flags omitted)
        'defaultEntities'         => [],
        'defaultLocales'          => [],
        'defaultSince'            => null,
        'defaultMaxPerEntity'     => null,
        'dryRunDefault'           => true,

        // Verify gate tolerances (Phase 4 / D-60)
        'verifyCountTolerance'    => 0.01,
        'verifyUrlDiffThreshold'  => 0.05,

        // Adapter table-name overrides (Phase 4 / D-57)
        'seoTableName'            => 'kuma_seo',
        'redirectsTableName'      => 'kuma_redirects',

        // Adapter Settings gates (Phase 4.1 / ADP-04). Default true preserves
        // current behavior — set false to disable the SEOmatic / Retour adapter
        // even when the respective plugin IS installed. Per-run override:
        // `--no-seo` / `--no-retour` on `kunstmaan-migrator/migrate`.
        'seoEnabled'              => true,
        'retourEnabled'           => true,

        // Optional generic rich-text fallback overrides. Usually unnecessary:
        // analyze/compile introspects Craft Matrix fields and picks the best
        // rich-text block automatically. Use only when the schema is ambiguous.
        'genericContentBlockOverrides' => [
            // 'pageBuilder' => ['blockType' => 'richTextBlock', 'fieldHandle' => 'bodyCopy'],
        ],

        // Optional relation mirrors. Use when a Craft design stores one
        // accepted relation in a second presentation field, such as a CTA
        // Matrix block. This is intentionally project config instead of a
        // hardcoded compiler heuristic.
        'relationMirrorRules' => [
            // [
            //     'targetField' => 'ctaPanel.primaryContact',
            //     'sourceField' => 'relatedContacts',
            // ],
        ],
    ],

    // Per-environment overlays — uncomment and customize as needed.
    // '.dev' => [
    //     // override any of the keys above per-environment
    // ],
    // '.production' => [
    //     // NeverProductionTrait hard-blocks the plugin in production anyway —
    //     // this overlay exists for symmetry / documentation purposes only.
    // ],
];
