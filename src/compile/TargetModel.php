<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\compile;

use Lameco\KumaCompile\Payload\SchemaGateway;
use Lameco\KumaCompile\Target\Slot;
use Lameco\KumaCompile\Target\TargetSchema;

/**
 * The `TargetSchema` a migration run answers from: the live Craft site.
 *
 * Its sibling, `CraftSchema`, parses `config/project/**` so authoring works before a target
 * is installed. That parse can lag an unapplied project config, which is exactly the wrong
 * answer during a load — so here the questions go through the same gateway the validator and
 * saver already use, and "does this handle exist" has one answer rather than two.
 *
 * The gateway is an interface, so this stays testable against a fake without booting Craft.
 */
final class TargetModel implements TargetSchema
{
    /** @var array<string, array<string, Slot>> */
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

    public function slot(string $entryType, string $field): ?Slot
    {
        return $this->slots($entryType)[$field] ?? null;
    }

    /** @return array<string, Slot> */
    public function slots(string $entryType): array
    {
        return $this->slots[$entryType] ??= self::toSlots($this->gateway->fieldSlotsFor($entryType));
    }

    /** @return list<string> */
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
     * Where a value such as a heading lands on a block: `''` at block level,
     * `<matrix>[0]` when the field only exists on a nested entry type, null when the block
     * has no such field anywhere.
     */
    public function pathFor(string $entryType, string $field): ?string
    {
        if ($this->slot($entryType, $field) !== null) {
            return '';
        }

        foreach ($this->slots($entryType) as $handle => $slot) {
            foreach ($slot->nested as $nested) {
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

        return $slot !== null && count($slot->nested) === 1 ? $slot->nested[0] : null;
    }

    /**
     * A live field layout carries no notion of the value Craft writes when a payload omits a
     * field — that is a project-config reading, and `Readiness` is the only caller that wants
     * it. Loading never asks, so the slot's default stays null here.
     *
     * @param array<string, array{type: string, required: bool, nested: list<string>, propagationMethod?: ?string}> $slots
     * @return array<string, Slot>
     */
    private static function toSlots(array $slots): array
    {
        $out = [];

        foreach ($slots as $handle => $slot) {
            $out[$handle] = new Slot(
                handle: $handle,
                type: $slot['type'],
                required: $slot['required'],
                nested: $slot['nested'],
                propagationMethod: $slot['propagationMethod'] ?? null,
            );
        }

        return $out;
    }
}
