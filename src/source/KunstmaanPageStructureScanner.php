<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
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
 *         '\App\Entity\Pages\NewsPage' => [
 *             'tableName'  => 'lameco_websitebundle_newspages',
 *             'contexts'   => [
 *                 ['name' => 'news_article', 'allowedPagePartClasses' => [
 *                     ['class' => '\Kunstmaan\PagePartBundle\Entity\HeaderPagePart',
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
 *   - PHP source is parsed via nikic/php-parser AST. NEVER include/require —
 *     the source is data, not code (T-02.1-04-01 mitigation).
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
        return [];
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
        return null;
    }

    /**
     * Parse every `*.yml` file under `{sourcePath}/config/kunstmaancms/pageparts/`
     * and return a context-name → allowed-class list index:
     *
     *   ['main' => [['name' => 'Header', 'class' => '\Kunstmaan\...\HeaderPagePart'], ...]]
     *
     * Returns `[]` when the directory is absent (PHP-only fallback per
     * CONTEXT D-31 Discretion — doctor already WARNed).
     *
     * @return array<string, list<array{name: string, class: string}>>
     */
    private function scanPagePartsYaml(string $sourcePath): array
    {
        return [];
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
        return [];
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
        return [];
    }
}
