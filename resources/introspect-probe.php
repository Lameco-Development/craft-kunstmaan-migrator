<?php

declare(strict_types=1);

/**
 * Doctrine metadata probe, run INSIDE the legacy app's own PHP context:
 *
 *   php introspect-probe.php /path/to/legacy-checkout
 *
 * Boots the legacy kernel with the legacy vendor/autoload and dumps every mapped
 * entity's table, columns and associations as JSON on stdout. Runs as a child
 * process on purpose: the legacy app's dependencies must never mix with the
 * compiler's, and a checkout that cannot boot must fail this process, not that one.
 *
 * Works against Doctrine ORM 2 (association mappings are arrays) and ORM 3
 * (mapping objects); metadata only, no database connection is required.
 */

error_reporting(E_ERROR | E_PARSE); // legacy deprecation noise must not corrupt the JSON

$root = rtrim($argv[1] ?? '', '/');

if ($root === '' || !is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "usage: php introspect-probe.php <legacy checkout with vendor/>\n");
    exit(2);
}

require $root . '/vendor/autoload.php';

if (is_file($root . '/.env') && class_exists(Symfony\Component\Dotenv\Dotenv::class)) {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');
}

$kernelClass = 'App\Kernel';

if (!class_exists($kernelClass)) {
    fwrite(STDERR, "No App\\Kernel in this checkout.\n");
    exit(2);
}

$kernel = new $kernelClass($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
$em = $doctrine->getManager();

/** Both ORM generations, one shape. */
$assoc = static function (object|array $mapping): array {
    $get = static fn (string $key): mixed => is_array($mapping) ? ($mapping[$key] ?? null) : ($mapping->$key ?? null);
    $type = $get('type');
    $names = [1 => 'OneToOne', 2 => 'ManyToOne', 4 => 'OneToMany', 8 => 'ManyToMany'];

    if (!is_int($type)) {
        // ORM 3 mapping objects know their own kind.
        $class = is_object($mapping) ? get_class($mapping) : '';
        $type = match (true) {
            str_contains($class, 'ManyToMany') => 8,
            str_contains($class, 'OneToMany') => 4,
            str_contains($class, 'ManyToOne') => 2,
            str_contains($class, 'OneToOne') => 1,
            default => 0,
        };
    }

    $joinTable = $get('joinTable');
    $joinTable = is_array($joinTable) ? ($joinTable['name'] ?? null) : (is_object($joinTable) ? ($joinTable->name ?? null) : null);

    $joinColumns = [];

    foreach ((array) ($get('joinColumns') ?? []) as $jc) {
        $name = is_array($jc) ? ($jc['name'] ?? null) : ($jc->name ?? null);

        if ($name !== null) {
            $joinColumns[] = (string) $name;
        }
    }

    return array_filter([
        'field' => $get('fieldName'),
        'kind' => $names[$type] ?? 'unknown',
        'target' => $get('targetEntity'),
        'mappedBy' => $get('mappedBy') ?: null,
        'inversedBy' => $get('inversedBy') ?: null,
        'joinTable' => $joinTable,
        'joinColumns' => $joinColumns !== [] ? $joinColumns : null,
        'orderBy' => $get('orderBy') ?: null,
    ], static fn ($v) => $v !== null);
};

$entities = [];

foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
    $columns = [];

    foreach ($meta->fieldMappings as $field => $mapping) {
        $get = static fn (string $key): mixed => is_array($mapping) ? ($mapping[$key] ?? null) : ($mapping->$key ?? null);
        $columns[(string) $field] = array_filter([
            'column' => $get('columnName') ?? $field,
            'type' => $get('type'),
            'nullable' => $get('nullable') ?? false,
            'length' => $get('length'),
        ], static fn ($v) => $v !== null);
    }

    $entities[$meta->getName()] = array_filter([
        'table' => $meta->getTableName(),
        'columns' => $columns,
        'associations' => array_values(array_map($assoc, $meta->associationMappings)),
        'mappedSuperclass' => $meta->isMappedSuperclass ?: null,
        'parent' => $meta->rootEntityName !== $meta->getName() ? $meta->rootEntityName : null,
        'discriminatorMap' => $meta->discriminatorMap !== [] ? $meta->discriminatorMap : null,
    ], static fn ($v) => $v !== null && $v !== []);
}

echo json_encode([
    'mode' => 'boot',
    'source' => $root,
    'entities' => $entities,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
