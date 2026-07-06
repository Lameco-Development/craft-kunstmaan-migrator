<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\db\KunstmaanEnvReader;

/**
 * Plugin Settings — shared seam between env vars and config/kunstmaan-migrator.php.
 * v2 loader prune: analyze/compile/CP-queue-only properties (Anthropic key,
 * mapping-stage config, source-checkout path, Phase 12 CP/queue gates) are
 * removed — this model now declares only what src/load/, src/fields/, and
 * src/safety/ actually read.
 */
class Settings extends Model
{
    // Legacy DB connection (D-12). Defaults to CRAFT_LEGACY_DB_* env vars.
    public ?string $legacyDbServer       = null;
    public int     $legacyDbPort         = 3306;
    public ?string $legacyDbDatabase     = null;
    public ?string $legacyDbUser         = null;
    public ?string $legacyDbPassword     = null;
    public string  $legacyDbCharset      = 'utf8mb4';
    public string  $legacyDbTablePrefix  = '';

    /**
     * Explicit locale override map: legacy Kunstmaan locale → Craft site handle.
     * Wins over both exact-match and language-prefix loose-match. Use when a
     * single legacy locale needs to land on a specific Craft handle (e.g.
     * `['nl' => 'nl-NL']` when Craft uses BCP 47 long-form handles).
     *
     * @var array<string, string>
     */
    public array   $localeMap            = [];

    /**
     * Craft volume handle assets land in when migrated. Defaults to
     * `uploads` — the starter-kit convention. Scaffolder-generated targets
     * use `media` (matches Kunstmaan's `kuma_media` semantics); override
     * via `config/kunstmaan-migrator.php` to align with the actual handle.
     */
    public string  $targetVolume         = 'uploads';

    /**
     * Skip starter-kit / project-side asset-size validators during ingest.
     * When true, AssetMigrationService catches `yii\web\HttpException` thrown
     * from `Asset::EVENT_BEFORE_SAVE` listeners whose message matches the
     * starter-kit's "The file is too large" copy, downgrades to a WARN, and
     * skips that asset. Other validation throws still surface.
     *
     * Use case: Lameco's craft-starter-kit ships a per-extension size cap
     * (modules/lameco/Module.php — 10MB for PDFs etc.) that's appropriate for
     * editor uploads but rejects valid pre-existing assets during migration.
     * Surfaced by deklerk's >10MB PDF on 2026-05-09.
     *
     * Defaults false — opt-in via project's `config/kunstmaan-migrator.php`
     * to acknowledge the operator is willingly bypassing the cap for
     * already-curated source content.
     */
    public bool    $skipAssetSizeValidation = false;

    // Phase 4 / D-57 — adapter source-table overrides for variant Kunstmaan
    // flavours. Defaults match the canonical kuma_* schema; operators flip via
    // env vars or config/kunstmaan-migrator.php when the legacy DB diverges.
    public string $seoTableName = 'kuma_seo';
    public string $redirectsTableName = 'kuma_redirects';
    public string $menuTableName = 'kuma_menu';
    public string $menuItemTableName = 'kuma_menu_item';
    public string $nodesTableName = 'kuma_nodes';
    public string $translationTableName = 'kuma_translation';

    /**
     * Symfony translation domains to migrate from kuma_translation.
     * Defaults to `['messages']` — the default Symfony domain that
     * `{% trans %}` calls without explicit `from 'X'` use. Other domains
     * (validators, security, plugin-specific) are skipped with a warning;
     * extend this list to include them.
     *
     * @var list<string>
     */
    public array $translationDomains = ['messages'];

    /**
     * Slice 2 — NodeMenu pass target nav handle. Defaults match the
     * scaffolder's slice 7 v0.7 porter rewrite (`headerMain`).
     */
    public string $nodeMenuNavHandle = 'headerMain';

    /**
     * Slice 2 — `kuma_nodes.internal_name` values to exclude from NodeMenu
     * migration. Defaults to `['settings']` (every Lameco site filters
     * this from header nav). Operator extends per-project — e.g. dewert
     * also filters `'dienst'` (legacy ServicesOverviewPage).
     *
     * @var list<string>
     */
    public array $nodeMenuExcludedInternalNames = ['settings'];

