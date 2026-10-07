<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

/**
 * Doctrine entity short name => table name, read from a Kunstmaan source checkout.
 *
 * The legacy database alone cannot tell you which table backs `FeaturePagePart`: the refs
 * table stores the FQCN, and Kunstmaan's table names are declared in PHP attributes that
 * follow no derivable convention (`lameco_websitebundle_textcolumn_page_parts` for
 * `TextColumnPagePart`, `faq_page_parts` for `FaqPagePart`). So the skeleton generator
 * reads them from source when a checkout is available, and leaves a TODO when it is not.
 */
final class EntityTableIndex
{
    /**
     * @param array<string, string> $tables short entity name (no PagePart suffix) => table
     * @param array<string, list<array{table: string, fk: string}>> $children owner short name => collections
     * @param array<string, string> $qualifiedTables fully qualified entity => table; what tells two
     *        namespaces sharing a short name apart (the app's `TextPagePart` and Kunstmaan's)
     * @param array<string, list<array{table: string, fk: string}>> $qualifiedChildren fully qualified owner => collections
     */
    private function __construct(
        private readonly array $tables,
        private readonly array $children = [],
        private readonly array $qualifiedTables = [],
        private readonly array $qualifiedChildren = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * The same index, from a committed introspection artifact instead of a fresh scan.
     *
     * Booted metadata is exact where the regex scan is best-effort: inheritance is resolved
     * and every owning ManyToOne carries its join column, so child-collection ownership
     * needs no attribute-order gymnastics. A static-mode artifact degrades gracefully — its
     * associations are empty, which leaves the children map empty and the caller on the
     * naming heuristic, exactly as if no source had been scanned.
     */
    public static function fromIntrospection(Introspection $introspection): self
    {
        $tables = [];
        $children = [];
        $qualifiedTables = [];
        $qualifiedChildren = [];

        foreach ($introspection->entities as $class => $spec) {
            if (!is_array($spec) || ($spec['mappedSuperclass'] ?? false) || !isset($spec['table'])) {
                continue;
            }

            $parts = explode('\\', (string) $class);
            $basename = (string) end($parts);
            $tables[PartClass::shortName($basename)] = (string) $spec['table'];
            $qualifiedTables[PartClass::normalize((string) $class)] = (string) $spec['table'];

            foreach ((array) ($spec['associations'] ?? []) as $assoc) {
                $target = (string) ($assoc['target'] ?? '');
                $joinColumns = (array) ($assoc['joinColumns'] ?? []);

                if (($assoc['kind'] ?? '') !== 'ManyToOne' || !str_ends_with($target, 'PagePart') || $joinColumns === []) {
                    continue;
                }

                $targetParts = explode('\\', $target);
                $collection = ['table' => (string) $spec['table'], 'fk' => (string) $joinColumns[0]];
                $children[PartClass::shortName((string) end($targetParts))][] = $collection;
                $qualifiedChildren[PartClass::normalize($target)][] = $collection;
            }
        }

        $byTable = static fn(array $a, array $b): int => $a['table'] <=> $b['table'];

        foreach ($children as &$collections) {
            usort($collections, $byTable);
        }

        unset($collections);

        foreach ($qualifiedChildren as &$collections) {
            usort($collections, $byTable);
        }

        unset($collections);

        return new self($tables, $children, $qualifiedTables, $qualifiedChildren);
    }

    /** Scans `<source>/src/Entity` for `#[ORM\Table(name: '...')]` and its annotation form. */
    public static function fromSource(string $sourceRoot): self
    {
        $entityDir = rtrim($sourceRoot, '/') . '/src/Entity';

        if (!is_dir($entityDir)) {
            throw new \RuntimeException(sprintf('No Doctrine entities at %s', $entityDir));
        }

        $tables = [];
        $children = [];
        $qualifiedTables = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($entityDir));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            $matched = preg_match('/#\[ORM\\\\Table\(\s*name:\s*[\'"]([^\'"]+)[\'"]/', $source, $m)
                || preg_match('/@ORM\\\\Table\(\s*name\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m);

            if (!$matched) {
                continue;
            }

            $class = $file->getBasename('.php');
            $tables[PartClass::shortName($class)] = $m[1];

            if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $ns) === 1) {
                $qualifiedTables[PartClass::normalize($ns[1] . '\\' . $class)] = $m[1];
            }

