<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\audit;

use yii\base\Component;

/**
 * Pure Page-rooted coverage classifier.
 *
 * Consumes structural discovery rows and mapping/compiler context, then emits
 * deterministic rows that explain which Craft Entry receives each surface or
 * why the surface is not migrated.
 */
final class PageRootedCoverageAuditor extends Component
{
    private const CATEGORIES = ['migrated', 'dropped', 'out_of_scope', 'unsupported', 'warning'];

    /**
     * @param list<array<string, mixed>> $discoveryRows
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $pageStructure
     * @param list<string> $compileWarnings
     * @return list<array<string, mixed>>
     */
    public function audit(
        array $discoveryRows,
        array $mapping,
        array $pageStructure = [],
        array $compileWarnings = [],
    ): array {
        $targets = $this->pageTargets($mapping, $pageStructure);
        $mappingCategories = $this->mappingCategories($mapping);
        $rows = [];

        foreach ($discoveryRows as $discovered) {
            if (!is_array($discovered)) {
                continue;
            }
            $pageFqcn = (string) ($discovered['pageFqcn'] ?? '');
            $surfaceType = (string) ($discovered['surfaceType'] ?? '');
            $sourceIdentifier = (string) ($discovered['sourceIdentifier'] ?? '');
            if ($pageFqcn === '' || $surfaceType === '' || $sourceIdentifier === '') {
                continue;
            }
            $target = $targets[$pageFqcn] ?? [
                'section' => '',
                'entryType' => '',
                'sourceTable' => (string) ($discovered['sourceTable'] ?? ''),
            ];
            $category = $this->categoryFor($discovered, $mappingCategories);
            $reason = $this->reasonFor($category, $discovered, $compileWarnings);

            $row = [
                'pageFqcn' => $pageFqcn,
                'sourceTable' => (string) ($target['sourceTable'] ?: ($discovered['sourceTable'] ?? '')),
                'surfaceType' => $surfaceType,
                'sourceIdentifier' => $sourceIdentifier,
                'sourceService' => (string) ($discovered['sourceService'] ?? ''),
                'category' => $category,
                'targetCraft' => [
                    'section' => (string) ($target['section'] ?? ''),
                    'entryType' => (string) ($target['entryType'] ?? ''),
                ],
                'reason' => $reason,
                'metadata' => $this->structuralMetadata($discovered),
            ];
            $rows[] = $this->dropEmpty($row);
        }

        usort($rows, static function (array $a, array $b): int {
            return [
                (string) ($a['pageFqcn'] ?? ''),
                (string) ($a['surfaceType'] ?? ''),
                (string) ($a['sourceIdentifier'] ?? ''),
            ] <=> [
                (string) ($b['pageFqcn'] ?? ''),
                (string) ($b['surfaceType'] ?? ''),
                (string) ($b['sourceIdentifier'] ?? ''),
            ];
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $coverageRows
     */
    public function renderMarkdown(array $coverageRows): string
    {
        $byPage = [];
        foreach ($coverageRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $byPage[(string) ($row['pageFqcn'] ?? '')][] = $row;
        }
        ksort($byPage);

        $out = "# Page-rooted Coverage\n\n";
        $out .= "Structural audit generated during compile. Content bodies and sample values are intentionally omitted.\n";
        foreach ($byPage as $pageFqcn => $rows) {
            if ($pageFqcn === '') {
                continue;
            }
            $target = (array) ($rows[0]['targetCraft'] ?? []);
            $out .= "\n## " . $this->escapeMarkdown($pageFqcn) . "\n\n";
            $out .= '- Craft target: section `' . $this->escapeMarkdown((string) ($target['section'] ?? '')) . '`, entry type `' . $this->escapeMarkdown((string) ($target['entryType'] ?? '')) . "`\n\n";
            $out .= "| Surface | Source identifier | Category | Craft target | Reason |\n";
            $out .= "|---|---|---|---|---|\n";
            foreach ($rows as $row) {
                $rowTarget = (array) ($row['targetCraft'] ?? []);
                $craftTarget = ((string) ($rowTarget['section'] ?? '')) . '/' . ((string) ($rowTarget['entryType'] ?? ''));
                $out .= '| ' . $this->escapeMarkdown((string) ($row['surfaceType'] ?? ''))
                    . ' | `' . $this->escapeMarkdown((string) ($row['sourceIdentifier'] ?? '')) . '`'
                    . ' | ' . $this->escapeMarkdown((string) ($row['category'] ?? ''))
                    . ' | `' . $this->escapeMarkdown($craftTarget) . '`'
                    . ' | ' . $this->escapeMarkdown((string) ($row['reason'] ?? '')) . " |\n";
            }
        }

        return rtrim($out) . "\n";
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $pageStructure
     * @return array<string, array{sourceTable: string, section: string, entryType: string}>
     */
    private function pageTargets(array $mapping, array $pageStructure): array
    {
        $targets = [];
        foreach ((array) ($mapping['nodeClasses'] ?? []) as $fqcn => $spec) {
            if (!is_string($fqcn) || !is_array($spec)) {
                continue;
            }
            $targets[$fqcn] = [
                'sourceTable' => (string) ($spec['sourceTable'] ?? ($pageStructure[$fqcn]['tableName'] ?? '')),
                'section' => (string) ($spec['section'] ?? ''),
                'entryType' => (string) ($spec['entryType'] ?? $spec['section'] ?? ''),
            ];
        }
        foreach ((array) ($mapping['proposals'] ?? []) as $row) {
            if (!is_array($row) || ((string) ($row['kind'] ?? '')) !== 'nodeClass') {
                continue;
            }
            if (((string) ($row['status'] ?? '')) !== 'accepted') {
                continue;
            }
            $fqcn = (string) ($row['fqcn'] ?? '');
            if ($fqcn === '') {
                continue;
            }
            $existing = $targets[$fqcn] ?? ['sourceTable' => '', 'section' => '', 'entryType' => ''];
            $targets[$fqcn] = [
                'sourceTable' => (string) ($row['sourceTable'] ?? $existing['sourceTable']),
                'section' => (string) ($row['targetSection'] ?? $existing['section']),
                'entryType' => (string) ($row['targetEntryType'] ?? $existing['entryType']),
            ];
        }
        ksort($targets);
        return $targets;
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, array{category: string, reason: string}>
     */
    private function mappingCategories(array $mapping): array
    {
        $out = [];
        foreach ((array) ($mapping['proposals'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if (!in_array($status, ['accepted', 'dropped'], true)) {
                continue;
            }
            $kind = (string) ($row['kind'] ?? 'column');
            $identifier = null;
            if ($kind === 'column') {
                if ($status === 'accepted' && (
                    (string) ($row['targetHandle'] ?? '') === ''
                    || (string) ($row['handler'] ?? '') === ''
                )) {
                    continue;
                }
                $identifier = (string) ($row['table'] ?? '') . '.' . (string) ($row['column'] ?? '');
            } elseif ($kind === 'pagePart') {
                $identifier = (string) ($row['pagePartClass'] ?? '') . '|' . (string) ($row['context'] ?? '');
            } elseif (in_array($kind, ['taxonomy', 'dataProvider'], true)) {
                $identifier = (string) ($row['fqcn'] ?? '');
            } elseif (in_array($kind, ['promotedTarget', 'promotedRelationTarget'], true)) {
                $identifier = (string) ($row['sourceRef'] ?? $row['relationRef'] ?? '');
            }
            if ($identifier === null || $identifier === '.' || $identifier === '') {
                continue;
            }
            $relationIntent = (string) ($row['relationIntent'] ?? '');
            if ($relationIntent !== '') {
                $out[$identifier] = [
                    'category' => $this->categoryForRelationIntent($relationIntent),
                    'reason' => $this->reasonForRelationIntent($relationIntent),
                ];
                foreach (['relationRef', 'sourceRef', 'targetRef'] as $key) {
                    $alias = (string) ($row[$key] ?? '');
                    if ($alias !== '') {
                        $out[$alias] = $out[$identifier];
                    }
                }
                continue;
            }
            $out[$identifier] = [
                'category' => $status === 'accepted' ? 'migrated' : 'dropped',
                'reason' => (string) ($row['rationale'] ?? ''),
            ];
        }
        ksort($out);
        return $out;
    }

    /** @param array<string, mixed> $discovered @param array<string, array{category: string, reason: string}> $mappingCategories */
    private function categoryFor(array $discovered, array $mappingCategories): string
    {
        $identifier = (string) ($discovered['sourceIdentifier'] ?? '');
        if (isset($mappingCategories[$identifier])) {
            return $mappingCategories[$identifier]['category'];
        }
        foreach (['relationRef', 'sourceRef', 'targetRef'] as $key) {
            $alias = (string) ($discovered[$key] ?? '');
            if ($alias !== '' && isset($mappingCategories[$alias])) {
                return $mappingCategories[$alias]['category'];
            }
        }
        $hint = (string) ($discovered['categoryHint'] ?? 'warning');
        return in_array($hint, self::CATEGORIES, true) ? $hint : 'warning';
    }

    /** @param array<string, mixed> $discovered @param list<string> $compileWarnings */
    private function reasonFor(string $category, array $discovered, array $compileWarnings): string
    {
        $identifier = (string) ($discovered['sourceIdentifier'] ?? '');
        foreach ($compileWarnings as $warning) {
            if ($identifier !== '' && str_contains($warning, $identifier)) {
                return $warning;
            }
            $property = (string) ($discovered['property'] ?? '');
            if ($property !== '' && str_contains($warning, $property)) {
                return $warning;
            }
            if ($property !== '' && str_ends_with($property, 's') && str_contains($warning, substr($property, 0, -1))) {
                return $warning;
            }
            if ($property !== '' && str_ends_with($property, 'ies') && str_contains($warning, substr($property, 0, -3) . 'y')) {
                return $warning;
            }
        }
        if (($discovered['surfaceType'] ?? '') === 'relation') {
            if ($category === 'dropped') {
                return 'relation.intent.drop: intentionally dropped by accepted mapping.';
            }
            if ($category === 'out_of_scope') {
                return 'relation.intent.out_of_scope: intentionally outside migration scope.';
            }
            if ($category === 'migrated') {
                return 'relation.promoted/reference/embed: accepted relation intent covers this evidence.';
            }
            $reason = (string) ($discovered['reason'] ?? '');
            if ($reason !== '') {
                return $reason;
            }
            return 'relation.unresolved: non-empty relation FK evidence requires reference, promote, embed, drop, or out_of_scope.';
        }
        $reason = (string) ($discovered['reason'] ?? '');
        if ($reason !== '') {
            return $reason;
        }
        return match ($category) {
            'migrated' => 'Mapped to the Craft Entry target.',
            'dropped' => 'Dropped by mapping/operator decision.',
            'out_of_scope' => 'Explicitly outside the configured migration scope.',
            'unsupported' => 'Unsupported or incomplete source shape; operator review required.',
            default => 'Warning: discovery found no migrated target or complete support metadata.',
        };
    }

    private function categoryForRelationIntent(string $intent): string
    {
        return match ($intent) {
            'drop' => 'dropped',
            'out_of_scope' => 'out_of_scope',
            'reference', 'promote', 'embed' => 'migrated',
            default => 'warning',
        };
    }

    private function reasonForRelationIntent(string $intent): string
    {
        return match ($intent) {
            'drop' => 'relation.intent.drop: intentionally dropped by accepted mapping.',
            'out_of_scope' => 'relation.intent.out_of_scope: intentionally outside migration scope.',
            'promote' => 'relation.promoted: promoted/shared target is loaded under its own stateSource.',
            'reference' => 'relation reference resolved through stateSource.',
            'embed' => 'relation embedded into owner payload.',
            default => 'relation.unresolved: relation evidence has no accepted intent.',
        };
    }

    /** @param array<string, mixed> $discovered @return array<string, mixed> */
    private function structuralMetadata(array $discovered): array
    {
        $blocked = ['pageFqcn', 'surfaceType', 'categoryHint', 'sourceService', 'sourceIdentifier', 'reason', 'samples', 'sample', 'value', 'body', 'content'];
        $out = [];
        foreach ($discovered as $key => $value) {
            if (in_array((string) $key, $blocked, true) || $value === '' || $value === null) {
                continue;
            }
            if (is_scalar($value)) {
                $out[(string) $key] = $value;
            } elseif (is_array($value)) {
                $out[(string) $key] = $this->scalarArrayOnly($value);
            }
        }
        ksort($out);
        return $out;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function scalarArrayOnly(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function dropEmpty(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === '' || $value === []) {
                unset($row[$key]);
            }
        }
        return $row;
    }

    private function escapeMarkdown(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
