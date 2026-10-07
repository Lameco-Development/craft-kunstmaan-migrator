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
     */
    public function __construct(
        public string $handle,
        public string $type,
        public bool $required,
        public array $nested = [],
        public ?string $default = null,
        public ?string $propagationMethod = null,
        public ?array $columns = null,
    ) {
    }

    public function isMatrix(): bool
    {
        return $this->type === 'Matrix';
    }
}
