<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

use Lameco\KumaCompile\Target\TargetSchema;

/**
 * What feeds every field of every mapped entry type — computed once.
 *
 * The mapping answers "what does this legacy thing become", one row at a
 * time; every verifying question is the inverse. The editor used to derive
 * each inversion ad hoc per screen — coverage here, sidecar fills there,
 * carriage counts elsewhere — while the compiler derives the same model
 * again to run. One computed structure means every screen answers from the
 * same facts, and a screen can no longer disagree with another screen.
 *
 * A snapshot of one mapping against one target schema: build it, ask it,
 * discard it with the request. It never watches the file.
 */
final class FieldProvenance
{
    /**
     * @param list<string> $entryTypes mapped entry types, live-volume first
     * @param array<string, array{pageTypes: list<string>, fields: array<string, array{required: bool, pages: list<array{page: string, expression: string}>, sidecars: list<array{sidecar: string, expression: string}>, parts: ?int}>}> $byEntryType
     * @param array<string, array<string, array{carried: int, total: int, missing: list<string>}>> $carriage sidecar => field => carriage
     */
    private function __construct(
        private readonly array $entryTypes,
        private readonly array $byEntryType,
        private readonly array $carriage,
    ) {
    }

    public static function of(Mapping $mapping, TargetSchema $target): self
    {
        // Entry types in live-volume order: everything that lists them should
        // lead with where the content is.
        $pages = $mapping->pages();
        uasort($pages, static fn ($a, $b): int =>
            (int) (is_array($b) ? ($b['live'] ?? 0) : 0) <=> (int) (is_array($a) ? ($a['live'] ?? 0) : 0));

        $entryTypes = [];
        $pageTypes = [];
        $pageFills = [];

        foreach ($pages as $name => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $entryType = $spec['entryType'] ?? null;

            if (!is_string($entryType) || $entryType === '') {
                continue;
            }

            $entryTypes[$entryType] = true;
            $pageTypes[$entryType][] = (string) $name;

            foreach ((array) ($spec['map'] ?? []) as $field => $expression) {
                $pageFills[$entryType][(string) $field][] = ['page' => (string) $name, 'expression' => (string) $expression];
            }
        }

        $entryTypes = array_keys($entryTypes);

        // Lane-invariant facts, once: which fields the parts lane feeds, and
        // what every live sidecar maps.
        $contextFields = [];

        foreach ((array) (($mapping->all()['defaults'] ?? [])['contexts'] ?? []) as $context) {
            $field = is_array($context) ? ($context['field'] ?? null) : null;

            if (is_string($field) && $field !== '') {
                $contextFields[$field] = true;
            }
        }

        $partsBlocks = 0;

        foreach ($mapping->parts() as $spec) {
            if (is_array($spec) && isset($spec['block'])) {
                $partsBlocks++;
            }
        }

        $sidecarMaps = [];

        foreach ($mapping->sidecars() as $name => $spec) {
            if (!is_array($spec) || isset($spec['drop']) || isset($spec['manual'])) {
                continue;
            }

            foreach ((array) ($spec['map'] ?? []) as $field => $expression) {
                $sidecarMaps[(string) $name][(string) $field] = (string) $expression;
            }
        }

        $byEntryType = [];
        $fieldSets = [];

        foreach ($entryTypes as $entryType) {
            $slots = $target->slots($entryType);
            $fieldSets[$entryType] = $slots;
            $required = array_flip($target->requiredFields($entryType));
            $fields = [];

            foreach (array_keys($slots) as $field) {
                $field = (string) $field;
                $sidecars = [];

                foreach ($sidecarMaps as $sidecar => $map) {
                    if (isset($map[$field])) {
                        $sidecars[] = ['sidecar' => (string) $sidecar, 'expression' => $map[$field]];
                    }
                }

                $fields[$field] = [
                    'required' => isset($required[$field]),
                    'pages' => $pageFills[$entryType][$field] ?? [],
                    'sidecars' => $sidecars,
                    'parts' => isset($contextFields[$field]) ? $partsBlocks : null,
                ];
            }

            $byEntryType[$entryType] = [
                'pageTypes' => $pageTypes[$entryType] ?? [],
                'fields' => $fields,
            ];
        }

        $carriage = [];

        foreach ($sidecarMaps as $sidecar => $map) {
            foreach (array_keys($map) as $field) {
                $missing = array_values(array_filter(
                    $entryTypes,
                    static fn (string $entryType): bool => !isset($fieldSets[$entryType][$field]),
                ));

                $carriage[(string) $sidecar][(string) $field] = [
                    'carried' => count($entryTypes) - count($missing),
                    'total' => count($entryTypes),
                    'missing' => $missing,
                ];
            }
        }

        return new self($entryTypes, $byEntryType, $carriage);
    }

    /** @return list<string> mapped entry types, live-volume first */
    public function entryTypes(): array
    {
        return $this->entryTypes;
    }

    /** @return array{pageTypes: list<string>, fields: array<string, array{required: bool, pages: list<array{page: string, expression: string}>, sidecars: list<array{sidecar: string, expression: string}>, parts: ?int}>} */
    public function coverage(string $entryType): array
    {
        return $this->byEntryType[$entryType] ?? ['pageTypes' => [], 'fields' => []];
    }

    /** @return array<string, list<array{sidecar: string, expression: string}>> field => who fills it */
    public function sidecarFills(string $entryType): array
    {
        $fills = [];

        foreach ($this->coverage($entryType)['fields'] as $field => $state) {
            if ($state['sidecars'] !== []) {
                $fills[$field] = $state['sidecars'];
            }
        }

        return $fills;
    }

    /** @return array<string, array{carried: int, total: int, missing: list<string>}> field => how many mapped entry types carry it */
    public function carriage(string $sidecar): array
    {
        return $this->carriage[$sidecar] ?? [];
    }

    /** @return list<array{entryType: string, unfed: int, required: int}> only the entry types with holes */
    public function gaps(): array
    {
        $gaps = [];

        foreach ($this->entryTypes as $entryType) {
            $unfed = 0;
            $required = 0;

            foreach ($this->coverage($entryType)['fields'] as $state) {
                if ($state['pages'] !== [] || $state['sidecars'] !== [] || $state['parts'] !== null) {
                    continue;
                }

                $unfed++;

                if ($state['required']) {
                    $required++;
                }
            }

            if ($unfed > 0) {
                $gaps[] = ['entryType' => $entryType, 'unfed' => $unfed, 'required' => $required];
            }
        }

        return $gaps;
    }

    /** @return list<string> the union of fields across every mapped entry type */
    public function pageFields(): array
    {
        $fields = [];

        foreach ($this->byEntryType as $coverage) {
            foreach (array_keys($coverage['fields']) as $field) {
                $fields[(string) $field] = true;
            }
        }

        $handles = array_keys($fields);
        sort($handles);

        return $handles;
    }
}
