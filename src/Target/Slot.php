<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Target;

/** One field placement on a Craft entry type's layout. */
final readonly class Slot
{
    /**
     * @param list<string> $nested      entry type handles a Matrix field allows
     * @param ?string $default          the value Craft writes when a fresh element omits this field
     * @param ?string $propagationMethod how a Matrix shares its blocks across sites — `all` means
     *                                   one set for every site, which two locales cannot both own
     * @param list<string>|null $columns a Table field's column handles; null when the field is no
     *                                   Table or the source does not say
     * @param bool $conditional          the layout shows the field only under an element condition
     *                                   (on the field or its tab), so `required` binds only there
     */
    public function __construct(
        public string $handle,
        public string $type,
        public bool $required,
        public array $nested = [],
        public ?string $default = null,
        public ?string $propagationMethod = null,
        public ?array $columns = null,
        public bool $conditional = false,
    ) {
    }

    /**
     * A Table field's column handles, which is what a `children:` map into it keys its rows by.
     *
     * Craft holds the columns under `colN` ids with the handle as a setting — in project config
     * and on a live `Table` field alike, so both schema readers parse them here. A column with no
     * handle cannot be addressed by one, so it is left out.
     *
     * @return list<string>|null null when the field has no columns setting — no Table
     */
    public static function columnHandlesOf(mixed $columns): ?array
    {
        if (!is_array($columns)) {
            return null;
        }

        $handles = [];

        foreach ($columns as $column) {
            $handle = is_array($column) ? (string) ($column['handle'] ?? '') : '';

            if ($handle !== '') {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    public function isMatrix(): bool
    {
        return $this->type === 'Matrix';
    }

    public function isAssets(): bool
    {
        return $this->type === 'Assets';
    }

    public function isTable(): bool
    {
        return $this->type === 'Table';
    }
}
