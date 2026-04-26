<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use lameco\kunstmaanmigrator\source\DoctrineEntityInfo;
use lameco\kunstmaanmigrator\source\DoctrineColumnInfo;
use lameco\kunstmaanmigrator\source\DoctrineRelationInfo;
use Craft;
use Throwable;
use yii\base\Component;

/**
 * Parses Doctrine PHP entity files from the Kunstmaan source checkout to
 * extract authoritative structure: table names, column definitions, and ORM
 * relations.
 *
 * Principle: source code = structure, database = data.
 *   - Table names come from #[ORM\Table(name: '...')] (not information_schema guessing).
 *   - Column types and nullability come from #[ORM\Column(...)] (not DB DATA_TYPE).
 *   - Relations come from #[ORM\ManyToOne/ManyToMany/...] (not FK constraints).
 *
 * Supports PHP 8 attributes (#[ORM\...]) and legacy docblock annotations
 * (@ORM\...). Short targetEntity names are resolved to FQCNs via `use`
 * statement parsing.
 *
 * The parser is lazy — the entity directory is scanned once on first access and
 * the result cached for the lifetime of the request.
 */
final class DoctrineEntityParser extends Component
{
    /** Absolute path to the Kunstmaan source checkout root (sourceCheckoutPath config). */
    public string $sourceCheckoutPath = '';

    /** @var array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo>|null FQCN → info map; null = unwarmed */
    private ?array $byFqcn = null;

    /** @var array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo>|null table name → info map; null = unwarmed */
    private ?array $byTable = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Resolve an FQCN to its DoctrineEntityInfo, or null if not found.
     */
    public function getByFqcn(string $fqcn): ?DoctrineEntityInfo
    {
        $this->warm();
        return $this->byFqcn[$fqcn] ?? null;
    }

    /**
     * Resolve a table name to its DoctrineEntityInfo, or null if not found.
     */
    public function getByTable(string $table): ?DoctrineEntityInfo
    {
        $this->warm();
        return $this->byTable[$table] ?? null;
    }

    /**
     * Return all parsed entities keyed by FQCN.
     *
     * @return array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo>
     */
    public function getAll(): array
    {
        $this->warm();
        return $this->byFqcn ?? [];
    }

    // -------------------------------------------------------------------------
    // Lazy warm
    // -------------------------------------------------------------------------

