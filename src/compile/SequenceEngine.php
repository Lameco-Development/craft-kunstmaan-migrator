<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use lameco\kunstmaanmigrator\legacy\PartReader;

/**
 * Applies the mapping's window rules to a page's ordered part list before any block is built.
 *
 * A Kunstmaan body is flat: an inline heading part sits between content parts rather than
 * belonging to one. Most Craft blocks own their heading, so the heading is normally absorbed
 * by the part that follows it — but only when that part has nothing in its own heading slot,
 * or absorbing would overwrite real content.
 */
final class SequenceEngine
{
    /**
     * @param list<array<string, mixed>> $rules the mapping's `sequence:` section
     * @param array<string, array<string, mixed>> $parts the mapping's `parts:` section
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $parts,
        private readonly PartReader $reader,
        private readonly BlockBuilder $builder,
        private readonly ?TargetModel $schema = null,
    ) {
    }

    /**
     * Rewrite the sequence into a list of emissions.
     *
     * @param list<array{part:string, id:int, sequence:int}> $sequence
     * @return list<array{part:string, id:int, absorb?:array<string,mixed>, emit?:array<string,mixed>}>
     */
    public function apply(array $sequence): array
    {
        $absorbRule = $this->rule('absorb');

        if ($absorbRule === null) {
            return array_map(static fn (array $p): array => ['part' => $p['part'], 'id' => $p['id']], $sequence);
        }

        $head = $this->matchHead((string) $absorbRule['match']);
        $fallback = $this->rule('emit', (string) ($absorbRule['else'] ?? ''));
        $out = [];
        $i = 0;
        $count = count($sequence);

        while ($i < $count) {
            $current = $sequence[$i];

            if ($current['part'] !== $head) {
                $out[] = ['part' => $current['part'], 'id' => $current['id']];
                $i++;

                continue;
            }

            $carried = $this->headValues($current, $absorbRule);
            $next = $sequence[$i + 1] ?? null;

            if ($next === null || $next['part'] === $head || !$this->canAbsorb($next, $absorbRule)) {
                if ($fallback !== null) {
                    $out[] = ['part' => $head, 'id' => $current['id'], 'emit' => $this->emit($fallback, $carried)];
                }

                $i++;

                continue;
            }

            // `runs: first` — a heading introducing several blocks of one type lands on the
            // first of them; the rest are emitted bare.
            $spec = $this->parts[$next['part']] ?? [];
            $placed = [];

            foreach ($carried as $field => $value) {
                $placed[$this->absorbPath($spec, (string) $field)] = $value;
            }

            $out[] = ['part' => $next['part'], 'id' => $next['id'], 'absorb' => $placed];
            $i += 2;
        }

        return $out;
    }

    /** @param array<string, mixed> $rule */
    private function headValues(array $part, array $rule): array
    {
        $spec = $this->parts[$part['part']] ?? [];
        $row = isset($spec['table']) ? $this->reader->row((string) $spec['table'], $part['id']) : null;

        if ($row === null) {
            return [];
        }

        $map = [];

        foreach ($rule['map'] ?? [] as $target => $expression) {
            // `head.title` reads the head part's own row.
            $map[$target] = preg_replace('/^head\./', '', (string) $expression);
        }

        return $this->builder->fieldsFrom($map, $row, $part['part']);
    }

    /**
     * The guard. Absorbing into a block that already carries its own heading would overwrite
     * content an editor wrote, so those windows fall through to the fallback rule instead.
     *
     * Where the heading lands is the part's own declaration: `absorbInto: false` means the
     * target block has no heading at all, and a path means the heading sits inside a nested
     * Matrix rather than at block level. The compiler cannot read Craft's field layout, so
     * this is stated in the mapping rather than guessed.
     *
     * @param array{part:string, id:int} $next
     * @param array<string, mixed> $rule
     */
    private function canAbsorb(array $next, array $rule): bool
    {
        $spec = $this->parts[$next['part']] ?? null;

        if ($spec === null || !isset($spec['block'], $spec['table'])) {
            return false;
        }

        if (($spec['absorbInto'] ?? null) === false) {
            return false;
        }

        // No override, and the schema says the block has no such field anywhere.
        if (!array_key_exists('absorbInto', $spec) && $this->schema !== null
            && $this->schema->pathFor((string) $spec['block'], (string) array_key_first($rule['map'] ?? [])) === null) {
            return false;
        }

        $primary = array_key_first($rule['map'] ?? []);

        if ($primary === null) {
            return false;
        }

        $path = $this->absorbPath($spec, (string) $primary);
        $map = $spec['map'] ?? [];

        if (!isset($map[$path])) {
            return true;   // nothing of the part's own lands there, so nothing to overwrite
        }

        $row = $this->reader->row((string) $spec['table'], $next['id']);

        if ($row === null) {
            return false;
        }

        $existing = $this->builder->fieldsFrom([$path => $map[$path]], $row, $next['part']);

        return ($existing[$path] ?? null) === null;
    }

    /**
     * Where an absorbed value lands on the target block.
     *
     * Derived from the target schema — which field layout actually holds a `heading`, and
     * whether it sits at block level or inside a nested Matrix. `absorbInto` in the mapping
     * overrides it, for the case where a human knows better than the derivation.
     *
     * @param array<string, mixed> $spec
     */
    private function absorbPath(array $spec, string $target): string
    {
        $prefix = $spec['absorbInto'] ?? null;

        if ($prefix === null && $this->schema !== null && isset($spec['block'])) {
            $prefix = $this->schema->pathFor((string) $spec['block'], $target) ?? '';
        }

        return is_string($prefix) && $prefix !== '' ? $prefix . '.' . $target : $target;
    }

    /** @param array<string, mixed> $rule */
    private function emit(array $rule, array $carried): array
    {
        $fields = [];

        foreach ($rule['map'] ?? [] as $target => $expression) {
            $key = preg_replace('/^head\./', '', (string) $expression);
            $value = $carried[$key] ?? ($carried[basename(str_replace('.', '/', (string) $target))] ?? null);

            if ($value !== null) {
                $fields[$target] = $value;
            }
        }

        return ['block' => (string) ($rule['block'] ?? ''), 'fields' => $fields, 'carried' => $carried];
    }

    private function matchHead(string $match): string
    {
        return trim(explode('>', $match)[0]);
    }

    /** @return array<string, mixed>|null */
    private function rule(string $action, string $id = ''): ?array
    {
        foreach ($this->rules as $rule) {
            if ($id !== '' ? ($rule['id'] ?? '') === $id : ($rule['action'] ?? '') === $action) {
                return $rule;
            }
        }

        return null;
    }
}
