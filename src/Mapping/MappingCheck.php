<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

use Lameco\Kunstmaanmigrator\Report\IntrospectionCheck;
use Lameco\Kunstmaanmigrator\Report\SpecDivergence;
use Lameco\Kunstmaanmigrator\Source\Introspection;
use Lameco\Kunstmaanmigrator\Target\SpecNotes;
use Lameco\Kunstmaanmigrator\Target\TargetCheck;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;

/**
 * The one answer to "may this mapping run here": shape, then the install,
 * then blocks nothing accepts, then spec divergence, then undecided
 * conflicts — in that order, because a mapping that is not well-formed
 * produces misleading target errors, and a handle that exists can still be
 * a pairing the write side rejects silently.
 *
 * One definition, four renderers: the `mapping/check` command, the
 * standalone `kuma-compile validate`, the migrate preflight, and the
 * control panel's Check button all ask here — the wording and the order can
 * no longer drift between them. Without a target schema (the standalone CLI
 * before a Craft project exists) the verdict covers what is checkable:
 * shape and conflicts.
 */
final class MappingCheck
{
    public function __construct(private readonly ?TargetSchema $target = null)
    {
    }

    /** @return array{0: string, 1: list<string>}|null headline and errors; null means it may run */
    public function verdict(Mapping $mapping, SpecNotes ...$specNotes): ?array
    {
        if ($specNotes !== [] && $this->target === null) {
            throw new MappingException('Spec divergence needs a target schema: the built content model is what says which of the spec\'s fields exist.');
        }

        if ($errors = (new Schema())->validate($mapping)) {
            return ['Mapping is not well-formed', $errors];
        }

        if ($this->target !== null) {
            $targetCheck = new TargetCheck($this->target);

            if ($errors = $targetCheck->check($mapping)) {
                return ['Mapping does not match this Craft install', $errors];
            }

            if ($errors = $targetCheck->blocksNoPageAccepts($mapping)) {
                return ['Blocks this Craft install accepts nowhere', $errors];
            }

            $divergences = [];

            foreach ($specNotes as $notes) {
                $divergences = [...$divergences, ...(new SpecDivergence($mapping, $notes, $this->target))->divergences()];
            }

            if ($divergences !== []) {
                return ['Mapping diverges from the content-model specs', $divergences];
            }
        }

        if ($conflicts = $mapping->openConflicts()) {
            return [
                sprintf('%d unresolved conflicts — set conflict.status: decided', count($conflicts)),
                array_map(static fn($c): string => sprintf('%s: %s vs %s', $c->subject, $c->artifact, $c->spec), $conflicts),
            ];
        }

        return null;
    }

    /**
     * The non-blocking findings: pages with no block field, required fields
     * nothing fills, markup aimed at a field that cannot render it, and —
     * given an introspection artifact — the legacy app's own wiring the
     * mapping contradicts.
     *
     * None of these refuse a run, and all of them predict content arriving
     * wrong rather than not arriving, which is the kind of defect a migration
     * ships without noticing.
     *
     * @return list<string>
     */
    public function warnings(Mapping $mapping, ?Introspection $introspection = null): array
    {
        $warnings = [];

        if ($this->target !== null) {
            $targetCheck = new TargetCheck($this->target);
            $warnings = [
                ...$targetCheck->pagesWithNoBlockField($mapping),
                ...$targetCheck->unfilledRequired($mapping),
                ...$targetCheck->htmlIntoPlainText($mapping),
            ];
        }

        if ($introspection !== null) {
            $warnings = [...$warnings, ...(new IntrospectionCheck($mapping, $introspection))->warnings()];
        }

        return $warnings;
    }
}
