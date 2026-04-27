<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

use yii\base\Component;

/**
 * MappingCompiler — derives the v1-shaped runtime ETL contract
 * (`nodeClasses` / `sections` / `sites`) from v2's flat `proposals[]` audit
 * trail + the page-structure scan emitted by analyze.
 *
 * Why this exists: v2 keeps `mapping.yaml` as a flat list of per-column
 * decisions (great for the rubber-stamp loop and for proposal traceability).
 * The ETL services (ExtractService, TransformService, EntryMigrationService)
 * however still consume v1's nested shape — `nodeClasses[fqcn].sourceTable`,
 * `sections[handle].entryType`, `sites[locale]`. Without a step that bridges
 * the two shapes the migrate pipeline reads `[]` for nodeClasses and
 * extracts zero rows. v1 expected the operator to hand-author these blocks
 * (the v1 AnalyzeController literally printed `# ---- paste this above
 * nodeClasses: ----`); v2 inherited the contract but lost the prompt and
 * never shipped the bridge.
 *
 * Pure logic — no I/O, no DB, no Craft. Caller (CompileController) loads
 * mapping.yaml + pageStructure.json + Settings::localeMap, calls compile(),
 * and writes the result back via MappingFile::writeAtomic.
 *
 * Scope (v1.0):
 *   - Derives `nodeClasses[fqcn]` from pageStructure entries:
 *       sourceTable from pageStructure.tableName
 *       section keyed by the dominant `targetEntryType` across that fqcn's
 *         accepted column rows (heuristic: "majority vote"; ties broken by
 *         alphabetical order to keep output stable across runs)
 *       fields[targetHandle] from accepted column rows whose targetEntryType
 *         matches the section
 *   - Derives `sections[entryType]` from accepted column rows' targetEntryType
 *       distinct values; entryType handle is the same as the section key
 *       (operators can rename later)
 *   - Derives `sites[locale]` from a passed-in operator-curated map (Settings
 *       ::localeMap) — does NOT auto-discover Craft sites here (that's the
 *       caller's job; the compiler is a pure transform)
 *
 * NOT in scope (operator hand-edits these on the compiled output):
 *   - pageBuilderHandle / pageBuilderContexts (page-part composition mapping)
 *   - headerBlock / bodyWrapBlock / bodyColumn (entry-type page-part layout)
 *   - joins (multi-table FK joins beyond the FK auto-discovery in extract)
 *   - SKIP actions (operator marks nodeClasses they explicitly opt out of)
 *
 * The compiled output emits placeholder/stub keys for the un-derived fields
 * so operators see the gaps and can fill them inline rather than hunting
 * through docs to discover what's missing.
 */
