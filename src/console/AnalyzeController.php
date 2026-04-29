<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\filter\FilterFactory;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\workflow\AnalyzeWorkflow;
use lameco\kunstmaanmigrator\source\CraftGraphContract;
use lameco\kunstmaanmigrator\source\KunstmaanGraphContract;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use yii\console\ExitCode;

/**
 * Analyze — single-action orchestration of the source-introspection + schema dumps +
 * heuristic + LLM proposal pipeline + report rendering. Collapses v1's 9-sub-action
 * AnalyzeController (2138 LOC) to one entrypoint per CONTEXT.md operator workflow.
 *
 * Pipeline (Phase 02.1 / D-42 — 11 steps; was 8 in Phase 2 / Plan 03):
 *   1.   NeverProduction gate (FIRST statement — Phase 1 / D-20)
 *   1.5  KUNSTMAAN_SOURCE_PATH gate (Phase 02.1 / D-31)
 *   2.   FilterFactory.fromCli + LocalePreflight::ensure (D-17 LOC-02)
 *   3.   KunstmaanSourceScanner::scan → entity index + table list (D-40 left side)
 *   4.   KunstmaanPageStructureScanner::scan → page-structure FQCN map (D-40 right)
 *   4.5  Page-part proposal emitter — pageStructure → mapping rows (D-35, locked here per advisor)
 *   5.   pageStructure.json write (storageDir/pageStructure.json — atomic JSON)
 *   6.   KunstmaanSchemaDumper::dump (consumes scan['tables']) → kunstmaan-schema.json
 *   7.   HeuristicProposer::autoMatch with heuristic 1.5 entity context (D-44)
 *   8.   LlmClassifier::batchPropose — KB markdown via v1-shaped adapter (B2 fix)
 *   9.   MappingFile::merge with skip-existing semantics (D-04 + D-34 kind-prefixed tuple)
 *   10.  MappingAuditor::audit (Plan 8 extends with BlockAvailabilityValidator)
 *   11.  ReportBuilder::render → REPORT.md
 *
 * Exit:
 *   - ExitCode::OK on green
 *   - ExitCode::CONFIG on locale-preflight FAIL or KUNSTMAAN_SOURCE_PATH unset/invalid
 *   - ExitCode::UNSPECIFIED_ERROR on any other FAIL
 *
 * --audit-strict (D-16): step 10's mapping-audit elevates non-empty drift findings
 * to fail-state when this flag is set; warn-only by default. Drift findings persist
 * to MAPPING-AUDIT.md regardless so operators always have an audit trail.
 */
class AnalyzeController extends Controller
{
    use NeverProductionTrait;

