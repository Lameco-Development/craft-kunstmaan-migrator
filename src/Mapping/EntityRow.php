<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * One `entities:` row — a non-node legacy table that becomes entries of its own.
 */
final class EntityRow
{
    private const DEFAULT_TITLE = 'title';

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

    /** A table to read and an entry type to become — the lane compiles nothing less. */
    public function compiles(): bool
    {
        return $this->table() !== null && $this->entryType() !== null;
    }

    public function table(): ?string
    {
        return $this->string('table');
    }

    public function section(): ?string
    {
        return $this->string('section');
    }

    public function entryType(): ?string
    {
        return $this->string('entryType');
    }

    /** The legacy column that becomes the entry title. */
    public function titleColumn(): string
    {
        return $this->string('title') ?? self::DEFAULT_TITLE;
    }

    /** Whether rows with the same id across environments are one entry. */
    public function dedupe(): bool
    {
        return ($this->spec['dedupe'] ?? false) === true;
    }

    /** Whether the row merges into the section's one existing entry rather than adding one. */
    public function single(): bool
    {
        return ($this->spec['single'] ?? false) === true;
    }

    /** The soft-delete column rows are filtered on, when the table has one. */
    public function softDelete(): ?string
    {
        return $this->string('softDelete');
    }

    public function live(): ?int
    {
        return isset($this->spec['live']) ? (int) $this->spec['live'] : null;
    }

    /** @return array<string, mixed> Craft field => legacy column expression */
    public function map(): array
    {
        return $this->arrayOf('map');
    }

    /** @return array<string, array<string, mixed>> Matrix field => child collection spec */
    public function children(): array
    {
        return array_filter($this->arrayOf('children'), is_array(...));
    }

    private function string(string $key): ?string
    {
        $value = $this->spec[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function arrayOf(string $key): array
    {
        $value = $this->spec[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