final class MappingCompiler extends Component
{
    /**
     * Compile v1-shaped runtime structures from v2 proposals + page structure.
     *
     * @param array<string, mixed>  $mapping           Existing mapping.yaml contents (must contain `proposals` key)
     * @param array<string, mixed>  $pageStructure     pageStructure.json contents (FQCN-keyed)
     * @param array<string, string> $sites             Operator-curated locale → Craft-site-handle map
     * @param ?string               $defaultEntryType  Phase 6 fallback — Settings::defaultEntryType. When non-null,
     *                                                 FQCNs that have neither an accepted nodeClass row nor any
     *                                                 column-row targetEntryType land on this entry type instead
     *                                                 of being skipped (the row dies at load time today). Should
     *                                                 be a real Craft entry-type handle.
     * @param ?string               $defaultBlockType  Phase 6 fallback — Settings::defaultBlockType. (Reserved for
     *                                                 page-part fallback; not used in this signature today but
     *                                                 surfaced in the compile report for symmetry / operator visibility.)
     * @return array{
     *   proposals: list<array<string, mixed>>,
     *   nodeClasses: array<string, array<string, mixed>>,
     *   sections: array<string, array<string, mixed>>,
     *   sites: array<string, string>,
     *   pageParts: array<string, array<string, mixed>>,
     *   taxonomies: array<string, array<string, mixed>>,
     *   dataProviders: array<string, array<string, mixed>>,
     *   _compileReport: array{
     *     nodeClassesEmitted: int,
     *     sectionsEmitted: int,
     *     fieldsEmittedPerSection: array<string, int>,
     *     skippedNodeClasses: list<string>,
     *     fallbackEntryTypeApplied: list<string>,
     *     implicitBlocksEmitted: int,
     *     taxonomiesEmitted: int,
     *     layoutBlocksEmitted: int,
     *     dataProvidersEmitted: int,
     *     warnings: list<string>,
     *   },
     * }
     */
    /**
     * @param list<string> $craftEntryTypeHandles Phase 6 — closed set of real Craft entry-type handles.
     *                                            When provided, the compiler validates derived
     *                                            entry-type handles against this set and routes
     *                                            invalid ones to $defaultEntryType (when set) so
     *                                            FQCNs the LLM was unsure about don't die at load time.
     *                                            Empty list = no validation (legacy behavior).
     */
    public function compile(
        array $mapping,
        array $pageStructure,
        array $sites,
        ?string $defaultEntryType = null,
        ?string $defaultBlockType = null,
        array $craftEntryTypeHandles = [],
        array $matrixFieldCatalog = [],
    ): array {
        $proposals = (array) ($mapping['proposals'] ?? []);

        // Phase 6 primary path: harvest accepted kind=nodeClass rows. These are
        // the LLM's entity-level decisions ("FQCN X → Craft entry-type Y") that
        // analyze step 7.5 emits. They take precedence over the basename
        // heuristic for both the column targetEntryType backfill AND the
        // downstream nodeClasses[].section assignment.
        $acceptedNodeClassByTable = $this->indexAcceptedNodeClassesByTable($proposals);
        $acceptedNodeClassByFqcn  = $this->indexAcceptedNodeClassesByFqcn($proposals);

        // First pass: auto-assign targetEntryType on column rows that lack one.
        // Precedence: accepted nodeClass row's targetEntryType > basename
        // heuristic. The heuristic stays as a fallback for projects that
        // ran analyze with --no-ai (no nodeClass proposals were emitted).
        $tableToEntryTypeFromHeuristic = $this->buildTableToEntryTypeMap($pageStructure);
        $tableToEntryType = $acceptedNodeClassByTable + $tableToEntryTypeFromHeuristic;
        [$proposals, $autoAssigned] = $this->autoAssignTargetEntryType($proposals, $tableToEntryType);

        $accepted = $this->filterAccepted($proposals);

        // Group accepted column rows by targetEntryType.
        $byEntryType = $this->groupByEntryType($accepted);

        // Build sections[]: one entry per distinct targetEntryType. When the
        // LLM produced an accepted nodeClass row that names a `targetSection`
        // distinct from the entry-type handle, use it — Craft sections and
        // entry types are NOT the same string in general (e.g. entry-type
        // `casePage` lives in section `casePages`). Without this distinction,
        // EntryMigrationService::saveEntryForSites looks up section=casePage
        // and fails because `casePage` is an entry-type handle, not a
        // section handle.
        $entryTypeToSection = [];
        foreach ($acceptedNodeClassByFqcn as $row) {
            $et  = (string) ($row['targetEntryType'] ?? '');
            $sec = (string) ($row['targetSection'] ?? '');
            if ($et !== '' && $sec !== '') {
                $entryTypeToSection[$et] = $sec;
            }
        }
        $sections = [];
        foreach (array_keys($byEntryType) as $entryType) {
            $sectionHandle = $entryTypeToSection[$entryType] ?? $entryType;
            $sections[$entryType] = [
                'entryType' => $entryType,
                'section'   => $sectionHandle,
            ];
        }
        ksort($sections);

        // Build nodeClasses[]: one entry per FQCN in pageStructure that has
        // at least one accepted column row whose source table matches.
        $nodeClasses = [];
        $skipped = [];
        $warnings = [];
        $fieldsPerSection = [];
        $fallbackEntryTypeApplied = [];
        $fallbackBlockTypeApplied = []; // [pagePartClass|parentPageClass|context, ...]

        // Phase 6 page-part fallback (companion to the page fallback). Walk
        // kind=pagePart rows in proposals; for any row with empty
        // targetBlockType where Settings::defaultBlockType is set, fill it
        // in. Operator's explicit edits survive — only empties get touched.
        // Mutation is in-memory on $proposals; the augmented rows get
        // persisted when CompileController writes the result back.
        if ($defaultBlockType !== null && $defaultBlockType !== '') {
            foreach ($proposals as &$pRow) {
                if (!is_array($pRow)) { continue; }
                if (((string) ($pRow['kind'] ?? '')) !== 'pagePart') { continue; }
                if (((string) ($pRow['targetBlockType'] ?? '')) !== '') { continue; }
                $pRow['targetBlockType'] = $defaultBlockType;
                $fallbackBlockTypeApplied[] = (string) ($pRow['pagePartClass'] ?? '?')
                    . '|' . (string) ($pRow['parentPageClass'] ?? '?')
                    . '|' . (string) ($pRow['context'] ?? '?');
                // Also bump status from needs-review to accepted so the row
                // actually flows through the load pipeline. Operator can
                // re-edit afterward if the fallback is wrong.
                $pRow['status'] = 'accepted';
            }
            unset($pRow);
        }

        // Phase 8.3 / D-16 — auto-resolve targetMatrixField from targetBlockType.
        // The page-part LLM (and the defaultBlockType fallback above) often
        // populate `targetBlockType` without `targetMatrixField`, leaving
        // compile unable to derive the parent nodeClass's pageBuilderHandle.
        // Result: every page's pageBuilder Matrix stays empty, regardless of
        // how many page-parts were proposed.
        //
        // The Craft side resolves this unambiguously when a block-type handle
        // is uniquely owned by a single Matrix field — the operator's mental
        // model "page-parts are global page-builder blocks" implies exactly
        // this 1-to-1 majority case in practice. When the same block-type
        // appears in multiple Matrix fields, leave the row untouched (the
        // operator must hand-pick) and surface a warning.
        $autoFilledMatrixField = [];
        if ($matrixFieldCatalog !== []) {
            // Invert the catalog: blockTypeHandle => list<matrixFieldHandle>.
            $blockTypeOwners = [];
            foreach ($matrixFieldCatalog as $matrixHandle => $blockTypes) {
                if (!is_array($blockTypes)) { continue; }
                foreach ($blockTypes as $bt) {
                    $blockTypeOwners[(string) $bt][] = (string) $matrixHandle;
                }
            }

            // Phase 8.6 — parent-aware Matrix tie-break. Build
            // parentShortName → list<matrixHandle> from accepted nodeClass
            // proposals + the Craft entry-type field-layout walk. When a
            // block-type is owned by multiple Matrices, prefer one the parent
            // page's entry-type actually contains. Without this, 8.3 would
            // warn-and-skip every shared block-type — which is exactly how
            // CQM's HomePage page-parts ended up routed to the wrong Matrix.
            $parentMatrices = [];
            try {
                $kb = \lameco\kunstmaanmigrator\Plugin::getInstance()->craftKnowledgeBase;
                foreach ($proposals as $ncRow) {
                    if (!is_array($ncRow)) { continue; }
                    if (((string) ($ncRow['kind'] ?? '')) !== 'nodeClass') { continue; }
                    if (((string) ($ncRow['status'] ?? '')) !== 'accepted') { continue; }
                    $entryType = (string) ($ncRow['targetEntryType'] ?? '');
                    $fqcn = (string) ($ncRow['fqcn'] ?? '');
                    if ($entryType === '' || $fqcn === '') { continue; }
                    $parts = explode('\\', trim($fqcn, '\\'));
                    $parentShort = (string) end($parts);
                    if ($parentShort === '') { continue; }
                    $owned = $kb->matrixFieldsForEntryType($entryType);
                    if ($owned !== []) {
                        $parentMatrices[$parentShort] = $owned;
                    }
                }
            } catch (\Throwable) {
                // CraftKnowledgeBase unavailable (test bootstrap, no Craft) —
                // skip parent-aware tie-break, fall through to the original
                // single-owner / warn-and-skip behaviour.
                $parentMatrices = [];
            }

            foreach ($proposals as &$pRow) {
                if (!is_array($pRow)) { continue; }
                if (((string) ($pRow['kind'] ?? '')) !== 'pagePart') { continue; }
                if (((string) ($pRow['targetMatrixField'] ?? '')) !== '') { continue; }
                $bt = (string) ($pRow['targetBlockType'] ?? '');
                if ($bt === '') { continue; }
                $owners = array_values(array_unique($blockTypeOwners[$bt] ?? []));
                $parentShort = (string) ($pRow['parentPageClass'] ?? '');
                $parentOwned = $parentMatrices[$parentShort] ?? [];
                $rowKey = (string) ($pRow['pagePartClass'] ?? '?')
                    . '|' . $parentShort
                    . '|' . (string) ($pRow['context'] ?? '?');

                if (count($owners) === 1) {
                    $pRow['targetMatrixField'] = $owners[0];
                    $autoFilledMatrixField[] = $rowKey . ' -> ' . $owners[0];
                    continue;
                }

                if (count($owners) > 1 && $parentOwned !== []) {
                    // Intersect: which of the block-type's owning Matrices
                    // does the parent page's entry-type actually contain?
                    $candidates = array_values(array_intersect($owners, $parentOwned));
                    if (count($candidates) === 1) {
                        $pRow['targetMatrixField'] = $candidates[0];
                        $autoFilledMatrixField[] = $rowKey . ' -> ' . $candidates[0]
                            . ' (parent-aware tie-break: ' . $parentShort . ')';
                        continue;
                    }
                    if (count($candidates) > 1) {
                        $warnings[] = sprintf(
                            'page-part %s blockType=%s appears in %d Matrix fields (%s); parent %s owns %d of them (%s) — operator must pick targetMatrixField',
                            $rowKey,
                            $bt,
                            count($owners),
                            implode(', ', $owners),
                            $parentShort,
                            count($candidates),
                            implode(', ', $candidates),
                        );
                        continue;
                    }
                    // Zero candidates means parent doesn't own ANY Matrix
                    // containing this block-type → block-type was wrong.
                    $warnings[] = sprintf(
                        'page-part %s blockType=%s does not belong to any Matrix the parent %s owns (%s); blockType is mis-routed',
                        $rowKey,
                        $bt,
                        $parentShort,
                        implode(', ', $parentOwned),
                    );
                    continue;
                }

                if (count($owners) > 1) {
                    $warnings[] = sprintf(
                        'page-part %s blockType=%s appears in %d Matrix fields (%s) — operator must pick targetMatrixField',
                        $rowKey,
                        $bt,
                        count($owners),
                        implode(', ', $owners),
                    );
                }
            }
            unset($pRow);
        }

        foreach ($pageStructure as $fqcn => $pageInfo) {
            if (!is_string($fqcn) || !is_array($pageInfo)) {
                continue;
            }
            $sourceTable = (string) ($pageInfo['tableName'] ?? '');
            if ($sourceTable === '') {
                $skipped[] = $fqcn . ' (no tableName in pageStructure)';
                continue;
            }

            // Find accepted rows whose source table matches this FQCN's table,
            // keyed by targetEntryType to compute the majority section.
            $tableRows = array_values(array_filter(
                $accepted,
                static fn(array $r): bool => (string) ($r['table'] ?? '') === $sourceTable,
            ));

            if ($tableRows === []) {
                $skipped[] = $fqcn . ' (no accepted columns for table ' . $sourceTable . ')';
                continue;
            }

            // Phase 6: prefer the LLM's accepted nodeClass row when present.
            // The LLM emits BOTH targetEntryType AND targetSection — they
            // are NOT the same thing in Craft (entryType lives in a section,
            // and the section handle is what Craft looks up for save). Use
            // both when available; fall back to majority-vote across accepted
            // column rows otherwise (operators who ran analyze with --no-ai,
            // or whose entity step proposed needs-review and they haven't
            // promoted yet).
            $entryTypeForFqcn = '';
            $nodeClassRow = $acceptedNodeClassByFqcn[$fqcn] ?? null;
            if ($nodeClassRow !== null) {
                $entryTypeForFqcn = (string) ($nodeClassRow['targetEntryType'] ?? '');
            }
            if ($entryTypeForFqcn === '') {
                $entryTypeCounts = [];
                foreach ($tableRows as $r) {
                    $et = (string) ($r['targetEntryType'] ?? '');
                    if ($et === '') {
                        continue;
                    }
                    $entryTypeCounts[$et] = ($entryTypeCounts[$et] ?? 0) + 1;
                }
                if ($entryTypeCounts === []) {
                    // Phase 6 fallback: if Settings::defaultEntryType is set, route this
                    // FQCN there so it migrates into a generic catch-all instead of
                    // failing at load time. Otherwise skip as before.
                    if ($defaultEntryType !== null && $defaultEntryType !== '') {
                        $entryTypeForFqcn = $defaultEntryType;
                        $fallbackEntryTypeApplied[] = $fqcn;
                    } else {
                        $skipped[] = $fqcn . ' (no targetEntryType assigned on any accepted column or nodeClass row; set Settings::defaultEntryType to enable graceful fallback)';
                        continue;
                    }
                } else {
                    $entryTypeForFqcn = $this->majorityKey($entryTypeCounts);
                }
            }
            // Phase 6 closed-set validation: if the chosen entryType handle isn't a
            // real Craft entry type AND the operator opted in to graceful fallback,
            // route the FQCN to the default. Catches the basename-heuristic case
            // where derived handles like `loginPage` or `topLevelPage` look plausible
            // but don't exist in the project's Craft schema.
            if (
                $craftEntryTypeHandles !== []
                && !in_array($entryTypeForFqcn, $craftEntryTypeHandles, true)
                && $defaultEntryType !== null
                && $defaultEntryType !== ''
            ) {
                $entryTypeForFqcn = $defaultEntryType;
                $fallbackEntryTypeApplied[] = $fqcn;
            }
            // sectionKey is the LOOKUP KEY into the sections[] map; by
            // convention sections[] is keyed by entry-type handle (the
            // sections[entryTypeForFqcn] entry's `.section` field carries
            // the real Craft section handle separately).
            $sectionKey = $entryTypeForFqcn;

            // Build fields[targetHandle] from accepted rows whose targetEntryType matches.
            $sectionRows = array_values(array_filter(
                $tableRows,
                static fn(array $r): bool => (string) ($r['targetEntryType'] ?? '') === $sectionKey,
            ));
            $fields = [];
            foreach ($sectionRows as $r) {
                $targetHandle = (string) ($r['targetHandle'] ?? '');
                if ($targetHandle === '') {
                    continue;
                }
                if (isset($fields[$targetHandle])) {
                    // Multiple source columns mapped to same target handle — operator
                    // must disambiguate. Keep the first; warn.
                    $warnings[] = sprintf(
                        '%s: multiple source columns mapped to %s.%s — kept %s (column %s ignored)',
                        $fqcn,
                        $sectionKey,
                        $targetHandle,
                        (string) $fields[$targetHandle]['source'],
                        (string) ($r['column'] ?? '?'),
                    );
                    continue;
                }
                $compiled = [
                    'handler' => (string) ($r['handler'] ?? 'plain'),
                    'source'  => (string) ($r['column'] ?? $targetHandle),
                ];
                // Phase 8.7 / D-32 — auto-fill handlerOptions.stateSource for
                // page-level `handler: relation` rows when the source column
                // matches a Doctrine ManyToOne FK on the owning entity. Without
                // this, the LLM-proposed `caseCategory ← case_study_category_id`
                // mapping has no stateSource and RelationHandler::resolveDirect
                // can't look up the migrated category at runtime → empty
                // relation field on every CaseStudyPage.
                if ($compiled['handler'] === 'relation' && !isset($r['handlerOptions'])) {
                    $opts = $this->relationOptionsForFkColumn($fqcn, $compiled['source']);
                    if ($opts !== null) {
                        $compiled['handlerOptions'] = $opts;
                    }
                } elseif (isset($r['handlerOptions']) && is_array($r['handlerOptions']) && $r['handlerOptions'] !== []) {
                    $compiled['handlerOptions'] = $r['handlerOptions'];
                }
                $fields[$targetHandle] = $compiled;
            }
            ksort($fields);

            // Stub un-derived keys so operator sees the gaps.
            $nodeClasses[$fqcn] = [
                'sourceTable'         => $sourceTable,
                'section'             => $sectionKey,
                'fields'              => $fields,
                // Operator-curated below this line — leave empty stubs.
                'pageBuilderHandle'   => '',
                'pageBuilderContexts' => array_map(
                    static fn(array $c): string => (string) ($c['name'] ?? ''),
                    (array) ($pageInfo['contexts'] ?? []),
                ),
                'bodyColumn'          => '',
                'headerBlock'         => null,
                'bodyWrapBlock'       => null,
                'joins'               => [],
            ];

            $fieldsPerSection[$sectionKey] = ($fieldsPerSection[$sectionKey] ?? 0) + count($fields);
        }
        ksort($nodeClasses);

        // Sites: operator-curated; pass through unchanged.
        $sitesOut = [];
        foreach ($sites as $locale => $handle) {
            if (is_string($locale) && is_string($handle) && $locale !== '' && $handle !== '') {
                $sitesOut[$locale] = $handle;
            }
        }
        ksort($sitesOut);
        if ($sitesOut === []) {
            $warnings[] = 'No sites map provided — Settings::localeMap is empty. Migrate cannot resolve per-locale Craft site IDs without it.';
        }

        // Phase 7: implicit-content page-part compilation. Walk accepted kind=pagePart
        // rows whose pagePartClass is the synthetic '__implicit_content__' marker
        // (analyze step 6.5 emits these for content-only pages). For each such row,
        // emit a synthetic mapping.pageParts entry keyed by '__implicit_content__|<short>|<context>'
        // and wire the parent nodeClasses entry's pageBuilderHandle/pageBuilderContexts
        // so ExtractService's synthetic page-part injection has a target to dispatch into.
        // Operator hand-edits to mapping.pageParts/nodeClasses are preserved (skip-existing).
        $existingPageParts = (array) ($mapping['pageParts'] ?? []);
        [$pagePartsOut, $nodeClasses, $implicitEmitted, $implicitWarnings] =
            $this->compileImplicitBlocks($proposals, $pageStructure, $existingPageParts, $nodeClasses);
        $warnings = array_merge($warnings, $implicitWarnings);

        // Phase 8.4 / D-19 — fold accepted kind=pagePart proposals into
        // mapping.pageParts[<pagePartFqcn>] so TransformService::transformPageBuilder's
        // `$mapping['pageParts'][$ppFqcn]` lookup actually finds an entry. Without
        // this pass, every real page-part row from the LLM (or the page-part
        // fallback) generates 0 Matrix blocks at transform time — the proposals
        // exist in mapping.yaml but never reach the transform stage.
        // Skip-existing: operator-curated entries always win.
        // Field-shape: TransformService expects fields[<targetHandle>] => {source, handler}.
        // For real pageparts the LLM doesn't propose field-level mappings yet, so we
        // emit empty fields here — produces a Matrix block with the right type but no
        // sub-field content. Operator can extend mapping.pageParts entries with field
        // specs by hand. Phase 8.5+ could add a per-pagePart-table column proposer.
        $pagePartsRegularEmitted = 0;
        foreach ($proposals as $pRow) {
            if (!is_array($pRow)) { continue; }
            if (((string) ($pRow['kind'] ?? '')) !== 'pagePart') { continue; }
            if (((string) ($pRow['status'] ?? '')) !== 'accepted') { continue; }
            $ppFqcn = (string) ($pRow['pagePartClass'] ?? '');
            if ($ppFqcn === '') { continue; }
            // Skip the synthetic `__implicit_content__` rows — those are handled
            // by compileImplicitBlocks() with parent-aware key derivation
            // (`__implicit_content__|<parentShort>|<context>`). Folding them
            // here under the bare `__implicit_content__` key would shadow the
            // implicit emitter and break Phase 7's content-only flow.
            if ($ppFqcn === '__implicit_content__') { continue; }
            if (isset($pagePartsOut[$ppFqcn])) { continue; }
            $blockType = (string) ($pRow['targetBlockType'] ?? '');
            if ($blockType === '') { continue; }
            // Carry through any operator-supplied fields map; default to empty.
            // Phase 8.6 / D-26: the per-page-part column proposer emits fields
            // in the residual list-of-dicts shape (each entry has
            // sourceProperty/targetHandle/handler). Detect that shape and
            // collapse to the final assoc map keyed by targetHandle.
            $fieldsRaw = (array) ($pRow['fields'] ?? []);
            $fieldsMap = $this->collapsePagePartFieldsList($fieldsRaw, $ppFqcn, $warnings);
            $pagePartsOut[$ppFqcn] = [
                'target' => $blockType,
                'fields' => $fieldsMap,
            ];
            $pagePartsRegularEmitted++;
        }
        ksort($pagePartsOut);
        ksort($nodeClasses);

        // Phase 8.3 / D-16 — propagate targetMatrixField from accepted
        // kind=pagePart rows to the parent nodeClasses[fqcn].pageBuilderHandle.
        // Without this, parent nodeClasses keep pageBuilderHandle: '' even
        // when there are accepted pagepart proposals targeting a Matrix on
        // them, and TransformService::transformOne's `if ($pageBuilderHandle
        // !== '')` gate skips the page-builder step entirely. Operator-set
        // pageBuilderHandle wins (skip-existing).
        $pbHandlePropagated = [];
        foreach ($proposals as $pRow) {
            if (!is_array($pRow)) { continue; }
            if (((string) ($pRow['kind'] ?? '')) !== 'pagePart') { continue; }
            if (((string) ($pRow['status'] ?? '')) !== 'accepted') { continue; }
            $matrix = (string) ($pRow['targetMatrixField'] ?? '');
            $parentShort = (string) ($pRow['parentPageClass'] ?? '');
            $context = (string) ($pRow['context'] ?? '');
            if ($matrix === '' || $parentShort === '') { continue; }
            // Resolve parentShort to FQCN via the same short→FQCN lookup
            // compileImplicitBlocks uses (compute inline — small N).
            $parentFqcn = null;
            foreach (array_keys($pageStructure) as $fqcn) {
                if (!is_string($fqcn)) { continue; }
                $parts = explode('\\', trim($fqcn, '\\'));
                if (((string) end($parts)) === $parentShort) { $parentFqcn = $fqcn; break; }
            }
            if ($parentFqcn === null || !isset($nodeClasses[$parentFqcn])) { continue; }
            // Skip-existing: operator-set wins.
            if ((string) ($nodeClasses[$parentFqcn]['pageBuilderHandle'] ?? '') === '') {
                $nodeClasses[$parentFqcn]['pageBuilderHandle'] = $matrix;
                $pbHandlePropagated[] = $parentFqcn . ' -> ' . $matrix;
            }
            // Always merge context (de-duped).
            $existingCtxs = array_values(array_filter(
                (array) ($nodeClasses[$parentFqcn]['pageBuilderContexts'] ?? []),
                static fn(mixed $c): bool => is_string($c) && $c !== '',
            ));
            if ($context !== '' && !in_array($context, $existingCtxs, true)) {
                $existingCtxs[] = $context;
                $nodeClasses[$parentFqcn]['pageBuilderContexts'] = $existingCtxs;
            }
        }

        // Phase 8 / D-07: compile mapping.taxonomies block from accepted kind=taxonomy
        // proposals. Identity key = FQCN. Skip-existing per MAP-04 — operator-curated
        // mapping.taxonomies entries always win.
        $existingTaxonomies = (array) ($mapping['taxonomies'] ?? []);
        [$taxonomiesOut, $taxonomiesEmitted, $taxonomyWarnings] =
            $this->compileTaxonomies($proposals, $existingTaxonomies);
        ksort($taxonomiesOut);
        $warnings = array_merge($warnings, $taxonomyWarnings);

        // Phase 8 / D-12: fold layout-block proposer output (headerBlock /
        // bodyWrapBlock / bodyColumn) into the existing nodeClasses entries.
        // Per-slot skip-existing — if the operator already filled a slot, the
        // proposal does not overwrite it.
        [$nodeClasses, $layoutBlocksEmitted, $layoutWarnings] =
            $this->compileLayoutBlocks($proposals, $nodeClasses);
        $warnings = array_merge($warnings, $layoutWarnings);

        // Phase 8 / D-13: emit top-level mapping.dataProviders block from accepted
        // kind=dataProvider proposals. Identity key = FQCN. Skip-existing per
        // MAP-04 — operator-curated entries always win.
        $existingDataProviders = (array) ($mapping['dataProviders'] ?? []);
        [$dataProvidersOut, $dataProvidersEmitted, $dataProviderWarnings] =
            $this->compileDataProviders($proposals, $existingDataProviders);
        ksort($dataProvidersOut);
        $warnings = array_merge($warnings, $dataProviderWarnings);

        return [
            'proposals'      => array_values($proposals),
            'nodeClasses'    => $nodeClasses,
            'sections'       => $sections,
            'sites'          => $sitesOut,
            'pageParts'      => $pagePartsOut,
            'taxonomies'     => $taxonomiesOut,
            'dataProviders'  => $dataProvidersOut,
            '_compileReport' => [
                'nodeClassesEmitted'        => count($nodeClasses),
                'sectionsEmitted'           => count($sections),
                'fieldsEmittedPerSection'   => $fieldsPerSection,
                'skippedNodeClasses'        => $skipped,
                'autoAssignedTargets'       => $autoAssigned,
                'fallbackEntryTypeApplied'  => $fallbackEntryTypeApplied,
                'fallbackBlockTypeApplied'  => $fallbackBlockTypeApplied,
                'autoFilledMatrixField'     => $autoFilledMatrixField,
                'pageBuilderHandlePropagated' => $pbHandlePropagated,
                'pagePartsRegularEmitted'   => $pagePartsRegularEmitted,
                'fallbackEntryTypeUsed'     => $defaultEntryType,
                'fallbackBlockTypeUsed'     => $defaultBlockType,
                'implicitBlocksEmitted'     => $implicitEmitted,
                'taxonomiesEmitted'         => $taxonomiesEmitted,
                'layoutBlocksEmitted'       => $layoutBlocksEmitted,
                'dataProvidersEmitted'      => $dataProvidersEmitted,
                'warnings'                  => $warnings,
            ],
        ];
    }

