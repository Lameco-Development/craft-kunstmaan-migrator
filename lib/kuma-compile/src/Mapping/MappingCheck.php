<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

use Lameco\KumaCompile\Target\TargetCheck;
use Lameco\KumaCompile\Target\TargetSchema;

/**
 * The one answer to "may this mapping run here": shape, then the install,
 * then blocks nothing accepts, then undecided conflicts — in that order,
 * because a mapping that is not well-formed produces misleading target
 * errors, and a handle that exists can still be a pairing the write side
 * rejects silently.
 *
 * One definition, three renderers: the `mapping/check` command, the migrate
 * preflight, and the control panel's Check button all ask here — the wording
 * and the order can no longer drift between them.
 */
final class MappingCheck
{
    public function __construct(private readonly TargetSchema $target)
    {
    }

    /** @return array{0: string, 1: list<string>}|null headline and errors; null means it may run */
    public function verdict(Mapping $mapping): ?array
    {
        if ($errors = (new Schema())->validate($mapping)) {
            return ['Mapping is not well-formed', $errors];
        }

        $targetCheck = new TargetCheck($this->target);

        if ($errors = $targetCheck->check($mapping)) {
            return ['Mapping does not match this Craft install', $errors];
        }

        if ($errors = $targetCheck->blocksNoPageAccepts($mapping)) {
            return ['Blocks this Craft install accepts nowhere', $errors];
        }

        if ($conflicts = $mapping->openConflicts()) {
            return [
                sprintf('%d unresolved conflicts — set conflict.status: decided', count($conflicts)),
                array_map(static fn($c): string => sprintf('%s: %s vs %s', $c->subject, $c->artifact, $c->spec), $conflicts),
            ];
        }

        return null;
    }
}
