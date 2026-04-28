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
 * Parses Doctrine entity files. PHP 8 attributes only.
 *
 * Source code = structure: table names from #[ORM\Table], columns from
 * #[ORM\Column], relations from #[ORM\ManyToOne]/etc. Short targetEntity
 * names resolve to FQCNs via `use` statement parsing. Lazy — scans the
 * entity directory once on first access and caches the result.
 *
 * Phase 8 / Plan 08-02 / D-10: in addition to the `Doctrine\ORM\Mapping\*`
 * namespace, the parser also scans the `Gedmo\Mapping\Annotation\*`
 * namespace for `#[Gedmo\Translatable]` (and equivalent short-class /
 * FQCN forms via the use-map). Each `DoctrineColumnInfo` exposes the
 * per-property `isGedmoTranslatable` flag — signal #1 of the union the
 * `TaxonomyMigrationService` consumes.
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

        // --- Table name
        $tableName = '';
        if (preg_match('/#\[ORM\\\\Table\s*\(\s*name\s*:\s*[\'"]([^\'"]+)[\'"]/i', $src, $m)) {
            $tableName = $m[1];
        }

        // Entities without an explicit @Table belong to a parent class — skip.
        if ($tableName === '') {
            return null;
        }

        // --- Build use-statement map: short name → FQCN
        $useMap = $this->parseUseStatements($src);

        // --- Columns (use-map drives Gedmo Translatable short-class resolution per Plan 08-02 / D-10)
        $columns = $this->parseColumns($src, $useMap);

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
     * @param array<string, string> $useMap short class name → FQCN (drives Gedmo resolution)
     * @return \lameco\kunstmaanmigrator\source\DoctrineColumnInfo[]
     */
    private function parseColumns(string $src, array $useMap = []): array
    {
        $columns = [];

        // Match #[ORM\Column(...)] followed (within ≤600 chars) by $propertyName.
        // Capture also the offset of the matched ORM\Column attribute so we
        // can look for Gedmo attributes that may appear BEFORE the column
        // attribute on the same property.
        preg_match_all(
            '/#\[ORM\\\\Column\s*\(([^)]*)\)\](.{0,600}?)\$(\w+)\s*;/s',
            $src,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $row) {
            $attrs        = $row[1][0];
            $property     = $row[3][0];
            $matchOffset  = $row[0][1];
            $matchEnd     = $row[3][1] + strlen($property);

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

            // Plan 08-02 / D-10 — Gedmo Translatable per-property flag.
            // Inspect the source span owned by this property: from the
            // prior `;`/`{` boundary up to (and including) the matched
            // `#[ORM\Column]` + tail captured before $property;. Gedmo
            // attributes can appear before OR after #[ORM\Column].
            $isGedmoTranslatable = $this->propertyHasGedmoTranslatable(
                $src,
                $matchOffset,
                $matchEnd,
                $useMap,
            );

            $columns[] = new DoctrineColumnInfo(
                $colName,
                $type,
                $nullable,
                $property,
                $isGedmoTranslatable,
            );
        }

        return $columns;
    }

    // -------------------------------------------------------------------------
    // Gedmo Translatable detection (Plan 08-02 / D-10 signal #1)
    // -------------------------------------------------------------------------

    /**
     * Detect `#[Gedmo\Translatable]` (or any use-map / FQCN equivalent of
     * `Gedmo\Mapping\Annotation\Translatable`) within the source span
     * belonging to a single property declaration.
     *
     * The "property span" is the textual region from the previous statement
     * boundary (`;` or `{`) up to the property declaration end. Both
     * orderings — `#[Gedmo\Translatable] #[ORM\Column]` and `#[ORM\Column]
     * #[Gedmo\Translatable]` — are recognized.
     *
     * SRC-20 invariant: docblock `@Gedmo\Translatable` is NOT recognized.
     * Only PHP 8 attribute syntax (`#[...]`) flips the flag.
     *
     * @param array<string, string> $useMap short class name → FQCN
     */
    private function propertyHasGedmoTranslatable(
        string $src,
        int $columnAttrOffset,
        int $propertyEndOffset,
        array $useMap,
    ): bool {
        // Walk backwards from columnAttrOffset to find the previous `;` or
        // `{` — that's the start of this property's attribute block. This
        // anchors the lookbehind so a Gedmo attribute on the PRIOR property
        // doesn't leak into this property's flag.
        $blockStart = 0;
        for ($i = $columnAttrOffset - 1; $i >= 0; $i--) {
            $ch = $src[$i];
            if ($ch === ';' || $ch === '{' || $ch === '}') {
                $blockStart = $i + 1;
                break;
            }
        }

        $span = substr($src, $blockStart, $propertyEndOffset - $blockStart);

        // Strip docblocks — SRC-20 invariant: only PHP 8 attributes count.
        // A `@Gedmo\Translatable` inside `/** ... */` MUST NOT flip the flag.
        $span = (string) preg_replace('!/\*.*?\*/!s', '', $span);

        // Match every `#[Foo\Bar]` (or `#[\Foo\Bar]`, `#[Foo]`) attribute
        // header in the span. Only the leading class name matters for FQCN
        // resolution; argument lists are irrelevant for Translatable
        // (it takes no positional args we care about).
        if (
            !preg_match_all(
                '/#\[\s*\\\\?([\w\\\\]+)/',
                $span,
                $attrMatches,
            )
        ) {
            return false;
        }

        $gedmoFqcn = 'Gedmo\\Mapping\\Annotation\\Translatable';

        foreach ($attrMatches[1] as $attrName) {
            $resolved = $this->resolveAttributeFqcn($attrName, $useMap);
            if ($resolved === $gedmoFqcn) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve an attribute class name as written (e.g. `Gedmo\Translatable`,
     * `Translatable`, `\Gedmo\Mapping\Annotation\Translatable`) to its FQCN
     * via the file's use-map. Mirrors the convention used by
     * `resolveTargetEntity()` for ManyToOne targetEntity short names.
     *
     * @param array<string, string> $useMap short class name → FQCN
     */
    private function resolveAttributeFqcn(string $attrName, array $useMap): string
    {
        $name = ltrim($attrName, '\\');
        if ($name === '') {
            return '';
        }

        // Already fully qualified: e.g. `Gedmo\Mapping\Annotation\Translatable`
        // (with leading `\` stripped above). Match against the canonical FQCN
        // shape directly.
        if (strpos($name, '\\') !== false) {
            // First segment may be a use-map alias (`Gedmo\Translatable` where
            // `use Gedmo\Mapping\Annotation as Gedmo;`). Try alias-prefix
            // resolution before treating the whole string as an FQCN.
            [$head, $rest] = explode('\\', $name, 2);
            if (isset($useMap[$head])) {
                return $useMap[$head] . '\\' . $rest;
            }
            return $name;
        }

        // Bare short name: `Translatable` — must come from a `use` statement.
        return $useMap[$name] ?? $name;
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
     * Resolve a targetEntity value to an FQCN. Handles `Employee::class` (use-map),
     * `\Full\Ns\Employee::class` (already FQCN), and `'App\Entity\Employee'` (string).
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

        return '';
    }
}
