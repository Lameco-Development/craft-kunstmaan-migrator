<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\filter;

use lameco\kunstmaanmigrator\Plugin;
use RuntimeException;

/**
 * Translates a run's entity filters into compiled-mapping scope, with the
 * shared preflight every consuming stage needs: mapping file present,
 * nodeClasses/sections compiled, and no unmapped entities in the filter.
 *
 * One implementation for finalize, verify (workflow + CLI) — previously three
 * drifting copies of the same wrapper around MappingFilterTranslator.
 */
final class CompiledScope
{
    /**
     * @return array{
     *   sectionHandles: list<string>,
     *   entryTypeHandles: list<string>,
     *   unmappedSourceEntities: list<string>
     * }
     */
    public static function forFilters(MigrationFilters $filters, string $stage): array
    {
        if ($filters->entities === []) {
            return [
                'sectionHandles' => [],
                'entryTypeHandles' => [],
                'unmappedSourceEntities' => [],
            ];
        }

        $plugin = Plugin::getInstance();
        $mappingPath = $plugin->mappingFile->resolvePath();
        if (!is_file($mappingPath)) {
            throw new RuntimeException(
                "Entity filters require compiled mapping for {$stage}. Run `./craft kunstmaan-migrator/compile` first.",
            );
        }

        $compiledMapping = $plugin->mappingFile->load($mappingPath);
        if ((array) ($compiledMapping['nodeClasses'] ?? []) === [] || (array) ($compiledMapping['sections'] ?? []) === []) {
            throw new RuntimeException(
                "Entity filters require compiled mapping nodeClasses/sections for {$stage}. Run `./craft kunstmaan-migrator/compile` first.",
            );
        }

        $translatedScope = (new MappingFilterTranslator())->translate($compiledMapping, $filters);
        if ($translatedScope['unmappedSourceEntities'] !== []) {
            throw new RuntimeException(
                'Entity filters are not present in compiled mapping: '
                . implode(', ', $translatedScope['unmappedSourceEntities'])
                . '. Run `./craft kunstmaan-migrator/analyze` and `./craft kunstmaan-migrator/compile`, or adjust --entities.',
            );
        }

        return $translatedScope;
    }
}
