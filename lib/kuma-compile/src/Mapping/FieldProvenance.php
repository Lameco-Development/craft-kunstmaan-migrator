<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

use Lameco\KumaCompile\Target\TargetSchema;

/**
 * What feeds every field of every mapped target — computed once.
 *
 * The mapping answers "what does this legacy thing become", one row at a
 * time; every verifying question is the inverse. Three kinds of target
 * receive content: the page entry types (fed by page maps, sidecars, and the
 * parts lane through its context fields), the entity entry types (fed by the
 * entities lane), and the blocks themselves (fed field-by-field by the parts
 * lane, and by the forms lane through its emitted block). A field none of
 * them feed arrives empty on every migrated element — the fact this
 * structure exists to show.
 *
 * A snapshot of one mapping against one target schema: build it, ask it,
 * discard it with the request. It never watches the file.
 */
final class FieldProvenance
{
    /**
     * @param list<array{handle: string, kind: string}> $targets
     * @param array<string, array{kind: string, receives: list<string>, fields: array<string, array{required: bool, feeders: list<array{lane: string, name: string, expression: string}>, partsCount: ?int}>}> $coverage keyed `kind:handle`
     * @param array<string, array<string, array{carried: int, total: int, missing: list<string>}>> $carriage sidecar => field => carriage
     * @param list<string> $pageEntryTypes
     */
    private function __construct(
        private readonly array $targets,
        private readonly array $coverage,
        private readonly array $carriage,
        private readonly array $pageEntryTypes,
    ) {
    }

    /** @param list<string> $blocks the block handles this install offers, from the Craft side */
    public static function of(Mapping $mapping, TargetSchema $target, array $blocks = []): self
    {
        // Page entry types in live-volume order: everything that lists them
        // should lead with where the content is.
        $pages = $mapping->pages();
        uasort($pages, static fn ($a, $b): int =>
            (int) (is_array($b) ? ($b['live'] ?? 0) : 0) <=> (int) (is_array($a) ? ($a['live'] ?? 0) : 0));

        $pageEntryTypes = [];
        $receives = [];
        $pageFeeders = [];

        foreach ($pages as $name => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $entryType = $spec['entryType'] ?? null;

            if (!is_string($entryType) || $entryType === '') {
                continue;
            }

            $pageEntryTypes[$entryType] = true;
            $receives[$entryType][] = (string) $name;

            foreach ((array) ($spec['map'] ?? []) as $field => $expression) {
                $pageFeeders[$entryType][self::rootField((string) $field)][] =
                    ['lane' => 'pages', 'name' => (string) $name, 'expression' => (string) $expression];
            }
        }

        $pageEntryTypes = array_keys($pageEntryTypes);

        // Lane-invariant facts, once.
        $contextFields = [];

        foreach ((array) (($mapping->all()['defaults'] ?? [])['contexts'] ?? []) as $context) {
            $field = is_array($context) ? ($context['field'] ?? null) : null;

            if (is_string($field) && $field !== '') {
                $contextFields[$field] = true;
            }
        }

        $sidecarMaps = [];

        foreach ($mapping->sidecars() as $name => $spec) {
            if (!is_array($spec) || isset($spec['drop']) || isset($spec['manual'])) {
                continue;
            }

            foreach ((array) ($spec['map'] ?? []) as $field => $expression) {
                $sidecarMaps[(string) $name][self::rootField((string) $field)] = (string) $expression;
            }
        }

        // The parts lane, inverted twice: how many part types become blocks at
        // all (the page-level aggregate), and which parts feed which fields of
        // which block (the block-level detail).
        $partsBlocks = 0;
        $blockReceives = [];
        $blockFeeders = [];

        foreach ($mapping->parts() as $class => $spec) {
            if (!is_array($spec) || !isset($spec['block']) || !is_string($spec['block']) || $spec['block'] === '') {
                continue;
            }

            $partsBlocks++;
            $block = $spec['block'];
            $blockReceives[$block][] = (string) $class;

            foreach ((array) ($spec['map'] ?? []) as $field => $expression) {
                $blockFeeders[$block][self::rootField((string) $field)][] =
                    ['lane' => 'parts', 'name' => (string) $class, 'expression' => (string) $expression];
            }

            foreach ((array) ($spec['children'] ?? []) as $field => $child) {
                $table = is_array($child) ? (string) ($child['table'] ?? '') : '';
                $blockFeeders[$block][self::rootField((string) $field)][] =
                    ['lane' => 'parts', 'name' => (string) $class, 'expression' => 'children of ' . ($table !== '' ? $table : (string) $class)];
            }
        }

        // The forms lane emits one block whose form field it fills itself.
        $emit = (array) (($mapping->all()['forms'] ?? [])['emit'] ?? []);
        $emitBlock = is_string($emit['block'] ?? null) ? $emit['block'] : null;
        $emitField = is_string($emit['field'] ?? null) ? $emit['field'] : null;

        if ($emitBlock !== null && $emitField !== null) {
            $blockReceives[$emitBlock] ??= [];
            $blockFeeders[$emitBlock][$emitField][] =
                ['lane' => 'forms', 'name' => 'forms lane', 'expression' => 'the migrated Formie form'];
        }

        $targets = [];
        $coverage = [];
        $fieldSets = [];

        foreach ($pageEntryTypes as $entryType) {
            $slots = $target->slots($entryType);
            $fieldSets[$entryType] = $slots;
            $required = array_flip($target->requiredFields($entryType));
            $fields = [];

            foreach (array_keys($slots) as $field) {
                $field = (string) $field;
                $feeders = $pageFeeders[$entryType][$field] ?? [];

                foreach ($sidecarMaps as $sidecar => $map) {
                    if (isset($map[$field])) {
                        $feeders[] = ['lane' => 'sidecars', 'name' => (string) $sidecar, 'expression' => $map[$field]];
                    }
                }

                $fields[$field] = [
                    'required' => isset($required[$field]),
                    'feeders' => $feeders,
                    'partsCount' => isset($contextFields[$field]) ? $partsBlocks : null,
                ];
            }

            $targets[] = ['handle' => $entryType, 'kind' => 'page'];
            $coverage['page:' . $entryType] = [
                'kind' => 'page',
                'receives' => $receives[$entryType] ?? [],
                'fields' => $fields,
            ];
        }

        foreach ($mapping->entities() as $name => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $entryType = $spec['entryType'] ?? null;

            if (!is_string($entryType) || $entryType === '') {
                continue;
            }

            $required = array_flip($target->requiredFields($entryType));
            $fields = [];

            foreach (array_keys($target->slots($entryType)) as $field) {
                $field = (string) $field;
                $feeders = [];

                foreach ((array) ($spec['map'] ?? []) as $mapped => $expression) {
                    if (self::rootField((string) $mapped) === $field) {
                        $feeders[] = ['lane' => 'entities', 'name' => (string) $name, 'expression' => (string) $expression];
                    }
                }

                $fields[$field] = [
                    'required' => isset($required[$field]),
                    'feeders' => $feeders,
                    'partsCount' => null,
                ];
            }

            $targets[] = ['handle' => $entryType, 'kind' => 'entity'];
            $coverage['entity:' . $entryType] = [
                'kind' => 'entity',
                'receives' => [(string) $name],
                'fields' => $fields,
            ];
        }

        // Every block the install offers, plus any block a part names that the
        // install does not — the second kind shows up with fields missing,
        // which is exactly the alarm it deserves.
        foreach (array_unique([...$blocks, ...array_keys($blockReceives)]) as $block) {
            $required = array_flip($target->requiredFields($block));
            $fields = [];

            foreach (array_keys($target->slots($block)) as $field) {
                $field = (string) $field;
                $fields[$field] = [
                    'required' => isset($required[$field]),
                    'feeders' => $blockFeeders[$block][$field] ?? [],
                    'partsCount' => null,
                ];
            }

            $targets[] = ['handle' => (string) $block, 'kind' => 'block'];
            $coverage['block:' . $block] = [
                'kind' => 'block',
                'receives' => $blockReceives[$block] ?? [],
                'fields' => $fields,
            ];
        }

        $carriage = [];

        foreach ($sidecarMaps as $sidecar => $map) {
            foreach (array_keys($map) as $field) {
                $missing = array_values(array_filter(
                    $pageEntryTypes,
                    static fn (string $entryType): bool => !isset($fieldSets[$entryType][$field]),
                ));

                $carriage[(string) $sidecar][(string) $field] = [
                    'carried' => count($pageEntryTypes) - count($missing),
                    'total' => count($pageEntryTypes),
                    'missing' => $missing,
                ];
            }
        }

        return new self($targets, $coverage, $carriage, $pageEntryTypes);
    }

