<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\legacy;

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
     */
    private function __construct(
        private readonly array $tables,
        private readonly array $children = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
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
            $tables[self::shortName($class)] = $m[1];
            $owner = self::ownerOf($source);

            if ($owner !== null) {
                $children[self::shortName($owner['entity'])][] = ['table' => $m[1], 'fk' => $owner['fk']];
            }
        }

        foreach ($children as &$collections) {
            usort($collections, static fn (array $a, array $b): int => $a['table'] <=> $b['table']);
        }

        return new self($tables, $children);
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
    public function childrenOf(string $shortClass): ?array
    {
        if ($this->children === []) {
            return null;
        }

        return $this->children[$shortClass] ?? [];
    }

    public function tableFor(string $shortClass): ?string
    {
        return $this->tables[$shortClass] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    private static function shortName(string $class): string
    {
        return str_ends_with($class, 'PagePart') ? substr($class, 0, -8) : $class;
    }
}