            $owner = self::ownerOf($source);

            if ($owner !== null) {
                $children[PartClass::shortName($owner['entity'])][] = ['table' => $m[1], 'fk' => $owner['fk']];
            }
        }

        foreach ($children as &$collections) {
            usort($collections, static fn(array $a, array $b): int => $a['table'] <=> $b['table']);
        }

        unset($collections);

        // A scan reads the app's own entities, and its relations are written against the short
        // name in that namespace, so a scanned class's collections are the short name's.
        return new self($tables, $children, $qualifiedTables, self::scannedChildren($qualifiedTables, $children));
    }

    /**
     * @param array<string, string> $qualifiedTables
     * @param array<string, list<array{table: string, fk: string}>> $children
     * @return array<string, list<array{table: string, fk: string}>>
     */
    private static function scannedChildren(array $qualifiedTables, array $children): array
    {
        $out = [];

        foreach (array_keys($qualifiedTables) as $class) {
            $collections = $children[PartClass::shortName((string) $class)] ?? [];

            if ($collections !== []) {
                $out[(string) $class] = $collections;
            }
        }

        return $out;
    }

    /**
     * The pagepart a child-collection entity belongs to, read from its owning ManyToOne.
     *
     * Reading the relation rather than guessing from the column name matters: in this corpus
     * `UserStoryItem` targets `UserStoriesPagePart` through a join column named
     * `block_link_pp_id`, so a name-based heuristic attributes it to the wrong part.
     *
     * @return array{entity: string, fk: string}|null
     */
    private static function ownerOf(string $source): ?array
    {
        $pattern = '/#\[ORM\\\\JoinColumn\(\s*name:\s*[\'"]([^\'"]+)[\'"][^\]]*\]\s*'
            . '#\[ORM\\\\ManyToOne\(\s*targetEntity:\s*([A-Za-z0-9_]+PagePart)::class/';

        if (preg_match($pattern, $source, $m) === 1) {
            return ['entity' => $m[2], 'fk' => $m[1]];
        }

        // The two attributes also appear in the opposite order.
        $reverse = '/#\[ORM\\\\ManyToOne\(\s*targetEntity:\s*([A-Za-z0-9_]+PagePart)::class[^\]]*\]\s*'
            . '#\[ORM\\\\JoinColumn\(\s*name:\s*[\'"]([^\'"]+)[\'"]/';

        if (preg_match($reverse, $source, $m) === 1) {
            return ['entity' => $m[1], 'fk' => $m[2]];
        }

        return null;
    }

    /**
     * Child collections owned by a pagepart, or null when no source checkout was scanned
     * (in which case the caller falls back to the `<token>_pp_id` naming heuristic).
     *
     * @return list<array{table: string, fk: string}>|null
     */
    public function childrenOf(string $class): ?array
    {
        if ($this->children === []) {
            return null;
        }

        if (PartClass::isQualified($class)) {
            return $this->qualifiedChildren[PartClass::normalize($class)] ?? [];
        }

        return $this->children[$class] ?? [];
    }

    /**
     * The table backing a class, by its short name or — where two namespaces share one — its
     * fully qualified name. A qualified name the index does not know has no table: falling back
     * to the short name would hand it the other namespace's.
     */
    public function tableFor(string $class): ?string
    {
        if (PartClass::isQualified($class)) {
            return $this->qualifiedTables[PartClass::normalize($class)] ?? null;
        }

        return $this->tables[$class] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }
}
