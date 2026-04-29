<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\source\KunstmaanEnvReader;

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

    /**
     * Phase 6 — graceful-fallback handles for the AI mapping flow. When the
     * entity-level LLM step returns low/empty confidence for a given Kunstmaan
     * Page FQCN (or page-part), the compiler falls back to these handles
     * instead of letting the row die at load time. The compiled mapping flags
     * the fallback in its compile report so the operator can review.
     *
     * Both null by default → keeps the existing fail-loud behavior. Set to a
     * real Craft entry-type / block-type handle to opt in to graceful fallback.
     *
     * Typical values for cqm-style projects: defaultEntryType="contentPage",
     * defaultBlockType="textContentBlock" (or whatever generic catch-all the
     * project's Craft schema provides).
     */
    public ?string $defaultEntryType     = null;
    public ?string $defaultBlockType     = null;

    /**
     * Optional operator overrides for generic rich-text fallback blocks.
     *
     * Shape:
     * [
     *   'pageBuilder' => ['blockType' => 'generalContentBlock', 'fieldHandle' => 'ckeditorDefault'],
     * ]
     *
     * Intended for config/kunstmaan-migrator.php when a site's Craft schema has
     * ambiguous Matrix block names and the introspection heuristic needs a hint.
     *
     * @var array<string, array{blockType?: string, fieldHandle?: string}>
     */
    public array $genericContentBlockOverrides = [];

    // Phase 4 / D-60 — verify-stage tolerances. Defaults: ±1% count tolerance,
    // 5% URL-diff threshold. CLI `--count-tolerance` overrides at controller seam.
    public float $verifyCountTolerance = 0.01;
    public float $verifyUrlDiffThreshold = 0.05;

    // Phase 4 / D-57 — adapter source-table overrides for non-CQM Kunstmaan
    // flavours. Defaults match the canonical kuma_* schema; operators flip via
    // env vars or config/kunstmaan-migrator.php when the legacy DB diverges.
    public string $seoTableName = 'kuma_seo';
    public string $redirectsTableName = 'kuma_redirects';

    // Phase 4.1 / D-24 — adapter explicit-disable. Defaults to true so existing
    // operators see no behavior change; flip to false to skip the adapter even
    // when the plugin IS installed. CLI --no-seo / --no-retour bypass per-run.
    public bool $seoEnabled = true;
    public bool $retourEnabled = true;

    // Phase 8 / D-14 — AI proposer scope gates. Defaults to true (proposers run);
    // flip to false to disable per Settings persistence. CLI --no-layout / --no-providers
    // bypass per-run.
    public bool $proposeLayout = true;
    public bool $proposeProviders = true;

    // Phase 8.5 / D-24 — optional Doctrine ManyToOne FK relation expansion.
    // Defaults false so extracted JSON stays source-faithful: raw FK columns
    // such as `employee_id` are present, while synthetic `_rel:<prop>.<col>`
    // helper columns are opt-in for operator/debug workflows.
    public bool $joinFkRelations = false;

    // Phase 10 — full taxonomy vocabulary import is opt-in. Default false keeps
    // canonical migration page-driven/referenced-only; CLI
    // --include-unreferenced-taxonomies can enable it per run.
    public bool $includeUnreferencedTaxonomies = false;

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
            [['anthropicApiKey', 'llmModel', 'mappingPath', 'defaultSince', 'kunstmaanSourcePath', 'defaultEntryType', 'defaultBlockType'], 'string'],
            [['llmTimeout', 'llmInterChunkDelay', 'defaultMaxPerEntity'], 'integer'],
            [['defaultEntities', 'defaultLocales', 'localeMap', 'genericContentBlockOverrides'], 'safe'],
            [['dryRunDefault'], 'boolean'],
            // Phase 4.1 / D-24 — adapter explicit-disable booleans.
            // Phase 8 / D-14 — AI proposer scope gates (proposeLayout, proposeProviders).
            // Phase 8.5 / D-24 — joinFkRelations (Doctrine ManyToOne join gate).
            [['seoEnabled', 'retourEnabled', 'proposeLayout', 'proposeProviders', 'joinFkRelations', 'includeUnreferencedTaxonomies'], 'boolean'],
            // Phase 4 / D-60 — verify-stage tolerances pinned to [0, 1].
            [['verifyCountTolerance', 'verifyUrlDiffThreshold'], 'number', 'min' => 0, 'max' => 1],
            // Phase 4 / D-57 — adapter source-table overrides.
            [['seoTableName', 'redirectsTableName'], 'string'],
        ];
    }
}