    /**
     * Phase 7 — compile accepted '__implicit_content__' pagePart rows into runtime shape.
     *
     * For each such row:
     *   - emit `mapping.pageParts['__implicit_content__|<parentShort>|<context>']` with
     *     `target: targetBlockType` and `fields: {<targetHandle>: {source, handler}}` so
     *     TransformService.transformPageBuilder dispatches the synthetic page-part injected
     *     by ExtractService through the regular pageParts pipeline.
     *   - mutate `nodeClasses[<fqcn>]` for the parent FQCN matching parentPageClass:
     *       * pageBuilderHandle ← targetMatrixField (only if currently empty;
     *         operator-set wins).
     *       * pageBuilderContexts ← merged with [context] (de-duped).
     *     The FQCN is resolved by short-class match against pageStructure (the same
     *     convention analyze uses when it emits parentPageClass).
     *
     * Skip-existing semantics: a row whose synthetic key already exists in the
     * operator's mapping.pageParts is left untouched (operator decisions are sacred).
     *
     * Empty `handler` defaults to 'plain' so TransformService doesn't warn
     * "Unknown handler ''" for every implicit block.
     *
     * @param  list<array<string, mixed>>                    $proposals
     * @param  array<string, mixed>                          $pageStructure
     * @param  array<string, mixed>                          $existingPageParts  Operator-curated pageParts block from mapping.yaml
     * @param  array<string, array<string, mixed>>           $nodeClasses        Already-built nodeClasses (mutated in-place)
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: int, 3: list<string>}
     *         [pagePartsOut, nodeClassesOut, implicitEmittedCount, warnings]
     */
    private function compileImplicitBlocks(
        array $proposals,
        array $pageStructure,
        array $existingPageParts,
        array $nodeClasses,
    ): array {
        $pagePartsOut = [];
        foreach ($existingPageParts as $k => $v) {
            if (is_string($k) && is_array($v)) {
                $pagePartsOut[$k] = $v;
            }
        }

        // Build short-class → FQCN lookup once. Two FQCNs sharing a basename
        // (e.g. App\Entity\Pages\NewsPage vs App\Entity\Archive\NewsPage) is
        // rare in real Kunstmaan repos but defensible to surface — without a
        // warning, an implicit row whose parentPageClass is the colliding name
        // would silently route to whichever FQCN won the last-write race.
        $shortToFqcn = [];
        $shortCollisions = [];
        foreach (array_keys($pageStructure) as $fqcn) {
            if (!is_string($fqcn)) { continue; }
            $parts = explode('\\', trim($fqcn, '\\'));
            $short = (string) end($parts);
            if ($short === '') { continue; }
            if (isset($shortToFqcn[$short]) && $shortToFqcn[$short] !== $fqcn) {
                $shortCollisions[$short][] = $fqcn;
                $shortCollisions[$short] = array_values(array_unique(
                    array_merge([$shortToFqcn[$short]], $shortCollisions[$short]),
                ));
            } else {
                $shortToFqcn[$short] = $fqcn;
            }
        }

        $emitted = 0;
        $warnings = [];
        foreach ($shortCollisions as $short => $fqcns) {
            $warnings[] = sprintf(
                'pageStructure has %d FQCNs sharing basename "%s" (%s) — implicit-content rows referencing this short name route to the first one. Rename or split the colliding entities to disambiguate.',
                count($fqcns),
                $short,
                implode(', ', $fqcns),
            );
        }

        foreach ($proposals as $row) {
            if (!is_array($row)) { continue; }
            if (((string) ($row['kind'] ?? '')) !== 'pagePart') { continue; }
            if (((string) ($row['pagePartClass'] ?? '')) !== '__implicit_content__') { continue; }
            if (((string) ($row['status'] ?? '')) !== 'accepted') { continue; }

            $parentShort = (string) ($row['parentPageClass'] ?? '');
            $context     = (string) ($row['context'] ?? 'main');
            $matrixField = (string) ($row['targetMatrixField'] ?? '');
            $blockType   = (string) ($row['targetBlockType'] ?? '');

            if ($parentShort === '' || $matrixField === '' || $blockType === '') {
                $warnings[] = sprintf(
                    'implicit-content row for %s/%s skipped: needs targetMatrixField AND targetBlockType (got matrix=%s block=%s)',
                    $parentShort ?: '?',
                    $context ?: '?',
                    $matrixField ?: '∅',
                    $blockType ?: '∅',
                );
                continue;
            }

            $key = '__implicit_content__|' . $parentShort . '|' . $context;

            // Build fields[<targetHandle>] = {source, handler}. Skip rows missing
            // targetHandle — those are unfilled operator decisions.
            $fieldsOut = [];
            foreach ((array) ($row['fields'] ?? []) as $f) {
                if (!is_array($f)) { continue; }
                $tgt = (string) ($f['targetHandle'] ?? '');
                $src = (string) ($f['sourceProperty'] ?? '');
                if ($tgt === '' || $src === '') { continue; }
                $handler = (string) ($f['handler'] ?? '');
                if ($handler === '') {
                    $handler = 'plain';
                }
                $fieldsOut[$tgt] = ['source' => $src, 'handler' => $handler];
            }
            if ($fieldsOut === []) {
                $warnings[] = sprintf(
                    'implicit-content row for %s/%s skipped: no fields with both sourceProperty and targetHandle filled in',
                    $parentShort,
                    $context,
                );
                continue;
            }
            ksort($fieldsOut);

            // Skip-existing: operator-curated mapping.pageParts wins.
            if (isset($pagePartsOut[$key])) {
                continue;
            }
            $pagePartsOut[$key] = [
                'target' => $blockType,
                'fields' => $fieldsOut,
            ];
            $emitted++;

            // Wire the parent nodeClasses entry. The parent FQCN must match a real
            // pageStructure entry by short-class basename, AND must already have a
            // nodeClasses[fqcn] entry (built earlier in compile()) — otherwise the
            // implicit block has no entry to ride on at extract time.
            $fqcn = $shortToFqcn[$parentShort] ?? null;
            if ($fqcn === null || !isset($nodeClasses[$fqcn])) {
                $warnings[] = sprintf(
                    'implicit-content row for %s/%s emitted to mapping.pageParts but no matching nodeClasses[%s] — extract will not inject',
                    $parentShort,
                    $context,
                    $fqcn ?? $parentShort,
                );
                continue;
            }

            // Operator-set pageBuilderHandle wins; only fill when empty.
            if ((string) ($nodeClasses[$fqcn]['pageBuilderHandle'] ?? '') === '') {
                $nodeClasses[$fqcn]['pageBuilderHandle'] = $matrixField;
            }
            // Merge context into pageBuilderContexts (de-dupe; preserve order).
            $existingCtxs = array_values(array_filter(
                (array) ($nodeClasses[$fqcn]['pageBuilderContexts'] ?? []),
                static fn(mixed $c): bool => is_string($c) && $c !== '',
            ));
            if (!in_array($context, $existingCtxs, true)) {
                $existingCtxs[] = $context;
            }
            $nodeClasses[$fqcn]['pageBuilderContexts'] = $existingCtxs;
        }

        return [$pagePartsOut, $nodeClasses, $emitted, $warnings];
    }

