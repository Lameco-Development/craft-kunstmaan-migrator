<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use lameco\kunstmaanmigrator\payload\SchemaGateway;

/**
 * The questions the compile side asks about the target content model.
 *
 * This replaces a parse of `config/project/**`. Reading the live schema through the same
 * gateway the validator and saver already use means there is one answer to "does this handle
 * exist" rather than two that can disagree — and it cannot lag a project config that has not
 * been applied.
 *
 * The gateway is an interface, so everything here stays testable against a fake without
 * booting Craft.
 */
final class TargetModel
{
    /** @var array<string, array<string, array{type: string, required: bool, nested: list<string>}>> */
    private array $slots = [];

    public function __construct(private readonly SchemaGateway $gateway)
    {
    }

    public function hasEntryType(string $handle): bool
    {
        return $this->gateway->entryTypeByHandle($handle) !== null;
    }

    public function hasSection(string $handle): bool
    {
        return $this->gateway->sectionByHandle($handle) !== null;
    }

    /** @return array{type: string, required: bool, nested: list<string>}|null */
    public function slot(string $entryType, string $field): ?array
    {
        return $this->slotsOf($entryType)[$field] ?? null;
    }

    /** @return array<string, array{type: string, required: bool, nested: list<string>}> */
    public function slotsOf(string $entryType): array
    {
        return $this->slots[$entryType] ??= $this->gateway->fieldSlotsFor($entryType);
    }

    /** @return list<string> */
    public function requiredFields(string $entryType): array
    {
        $required = [];

        foreach ($this->slotsOf($entryType) as $handle => $slot) {
            if ($slot['required']) {
                $required[] = $handle;
            }
        }

        return $required;
    }

    /**
     * Where a value such as a heading lands on a block: `''` at block level,
     * `<matrix>[0]` when the field only exists on a nested entry type, null when the block
     * has no such field anywhere.
     */
    public function pathFor(string $entryType, string $field): ?string
    {
        if ($this->slot($entryType, $field) !== null) {
            return '';
        }

        foreach ($this->slotsOf($entryType) as $handle => $slot) {
            foreach ($slot['nested'] as $nested) {
                if ($this->slot($nested, $field) !== null) {
                    return sprintf('%s[0]', $handle);
                }
            }
        }

        return null;
    }

    /** The nested entry type of a Matrix field, when it is unambiguous. */
    public function nestedTypeOf(string $entryType, string $field): ?string
    {
        $slot = $this->slot($entryType, $field);

        return $slot !== null && count($slot['nested']) === 1 ? $slot['nested'][0] : null;
    }
}
