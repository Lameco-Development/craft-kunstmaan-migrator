#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Structural Kunstmaan source-shape sampler.
 *
 * Emits class/table/relation/count/risk metadata only. It deliberately does
 * not print method bodies, property default values, SQL rows, content samples,
 * secrets, or snippets from local client source projects.
 */

$paths = array_slice($argv, 1);
if ($paths === []) {
    $home = rtrim((string) ($_SERVER['HOME'] ?? ''), '/');
    $paths = array_filter([
        $home !== '' ? $home . '/Sites/cqm-website' : null,
        $home !== '' ? $home . '/Sites/simac-website' : null,
        $home !== '' ? $home . '/Sites/enreach-website' : null,
    ]);
}

if ($paths === []) {
    fwrite(STDERR, "Usage: php tools/audit-source-shapes.php <source-path> [<source-path> ...]\n");
    exit(2);
}

foreach ($paths as $path) {
    auditSourcePath((string) $path);
}

exit(0);

/**
 * @return void
 */
function auditSourcePath(string $inputPath): void
{
    $expanded = expandHome($inputPath);
    if (!is_dir($expanded)) {
        echo "project={$inputPath} status=missing\n";
        return;
    }

    $root = realpath($expanded) ?: $expanded;
    $entities = collectEntities($root);
    $counts = [
        'classes' => count($entities),
        'pages' => 0,
        'pageparts' => 0,
        'relations' => 0,
        'tables' => [],
    ];
    foreach ($entities as $entity) {
        if ($entity['kind'] === 'page') {
            $counts['pages']++;
        } elseif ($entity['kind'] === 'pagepart') {
            $counts['pageparts']++;
        }
        if ($entity['table'] !== '') {
            $counts['tables'][$entity['table']] = true;
        }
        $counts['relations'] += count($entity['relations']);
    }

    echo 'project=' . $root
        . ' status=ok'
        . ' classes=' . $counts['classes']
        . ' pages=' . $counts['pages']
        . ' pageparts=' . $counts['pageparts']
        . ' tables=' . count($counts['tables'])
        . ' relations=' . $counts['relations']
        . "\n";

    foreach ($entities as $entity) {
        echo 'class=' . $entity['fqcn']
            . ' kind=' . $entity['kind']
            . ' table=' . $entity['table']
            . ' columns=' . count($entity['columns'])
            . ' relations=' . count($entity['relations'])
            . "\n";
        foreach ($entity['relations'] as $relation) {
            echo 'relation=' . $relation['type']
                . ' property=' . $relation['property']
                . ' target=' . $relation['target'];
            if ($relation['fk'] !== '') {
                echo ' fk=' . $relation['fk'];
            }
            if ($relation['joinTable'] !== '') {
                echo ' joinTable=' . $relation['joinTable'];
            }
            echo ' metadata=' . relationMetadataPresence($relation) . "\n";
        }
    }

    foreach (riskFlags($entities) as $risk) {
        echo 'risk=' . $risk . "\n";
    }
}

function expandHome(string $path): string
{
    if ($path === '~' || str_starts_with($path, '~/')) {
        $home = rtrim((string) ($_SERVER['HOME'] ?? ''), '/');
        return $home . substr($path, 1);
    }
    return $path;
}

/**
 * @return list<array{fqcn: string, kind: string, table: string, columns: list<string>, relations: list<array{type: string, property: string, target: string, fk: string, joinTable: string}>}>
 */
function collectEntities(string $root): array
{
    $entityDir = rtrim($root, '/') . '/src/Entity';
    if (!is_dir($entityDir)) {
        return [];
    }

    $entities = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($entityDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $entity = parseEntityFile((string) $file->getRealPath());
        if ($entity !== null) {
            $entities[] = $entity;
        }
    }

    usort($entities, static fn(array $a, array $b): int => $a['fqcn'] <=> $b['fqcn']);
    return $entities;
}

/**
 * @return array{fqcn: string, kind: string, table: string, columns: list<string>, relations: list<array{type: string, property: string, target: string, fk: string, joinTable: string}>}|null
 */
function parseEntityFile(string $path): ?array
{
    $src = @file_get_contents($path);
    if (!is_string($src) || $src === '') {
        return null;
    }
    if (!preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $src, $namespaceMatch)) {
        return null;
    }
    if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $src, $classMatch)) {
        return null;
    }

    $namespace = $namespaceMatch[1];
    $className = $classMatch[1];
    $fqcn = $namespace . '\\' . $className;
    $useMap = parseUseMap($src);

    $table = '';
    if (preg_match('/#\[ORM\\\\Table\s*\(\s*name\s*:\s*[\'"]([^\'"]+)[\'"]/i', $src, $tableMatch)) {
        $table = $tableMatch[1];
    }
    if ($table === '') {
        return null;
    }

    $columns = [];
    preg_match_all('/#\[ORM\\\\Column\s*\(([^)]*)\)\](.{0,600}?)\$(\w+)\s*;/s', $src, $columnMatches, PREG_SET_ORDER);
    foreach ($columnMatches as $row) {
        $attrs = $row[1];
        $property = $row[3];
        $column = attrString($attrs, 'name') ?: $property;
        $columns[] = $column;
    }
    $columns = array_values(array_unique($columns));
    sort($columns);

    $relations = parseRelations($src, $useMap, $namespace);

    return [
        'fqcn' => $fqcn,
        'kind' => classifyKind($fqcn, $className, $table),
        'table' => $table,
        'columns' => $columns,
        'relations' => $relations,
    ];
}