    private function warm(): void
    {
        if ($this->byFqcn !== null) {
            return;
        }

        $this->byFqcn  = [];
        $this->byTable = [];

        if ($this->sourceCheckoutPath === '') {
            return;
        }

        $entityDir = rtrim($this->sourceCheckoutPath, '/') . '/src/Entity';
        if (!is_dir($entityDir)) {
            Craft::warning(
                "DoctrineEntityParser: entity directory not found at {$entityDir}",
                __METHOD__,
            );
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entityDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            try {
                $info = $this->parseFile((string) $file->getRealPath());
                if ($info !== null) {
                    $this->byFqcn[$info->fqcn]    = $info;
                    $this->byTable[$info->tableName] = $info;
                }
            } catch (Throwable $e) {
                Craft::warning(
                    "DoctrineEntityParser: failed to parse {$file->getRealPath()}: {$e->getMessage()}",
                    __METHOD__,
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // File parser
    // -------------------------------------------------------------------------

    private function parseFile(string $path): ?DoctrineEntityInfo
    {
        $src = @file_get_contents($path);
        if ($src === false || $src === '') {
            return null;
        }

        // --- Namespace + class name → FQCN
        if (!preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $src, $m)) {
            return null;
        }
        $namespace = $m[1];

        if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $src, $m)) {
            return null;
        }
        $className = $m[1];
        $fqcn = $namespace . '\\' . $className;

        // --- Table name (PHP 8 attribute or docblock)
        $tableName = '';
        if (preg_match('/#\[ORM\\\\Table\s*\(\s*name\s*:\s*[\'"]([^\'"]+)[\'"]/i', $src, $m)) {
            $tableName = $m[1];
        } elseif (preg_match('/@ORM\\\\Table\s*\(\s*name\s*=\s*[\'"]([^\'"]+)[\'"]/i', $src, $m)) {
            $tableName = $m[1];
        }

        // Entities without an explicit @Table belong to a parent class — skip.
        if ($tableName === '') {
            return null;
        }

        // --- Build use-statement map: short name → FQCN
        $useMap = $this->parseUseStatements($src);

        // --- Columns
        $columns = $this->parseColumns($src);

        // --- Relations
        $relations = $this->parseRelations($src, $useMap, $namespace);

        return new DoctrineEntityInfo($fqcn, $tableName, $columns, $relations);
    }

    // -------------------------------------------------------------------------
    // Use-statement map
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>  short class name → FQCN
     */
    private function parseUseStatements(string $src): array
    {
        $map = [];
        // Matches: use Some\Namespace\ClassName; or use Some\Namespace\ClassName as Alias;
        preg_match_all(
            '/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m',
            $src,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $row) {
            $fqcn  = $row[1];
            $alias = $row[2] ?? '';
            $short = $alias !== '' ? $alias : (string) substr(strrchr($fqcn, '\\') ?: '\\' . $fqcn, 1);
            $map[$short] = $fqcn;
        }
        return $map;
    }

    // -------------------------------------------------------------------------
    // Column parser
    // -------------------------------------------------------------------------
    /**
     * @return \lameco\kunstmaanmigrator\source\DoctrineColumnInfo[]
     */
    private function parseColumns(string $src): array
    {
        $columns = [];

        // Match #[ORM\Column(...)] followed (within ≤600 chars) by $propertyName.
        // The lookahead stops at the next attribute/property to avoid bleeding across fields.
        preg_match_all(
            '/#\[ORM\\\\Column\s*\(([^)]*)\)\](.{0,600}?)\$(\w+)\s*;/s',
            $src,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $row) {
            $attrs    = $row[1];
            $property = $row[3];

            $colName = $property;
            if (preg_match('/\bname\s*:\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
                $colName = $m[1];
            } elseif (preg_match('/\bname\s*=\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
                $colName = $m[1];
            }

            $type = 'string';
            if (preg_match('/\btype\s*:\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
                $type = $m[1];
            } elseif (preg_match('/\btype\s*=\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
                $type = $m[1];
            }

            $nullable = (bool) preg_match('/\bnullable\s*[=:]\s*true\b/', $attrs);

            $columns[] = new DoctrineColumnInfo($colName, $type, $nullable, $property);
        }

        // Docblock fallback (@ORM\Column) — only when no PHP 8 columns found.
        if ($columns === []) {
            preg_match_all(
                '/@ORM\\\\Column\s*\(([^)]*)\)\s*(?:\*\s+@var[^\n]*)?\s*\*\/\s*(?:private|protected|public)\s+[^\$]*\$(\w+)/s',
                $src,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $row) {
                $attrs    = $row[1];
                $property = $row[2];

                $colName = $property;
                if (preg_match('/\bname\s*=\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
                    $colName = $m[1];
                }
                $type = 'string';
                if (preg_match('/\btype\s*=\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
                    $type = $m[1];
                }
                $nullable = (bool) preg_match('/\bnullable\s*=\s*true\b/', $attrs);
                $columns[] = new DoctrineColumnInfo($colName, $type, $nullable, $property);
            }
        }

        return $columns;
    }

    // -------------------------------------------------------------------------
    // Relation parser
    // -------------------------------------------------------------------------
    /**
     * @param array<string, string> $useMap
     * @return \lameco\kunstmaanmigrator\source\DoctrineRelationInfo[]
     */
    private function parseRelations(string $src, array $useMap, string $namespace): array
    {
        $relations = [];

        // Collect JoinColumn FK names keyed by the property that follows them.
        // Pattern: #[ORM\JoinColumn(name: 'col', ...)] … $property
        $joinCols = [];
        preg_match_all(
            '/#\[ORM\\\\JoinColumn\s*\(([^)]*)\)\](.{0,500}?)\$(\w+)\s*;/s',
            $src,
            $jcMatches,
            PREG_SET_ORDER,
        );
        foreach ($jcMatches as $row) {
            $attrs    = $row[1];
            $property = $row[3];
            $colName  = null;
            if (preg_match('/\bname\s*:\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
                $colName = $m[1];
            }
            if ($colName !== null) {
                $joinCols[$property] = $colName;
            }
        }

        foreach (['ManyToOne', 'OneToMany', 'ManyToMany', 'OneToOne'] as $relType) {
            // PHP 8 attribute: #[ORM\ManyToOne(targetEntity: Employee::class)] … $property
            preg_match_all(
                '/#\[ORM\\\\' . $relType . '\s*\(([^)]*)\)\](.{0,500}?)\$(\w+)\s*;/s',
                $src,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $row) {
                $attrs    = $row[1];
                $property = $row[3];

                $targetFqcn = $this->resolveTargetEntity($attrs, $useMap, $namespace);
                if ($targetFqcn === '') {
                    continue;
                }

                $fkColumn = $joinCols[$property] ?? null;
                $relations[] = new DoctrineRelationInfo($relType, $targetFqcn, $property, $fkColumn);
            }
        }

        return $relations;
    }

    /**
     * Extract and resolve the targetEntity value from an ORM relation attribute string.
     *
     * Handles:
     *   targetEntity: Employee::class          → resolved via use map
     *   targetEntity: \Full\Ns\Employee::class → already FQCN
     *   targetEntity: 'App\Entity\Employee'    → already FQCN string
     *
     * @param array<string, string> $useMap
     */
    private function resolveTargetEntity(string $attrs, array $useMap, string $namespace): string
    {
        // ::class form — e.g. Employee::class or \Full\Ns\Employee::class
        if (preg_match('/\btargetEntity\s*:\s*([\\\\\w]+)::class/', $attrs, $m)) {
            $ref = ltrim($m[1], '\\');
            // Already fully qualified (contains backslash or found in use map as-is)
            if (str_contains($ref, '\\')) {
                return $ref;
            }
            // Resolve via use statement
            return $useMap[$ref] ?? ($namespace . '\\' . $ref);
        }

        // String form — e.g. targetEntity: 'App\Entity\Employee'
        if (preg_match('/\btargetEntity\s*:\s*[\'"]([^\'"]+)[\'"]/', $attrs, $m)) {
            return ltrim($m[1], '\\');
        }

        // Docblock annotation form — targetEntity="Employee" or targetEntity=Employee::class
        if (preg_match('/\btargetEntity\s*=\s*[\'"]?([\\\\?\w]+)(?::class)?[\'"]?/', $attrs, $m)) {
            $ref = ltrim(trim($m[1], '\'"'), '\\');
            if (str_contains($ref, '\\')) {
                return $ref;
            }
            return $useMap[$ref] ?? ($namespace . '\\' . $ref);
        }

        return '';
    }
}
