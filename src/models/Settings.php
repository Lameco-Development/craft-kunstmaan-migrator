<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\models;

use craft\base\Model;
use lameco\kunstmaanmigrator\adapters\Adapter;
use lameco\kunstmaanmigrator\adapters\AdapterSetting;
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
    /**
     * The legacy checkout this migration reads — filled by the wizard's detect step.
     * Powers the media-root prefill and source introspection; the database
     * credentials it carried were copied into the legacyDb* settings below.
     */
    public ?string $legacySourcePath     = null;

    /**
     * Where the old site's uploads live. Every Kunstmaan site here keeps them
     * at `public/uploads/media`, so this is derived from the detected checkout
     * and only needs a value when a site breaks that convention.
     */
    public ?string $legacyMediaRoot      = null;

    /**
     * Directory of content-model block specs whose migration notes drive the
     * mapping prefill. Empty means auto-detect `docs/content-model/page-builder`
     * under the project root.
     */
    public ?string $specsPath            = null;

    public ?string $legacyDbServer       = null;
    public int     $legacyDbPort         = 3306;
    public ?string $legacyDbDatabase     = null;
    public ?string $legacyDbUser         = null;
    public ?string $legacyDbPassword     = null;
    public string  $legacyDbCharset      = 'utf8mb4';
    public string  $legacyDbTablePrefix  = '';

    /**
     * Where the mapping YAML lives.
     *
     * The mapping owns the migration's topology — which databases exist, where
     * each one's uploads are, and which legacy locale writes to which Craft
     * site. Those belong in a version-controlled file next to the field
     * mappings they travel with, not in a settings form. This is the one
     * pointer the control panel needs in order to read and show them.
     */
    public string $mappingPath = '';

    /**
     * Craft volume handle assets land in when migrated. Defaults to
     * `uploads` — the starter-kit convention. Scaffolder-generated targets
     * use `media` (matches Kunstmaan's `kuma_media` semantics); override
     * via `config/kunstmaan-migrator.php` to align with the actual handle.
     */
    public string  $targetVolume         = 'uploads';

    /**
     * Subfolder within the volume. Assets land at `{subfolder}/{year}/filename`;
     * empty puts them at the volume root.
     */
    public string  $targetSubfolder      = 'migrated';

    /**
     * How the path below the subfolder is built: `year` or `legacy-tree`.
     *
     * `year` buckets by the file's own `created_at`, which is a fact about the file that no
     * editor has ever gone looking for. `legacy-tree` mirrors the `kuma_folders` chain the file
     * sat in, so the client's own media organisation survives the migration — rooted per
     * environment when the corpus has more than one legacy source, because three installs each
     * ship a folder named `Media/Afbeeldingen`.
     *
     * Defaults to `year` so an existing project's asset paths do not move under it.
     */
    public string  $assetFolderStrategy  = 'year';

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

    /**
     * The `forms:` lane. A built-in keeps a literal property like the other
     * four — it is part of the plugin's own surface, so it belongs in project
     * config as a first-class switch. The generic `adapters` bag is the path for
     * adapters the plugin does not ship, not the default for the ones it does.
     */
    public bool $formsEnabled = true;

    /** The `globals:` lane — the legacy footer, written into navs. */
    public bool $globalsEnabled = true;

    /**
     * Adapter-owned preferences: adapter handle => setting handle => value.
     *
     * A generic bag rather than more literal properties, because a literal
     * property can only be added by editing this class — which an adapter
     * shipped by a project cannot do. The keys an adapter may use are the ones
     * it declares as AdapterSetting; anything else is ignored on read.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $adapters = [];

    /**
     * One adapter's configuration, resolved.
     *
     * Precedence, first hit wins: the stored value, the legacy Settings property
     * the setting used to live on (so a project configured through
     * config/kunstmaan-migrator.php keeps working), then the declared default.
     *
     * @return array<string, mixed>
     */
    public function forAdapter(Adapter $adapter): array
    {
        $stored = $this->adapters[$adapter->handle] ?? [];
        $out = [];

        foreach ($adapter->settings as $setting) {
            if (array_key_exists($setting->handle, $stored)) {
                $out[$setting->handle] = $setting->cast($stored[$setting->handle]);

                continue;
            }

            $legacy = $setting->legacyProperty;

            if ($legacy !== null && property_exists($this, $legacy) && !$this->isPropertyDefault($legacy)) {
                $out[$setting->handle] = $setting->cast($this->$legacy);

                continue;
            }

            $out[$setting->handle] = $setting->default;
        }

        return $out;
    }

    /**
     * Whether this adapter's switch is on, resolved the same way AdapterGate
     * resolves it — so the control panel cannot show a state the run disagrees
     * with. The built-in four keep literal properties; anything else lives in
     * the bag and defaults to on.
     */
    public function isAdapterEnabled(Adapter $adapter): bool
    {
        $flag = $adapter->settingsFlag;

        if (property_exists($this, $flag)) {
            return (bool) $this->$flag;
        }

        $stored = $this->adapters[$adapter->handle][$flag] ?? null;

        return $stored === null ? true : (bool) $stored;
    }

    /**
     * The form field name the switch posts to: a literal property for the
     * built-ins, the adapter's own bag for everything else.
     */
    public function adapterEnabledInputName(Adapter $adapter): string
    {
        return property_exists($this, $adapter->settingsFlag)
            ? $adapter->settingsFlag
            : sprintf('adapters[%s][%s]', $adapter->handle, $adapter->settingsFlag);
    }

    /**
     * Whether a legacy property still holds the value it was declared with.
     *
     * Without this a legacy default would beat an adapter's declared default and
     * the two could silently disagree — the migration would follow whichever was
     * written first rather than whichever the operator meant.
     */
    private function isPropertyDefault(string $property): bool
    {
        static $defaults = null;
        $defaults ??= get_class_vars(self::class);

        return ($defaults[$property] ?? null) === $this->$property;
    }

    /**
     * The plugin's own settings, declared so the screen can render them.
     *
     * These are not adapter settings — assets are written by the entry pass,
     * not by a pass that runs after it — so they bind to real Settings
     * properties rather than to the `adapters` bag, and the services that
     * already read those properties are untouched. What the declaration buys is
     * the same thing it buys an adapter: the screen renders what is declared
     * instead of a list typed into a template, so adding a setting is one line
     * in one place.
     *
     * `targetVolume` in particular has spent this plugin's life invisible while
     * defaulting to `uploads` and documenting that scaffolder-generated targets
     * use `media` — which is a first-run migration into the wrong volume, and
     * exactly the kind of thing an operator should not need to read PHP to find.
     *
     * @return list<AdapterSetting>
     */
    public static function coreSettings(): array
    {
        return [
            new AdapterSetting(
                'assetFolderStrategy',
                'Asset folder structure',
                AdapterSetting::TYPE_STRING,
                'year',
                '`year` buckets migrated assets by their own created date. `legacy-tree` mirrors the '
                . 'Kunstmaan folder structure instead, rooted per environment when the corpus has more '
                . 'than one legacy source.',
            ),
            new AdapterSetting(
                'targetVolume',
                'Asset volume',
                AdapterSetting::TYPE_STRING,
                'uploads',
                'The Craft volume migrated assets land in. Scaffolder-generated targets use `media`; '
                . 'the starter kit uses `uploads`. Getting this wrong migrates every asset into the wrong place.',
            ),
            new AdapterSetting(
                'targetSubfolder',
                'Asset subfolder',
                AdapterSetting::TYPE_STRING,
                'migrated',
                'Assets land at `{subfolder}/{year}/filename`. Leave empty to write to the volume root.',
            ),
            new AdapterSetting(
                'skipAssetSizeValidation',
                'Ignore asset size limits',
                AdapterSetting::TYPE_BOOLEAN,
                false,
                'A project-side size cap that is right for editor uploads will reject valid legacy assets. '
                . 'With this on, an oversized asset is skipped and reported instead of taking the whole entry down.',
            ),
        ];
    }

    /**
     * A core setting's current value, resolved the same way the screen shows it.
     */
    public function core(AdapterSetting $setting): mixed
    {
        return property_exists($this, $setting->handle)
            ? $setting->cast($this->{$setting->handle})
            : $setting->default;
    }

    /**
     * The attributes that may hold `$VAR` or `@alias` rather than a value.
     *
     * Named once: the behavior needs the list, and so does beforeValidate(),
     * which captures what the operator typed before the behavior resolves it.
     */
    private const ENV_ATTRIBUTES = [
        'legacyDbServer', 'legacyDbDatabase', 'legacyDbUser', 'legacyDbPassword',
        'legacyDbCharset', 'legacyDbTablePrefix', 'mappingPath',
    ];

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => self::ENV_ATTRIBUTES,
            ],
        ];
    }

    /**
     * The legacy connection, resolved.
     *
     * Deliberately NOT applied to the properties. Craft persists this model to
     * project config, so anything written onto an attribute is a value headed
     * for git — which is how the settings screen came to arrive pre-filled with
     * a password nobody typed, and then refused to save it. The properties hold
     * what the operator configured; this resolves it for the code that connects.
     *
     * Precedence, first hit wins: the stored setting (with `$VAR` expanded),
     * CRAFT_LEGACY_DB_*, KUMA_DB_* — which is what `migrate` read directly
     * before this model governed the connection — then the Kunstmaan project's
     * own .env DATABASE_URL, then the MySQL default.
     *
     * @return array{host: string, port: int, user: string, password: string, charset: string, tablePrefix: string}
     */
    public function legacyConnection(): array
    {
        $dsn = $this->kunstmaanDsn();

        return [
            'host' => $this->resolve($this->legacyDbServer, 'CRAFT_LEGACY_DB_SERVER', 'KUMA_DB_HOST')
                ?? $dsn['host'] ?? '127.0.0.1',
            'port' => (int) ($this->legacyDbPort !== 3306
                ? $this->legacyDbPort
                : ($this->resolve(null, 'CRAFT_LEGACY_DB_PORT', 'KUMA_DB_PORT') ?? $dsn['port'] ?? 3306)),
            'user' => $this->resolve($this->legacyDbUser, 'CRAFT_LEGACY_DB_USER', 'KUMA_DB_USER')
                ?? $dsn['user'] ?? 'root',
            'password' => $this->resolve($this->legacyDbPassword, 'CRAFT_LEGACY_DB_PASSWORD', 'KUMA_DB_PASSWORD')
                ?? $dsn['password'] ?? '',
            'charset' => $this->resolve($this->legacyDbCharset, 'CRAFT_LEGACY_DB_CHARSET', null) ?? 'utf8mb4',
            'tablePrefix' => $this->resolve($this->legacyDbTablePrefix, 'CRAFT_LEGACY_DB_TABLE_PREFIX', null) ?? '',
        ];
    }

    /**
     * A stored value beats an env var, and an empty string is an operator
     * saying "none" rather than an operator saying nothing.
     */
    private function resolve(?string $stored, string $primaryEnv, ?string $fallbackEnv): ?string
    {
        if ($stored !== null && $stored !== '') {
            $parsed = App::parseEnv($stored);

            return is_string($parsed) && $parsed !== '' ? $parsed : null;
        }

        $value = App::env($primaryEnv) ?: ($fallbackEnv !== null ? App::env($fallbackEnv) : null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The Kunstmaan project's own DATABASE_URL, read once.
     *
     * D-07 kept this so a migration needs no configuration at all when the
     * legacy checkout is on the same machine. It used to fill the properties
     * during validation, which meant a control-panel save wrote the legacy
     * project's credentials into project config.
     *
     * @return array{host?: string, port?: int, user?: string, password?: string, database?: string}
     */
    private function kunstmaanDsn(): array
    {
        try {
            $reader = $this->getEnvReader();

            if (($reader->getDatabaseUrl() ?: '') === '') {
                return [];
            }
        } catch (\Throwable) {
            // No legacy checkout to read, or no application to reach it through.
            // That is a missing convenience, not a failure — the settings screen
            // must still render and the configured values still resolve.
            return [];
        }

        return array_filter([
            'host' => $reader->getDsnHost(),
            'port' => $reader->getDsnPort(),
            'user' => $reader->getDsnUser(),
            'password' => $reader->getDsnPassword(),
            'database' => $reader->getDsnDatabase(),
        ], static fn ($value): bool => $value !== null);
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

    /**
     * A legacy-database password must name an environment variable, never carry one.
     *
     * Craft writes plugin settings into project config, which is committed and
     * deployed. A password typed into the settings screen would therefore end
     * up in git and on production — for a tool that only ever runs locally,
     * against a database only this machine can reach.
     *
     * `EnvAttributeParserBehavior` already resolves `$VAR` for every read, so
     * storing the name costs nothing.
     */
    public function validateIsEnvReference(string $attribute): void
    {
        // Read what the operator typed, not what it resolves to.
        //
        // EnvAttributeParserBehavior swaps every parsed attribute for its
        // resolved value in beforeValidate() and puts the original back in
        // afterValidate(). So by the time this runs, `$KUMA_DB_PASSWORD` has
        // already become the password itself — and this validator, whose whole
        // job is to reject a literal password, rejected it. The settings screen
        // could not be saved by any project that had done what the field asks.
        if ($this->unparsed($attribute) !== null) {
            return;
        }

        $value = $this->$attribute;

        if (!is_string($value) || $value === '' || str_starts_with($value, '$')) {
            return;
        }

        $this->addError(
            $attribute,
            'Use an environment variable name such as $CRAFT_LEGACY_DB_PASSWORD. '
            . 'A value here would be written into project config, committed, and deployed.',
        );
    }

    /**
     * An environment variable that does not exist is almost always a typo, and
     * one that is invisible until a run fails to connect — `$KUMA_DB_PASSWORDd`
     * saves cleanly, reads as empty, and reports as "cannot connect" an hour
     * later. Checking at save time is where it costs nothing.
     */
    public function validateEnvReferenceResolves(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_string($value) || !str_starts_with($value, '$')) {
            return;
        }

        $name = substr($value, 1);

        if ($name === '' || App::env($name) !== null) {
            return;
        }

        $this->addError(
            $attribute,
            sprintf('%s is not set in this environment — check the spelling, or add it to your .env.', $value),
        );
    }

    /**
     * @var array<string, string> what the operator typed, captured before the
     *      env parser swaps in resolved values
     */
    private array $typedValues = [];

    /**
     * Yii calls this, then triggers EVENT_BEFORE_VALIDATE — which is where
     * EnvAttributeParserBehavior replaces every parsed attribute with its
     * resolved value. So this is the last moment the typed value still exists,
     * and capturing it here is what lets validateIsEnvReference tell
     * `$KUMA_DB_PASSWORD` from the password it stands for.
     *
     * The behavior exposes getUnparsedAttribute() for the same purpose, but
     * reaching it means getBehavior(), which instantiates behaviors through the
     * Yii container — unavailable in this suite, and the point of a validator is
     * that it can be tested.
     */
    public function beforeValidate(): bool
    {
        $this->captureTypedValues();

        return parent::beforeValidate();
    }

    /**
     * Separate from beforeValidate() only so it is reachable without a Yii
     * application: parent::beforeValidate() triggers the event that attaches
     * behaviours, which needs the container this suite does not have.
     */
    public function captureTypedValues(): void
    {
        foreach (self::ENV_ATTRIBUTES as $attribute) {
            $this->typedValues[$attribute] = (string) ($this->$attribute ?? '');
        }
    }

    /**
     * What the operator typed for an attribute, before `$VAR` and `@alias` were
     * resolved — or null when validation is not running and nothing was captured.
     */
    private function unparsed(string $attribute): ?string
    {
        $typed = $this->typedValues[$attribute] ?? null;

        return $typed !== null && str_starts_with($typed, '$') ? $typed : null;
    }

    public function rules(): array
    {
        return [
            [['legacyDbServer', 'legacyDbDatabase', 'legacyDbUser', 'legacyMediaRoot', 'specsPath'], 'string'],
            [['legacyDbPort'], 'integer'],
            [['legacyDbPassword', 'legacyDbCharset', 'legacyDbTablePrefix'], 'string'],
            [['legacyDbPassword'], 'validateIsEnvReference'],
            [
                ['legacyDbServer', 'legacyDbUser', 'legacyDbPassword', 'legacyDbCharset', 'mappingPath'],
                'validateEnvReferenceResolves',
            ],
            // Phase 4.1 / D-24 — adapter explicit-disable booleans.
            [['seoEnabled', 'retourEnabled', 'navigationEnabled', 'translationsEnabled', 'formsEnabled', 'globalsEnabled'], 'boolean'],
            [['nodeMenuNavHandle', 'mappingPath', 'targetVolume', 'targetSubfolder'], 'string'],
            [['assetFolderStrategy'], 'in', 'range' => ['year', 'legacy-tree']],
            [['skipAssetSizeValidation'], 'boolean'],
            [['nodeMenuExcludedInternalNames', 'translationDomains', 'adapters'], 'safe'],
        ];
    }
}