    /**
     * Index accepted kind=nodeClass rows by source table → targetEntryType.
     * Phase 6: feeds the column-row backfill pass so column proposals inherit
     * the LLM's entity-level decision before the majority-vote fallback fires.
     *
     * @param  list<array<string, mixed>> $proposals
     * @return array<string, string>      sourceTable → targetEntryType
     */
    private function indexAcceptedNodeClassesByTable(array $proposals): array
    {
        $out = [];
        foreach ($proposals as $r) {
            if (!is_array($r)) { continue; }
            if (((string) ($r['kind'] ?? '')) !== 'nodeClass') { continue; }
            if (((string) ($r['status'] ?? '')) !== 'accepted') { continue; }
            $tbl = (string) ($r['sourceTable'] ?? '');
            $et  = (string) ($r['targetEntryType'] ?? '');
            if ($tbl !== '' && $et !== '') {
                $out[$tbl] = $et;
            }
        }
        return $out;
    }

    /**
     * Index accepted kind=nodeClass rows by FQCN → row. Used by the per-FQCN
     * loop in compile() so the LLM's targetEntryType wins over the basename
     * heuristic / majority-vote fallback.
     *
     * @param  list<array<string, mixed>> $proposals
     * @return array<string, array<string, mixed>>
     */
    private function indexAcceptedNodeClassesByFqcn(array $proposals): array
    {
        $out = [];
        foreach ($proposals as $r) {
            if (!is_array($r)) { continue; }
            if (((string) ($r['kind'] ?? '')) !== 'nodeClass') { continue; }
            if (((string) ($r['status'] ?? '')) !== 'accepted') { continue; }
            $fqcn = (string) ($r['fqcn'] ?? '');
            if ($fqcn !== '') {
                $out[$fqcn] = $r;
            }
        }
        return $out;
    }

