<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Target;

/** One field placement on a Craft entry type's layout. */
final readonly class Slot
{
    /**
     * @param list<string> $nested  entry type handles a Matrix field allows
     * @param ?string $default      the value Craft writes when a fresh element omits this field
     */
    public function __construct(
        public string $handle,
        public string $type,
        public bool $required,
        public array $nested = [],
        public ?string $default = null,
    ) {
    }

    public function isMatrix(): bool
    {
        return $this->type === 'Matrix';
    }
}
