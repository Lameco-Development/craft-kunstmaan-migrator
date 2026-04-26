<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;

/**
 * Plugin Settings — shared seam between env vars, config/kunstmaan-migrator.php,
 * and the (Phase 4) CP Settings page. Phase 1 reads only the legacyDb* fields and
 * anthropicApiKey; the rest are declared upfront per D-15 so Phase 4 / CFG-01
 * plugs in without a refactor.
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

    // Anthropic key (D-14). Defaults to ANTHROPIC_API_KEY.
    public ?string $anthropicApiKey      = null;

    // Phase 2-4 fields (D-15) — declared, unused until later phases.
    public ?string $llmModel             = null;
    public ?int    $llmTimeout           = null;
    public ?int    $llmInterChunkDelay   = null;
    public ?string $mappingPath          = null;
    // Phase 02.1 / D-30 (Kunstmaan source path). Defaults to KUNSTMAAN_SOURCE_PATH env.
    public ?string $kunstmaanSourcePath  = null;
    public array   $defaultEntities      = [];
    public array   $defaultLocales       = [];

    /**
     * Explicit locale override map: legacy Kunstmaan locale → Craft site handle.
     * Wins over both exact-match and language-prefix loose-match. Use when a
     * single legacy locale needs to land on a specific Craft handle (e.g.
     * `['nl' => 'nl-NL']` when Craft uses BCP 47 long-form handles).
     *
     * @var array<string, string>
     */
    public array   $localeMap            = [];
    public ?string $defaultSince         = null;
    public ?int    $defaultMaxPerEntity  = null;
    public bool    $dryRunDefault        = true;

    // Phase 4 / D-60 — verify-stage tolerances. Defaults: ±1% count tolerance,
    // 5% URL-diff threshold. CLI `--count-tolerance` overrides at controller seam.
    public float $verifyCountTolerance = 0.01;
    public float $verifyUrlDiffThreshold = 0.05;

    // Phase 4 / D-57 — adapter source-table overrides for non-CQM Kunstmaan
    // flavours. Defaults match the canonical kuma_* schema; operators flip via
    // env vars or config/kunstmaan-migrator.php when the legacy DB diverges.
    public string $seoTableName = 'kuma_seo';
    public string $redirectsTableName = 'kuma_redirects';

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'legacyDbServer', 'legacyDbDatabase', 'legacyDbUser', 'legacyDbPassword',
                    'legacyDbCharset', 'legacyDbTablePrefix',
                    'anthropicApiKey',
                    'llmModel', 'mappingPath', 'defaultSince',
                    'kunstmaanSourcePath',
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
        // D-14: ANTHROPIC_API_KEY env fallback. Settings property override wins when present.
        // Never logged by this class; doctor reports presence only (T-1-03).
        $this->anthropicApiKey ??= App::env('ANTHROPIC_API_KEY') ?: null;
        // D-30: Kunstmaan source-checkout path (Phase 02.1). KUNSTMAAN_SOURCE_PATH env
        // fallback; Settings property override wins. Resolver validates the path
        // (realpath + is_dir + src/Entity/) — see KunstmaanSourcePathResolver.
        $this->kunstmaanSourcePath ??= App::env('KUNSTMAAN_SOURCE_PATH') ?: null;
    }

    public function rules(): array
    {
        return [
            [['legacyDbServer', 'legacyDbDatabase', 'legacyDbUser'], 'string'],
            [['legacyDbPort'], 'integer'],
            [['legacyDbPassword', 'legacyDbCharset', 'legacyDbTablePrefix'], 'string'],
            [['anthropicApiKey', 'llmModel', 'mappingPath', 'defaultSince', 'kunstmaanSourcePath'], 'string'],
            [['llmTimeout', 'llmInterChunkDelay', 'defaultMaxPerEntity'], 'integer'],
            [['defaultEntities', 'defaultLocales', 'localeMap'], 'safe'],
            [['dryRunDefault'], 'boolean'],
            // Phase 4 / D-60 — verify-stage tolerances pinned to [0, 1].
            [['verifyCountTolerance', 'verifyUrlDiffThreshold'], 'number', 'min' => 0, 'max' => 1],
            // Phase 4 / D-57 — adapter source-table overrides.
            [['seoTableName', 'redirectsTableName'], 'string'],
        ];
    }
}
