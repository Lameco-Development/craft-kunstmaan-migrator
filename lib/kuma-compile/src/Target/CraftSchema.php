<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Target;

use Symfony\Component\Yaml\Yaml;

/**
 * The target Craft site's content model, read from its version-controlled project config.
 *
 * The authoring-time implementation of {@see TargetSchema}: it needs no running Craft, which is
 * what lets `validate`, `coverage` and `readiness` run against a target that is not installed yet.
 *
 * Without this, every question about the target has to be answered by hand in the mapping —
 * which field holds a heading, what a Matrix's nested entry type is called, whether a field
 * handle even exists. Hand-answering those is how `logos` got written for a field actually
 * called `logoSliderItems`: wrong in a way nothing catches until load time.
 *
 * Project config is the source because it is in git next to the mapping and needs no running
 * Craft. It can lag the database when `project-config/apply` has not run, so it is a default
 * rather than the only possible source.
 */
final class CraftSchema implements TargetSchema
{
    /**
     * @param array<string, array<string, Slot>> $entryTypes handle => field handle => slot
     * @param list<string> $sections
     */
    private function __construct(
        private readonly array $entryTypes,
        private readonly array $sections,
    ) {
    }

    public static function fromProjectConfig(string $craftRoot): self
    {
        $root = rtrim($craftRoot, '/');
        $dir = is_dir($root . '/config/project') ? $root . '/config/project' : $root;

        if (!is_dir($dir . '/entryTypes')) {
            throw new \RuntimeException(sprintf('No Craft project config at %s', $dir));
        }

        $fields = self::readFields($dir);
        $layouts = [];

        foreach (glob($dir . '/entryTypes/*.yaml') ?: [] as $file) {
            $data = self::deassoc(Yaml::parseFile($file));
            $handle = (string) ($data['handle'] ?? '');

            if ($handle === '') {
                continue;
            }

            $layouts[$handle] = self::slotsOf($data, $fields);
        }

        $sections = [];

        foreach (glob($dir . '/sections/*.yaml') ?: [] as $file) {
            $data = self::deassoc(Yaml::parseFile($file));

            if (isset($data['handle'])) {
                $sections[] = (string) $data['handle'];
            }
        }

        return new self($layouts, $sections);
    }

    /** @return array<string, array{handle:?string, type:string, nested:list<string>, default:?string, propagationMethod:?string}> uid => field */
    private static function readFields(string $dir): array
    {
        $fields = [];

        foreach (glob($dir . '/fields/*.yaml') ?: [] as $file) {
            $uid = self::uidOf($file);
            $data = self::deassoc(Yaml::parseFile($file));
            $nested = [];

            foreach (($data['settings']['entryTypes'] ?? []) as $entry) {
                if (is_array($entry) && isset($entry['uid'])) {
                    $nested[] = (string) $entry['uid'];
                }
            }

            $fields[$uid] = [
                'handle' => isset($data['handle']) ? (string) $data['handle'] : null,
                'type' => self::shortType((string) ($data['type'] ?? '')),
                'nested' => $nested,
                'default' => self::defaultOf($data['settings'] ?? []),
                'propagationMethod' => isset($data['settings']['propagationMethod'])
                    ? (string) $data['settings']['propagationMethod']
                    : null,
            ];
        }

        // Nested entry-type uids resolve to handles once every entry type has been seen.
        $byUid = [];

        foreach (glob($dir . '/entryTypes/*.yaml') ?: [] as $file) {
            $data = self::deassoc(Yaml::parseFile($file));
            $byUid[self::uidOf($file)] = (string) ($data['handle'] ?? '');
        }

        foreach ($fields as &$field) {
            $field['nested'] = array_values(array_filter(array_map(
                static fn (string $uid): string => $byUid[$uid] ?? '',
                $field['nested'],
            )));
        }

        return $fields;
    }