    public bool $noAi = false;
    public bool $autoAcceptHigh = false;
    public bool $auditStrict = false;
    // Phase 02.1 / D-32: declared-only here. Plan 5 wires KunstmaanSourceScanner to
    // consume this flag for drift-strict elevation (DB↔scan mismatch findings go
    // from WARN → fail when --source-strict is set; default is WARN).
    public bool $sourceStrict = false;
    // Phase 8 / D-14 — AI proposer scope gates (mirrors ADP-04 --no-seo / --no-retour
    // pattern). CLI per-run override of Settings::proposeLayout / Settings::proposeProviders.
    public bool $noLayout = false;
    public bool $noProviders = false;
    public ?string $entities = null;
    public ?string $locales = null;
    public ?string $since = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'noAi', 'autoAcceptHigh', 'auditStrict', 'sourceStrict',
            'noLayout', 'noProviders',  // NEW — Phase 8 / D-14
            'entities', 'locales', 'since',
        ]);
    }

    public function actionIndex(): int
    {
        // FND-04 / D-20: NeverProduction guard FIRST — before any legacy DB read.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $result = (new AnalyzeWorkflow())->run([
            'entities' => $this->entities,
            'locales' => $this->locales,
            'since' => $this->since,
            'noAi' => $this->noAi,
            'autoAcceptHigh' => $this->autoAcceptHigh,
            'auditStrict' => $this->auditStrict,
            'sourceStrict' => $this->sourceStrict,
            'noLayout' => $this->noLayout,
            'noProviders' => $this->noProviders,
        ], function (array $event): void {
            $stream = (string) ($event['stream'] ?? 'stdout');
            $message = (string) ($event['message'] ?? '');
            if ($stream === 'stderr') {
                $this->stderr($message);
                return;
            }
            $this->stdout($message);
        });

        return (int) ($result['summary']['exitCode'] ?? ExitCode::UNSPECIFIED_ERROR);
    }

    /**
     * D-02: heuristic confidence → initial status.
     * @param array<string, mixed> $proposal
     */
    private function statusForHeuristic(array $proposal): string
    {
        $confidence = (string) ($proposal['confidence'] ?? '');
        $decision = (string) ($proposal['decision'] ?? '');
        if ($decision === 'drop') {
            return 'dropped'; // zero-fill heuristic auto-drop (D-02 last row)
        }
        return $confidence === 'high' ? 'accepted' : 'proposed';
    }

    /**
     * D-02 + --no-ai semantics: LLM confidence → initial status; skip-LLM rows get needs-review.
     * @param array<string, mixed> $proposal
     */
    private function statusForLlm(array $proposal, bool $skipped): string
    {
        if ($skipped) {
            return 'needs-review';
        }
        $confidence = (string) ($proposal['confidence'] ?? 'low');
        return $confidence === 'high' ? 'proposed' : 'needs-review';
    }

    /**
     * Build heuristic-input violation rows from the schema dump.
     *
     * Phase 2 / Plan 03 ships only the orchestration primitives — Plan 05's
     * CoverageAuditor will produce a more accurate violation set by cross-
     * referencing existing mapping.yaml coverage and Craft FieldLayouts.
     *
     * For now, every column in every dumped table becomes a candidate
     * violation. The heuristic auto-drops zero-fill columns and matches the
     * rest against the (currently-empty) Craft field index.
     *
     * @param array<string, mixed> $schemaDump
     * @return list<array<string, mixed>>
     */
    private function buildViolationsFromSchema(array $schemaDump): array
    {
        $out = [];
        $columnsByTable = (array) ($schemaDump['columns'] ?? []);
        foreach ($columnsByTable as $table => $cols) {
            if (!is_array($cols)) { continue; }
            foreach ($cols as $c) {
                if (!is_array($c)) { continue; }
                $out[] = [
                    'table'           => (string) $table,
                    'column'          => (string) ($c['column'] ?? ''),
                    'targetEntryType' => '', // populated by Plan 05 CoverageAuditor
                    'fillRate'        => (float) ($c['fillRate'] ?? 0),
                    'sqlType'         => (string) ($c['sqlType'] ?? ''),
                    'samples'         => (array) ($c['samples'] ?? []),
                ];
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $kunstmaanGraph
     * @return list<array<string, mixed>>
     */
    private function annotateRowsWithKunstmaanRelationRefs(array $rows, array $kunstmaanGraph): array
    {
        if ($rows === []) {
            return [];
        }

        $relationRefsByColumn = [];
        $entities = (array) ($kunstmaanGraph[KunstmaanGraphContract::KEY_ENTITIES] ?? []);
        foreach ((array) ($kunstmaanGraph[KunstmaanGraphContract::KEY_RELATIONS] ?? []) as $relationRef => $relation) {
            if (!is_string($relationRef) || !is_array($relation)) {
                continue;
            }
            $sourceRef = (string) ($relation['sourceRef'] ?? '');
            $fkColumn = (string) ($relation['fkColumn'] ?? '');
            if ($sourceRef === '' || $fkColumn === '') {
                continue;
            }
            $source = $entities[$sourceRef] ?? [];
            if (!is_array($source)) {
                continue;
            }
            $table = (string) ($source['table'] ?? '');
            if ($table === '') {
                continue;
            }
            $relationRefsByColumn[$table . "\0" . $fkColumn] ??= $relationRef;
        }

        if ($relationRefsByColumn === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            if (!is_array($row) || (string) ($row['sourceRef'] ?? '') !== '') {
                continue;
            }
            $table = (string) ($row['table'] ?? '');
            $column = (string) ($row['column'] ?? '');
            if ($table === '' || $column === '') {
                continue;
            }
            $relationRef = $relationRefsByColumn[$table . "\0" . $column] ?? null;
            if (is_string($relationRef) && $relationRef !== '') {
                $row['sourceRef'] = $relationRef;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Build the Craft field index keyed by entry type.
     *
     * Phase 2 / Plan 03 ships an empty index — Plan 05 wires the live
     * Craft FieldLayout walk per CONTEXT.md MappingAuditor pattern. With an
     * empty index, the heuristic only fires the zero-fill auto-drop rule
     * (heuristic 1) and routes everything else to LLM residual (or to the
     * needs-review skip-stub when LLM is disabled).
     *
     * @return array<string, list<array{handle: string, type: string, classification?: string}>>
     */
    private function buildCraftFieldIndex(): array
    {
        return Plugin::getInstance()->craftKnowledgeBase->buildFieldIndex();
    }

    /**
     * Phase 8.7 / F1 — page-wins auto-folding for ManyToOne 1:1 wrapping pairs.
     *
     * For each scoped page FQCN, walks its parsed Doctrine ManyToOne
     * relations. When a relation's target is a project-defined entity AND
     * the name-match heuristic fires (`<X>Page` strips to `<X>` matching the
     * target's basename — e.g. EmployeePage→Employee), emits one synthetic
     * residual column row per non-id, non-FK column on the target. Source
     * column is shaped as `_rel:<property>.<column>` so the existing column
     * LLM (LlmClassifier::buildBatchPrompt: 2219) recognises it as an FK-
     * embedded column and the existing compile pipeline writes it to
     * `nodeClasses[<pageFqcn>].fields[<targetHandle>] = {handler, source: '_rel:...'}`
     * transparently.
     *
     * Returns `[syntheticRows, foldedTargetFqcns, mergeRelationProposals]`. The folded set is
     * consumed downstream to force `status: dropped, reason: superseded-
     * by-page` on the wrapped entity's taxonomy proposal — the canonical
     * entry is the page; the wrapped entity contributes via `_rel:` only
     * (governed by the saved `feedback_pages_lead.md` rule).
     * The merge proposals persist the runtime transform contract:
     * `nodeClasses[page].mergeRelations[rel] = {mode: flatten, table, fk, pk}`.
     *
     * Symmetric gating: name-match drives BOTH emission and drop. Page→
     * taxonomy ManyToOne FKs (e.g. CaseStudyPage→CaseStudyCategory) DO NOT
     * trigger this because `CaseStudyPage` strips to `CaseStudy`, not
     * `CaseStudyCategory`. Non-conventional 1:1 wraps fall through and the
     * operator hand-curates `_rel:<prop>.<col>` rows the way they do today.
     *
     * Runs unconditionally — deterministic structural walk, no AI calls.
     * Static + value-object inputs so the path is unit-testable without a
     * Craft + DB harness.
     *
     * @param  array<string, array<string, mixed>>                              $scopedPageStructure  pageStructure scoped by --entities
     * @param  array<string, \lameco\kunstmaanmigrator\source\DoctrineEntityInfo> $entityIndex  parser->getAll() — FQCN-keyed
     * @param  array<string, list<array<string, mixed>>>                        $columnsByTable  schemaDump.columns — table → list of {column, fillRate, sqlType, samples}
     * @param  array<string, string>                                            $pageTableToEntryType  page table → targetEntryType (from nodeClass proposals, all confidence tiers)
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: list<array<string, mixed>>}
     *         [syntheticRows, foldedTargetFqcns, mergeRelationProposals]
     */
    public static function emitPageWrapSyntheticColumns(
        array $scopedPageStructure,
        array $entityIndex,
        array $columnsByTable,
        array $pageTableToEntryType,
    ): array {
        $syntheticRows = [];
        $foldedFqcns = [];
        $mergeRelationProposals = [];
        $seenSyntheticKey = []; // dedupe within this run when two pages wrap the same target

        foreach ($scopedPageStructure as $pageFqcn => $pageRecord) {
            if (!is_string($pageFqcn) || !is_array($pageRecord)) {
                continue;
            }
            $pageTable = (string) ($pageRecord['tableName'] ?? '');
            if ($pageTable === '') {
                continue;
            }
            $pageInfo = $entityIndex[$pageFqcn] ?? null;
            if ($pageInfo === null) {
                continue;
            }
            $pageBase = self::basenameForFqcn($pageFqcn);
            // F1 name-match anchor: strip a trailing `Page` segment from the
            // page basename — that's the conventional Kunstmaan wrap shape.
            // Without the suffix the page isn't conventionally a wrapper.
            if (!str_ends_with($pageBase, 'Page')) {
                continue;
            }
            $strippedPageBase = substr($pageBase, 0, -strlen('Page'));
            if ($strippedPageBase === '') {
                continue;
            }

            foreach ($pageInfo->relations as $rel) {
                if ($rel->relationType !== 'ManyToOne') {
                    continue;
                }
                $targetFqcn = (string) $rel->targetEntity;
                if ($targetFqcn === '') {
                    continue;
                }
                $targetInfo = $entityIndex[$targetFqcn] ?? null;
                if ($targetInfo === null) {
                    // Vendor / Kunstmaan-core entity — parser didn't see it.
                    // Skip; vendor entities don't carry page-relevant content.
                    continue;
                }
                $targetBase = self::basenameForFqcn($targetFqcn);
                // Symmetric name-match — only fold when stripped page basename
                // equals target basename (EmployeePage→Employee yes;
                // CaseStudyPage→CaseStudyCategory no).
                if ($targetBase !== $strippedPageBase) {
                    continue;
                }

                $targetTable = $targetInfo->tableName;
                $relatedColumns = (array) ($columnsByTable[$targetTable] ?? []);
                // Build a quick lookup of FK column names on the target so we
                // skip them — they're structural FK plumbing, not content.
                $fkColsOnTarget = [];
                foreach ($targetInfo->relations as $tRel) {
                    if ($tRel->fkColumn !== null && $tRel->fkColumn !== '') {
                        $fkColsOnTarget[$tRel->fkColumn] = true;
                    }
                }

                $emittedAtLeastOne = false;
                foreach ($relatedColumns as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $colName = (string) ($c['column'] ?? '');
                    if ($colName === '' || $colName === 'id') {
                        continue;
                    }
                    if (isset($fkColsOnTarget[$colName])) {
                        continue;
                    }
                    $relColumn = '_rel:' . $rel->propertyName . '.' . $colName;
                    $key = $pageTable . '|' . $relColumn;
                    if (isset($seenSyntheticKey[$key])) {
                        continue;
                    }
                    $seenSyntheticKey[$key] = true;

                    $syntheticRows[] = [
                        'table'           => $pageTable,
                        'column'          => $relColumn,
                        // Fill from nodeClass proposals (any confidence tier);
                        // the downstream backfill block at lines 441-469 only
                        // promotes from high-confidence rows, so we set this
                        // here directly so synthetic rows carry the entry-type
                        // context the LLM needs to pick a targetHandle.
                        'targetEntryType' => (string) ($pageTableToEntryType[$pageTable] ?? ''),
                        'fillRate'        => (float) ($c['fillRate'] ?? 0),
                        'sqlType'         => (string) ($c['sqlType'] ?? ''),
                        'samples'         => (array) ($c['samples'] ?? []),
                    ];
                    $emittedAtLeastOne = true;
                }

                if ($emittedAtLeastOne) {
                    $foldedFqcns[$targetFqcn] = true;
                    if ((string) ($rel->fkColumn ?? '') !== '') {
                        $mergeRelationProposals[$pageFqcn] ??= [
                            'fqcn' => $pageFqcn,
                            'sourceTable' => $pageTable,
                            'targetEntryType' => (string) ($pageTableToEntryType[$pageTable] ?? ''),
                            'targetSection' => '',
                            'confidence' => 'high',
                            'rationale' => 'Name-matched page wrap: flatten related entity fields into the page-owned Craft entry.',
                            'mergeRelations' => [],
                        ];
                        $mergeRelationProposals[$pageFqcn]['mergeRelations'][$rel->propertyName] = [
                            'mode' => 'flatten',
                            'table' => $targetTable,
                            'fk' => (string) $rel->fkColumn,
                            'pk' => 'id',
                        ];
                    }
                }
            }
        }

        return [$syntheticRows, array_keys($foldedFqcns), array_values($mergeRelationProposals)];
    }

    /**
     * Phase 8.7 / F1 — basename helper that does NOT require the class to be
     * autoloadable (parser entities are read from source files; many won't
     * be on the include path during analyze).
     */
    private static function basenameForFqcn(string $fqcn): string
    {
        $tail = strrchr($fqcn, '\\');
        return $tail !== false ? substr($tail, 1) : $fqcn;
    }

    /**
     * Phase 8.6 / D-26 — build the two closed-set lookups the per-page-part
     * column proposer needs:
     *
     *   - `pagePartColumns`: pagePartFqcn → list<{column, type}> from the
     *     Doctrine entity parser. Source columns the LLM may map FROM.
     *   - `blockTypeFields`: blockTypeHandle → list<{handle, type}> from
     *     CraftKnowledgeBase::buildFieldIndex (block-types are entry-types
     *     in Craft 5; the index keys cover both pages and Matrix blocks).
     *     Target fields the LLM may map TO.
     *
     * Scoped to FQCNs / handles actually present in the resolved page-part
     * rows so we don't bloat the prompt with classes the proposer won't
     * touch this run.
     *
     * Phase 8.7 / D-30 also returns `pagePartRelations`: pagePartFqcn →
     * list<{property, type, targetFqcn, childTable, backRefColumn,
     * childColumns}> for OneToMany / ManyToMany relations the LLM can
     * resolve via the `relation` handler with `joinTable` options. ManyToOne
     * relations are excluded — those land on the parent row directly via
     * the 8.5 D-21 `_rel:<prop>.<col>` embed shape.
     *
     * @param  list<array<string, mixed>> $pagePartProposals
     * @param  array<string, string>      $m2mJoinByPair Phase 8.7 / D-29 — `<owningFqcn>|<targetFqcn>` → joinTable name
     * @return array{
     *   0: array<string, list<array{column: string, type: string}>>,
     *   1: array<string, list<array{handle: string, type: string}>>,
     *   2: array<string, list<array{property: string, type: string, targetFqcn: string, childTable: string, backRefColumn: string|null, childColumns: list<string>, joinTable?: string}>>
     * }
     */
    private function buildPagePartFieldsContext(array $pagePartProposals, array $m2mJoinByPair = []): array
    {
        $plugin = Plugin::getInstance();
        $parser = $plugin->doctrineEntityParser;
        $fieldIndex = $plugin->craftKnowledgeBase->buildFieldIndex();

        $pagePartColumns = [];
        $blockTypeFields = [];
        $pagePartRelations = [];
        foreach ($pagePartProposals as $row) {
            if (!is_array($row)) { continue; }
            $blockType = (string) ($row['targetBlockType'] ?? '');
            if ($blockType === '') { continue; }

            $ppFqcn = (string) ($row['pagePartClass'] ?? '');

            // Phase 8.7 / issue 6 Phase 2 — implicit-content rows have a
            // synthetic pagePartClass marker, not a real Doctrine class. The
            // parser returns null for them, which used to leave
            // $pagePartColumns empty → proposePagePartFields skipped the row
            // (no source-column list) → targetHandle never proposed →
            // MappingCompiler::emitImplicitContentRows dropped the row at
            // compile. Hydrate columns from the row's own fields[] (the
            // implicit emitter populated `sourceProperty` + `sqlType` from
            // the page table). Cache key is composite so multiple implicit
            // rows (CaseStudyPage/main vs ContactPage/main) don't collide.
            if ($ppFqcn === '__implicit_content__') {
                $key = self::implicitContentColumnsKey($row);
                if ($key !== null && !isset($pagePartColumns[$key])) {
                    $cols = [];
                    foreach ((array) ($row['fields'] ?? []) as $f) {
                        if (!is_array($f)) { continue; }
                        $col = (string) ($f['sourceProperty'] ?? '');
                        $sqlType = (string) ($f['sqlType'] ?? '');
                        if ($col === '') { continue; }
                        $cols[] = ['column' => $col, 'type' => $sqlType];
                    }
                    if ($cols !== []) {
                        $pagePartColumns[$key] = $cols;
                    }
                }
                // Implicit rows have no Doctrine relations; skip the parser
                // path entirely. Block-type fields hint still needs to land
                // in $blockTypeFields so the LLM gets the closed set.
                if (!isset($blockTypeFields[$blockType])) {
                    $rich = self::buildBlockTypeFieldsHint($fieldIndex[$blockType] ?? []);
                    if ($rich !== []) {
                        $blockTypeFields[$blockType] = $rich;
                    }
                }
                continue;
            }

            if ($ppFqcn !== '' && !isset($pagePartColumns[$ppFqcn])) {
                $info = $parser->getByFqcn($ppFqcn);
                if ($info !== null) {
                    $cols = [];
                    foreach ($info->columns as $c) {
                        $name = (string) $c->columnName;
                        if ($name === '' || $name === 'id') { continue; }
                        $cols[] = [
                            'column' => $name,
                            'type'   => (string) $c->type,
                        ];
                    }
                    if ($cols !== []) {
                        $pagePartColumns[$ppFqcn] = $cols;
                    }

                    // Phase 8.7 / D-29+D-30 — surface OneToMany + ManyToMany
                    // relation context so the LLM can propose `handler:
                    // relation` with `handlerOptions.joinTable=<child_table>`
                    // when the target field is Entries / Assets.
                    $rels = [];
                    foreach ($info->relations as $rel) {
                        if (!in_array($rel->relationType, ['OneToMany', 'ManyToMany'], true)) {
                            continue;
                        }
                        $targetInfo = $parser->getByFqcn($rel->targetEntity);
                        $childTable = $targetInfo?->tableName ?? '';
                        if ($childTable === '') { continue; }
                        // Find the back-ref column on the child by walking the
                        // target entity's ManyToOne relations for one whose
                        // targetEntity matches this parent FQCN. That gives us
                        // the joinLocalColumn (the FK from child → parent).
                        $backRef = null;
                        if ($targetInfo !== null) {
                            foreach ($targetInfo->relations as $childRel) {
                                if ($childRel->relationType === 'ManyToOne'
                                    && $childRel->targetEntity === $ppFqcn
                                    && $childRel->fkColumn !== null
                                    && $childRel->fkColumn !== ''
                                ) {
                                    $backRef = $childRel->fkColumn;
                                    break;
                                }
                            }
                        }
                        // Phase 8.7 / D-30 — childCols feeds the LLM prompt
                        // as the candidate list for `joinForeignColumn`. Exclude
                        // `id` (PK) AND the back-ref FK (already surfaced
                        // separately as `backRef`) so the LLM picks from
                        // payload columns only. Without this, the LLM was
                        // confusing `clients_pp_id` (back-ref) and `weight`
                        // (ordering column) with the actual asset/entry FK.
                        $childCols = $targetInfo !== null
                            ? array_values(array_filter(array_map(
                                static fn($c): string => (string) $c->columnName,
                                $targetInfo->columns,
                            ), static fn(string $n): bool => $n !== '' && $n !== 'id' && $n !== $backRef))
                            : [];
                        $entry = [
                            'property'      => $rel->propertyName,
                            'type'          => $rel->relationType,
                            'targetFqcn'    => $rel->targetEntity,
                            'childTable'    => $childTable,
                            'backRefColumn' => $backRef,
                            'childColumns'  => $childCols,
                        ];
                        // Phase 8.7 / D-29 — for ManyToMany, surface the
                        // auto-discovered (or annotation-found) join table
                        // name. The LLM uses this for `joinTable` in
                        // handlerOptions when proposing the relation handler.
                        if ($rel->relationType === 'ManyToMany') {
                            $jt = $m2mJoinByPair[$ppFqcn . '|' . $rel->targetEntity] ?? '';
                            if ($jt !== '') {
                                $entry['joinTable'] = $jt;
                            }
                        }
                        $rels[] = $entry;
                    }
                    if ($rels !== []) {
                        $pagePartRelations[$ppFqcn] = $rels;
                    }
                }
            }

            if (!isset($blockTypeFields[$blockType])) {
                $rich = self::buildBlockTypeFieldsHint($fieldIndex[$blockType] ?? []);
                if ($rich !== []) {
                    $blockTypeFields[$blockType] = $rich;
                }
            }
        }
        return [$pagePartColumns, $blockTypeFields, $pagePartRelations];
    }

    /**
     * Phase 8.7 / issue 6 Phase 2 — composite cache key for implicit-content
     * page-part rows. Bare `__implicit_content__` collides across pages
     * (CaseStudyPage/main and ContactPage/main both share the literal),
     * which would overwrite each other's column lists in $pagePartColumns.
     * The triple `<marker>|<parent>|<context>` mirrors the identity tuple
     * MappingFile::merge uses for page-part rows.
     *
     * @param  array<string, mixed> $row
     */
    private static function implicitContentColumnsKey(array $row): ?string
    {
        $parent = (string) ($row['parentPageClass'] ?? '');
        $context = (string) ($row['context'] ?? '');
        if ($parent === '' || $context === '') {
            return null;
        }
        return '__implicit_content__|' . $parent . '|' . $context;
    }

    /**
     * Phase 8.7 / issue 6 Phase 2 — extracted from buildPagePartFieldsContext
     * so the implicit-content branch can reuse the same closed-set field
     * shape (handle, type, options, allowedBlockTypes, sources, allowedKinds)
     * that the Doctrine path produces. Single source of truth for the
     * "what does the LLM see for allowedBlockFields" projection.
     *
     * @param  list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    private static function buildBlockTypeFieldsHint(array $fields): array
    {
        $rich = [];
        foreach ($fields as $f) {
            if (!is_array($f)) { continue; }
            $h = (string) ($f['handle'] ?? '');
            // Skip dotted-path Matrix-sub entries here — block-types rarely
            // contain nested Matrix fields, and when they do the column
            // proposer is the wrong tool. Flat fields only.
            if ($h === '' || str_contains($h, '.')) { continue; }
            // Phase 8.6 / D-28 — pass through the type-specific meta
            // (options / allowedBlockTypes / sources / allowedKinds) emitted
            // by CraftKnowledgeBase::describeField. The LLM prompt builder
            // renders them inline so name-matches can't ride past closed-set
            // constraints unchecked.
            $entry = [
                'handle' => $h,
                'type'   => (string) ($f['type'] ?? ''),
            ];
            foreach (['options', 'allowedBlockTypes', 'sources', 'allowedKinds'] as $metaKey) {
                if (isset($f[$metaKey]) && is_array($f[$metaKey]) && $f[$metaKey] !== []) {
                    $entry[$metaKey] = $f[$metaKey];
                }
            }
            $rich[] = $entry;
        }
        return $rich;
    }

    /**
     * Phase 8.5 / D-23 — build the relation-graph.json payload.
     *
     * Walks every parsed Doctrine entity and emits one record per FQCN that
     * owns at least one ManyToOne relation. The shape mirrors the LLM-facing
     * Relations table in `KunstmaanKnowledgeBase::renderPagesMarkdown` (D-20) and the
     * `_rel:<prop>.<col>` keys produced by `ExtractService::joinManyToOneRelations`
     * (D-21) so operators can correlate the three artifacts.
     *
     * Output:
     *   {
     *     "App\\Entity\\Pages\\EmployeePage": {
     *       "manyToOne": [
     *         {
     *           "property": "employee",
     *           "targetEntity": "App\\Entity\\Employee",
     *           "fkColumn": "employee_id",
     *           "targetTable": "lameco_websitebundle_employee_employees"
     *         }
     *       ]
     *     }
     *   }
     *
     * Empty array when the parser is null or no ManyToOne relations exist —
     * caller falls back to skipping the file write rather than emitting `[]`.
     *
     * @return array<string, array{manyToOne: list<array{property: string, targetEntity: string, fkColumn: string|null, targetTable: string}>}>
     */
    private function buildRelationGraph(): array
    {
        $parser = Plugin::getInstance()->doctrineEntityParser;
        $out = [];
        foreach ($parser->getAll() as $fqcn => $info) {
            $manyToOne = [];
            foreach ($info->relations as $rel) {
                if ($rel->relationType !== 'ManyToOne') {
                    continue;
                }
                $targetTable = $parser->getByFqcn($rel->targetEntity)?->tableName ?? '';
                $manyToOne[] = [
                    'property'     => $rel->propertyName,
                    'targetEntity' => $rel->targetEntity,
                    'fkColumn'     => $rel->fkColumn,
                    'targetTable'  => $targetTable,
                ];
            }
            if ($manyToOne === []) {
                continue;
            }
            $out[(string) $fqcn] = ['manyToOne' => $manyToOne];
        }
        return $out;
    }

    /**
     * Resolve a class FQCN to its short name. Tries ReflectionClass first (handles
     * autoloaded classes correctly); falls back to a string split on the last `\`
     * when the class is not loadable (operator's source file may reference a class
     * that doesn't exist on the consumer's autoload path — T-02.1-07-01 mitigation).
     *
     * ReflectionClass does NOT autoload via include/require for getShortName(); it
     * only consults the loaded class table. Safe to call on operator-supplied FQCNs.
     */
    private function shortClassName(string $fqcn): string
    {
        try {
            return (new \ReflectionClass($fqcn))->getShortName();
        } catch (\ReflectionException) {
            $tail = strrchr($fqcn, '\\');
            return $tail !== false ? substr($tail, 1) : $fqcn;
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @param list<string> $fallbackHandles
     * @return list<string>
     */
    private function candidateEntryTypeHandles(array $mapping, array $fallbackHandles): array
    {
        $handles = [];
        foreach ((array) ($mapping['proposals'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['targetEntryType', 'entryType'] as $key) {
                $handle = (string) ($row[$key] ?? '');
                if ($handle !== '') {
                    $handles[] = $handle;
                }
            }
        }

        if ($handles === []) {
            $handles = $fallbackHandles;
        }

        sort($handles);

        return array_values(array_unique($handles));
    }

    /**
     * Build a v1-shaped KB mapping adapter from v2's flat `proposals[]` array (B2 fix).
     *
     * KunstmaanKnowledgeBase::renderPagesMarkdown / renderPagePartsMarkdown read v1's nested
     * mapping shape:
     *   - $mapping['pageParts']   — keyed by pagePart FQCN
     *   - $mapping['nodeClasses'] — keyed by Page entity FQCN
     *   - $mapping['sections']    — keyed by section handle (column rows grouped)
     *
     * v2 stores rows in a FLAT `proposals[]` array — without this adapter, every
     * per-row annotation is silently lost (the placeholders would be replaced but
     * the mapping overlay would render empty).
     *
     * Buckets:
     *   - kind=pagePart rows → 'pageParts' keyed by pagePartClass FQCN
     *   - kind=column rows   → 'sections' keyed by targetEntryType
     *                          ('_unmapped' bucket for column rows lacking targetEntryType)
     *
     * 'nodeClasses' is reserved for Page entity FQCN annotations (v1 sources this
     * from a dedicated YAML key v2 doesn't ship). Leaving it empty is correct —
     * KunstmaanKnowledgeBase simply renders no per-Page annotations. Future work: derive
     * from accepted column rows whose table maps to a Page entity via heuristic 1.5.
     *
     * Public + static so the unit test (AnalyzeControllerKbAdapterTest) can call
     * it without instantiating the controller (which requires Craft's Yii base
     * wiring not available in the unit-test bootstrap).
     *
     * @param list<array<string, mixed>> $proposals  Pre-LLM proposals (heuristic-matched + emitted page-part rows)
     * @return array{pageParts: array<string, array<string, mixed>>, nodeClasses: array<string, mixed>, sections: array<string, list<array<string, mixed>>>}
     */
    public static function buildKbMappingAdapter(array $proposals): array
    {
        $adapter = [
            'pageParts'   => [],
            'nodeClasses' => [],
            'sections'    => [],
        ];
        foreach ($proposals as $row) {
            if (!is_array($row)) { continue; }
            $kind = (string) ($row['kind'] ?? 'column');
            if ($kind === 'pagePart') {
                $key = (string) ($row['pagePartClass'] ?? '');
                if ($key !== '') {
                    $adapter['pageParts'][$key] = $row;
                }
                continue;
            }
            // Column rows (default when 'kind' is unset — heuristic-matched proposals
            // come from autoMatch() which doesn't carry an explicit kind).
            $section = (string) ($row['targetEntryType'] ?? '');
            if ($section === '') { $section = '_unmapped'; }
            $adapter['sections'][$section][] = $row;
        }
        return $adapter;
    }
}
