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
        array $flatPagePartCandidates = [],
        array $entryTypeFlatHandles = [],
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
        if ($craftEntryTypeHandles !== []) {
            $allowedEntryTypes = array_flip(array_map('strval', $craftEntryTypeHandles));
            $tableToEntryTypeFromHeuristic = array_filter(
                $tableToEntryTypeFromHeuristic,
                static fn(string $entryType): bool => isset($allowedEntryTypes[$entryType]),
            );
        }
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
            // Phase 8.7 / D-40 — entry-type's allowed flat-handle catalog
            // for targetHandle validation. Empty list = no catalog passed
            // (legacy callers / tests) → skip validation per call-site.
            $allowedHandles = $entryTypeFlatHandles[$sectionKey] ?? null;
            $fields = [];
            foreach ($sectionRows as $r) {
                $nativePostDate = $this->isNativePostDateSource($r);
                if (!$nativePostDate && in_array((string) ($r['relationIntent'] ?? ''), ['drop', 'out_of_scope'], true)) {
                    continue;
                }
                $targetHandle = $this->targetHandleForCompiledRow($r, $sectionKey);
                if ($targetHandle === '') {
                    continue;
                }
                $r['targetHandle'] = $targetHandle;
                // Phase 8.7 / D-40 — drop+warn when targetHandle doesn't exist
                // on the chosen entry-type. Catches silent-empty bugs like
                // `content → newsPage::content` (newsPage has no `content`
                // field). Only fires when the catalog was supplied; the
                // legacy null path keeps existing tests passing. Dotted-path
                // targets (Matrix sub-fields, e.g. `headerHome.title` per
                // Phase 8.2 / D-15) are handled by a separate validation
                // path — skip flat-catalog check.
                if ($allowedHandles !== null
                    && !str_contains($targetHandle, '.')
                    && !$this->isNativeEntryProperty($targetHandle)
                    && !in_array($targetHandle, $allowedHandles, true)
                ) {
                    $warnings[] = sprintf(
                        '%s: column proposal %s.%s → %s::%s dropped — `%s` is not a flat field on entry-type `%s` (allowed: %s)',
                        $fqcn,
                        (string) ($r['table'] ?? '?'),
                        (string) ($r['column'] ?? '?'),
                        $sectionKey,
                        $targetHandle,
                        $targetHandle,
                        $sectionKey,
                        implode(', ', $allowedHandles),
                    );
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
                    'handler' => $this->handlerForCompiledRow($r, $targetHandle),
                    'source'  => (string) ($r['column'] ?? $targetHandle),
                ];
                // Phase 8.7 / D-32 — auto-fill handlerOptions.stateSource for
                // page-level `handler: relation` rows when the source column
                // matches a Doctrine ManyToOne FK on the owning entity. Without
                // this, the LLM-proposed `caseCategory ← case_study_category_id`
                // mapping has no stateSource and RelationHandler::resolveDirect
                // can't look up the migrated category at runtime → empty
                // relation field on every CaseStudyPage.
                if ($compiled['handler'] === 'relation') {
                    $relationClosure = $this->relationOptionsForCompiledRow($fqcn, $r, $compiled['source'], $pageStructure);
                    if ($relationClosure['warning'] !== null) {
                        $warnings[] = $relationClosure['warning'];
                    }
                    if ($relationClosure['options'] !== null) {
                        $compiled['handlerOptions'] = $this->mergeRelationHandlerOptions(
                            isset($r['handlerOptions']) && is_array($r['handlerOptions']) ? $r['handlerOptions'] : [],
                            $relationClosure['options'],
                        );
                    } elseif (isset($r['handlerOptions']) && is_array($r['handlerOptions']) && $r['handlerOptions'] !== []) {
                        $compiled['handlerOptions'] = $r['handlerOptions'];
                    }
                    if ($relationClosure['source'] !== null) {
                        $compiled['source'] = $relationClosure['source'];
                    }
                } elseif (isset($r['handlerOptions']) && is_array($r['handlerOptions']) && $r['handlerOptions'] !== []) {
                    $compiled['handlerOptions'] = $r['handlerOptions'];
                }
                $validationWarning = $this->validateCompiledFieldSpec($fqcn, $r, $compiled);
                if ($validationWarning !== null) {
                    $warnings[] = $validationWarning;
                    continue;
                }
                $fields[$targetHandle] = $compiled;
            }
            $this->maybeAddContactCtaTeamMemberField($fields, $sectionKey, $allowedHandles);
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
            $this->mergeOperatorNodeClassOverrides($nodeClasses[$fqcn], (array) ($mapping['nodeClasses'][$fqcn] ?? []));

            // Phase 8.7 / D-39 — auto-detect flatPagePartContent target. When
            // the entry-type for this FQCN has no Matrix field and has at
            // least one ckeditor field, set the flat-fold target so vendor
            // page-parts (TextPagePart, MultiLineTextPagePart, etc.)
            // attached to legacy pages flow into the flat field at transform
            // time (D-38 routing). Operator hand-edits below win on
            // `compile --overwrite` re-runs because compile preserves
            // pageParts/nodeClasses operator overrides via skip-existing —
            // here we only set the value if the candidate map nominates one.
            $candidate = $flatPagePartCandidates[$sectionKey] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $nodeClasses[$fqcn]['flatPagePartContent'] = $candidate;
            }

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
            $this->compileImplicitBlocks($proposals, $pageStructure, $existingPageParts, $nodeClasses, $entryTypeFlatHandles);
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
            if (!$this->parentOwnsMatrixField($nodeClasses[$parentFqcn], $matrix, $entryTypeFlatHandles)) {
                $warnings[] = $this->pageBuilderOwnershipWarning($parentFqcn, $matrix, $pRow, $nodeClasses[$parentFqcn]);
                continue;
            }
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
        $this->applyFallbackBodyContent(
            $nodeClasses,
            $proposals,
            $defaultEntryType,
            $defaultBlockType,
            $matrixFieldCatalog,
            $entryTypeFlatHandles,
        );
        $this->removeFabricatedBodyWrapTitles($nodeClasses);

        // Phase 8 / D-13: emit top-level mapping.dataProviders block from accepted
        // kind=dataProvider proposals. Identity key = FQCN. Skip-existing per
        // MAP-04 — operator-curated entries always win.
        $existingDataProviders = (array) ($mapping['dataProviders'] ?? []);
        [$dataProvidersOut, $dataProvidersEmitted, $dataProviderWarnings] =
            $this->compileDataProviders($proposals, $existingDataProviders);
        ksort($dataProvidersOut);
        $warnings = array_merge($warnings, $dataProviderWarnings);

        // Phase 11 / D-16: promoted/shared relation targets need their own
        // executable identity before owner entries reference them.
        [$promotedTargetsOut, $promotedTargetsEmitted, $promotedWarnings] =
            $this->compilePromotedTargets($proposals, (array) ($mapping['promotedTargets'] ?? []));
        ksort($promotedTargetsOut);
        $warnings = array_merge($warnings, $promotedWarnings);

        return [
            'proposals'      => array_values($proposals),
            'nodeClasses'    => $nodeClasses,
            'sections'       => $sections,
            'sites'          => $sitesOut,
            'pageParts'      => $pagePartsOut,
            'taxonomies'     => $taxonomiesOut,
            'dataProviders'  => $dataProvidersOut,
            'promotedTargets' => $promotedTargetsOut,
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
                'promotedTargetsEmitted'    => $promotedTargetsEmitted,
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
     * @param  array<string, list<string>>                   $entryTypeFlatHandles
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: int, 3: list<string>}
     *         [pagePartsOut, nodeClassesOut, implicitEmittedCount, warnings]
     */
    private function compileImplicitBlocks(
        array $proposals,
        array $pageStructure,
        array $existingPageParts,
        array $nodeClasses,
        array $entryTypeFlatHandles = [],
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
            if (!$this->parentOwnsMatrixField($nodeClasses[$fqcn], $matrixField, $entryTypeFlatHandles)) {
                $warnings[] = $this->pageBuilderOwnershipWarning($fqcn, $matrixField, $row, $nodeClasses[$fqcn]);
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
     * Validate that a page-builder Matrix field is owned by the parent entry
     * type before propagating it to nodeClasses[].pageBuilderHandle.
     *
     * Empty catalogs preserve legacy/test behavior; when a caller supplies the
     * Craft entry-type handle catalog, invalid ownership is treated as visible
     * compile validation and propagation is blocked.
     *
     * @param array<string, mixed>        $nodeClass
     * @param array<string, list<string>> $entryTypeFlatHandles
     */
    private function parentOwnsMatrixField(array $nodeClass, string $matrixField, array $entryTypeFlatHandles): bool
    {
        if ($matrixField === '' || $entryTypeFlatHandles === []) {
            return true;
        }
        $entryType = (string) ($nodeClass['section'] ?? '');
        if ($entryType === '' || !isset($entryTypeFlatHandles[$entryType])) {
            return true;
        }
        return in_array($matrixField, $entryTypeFlatHandles[$entryType], true);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $nodeClass
     */
    private function pageBuilderOwnershipWarning(string $parentFqcn, string $matrixField, array $row, array $nodeClass): string
    {
        $entryType = (string) ($nodeClass['section'] ?? '∅');
        $source = (string) ($row['pagePartClass'] ?? $row['parentPageClass'] ?? '__implicit_content__');
        $flatFallback = (string) ($nodeClass['flatPagePartContent'] ?? '');
        if ($flatFallback !== '') {
            return sprintf(
                '%s: pageBuilderHandle `%s` not propagated for %s because entry-type `%s` does not own that Matrix field; preserving page-part content via flatPagePartContent `%s`.',
                $parentFqcn,
                $matrixField,
                $source,
                $entryType,
                $flatFallback,
            );
        }

        return sprintf(
            '%s: pageBuilderHandle `%s` not propagated for %s because entry-type `%s` does not own that Matrix field and no flatPagePartContent fallback is available; mapping requires operator review to avoid data loss.',
            $parentFqcn,
            $matrixField,
            $source,
            $entryType,
        );
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
            foreach (['headerBlock', 'bodyWrapBlock', 'bodyColumn', 'mergeRelations'] as $slot) {
                if (!array_key_exists($slot, $row)) { continue; }
                $proposed = $row[$slot];
                if ($proposed === null || $proposed === '') { continue; }
                // Per-slot skip-existing: operator-set wins.
                $existing = $entry[$slot] ?? null;
                if ($existing !== null && $existing !== '') { continue; }
                if (in_array($slot, ['headerBlock', 'bodyWrapBlock', 'mergeRelations'], true)) {
                    if (!is_array($proposed) || $proposed === []) {
                        continue;
                    }
                    $entry[$slot] = $proposed;
                } else {
                    $entry[$slot] = (string) $proposed;
                }
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
     * Resolve an executable flat Craft field handle from either an explicit
     * targetHandle or the graph-aware targetRef emitted by analyze.
     *
     * Rows such as `targetRef: craft.field:newsPage.image` are already a real
     * operator decision; leaving `targetHandle` blank made them look accepted
     * in the review surface while compile silently skipped them.
     *
     * @param array<string, mixed> $row
     */
    private function targetHandleForCompiledRow(array $row, string $entryType): string
    {
        $targetHandle = trim((string) ($row['targetHandle'] ?? ''));
        if ($targetHandle !== '') {
            return $targetHandle;
        }

        if ($this->isNativePostDateSource($row)) {
            return 'postDate';
        }

        $intent = (string) ($row['relationIntent'] ?? '');
        if (in_array($intent, ['embed', 'promote'], true)) {
            return '';
        }

        $targetRef = (string) ($row['targetRef'] ?? '');
        $prefix = 'craft.field:';
        if (!str_starts_with($targetRef, $prefix)) {
            return '';
        }

        $tail = substr($targetRef, strlen($prefix));
        $parts = explode('.', $tail, 2);
        if (count($parts) !== 2) {
            return '';
        }

        [$refEntryType, $refHandle] = $parts;
        if ($refEntryType !== $entryType || $refHandle === '') {
            return '';
        }

        return $refHandle;
    }

    /**
     * Infer the narrowest safe handler for graph-derived field rows.
     *
     * @param array<string, mixed> $row
     */
    private function handlerForCompiledRow(array $row, string $targetHandle): string
    {
        $handler = trim((string) ($row['handler'] ?? ''));
        if ($handler !== '') {
            return $handler;
        }

        $sourceRef = strtolower((string) ($row['sourceRef'] ?? ''));
        $column = strtolower((string) ($row['column'] ?? ''));
        $target = strtolower($targetHandle);

        if (
            $target === 'postdate'
            || $target === 'expirydate'
            || str_contains((string) ($row['sqlType'] ?? ''), 'date')
            || str_contains((string) ($row['sqlType'] ?? ''), 'time')
        ) {
            return 'date';
        }

        if (
            str_contains($sourceRef, 'media')
            || str_ends_with($column, 'image_id')
            || str_contains($target, 'image')
        ) {
            return 'asset';
        }

        if (
            str_ends_with($column, '_id')
            && in_array((string) ($row['relationIntent'] ?? ''), ['reference', 'promote'], true)
        ) {
            return 'relation';
        }

        return 'plain';
    }

    /**
     * If a Craft entry type exposes a top-level contactCta Matrix and the
     * legacy page already maps an employee/team relation, mirror that relation
     * into contactCta.teamMember. This is a presentation-specific native Craft
     * structure, not a second legacy source decision, so it is derived during
     * compile instead of requiring operators to hand-edit mapping.yaml.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param list<string>|null $allowedHandles
     */
    private function maybeAddContactCtaTeamMemberField(array &$fields, string $entryType, ?array $allowedHandles): void
    {
        unset($entryType);
        if ($allowedHandles !== null && !in_array('contactCta', $allowedHandles, true)) {
            return;
        }
        if (isset($fields['contactCta.teamMember'])) {
            return;
        }

        foreach ($fields as $handle => $spec) {
            if (!is_array($spec) || (string) ($spec['handler'] ?? '') !== 'relation') {
                continue;
            }
            $source = strtolower((string) ($spec['source'] ?? ''));
            $stateSource = strtolower((string) (($spec['handlerOptions'] ?? [])['stateSource'] ?? ''));
            $handleLower = strtolower((string) $handle);
            $looksLikeTeamMember =
                str_contains($handleLower, 'teammember')
                || str_contains($handleLower, 'employee')
                || str_contains($source, 'employee')
                || str_contains($stateSource, 'employee');
            if (!$looksLikeTeamMember) {
                continue;
            }

            $fields['contactCta.teamMember'] = $spec;
            return;
        }
    }

    /**
     * Graceful fallback pages should not become title-only shells. When a page
     * type falls back to the configured catch-all entry type and already has a
     * bodyWrapBlock, infer the most likely source body column and Matrix handle
     * from the reviewed proposals/Craft field layout.
     *
     * @param array<string, array<string, mixed>> $nodeClasses
     * @param list<array<string, mixed>> $proposals
     * @param array<string, list<string>> $matrixFieldCatalog
     * @param array<string, list<string>> $entryTypeFlatHandles
     */
    private function applyFallbackBodyContent(
        array &$nodeClasses,
        array $proposals,
        ?string $defaultEntryType,
        ?string $defaultBlockType,
        array $matrixFieldCatalog,
        array $entryTypeFlatHandles,
    ): void {
        if ($defaultEntryType === null || $defaultEntryType === '') {
            return;
        }

        foreach ($nodeClasses as &$nodeClass) {
            if ((string) ($nodeClass['section'] ?? '') !== $defaultEntryType) {
                continue;
            }
            if ((string) ($nodeClass['bodyColumn'] ?? '') !== '') {
                continue;
            }

            $sourceTable = (string) ($nodeClass['sourceTable'] ?? '');
            if ($sourceTable === '') {
                continue;
            }
            $bodyColumn = $this->fallbackBodyColumnForTable($proposals, $sourceTable);
            if ($bodyColumn === null) {
                continue;
            }

            $allowedHandles = $entryTypeFlatHandles[$defaultEntryType] ?? [];
            $pageBuilderHandle = (string) ($nodeClass['pageBuilderHandle'] ?? '');
            $bodyWrap = $nodeClass['bodyWrapBlock'] ?? null;
            $declaredFieldHandle = (string) ($bodyWrap['fieldHandle'] ?? '');
            if ($pageBuilderHandle === ''
                && $declaredFieldHandle !== ''
                && in_array($declaredFieldHandle, $allowedHandles, true)
                && str_contains(strtolower($declaredFieldHandle), 'pagebuilder')
            ) {
                $pageBuilderHandle = $declaredFieldHandle;
            }
            if ($pageBuilderHandle === '' && in_array('pageBuilder', $allowedHandles, true)) {
                $pageBuilderHandle = 'pageBuilder';
            }
            if ($pageBuilderHandle === '') {
                continue;
            }

            if (!is_array($bodyWrap) || (string) ($bodyWrap['blockType'] ?? '') === '') {
                $blockType = $this->fallbackBodyBlockType(
                    $matrixFieldCatalog,
                    $pageBuilderHandle,
                    $defaultBlockType,
                );
                if ($blockType === null) {
                    continue;
                }
                $bodyWrap = [
                    'blockType' => $blockType,
                    'fieldHandle' => 'ckeditorDefault',
                ];
                $declaredFieldHandle = 'ckeditorDefault';
            }

            if ($declaredFieldHandle === '' || $declaredFieldHandle === $pageBuilderHandle) {
                $bodyWrap['fieldHandle'] = 'ckeditorDefault';
            }

            $nodeClass['pageBuilderHandle'] = $pageBuilderHandle;
            $nodeClass['bodyColumn'] = $bodyColumn;
            $nodeClass['bodyWrapBlock'] = $bodyWrap;
        }
        unset($nodeClass);
    }

    /**
     * @param list<array<string, mixed>> $proposals
     */
    private function fallbackBodyColumnForTable(array $proposals, string $sourceTable): ?string
    {
        $ranked = [];
        $rankByColumn = [
            'content' => 100,
            'body' => 90,
            'intro' => 80,
            'summary' => 70,
            'description' => 60,
            'text' => 50,
        ];

        foreach ($proposals as $index => $row) {
            if (!is_array($row)
                || (string) ($row['kind'] ?? '') !== 'column'
                || (string) ($row['status'] ?? '') !== 'accepted'
                || (string) ($row['table'] ?? '') !== $sourceTable
            ) {
                continue;
            }

            $column = strtolower((string) ($row['column'] ?? ''));
            if ($column === '' || !isset($rankByColumn[$column])) {
                continue;
            }

            $fillRate = (float) ($row['fillRate'] ?? 1.0);
            if ($fillRate <= 0.0) {
                continue;
            }

            $sqlType = strtolower((string) ($row['sqlType'] ?? ''));
            if ($sqlType !== ''
                && !str_contains($sqlType, 'text')
                && !str_contains($sqlType, 'char')
            ) {
                continue;
            }

            $ranked[] = [
                'column' => (string) ($row['column'] ?? ''),
                'score' => $rankByColumn[$column],
                'index' => $index,
            ];
        }

        if ($ranked === []) {
            return null;
        }

        usort(
            $ranked,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']),
        );

        return (string) $ranked[0]['column'];
    }

    /**
     * @param array<string, list<string>> $matrixFieldCatalog
     */
    private function fallbackBodyBlockType(
        array $matrixFieldCatalog,
        string $matrixHandle,
        ?string $defaultBlockType,
    ): ?string {
        $blocks = $matrixFieldCatalog[$matrixHandle] ?? [];
        if ($blocks === []) {
            return null;
        }

        foreach (array_filter([(string) $defaultBlockType, 'generalContentBlock', 'textContentBlock']) as $candidate) {
            if (in_array($candidate, $blocks, true)) {
                return $candidate;
            }
        }

        return (string) $blocks[0];
    }

    /**
     * Body wrapper block titles are visible editorial content. Keep only
     * source-backed templates such as "{title}"; drop literal labels invented
     * by proposals or previous fallback inference.
     *
     * @param array<string, array<string, mixed>> $nodeClasses
     */
    private function removeFabricatedBodyWrapTitles(array &$nodeClasses): void
    {
        foreach ($nodeClasses as &$nodeClass) {
            if (!isset($nodeClass['bodyWrapBlock']) || !is_array($nodeClass['bodyWrapBlock'])) {
                continue;
            }

            $title = (string) ($nodeClass['bodyWrapBlock']['title'] ?? '');
            if ($title === '' || !preg_match('/\{[a-zA-Z0-9_]+\}/', $title)) {
                unset($nodeClass['bodyWrapBlock']['title']);
            }
        }
        unset($nodeClass);
    }

    /**
     * Recognize legacy editorial publish-date columns that should hydrate
     * Craft's native Entry::$postDate instead of requiring a custom field.
     *
     * @param array<string, mixed> $row
     */
    private function isNativePostDateSource(array $row): bool
    {
        $column = strtolower((string) ($row['column'] ?? ''));
        $sqlType = strtolower((string) ($row['sqlType'] ?? ''));
        if (!str_contains($sqlType, 'date') && !str_contains($sqlType, 'time')) {
            return false;
        }

        return in_array($column, ['date', 'postdate', 'post_date', 'post_at', 'published_at', 'publish_date'], true);
    }

    private function isNativeEntryProperty(string $handle): bool
    {
        return in_array($handle, ['postDate', 'expiryDate', 'enabled', 'authorId', 'parentId'], true);
    }

    /**
     * Preserve operator-curated runtime config on nodeClasses during
     * compile --overwrite. Proposals own regenerated field decisions, but
     * hand-authored runtime wiring such as joins, mergeRelations, and header
     * blocks is part of the executable migration contract.
     *
     * @param array<string, mixed> $nodeClass
     * @param array<string, mixed> $existing
     */
    private function mergeOperatorNodeClassOverrides(array &$nodeClass, array $existing): void
    {
        if ($existing === []) {
            return;
        }

        if (isset($existing['fields']) && is_array($existing['fields']) && $existing['fields'] !== []) {
            $fields = $existing['fields'];
            foreach ($fields as $handle => $fieldSpec) {
                $handleString = (string) $handle;
                if (
                    $handleString === 'seo'
                    && is_array($fieldSpec)
                    && (string) ($fieldSpec['handler'] ?? '') !== 'seomatic'
                ) {
                    unset($fields[$handle]);
                    continue;
                }

                if ($this->isRuntimeMatrixContainerField($handleString, $nodeClass, $existing)) {
                    unset($fields[$handle]);
                }
            }
            $nodeClass['fields'] = array_replace((array) ($nodeClass['fields'] ?? []), $fields);
            ksort($nodeClass['fields']);
        }

        foreach (['pageBuilderHandle', 'bodyColumn', 'flatPagePartContent'] as $key) {
            if (isset($existing[$key]) && is_string($existing[$key]) && $existing[$key] !== '') {
                $nodeClass[$key] = $existing[$key];
            }
        }

        if (isset($existing['pageBuilderContexts']) && is_array($existing['pageBuilderContexts'])) {
            $contexts = array_values(array_filter(
                array_map('strval', array_merge(
                    (array) ($nodeClass['pageBuilderContexts'] ?? []),
                    $existing['pageBuilderContexts'],
                )),
                static fn(string $context): bool => $context !== '',
            ));
            $nodeClass['pageBuilderContexts'] = array_values(array_unique($contexts));
        }

        foreach (['headerBlock', 'bodyWrapBlock', 'joins', 'mergeRelations'] as $key) {
            if (isset($existing[$key]) && is_array($existing[$key]) && $existing[$key] !== []) {
                $nodeClass[$key] = $existing[$key];
            }
        }
    }

    /**
     * @param array<string, mixed> $nodeClass
     * @param array<string, mixed> $existing
     */
    private function isRuntimeMatrixContainerField(string $handle, array $nodeClass, array $existing): bool
    {
        if ($handle === '') {
            return false;
        }

        $headerBlock = $nodeClass['headerBlock'] ?? $existing['headerBlock'] ?? null;
        if (is_array($headerBlock) && (string) ($headerBlock['fieldHandle'] ?? '') === $handle) {
            return true;
        }

        foreach ([$nodeClass['pageBuilderHandle'] ?? null, $existing['pageBuilderHandle'] ?? null] as $pageBuilderHandle) {
            if (is_string($pageBuilderHandle) && $pageBuilderHandle === $handle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $proposals
     * @param array<string, mixed> $existing
     * @return array{0: array<string, array<string, mixed>>, 1: int, 2: list<string>}
     */
    private function compilePromotedTargets(array $proposals, array $existing): array
    {
        $out = [];
        foreach ($existing as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $out[$key] = $value;
            }
        }

        $emitted = 0;
        $warnings = [];
        foreach ($proposals as $row) {
            if (!is_array($row)) { continue; }
            $kind = (string) ($row['kind'] ?? '');
            if (!in_array($kind, ['promotedTarget', 'promotedRelationTarget'], true)) { continue; }
            if (((string) ($row['status'] ?? '')) !== 'accepted') { continue; }

            $stateSource = (string) ($row['stateSource'] ?? '');
            $sourceRef = (string) ($row['sourceRef'] ?? '');
            $targetRef = (string) ($row['targetRef'] ?? '');
            $targetSection = (string) ($row['targetSection'] ?? '');
            $targetEntryType = (string) ($row['targetEntryType'] ?? '');
            $relationIntent = (string) ($row['relationIntent'] ?? '');
            $key = $stateSource !== '' ? $stateSource : $sourceRef;

            $missing = [];
            foreach ([
                'stateSource' => $stateSource,
                'sourceRef' => $sourceRef,
                'targetRef' => $targetRef,
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
                'relationIntent' => $relationIntent,
            ] as $field => $value) {
                if ($value === '') {
                    $missing[] = $field;
                }
            }
            if ($missing !== []) {
                $warnings[] = sprintf(
                    'promoted relation target %s skipped: missing %s',
                    $sourceRef !== '' ? $sourceRef : '?',
                    implode(', ', $missing),
                );
                continue;
            }
            if (isset($out[$key])) {
                continue;
            }

            $out[$key] = [
                'stateSource' => $stateSource,
                'sourceRef' => $sourceRef,
                'targetRef' => $targetRef,
                'targetSection' => $targetSection,
                'targetEntryType' => $targetEntryType,
                'relationIntent' => $relationIntent,
                'fields' => is_array($row['fields'] ?? null) ? $row['fields'] : [],
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
    private function relationOptionsForFkColumn(string $owningFqcn, string $sourceCol, array $pageStructure = []): ?array
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
            $pageWrapper = $this->pageWrapperRelationOptions($targetFqcn, $sourceCol, $pageStructure);
            if ($pageWrapper !== null) {
                return $pageWrapper;
            }
            $stateSource = str_replace('\\', '_', $targetFqcn);
            return ['stateSource' => $stateSource];
        }
        return null;
    }

    /**
     * Derive relation handler options for accepted page-owned relation rows.
     *
     * Supports two deterministic metadata shapes emitted by analysis/curation:
     * - ManyToMany: relation.joinTable + joinLocalColumn + joinForeignColumn.
     * - OneToMany: relation.targetTable + backRefColumn; foreign ids are the
     *   child table's primary `id` values.
     *
     * Falls back to the existing ManyToOne FK helper for direct FK columns.
     *
     * @param array<string, mixed> $row
     * @return array{options: ?array<string, mixed>, source: ?string, warning: ?string}
     */
    private function relationOptionsForCompiledRow(string $owningFqcn, array $row, string $sourceCol, array $pageStructure = []): array
    {
        $relation = $row['relation'] ?? null;
        if (is_array($relation) && $relation !== []) {
            $type = (string) ($relation['relationType'] ?? $relation['type'] ?? '');
            $property = (string) ($relation['relationProperty'] ?? $relation['property'] ?? '?');
            $targetFqcn = (string) ($relation['targetFqcn'] ?? $relation['targetEntity'] ?? '');
            $targetTable = (string) ($relation['targetTable'] ?? '');
            $stateSource = $this->stateSourceForTargetFqcn($targetFqcn);

            if ($type === 'ManyToOne') {
                $pageWrapper = $this->pageWrapperRelationOptions($targetFqcn, $sourceCol, $pageStructure);
                if ($pageWrapper !== null) {
                    return [
                        'options' => $pageWrapper,
                        'source' => null,
                        'warning' => null,
                    ];
                }
            }

            if ($type === 'ManyToMany') {
                $joinTable = (string) ($relation['joinTable'] ?? '');
                $joinLocalColumn = (string) ($relation['joinLocalColumn'] ?? $relation['backRefColumn'] ?? '');
                $joinForeignColumn = (string) ($relation['joinForeignColumn'] ?? '');
                $missing = [];
                foreach ([
                    'targetFqcn' => $targetFqcn,
                    'joinTable' => $joinTable,
                    'joinLocalColumn' => $joinLocalColumn,
                    'joinForeignColumn' => $joinForeignColumn,
                ] as $key => $value) {
                    if ($value === '') {
                        $missing[] = $key;
                    }
                }
                if ($missing !== []) {
                    return [
                        'options' => null,
                        'source' => null,
                        'warning' => sprintf(
                            'unsupported relation %s::%s (%s → %s table=%s) for targetHandle=%s: missing %s',
                            $owningFqcn,
                            $property,
                            $type,
                            $targetFqcn !== '' ? $targetFqcn : '∅',
                            $targetTable !== '' ? $targetTable : '∅',
                            (string) ($row['targetHandle'] ?? '?'),
                            implode(', ', $missing),
                        ),
                    ];
                }
                return [
                    'options' => [
                        'stateSource' => $stateSource,
                        'joinTable' => $joinTable,
                        'joinLocalColumn' => $joinLocalColumn,
                        'joinForeignColumn' => $joinForeignColumn,
                    ],
                    'source' => 'id',
                    'warning' => null,
                ];
            }

            if ($type === 'OneToMany') {
                $backRefColumn = (string) ($relation['backRefColumn'] ?? $relation['joinLocalColumn'] ?? '');
                $missing = [];
                foreach ([
                    'targetFqcn' => $targetFqcn,
                    'targetTable' => $targetTable,
                    'backRefColumn' => $backRefColumn,
                ] as $key => $value) {
                    if ($value === '') {
                        $missing[] = $key;
                    }
                }
                if ($missing !== []) {
                    return [
                        'options' => null,
                        'source' => null,
                        'warning' => sprintf(
                            'unsupported relation %s::%s (%s → %s table=%s) for targetHandle=%s: missing %s',
                            $owningFqcn,
                            $property,
                            $type,
                            $targetFqcn !== '' ? $targetFqcn : '∅',
                            $targetTable !== '' ? $targetTable : '∅',
                            (string) ($row['targetHandle'] ?? '?'),
                            implode(', ', $missing),
                        ),
                    ];
                }
                return [
                    'options' => [
                        'stateSource' => $stateSource,
                        'joinTable' => $targetTable,
                        'joinLocalColumn' => $backRefColumn,
                        'joinForeignColumn' => (string) ($relation['joinForeignColumn'] ?? 'id'),
                    ],
                    'source' => 'id',
                    'warning' => null,
                ];
            }
        }

        $opts = $this->relationOptionsForFkColumn($owningFqcn, $sourceCol, $pageStructure);
        return ['options' => $opts, 'source' => null, 'warning' => null];
    }

    /**
     * Some Kunstmaan sites model a visible target as Page + related entity
     * (EmployeePage.employee_id -> Employee), while Craft stores the result as
     * one entry. A foreign key from another page to Employee must therefore
     * resolve through the wrapper page's state rows, not the raw entity.
     *
     * @param array<string, mixed> $pageStructure
     * @return array<string, mixed>|null
     */
    private function pageWrapperRelationOptions(string $targetFqcn, string $sourceCol, array $pageStructure): ?array
    {
        $targetBase = $this->classBaseName($targetFqcn);
        if ($targetBase === '' || $sourceCol === '') {
            return null;
        }

        foreach ($pageStructure as $pageFqcn => $info) {
            if (!is_string($pageFqcn) || !is_array($info)) {
                continue;
            }
            $pageBase = $this->classBaseName($pageFqcn);
            if ($pageBase !== $targetBase . 'Page') {
                continue;
            }
            $table = (string) ($info['tableName'] ?? '');
            if ($table === '') {
                continue;
            }
            return [
                'stateSource' => $this->stateSourceForTargetFqcn($pageFqcn),
                'joinTranslation' => [
                    'table' => $table,
                    'sourceColumn' => $sourceCol,
                    'targetColumn' => 'id',
                ],
            ];
        }

        return null;
    }

    /**
     * Deterministic graph-derived relation routing wins over LLM-supplied
     * guesses for the same option keys, while preserving any extra curated
     * options that do not conflict.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $derived
     * @return array<string, mixed>
     */
    private function mergeRelationHandlerOptions(array $existing, array $derived): array
    {
        return array_replace_recursive($existing, $derived);
    }

    private function classBaseName(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        return (string) end($parts);
    }

    private function stateSourceForTargetFqcn(string $targetFqcn): string
    {
        return str_replace('\\', '_', trim($targetFqcn, '\\'));
    }

    /**
     * Validate handler/source/target combinations that are known to produce
     * silent empty runtime output.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $compiled
     */
    private function validateCompiledFieldSpec(string $fqcn, array $row, array $compiled): ?string
    {
        $handler = (string) ($compiled['handler'] ?? '');
        $source = (string) ($compiled['source'] ?? '');
        $targetHandle = (string) ($row['targetHandle'] ?? '?');
        $sqlType = strtolower((string) ($row['sqlType'] ?? $row['sourceType'] ?? ''));

        if ($handler === 'matrix') {
            $hasMatrixOptions = isset($compiled['handlerOptions']) && is_array($compiled['handlerOptions']) && $compiled['handlerOptions'] !== [];
            $looksScalar = $sqlType === ''
                || str_contains($sqlType, 'char')
                || str_contains($sqlType, 'text')
                || str_contains($sqlType, 'int')
                || str_contains($sqlType, 'date');
            if ($looksScalar && !$hasMatrixOptions) {
                return $this->fieldWarning($fqcn, $source, $targetHandle, $handler, 'matrix handler cannot materialize scalar source without handlerOptions/block metadata');
            }
        }

        if ($handler === 'dropdown') {
            $options = $row['options'] ?? $row['allowedValues'] ?? $row['enumValues'] ?? $compiled['handlerOptions']['options'] ?? null;
            if (!is_array($options) || $options === []) {
                return $this->fieldWarning($fqcn, $source, $targetHandle, $handler, 'dropdown handler requires target options metadata before arbitrary text can be accepted');
            }
        }

        if ($handler === 'relation') {
            $opts = isset($compiled['handlerOptions']) && is_array($compiled['handlerOptions'])
                ? $compiled['handlerOptions']
                : [];
            $hasDirect = isset($opts['stateSource']) && (string) $opts['stateSource'] !== '';
            $hasJoin = isset($opts['joinTable'])
                && (string) $opts['joinTable'] !== ''
                && isset($opts['joinLocalColumn'])
                && (string) $opts['joinLocalColumn'] !== ''
                && isset($opts['joinForeignColumn'])
                && (string) $opts['joinForeignColumn'] !== '';
            if (!$hasDirect || (isset($opts['joinTable']) && !$hasJoin)) {
                return $this->fieldWarning($fqcn, $source, $targetHandle, $handler, 'relation handler requires stateSource and complete joinTable options or a derivable ManyToOne FK');
            }
        }

        return null;
    }

    private function fieldWarning(string $fqcn, string $source, string $targetHandle, string $handler, string $reason): string
    {
        return sprintf(
            '%s: source=%s targetHandle=%s handler=%s skipped — %s',
            $fqcn,
            $source !== '' ? $source : '∅',
            $targetHandle !== '' ? $targetHandle : '∅',
            $handler !== '' ? $handler : '∅',
            $reason,
        );
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
