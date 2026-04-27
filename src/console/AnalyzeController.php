<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use yii\console\ExitCode;

/**
 * Analyze — single-action orchestration of the source-introspection + schema-dump +
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
 *   6.   SchemaDumper::dump (consumes scan['tables']) → schema-dump.json
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

        $this->stdout("Analyze: scanning legacy source + schema\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since);

        // Step 1.5 (Phase 02.1 / D-31): KUNSTMAAN_SOURCE_PATH gate. Second gate after
        // enforceNeverProduction. Greenfield-fallback was dropped per D-31; without a
        // valid source path the source-scanner cannot read entity files and analyze
        // cannot proceed.
        $sourcePath = $plugin->kunstmaanSourcePathResolver->resolve();
        if ($sourcePath === null) {
            $this->stderr(
                "  FAIL KUNSTMAAN_SOURCE_PATH unset or invalid — analyze cannot proceed.\n"
                . "       Set KUNSTMAAN_SOURCE_PATH in .env (or kunstmaanSourcePath in plugin settings).\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   source path resolved → {$sourcePath}\n", Console::FG_GREEN);

        // Step 2: locale preflight (LOC-02 D-17 hard-fail).
        $unmapped = $plugin->localePreflight->ensure($filters);
        if ($unmapped !== null) {
            $this->stderr(
                "  FAIL unmapped Kunstmaan locales: " . implode(', ', $unmapped) . "\n",
                Console::FG_RED,
            );
            // LOC-01: surface the paste-ready Craft `sites:` block inline. REPORT.md
            // is never written on this path, so without this the operator has no
            // concrete YAML to copy.
            $detected = $plugin->localePreflight->detect();
            $this->stdout("\n" . $plugin->reportBuilder->renderLocales($detected) . "\n");
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   locale preflight\n", Console::FG_GREEN);

        $storageDir = Craft::$app->path->getStoragePath() . '/migration';

        // Step 3 (Phase 02.1 / D-42): KunstmaanSourceScanner — entity index + table list.
        try {
            $sourceScan = $plugin->kunstmaanSourceScanner->scan();
        } catch (Throwable $e) {
            $this->stderr("  FAIL source scanner: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $entityCount = count($sourceScan['entities'] ?? []);
        $tableScanCount = count((array) ($sourceScan['tables'] ?? []));
        $this->stdout(
            "  OK   source scanned ({$entityCount} entities, {$tableScanCount} tables)\n",
            Console::FG_GREEN,
        );

        // Step 4 (Phase 02.1 / D-42): KunstmaanPageStructureScanner — pageStructure FQCN map.
        try {
            $pageStructure = $plugin->kunstmaanPageStructureScanner->scan();
        } catch (Throwable $e) {
            $this->stderr("  FAIL page-structure scanner: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(
            "  OK   page structure scanned (" . count($pageStructure) . " page entities)\n",
            Console::FG_GREEN,
        );

        // Step 4.5 (Phase 02.1 / D-35, locked here per advisor): walk pageStructure and
        // emit a kind=pagePart mapping row per (pagePartClass × parentPageClass × context)
        // tuple. Plan 06's structural-only merge identity tuple ensures re-runs dedupe
        // even when the operator has filled in targetEntryType.
        $pagePartProposals = [];
        foreach ($pageStructure as $pageFqcn => $pageRecord) {
            $parentPageClass = $this->shortClassName((string) $pageFqcn);
            foreach ((array) ($pageRecord['contexts'] ?? []) as $contextRecord) {
                $contextName = (string) ($contextRecord['name'] ?? '');
                foreach ((array) ($contextRecord['allowedPagePartClasses'] ?? []) as $allowedClass) {
                    $pagePartClass = (string) ($allowedClass['class'] ?? '');
                    if ($pagePartClass === '') { continue; }
                    $shortClass = $this->shortClassName($pagePartClass);
                    $sourceTable = (string) ($allowedClass['table'] ?? '');
                    $rationale = "page-part class {$shortClass} in {$parentPageClass} context '{$contextName}'";
                    $pagePartProposals[] = $plugin->mappingFile->buildPagePartRow(
                        pagePartClass: $pagePartClass,
                        sourceTable: $sourceTable,
                        parentPageClass: $parentPageClass,
                        context: $contextName,
                        targetEntryType: '', // operator fills via map loop; structural-only merge tuple dedupes re-runs
                        confidence: 'medium',
                        rationale: $rationale,
                    );
                }
            }
        }
        $this->stdout(
            "  OK   page-part emitter produced " . count($pagePartProposals) . " proposals\n",
            Console::FG_GREEN,
        );

        // Step 5: pageStructure.json write (atomic JSON; sibling of schema-dump.json).
        $pageStructurePath = $storageDir . '/pageStructure.json';
        if (!$plugin->mappingFile->writeAtomicJson($pageStructurePath, $pageStructure)) {
            $this->stderr("  FAIL could not write {$pageStructurePath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   pageStructure.json written → {$pageStructurePath}\n", Console::FG_GREEN);

        // Step 6: schema dump (consumes Phase 02.1 source-scanner table list).
        try {
            $schemaDump = $plugin->schemaDumper->dump($filters, (array) ($sourceScan['tables'] ?? []));
        } catch (Throwable $e) {
            $this->stderr("  FAIL schema dump: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $tableCount = count($schemaDump['tables'] ?? []);
        $colCount   = array_sum(array_map('count', $schemaDump['columns'] ?? []));
        $schemaPath = $storageDir . '/schema-dump.json';
        if (!$plugin->mappingFile->writeAtomicJson($schemaPath, $schemaDump)) {
            $this->stderr("  FAIL could not write {$schemaPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   schema dumped ({$tableCount} tables, {$colCount} columns) → {$schemaPath}\n", Console::FG_GREEN);

        // Step 6.5 (Phase 7): synthetic page-part emitter for content-only pages.
        // Detects Page FQCNs that have NO real page-parts AND DO have content-like
        // columns on their source table (longtext content/body/intro, banner_*, etc.)
        // — emits a synthetic kind=pagePart row with pagePartClass='__implicit_content__'
        // so the downstream proposePagePartBlocks LLM step proposes a Matrix
        // wrapping just like it does for real page-parts. Must run AFTER step 6
        // (schemaDump) — earlier placement read $schemaDump before it was defined
        // and silently produced zero rows.
        $fqcnsWithRealPageParts = [];
        foreach ($pagePartProposals as $r) {
            $fqcnsWithRealPageParts[(string) ($r['parentPageClass'] ?? '')] = true;
        }
        $contentLikeNamePatterns = '/^(content|body|intro|text|description|banner_|header_|hero_)/i';
        $contentLikeSqlTypes = ['longtext', 'mediumtext', 'text'];
        $columnsByTable = (array) ($schemaDump['columns'] ?? []);
        $implicitCount = 0;
        foreach ($pageStructure as $pageFqcn => $pageRecord) {
            if (!is_array($pageRecord)) { continue; }
            $shortFqcn = $this->shortClassName((string) $pageFqcn);
            if (isset($fqcnsWithRealPageParts[$shortFqcn])) { continue; }
            $sourceTable = (string) ($pageRecord['tableName'] ?? '');
            if ($sourceTable === '') { continue; }
            $tableCols = (array) ($columnsByTable[$sourceTable] ?? []);
            $contentColumns = [];
            foreach ($tableCols as $c) {
                if (!is_array($c)) { continue; }
                $colName = (string) ($c['column'] ?? '');
                $sqlType = strtolower((string) ($c['sqlType'] ?? ''));
                if ($colName === '' || $sqlType === '') { continue; }
                $isContentSqlType = in_array($sqlType, $contentLikeSqlTypes, true);
                $isContentName = (bool) preg_match($contentLikeNamePatterns, $colName);
                if ($isContentSqlType || $isContentName) {
                    $contentColumns[] = [
                        'sourceProperty' => $colName,
                        'targetHandle'   => '',
                        'handler'        => '',
                    ];
                }
            }
            if ($contentColumns === []) { continue; }
            $columnList = implode(', ', array_map(static fn(array $c): string => (string) $c['sourceProperty'], $contentColumns));
            $pagePartProposals[] = $plugin->mappingFile->buildPagePartRow(
                pagePartClass: '__implicit_content__',
                sourceTable: $sourceTable,
                parentPageClass: $shortFqcn,
                context: 'main',
                targetEntryType: '',
                targetMatrixField: '',
                targetBlockType: '',
                fields: $contentColumns,
                confidence: 'medium',
                rationale: "Page {$shortFqcn} has no real page-parts but carries content-like columns ({$columnList}). Propose a Matrix wrapping so the page's primary content actually migrates instead of being silently dropped.",
            );
            $implicitCount++;
        }
        if ($implicitCount > 0) {
            $this->stdout(
                "  OK   implicit-content emitter produced {$implicitCount} synthetic page-part proposals (content-only Pages)\n",
                Console::FG_GREEN,
            );
        }

        // Step 7: heuristic proposals.
        // HeuristicProposer::autoMatch returns [matched, residual]. The schema dump
        // alone does not carry violation rows; v1's coverage step transforms
        // schema-dump → violations. Phase 2 / Plan 03 ships only the orchestration
        // primitives — Plan 05 (CoverageAuditor) wires the schema → violations
        // transform and feeds it here. Until then, autoMatch sees an empty input
        // and returns ([], []) — heuristics produce zero proposals on a fresh run.
        $violations = $this->buildViolationsFromSchema($schemaDump);
        $craftFieldIndex = $this->buildCraftFieldIndex();

        // Phase 02.1 / D-44: wire heuristic 1.5 entity context. Load the existing
        // mapping.yaml HERE (early — same payload is reused at the merge step) so
        // accepted column rows can resolve table → targetEntryType for the entity-
        // aware match. On a fresh run with no mapping.yaml, acceptedRows is empty
        // and heuristic 1.5 falls through silently (heuristics 3-9 fire as before).
        $mappingPath = $plugin->mappingFile->resolvePath();
        $existing = $plugin->mappingFile->load($mappingPath);
        $plugin->heuristicProposer->entityIndex = (array) ($sourceScan['entities'] ?? []);
        $plugin->heuristicProposer->acceptedRows = array_values(array_filter(
            $existing['proposals'] ?? [],
            static fn(array $r): bool =>
                ((string) ($r['status'] ?? '')) === 'accepted'
                && ((string) ($r['kind'] ?? 'column')) === 'column',
        ));

        try {
            [$heuristicProposals, $residual] = $plugin->heuristicProposer->autoMatch(
                $violations,
                $craftFieldIndex,
            );
        } catch (Throwable $e) {
            $this->stderr("  FAIL heuristic proposer: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(
            "  OK   heuristics produced " . count($heuristicProposals) . " proposals "
            . "(" . count($residual) . " residual for LLM)\n",
            Console::FG_GREEN,
        );

        // Step 7.5 (Phase 6): entity-level LLM proposer. Asks the LLM "for each
        // Kunstmaan Page FQCN, which Craft entry-type handle is the best fit?"
        // before the column-level batch step runs — so column proposals get a
        // real targetEntryType context instead of the empty-string placeholder
        // that Plan 03 shipped (Plan 05 follow-up that never landed). Skipped
        // when --no-ai or no API key — falls back to the basename heuristic in
        // MappingCompiler downstream.
        $apiKeyForEntityStep = (string) ($plugin->getSettings()->anthropicApiKey ?? '');
        $skipEntityLlm = $this->noAi || $apiKeyForEntityStep === '' || $pageStructure === [];
        $nodeClassProposals = [];
        $craftEntryTypeHandles = $plugin->craftKnowledgeBase->entryTypeHandles();
        $kbCraftMd = '';
        // Phase 8 / D-14: defensive scoping — Phase 8 proposer steps (7.7/7.8/7.9 below)
        // reference $kbLegacyMd outside the entity-LLM non-skip branch where it's first
        // built. Initialize empty here so a skipped entity-LLM step does not leave the
        // variable undefined for the new steps.
        $kbLegacyMd = '';
        if ($skipEntityLlm) {
            $reason = $this->noAi
                ? '--no-ai set'
                : ($apiKeyForEntityStep === '' ? 'ANTHROPIC_API_KEY not set' : 'no page entities discovered');
            $this->stdout(
                "  WARN entity-level LLM skipped ({$reason}) — nodeClass rows will fall back to compiler basename heuristic\n",
                Console::FG_YELLOW,
            );
        } else {
            try {
                $kbCraftMd = $plugin->craftKnowledgeBase->renderEntryTypesMarkdown();
                // Build legacy KB markdown ONCE here so both step 7.5 and step 8 share it.
                $preLlmProposals = array_merge($heuristicProposals, $pagePartProposals);
                $kbAdapterEarly  = self::buildKbMappingAdapter($preLlmProposals);
                $nowEarly        = new \DateTimeImmutable();
                $kbPagesEarly    = $plugin->knowledgeBase->renderPagesMarkdown($kbAdapterEarly, $nowEarly);
                $kbPagePartsEarly = $plugin->knowledgeBase->renderPagePartsMarkdown($kbAdapterEarly, $nowEarly);
                $kbLegacyMd = $kbPagesEarly . "\n\n" . $kbPagePartsEarly;
                $this->stdout(
                    "  ... entity-level LLM batching " . count($pageStructure) . " page entities (chunks of 8) against "
                    . count($craftEntryTypeHandles) . " Craft entry-type handles\n",
                    Console::FG_GREY,
                );
                $entityProgressStarted = false;
                $nodeClassProposals = $plugin->llmClassifier->proposeNodeClasses(
                    $pageStructure,
                    $craftEntryTypeHandles,
                    $kbLegacyMd,
                    $kbCraftMd,
                    function (int $i, int $n, int $entitiesInChunk, int $proposalsReturned, float $sec) use (&$entityProgressStarted): void {
                        if (!$entityProgressStarted) {
                            Console::startProgress(0, $n, '  ... LLM-entity ');
                            $entityProgressStarted = true;
                        }
                        Console::updateProgress(
                            $i,
                            $n,
                            sprintf(
                                '  ... LLM-entity [chunk %d/%d entities=%d→props=%d %.1fs] ',
                                $i, $n, $entitiesInChunk, $proposalsReturned, $sec,
                            ),
                        );
                    },
                );
                if ($entityProgressStarted) {
                    Console::endProgress();
                }
                $matched = count(array_filter($nodeClassProposals, static fn(array $p): bool => (string) $p['targetEntryType'] !== ''));
                $this->stdout(
                    "  OK   entity-level LLM produced " . count($nodeClassProposals) . " nodeClass proposals "
                    . "({$matched} with non-empty targetEntryType)\n",
                    Console::FG_GREEN,
                );
            } catch (Throwable $e) {
                $this->stderr("  FAIL entity-level LLM: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Backfill column violations' targetEntryType from accepted-tier nodeClass
        // proposals (high confidence → status will be `accepted`; medium/low → won't
        // be applied here but the operator can promote via map). This bridges the
        // entity decision into the column-level batch so column proposals come back
        // with a meaningful entryType context.
        if ($nodeClassProposals !== []) {
            $tableToEntryType = [];
            foreach ($nodeClassProposals as $p) {
                if (((string) $p['confidence']) !== 'high') {
                    continue;
                }
                if (((string) $p['targetEntryType']) === '') {
                    continue;
                }
                $tableToEntryType[(string) $p['sourceTable']] = (string) $p['targetEntryType'];
            }
            $backfilled = 0;
            foreach ($residual as &$rRow) {
                if (!is_array($rRow)) { continue; }
                if ((string) ($rRow['targetEntryType'] ?? '') !== '') { continue; }
                $tbl = (string) ($rRow['table'] ?? '');
                if (isset($tableToEntryType[$tbl])) {
                    $rRow['targetEntryType'] = $tableToEntryType[$tbl];
                    $backfilled++;
                }
            }
            unset($rRow);
            if ($backfilled > 0) {
                $this->stdout(
                    "  OK   backfilled targetEntryType on {$backfilled} residual columns from high-confidence nodeClass proposals\n",
                    Console::FG_GREEN,
                );
            }
        }

        // Step 7.6 (Phase 6): page-part LLM proposer. For each kind=pagePart row
        // the page-part emitter produced (one per pagePartClass × parentPage ×
        // context), ask the LLM to pick a (targetMatrixField, targetBlockType)
        // pair from the Craft Matrix-field catalog. Closed-set validated — the
        // LLM cannot invent handles. Without this step page-part rows always
        // landed status:needs-review with empty target (D-35 historical default,
        // since no deterministic heuristic exists). With it, AI-promoted page
        // parts get the same D-02 confidence-tier → status mapping as nodeClass
        // and column rows. Skipped when --no-ai or no API key (page-part rows
        // remain needs-review for operator hand-fill via map).
        $matrixCatalog = $plugin->craftKnowledgeBase->matrixFieldCatalog();
        $skipPagePartLlm = $this->noAi
            || $apiKeyForEntityStep === ''
            || $pagePartProposals === []
            || $matrixCatalog === [];
        if ($skipPagePartLlm) {
            $reason = $this->noAi
                ? '--no-ai set'
                : ($apiKeyForEntityStep === ''
                    ? 'ANTHROPIC_API_KEY not set'
                    : ($pagePartProposals === [] ? 'no page-parts emitted' : 'no Matrix fields configured in Craft'));
            $this->stdout(
                "  WARN page-part LLM skipped ({$reason}) — pagePart rows remain status:needs-review\n",
                Console::FG_YELLOW,
            );
        } else {
            try {
                // Reuse the legacy + craft KB markdown built for the entity-level
                // step. Append the Matrix catalog markdown so the LLM sees the
                // canonical (matrixField → blocks) listing in addition to the
                // entry-type catalog.
                $kbCraftWithMatrixMd = $kbCraftMd . "\n\n" . $plugin->craftKnowledgeBase->renderMatrixCatalogMarkdown();
                $this->stdout(
                    "  ... page-part LLM batching " . count($pagePartProposals) . " page parts (chunks of 8) against "
                    . count($matrixCatalog) . " Matrix fields\n",
                    Console::FG_GREY,
                );
                $ppProgressStarted = false;
                $pagePartProposals = $plugin->llmClassifier->proposePagePartBlocks(
                    $pagePartProposals,
                    $matrixCatalog,
                    $kbLegacyMd,
                    $kbCraftWithMatrixMd,
                    function (int $i, int $n, int $partsInChunk, int $proposalsReturned, float $sec) use (&$ppProgressStarted): void {
                        if (!$ppProgressStarted) {
                            Console::startProgress(0, $n, '  ... LLM-pagePart ');
                            $ppProgressStarted = true;
                        }
                        Console::updateProgress(
                            $i,
                            $n,
                            sprintf(
                                '  ... LLM-pagePart [chunk %d/%d parts=%d→props=%d %.1fs] ',
                                $i, $n, $partsInChunk, $proposalsReturned, $sec,
                            ),
                        );
                    },
                );
                if ($ppProgressStarted) {
                    Console::endProgress();
                }
                $matchedPp = count(array_filter(
                    $pagePartProposals,
                    static fn(array $p): bool => (string) ($p['targetBlockType'] ?? '') !== '',
                ));
                $this->stdout(
                    "  OK   page-part LLM produced " . count($pagePartProposals) . " proposals "
                    . "({$matchedPp} with non-empty targetBlockType)\n",
                    Console::FG_GREEN,
                );
            } catch (Throwable $e) {
                $this->stderr("  FAIL page-part LLM: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // ----------------------------------------------------------------
        // Phase 8 / D-05, D-06, D-12, D-13, D-14 — three new proposers.
        //
        // Step 7.7 — proposeNonPageEntities  (taxonomy / SUPPORTING classifier)
        // Step 7.8 — proposeLayoutBlocks    (header / wrap / column slot proposer)
        // Step 7.9 — proposeDataProviders   (orphan page-part dataProvider proposer)
        //
        // Skip-gate ladder mirrors Phase 4.1 / ADP-04 (Settings + CLI override layers).
        // ----------------------------------------------------------------

        $taxonomyProposals     = [];
        $layoutBlockProposals  = [];
        $dataProviderProposals = [];

        // Step 7.7 (Phase 8 / D-05, D-06): non-page entity (taxonomy) proposer.
        // Filters $sourceScan['entities'] (FQCN-keyed DoctrineEntityInfo objects)
        // to those NOT present in $pageStructure, adapts to array shape, and
        // dispatches to LlmClassifier::proposeNonPageEntities. Output rows are
        // either kind=taxonomy (caller routes through buildTaxonomyRow) or
        // kind=taxonomy + status=dropped (SUPPORTING — pass through verbatim;
        // MappingAuditor's dropped-status short-circuit handles them).
        $skipNonPage = $this->noAi
            || $apiKeyForEntityStep === ''
            || ($sourceScan['entities'] ?? []) === [];
        if ($skipNonPage) {
            $reason = $this->noAi
                ? '--no-ai set'
                : ($apiKeyForEntityStep === ''
                    ? 'ANTHROPIC_API_KEY not set'
                    : 'no entities discovered by source scanner');
            $this->stdout(
                "  WARN non-page-entity LLM skipped ({$reason}) — taxonomy rows will not be proposed\n",
                Console::FG_YELLOW,
            );
        } else {
            try {
                // Adapt DoctrineEntityInfo objects → array shape proposeNonPageEntities expects.
                // Filter out FQCNs already classified as Pages (they have a pageStructure entry).
                // Phase 8.1 / D-05a: also exclude PagePart classes — pageparts map to
                // page-builder Matrix blocks globally (handled by step 7.6's
                // proposePagePartBlocks). They are NOT taxonomies; emitting them
                // here produced status:accepted+empty-targetSection rows that
                // crashed TaxonomyMigrationService at runtime.
                $nonPageEntityIndex = [];
                foreach ((array) ($sourceScan['entities'] ?? []) as $fqcn => $info) {
                    if (!is_string($fqcn) || $fqcn === '') { continue; }
                    if (isset($pageStructure[$fqcn])) { continue; }
                    if (!($info instanceof \lameco\kunstmaanmigrator\source\DoctrineEntityInfo)) { continue; }
                    // Phase 8.1 / D-05a: exclude PagePart classes by namespace + suffix
                    // (Kunstmaan convention — page-parts live under `*\PageParts\*` or
                    // have a `*PagePart` class-name suffix).
                    if (preg_match('#\\\\PageParts?\\\\#', $fqcn) === 1) { continue; }
                    if (str_ends_with($fqcn, 'PagePart')) { continue; }
                    $nonPageEntityIndex[$fqcn] = [
                        'tableName' => $info->tableName,
                        'columns'   => $info->columns,
                        'relations' => $info->relations,
                        'contexts'  => [], // non-Page entities have no Kunstmaan page contexts
                    ];
                }
                if ($nonPageEntityIndex === []) {
                    $this->stdout(
                        "  OK   non-page-entity LLM: no non-Page entities to classify (every entity is a Page)\n",
                        Console::FG_GREEN,
                    );
                } else {
                    // Build the taxonomy-flavoured KB markdown (renderTaxonomiesMarkdown
                    // mirrors renderPagesMarkdown / renderPagePartsMarkdown shape; mapping
                    // is null because no taxonomy rows exist yet).
                    $kbTaxonomiesMd = $plugin->knowledgeBase->renderTaxonomiesMarkdown(null, new \DateTimeImmutable());
                    // Combine the legacy KB markdown built in step 7.5 (Pages + PageParts)
                    // with the new taxonomy markdown so the LLM has the full source picture.
                    $kbLegacyForTaxonomies = $kbLegacyMd === ''
                        ? $kbTaxonomiesMd
                        : $kbLegacyMd . "\n\n" . $kbTaxonomiesMd;
                    $this->stdout(
                        "  ... non-page-entity LLM batching " . count($nonPageEntityIndex) . " non-page entities (chunks of 8) against "
                        . count($craftEntryTypeHandles) . " Craft entry-type handles\n",
                        Console::FG_GREY,
                    );
                    $npProgressStarted = false;
                    $taxonomyProposals = $plugin->llmClassifier->proposeNonPageEntities(
                        $nonPageEntityIndex,
                        $craftEntryTypeHandles,
                        $kbLegacyForTaxonomies,
                        $kbCraftMd,
                        function (int $i, int $n, int $entitiesInChunk, int $proposalsReturned, float $sec) use (&$npProgressStarted): void {
                            if (!$npProgressStarted) {
                                Console::startProgress(0, $n, '  ... LLM-nonPage ');
                                $npProgressStarted = true;
                            }
                            Console::updateProgress(
                                $i,
                                $n,
                                sprintf(
                                    '  ... LLM-nonPage [chunk %d/%d entities=%d→props=%d %.1fs] ',
                                    $i, $n, $entitiesInChunk, $proposalsReturned, $sec,
                                ),
                            );
                        },
                    );
                    if ($npProgressStarted) {
                        Console::endProgress();
                    }
                    $taxonomyCount = 0;
                    $supportingCount = 0;
                    foreach ($taxonomyProposals as $r) {
                        if (((string) ($r['status'] ?? '')) === 'dropped') {
                            $supportingCount++;
                        } else {
                            $taxonomyCount++;
                        }
                    }
                    $this->stdout(
                        "  OK   non-page-entity LLM produced " . count($taxonomyProposals) . " proposals "
                        . "({$taxonomyCount} taxonomy, {$supportingCount} dropped supporting)\n",
                        Console::FG_GREEN,
                    );
                }
            } catch (Throwable $e) {
                $this->stderr("  FAIL non-page-entity LLM: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Step 7.8 (Phase 8 / D-11, D-12, D-14): layout-block proposer for header /
        // body-wrap / body-column slots. Heuristic-trigger gated inside the proposer
        // (D-12 — only fires for entry-types whose Matrix catalog has a header- or
        // wrap-shaped block). Output rows are kind=nodeClass partial updates carrying
        // headerBlock / bodyWrapBlock / bodyColumn fields; Plan 09's compile step
        // folds them into nodeClasses[fqcn].
        $skipLayout = $this->noLayout
            || !$plugin->getSettings()->proposeLayout
            || $this->noAi
            || $apiKeyForEntityStep === ''
            || $pageStructure === [];
        if ($skipLayout) {
            $reason = $this->noLayout
                ? '--no-layout set'
                : (!$plugin->getSettings()->proposeLayout
                    ? 'Settings::proposeLayout disabled'
                    : ($this->noAi
                        ? '--no-ai set'
                        : ($apiKeyForEntityStep === ''
                            ? 'ANTHROPIC_API_KEY not set'
                            : 'no page entities discovered')));
            $this->stdout(
                "  WARN layout-block LLM skipped ({$reason}) — nodeClass rows will not carry layout slots\n",
                Console::FG_YELLOW,
            );
        } else {
            try {
                // Page-aware pageStructure for the proposer: thread accepted nodeClass
                // proposals' targetEntryType into pageStructure entries so the proposer's
                // heuristic-trigger filter (lines 737-757 of LlmClassifier) finds the
                // matching matrixField in the catalog. Without this, $info['targetEntryType']
                // is empty and the filter never fires.
                $pageStructureForLayout = [];
                $entityTypeByFqcn = [];
                foreach ($nodeClassProposals as $ncp) {
                    if (!is_array($ncp)) { continue; }
                    $f = (string) ($ncp['fqcn'] ?? '');
                    $et = (string) ($ncp['targetEntryType'] ?? '');
                    if ($f !== '' && $et !== '') {
                        $entityTypeByFqcn[$f] = $et;
                    }
                }
                foreach ($pageStructure as $fqcn => $info) {
                    $copy = is_array($info) ? $info : [];
                    if (!isset($copy['targetEntryType']) || (string) $copy['targetEntryType'] === '') {
                        $copy['targetEntryType'] = $entityTypeByFqcn[(string) $fqcn] ?? '';
                    }
                    $pageStructureForLayout[(string) $fqcn] = $copy;
                }
                $matrixCatalog = $plugin->craftKnowledgeBase->matrixFieldCatalog();
                $this->stdout(
                    "  ... layout-block LLM batching " . count($pageStructureForLayout) . " page entities (chunks of 8) against "
                    . count($matrixCatalog) . " Matrix fields\n",
                    Console::FG_GREY,
                );
                $lbProgressStarted = false;
                $layoutBlockProposals = $plugin->llmClassifier->proposeLayoutBlocks(
                    $pageStructureForLayout,
                    $matrixCatalog,
                    $kbLegacyMd,
                    $kbCraftMd,
                    function (int $i, int $n, int $entitiesInChunk, int $proposalsReturned, float $sec) use (&$lbProgressStarted): void {
                        if (!$lbProgressStarted) {
                            Console::startProgress(0, $n, '  ... LLM-layout ');
                            $lbProgressStarted = true;
                        }
                        Console::updateProgress(
                            $i,
                            $n,
                            sprintf(
                                '  ... LLM-layout [chunk %d/%d entities=%d→props=%d %.1fs] ',
                                $i, $n, $entitiesInChunk, $proposalsReturned, $sec,
                            ),
                        );
                    },
                );
                if ($lbProgressStarted) {
                    Console::endProgress();
                }
                $this->stdout(
                    "  OK   layout-block LLM produced " . count($layoutBlockProposals) . " proposals\n",
                    Console::FG_GREEN,
                );
            } catch (Throwable $e) {
                $this->stderr("  FAIL layout-block LLM: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Step 7.9 (Phase 8 / D-13, D-14): dataProvider proposer for orphan page-parts.
        // D-13 orphan filter: page-part FQCNs with NO row in kuma_page_part_refs AND
        // whose source table is NOT joined to kuma_node_versions. These are computed
        // here in the controller and fed to LlmClassifier::proposeDataProviders as a
        // pre-filtered list (the proposer itself does no orphan detection).
        $skipProviders = $this->noProviders
            || !$plugin->getSettings()->proposeProviders
            || $this->noAi
            || $apiKeyForEntityStep === '';
        if ($skipProviders) {
            $reason = $this->noProviders
                ? '--no-providers set'
                : (!$plugin->getSettings()->proposeProviders
                    ? 'Settings::proposeProviders disabled'
                    : ($this->noAi
                        ? '--no-ai set'
                        : 'ANTHROPIC_API_KEY not set'));
            $this->stdout(
                "  WARN dataProvider LLM skipped ({$reason}) — orphan page-parts will not be proposed\n",
                Console::FG_YELLOW,
            );
        } else {
            try {
                // Build the candidate page-part set from pageStructure's allowedPagePartClasses
                // (FQCN + sourceTable per advisor — pageStructure is the canonical surface
                // for page-part class enumeration; $sourceScan['pageParts'] does not exist).
                $candidatePageParts = [];
                foreach ($pageStructure as $pageFqcn => $pageRecord) {
                    if (!is_array($pageRecord)) { continue; }
                    foreach ((array) ($pageRecord['contexts'] ?? []) as $contextRecord) {
                        foreach ((array) ($contextRecord['allowedPagePartClasses'] ?? []) as $allowedClass) {
                            $ppFqcn  = (string) ($allowedClass['class'] ?? '');
                            if ($ppFqcn === '') { continue; }
                            $ppTable = (string) ($allowedClass['table'] ?? '');
                            if (!isset($candidatePageParts[$ppFqcn])) {
                                $candidatePageParts[$ppFqcn] = [
                                    'fqcn'        => $ppFqcn,
                                    'sourceTable' => $ppTable,
                                ];
                            }
                        }
                    }
                }

                // D-13 orphan filter — half 1: drop FQCNs referenced by any
                // kuma_page_part_refs row.
                $referencedFqcns = [];
                try {
                    $refRows = $plugin->legacyDbService->queryAll(
                        'SELECT DISTINCT page_part_entityname FROM '
                        . \lameco\kunstmaanmigrator\source\KunstmaanCoreTables::PAGE_PART_REFS
                        . ' WHERE page_part_entityname IS NOT NULL'
                    );
                    foreach ($refRows as $r) {
                        $referencedFqcns[(string) ($r['page_part_entityname'] ?? '')] = true;
                    }
                } catch (Throwable) {
                    // kuma_page_part_refs unreadable — treat referenced set as empty,
                    // every candidate becomes an orphan candidate (downstream FK heuristic
                    // still filters those joined to kuma_node_versions).
                }

                // Look up DoctrineEntityInfo for each candidate so we can run the
                // node-version FK heuristic.
                $entityIndexByFqcn = (array) ($sourceScan['entities'] ?? []);

                $orphans = [];
                foreach ($candidatePageParts as $ppFqcn => $candidate) {
                    if (isset($referencedFqcns[$ppFqcn])) { continue; }
                    // D-13 orphan filter — half 2: source table not joined to
                    // kuma_node_versions (no FK column matching the node-version pattern).
                    $hasNodeVersionFk = false;
                    $info = $entityIndexByFqcn[$ppFqcn] ?? null;
                    if ($info instanceof \lameco\kunstmaanmigrator\source\DoctrineEntityInfo) {
                        // Probe DoctrineRelationInfo objects: targetEntity FQCN match
                        // (str_contains 'NodeVersion') or fkColumn name match.
                        foreach ($info->relations as $rel) {
                            if (!($rel instanceof \lameco\kunstmaanmigrator\source\DoctrineRelationInfo)) { continue; }
                            $relTarget = $rel->targetEntity;
                            $relColumn = (string) ($rel->fkColumn ?? '');
                            if (str_contains($relTarget, 'NodeVersion')
                                || $relColumn === 'node_version_id'
                                || $relColumn === 'kuma_node_version_id'
                            ) {
                                $hasNodeVersionFk = true;
                                break;
                            }
                        }
                        // Fallback: scan declared columns by columnName (covers cases
                        // where the FK is a plain column rather than a Doctrine relation).
                        if (!$hasNodeVersionFk) {
                            foreach ($info->columns as $col) {
                                if (!($col instanceof \lameco\kunstmaanmigrator\source\DoctrineColumnInfo)) { continue; }
                                if ($col->columnName === 'node_version_id'
                                    || $col->columnName === 'kuma_node_version_id'
                                ) {
                                    $hasNodeVersionFk = true;
                                    break;
                                }
                            }
                        }
                    }
                    if ($hasNodeVersionFk) { continue; }
                    $orphans[] = $candidate;
                }

                if ($orphans === []) {
                    $this->stdout(
                        "  OK   dataProvider LLM: no orphan page-parts detected (all page-parts have kuma_page_part_refs row or kuma_node_versions FK)\n",
                        Console::FG_GREEN,
                    );
                } else {
                    $matrixCatalogForProviders = $plugin->craftKnowledgeBase->matrixFieldCatalog();
                    $this->stdout(
                        "  ... dataProvider LLM batching " . count($orphans) . " orphan page-parts (chunks of 8) against "
                        . count($matrixCatalogForProviders) . " Matrix field(s)\n",
                        Console::FG_GREY,
                    );
                    $dpProgressStarted = false;
                    $dataProviderProposals = $plugin->llmClassifier->proposeDataProviders(
                        $orphans,
                        $matrixCatalogForProviders,
                        $kbLegacyMd,
                        $kbCraftMd,
                        function (int $i, int $n, int $entitiesInChunk, int $proposalsReturned, float $sec) use (&$dpProgressStarted): void {
                            if (!$dpProgressStarted) {
                                Console::startProgress(0, $n, '  ... LLM-provider ');
                                $dpProgressStarted = true;
                            }
                            Console::updateProgress(
                                $i,
                                $n,
                                sprintf(
                                    '  ... LLM-provider [chunk %d/%d orphans=%d→props=%d %.1fs] ',
                                    $i, $n, $entitiesInChunk, $proposalsReturned, $sec,
                                ),
                            );
                        },
                    );
                    if ($dpProgressStarted) {
                        Console::endProgress();
                    }
                    $this->stdout(
                        "  OK   dataProvider LLM produced " . count($dataProviderProposals) . " proposals\n",
                        Console::FG_GREEN,
                    );
                }
            } catch (Throwable $e) {
                $this->stderr("  FAIL dataProvider LLM: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Step 8: LLM batch proposals for residuals (skip when --no-ai or key missing).
        // Phase 02.1 (B2 fix): KB markdown placeholders replaced with rendered Pages +
        // PageParts markdown — but only via a v1-shaped MAPPING ADAPTER built from v2's
        // flat proposals[] array. KnowledgeBase::renderPagesMarkdown / renderPagePartsMarkdown
        // read v1's nested keys ($mapping['pageParts'] / ['nodeClasses'] / ['sections']);
        // without the adapter, every per-row annotation would be silently lost (placeholders
        // would be replaced but the mapping overlay would render empty).
        $llmProposals = [];
        $apiKey = (string) ($plugin->getSettings()->anthropicApiKey ?? '');
        $skipLlm = $this->noAi || $apiKey === '' || $residual === [];
        if ($skipLlm) {
            $reason = $this->noAi
                ? '--no-ai set'
                : ($apiKey === '' ? 'ANTHROPIC_API_KEY not set' : 'no residual columns');
            $this->stdout(
                "  WARN LLM call skipped ({$reason}) — residual columns land status: needs-review\n",
                Console::FG_YELLOW,
            );
        } else {
            try {
                // Build v1-shaped KB mapping adapter from v2's flat proposals[] (B2 fix).
                // Pre-LLM proposals = heuristic-matched rows + emitted page-part rows.
                // Heuristic rows lack an explicit `kind` discriminator and default to
                // 'column' inside the adapter bucketer.
                $preLlmProposals = array_merge($heuristicProposals, $pagePartProposals);
                $adapter = self::buildKbMappingAdapter($preLlmProposals);
                $now = new \DateTimeImmutable();
                $kbPages     = $plugin->knowledgeBase->renderPagesMarkdown($adapter, $now);
                $kbPageParts = $plugin->knowledgeBase->renderPagePartsMarkdown($adapter, $now);
                $this->stdout(
                    "  ... LLM batching " . count($residual) . " residual columns (chunks of 10, grouped by targetEntryType)\n",
                    Console::FG_GREY,
                );
                // Progress bar via Yii Console helper (same pattern as AssetMigrationService).
                // Lazy-started inside the callback so we know $chunkTotal up-front.
                $progressStarted = false;
                $llmProposals = $plugin->llmClassifier->batchPropose(
                    $residual,
                    $craftFieldIndex,
                    $kbPages . "\n\n" . $kbPageParts,
                    $kbCraftMd, // Phase 6: real Craft KB markdown (empty when entity step skipped)
                    function (int $i, int $n, string $et, int $cols, int $props, float $sec) use (&$progressStarted): void {
                        if (!$progressStarted) {
                            Console::startProgress(0, $n, '  ... LLM ');
                            $progressStarted = true;
                        }
                        $etLabel = $et === '' ? '(unmapped)' : $et;
                        Console::updateProgress(
                            $i,
                            $n,
                            sprintf(
                                '  ... LLM [chunk %d/%d et=%s cols=%d→props=%d %.1fs] ',
                                $i, $n, $etLabel, $cols, $props, $sec,
                            ),
                        );
                    },
                );
                if ($progressStarted) {
                    Console::endProgress();
                }
                $this->stdout(
                    "  OK   LLM produced " . count($llmProposals) . " proposals\n",
                    Console::FG_GREEN,
                );
            } catch (Throwable $e) {
                $this->stderr("  FAIL LLM classifier: {$e->getMessage()}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Step 9 (build rows): D-02 confidence-tier → status assignment + page-part rows.
        // Page-part rows produced by step 4.5 are already row-shaped (buildPagePartRow
        // returns a status:needs-review row per D-35); they merge in alongside column rows.
        $rows = [];
        foreach ($heuristicProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForHeuristic($p));
        }
        foreach ($llmProposals as $p) {
            $rows[] = $plugin->mappingFile->buildRow($p, $this->statusForLlm($p, $skipLlm));
        }
        foreach ($pagePartProposals as $p) {
            // Phase 6: AI-promoted page parts use the same D-02 confidence-tier
            // mapping as nodeClass + column rows. Page-part rows that came back
            // with a non-empty targetBlockType + a valid confidence get promoted
            // accordingly; rows with empty targetBlockType (or the AI step was
            // skipped) keep their default needs-review status from the emitter.
            $hasAiTarget = ((string) ($p['targetBlockType'] ?? '')) !== '';
            if ($hasAiTarget) {
                $p['status'] = $this->statusForLlm($p, false);
            }
            $rows[] = $p;
        }
        // Phase 6: persist entity-level (kind=nodeClass) proposals via the same
        // D-02 confidence-tier → status mapping. High-confidence rows land
        // `accepted`; medium/low → `needs-review` (operator must promote in map).
        foreach ($nodeClassProposals as $p) {
            $rows[] = $plugin->mappingFile->buildNodeClassRow($p, $this->statusForLlm($p, false));
        }
        // Phase 8 / D-05, D-06 — non-page entity (taxonomy) proposals from step 7.7.
        // SUPPORTING drops are emitted by the proposer with kind=taxonomy + status=dropped
        // (advisor row-shape correction — keeps MappingAuditor's dropped-status
        // short-circuit happy). Pass those through verbatim. TAXONOMY rows go through
        // buildTaxonomyRow with confidence-tier → status mapping.
        foreach ($taxonomyProposals as $p) {
            if (((string) ($p['status'] ?? '')) === 'dropped') {
                $rows[] = $p;
                continue;
            }
            $confidence = (string) ($p['confidence'] ?? 'medium');
            $initialStatus = $confidence === 'high' ? 'accepted' : 'needs-review';
            $rows[] = $plugin->mappingFile->buildTaxonomyRow($p, $initialStatus);
        }
        // Phase 8 / D-11, D-12 — layout-block proposals from step 7.8 are partial
        // kind=nodeClass updates (header/wrap/column slot keys + confidence + rationale).
        // Plan 09 compile step folds them into the nodeClasses[fqcn] block. Pass through
        // with each row's intrinsic status field (proposer already set per D-06 ladder).
        foreach ($layoutBlockProposals as $p) {
            $rows[] = $p;
        }
        // Phase 8 / D-13 — dataProvider proposals from step 7.9. Confidence-tier →
        // status mapping; LLM-omitted FQCNs already carry status=needs-review per the
        // proposer's own omission handling.
        foreach ($dataProviderProposals as $p) {
            $intrinsicStatus = (string) ($p['status'] ?? '');
            if ($intrinsicStatus === 'needs-review') {
                $rows[] = $plugin->mappingFile->buildDataProviderRow($p, 'needs-review');
                continue;
            }
            $confidence = (string) ($p['confidence'] ?? 'medium');
            $initialStatus = $confidence === 'high' ? 'accepted' : 'needs-review';
            $rows[] = $plugin->mappingFile->buildDataProviderRow($p, $initialStatus);
        }
        // For LLM-skipped residuals (no LLM call, no proposals returned), still
        // emit a row with status: needs-review so the operator sees the gap.
        if ($skipLlm && $residual !== []) {
            foreach ($residual as $v) {
                $stub = [
                    'table'           => (string) ($v['table'] ?? ''),
                    'column'          => (string) ($v['column'] ?? ''),
                    'targetEntryType' => (string) ($v['targetEntryType'] ?? ''),
                    'targetHandle'    => '',
                    'handler'         => '',
                    'confidence'      => 'low',
                    'rationale'       => 'LLM call skipped (--no-ai or no API key)',
                    'fillRate'        => (float) ($v['fillRate'] ?? 0),
                    'sqlType'         => (string) ($v['sqlType'] ?? ''),
                    'samples'         => (array) ($v['samples'] ?? []),
                ];
                $rows[] = $plugin->mappingFile->buildRow($stub, 'needs-review');
            }
        }

        // Apply --auto-accept-high: promote LLM-high proposed → accepted (MAP-05).
        if ($this->autoAcceptHigh) {
            foreach ($rows as &$r) {
                if (($r['status'] ?? '') === 'proposed' && ($r['confidence'] ?? '') === 'high') {
                    $r['status'] = 'accepted';
                }
            }
            unset($r);
        }

        // Step 9 cont. (skip-existing merge — D-04 + D-34 kind-prefixed identity tuple).
        // $mappingPath / $existing were loaded earlier (step 7) so heuristic 1.5 could
        // populate acceptedRows; reuse the same payload here without a second disk read.
        $merged = $plugin->mappingFile->merge($existing, $rows);

        $yaml = Yaml::dump($merged, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (!$plugin->mappingFile->writeAtomic($mappingPath, $yaml)) {
            $this->stderr("  FAIL could not write {$mappingPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(
            "  OK   mapping.yaml updated (" . count($merged['proposals']) . " rows total) → {$mappingPath}\n",
            Console::FG_GREEN,
        );

        // Step 10: mapping-audit (D-16) + D-32 drift section + D-36 block-availability.
        // Warn-only by default; --audit-strict elevates FieldLayout findings; --source-strict
        // elevates non-empty drift findings. Either flag alone elevates only its own kind.
        // T-2-21 mitigation: MAPPING-AUDIT.md is written via writeAtomic BEFORE any
        // strict-mode short-circuit return so operators always have the on-disk trail
        // regardless of exit code.
        $findings = $plugin->mappingAuditor->audit($merged['proposals']);
        $driftFindings = (array) ($sourceScan['drift'] ?? []);
        $auditPath = $storageDir . '/MAPPING-AUDIT.md';
        $auditMd = $plugin->mappingAuditor->renderMarkdown($findings, $driftFindings);
        if (!$plugin->mappingFile->writeAtomic($auditPath, $auditMd)) {
            $this->stderr("  FAIL could not write {$auditPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        // FieldLayout findings outcome.
        if ($findings === []) {
            $this->stdout("  OK   mapping audit clean → {$auditPath}\n", Console::FG_GREEN);
        } else {
            $n = count($findings);
            if ($this->auditStrict) {
                $this->stderr(
                    "  FAIL mapping audit: {$n} drift finding(s) (--audit-strict) → {$auditPath}\n",
                    Console::FG_RED,
                );
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout(
                "  WARN mapping audit: {$n} drift finding(s) → {$auditPath}\n",
                Console::FG_YELLOW,
            );
        }
        // D-32 drift outcome (DB↔scan mismatch from KunstmaanSourceScanner).
        $dbExtra = count((array) ($driftFindings['dbHasButScanMissing'] ?? []));
        $scanExtra = count((array) ($driftFindings['scanHasButDbMissing'] ?? []));
        if ($dbExtra > 0 || $scanExtra > 0) {
            if ($this->sourceStrict) {
                $this->stderr(
                    "  FAIL source drift: {$dbExtra} DB-extra / {$scanExtra} scan-extra table(s) (--source-strict) → {$auditPath}\n",
                    Console::FG_RED,
                );
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout(
                "  WARN source drift: {$dbExtra} DB-extra / {$scanExtra} scan-extra table(s) → {$auditPath}\n",
                Console::FG_YELLOW,
            );
        }

        // Step 11: REPORT.md
        $reportPath = $storageDir . '/REPORT.md';
        $report = $plugin->reportBuilder->render($schemaDump, $merged['proposals']);
        if (!$plugin->mappingFile->writeAtomic($reportPath, $report)) {
            $this->stderr("  FAIL could not write {$reportPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   REPORT.md written → {$reportPath}\n", Console::FG_GREEN);

        $this->stdout("\nAnalyze: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
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
     * Build a v1-shaped KB mapping adapter from v2's flat `proposals[]` array (B2 fix).
     *
     * KnowledgeBase::renderPagesMarkdown / renderPagePartsMarkdown read v1's nested
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
     * KnowledgeBase simply renders no per-Page annotations. Future work: derive
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
