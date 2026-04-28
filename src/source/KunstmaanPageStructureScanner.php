<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use Craft;
use FilesystemIterator;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use yii\base\Component;

/**
 * Reads the Kunstmaan source checkout's `src/Entity/Pages/*.php` and
 * `config/kunstmaancms/pageparts/*.yml` to build a per-Page-entity structured
 * record consumed by Plan 6 (`pageStructure.json`) and Plans 7-8 (page-part
 * proposal rows + block-availability validation).
 *
 * Contract (D-40 / D-42 step 4):
 *
 *   scan() returns:
 *       [
 *         'App\Entity\Pages\NewsPage' => [
 *             'tableName'  => 'lameco_websitebundle_newspages',
 *             'contexts'   => [
 *                 ['name' => 'news_article', 'allowedPagePartClasses' => [
 *                     ['class' => 'Kunstmaan\PagePartBundle\Entity\HeaderPagePart',
 *                      'table' => 'kuma_main_pageparts'],
 *                 ]],
 *             ],
 *             'templates'         => ['homepage'],
 *             'possibleChildTypes'=> [],
 *             'discriminatorMap'  => [],   // populated when entity has SINGLE_TABLE inheritance
 *             'sourcePath'        => '/abs/path/cqm-website/src/Entity/Pages/NewsPage.php',
 *         ],
 *         ...
 *       ]
 *
 * Strategy (D-30..D-43 + advisor):
 *   - PHP source is parsed via nikic/php-parser AST. The source file is
 *     read with file_get_contents and tokenised — never executed, never
 *     evaluated (T-02.1-04-01 mitigation).
 *   - YAML config is parsed via Symfony YAML at default flags (no
 *     PARSE_OBJECT — T-02.1-04-02 mitigation).
 *   - When `config/kunstmaancms/pageparts/` is absent, scanner falls back to
 *     PHP-only mode: contexts come from getPagePartAdminConfigurations()
 *     return values; allowedPagePartClasses stays empty per context.
 *     (Doctor's 5th check WARNs already; here we silently degrade.)
 *
 * Pipeline integration: registered as a Yii Component in Plan 5 alongside
 * KunstmaanSourceScanner. AnalyzeController consumes scan() output during
 * step 4 (D-42) and writes the structured array to
 * `storage/migration/pageStructure.json` via MappingFile::writeAtomicJson.
 */
final class KunstmaanPageStructureScanner extends Component
{
    /** Source-path resolver (D-33). Required at runtime; null fails closed. */
    public ?KunstmaanSourcePathResolver $pathResolver = null;

    /**
     * 4-tier FQCN → legacy detail-table resolver (Plan 02-03 port). Used to
     * fill `allowedPagePartClasses[*].table` once the YAML index has been
     * walked. May be null in unit tests; callers degrade to empty 'table'.
     */
    public ?DetailTableResolver $tableResolver = null;

