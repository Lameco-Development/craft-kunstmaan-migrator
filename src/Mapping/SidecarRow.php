<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * One `sidecars:` row — a per-page entity joined through Kunstmaan's polymorphic
 * `(ref_entity_name, ref_id)` pair. It has no target of its own: its map addresses
 * the fields of the page it decorates.
 */
final class SidecarRow
{
    /** @param array<string, mixed> $spec the row as the file holds it */
    private function __construct(
        public readonly string $name,
        public readonly array $spec,
    ) {
    }

    public static function fromSpec(string $name, mixed $spec): self
    {
        return new self($name, is_array($spec) ? $spec : []);
    }

    /** `manual:` and `drop:` both take the sidecar out of the run. */
    public function isMigrated(): bool
    {
        return !isset($this->spec['manual']) && !isset($this->spec['drop']);
    }

    public function table(): ?string
    {
        $value = $this->spec['table'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> page field => legacy column expression */
    public function map(): array
    {
        return $this->arrayOf('map');
    }

    /** @return array<string, array<string, mixed>> Matrix field => child collection spec */
    public function children(): array
    {
        return array_filter($this->arrayOf('children'), is_array(...));
    }

    /** @return array<string, mixed> */
    private function arrayOf(string $key): array
    {
        $value = $this->spec[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
