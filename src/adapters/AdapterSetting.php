<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

/**
 * One preference an adapter owns.
 *
 * Every adapter-specific setting the plugin had lived as a literal property on
 * the global Settings model: `nodeMenuNavHandle`, `nodeMenuExcludedInternalNames`,
 * `translationDomains`, `targetVolume`. Three consequences, all of them bad.
 *
 * An adapter could not be configured without editing a class it does not own,
 * so a third-party adapter could not be configured at all — and worse, could not
 * even be *enabled*: AdapterGate read its switch with `property_exists()`, which
 * is false for any flag Settings does not literally declare, so a registered
 * adapter was gated permanently off and told the operator they had turned it off
 * via a property that does not exist.
 *
 * None of those settings appeared on the settings screen either, because the
 * template listed fields by hand. Navigation's target nav handle — the single
 * most project-specific value in the plugin — was invisible and editable only in
 * a PHP config file.
 *
 * And a lane whose whole configuration is a decision, like `globals:` choosing
 * between a nav and a global-settings field, had nowhere to record it.
 */
final class AdapterSetting
{
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_STRING = 'string';
    public const TYPE_LIST = 'list';

    /**
     * @param string $handle key within the adapter's own settings bag
     * @param string $label  what an operator calls it
     * @param string $type   one of the TYPE_* constants
     * @param mixed  $default value when the operator has not chosen one
     * @param string $instructions the sentence under the field; say what the
     *               value does to the migration, not what the field is
     * @param string|null $legacyProperty a Settings property this used to live
     *               on. Read as a fallback so a project already configured
     *               through config/kunstmaan-migrator.php keeps working.
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly string $type = self::TYPE_STRING,
        public readonly mixed $default = null,
        public readonly string $instructions = '',
        public readonly ?string $legacyProperty = null,
    ) {
    }

    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            self::TYPE_BOOLEAN => (bool) $value,
            self::TYPE_LIST => is_array($value)
                ? array_values(array_filter(array_map(trim(...), array_map(strval(...), $value)), static fn(string $v): bool => $v !== ''))
                : array_values(array_filter(array_map(trim(...), explode(',', (string) $value)), static fn(string $v): bool => $v !== '')),
            default => (string) $value,
        };
    }
}