    /**
     * Walk `{sourcePath}/src/Entity/Pages/*.php`, parse each via AST, fold in
     * allowed-page-part classes from `{sourcePath}/config/kunstmaancms/pageparts/*.yml`,
     * and return the structured per-FQCN record.
     *
     * Returns `[]` when the source path is unset or invalid (caller has
     * already FAILed via DoctorController's 5th check / AnalyzeController
     * gate; this is fail-closed defense in depth).
     *
     * @return array<string, array{
     *     tableName: string,
     *     contexts: list<array{name: string, allowedPagePartClasses: list<array{class: string, table: string}>}>,
     *     templates: list<string>,
     *     possibleChildTypes: list<string>,
     *     discriminatorMap: array<string, string>,
     *     sourcePath: string,
     * }>
     */
    public function scan(): array
    {
        if ($this->pathResolver === null) {
            return [];
        }
        $sourcePath = $this->pathResolver->resolve();
        if ($sourcePath === null) {
            return [];
        }

        // Build YAML index once: contextName → list of {name, class} (D-42 step 4).
        $yamlIndex = $this->scanPagePartsYaml($sourcePath);

        $pagesDir = $sourcePath . '/src/Entity/Pages';
        if (!is_dir($pagesDir)) {
            return [];
        }

        $out = [];
        $iterator = new FilesystemIterator(
            $pagesDir,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }
            $record = $this->scanPageEntity($fileInfo->getPathname());
            if ($record === null) {
                continue;
            }

            // Fill allowedPagePartClasses from the YAML index for each declared context.
            $filledContexts = [];
            foreach ($record['contexts'] as $ctx) {
                $contextName = $ctx['name'];
                $allowed = [];
                if (isset($yamlIndex[$contextName])) {
                    foreach ($yamlIndex[$contextName] as $entry) {
                        $cls = ltrim($entry['class'], '\\');
                        $table = '';
                        if ($this->tableResolver !== null) {
                            try {
                                $table = $this->tableResolver->resolve($cls);
                            } catch (InvalidArgumentException $e) {
                                // IN-04: emit info (not warning) so operators can grep
                                // storage logs for page-part classes whose legacy table
                                // the resolver legitimately could not map.
                                Craft::info(
                                    sprintf(
                                        'KunstmaanPageStructureScanner: tableResolver->resolve(%s) unresolved: %s',
                                        $cls,
                                        $e->getMessage(),
                                    ),
                                    __METHOD__,
                                );
                                $table = '';
                            } catch (Throwable $e) {
                                Craft::warning(
                                    sprintf(
                                        'KunstmaanPageStructureScanner: tableResolver->resolve(%s) failed: %s',
                                        $cls,
                                        $e->getMessage(),
                                    ),
                                    __METHOD__,
                                );
                                $table = '';
                            }
                        }
                        $allowed[] = ['class' => $cls, 'table' => $table];
                    }
                }
                $filledContexts[] = [
                    'name' => $contextName,
                    'allowedPagePartClasses' => $allowed,
                ];
            }

            $fqcn = $record['fqcn'];
            $out[$fqcn] = [
                'tableName'          => $record['tableName'],
                'contexts'           => $filledContexts,
                'templates'          => $record['templates'],
                'possibleChildTypes' => $record['possibleChildTypes'],
                'discriminatorMap'   => $record['discriminatorMap'],
                'sourcePath'         => $record['sourcePath'],
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * Parse a single PHP entity file and return its per-FQCN record (without
     * the YAML-driven allowedPagePartClasses fill — that happens in scan()
     * after the YAML index is built once).
     *
     * @return array{
     *     fqcn: string,
     *     tableName: string,
     *     contexts: list<array{name: string, allowedPagePartClasses: list<array{class: string, table: string}>}>,
     *     templates: list<string>,
     *     possibleChildTypes: list<string>,
     *     discriminatorMap: array<string, string>,
     *     sourcePath: string,
     * }|null
     */
    private function scanPageEntity(string $phpPath): ?array
    {
        // Resolve FQCN by reading namespace + class declaration via AST.
        $fqcn = $this->extractClassFqcn($phpPath);
        if ($fqcn === null) {
            return null;
        }

        // Resolve legacy table via DetailTableResolver — may throw on miss.
        $tableName = '';
        if ($this->tableResolver !== null) {
            try {
                $tableName = $this->tableResolver->resolve($fqcn);
            } catch (InvalidArgumentException $e) {
                // IN-04: emit info (not warning) so operators can grep storage
                // logs for Page entities whose legacy table the resolver
                // legitimately could not map (downstream pageStructure.json
                // consumers would otherwise see empty tableName silently).
                Craft::info(
                    sprintf(
                        'KunstmaanPageStructureScanner: tableResolver->resolve(%s) unresolved: %s',
                        $fqcn,
                        $e->getMessage(),
                    ),
                    __METHOD__,
                );
                $tableName = '';
            } catch (Throwable $e) {
                Craft::warning(
                    sprintf(
                        'KunstmaanPageStructureScanner: tableResolver->resolve(%s) failed: %s',
                        $fqcn,
                        $e->getMessage(),
                    ),
                    __METHOD__,
                );
                $tableName = '';
            }
        }

        // getPagePartAdminConfigurations() → list of context names (strings).
        $rawContexts = $this->extractMethodReturnArray($phpPath, 'getPagePartAdminConfigurations');
        $contexts = [];
        foreach ($rawContexts as $ctx) {
            if (is_string($ctx) && $ctx !== '') {
                $contexts[] = ['name' => $ctx, 'allowedPagePartClasses' => []];
            }
        }

        // getPageTemplates() → list of template names (or structured arrays).
        $templates = [];
        foreach ($this->extractMethodReturnArray($phpPath, 'getPageTemplates') as $tpl) {
            if (is_string($tpl)) {
                $templates[] = $tpl;
            } elseif (is_array($tpl)) {
                // Structured template entry — try common Kunstmaan keys.
                if (isset($tpl['name']) && is_string($tpl['name'])) {
                    $templates[] = $tpl['name'];
                }
            }
        }

        // getPossibleChildTypes() — list of {name,class} arrays. We surface class FQCNs.
        $possibleChildTypes = [];
        foreach ($this->extractMethodReturnArray($phpPath, 'getPossibleChildTypes') as $entry) {
            if (is_string($entry)) {
                $possibleChildTypes[] = $entry;
            } elseif (is_array($entry) && isset($entry['class']) && is_string($entry['class'])) {
                $possibleChildTypes[] = $entry['class'];
            }
        }

        $discriminatorMap = $this->extractDiscriminatorMap($phpPath);

        return [
            'fqcn'               => $fqcn,
            'tableName'          => $tableName,
            'contexts'           => $contexts,
            'templates'          => $templates,
            'possibleChildTypes' => $possibleChildTypes,
            'discriminatorMap'   => $discriminatorMap,
            'sourcePath'         => $phpPath,
        ];
    }

    /**
     * Parse every `*.yml` file under `{sourcePath}/config/kunstmaancms/pageparts/`
     * and return a context-name → allowed-class list index:
     *
     *   ['main' => [['name' => 'Header', 'class' => '\Kunstmaan\...\HeaderPagePart'], ...]]
     *
     * Context name comes from the YAML map key under `kunstmaan_page_part.pageparts`
     * (NOT the inner `context:` field — Kunstmaan's own loader keys on the map key).
     *
     * Returns `[]` when the directory is absent (PHP-only fallback per
     * CONTEXT D-31 Discretion — doctor already WARNed).
     *
     * @return array<string, list<array{name: string, class: string}>>
     */
    private function scanPagePartsYaml(string $sourcePath): array
    {
        $dir = $sourcePath . '/config/kunstmaancms/pageparts';
        if (!is_dir($dir)) {
            return [];
        }

        $index = [];
        $iterator = new FilesystemIterator(
            $dir,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'yml') {
                continue;
            }
            try {
                // Default flags only — NO PARSE_OBJECT (T-02.1-04-02 mitigation).
                $parsed = Yaml::parseFile($fileInfo->getPathname()) ?? [];
            } catch (Throwable $e) {
                Craft::warning(
                    sprintf(
                        'KunstmaanPageStructureScanner: failed to parse %s: %s',
                        $fileInfo->getPathname(),
                        $e->getMessage(),
                    ),
                    __METHOD__,
                );
                continue;
            }
            if (!is_array($parsed)) {
                continue;
            }

            $pageparts = $parsed['kunstmaan_page_part']['pageparts'] ?? null;
            if (!is_array($pageparts)) {
                continue;
            }

            foreach ($pageparts as $contextKey => $contextDef) {
                if (!is_string($contextKey) || !is_array($contextDef)) {
                    continue;
                }
                $types = $contextDef['types'] ?? [];
                if (!is_array($types)) {
                    continue;
                }
                $entries = [];
                foreach ($types as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $name = isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '';
                    $class = isset($entry['class']) && is_string($entry['class']) ? $entry['class'] : '';
                    if ($class === '') {
                        continue;
                    }
                    $entries[] = ['name' => $name, 'class' => $class];
                }
                // Multiple YAML files may define the same context key — last writer wins
                // (mirrors Symfony's config-merge semantics).
                $index[$contextKey] = $entries;
            }
        }

        return $index;
    }

    /**
     * Walk a class file's AST, find a method matching `$methodName`, and
     * return the literal value of its first `return` statement (when that
     * value is an array literal). Recursively reconstructs scalar / nested-
     * array PHP values from `Node\Scalar\*` + `Node\Expr\Array_` nodes.
     *
     * Returns `[]` on parse failure, missing method, or non-array return.
     * Failures are logged via `Craft::warning` and swallowed.
     *
     * @return array<int|string, mixed>
     */
    private function extractMethodReturnArray(string $phpPath, string $methodName): array
    {
        $ast = $this->parsePhpFile($phpPath);
        if ($ast === null) {
            return [];
        }

        $found = null;
        $visitor = new class($methodName, $found) extends NodeVisitorAbstract {
            public function __construct(
                private readonly string $methodName,
                /** @var Node\Expr\Array_|null */
                private mixed &$found,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof Node\Stmt\ClassMethod) {
                    return null;
                }
                if ($node->name->name !== $this->methodName) {
                    return null;
                }
                foreach ($node->stmts ?? [] as $stmt) {
                    if ($stmt instanceof Node\Stmt\Return_
                        && $stmt->expr instanceof Node\Expr\Array_) {
                        $this->found = $stmt->expr;
                        return null;
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if (!$found instanceof Node\Expr\Array_) {
            return [];
        }

        $value = $this->arrayNodeToPhp($found);
        return is_array($value) ? $value : [];
    }

    /**
     * Walk a class file's AST, find a class-level `#[ORM\DiscriminatorMap([...])]`
     * attribute, and return the discriminator-value → FQCN map. Returns `[]`
     * when the attribute is absent (most pages — only single-table-inheritance
     * roots declare it).
     *
     * @return array<string, string>
     */
    private function extractDiscriminatorMap(string $phpPath): array
    {
        $ast = $this->parsePhpFile($phpPath);
        if ($ast === null) {
            return [];
        }

        $arrayNode = null;
        $visitor = new class($arrayNode) extends NodeVisitorAbstract {
            public function __construct(
                /** @var Node\Expr\Array_|null */
                private mixed &$arrayNode,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof Node\Stmt\Class_) {
                    return null;
                }
                foreach ($node->attrGroups as $group) {
                    foreach ($group->attrs as $attr) {
                        $name = $attr->name->toString();
                        // Match aliased ORM\DiscriminatorMap, fully-qualified
                        // Doctrine\ORM\Mapping\DiscriminatorMap, or any alias
                        // ending in \DiscriminatorMap.
                        if ($name === 'ORM\DiscriminatorMap'
                            || $name === 'Doctrine\ORM\Mapping\DiscriminatorMap'
                            || str_ends_with($name, '\DiscriminatorMap')
                            || $name === 'DiscriminatorMap') {
                            if (!isset($attr->args[0])) {
                                continue;
                            }
                            $value = $attr->args[0]->value;
                            if ($value instanceof Node\Expr\Array_) {
                                $this->arrayNode = $value;
                                return null;
                            }
                        }
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if (!$arrayNode instanceof Node\Expr\Array_) {
            return [];
        }

        $reconstructed = $this->arrayNodeToPhp($arrayNode);
        if (!is_array($reconstructed)) {
            return [];
        }

        $map = [];
        foreach ($reconstructed as $key => $value) {
            if (is_string($value) && (is_string($key) || is_int($key))) {
                $map[(string) $key] = $value;
            }
        }
        return $map;
    }

    /**
     * Resolve the fully-qualified class name declared in `$phpPath` by reading
     * its `namespace` + `class` AST nodes. Returns the leaf class with no
     * leading backslash. Returns null when the file declares no class.
     */
    private function extractClassFqcn(string $phpPath): ?string
    {
        $ast = $this->parsePhpFile($phpPath);
        if ($ast === null) {
            return null;
        }

        foreach ($ast as $top) {
            if ($top instanceof Node\Stmt\Namespace_) {
                $ns = $top->name !== null ? $top->name->toString() : '';
                foreach ($top->stmts as $inner) {
                    if ($inner instanceof Node\Stmt\Class_ && $inner->name !== null) {
                        return $ns !== '' ? $ns . '\\' . $inner->name->name : $inner->name->name;
                    }
                }
            } elseif ($top instanceof Node\Stmt\Class_ && $top->name !== null) {
                return $top->name->name;
            }
        }

        return null;
    }

    /**
     * Parse a PHP source file into its AST (an array of top-level statement
     * nodes). Returns null on read or parse failure (caller swallows + logs).
     *
     * Security (T-02.1-04-01 mitigation): file_get_contents + ParserFactory
     * only — bytes are read and tokenised, never executed or evaluated. The
     * file's contents never leave the AST tree.
     *
     * @return list<Node\Stmt>|null
     */
    private function parsePhpFile(string $phpPath): ?array
    {
        $code = @file_get_contents($phpPath);
        if ($code === false) {
            return null;
        }
        try {
            $parser = (new ParserFactory())->createForHostVersion();
            $ast = $parser->parse($code);
        } catch (Throwable $e) {
            Craft::warning(
                sprintf(
                    'KunstmaanPageStructureScanner: failed to parse %s: %s',
                    $phpPath,
                    $e->getMessage(),
                ),
                __METHOD__,
            );
            return null;
        }
        return $ast;
    }

    /**
     * Recursively reconstruct a PHP value from a `Node\Expr\Array_` AST node.
     * Supports scalar leaves (string / int / float / bool / null) and nested
     * arrays. Non-literal expressions (constant fetches, method calls,
     * concatenations) collapse to null and are filtered out by the caller.
     *
     * @return array<int|string, mixed>
     */
    private function arrayNodeToPhp(Node\Expr\Array_ $arrayNode): array
    {
        $out = [];
        $autoIndex = 0;
        foreach ($arrayNode->items as $item) {
            if (!$item instanceof Node\ArrayItem) {
                continue;
            }
            $value = $this->scalarNodeToPhp($item->value);
            if ($item->key === null) {
                $out[$autoIndex++] = $value;
            } else {
                $key = $this->scalarNodeToPhp($item->key);
                if (is_string($key) || is_int($key)) {
                    $out[$key] = $value;
                    if (is_int($key) && $key >= $autoIndex) {
                        $autoIndex = $key + 1;
                    }
                } else {
                    $out[$autoIndex++] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * Convert a single AST scalar / array / class-const expression to a PHP
     * value. Returns null for expressions we don't safely model (variable
     * references, method calls, binary ops).
     */
    private function scalarNodeToPhp(?Node $node): mixed
    {
        if ($node === null) {
            return null;
        }
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Scalar\Float_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            $name = strtolower($node->name->toLowerString());
            return match ($name) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => null,
            };
        }
        if ($node instanceof Node\Expr\Array_) {
            return $this->arrayNodeToPhp($node);
        }
        if ($node instanceof Node\Expr\ClassConstFetch) {
            // ::class — surface the class name as a string FQCN. Other
            // constants collapse to null (we don't resolve constant values).
            if ($node->name instanceof Node\Identifier
                && $node->name->name === 'class'
                && $node->class instanceof Node\Name) {
                return $node->class->toString();
            }
            return null;
        }
        return null;
    }
}
