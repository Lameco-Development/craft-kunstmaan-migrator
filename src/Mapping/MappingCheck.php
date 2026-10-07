<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

use Lameco\Kunstmaanmigrator\Report\IntrospectionCheck;
use Lameco\Kunstmaanmigrator\Report\SpecDivergence;
use Lameco\Kunstmaanmigrator\Source\EntityTableIndex;
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
    /**
     * @param array<string, int>|null $liveParts fully qualified pagepart class => live placements,
     *        when the legacy databases were read; null leaves the collision check out
     * @param ?EntityTableIndex $tables the legacy entities' tables, where known: classes that
     *        share a short name and read one table are no collision
     */
    public function __construct(
        private readonly ?TargetSchema $target = null,
        private readonly ?array $liveParts = null,
        private readonly ?EntityTableIndex $tables = null,
    ) {
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

        if ($this->liveParts !== null && ($errors = $this->collisionErrors($mapping, $this->liveParts))) {
            return ['Short-name rows that read one table for several live classes', $errors];
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
     * nothing fills, and — given an introspection artifact — the legacy app's
     * own wiring the mapping contradicts.
     *
     * @return list<string>
     */
    public function warnings(Mapping $mapping, ?Introspection $introspection = null): array
    {
        $warnings = [];

        if ($this->target !== null) {
            $targetCheck = new TargetCheck($this->target);
            $warnings = [...$targetCheck->pagesWithNoBlockField($mapping), ...$targetCheck->unfilledRequired($mapping)];
        }

        if ($introspection !== null) {
            $warnings = [...$warnings, ...(new IntrospectionCheck($mapping, $introspection))->warnings()];
        }

        return $warnings;
    }

    /**
     * @param array<string, int> $liveParts
     * @return list<string>
     */
    private function collisionErrors(Mapping $mapping, array $liveParts): array
    {
        $errors = [];

        foreach ($mapping->unresolvedPartCollisions($liveParts, $this->tables) as $collision) {
            $classes = [];

            foreach ($collision['classes'] as $class => $n) {
                $classes[] = sprintf('%s (%d)', $class, $n);
            }

            $errors[] = sprintf(
                '`%s` reads %s for %d live classes — %s. Key a row by each class\'s fully qualified name, so each reads its own table',
                $collision['key'],
                $collision['table'] !== null ? '`' . $collision['table'] . '`' : 'one table',
                count($classes),
                implode(', ', $classes),
            );
        }

        return $errors;
    }
}