    /**
     * Build sourceTable → entryTypeHandle lookup from pageStructure entries.
     * Entry-type handle is derived from the FQCN basename via lcfirst (so
     * `App\Entity\Pages\NewsPage` → `newsPage`). Operators can rename handles
     * after the fact in the resulting mapping.yaml.
     *
     * @param  array<string, mixed> $pageStructure
     * @return array<string, string>  table → entryType
     */
    private function buildTableToEntryTypeMap(array $pageStructure): array
    {
        $out = [];
        foreach ($pageStructure as $fqcn => $info) {
            if (!is_string($fqcn) || !is_array($info)) {
                continue;
            }
            $table = (string) ($info['tableName'] ?? '');
            if ($table === '') {
                continue;
            }
            $parts = explode('\\', trim($fqcn, '\\'));
            $basename = end($parts);
            if (!is_string($basename) || $basename === '') {
                continue;
            }
            $out[$table] = lcfirst($basename);
        }
        return $out;
    }

    /**
     * Backfill empty `targetEntryType` on column proposals whose source
     * `table` matches a known table → entryType lookup.
     *
     * @param  list<array<string, mixed>> $proposals
     * @param  array<string, string>      $tableToEntryType
     * @return array{0: list<array<string, mixed>>, 1: int}  [updated proposals, count assigned]
     */
    private function autoAssignTargetEntryType(array $proposals, array $tableToEntryType): array
    {
        $assigned = 0;
        foreach ($proposals as &$r) {
            if (!is_array($r)) {
                continue;
            }
            if (((string) ($r['kind'] ?? 'column')) !== 'column') {
                continue;
            }
            $current = (string) ($r['targetEntryType'] ?? '');
            if ($current !== '') {
                continue;
            }
            $table = (string) ($r['table'] ?? '');
            if (!isset($tableToEntryType[$table])) {
                continue;
            }
            $r['targetEntryType'] = $tableToEntryType[$table];
            $assigned++;
        }
        unset($r);
        return [$proposals, $assigned];
    }

