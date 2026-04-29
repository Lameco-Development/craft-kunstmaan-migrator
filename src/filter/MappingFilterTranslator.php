<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\filter;

/**
 * Translates source-domain entity filters into Craft query scopes.
 *
 * MigrationFilters::$entities deliberately stores Kunstmaan source identities
 * (FQCNs and/or basenames). Craft element queries must not compare those values
 * directly to Craft section or entry-type handles. This translator is the
 * boundary where compiled mapping state turns source identities into Craft
 * handles for verify/finalize query surfaces.
 */
final class MappingFilterTranslator
{
    /**
     * @param array<string, mixed> $compiledMapping
     * @return array{
     *   sectionHandles: list<string>,
     *   entryTypeHandles: list<string>,
     *   unmappedSourceEntities: list<string>
     * }
     */
    public function translate(array $compiledMapping, ?MigrationFilters $filters): array
    {
        if ($filters === null || $filters->entities === []) {
            return [
                'sectionHandles' => [],
                'entryTypeHandles' => [],
                'unmappedSourceEntities' => [],
            ];
        }

        $nodeClasses = (array) ($compiledMapping['nodeClasses'] ?? []);
        $sections = (array) ($compiledMapping['sections'] ?? []);

        $sectionHandles = [];
        $entryTypeHandles = [];
        $unmapped = [];

        foreach ($filters->entities as $sourceEntity) {
            $sourceEntity = (string) $sourceEntity;
            if ($sourceEntity === '') {
                continue;
            }

            $matched = false;
            foreach ($nodeClasses as $fqcn => $nodeSpec) {
                $fqcn = (string) $fqcn;
                if (!$this->matchesSourceIdentity($sourceEntity, $fqcn)) {
                    continue;
                }

                $matched = true;
                $nodeSpec = is_array($nodeSpec) ? $nodeSpec : [];
                $sectionKey = (string) ($nodeSpec['section'] ?? '');
                if ($sectionKey === '') {
                    continue;
                }

                $sectionSpec = (array) ($sections[$sectionKey] ?? []);
                $sectionHandle = (string) ($sectionSpec['section'] ?? $sectionKey);
                $entryTypeHandle = (string) ($sectionSpec['entryType'] ?? $sectionKey);

                if ($sectionHandle !== '') {
                    $sectionHandles[$sectionHandle] = true;
                }
                if ($entryTypeHandle !== '') {
                    $entryTypeHandles[$entryTypeHandle] = true;
                }
            }

            if (!$matched) {
                $unmapped[$sourceEntity] = true;
            }
        }

        $sectionHandles = array_keys($sectionHandles);
        $entryTypeHandles = array_keys($entryTypeHandles);
        $unmapped = array_keys($unmapped);
        sort($sectionHandles, SORT_STRING);
        sort($entryTypeHandles, SORT_STRING);
        sort($unmapped, SORT_STRING);

        return [
            'sectionHandles' => $sectionHandles,
            'entryTypeHandles' => $entryTypeHandles,
            'unmappedSourceEntities' => $unmapped,
        ];
    }

    private function matchesSourceIdentity(string $sourceEntity, string $fqcn): bool
    {
        return $sourceEntity === $fqcn || $sourceEntity === $this->sourceBasename($fqcn);
    }

    private function sourceBasename(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return (string) end($parts);
    }
}