    /**
     * The value Craft supplies itself when a fresh element arrives without this field.
     *
     * A required field is only a migration blocker if nothing fills it, and the field's own
     * default counts: Craft applies an option marked `default: true` — or a Lightswitch's
     * `default` — when the element is new and the payload omits the field. Reporting those as
     * unfilled sends someone to fix a mapping that does not need fixing.
     *
     * @param array<string, mixed> $settings
     */
    private static function defaultOf(array $settings): ?string
    {
        foreach ($settings['options'] ?? [] as $option) {
            if (is_array($option) && ($option['default'] ?? false)) {
                return (string) ($option['value'] ?? '');
            }
        }

        foreach (['default', 'defaultValue'] as $key) {
            $value = $settings[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entryType
     * @param array<string, array{handle:?string, type:string, nested:list<string>, default:?string, propagationMethod:?string}> $fields
     * @return array<string, Slot>
     */
    private static function slotsOf(array $entryType, array $fields): array
    {
        $slots = [];

        $walk = static function (mixed $node) use (&$walk, &$slots, $fields): void {
            if (is_array($node)) {
                if (str_ends_with((string) ($node['type'] ?? ''), 'CustomField')) {
                    $uid = (string) ($node['fieldUid'] ?? '');
                    $field = $fields[$uid] ?? null;

                    // A null instance handle means the placement uses the base field's own
                    // handle — the case that made an earlier attempt at this read `None`.
                    $handle = $node['handle'] ?? null;
                    $handle = $handle !== null && $handle !== '' ? (string) $handle : ($field['handle'] ?? null);

                    if ($handle !== null && $field !== null) {
                        $slots[$handle] = new Slot(
                            handle: $handle,
                            type: $field['type'],
                            required: (bool) ($node['required'] ?? false),
                            nested: $field['nested'],
                            default: $field['default'],
                            propagationMethod: $field['propagationMethod'],
                        );
                    }
                }

                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };

        $walk($entryType['fieldLayouts'] ?? []);

        return $slots;
    }

    public function hasEntryType(string $handle): bool
    {
        return isset($this->entryTypes[$handle]);
    }

    public function hasSection(string $handle): bool
    {
        return in_array($handle, $this->sections, true);
    }

    /** @return array<string, Slot> */
    public function slots(string $entryType): array
    {
        return $this->entryTypes[$entryType] ?? [];
    }

    public function slot(string $entryType, string $field): ?Slot
    {
        return $this->entryTypes[$entryType][$field] ?? null;
    }

    /** @return list<string> field handles the entry type marks required */
    public function requiredFields(string $entryType): array
    {
        $required = [];

        foreach ($this->slots($entryType) as $handle => $slot) {
            if ($slot->required) {
                $required[] = $handle;
            }
        }

        return $required;
    }

    /**
     * Where a value like a heading lands on a block.
     *
     * Returns `''` for block level, `<matrix>[0]` when the field only exists on a nested
     * entry type, and null when the block has no such field at all.
     */
    public function pathFor(string $entryType, string $field): ?string
    {
        if ($this->slot($entryType, $field) !== null) {
            return '';
        }

        foreach ($this->slots($entryType) as $handle => $slot) {
            if ($slot->type !== 'Matrix') {
                continue;
            }

            foreach ($slot->nested as $nestedType) {
                if ($this->slot($nestedType, $field) !== null) {
                    return sprintf('%s[0]', $handle);
                }
            }
        }

        return null;
    }

    /** The single nested entry type of a Matrix field, when it is unambiguous. */
    public function nestedTypeOf(string $entryType, string $field): ?string
    {
        $slot = $this->slot($entryType, $field);

        return $slot !== null && count($slot->nested) === 1 ? $slot->nested[0] : null;
    }

    private static function uidOf(string $file): string
    {
        $name = basename($file, '.yaml');
        $parts = explode('--', $name);

        return end($parts);
    }

    private static function shortType(string $type): string
    {
        $parts = explode('\\', $type);

        return end($parts);
    }

    /** Craft writes ordered maps as `{__assoc__: [[k, v], ...]}`. */
    private static function deassoc(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['__assoc__']) && is_array($value['__assoc__'])) {
            $out = [];

            foreach ($value['__assoc__'] as [$k, $v]) {
                $out[$k] = self::deassoc($v);
            }

            return $out;
        }

        return array_map(self::deassoc(...), $value);
    }
}
