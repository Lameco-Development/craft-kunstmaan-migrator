<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Source;

/**
 * The static half of introspection — what a checkout says without being booted.
 *
 * Two scans no Doctrine metadata carries: the sidecar entities a NodeListener wires into
 * the page editing UI (found in the listener code, not the schema), and which entity
 * fields each form type actually draws (found in the form code). Both are regex over
 * source, deliberately: they run against checkouts that may not boot — a PHP 7 legacy
 * site on a PHP 8 machine — and their output is advisory input to `validate`, not
 * something the compiler executes.
 *
 * Also the boot fallback: when the kernel cannot start, entity tables and columns are
 * read from the ORM attributes, marked `mode: static` so the artifact says how much to
 * trust it (no resolved inheritance, associations best-effort).
 */
final class SourceScanner
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Entities a NodeListener attaches to pages through the polymorphic ref.
     *
     * Reads `AdaptFormEvent` listeners for the `findOrCreateFor` pattern: the repository
     * class asked, the tab name shown, the gate method that switches the tab per page
     * type, and the exclude-fields method that narrows it. Advisory by design — a corpus
     * with a hand-rolled variant still shows up in the sidecar *table* discovery, which
     * keys on columns, not code.
     *
     * @return list<array{entity: string, tab: ?string, gate: ?string, excludeFields: ?string}>
     */
    public function sidecarListeners(): array
    {
        $out = [];

        foreach ($this->files('/src/EventListener') as $source) {
            if (!str_contains($source, 'AdaptFormEvent')) {
                continue;
            }

            // One block per findOrCreateFor: the repository names the entity; the tab
            // name, gate and exclude-fields follow within the same statement group.
            $offset = 0;

            while (preg_match(
                '/getRepository\((\\\\?[\w\\\\]+)::class\)\s*->findOrCreateFor/',
                $source,
                $m,
                PREG_OFFSET_CAPTURE,
                $offset,
            ) === 1) {
                $entity = ltrim($m[1][0], '\\');
                $start = (int) $m[0][1];
                $offset = $start + strlen($m[0][0]);
                // The gate guards the block, so it sits *before* the repository call;
                // the tab name and exclude-fields follow it.
                $before = substr($source, max(0, $start - 400), min(400, $start));
                $window = substr($source, $start, 1200);

                // A window that runs into the next findOrCreateFor block reads that
                // block's tab name and exclude-fields as this one's.
                $own = (int) strpos($window, 'findOrCreateFor');
                $next = strpos($window, 'findOrCreateFor', $own + strlen('findOrCreateFor'));

                if ($next !== false) {
                    $window = substr($window, 0, $next);
                }
                $gates = [];
                preg_match_all('/method_exists\(\$\w+,\s*[\'"](get\w+)[\'"]\)/', $before, $gm);

                foreach ($gm[1] as $gate) {
                    if (!str_ends_with($gate, 'ExcludeFields')) {
                        $gates[] = $gate;
                    }
                }

                $out[] = [
                    'entity' => str_contains($entity, '\\') ? $entity : $this->resolveImport($source, $entity),
                    'tab' => preg_match('/new Tab\(\s*[\'"]([^\'"]+)[\'"]/', $window, $t) === 1 ? $t[1] : null,
                    'gate' => $gates !== [] ? end($gates) : null,
                    'excludeFields' => preg_match('/[\'"](get\w*ExcludeFields)[\'"]/', $window, $x) === 1 ? $x[1] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Which entity fields each form type draws — the editor-facing set.
     *
     * @return array<string, array{entity: ?string, fields: list<string>}>
     */
    public function formTypes(): array
    {
        $out = [];

        foreach ($this->files('/src/Form', withPath: true) as $path => $source) {
            if (!str_contains($source, 'AbstractType') && !str_contains($source, 'FormBuilderInterface')) {
                continue;
            }

            preg_match_all('/->add\(\s*[\'"](\w+)[\'"]/', $source, $adds);
            $entity = null;

            if (preg_match('/[\'"]data_class[\'"]\s*=>\s*(\\\\?[\w\\\\]+)::class/', $source, $dc) === 1) {
                $short = ltrim($dc[1], '\\');
                $entity = str_contains($short, '\\') ? $short : $this->resolveImport($source, $short);
            }

            if ($adds[1] === [] && $entity === null) {
                continue;
            }

            $class = $this->classOf($source) ?? basename((string) $path, '.php');
            $out[$class] = ['entity' => $entity, 'fields' => array_values(array_unique($adds[1]))];
        }

        return $out;
    }

    /**
     * Entities from ORM attributes, for a checkout that cannot boot.
     *
     * Columns come from the class and every trait it uses (recursively — a trait may use
     * another), and the foreign keys of its owning ManyToOne/OneToOne associations are recorded
     * as `joinColumns`, so `columnsOf()` sees what the table really has. A JoinColumn inside a
     * `JoinTable` belongs to the join table and is left out. Inherited columns from a parent
     * class are still not resolved — that needs the booted metadata.
     *
     * @return array<string, array<string, mixed>> FQCN => {table, columns, associations}
     */
    public function staticEntities(): array
    {
        $out = [];
        $traits = $this->traitIndex();

        foreach ($this->files('/src/Entity', withPath: true) as $path => $source) {
            $matched = preg_match('/#\[ORM\\\\Table\(\s*name:\s*[\'"]([^\'"]+)[\'"]/', $source, $m)
                || preg_match('/@ORM\\\\Table\(\s*name\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m);

            if (!$matched) {
                continue;
            }

            $class = $this->classOf($source) ?? basename((string) $path, '.php');
            $columns = [];
            $associations = [];
            $seen = [];

            foreach ([$source, ...$this->usedTraits($source, $traits, $seen)] as $body) {
                [$own, $joins] = $this->propertiesOf($body);
                $columns += $own;
                $associations = [...$associations, ...$joins];
            }

            // Join columns alone are not an account of the table: an entity whose scalar columns
            // all come from a parent the scan cannot resolve (Kunstmaan's AbstractArticlePage)
            // would read as having only its foreign keys, and every inherited column it maps
            // would warn. No columns keeps `validate` silent about it, as it was.
            $out[$class] = [
                'table' => $m[1],
                'columns' => $columns,
                'associations' => $columns === [] ? [] : $associations,
            ];
        }

        return $out;
    }

    /**
     * The column and join-column declarations on one class or trait body.
     *
     * Each property's preamble — the attributes between the previous statement and the
     * property — is read on its own, so a JoinColumn is only ever attributed to the property
     * it decorates.
     *
     * @return array{0: array<string, array{column: string}>, 1: list<array{field: string, kind: string, joinColumns: list<string>}>}
     */
    private function propertiesOf(string $source): array
    {
        $columns = [];
        $associations = [];
        // Docblocks can hold `;` and braces (`{@inheritdoc}`), which would cut a preamble short.
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        preg_match_all(
            '/(?:private|protected|public)(?:\s+(?:readonly|static))*(?:\s+\??[\w\\\\|]+)?\s+\$(\w+)\s*[;=]/',
            $source,
            $props,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($props as $prop) {
            $offset = (int) $prop[0][1];
            $before = substr($source, 0, $offset);
            $cut = max((int) strrpos($before, ';'), (int) strrpos($before, '{'), (int) strrpos($before, '}'));
            $preamble = substr($before, $cut);
            $field = $prop[1][0];

            if (preg_match('/#\[ORM\\\\Column\(\s*name:\s*[\'"]([^\'"]+)[\'"]/', $preamble, $col) === 1) {
                $columns[$field] = ['column' => $col[1]];

                continue;
            }

            if (preg_match('/#\[ORM\\\\(ManyToOne|OneToOne)\b/', $preamble, $kind) !== 1 || str_contains($preamble, 'JoinTable')) {
                continue;
            }

            preg_match_all('/#\[ORM\\\\JoinColumn\(\s*name:\s*[\'"]([^\'"]+)[\'"]/', $preamble, $joins);

            if ($joins[1] !== []) {
                $associations[] = ['field' => $field, 'kind' => $kind[1], 'joinColumns' => $joins[1]];
            }
        }

        return [$columns, $associations];
    }

    /**
     * Every `trait` declared under `src/`, by FQCN.
     *
     * @return array<string, string> FQCN => source
     */
    private function traitIndex(): array
    {
        $out = [];

        foreach ($this->files('/src') as $source) {
            if (preg_match('/namespace\s+([\w\\\\]+);/', $source, $ns) === 1
                && preg_match('/\ntrait\s+(\w+)/', $source, $tr) === 1) {
                $out[$ns[1] . '\\' . $tr[1]] = $source;
            }
        }

        return $out;
    }

    /**
     * The sources of the traits a class or trait body uses, followed transitively.
     *
     * @param array<string, string> $traits FQCN => source
     * @param array<string, true>   $seen   guards against a trait cycle
     * @return list<string>
     */
    private function usedTraits(string $source, array $traits, array &$seen): array
    {
        $open = preg_match('/\n(?:final\s+|abstract\s+)*(?:class|trait)\s+\w+[^{]*\{/', $source, $decl, PREG_OFFSET_CAPTURE) === 1
            ? (int) $decl[0][1] + strlen($decl[0][0])
            : null;

        if ($open === null) {
            return [];
        }

        $namespace = preg_match('/namespace\s+([\w\\\\]+);/', $source, $ns) === 1 ? $ns[1] : '';
        preg_match_all('/^\s*use\s+([\w\\\\]+(?:\s*,\s*[\w\\\\]+)*)\s*[;{]/m', substr($source, $open), $uses);
        $out = [];

        foreach ($uses[1] as $list) {
            foreach (array_map('trim', explode(',', $list)) as $name) {
                $fqcn = match (true) {
                    str_starts_with($name, '\\') => ltrim($name, '\\'),
                    ($resolved = $this->resolveImport($source, $name)) !== $name => $resolved,
                    default => ltrim($namespace . '\\' . $name, '\\'),
                };

                if (isset($seen[$fqcn]) || !isset($traits[$fqcn])) {
                    continue;
                }

                $seen[$fqcn] = true;
                $out = [$traits[$fqcn], ...$this->usedTraits($traits[$fqcn], $traits, $seen), ...$out];
            }
        }

        return $out;
    }

    /** The FQCN a file declares, from its namespace and class lines. */
    private function classOf(string $source): ?string
    {
        if (preg_match('/namespace\s+([\w\\\\]+);/', $source, $ns) === 1
            && preg_match('/\n(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/', $source, $cl) === 1) {
            return $ns[1] . '\\' . $cl[1];
        }

        return null;
    }

    /** A short class name back to its FQCN through the file's use statements. */
    private function resolveImport(string $source, string $short): string
    {
        return preg_match('/use\s+([\w\\\\]+\\\\' . preg_quote($short, '/') . ');/', $source, $m) === 1
            ? $m[1]
            : $short;
    }

    /**
     * @return array<int|string, string> file source, keyed by path when asked
     */
    private function files(string $dir, bool $withPath = false): array
    {
        $base = rtrim($this->root, '/') . $dir;

        if (!is_dir($base)) {
            return [];
        }

        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $source = (string) file_get_contents($file->getPathname());
                $withPath ? $out[$file->getPathname()] = $source : $out[] = $source;
            }
        }

        ksort($out);

        return $out;
    }
}