    /**
     * Keep only accepted column proposals (kind=column, status=accepted).
     * Drops/needs-review/proposed and pagePart rows are excluded — the ETL
     * cares about column→field mappings only.
     *
     * @param  list<array<string, mixed>> $proposals
     * @return list<array<string, mixed>>
     */
    private function filterAccepted(array $proposals): array
    {
        $out = [];
        foreach ($proposals as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (((string) ($r['kind'] ?? 'column')) !== 'column') {
                continue;
            }
            if (((string) ($r['status'] ?? '')) !== 'accepted') {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Group accepted column rows by targetEntryType. Empty targetEntryType
     * is dropped (it's a "no-section" decision — column accepted as
     * structurally-irrelevant metadata).
     *
     * @param  list<array<string, mixed>> $accepted
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByEntryType(array $accepted): array
    {
        $byEt = [];
        foreach ($accepted as $r) {
            $et = (string) ($r['targetEntryType'] ?? '');
            if ($et === '') {
                continue;
            }
            $byEt[$et][] = $r;
        }
        return $byEt;
    }

    /**
     * Return the key with the highest count; ties broken by alphabetical
     * ordering for stable output across runs.
     *
     * @param  array<string, int> $counts
     */
    private function majorityKey(array $counts): string
    {
        $max = 0;
        $winners = [];
        foreach ($counts as $k => $n) {
            if ($n > $max) {
                $max = $n;
                $winners = [$k];
            } elseif ($n === $max) {
                $winners[] = $k;
            }
        }
        sort($winners);
        return (string) ($winners[0] ?? '');
    }

    /**
     * Phase 8 / D-07: compile mapping.taxonomies block from accepted kind=taxonomy
     * proposals. Identity key = FQCN. Skip-existing per MAP-04 — operator-curated
     * mapping.taxonomies entries always win.
     *
     * Output entry shape (per FQCN):
     *   { sourceTable, targetSection, targetEntryType, fields: {legacyCol => craftHandle, ...} }
     *
     * Field-level mapping is inferred from same-sourceTable kind=column rows
     * (D-07: no nested fields[] on the taxonomy row itself; same convention
     * nodeClasses already use). The fold walks accepted column rows whose
     * `table` matches the taxonomy row's `sourceTable` and projects each into
     * `fields[$column] = $targetHandle`.
     *
     * @param  list<array<string, mixed>>          $proposals
     * @param  array<string, mixed>                $existingTaxonomies  Operator-curated taxonomies block from mapping.yaml
     * @return array{0: array<string, array<string, mixed>>, 1: int, 2: list<string>}
     *         [taxonomiesOut, taxonomiesEmitted, warnings]
     */
    private function compileTaxonomies(array $proposals, array $existingTaxonomies): array
    {
        $taxonomiesOut = [];
        foreach ($existingTaxonomies as $k => $v) {
            if (is_string($k) && is_array($v)) {
                $taxonomiesOut[$k] = $v;
            }
        }
        $emitted = 0;
        $warnings = [];
        foreach ($proposals as $row) {
            if (!is_array($row)) { continue; }
            if (((string) ($row['kind'] ?? '')) !== 'taxonomy') { continue; }
            if (((string) ($row['status'] ?? '')) !== 'accepted') { continue; }
            $fqcn = (string) ($row['fqcn'] ?? '');
            if ($fqcn === '') { continue; }
            // Skip-existing: operator-curated mapping.taxonomies wins (MAP-04).
            if (isset($taxonomiesOut[$fqcn])) { continue; }

            $sourceTable    = (string) ($row['sourceTable'] ?? '');
            $taxSection     = (string) ($row['targetSection'] ?? '');
            $taxEntryType   = (string) ($row['targetEntryType'] ?? '');

            // Phase 8.1 / D-07a: defensive skip — refuse to fold rows where any
            // of the three required keys is empty. TaxonomyMigrationService::migrateAll
            // hard-fails on incomplete rows, so emitting them here would crash the
            // load step. The MappingAuditor surfaces a missing-section / missing-entry-type
            // finding for these (advisory) — this guard makes compile defensive
            // even when the operator has not run audit + remediated upstream.
            if ($sourceTable === '' || $taxSection === '' || $taxEntryType === '') {
                $warnings[] = sprintf(
                    'taxonomy %s skipped: incomplete (sourceTable=%s, targetSection=%s, targetEntryType=%s) — re-run analyze or fix mapping.yaml',
                    $fqcn,
                    $sourceTable !== '' ? $sourceTable : '∅',
                    $taxSection !== '' ? $taxSection : '∅',
                    $taxEntryType !== '' ? $taxEntryType : '∅',
                );
                continue;
            }

            // D-07 field-fold: walk accepted same-sourceTable kind=column rows
            // and project each into fields[<legacyCol>] = <craftFieldHandle>.
            // Column row shape (MappingFile::buildRow): kind=column, table,
            // column, targetHandle, targetEntryType, status. The column-row
            // accessor is `targetHandle` (not the plan example's `targetField`,
            // which doesn't exist on the column row payload — see Plan 09
            // action: "Adjust the kind=column row's accessor — Plan 01
            // introduced targetField but the existing column-row shape may
            // use different keys").
            $fields = [];
            if ($sourceTable !== '') {
                foreach ($proposals as $colRow) {
                    if (!is_array($colRow)) { continue; }
                    if (((string) ($colRow['kind'] ?? 'column')) !== 'column') { continue; }
                    if (((string) ($colRow['status'] ?? '')) !== 'accepted') { continue; }
                    if (((string) ($colRow['table'] ?? '')) !== $sourceTable) { continue; }
                    $legacyCol   = (string) ($colRow['column'] ?? '');
                    $craftHandle = (string) ($colRow['targetHandle'] ?? $colRow['targetField'] ?? '');
                    if ($legacyCol === '' || $craftHandle === '') { continue; }
                    $fields[$legacyCol] = $craftHandle;
                }

                // Phase 8.4 / D-18 — taxonomy title heuristic. Taxonomy entries
                // need a Craft `title` to render as anything other than
                // `[legacy id N]`. The residual-column LLM tends to drop these
                // (rationale: "categories should be a Craft Categories field
                // type, not page columns") leaving the column row with an
                // empty targetHandle. Auto-default common title-like column
                // names (`name` / `title` / `label`) to `targetHandle: title`
                // so taxonomy entries get a meaningful title without operator
                // hand-edits. Operator-set targetHandle (non-empty) wins.
                foreach ($proposals as $colRow) {
                    if (!is_array($colRow)) { continue; }
                    if (((string) ($colRow['kind'] ?? 'column')) !== 'column') { continue; }
                    if (((string) ($colRow['table'] ?? '')) !== $sourceTable) { continue; }
                    $legacyCol = (string) ($colRow['column'] ?? '');
                    $tgt = (string) ($colRow['targetHandle'] ?? $colRow['targetField'] ?? '');
                    // Skip if already mapped (operator/LLM win).
                    if ($tgt !== '') { continue; }
                    if (isset($fields[$legacyCol])) { continue; }
                    if (!in_array(strtolower($legacyCol), ['name', 'title', 'label'], true)) { continue; }
                    $fields[$legacyCol] = 'title';
                }

                ksort($fields);
            }

            $taxonomiesOut[$fqcn] = [
                'sourceTable'     => $sourceTable,
                'targetSection'   => $taxSection,
                'targetEntryType' => $taxEntryType,
                'fields'          => $fields,
            ];
            $emitted++;
        }
        return [$taxonomiesOut, $emitted, $warnings];
    }

    /**
     * Phase 8 / D-12 — fold accepted kind=nodeClass partial-update rows
     * carrying headerBlock / bodyWrapBlock / bodyColumn into existing
     * nodeClasses[fqcn] entries. Per-slot skip-existing: a slot the operator
     * has already filled is never overwritten.
     *
     * Filters: kind === 'nodeClass' AND status === 'accepted' AND row carries
     * any of the three slot keys. Only mutates entries already present in
     * $nodeClassesIn (derived from pageStructure earlier in compile()) — a
     * proposal for an FQCN with no nodeClasses entry to ride on is silently
     * skipped (extract has nothing to dispatch through).
     *
     * @param  list<array<string, mixed>>          $proposals
     * @param  array<string, array<string, mixed>> $nodeClassesIn
     * @return array{0: array<string, array<string, mixed>>, 1: int, 2: list<string>}
     *         [nodeClassesOut, layoutBlocksEmitted, warnings]
     */
    private function compileLayoutBlocks(array $proposals, array $nodeClassesIn): array
    {
        $nodeClassesOut = $nodeClassesIn;
        $emitted = 0;
        $warnings = [];
        foreach ($proposals as $row) {
            if (!is_array($row)) { continue; }
            if (((string) ($row['kind'] ?? '')) !== 'nodeClass') { continue; }
            if (((string) ($row['status'] ?? '')) !== 'accepted') { continue; }
            $fqcn = (string) ($row['fqcn'] ?? '');
            if ($fqcn === '' || !isset($nodeClassesOut[$fqcn])) { continue; }
            $entry = $nodeClassesOut[$fqcn];
            $touched = false;
            foreach (['headerBlock', 'bodyWrapBlock', 'bodyColumn'] as $slot) {
                if (!array_key_exists($slot, $row)) { continue; }
                $proposed = $row[$slot];
                if ($proposed === null || $proposed === '') { continue; }
                // Per-slot skip-existing: operator-set wins.
                $existing = $entry[$slot] ?? null;
                if ($existing !== null && $existing !== '') { continue; }
                $entry[$slot] = (string) $proposed;
                $touched = true;
            }
            if ($touched) {
                $nodeClassesOut[$fqcn] = $entry;
                $emitted++;
            }
        }
        return [$nodeClassesOut, $emitted, $warnings];
    }

    /**
     * Phase 8 / D-13 — emit top-level mapping.dataProviders block from accepted
     * kind=dataProvider proposals. Identity key = FQCN. Skip-existing per
     * MAP-04 — operator-curated entries always win.
     *
     * Output entry shape (per FQCN): { sourceTable, target, configFields }.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @param  array<string, mixed>        $existing  Operator-curated dataProviders block from mapping.yaml
     * @return array{0: array<string, array<string, mixed>>, 1: int, 2: list<string>}
     *         [dataProvidersOut, dataProvidersEmitted, warnings]
     */
    private function compileDataProviders(array $proposals, array $existing): array
    {
        $out = [];
        foreach ($existing as $k => $v) {
            if (is_string($k) && is_array($v)) {
                $out[$k] = $v;
            }
        }
        $emitted = 0;
        $warnings = [];
        foreach ($proposals as $row) {
            if (!is_array($row)) { continue; }
            if (((string) ($row['kind'] ?? '')) !== 'dataProvider') { continue; }
            if (((string) ($row['status'] ?? '')) !== 'accepted') { continue; }
            $fqcn = (string) ($row['fqcn'] ?? '');
            if ($fqcn === '') { continue; }
            // Skip-existing: operator-curated mapping.dataProviders wins (MAP-04).
            if (isset($out[$fqcn])) { continue; }
            $out[$fqcn] = [
                'sourceTable'  => (string) ($row['sourceTable'] ?? ''),
                'target'       => (string) ($row['target'] ?? ''),
                'configFields' => (array) ($row['configFields'] ?? []),
            ];
            $emitted++;
        }
        return [$out, $emitted, $warnings];
    }

    /**
     * Phase 8.7 / D-32 — derive `handlerOptions` for a page-level `handler:
     * relation` field from the owning entity's Doctrine relations.
     *
     * The LLM proposes `handler: relation, source: <fk_col>` for FK-shaped
     * residual columns but doesn't always know the right `stateSource`. This
     * helper looks up the owning entity's ManyToOne relations, finds the one
     * whose `fkColumn` matches the source, and produces:
     *
     *   { stateSource: '<target_FQCN_slug>' }
     *
     * The state-table key shape matches `kunstmaanSourceId` (FQCN slug from
     * ExtractService::fqcnSlug) — both pages and taxonomies use it.
     *
     * Returns null when:
     *   - no Doctrine entity parser is available (test bootstrap)
     *   - the owning entity isn't parsed
     *   - no ManyToOne relation matches the source column
     *
     * @return array<string, mixed>|null
     */
    private function relationOptionsForFkColumn(string $owningFqcn, string $sourceCol): ?array
    {
        if ($owningFqcn === '' || $sourceCol === '') {
            return null;
        }
        try {
            $parser = \lameco\kunstmaanmigrator\Plugin::getInstance()->doctrineEntityParser;
        } catch (\Throwable) {
            return null;
        }
        $info = $parser->getByFqcn($owningFqcn);
        if ($info === null) {
            return null;
        }
        foreach ($info->relations as $rel) {
            if ($rel->relationType !== 'ManyToOne') { continue; }
            if ($rel->fkColumn !== $sourceCol) { continue; }
            $targetFqcn = trim($rel->targetEntity, '\\');
            if ($targetFqcn === '') { continue; }
            $stateSource = str_replace('\\', '_', $targetFqcn);
            return ['stateSource' => $stateSource];
        }
        return null;
    }

    /**
     * Phase 8.6 / D-26 — collapse the residual list-of-dicts fields shape
     * the per-page-part column proposer emits into the final assoc map shape
     * TransformService consumes.
     *
     * Input shapes accepted:
     *   (a) list of dicts with sourceProperty/targetHandle/handler
     *       (LLM-proposed; produced by proposePagePartFields):
     *         [['sourceProperty'=>'title','targetHandle'=>'heading','handler'=>'plain'], …]
     *   (b) assoc map keyed by targetHandle (operator-curated final form):
     *         ['heading' => ['handler'=>'plain','source'=>'title'], …]
     *
     * (b) is passed through untouched (skip-existing semantics — operator
     * already curated). (a) is collapsed to (b)-shape.
     *
     * Multi-source-to-same-target collisions: keep the first; warn on the
     * rest (mirrors the column-row collision rule at line 405).
     *
     * @param  array<int|string, mixed> $fieldsRaw
     * @param  list<string>             $warnings  appended to in-place
     * @return array<string, array{handler: string, source: string}>
     */
    private function collapsePagePartFieldsList(array $fieldsRaw, string $ppFqcn, array &$warnings): array
    {
        if ($fieldsRaw === []) {
            return [];
        }
        // Detect shape (b): keys are non-int and values are dicts with
        // `source` (the final-form marker) — pass through unchanged.
        $isFinalShape = true;
        foreach ($fieldsRaw as $k => $v) {
            if (!is_string($k) || !is_array($v) || !isset($v['source'])) {
                $isFinalShape = false;
                break;
            }
        }
        if ($isFinalShape) {
            /** @var array<string, array{handler: string, source: string}> $fieldsRaw */
            return $fieldsRaw;
        }

        // Shape (a): list of dicts. Collapse to assoc by targetHandle.
        $out = [];
        foreach ($fieldsRaw as $entry) {
            if (!is_array($entry)) { continue; }
            $tgt = (string) ($entry['targetHandle'] ?? '');
            $src = (string) ($entry['sourceProperty'] ?? '');
            $handler = (string) ($entry['handler'] ?? 'plain');
            if ($tgt === '' || $src === '') { continue; }
            if (isset($out[$tgt])) {
                $warnings[] = sprintf(
                    '%s: page-part fields collision — %s already mapped to source `%s`; ignoring source `%s`',
                    $ppFqcn,
                    $tgt,
                    (string) $out[$tgt]['source'],
                    $src,
                );
                continue;
            }
            $compiled = [
                'handler' => $handler !== '' ? $handler : 'plain',
                'source'  => $src,
            ];
            // Phase 8.7 / D-31 — preserve handlerOptions for relation/asset/
            // matrix handlers that need them (e.g. relation with joinTable).
            if (isset($entry['handlerOptions']) && is_array($entry['handlerOptions']) && $entry['handlerOptions'] !== []) {
                $compiled['handlerOptions'] = $entry['handlerOptions'];
            }
            $out[$tgt] = $compiled;
        }
        ksort($out);
        return $out;
    }
}