    /** `contentColumns[0].content` feeds the `contentColumns` slot. */
    private static function rootField(string $field): string
    {
        return (string) preg_replace('/[\[.].*$/', '', $field);
    }

    /** @return list<array{handle: string, kind: string}> every mapped target, pages first */
    public function targets(): array
    {
        return $this->targets;
    }

    /** @return list<string> mapped page entry types, live-volume first */
    public function entryTypes(): array
    {
        return $this->pageEntryTypes;
    }

    /** @return array{kind: string, receives: list<string>, fields: array<string, array{required: bool, feeders: list<array{lane: string, name: string, expression: string}>, partsCount: ?int}>} */
    public function coverage(string $kind, string $handle): array
    {
        return $this->coverage[$kind . ':' . $handle] ?? ['kind' => $kind, 'receives' => [], 'fields' => []];
    }

    /** @return array<string, list<array{sidecar: string, expression: string}>> field => who fills it */
    public function sidecarFills(string $entryType): array
    {
        $fills = [];

        foreach ($this->coverage('page', $entryType)['fields'] as $field => $state) {
            foreach ($state['feeders'] as $feeder) {
                if ($feeder['lane'] === 'sidecars') {
                    $fills[$field][] = ['sidecar' => $feeder['name'], 'expression' => $feeder['expression']];
                }
            }
        }

        return $fills;
    }

    /** @return array<string, array{carried: int, total: int, missing: list<string>}> field => how many mapped entry types carry it */
    public function carriage(string $sidecar): array
    {
        return $this->carriage[$sidecar] ?? [];
    }

    /** @return list<array{handle: string, kind: string, unfed: int, required: int}> only the targets with holes */
    public function gaps(): array
    {
        $gaps = [];

        foreach ($this->targets as $target) {
            $unfed = 0;
            $required = 0;

            foreach ($this->coverage($target['kind'], $target['handle'])['fields'] as $state) {
                if ($state['feeders'] !== [] || $state['partsCount'] !== null) {
                    continue;
                }

                $unfed++;

                if ($state['required']) {
                    $required++;
                }
            }

            if ($unfed > 0) {
                $gaps[] = ['handle' => $target['handle'], 'kind' => $target['kind'], 'unfed' => $unfed, 'required' => $required];
            }
        }

        return $gaps;
    }

    /** @return list<string> the union of fields across every mapped page entry type */
    public function pageFields(): array
    {
        $fields = [];

        foreach ($this->pageEntryTypes as $entryType) {
            foreach (array_keys($this->coverage('page', $entryType)['fields']) as $field) {
                $fields[(string) $field] = true;
            }
        }

        $handles = array_keys($fields);
        sort($handles);

        return $handles;
    }
}
