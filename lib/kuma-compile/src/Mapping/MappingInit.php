<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\EntityTableIndex;
use Lameco\KumaCompile\Legacy\Introspection;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Throwable;

/**
 * The one `init` engine.
 *
 * `./craft kunstmaan-migrator/mapping/init` and the standalone
 * `kuma-compile init` are both thin adapters over this class: same
 * `NAME=database` grammar, same entity-resolution ladder
 * (introspection artifact > source checkout > nothing), same skeleton,
 * same refusal to overwrite. What differs per surface is option syntax
 * and where the DSN comes from — Craft settings vs environment.
 */
final class MappingInit
{
    /**
     * @param list<string> $pairs each `NAME=database`
     *
     * @return array<string, string> environment name => database
     *
     * @throws MappingException on a malformed pair
     */
    public static function parsePairs(array $pairs): array
    {
        $databases = [];

        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                throw new MappingException(sprintf('expected NAME=database, got `%s`', $pair));
            }

            [$name, $database] = explode('=', $pair, 2);
            $databases[$name] = $database;
        }

        return $databases;
    }

    /**
     * @param array<string, string> $databases environment name => database
     *
     * @return array<string, LegacyDatabase>
     *
     * @throws MappingException naming the environment that could not be reached
     */
    public static function connect(array $databases, Dsn $dsn): array
    {
        $connections = [];

        foreach ($databases as $name => $database) {
            try {
                $connections[$name] = LegacyDatabase::connect($name, $database, $dsn);
            } catch (Throwable $e) {
                throw new MappingException(
                    sprintf('Cannot reach %s (%s): %s', $name, $database, $e->getMessage()),
                    0,
                    $e,
                );
            }
        }

        return $connections;
    }

    /**
     * Discover the corpus and generate the skeleton.
     *
     * The introspection artifact wins over the static source scan: it carries
     * exact entity tables, child-collection ownership and the entity candidates
     * from booted Doctrine metadata. With neither, table names are left as TODO
     * and the result says so.
     *
     * @param array<string, LegacyDatabase> $connections
     */
    public static function skeleton(
        array $connections,
        ?string $sourceRoot = null,
        ?string $introspectionPath = null,
    ): MappingInitResult {
        $introspection = $introspectionPath !== null ? Introspection::fromFile($introspectionPath) : null;
        $entities = match (true) {
            $introspection !== null => EntityTableIndex::fromIntrospection($introspection),
            $sourceRoot !== null => EntityTableIndex::fromSource($sourceRoot),
            default => EntityTableIndex::empty(),
        };

        return new MappingInitResult(
            (new Skeleton($entities, $introspection))->generate($connections),
            $entities->isEmpty(),
        );
    }

    /**
     * The mapping is the migration, and an accidental `init` over a finished
     * one is hours of decisions gone — so this refuses rather than overwrites.
     *
     * @throws MappingException when the path already holds a file
     */
    public static function write(string $path, string $yaml): void
    {
        if (is_file($path)) {
            throw new MappingException(sprintf('%s already exists — refusing to overwrite a mapping.', $path));
        }

        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $yaml);
    }
}