/**
 * @return array<string, string>
 */
function parseUseMap(string $src): array
{
    $map = [];
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $src, $matches, PREG_SET_ORDER);
    foreach ($matches as $row) {
        $fqcn = $row[1];
        $alias = $row[2] ?? '';
        $short = $alias !== '' ? $alias : basename(str_replace('\\', '/', $fqcn));
        $map[$short] = $fqcn;
    }
    return $map;
}

/**
 * @param array<string, string> $useMap
 * @return list<array{type: string, property: string, target: string, fk: string, joinTable: string}>
 */
function parseRelations(string $src, array $useMap, string $namespace): array
{
    $relations = [];
    preg_match_all(
        '/#\[ORM\\\\(ManyToOne|OneToMany|ManyToMany|OneToOne)\s*\(([^)]*)\)\](.{0,900}?)\$(\w+)\s*;/s',
        $src,
        $matches,
        PREG_SET_ORDER,
    );

    foreach ($matches as $row) {
        $type = $row[1];
        $attrs = $row[2];
        $between = $row[3];
        $property = $row[4];
        $targetRaw = attrClass($attrs, 'targetEntity');
        $target = resolveClass($targetRaw, $useMap, $namespace);
        $fk = '';
        if (preg_match('/#\[ORM\\\\JoinColumn\s*\(([^)]*)\)\]/s', $between, $joinColumn)) {
            $fk = attrString($joinColumn[1], 'name');
        }
        $joinTable = '';
        if (preg_match('/#\[ORM\\\\JoinTable\s*\(([^)]*)\)\]/s', $between, $joinTableMatch)) {
            $joinTable = attrString($joinTableMatch[1], 'name');
        }
        $relations[] = [
            'type' => $type,
            'property' => $property,
            'target' => $target,
            'fk' => $fk,
            'joinTable' => $joinTable,
        ];
    }

    usort($relations, static fn(array $a, array $b): int => [$a['type'], $a['property']] <=> [$b['type'], $b['property']]);
    return $relations;
}

function attrString(string $attrs, string $name): string
{
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*:\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
        return $m[1];
    }
    return '';
}

function attrClass(string $attrs, string $name): string
{
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*:\s*([\\\\\w]+)::class/', $attrs, $m)) {
        return $m[1];
    }
    if (preg_match('/\b' . preg_quote($name, '/') . '\s*:\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * @param array<string, string> $useMap
 */
function resolveClass(string $raw, array $useMap, string $namespace): string
{
    if ($raw === '') {
        return '';
    }
    $raw = ltrim($raw, '\\');
    if (str_contains($raw, '\\')) {
        return $raw;
    }
    return $useMap[$raw] ?? ($namespace . '\\' . $raw);
}

function classifyKind(string $fqcn, string $className, string $table): string
{
    $haystack = strtolower($fqcn . ' ' . $className . ' ' . $table);
    if (str_contains($haystack, 'pagepart') || str_contains($haystack, 'page_part')) {
        return 'pagepart';
    }
    if (str_contains($haystack, 'page')) {
        return 'page';
    }
    if (str_contains($haystack, 'taxonomy') || str_contains($haystack, 'dataprovider') || str_contains($haystack, 'data_provider')) {
        return 'taxonomy_or_data_provider';
    }
    return 'entity';
}

/**
 * @param array{type: string, property: string, target: string, fk: string, joinTable: string} $relation
 */
function relationMetadataPresence(array $relation): string
{
    $bits = [];
    if ($relation['fk'] !== '') {
        $bits[] = 'fk';
    }
    if ($relation['joinTable'] !== '') {
        $bits[] = 'joinTable';
    }
    if ($relation['target'] !== '') {
        $bits[] = 'target';
    }
    return $bits === [] ? 'none' : implode(',', $bits);
}

/**
 * @param list<array{fqcn: string, kind: string, table: string, columns: list<string>, relations: list<array{type: string, property: string, target: string, fk: string, joinTable: string}>}> $entities
 * @return list<string>
 */
function riskFlags(array $entities): array
{
    $risks = [];
    foreach ($entities as $entity) {
        foreach ($entity['relations'] as $relation) {
            if ($relation['fk'] !== '' && preg_match('/(^|_)media(_id|id$)/i', $relation['fk'])) {
                $risks[] = 'media_fk table=' . $entity['table'] . ' column=' . $relation['fk'];
            }
            if ($relation['joinTable'] === '' && $relation['type'] === 'ManyToMany') {
                $risks[] = 'missing_join_table_metadata class=' . $entity['fqcn'] . ' property=' . $relation['property'];
            }
            if (preg_match('/(Taxonomy|Category|Tag|DataProvider)/i', $relation['target'])) {
                $risks[] = 'taxonomy_or_data_provider_like class=' . $relation['target'];
            }
        }
        if (preg_match('/(seo|redirect|route|url)/i', $entity['table'])) {
            $risks[] = 'seo_or_redirect_table table=' . $entity['table'];
        }
    }
    $risks = array_values(array_unique($risks));
    sort($risks);
    return $risks;
}
