<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\audit\PageRootedCoverageAuditor;
use lameco\kunstmaanmigrator\audit\PageRootedSurfaceDiscovery;
use lameco\kunstmaanmigrator\compile\GraphCompatibilityValidator;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;
use Throwable;
use yii\console\ExitCode;

/**
 * Compile — derive the v1-shaped runtime ETL contract (`nodeClasses` /
 * `sections` / `sites`) from accepted column proposals + pageStructure.json
 * + Settings::localeMap. Writes the augmented mapping.yaml back via
 * MappingFile::writeAtomic.
 *
 * Why this exists: see MappingCompiler's docblock. The short version is that
 * v2's flat `proposals[]` audit trail and the ETL's nested `nodeClasses` /
 * `sections` / `sites` shape are two different contracts; without a step
 * that bridges them, `migrate` reads `[]` for nodeClasses and extracts zero
 * rows.
 *
 * Default behavior (no flags): refuses to overwrite existing `nodeClasses` /
 * `sections` / `sites` blocks — operators who hand-curate those structures
 * see a clear error rather than silent data loss. Pass `--overwrite` to
 * regenerate.
 *
 * Per-FQCN spot-update is intentionally NOT supported in v1.0 — the shape
 * is small enough to regenerate wholesale, and partial updates would
 * complicate idempotency + diff review.
 */
class CompileController extends Controller
{
    use NeverProductionTrait;

