<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

/**
 * One recorded disagreement between the two source mappings, kept in the mapping
 * file until someone resolves it rather than silently resolved at compile time.
 */
final readonly class Conflict
{
    public function __construct(
        public string $lane,
        public string $subject,
        public string $artifact,
        public string $spec,
        public ?string $note = null,
        public ?int $live = null,
    ) {
    }
}