    // Phase 4.1 / D-24 — adapter explicit-disable. Defaults to true so existing
    // operators see no behavior change; flip to false to skip the adapter even
    // when the plugin IS installed. CLI --no-seo / --no-retour / --no-nav bypass per-run.
    public bool $seoEnabled = true;
    public bool $retourEnabled = true;
    public bool $navigationEnabled = true;
    public bool $translationsEnabled = true;

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'legacyDbServer', 'legacyDbDatabase', 'legacyDbUser', 'legacyDbPassword',
                    'legacyDbCharset', 'legacyDbTablePrefix',
                    // Phase 4 / D-57 — adapter table-name env overrides. The
                    // Phase 4 / D-60 verify-tolerance floats deliberately stay
                    // out of this list; env-parse of float values is fragile
                    // (PATTERNS.md flag #2) — CLI override is their runtime knob.
                    'seoTableName', 'redirectsTableName',
                ],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // D-12: env-var fallback. config/kunstmaan-migrator.php overrides win when present
        // (Craft loads the config file BEFORE init() and assigns to the public properties,
        // so `??=` only fills the unset cases).
        $this->legacyDbServer      ??= App::env('CRAFT_LEGACY_DB_SERVER') ?: null;
        $this->legacyDbDatabase    ??= App::env('CRAFT_LEGACY_DB_DATABASE') ?: null;
        $this->legacyDbUser        ??= App::env('CRAFT_LEGACY_DB_USER') ?: null;
        $this->legacyDbPassword    ??= App::env('CRAFT_LEGACY_DB_PASSWORD') ?: null;
        $envPort = App::env('CRAFT_LEGACY_DB_PORT');
        if ($envPort !== null && $envPort !== '' && $envPort !== false) {
            $this->legacyDbPort = (int) $envPort;
        }
        $envCharset = App::env('CRAFT_LEGACY_DB_CHARSET');
        if (is_string($envCharset) && $envCharset !== '') {
            $this->legacyDbCharset = $envCharset;
        }
        $envPrefix = App::env('CRAFT_LEGACY_DB_TABLE_PREFIX');
        if (is_string($envPrefix)) {
            $this->legacyDbTablePrefix = $envPrefix;
        }
    }

    /**
     * Phase 4.1 / D-07 — auto-fill blank legacyDb* fields from the Kunstmaan
     * project's `.env` `DATABASE_URL` at validation time. Operator-supplied
     * Settings values always win; only `null` slots fill.
     *
     * D-08: `KunstmaanEnvReader` returns components already `urldecode()`'d,
     *       so we just hand them straight through.
     * D-09: `KunstmaanEnvReader` only honours mysql / mysql+pdo / pdo:mysql
     *       schemes — every getDsn* accessor returns null for postgres /
     *       sqlite / unknown DSNs, so the `??=` fills become no-ops.
     *
     * `legacyDbPort` carries a property default of 3306 (not null), so we
     * treat that exact value as the "operator hasn't customized" sentinel
     * and only fill from the DSN when the operator's value is still 3306.
     */
    public function beforeValidate(): bool
    {
        // Fast path: every string field is operator-filled — skip the env reader entirely.
        if (
            $this->legacyDbServer !== null && $this->legacyDbServer !== ''
            && $this->legacyDbDatabase !== null && $this->legacyDbDatabase !== ''
            && $this->legacyDbUser !== null && $this->legacyDbUser !== ''
            && $this->legacyDbPassword !== null && $this->legacyDbPassword !== ''
        ) {
            return parent::beforeValidate();
        }

        $reader = $this->getEnvReader();
        $dsn = $reader->getDatabaseUrl();
        if ($dsn === null || $dsn === '') {
            return parent::beforeValidate();
        }

        // D-09: non-mysql DSN → reader's parsed components are all null.
        // Probe the three structurally-required components; if all three are null
        // the reader rejected the scheme and we have nothing to fill from.
        if (
            $reader->getDsnHost() === null
            && $reader->getDsnUser() === null
            && $reader->getDsnDatabase() === null
        ) {
            return parent::beforeValidate();
        }

        // D-07: operator values win — `??=` only writes when the field is null.
        // Empty-string is treated as operator-provided (operator may have intentionally
        // blanked a field; respect that choice).
        $this->legacyDbServer   ??= $reader->getDsnHost();
        $this->legacyDbUser     ??= $reader->getDsnUser();
        $this->legacyDbPassword ??= $reader->getDsnPassword();
        $this->legacyDbDatabase ??= $reader->getDsnDatabase();

        // Port special case: the property default is 3306 (the MySQL canonical).
        // Treat that exact value as the "operator hasn't customized" sentinel.
        // Any other operator-set port (5555, 33060, etc.) wins.
        $dsnPort = $reader->getDsnPort();
        if ($dsnPort !== null && $this->legacyDbPort === 3306) {
            $this->legacyDbPort = $dsnPort;
        }

        return parent::beforeValidate();
    }

    /**
     * Test seam (Plan 04.1-02 / D-07 follow-on). Production callers route
     * through `Plugin::getInstance()->kunstmaanEnvReader`; tests subclass
     * Settings and override this method to inject a scripted reader without
     * touching the Plugin singleton or the filesystem.
     */
    protected function getEnvReader(): KunstmaanEnvReader
    {
        return Plugin::getInstance()->kunstmaanEnvReader;
    }

    public function rules(): array
    {
        return [
            [['legacyDbServer', 'legacyDbDatabase', 'legacyDbUser'], 'string'],
            [['legacyDbPort'], 'integer'],
            [['legacyDbPassword', 'legacyDbCharset', 'legacyDbTablePrefix'], 'string'],
            [['localeMap'], 'safe'],
            // Phase 4.1 / D-24 — adapter explicit-disable booleans.
            [['seoEnabled', 'retourEnabled', 'navigationEnabled', 'translationsEnabled'], 'boolean'],
            // Phase 4 / D-57 — adapter source-table overrides.
            [['seoTableName', 'redirectsTableName', 'menuTableName', 'menuItemTableName', 'nodesTableName', 'nodeMenuNavHandle', 'translationTableName'], 'string'],
            [['nodeMenuExcludedInternalNames', 'translationDomains'], 'safe'],
        ];
    }
}