    public bool $overwrite = false;
    public bool $dryRun    = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'overwrite', 'dryRun',
        ]);
    }

    /**
     * `compile` (default) — derive nodeClasses + sections + sites and write
     * them back into mapping.yaml above the existing `proposals:` block.
     */
    public function actionIndex(): int
    {
        // D-20: NeverProduction guard FIRST.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Compile: proposals + pageStructure → nodeClasses + sections + sites\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $mappingPath = $plugin->mappingFile->resolvePath();

        // 1. Load mapping.yaml.
        try {
            $mapping = $plugin->mappingFile->load($mappingPath);
        } catch (Throwable $e) {
            $this->stderr("  FAIL load mapping.yaml: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $proposalCount = count((array) ($mapping['proposals'] ?? []));
        if ($proposalCount === 0) {
            $this->stderr("  FAIL mapping.yaml has no proposals — run `analyze` first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   mapping.yaml loaded ({$proposalCount} proposal rows)\n", Console::FG_GREEN);

        // 2. Refuse-to-overwrite guard.
        $hasExisting = isset($mapping['nodeClasses']) || isset($mapping['sections']) || isset($mapping['sites']);
        if ($hasExisting && !$this->overwrite) {
            $this->stderr(
                "  FAIL mapping.yaml already has nodeClasses / sections / sites blocks. "
                . "Pass --overwrite to regenerate (existing blocks will be replaced).\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }

        // 3. Load pageStructure.json (written by analyze).
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $pageStructurePath = $storageDir . '/pageStructure.json';
        if (!is_file($pageStructurePath)) {
            $this->stderr(
                "  FAIL pageStructure.json missing at {$pageStructurePath} — run `analyze` first.\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $raw = (string) file_get_contents($pageStructurePath);
        $pageStructure = json_decode($raw, true);
        if (!is_array($pageStructure)) {
            $this->stderr("  FAIL pageStructure.json is not a JSON object.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $this->stdout(
            "  OK   pageStructure.json loaded (" . count($pageStructure) . " page entities)\n",
            Console::FG_GREEN,
        );

        // 3.5. Graph compatibility gate. Phase 11 makes these canonical
        // artifacts versioned graphs; stale/missing versions fail before an
        // executable mapping can be written.
        $kunstmaanGraph = $this->loadJsonObject($storageDir . '/kunstmaan-schema.json');
        $craftGraph = $this->loadJsonObject($storageDir . '/craft-schema.json');
        $graphCompatibilityRows = (new GraphCompatibilityValidator())->validate($mapping, $kunstmaanGraph, $craftGraph);
        $graphFatalRows = array_values(array_filter(
            $graphCompatibilityRows,
            static fn(array $row): bool => (string) ($row['severity'] ?? '') === 'fatal',
        ));
        foreach ($this->summarizeGraphCompatibilityRows($graphCompatibilityRows) as $row) {
            $line = sprintf(
                '%s [%s] %s%s%s',
                strtoupper((string) ($row['severity'] ?? 'warning')),
                (string) ($row['code'] ?? 'graph_compatibility'),
                (string) ($row['message'] ?? ''),
                ((string) ($row['sourceRef'] ?? '')) !== '' ? ' source=' . (string) $row['sourceRef'] : '',
                ((string) ($row['targetRef'] ?? '')) !== '' ? ' target=' . (string) $row['targetRef'] : '',
            );
            if ((string) ($row['severity'] ?? '') === 'fatal') {
                $this->stderr("  FAIL graph validation: {$line}\n", Console::FG_RED);
            } else {
                $this->stdout("  WARN graph validation: {$line}\n", Console::FG_YELLOW);
            }
        }
        if ($graphFatalRows !== []) {
            return ExitCode::CONFIG;
        }

        // 4. Resolve sites map. Precedence (highest first):
        //    a. existing mapping.yaml `sites:` block (operator-curated)
        //    b. Settings::localeMap (host config)
        //    c. auto-derived from kunstmaan-schema.json legacy locales × Craft sites
        //       (language-code match: legacy locale 'nl' → site whose language
        //       starts with 'nl-' or equals 'nl').
        $sites = (array) ($mapping['sites'] ?? []);
        $sitesSource = 'mapping.yaml sites: block';
        if ($sites === []) {
            $sites = (array) ($plugin->getSettings()->localeMap ?? []);
            $sitesSource = 'Settings::localeMap';
        }
        if ($sites === []) {
            $sites = $this->autoDeriveSitesFromLegacyLocales($storageDir);
            $sitesSource = 'auto-derived (kunstmaan-schema locales × Craft sites by language code)';
        }
        $this->stdout(sprintf(
            "  OK   sites map resolved: %d entries (source: %s)\n",
            count($sites),
            $sitesSource,
        ), Console::FG_GREEN);

        // 5. Compile. Pass Settings::defaultEntryType / defaultBlockType so the
        //    compiler can apply graceful fallback for FQCNs / page-parts the AI
        //    could not confidently map (Phase 6, opt-in by the operator). Pass
        //    the Craft entry-type catalog so the compiler can validate basename-
        //    derived handles and route invalid ones to the fallback.
        $settings = $plugin->getSettings();
        $compiled = $plugin->mappingCompiler->compile(
            $mapping,
            $pageStructure,
            $sites,
            $settings->defaultEntryType ?: null,
            $settings->defaultBlockType ?: null,
            $plugin->craftKnowledgeBase->entryTypeHandles(),
            // Phase 8.3 / D-16 — pass the Matrix-field catalog so compile can
            // auto-resolve targetMatrixField from targetBlockType for
            // pagePart rows where the LLM populated only the block type.
            $plugin->craftKnowledgeBase->matrixFieldCatalog(),
            // Phase 8.7 / D-39 — auto-detect map of entry-types whose field
            // layout has no Matrix and has a flat ckeditor field. compile
            // emits `nodeClasses[fqcn].flatPagePartContent = <handle>` so
            // TransformService's D-38 flat-fold routes vendor page-part
            // content (TextPagePart, etc.) into the right ckeditor field
            // automatically — no per-project hand-curation needed.
            $plugin->craftKnowledgeBase->flatPagePartCandidates(),
            // Phase 8.7 / D-40 — pass entry-type's flat-handle catalog so
            // compile can drop+warn on column proposals whose targetHandle
            // doesn't exist on the chosen entry-type (silent-empty
            // prevention).
            $plugin->craftKnowledgeBase->entryTypeFlatHandles(),
            // Portable fallback content resolver: per Matrix field, the best
            // generic rich-text block/sub-field discovered from Craft's live
            // schema. Avoids CQM-only assumptions like `ckeditorDefault`.
            $plugin->craftKnowledgeBase->genericContentBlockCandidates(
                (array) ($settings->genericContentBlockOverrides ?? []),
            ),
            (array) ($settings->relationMirrorRules ?? []),
        );
        $report = $compiled['_compileReport'];

        if ((int) ($report['autoAssignedTargets'] ?? 0) > 0) {
            $this->stdout(sprintf(
                "  OK   auto-assigned targetEntryType on %d previously-empty proposal rows (basename heuristic)\n",
                (int) $report['autoAssignedTargets'],
            ), Console::FG_GREEN);
        }
        $fallbackApplied = (array) ($report['fallbackEntryTypeApplied'] ?? []);
        if ($fallbackApplied !== []) {
            $fallbackTo = (string) ($report['fallbackEntryTypeUsed'] ?? '?');
            $this->stdout(sprintf(
                "  OK   fallback applied: %d FQCNs routed to Settings::defaultEntryType=%s\n",
                count($fallbackApplied),
                $fallbackTo,
            ), Console::FG_GREEN);
            foreach ($fallbackApplied as $fqcn) {
                $this->stdout("        - {$fqcn}\n", Console::FG_GREY);
            }
        }
        $fallbackPagePartsApplied = (array) ($report['fallbackBlockTypeApplied'] ?? []);
        if ($fallbackPagePartsApplied !== []) {
            $blockFallbackTo = (string) ($report['fallbackBlockTypeUsed'] ?? '?');
            $this->stdout(sprintf(
                "  OK   page-part fallback applied: %d page parts routed to Settings::defaultBlockType=%s (status flipped to accepted)\n",
                count($fallbackPagePartsApplied),
                $blockFallbackTo,
            ), Console::FG_GREEN);
        }
        // Phase 8.3 / D-16 — surface the targetMatrixField auto-resolution count.
        $autoFilledMatrixField = (array) ($report['autoFilledMatrixField'] ?? []);
        if ($autoFilledMatrixField !== []) {
            $this->stdout(sprintf(
                "  OK   page-part Matrix-field auto-resolved: %d page parts (block-type uniquely owned by one Matrix field)\n",
                count($autoFilledMatrixField),
            ), Console::FG_GREEN);
        }
        $pbHandlePropagated = (array) ($report['pageBuilderHandlePropagated'] ?? []);
        if ($pbHandlePropagated !== []) {
            $this->stdout(sprintf(
                "  OK   pageBuilderHandle propagated to %d nodeClasses from accepted page-part rows\n",
                count($pbHandlePropagated),
            ), Console::FG_GREEN);
        }
        $pagePartsRegularEmitted = (int) ($report['pagePartsRegularEmitted'] ?? 0);
        if ($pagePartsRegularEmitted > 0) {
            $this->stdout(sprintf(
                "  OK   compiled %d regular page-part(s) into mapping.pageParts\n",
                $pagePartsRegularEmitted,
            ), Console::FG_GREEN);
        }
        if ($fallbackApplied === [] && $report['fallbackEntryTypeUsed'] === null) {
            // Operator hasn't opted in to graceful fallback. If we skipped any
            // FQCNs for "no targetEntryType", nudge them toward the setting.
            $skippedNoTarget = array_filter(
                (array) ($report['skippedNodeClasses'] ?? []),
                static fn(string $s): bool => str_contains($s, 'no targetEntryType assigned'),
            );
            if ($skippedNoTarget !== []) {
                $this->stdout(sprintf(
                    "  INFO %d FQCN(s) skipped for missing targetEntryType. Set Settings::defaultEntryType (e.g. 'contentPage') to route them to a generic catch-all.\n",
                    count($skippedNoTarget),
                ), Console::FG_GREY);
            }
        }
        $this->stdout(sprintf(
            "  OK   compile produced %d nodeClasses + %d sections + %d sites\n",
            (int) $report['nodeClassesEmitted'],
            (int) $report['sectionsEmitted'],
            count($compiled['sites']),
        ), Console::FG_GREEN);
        $implicitEmitted = (int) ($report['implicitBlocksEmitted'] ?? 0);
        if ($implicitEmitted > 0) {
            $this->stdout(sprintf(
                "  OK   compiled %d implicit-content page-part block(s) into mapping.pageParts + nodeClasses.pageBuilderHandle\n",
                $implicitEmitted,
            ), Console::FG_GREEN);
        }
        // Phase 8 / Plan 09 — surface the three new compile counters.
        // Silent when 0 to mirror implicitBlocksEmitted convention.
        $taxonomiesEmitted = (int) ($report['taxonomiesEmitted'] ?? 0);
        if ($taxonomiesEmitted > 0) {
            $this->stdout(sprintf(
                "  OK   compiled %d taxonomy block(s) into mapping.taxonomies\n",
                $taxonomiesEmitted,
            ), Console::FG_GREEN);
        }
        $layoutBlocksEmitted = (int) ($report['layoutBlocksEmitted'] ?? 0);
        if ($layoutBlocksEmitted > 0) {
            $this->stdout(sprintf(
                "  OK   compiled %d page-builder layout block(s) into mapping.nodeClasses\n",
                $layoutBlocksEmitted,
            ), Console::FG_GREEN);
        }
        $dataProvidersEmitted = (int) ($report['dataProvidersEmitted'] ?? 0);
        if ($dataProvidersEmitted > 0) {
            $this->stdout(sprintf(
                "  OK   compiled %d dataProvider block(s) into mapping.dataProviders\n",
                $dataProvidersEmitted,
            ), Console::FG_GREEN);
        }
        $promotedTargetsEmitted = (int) ($report['promotedTargetsEmitted'] ?? 0);
        if ($promotedTargetsEmitted > 0) {
            $this->stdout(sprintf(
                "  OK   compiled %d promoted relation target(s) into mapping.promotedTargets\n",
                $promotedTargetsEmitted,
            ), Console::FG_GREEN);
        }

        // Validate compiled section handles against Craft's actual entry-type
        // catalog. Compiler derives candidate handles from FQCN basenames
        // (NewsPage → newsPage), but Craft's project-config typically uses
        // shorter / domain-specific names (newsPages, casePage, etc.) that
        // can't be auto-derived. Surface mismatches NOW so operators don't
        // discover them at first --live failure.
        $craftHandles = $this->craftEntryTypeHandles();
        $missing = [];
        $matched = [];
        foreach ($compiled['nodeClasses'] as $fqcn => $spec) {
            $section = (string) ($spec['section'] ?? '');
            if ($section === '') {
                continue;
            }
            if (in_array($section, $craftHandles, true)) {
                $matched[] = $section;
                continue;
            }
            $missing[$fqcn] = [
                'derived' => $section,
                'suggestions' => $this->suggestCraftHandle($section, $craftHandles),
            ];
        }
        if ($missing !== []) {
            $this->stdout(sprintf(
                "  WARN %d nodeClasses point at entry types Craft does NOT have. Migrate --live will fail per-entry on these.\n",
                count($missing),
            ), Console::FG_YELLOW);
            $this->stdout("        Either rename the section: handle in the compiled mapping.yaml,\n", Console::FG_YELLOW);
            $this->stdout("        or create the missing entry type in Craft. Suggested matches:\n", Console::FG_YELLOW);
            foreach ($missing as $fqcn => $info) {
                $sugg = $info['suggestions'] !== []
                    ? ' → try ' . implode(' / ', $info['suggestions'])
                    : ' → no close match in Craft';
                $this->stdout(sprintf("        - %s : section=%s%s\n", $fqcn, $info['derived'], $sugg), Console::FG_YELLOW);
            }
        } elseif ($matched !== []) {
            $this->stdout(sprintf(
                "  OK   all %d compiled section handles match real Craft entry types\n",
                count(array_unique($matched)),
            ), Console::FG_GREEN);
        }

        if (!empty($report['skippedNodeClasses'])) {
            $this->stdout(
                "  WARN skipped " . count($report['skippedNodeClasses']) . " page entities:\n",
                Console::FG_YELLOW,
            );
            foreach ($report['skippedNodeClasses'] as $line) {
                $this->stdout("        - {$line}\n", Console::FG_YELLOW);
            }
        }
        foreach ($this->summarizeCompileWarnings((array) $report['warnings']) as $w) {
            $this->stdout("  WARN {$w}\n", Console::FG_YELLOW);
        }

        $targetValidation = $plugin->craftTargetIntrospector->validateWithSeverity($compiled, $this->craftTargetSchema($plugin));
        foreach ($targetValidation['fatal'] as $w) {
            $this->stderr("  FAIL target validation: {$w}\n", Console::FG_RED);
        }
        foreach ($targetValidation['warnings'] as $w) {
            $this->stdout("  WARN target validation: {$w}\n", Console::FG_YELLOW);
        }
        if ($targetValidation['fatal'] !== []) {
            return ExitCode::CONFIG;
        }

        // 6. Dry-run early exit.
        if ($this->dryRun) {
            $this->stdout("  WARN dry-run — mapping.yaml NOT written. Drop --dry-run to persist.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        // 7. Serialize + write atomically.
        unset($compiled['_compileReport']); // never persist the report into mapping.yaml
        // Order keys so reads land in the operator-friendly order:
        //   sites → sections → nodeClasses → pageParts → taxonomies → dataProviders → proposals
        //   (small-to-large; structural blocks before the proposals audit trail).
        // pageParts / taxonomies / dataProviders are only written when the compiler
        // produced any (or the operator already curated them) — keeps mapping.yaml
        // free of empty placeholder blocks for Phase ≤ 7 projects.
        $pagePartsOut      = (array) ($compiled['pageParts'] ?? []);
        $taxonomiesOut     = (array) ($compiled['taxonomies'] ?? []);
        $dataProvidersOut  = (array) ($compiled['dataProviders'] ?? []);
        $promotedTargetsOut = (array) ($compiled['promotedTargets'] ?? []);
        $ordered = [
            'sites'       => $compiled['sites'],
            'sections'    => $compiled['sections'],
            'nodeClasses' => $compiled['nodeClasses'],
        ];
        if ($pagePartsOut !== []) {
            $ordered['pageParts'] = $pagePartsOut;
        }
        if ($taxonomiesOut !== []) {
            $ordered['taxonomies'] = $taxonomiesOut;
        }
        if ($dataProvidersOut !== []) {
            $ordered['dataProviders'] = $dataProvidersOut;
        }
        if ($promotedTargetsOut !== []) {
            $ordered['promotedTargets'] = $promotedTargetsOut;
        }
        $ordered['proposals'] = $compiled['proposals'];
        try {
            $yaml = SymfonyYaml::dump($ordered, 8, 2, SymfonyYaml::DUMP_NULL_AS_TILDE);
        } catch (Throwable $e) {
            $this->stderr("  FAIL yaml dump: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $ok = $plugin->mappingFile->writeAtomic($mappingPath, $yaml);
        if (!$ok) {
            $this->stderr("  FAIL writeAtomic to {$mappingPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   mapping.yaml written → {$mappingPath}\n", Console::FG_GREEN);

        // 8. Page-rooted structural coverage artifacts.
        // The audit is intentionally structural-only: discovery consumes compiled
        // mapping/pageStructure/warnings and emits FQCNs, tables, handles, relation
        // shapes, adapter names, ids, and token types — never source samples.
        $coverageMapping = $compiled;
        unset($coverageMapping['_compileReport']);
        $surfaceDiscovery = $plugin->pageRootedSurfaceDiscovery ?? new PageRootedSurfaceDiscovery();
        $coverageAuditor = $plugin->pageRootedCoverageAuditor ?? new PageRootedCoverageAuditor();
        $discoveryRows = $surfaceDiscovery->discover(
            $coverageMapping,
            $pageStructure,
            $this->relationMetadataFromPageStructure($pageStructure),
        );
        $coverageRows = $coverageAuditor->audit(
            $discoveryRows,
            $coverageMapping,
            $pageStructure,
            (array) ($report['warnings'] ?? []),
        );
        $coverageJsonPath = $storageDir . '/page-rooted-coverage.json';
        $coverageMarkdownPath = $storageDir . '/PAGE-ROOTED-COVERAGE.md';
        if (!$plugin->mappingFile->writeAtomicJson($coverageJsonPath, ['rows' => $coverageRows])) {
            $this->stderr("  FAIL writeAtomicJson to {$coverageJsonPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        if (!$plugin->mappingFile->writeAtomic($coverageMarkdownPath, $coverageAuditor->renderMarkdown($coverageRows))) {
            $this->stderr("  FAIL writeAtomic to {$coverageMarkdownPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   Page-rooted coverage written → {$coverageJsonPath}\n", Console::FG_GREEN);
        $this->stdout("  OK   Page-rooted coverage written → {$coverageMarkdownPath}\n", Console::FG_GREEN);

        $this->stdout("\nCompile: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function summarizeGraphCompatibilityRows(array $rows): array
    {
        $out = [];
        $relationIntent = [];

        foreach ($rows as $row) {
            if (
                (string) ($row['severity'] ?? '') !== 'fatal'
                && (string) ($row['code'] ?? '') === 'relation_intent_required'
            ) {
                $source = (string) ($row['sourceRef'] ?? '');
                $target = (string) ($row['targetRef'] ?? '');
                $relationIntent[] = $source . ($target !== '' ? ' -> ' . $target : '');
                continue;
            }

            $out[] = $row;
        }

        if ($relationIntent !== []) {
            $examples = array_slice($relationIntent, 0, 5);
            $suffix = count($relationIntent) > count($examples)
                ? '; examples: ' . implode(', ', $examples) . ', +' . (count($relationIntent) - count($examples)) . ' more'
                : '; examples: ' . implode(', ', $examples);
            $out[] = [
                'severity' => 'warning',
                'code' => 'relation_intent_required',
                'message' => count($relationIntent) . ' graph relation(s) have FK evidence but no explicit intent yet (reference, promote, embed, drop, or out_of_scope)' . $suffix,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function summarizeCompileWarnings(array $warnings): array
    {
        $out = [];
        $pageBuilderGroups = [];
        $deduped = [];

        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                continue;
            }
            if (preg_match(
                '/^(?<fqcn>[^:]+): pageBuilderHandle `(?<matrix>[^`]+)` not propagated for (?<source>.+?) because entry-type `(?<entryType>[^`]+)` does not own that Matrix field(?<tail>.*)$/',
                $warning,
                $m,
            ) === 1) {
                $hasFallback = str_contains($m['tail'], 'flatPagePartContent');
                $key = implode('|', [$m['fqcn'], $m['matrix'], $m['entryType'], $hasFallback ? 'fallback' : 'no-fallback']);
                $pageBuilderGroups[$key]['fqcn'] = $m['fqcn'];
                $pageBuilderGroups[$key]['matrix'] = $m['matrix'];
                $pageBuilderGroups[$key]['entryType'] = $m['entryType'];
                $pageBuilderGroups[$key]['hasFallback'] = $hasFallback;
                $pageBuilderGroups[$key]['sources'][] = $m['source'];
                continue;
            }

            $deduped[$warning] = ($deduped[$warning] ?? 0) + 1;
        }

        foreach ($deduped as $warning => $count) {
            $out[] = $count > 1 ? "{$warning} (repeated {$count}x)" : $warning;
        }

        foreach ($pageBuilderGroups as $group) {
            $sources = array_values(array_unique((array) $group['sources']));
            $examples = array_slice($sources, 0, 3);
            $suffix = count($sources) > count($examples)
                ? ', +' . (count($sources) - count($examples)) . ' more'
                : '';
            $out[] = sprintf(
                '%s: %d page-part mapping(s) not propagated from pageBuilderHandle `%s` because entry-type `%s` does not own that Matrix field%s; examples: %s%s.',
                $group['fqcn'],
                count($sources),
                $group['matrix'],
                $group['entryType'],
                $group['hasFallback']
                    ? '; content is preserved via flatPagePartContent fallback'
                    : ' and no flatPagePartContent fallback is available',
                implode(', ', $examples),
                $suffix,
            );
        }

        return $out;
    }

    /**
     * Normalize optional relation metadata embedded by source scanners into the
     * shape consumed by PageRootedSurfaceDiscovery. Scanners differ by project,
     * so this accepts common keys and otherwise returns an empty map, which the
     * discovery service converts into explicit unsupported relation descriptors.
     *
     * @param array<string, mixed> $pageStructure
     * @return array<string, list<array<string, mixed>>>
     */
    private function relationMetadataFromPageStructure(array $pageStructure): array
    {
        $out = [];
        foreach ($pageStructure as $fqcn => $record) {
            if (!is_string($fqcn) || !is_array($record)) {
                continue;
            }
            $relations = [];
            foreach (['relations', 'relationMetadata', 'doctrineRelations'] as $key) {
                foreach ((array) ($record[$key] ?? []) as $relation) {
                    if (is_array($relation)) {
                        $relations[] = $relation;
                    }
                }
            }
            if ($relations !== []) {
                $out[$fqcn] = $relations;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonObject(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Snapshot Craft's currently-configured entry-type handles. Used by the
     * compile validation step to flag compiled section: handles that don't
     * exist in Craft (which would fail per-entry at migrate --live time).
     *
     * @return list<string>
     */
    private function craftEntryTypeHandles(): array
    {
        $out = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $et) {
            $h = (string) $et->handle;
            if ($h !== '') {
                $out[] = $h;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Build the schema facade consumed by CraftTargetIntrospector.
     *
     * @return array<string, mixed>
     */
    private function craftTargetSchema(Plugin $plugin): array
    {
        $sections = [];
        foreach ($plugin->craftKnowledgeBase->sectionToEntryTypes() as $handle => $entryTypes) {
            $sections[$handle] = ['entryTypes' => $entryTypes];
        }

        $entryTypes = [];
        foreach ($plugin->craftKnowledgeBase->buildFieldIndex() as $entryType => $fields) {
            $fieldMap = [];
            foreach ((array) $fields as $field) {
                if (!is_array($field)) { continue; }
                $handle = (string) ($field['handle'] ?? '');
                if ($handle === '' || str_contains($handle, '.')) { continue; }
                $fieldMap[$handle] = ['type' => strtolower((string) ($field['classification'] ?? $field['type'] ?? 'plain'))];
                if (isset($field['blocks']) && is_array($field['blocks'])) {
                    $fieldMap[$handle]['blocks'] = $field['blocks'];
                }
            }
            $entryTypes[(string) $entryType] = ['fields' => $fieldMap];
        }

        $volumes = [];
        try {
            foreach (Craft::$app->volumes->getAllVolumes() as $volume) {
                $handle = (string) $volume->handle;
                if ($handle !== '') { $volumes[] = $handle; }
            }
        } catch (Throwable) {
            $volumes = [];
        }

        return [
            'sections' => $sections,
            'entryTypes' => $entryTypes,
            'volumes' => array_values(array_unique($volumes)),
            'plugins' => [
                'seomatic' => Craft::$app->plugins->getPlugin('seomatic') !== null,
                'retour' => Craft::$app->plugins->getPlugin('retour') !== null,
            ],
        ];
    }

    /**
     * Suggest up to 3 Craft entry-type handles closest to a derived candidate.
     * Order: case-insensitive exact, then candidate-as-substring-of-craft,
     * then craft-as-substring-of-candidate, then Levenshtein-nearest. Empty
     * list when nothing within plausible edit distance.
     *
     * @param  list<string> $craftHandles
     * @return list<string>
     */
    private function suggestCraftHandle(string $candidate, array $craftHandles): array
    {
        if ($candidate === '' || $craftHandles === []) {
            return [];
        }
        $candidateLc = strtolower($candidate);
        $tier1 = []; // case-insensitive exact
        $tier2 = []; // substring match either direction
        $tier3 = []; // levenshtein-nearest
        foreach ($craftHandles as $h) {
            $hLc = strtolower($h);
            if ($hLc === $candidateLc) {
                $tier1[] = $h;
            } elseif (str_contains($hLc, $candidateLc) || str_contains($candidateLc, $hLc)) {
                $tier2[] = $h;
            } else {
                $dist = levenshtein($candidateLc, $hLc);
                if ($dist <= max(3, (int) (strlen($candidateLc) / 3))) {
                    $tier3[] = [$h, $dist];
                }
            }
        }
        usort($tier3, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $tier3 = array_map(static fn(array $p): string => (string) $p[0], $tier3);
        $merged = array_merge($tier1, $tier2, $tier3);
        return array_slice(array_values(array_unique($merged)), 0, 3);
    }

    /**
     * Derive a candidate locale → siteHandle map from kunstmaan-schema.json's
     * `locales` list cross-referenced with Craft's configured sites by
     * language-code prefix. Returns [] when either side is empty or no
     * languages match — operator must then hand-curate Settings::localeMap
     * or the mapping.yaml sites: block.
     *
     * Match rules:
     *   - exact: legacy locale equals Craft site language (e.g. 'en' === 'en')
     *   - prefix: legacy locale equals first segment of Craft language
     *     (e.g. 'nl' matches 'nl-NL' / 'nl-BE')
     *
     * When multiple Craft sites match a legacy locale, the primary site wins.
     *
     * @return array<string, string>  legacy locale → Craft site handle
     */
    private function autoDeriveSitesFromLegacyLocales(string $storageDir): array
    {
        $schemaPath = $storageDir . '/kunstmaan-schema.json';
        if (!is_file($schemaPath)) {
            $legacySchemaPath = $storageDir . '/schema-dump.json';
            if (!is_file($legacySchemaPath)) {
                return [];
            }
            $schemaPath = $legacySchemaPath;
        }
        $raw = (string) file_get_contents($schemaPath);
        $schema = json_decode($raw, true);
        $legacyLocales = (array) ($schema['locales'] ?? []);
        if ($legacyLocales === []) {
            return [];
        }

        $craftSites = Craft::$app->sites->getAllSites();
        if ($craftSites === []) {
            return [];
        }

        $out = [];
        foreach ($legacyLocales as $legacy) {
            $legacy = (string) $legacy;
            if ($legacy === '') {
                continue;
            }
            $bestHandle = null;
            $bestPrimary = false;
            foreach ($craftSites as $site) {
                $lang = (string) $site->language;
                $matches = ($lang === $legacy)
                    || (strpos($lang, $legacy . '-') === 0);
                if (!$matches) {
                    continue;
                }
                if ($bestHandle === null || (!$bestPrimary && $site->primary)) {
                    $bestHandle = (string) $site->handle;
                    $bestPrimary = (bool) $site->primary;
                }
            }
            if ($bestHandle !== null) {
                $out[$legacy] = $bestHandle;
            }
        }
        return $out;
    }
}
