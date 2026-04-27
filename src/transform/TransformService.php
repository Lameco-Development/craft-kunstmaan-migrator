<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\transform;

use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\db\LegacyDbService;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\filter\MigrationFilters;
use Craft;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Stage 2b — Transform.
 *
 * Reads extracted-row tuples produced by ExtractService (CONTEXT D-48 in-process pipeline —
 * NO intermediate disk artifacts), applies mapping.yaml rules through the FieldHandlerRegistry,
 * and yields per-entry payloads containing per-site Craft-shaped field values.
 *
 * B4 — ResolverContext is constructed with the exact 7-arg v1 constructor (siteId, siteHandle,
 * state, ck, paths, siteMap, legacyDb) using named arguments.
 *
 * B8 / D-24a — Emits Craft 5 native matrix shape:
 *   'pageBuilder' => [
 *     'new1' => ['type' => 'heroBlock', 'enabled' => true, 'fields' => [...]],
 *   ]
 * with a hidden `_sourcePartRef: '<PagePartSimpleName>:<legacyId>'` field inside each block's
 * `fields` hash — EntryMigrationService reads this to thread matrix block UIDs across re-runs
 * (Pitfall 3 mitigation).
 *
 * B9 — DataProvider transform fully wired: mapping.yaml::dataProviders rows emit blocks whose
 * `type` is the dataProvider's configured target, populated from `configFields`, and carry a
 * hidden `_providerType` marker (the legacy DataProvider FQCN) so the Load stage routes them
 * to provider-block logic rather than content-block logic.
 */
class TransformService extends Component
{
    public ?FieldHandlerRegistry $handlerRegistry = null;
    public ?CkeditorRewriterService $ckeditorRewriter = null;
    public ?LegacyDbService $legacyDb = null;
    public ?MigrationStateReader $migrationState = null;
    public ?AssetPathResolver $assetPathResolver = null;

    public string $storagePath = '@storage/migration';

    /**
     * Phase 8.2 / D-15 test seam — overrides Craft fields lookup in
     * resolveMatrixInnerEntryType so the dotted-path collapse logic is unit
     * testable without a Craft fields service. Production code never sets
     * this — Craft is canonical.
     *
     * @var array<string, string>  matrixFieldHandle => innerEntryTypeHandle
     */
    public array $matrixInnerTypeMap = [];

    /**
     * CONTEXT D-48 in-process pipeline reshape — extracted rows arrive via iterable instead of disk artifacts.
     *
     * v1 signature: `run(array $mapping, array $options = []): array` — walked
     * `extracted/<fqcn-slug>/<node-id>.json` files and wrote `transformed/entries/<fqcn-slug>/<node-id>.json`.
     * v2 signature: receives an iterable of extracted-row tuples directly from ExtractService and
     * yields per-entry payload tuples, with no intermediate disk artifacts.
     *
     * @param  iterable<array<string, mixed>> $extracted stream of extracted-row tuples from ExtractService
     * @param  array<string, mixed> $mapping parsed mapping.yaml
     * @param  MigrationFilters     $filters Phase 2 / D-10 filter spec (entities + locales + since)
     * @param  array<string, mixed> $options {onlyNodeClass?: string|null, limit?: int|null}
     * @param  (callable(int $done, string $fqcn): void)|null $onProgress
     *         Optional progress callback fired after each extracted row is consumed.
     *         `$done` is the running input-row count; total is unknown (the input is a
     *         streaming iterable). Caller typically pairs this with the upstream
     *         ExtractService precount as the denominator.
     * @return iterable<array<string, mixed>> transform output payloads, one per extracted row
     */
    public function run(iterable $extracted, array $mapping, MigrationFilters $filters, array $options = [], ?callable $onProgress = null): iterable
    {
        if ($this->handlerRegistry === null) {
            throw new RuntimeException('TransformService: handlerRegistry not injected');
        }
        if ($this->ckeditorRewriter === null) {
            throw new RuntimeException('TransformService: ckeditorRewriter not injected');
        }

        $report = [
            'nodesTransformed'  => 0,
            'blocksTransformed' => 0,
            'skippedBlocks'     => 0,
            'warnings'          => [],
        ];

        // Build siteMap (legacy-locale → Craft siteId) for ResolverContext construction.
        $siteMap = $this->buildSiteMap($mapping);

        $onlyFqcn = isset($options['onlyNodeClass']) ? (string) $options['onlyNodeClass'] : null;
        $limit = isset($options['limit']) ? (int) $options['limit'] : null;
        $processed = 0;

        // Phase 2 / D-10 filter piping per FILT-02 — added in v2 port.
        // entities allow-list scopes the FQCN dispatch (empty = unbounded).
        $entitiesFilter = $filters->entities;
        // Phase 2 / D-10 filter piping per FILT-02 — added in v2 port.
        // locales scope the per-site loop in ResolverContext construction (empty = unbounded).
        $localesFilter = $filters->locales;

        // CONTEXT D-48 in-process pipeline reshape — extracted rows arrive via iterable instead of disk artifacts.
        $consumed = 0;
        foreach ($extracted as $extractedRow) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }
            if (!is_array($extractedRow)) {
                continue;
            }

            $fqcn = (string) ($extractedRow['fqcn'] ?? '');
            if ($fqcn === '') {
                continue;
            }
            $consumed++;
            if ($onProgress !== null) {
                $onProgress($consumed, $fqcn);
            }
            if ($onlyFqcn !== null && $fqcn !== $onlyFqcn) {
                continue;
            }

            // Phase 2 / D-10 filter piping per FILT-02 — added in v2 port.
            // entities allow-list (FQCN simple-name match) scopes the FQCN dispatch.
            if ($entitiesFilter !== []) {
                $simpleParts = explode('\\', trim($fqcn, '\\'));
                $simpleName = end($simpleParts);
                if (!in_array($simpleName, $entitiesFilter, true) && !in_array($fqcn, $entitiesFilter, true)) {
                    continue;
                }
            }

            $nodeSpec = $mapping['nodeClasses'][$fqcn] ?? null;
            if (!is_array($nodeSpec)) {
                $report['warnings'][] = "No nodeClasses mapping for {$fqcn}";
                continue;
            }
            if (($nodeSpec['action'] ?? null) === 'SKIP') {
                continue;
            }

            $sectionKey = (string) ($nodeSpec['section'] ?? '');
            $sectionSpec = $mapping['sections'][$sectionKey] ?? null;
            if (!is_array($sectionSpec)) {
                $report['warnings'][] = "No sections mapping for {$sectionKey}";
                continue;
            }

            $perSiteOut = [];
            $perSiteIn = (array) ($extractedRow['perSite'] ?? []);
            foreach ($perSiteIn as $locale => $siteData) {
                $locale = (string) $locale;

                // Phase 2 / D-10 filter piping per FILT-02 — added in v2 port.
                // locales scope the per-site loop (empty = unbounded).
                if ($localesFilter !== [] && !in_array($locale, $localesFilter, true)) {
                    continue;
                }

                $siteHandle = (string) ($mapping['sites'][$locale] ?? 'default');
                $siteId = $siteMap[$locale] ?? 1;

                $ctx = $this->buildContext($siteId, $siteHandle, $siteMap);

                $fieldValues = $this->transformFields(
                    (array) $siteData,
                    $nodeSpec,
                    $mapping,
                    $ctx,
                    $report,
                );

                $perSiteOut[$siteHandle] = [
                    'siteId'      => $siteId,
                    'locale'      => $locale,
                    'online'      => (bool) ($siteData['online'] ?? false),
                    'title'       => (string) ($siteData['title'] ?? ''),
                    'slug'        => (string) ($siteData['slug'] ?? ''),
                    'fieldValues' => $fieldValues,
                ];
            }

            $payload = [
                'kunstmaanSourceId' => (string) ($extractedRow['kunstmaanSourceId'] ?? ''),
                'section'           => (string) ($sectionSpec['section'] ?? ''),
                'entryType'         => (string) ($sectionSpec['entryType'] ?? ''),
                'kuma_node_id'      => (int) ($extractedRow['kuma_node_id'] ?? 0),
                'kuma_parent_id'    => $extractedRow['kuma_parent_id'] ?? null,
                'refIdsByLocale'    => (array) ($extractedRow['refIdsByLocale'] ?? []),
                'perSite'           => $perSiteOut,
                // CONTEXT D-48 in-process pipeline reshape — stateSource/stateKey carried in payload
                // for Load stage idempotency + state-row writes (was implicit via on-disk filename in v1).
                'stateSource'       => str_replace('\\', '_', trim($fqcn, '\\')),
                'stateKey'          => (int) ($extractedRow['kuma_node_id'] ?? 0),
            ];

            $report['nodesTransformed']++;
            $processed++;

            yield $payload;
        }

        // Final report row — yielded as a sentinel so callers can collect counters
        // without an explicit second return channel. Marked with a `__report` key.
        // CONTEXT D-48 in-process pipeline reshape — was returned as the run() return value in v1.
        yield ['__report' => $report];
    }

    /**
     * Build the Craft 5 per-site fieldValues hash for a single extracted site payload.
     *
     * Dispatches legacy values through FieldHandlerRegistry handlers and, for pagePart streams,
     * emits Craft 5 native matrix blocks with the B8/D-24a `_sourcePartRef` hidden field.
     *
     * DataProvider dispatch (B9) is fully wired — if a pagePart FQCN matches `mapping.dataProviders`
     * rather than `mapping.pageParts`, the block is emitted with a `_providerType` marker so the
     * Load stage routes it through provider-block logic.
     *
     * @param  array<string, mixed> $siteData
     * @param  array<string, mixed> $nodeSpec
     * @param  array<string, mixed> $mapping
     * @param  array<string, mixed> $report   (by-ref accumulator)
     * @return array<string, mixed>
     */
    private function transformFields(
        array $siteData,
        array $nodeSpec,
        array $mapping,
        ResolverContext $ctx,
        array &$report,
    ): array {
        $detail = is_array($siteData['detail'] ?? null) ? (array) $siteData['detail'] : [];
        $detail = $this->hydrateDetailJoins($detail, $nodeSpec, $report);

        // Inject synthetic _pageParts_{context} columns for pages that use direct
        // field mapping instead of a pageBuilder matrix (pageBuilderHandle: ~).
        // Each synthetic key holds the concatenated HTML from all page parts in
        // that context slot whose row contains a 'content', 'body', or 'text' column.
        // This allows mapping.yaml to say: source: _pageParts_main, handler: ckeditor
        foreach ($this->buildPagePartColumns((array) ($siteData['pageParts'] ?? [])) as $key => $html) {
            $detail[$key] ??= $html;
        }

        $fieldValues = [];

        // 1) Scalar/relational detail-table fields per nodeSpec.fields
        $fieldsSpec = (array) ($nodeSpec['fields'] ?? []);
        foreach ($fieldsSpec as $targetHandle => $fieldSpec) {
            if (!is_array($fieldSpec)) {
                continue;
            }
            $handlerId = (string) ($fieldSpec['handler'] ?? 'plain');
            if ($handlerId === 'plainText') {
                $handlerId = 'plain';
            }
            try {
                $handler = $this->handlerRegistry->get($handlerId);
            } catch (Throwable) {
                $report['warnings'][] = "Unknown handler '{$handlerId}' for field {$targetHandle}";
                continue;
            }

            $source = (string) ($fieldSpec['source'] ?? $targetHandle);
            $opts = (array) ($fieldSpec['handlerOptions'] ?? []);
            $this->applyRelationShorthand($fieldSpec, $source, $opts);
            $legacyValue = $detail[$source] ?? null;
            $opts = $this->applyTargetShorthand($mapping, $fieldSpec, $opts);

            try {
                $fieldValues[$targetHandle] = $handler->resolve($legacyValue, $ctx, $opts);
            } catch (Throwable $e) {
                $report['warnings'][] = "Handler '{$handlerId}' failed on {$targetHandle}: " . $e->getMessage();
            }
        }

        // Phase 8.2 / D-15 — collapse dotted-path target handles into nested
        // Matrix-of-entries payloads. The residual-column LLM may propose
        // `headerHome.heading -> banner_heading` (and siblings) when a column
        // cluster on the source page table maps into a Matrix-of-sub-entry
        // field on the Craft entry-type. Aggregate same-matrix-field writes
        // into a single block payload (Craft 5 shape: `<matrix>['new1'] =
        // ['type' => <innerType>, 'fields' => [...]]`).
        $fieldValues = $this->collapseDottedPathTargets($fieldValues, $report);

        // 2) PageBuilder — pagePart + dataProvider blocks in Craft 5 native matrix shape.
        $pageBuilderHandle = (string) ($nodeSpec['pageBuilderHandle'] ?? '');
        if ($pageBuilderHandle !== '') {
            // pageBuilderContexts: mapping.yaml uses list<string>; preserve trim +
            // empty-filter semantics for all reachable inputs (null => no filter).
            $rawCtxs = $nodeSpec['pageBuilderContexts'] ?? null;
            $pageBuilderContexts = $rawCtxs === null
                ? null
                : array_values(array_filter(array_map('trim', (array) $rawCtxs), 'strlen'));
            $blocks = $this->transformPageBuilder(
                (array) ($siteData['pageParts'] ?? []),
                $mapping,
                $ctx,
                $report,
                $pageBuilderContexts,
            );
            if ($blocks !== []) {
                $fieldValues[$pageBuilderHandle] = $blocks;
            }
        }

        // 3) Plan 05-05: headerBlock — emit a single-block Matrix payload under
        //    $nodeSpec['headerBlock']['fieldHandle'] for entry types that have a
        //    `header` Matrix field (typical on MethodPage / FieldPage / TextPagePlus /
        //    HomePage) so heading/subheading/image land INSIDE the header block
        //    instead of getting dropped by EntryMigrationService's validHandles filter.
        $headerSpec = $nodeSpec['headerBlock'] ?? null;
        if (is_array($headerSpec)) {
            $hFieldHandle = (string) ($headerSpec['fieldHandle'] ?? '');
            $hBlockType = (string) ($headerSpec['blockType'] ?? '');
            $hFieldsSpec = (array) ($headerSpec['fields'] ?? []);
            $hComposeSpec = (array) ($headerSpec['compose'] ?? []);
            if ($hFieldHandle !== '' && $hBlockType !== '' && ($hFieldsSpec !== [] || $hComposeSpec !== [])) {
                // Resolve declarative handler-based fields first.
                $resolvedHeaderFields = $hFieldsSpec !== []
                    ? $this->resolveFieldSpecs($hFieldsSpec, $detail, $ctx, $report, $mapping)
                    : [];

                // Compose directive (plan 05-05 Option B): synthesize block-field
                // values from multiple detail columns via HTML template
                // substitution. Applied AFTER resolveFieldSpecs so declarative
                // handler-based fields still win if both target the same
                // block-field handle.
                foreach ($hComposeSpec as $composeHandle => $composeRow) {
                    if (!is_array($composeRow)) {
                        continue;
                    }
                    $template = (string) ($composeRow['template'] ?? '');
                    if ($template === '') {
                        continue;
                    }
                    $html = preg_replace_callback(
                        '/\{([a-zA-Z0-9_]+)\}/',
                        static function (array $m) use ($detail): string {
                            $val = $detail[$m[1]] ?? '';
                            if (is_array($val) || is_object($val)) {
                                return '';
                            }
                            return htmlspecialchars((string) $val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        },
                        $template,
                    );
                    // resolveFieldSpecs output wins if the same key is present there.
                    if (!array_key_exists((string) $composeHandle, $resolvedHeaderFields)) {
                        $resolvedHeaderFields[(string) $composeHandle] = $html;
                    }
                }

                // Use the field handle + detail row id as a stable _sourcePartRef so
                // UID threading works on re-runs — without it every overwrite appends a
                // new header block instead of updating the existing one.
                // e.g. "header:headerHome:17" is stable across runs.
                $resolvedHeaderFields['_sourcePartRef'] = 'header:'
                    . $hFieldHandle
                    . ':' . (string) ($detail['id'] ?? 'x');

                // Block title: headerDefault / headerCaseHero / headerHero have
                // hasTitleField: true with no titleFormat, so Craft requires a
                // non-empty title on each block. Default to the parent entry's
                // `title` column (good editor UX — block shows the article name).
                // Allow mapping.yaml override via headerBlock.title with the same
                // {placeholder} substitution semantics as compose.
                $titleTemplate = (string) ($headerSpec['title'] ?? '{title}');
                $blockTitle = preg_replace_callback(
                    '/\{([a-zA-Z0-9_]+)\}/',
                    static function (array $m) use ($detail): string {
                        $val = $detail[$m[1]] ?? '';
                        if (is_array($val) || is_object($val)) {
                            return '';
                        }
                        return (string) $val;
                    },
                    $titleTemplate,
                );
                if ($blockTitle === '' || $blockTitle === null) {
                    $blockTitle = 'Header';
                }

                $fieldValues[$hFieldHandle] = [
                    'new1' => [
                        'type' => $hBlockType,
                        'enabled' => true,
                        'title' => $blockTitle,
                        'fields' => $resolvedHeaderFields,
                    ],
                ];
                $report['headerBlocksEmitted'] = ($report['headerBlocksEmitted'] ?? 0) + 1;
            } else {
                $report['warnings'][] = sprintf(
                    'headerBlock malformed for nodeClass; skipping emission (fieldHandle=%s, blockType=%s, fields=%d, compose=%d).',
                    $hFieldHandle,
                    $hBlockType,
                    count($hFieldsSpec),
                    count($hComposeSpec),
                );
            }
        }

        // 4) Plan 05-05: bodyWrapBlock — when nodeSpec declares bodyWrapBlock,
        //    wrap the rendered HTML from the bodyColumn source in a Matrix block
        //    of that type APPENDED to pageBuilder. Does NOT replace existing
        //    pageBuilder content (pagePart stream blocks from "2) PageBuilder"
        //    above, or bodyColumn + bodyBlockType legacy path if present).
        //
        // Accepts two shapes:
        //   (a) string            — legacy short form; uses 'html' as the
        //                           target block-field handle and the entry
        //                           title as the block title.
        //        bodyWrapBlock: textContentBlock
        //
        //   (b) object with keys  — explicit, recommended for blocks whose
        //                           inner richtext handle is not 'html' (e.g.
        //                           generalContentBlock uses ckeditorDefault).
        //        bodyWrapBlock:
        //          blockType: generalContentBlock
        //          fieldHandle: ckeditorDefault
        //          title: "{title}"        # optional; same {col} substitution
        //                                  # as headerBlock.title. Default: {title}.
        $bodyWrapSpec = $nodeSpec['bodyWrapBlock'] ?? null;
        $bodyColumn = (string) ($nodeSpec['bodyColumn'] ?? '');
        if ($bodyWrapSpec !== null && $bodyColumn !== '' && $pageBuilderHandle !== '') {
            if (is_string($bodyWrapSpec)) {
                $bwBlockType = $bodyWrapSpec;
                $bwFieldHandle = 'html';
                $bwTitleTemplate = '{title}';
            } elseif (is_array($bodyWrapSpec)) {
                $bwBlockType = (string) ($bodyWrapSpec['blockType'] ?? '');
                $bwFieldHandle = (string) ($bodyWrapSpec['fieldHandle'] ?? 'html');
                $bwTitleTemplate = (string) ($bodyWrapSpec['title'] ?? '{title}');
            } else {
                $bwBlockType = '';
                $bwFieldHandle = '';
                $bwTitleTemplate = '';
            }

            $contentHtml = (string) ($detail[$bodyColumn] ?? '');
            if ($bwBlockType !== '' && $bwFieldHandle !== '' && $contentHtml !== '') {
                $bwTitle = preg_replace_callback(
                    '/\{([a-zA-Z0-9_]+)\}/',
                    static function (array $m) use ($detail): string {
                        $val = $detail[$m[1]] ?? '';
                        if (is_array($val) || is_object($val)) {
                            return '';
                        }
                        return (string) $val;
                    },
                    $bwTitleTemplate,
                );
                if ($bwTitle === '' || $bwTitle === null) {
                    $bwTitle = 'Content';
                }

                $existing = (array) ($fieldValues[$pageBuilderHandle] ?? []);
                $nextIndex = count($existing) + 1;
                $blockKey = 'new' . $nextIndex;
                $existing[$blockKey] = [
                    'type' => $bwBlockType,
                    'enabled' => true,
                    'title' => $bwTitle,
                    'fields' => [
                        $bwFieldHandle => $contentHtml,
                    ],
                ];
                $fieldValues[$pageBuilderHandle] = $existing;
                $report['bodyWrapBlocksEmitted'] = ($report['bodyWrapBlocksEmitted'] ?? 0) + 1;
            }
        }

        return $fieldValues;
    }

    /**
     * Emit Craft 5 native matrix blocks for the pageParts stream.
     *
     * Block shape per D-24a:
     *   'new1' => [
     *     'type'    => '<targetBlockType>',
     *     'enabled' => true,
     *     'fields'  => [ ...resolvedValues..., '_sourcePartRef' => '<SimpleName>:<legacyId>' ],
     *   ]
     *
     * DataProvider rows (B9) use `_providerType` in addition to `_sourcePartRef` so the Load
     * stage can dispatch provider-block logic (e.g. persist the provider FQCN on the block).
     *
     * @param  list<array<string, mixed>> $pageParts
     * @param  array<string, mixed>       $mapping
     * @param  array<string, mixed>       $report      (by-ref accumulator)
     * @param  list<string>|null          $allowedCtxs restrict to these context slots (null = no filter)
     * @return array<string, mixed>
     */
    private function transformPageBuilder(array $pageParts, array $mapping, ResolverContext $ctx, array &$report, ?array $allowedCtxs = null): array
    {
        $blocks = [];
        $blockIndex = 1;

        foreach ($pageParts as $pp) {
            if (!is_array($pp)) {
                continue;
            }
            $ppFqcn = (string) ($pp['fqcn'] ?? '');
            if ($ppFqcn === '') {
                continue;
            }
            if ($allowedCtxs !== null) {
                $ppCtx = (string) ($pp['context'] ?? '');
                if (!in_array($ppCtx, $allowedCtxs, true)) {
                    continue;
                }
            }
            $row = is_array($pp['row'] ?? null) ? (array) $pp['row'] : [];

            // Try pageParts first.
            $partSpec = $mapping['pageParts'][$ppFqcn] ?? null;
            $providerSpec = null;
            if (!is_array($partSpec)) {
                $providerSpec = $mapping['dataProviders'][$ppFqcn] ?? null;
            }

            if (is_array($partSpec)) {
                if (($partSpec['action'] ?? null) === 'SKIP') {
                    $report['skippedBlocks']++;
                    continue;
                }
                $resolvedFields = $this->resolveFieldSpecs(
                    (array) ($partSpec['fields'] ?? []),
                    $row,
                    $ctx,
                    $report,
                    $mapping,
                );
                $resolvedFields['_sourcePartRef'] = $this->deriveSourcePartRef(
                    $ppFqcn,
                    (int) ($pp['sourcePartId'] ?? $blockIndex),
                );

                $blocks['new' . $blockIndex] = [
                    'type'    => (string) ($partSpec['target'] ?? ''),
                    'enabled' => true,
                    'fields'  => $resolvedFields,
                ];
                $blockIndex++;
                $report['blocksTransformed']++;
                continue;
            }

            if (is_array($providerSpec)) {
                // B9 — DataProvider dispatch fully wired.
                if (($providerSpec['action'] ?? null) === 'SKIP') {
                    $report['skippedBlocks']++;
                    continue;
                }
                $configFieldValues = $this->resolveFieldSpecs(
                    (array) ($providerSpec['configFields'] ?? []),
                    $row,
                    $ctx,
                    $report,
                    $mapping,
                );
                $configFieldValues['_providerType']  = $ppFqcn;
                $configFieldValues['_sourcePartRef'] = $this->deriveSourcePartRef(
                    $ppFqcn,
                    (int) ($pp['sourcePartId'] ?? $blockIndex),
                );

                $blocks['new' . $blockIndex] = [
                    'type'    => (string) ($providerSpec['target'] ?? ''),
                    'enabled' => true,
                    'fields'  => $configFieldValues,
                ];
                $blockIndex++;
                $report['blocksTransformed']++;
                continue;
            }

            // Unknown FQCN — log and skip; mapping should cover every encountered type.
            $report['warnings'][] = "No pageParts/dataProviders mapping for {$ppFqcn}";
            $report['skippedBlocks']++;
        }

        return $blocks;
    }

    /**
     * Dispatch a bag of {source, handler, handlerOptions?} specs against a legacy row.
     *
     * @param  array<string, mixed> $specs
     * @param  array<string, mixed> $row
     * @param  array<string, mixed> $report by-ref accumulator
     * @return array<string, mixed>
     */
    /**
     * Resolves a fieldSpec's `target:` shorthand to `handlerOptions.stateSource`,
     * consulting mapping.yaml to translate a Craft entryType handle (e.g.
     * `teamMember`) into the FQCN-slug the state table is keyed by (e.g.
     * `App_Entity_Pages_EmployeePage`). Accepts a pre-resolved FQCN-slug as-is.
     *
     * Without this, every relation mapping using the semantic form
     * `target: teamMember` silently no-ops because RelationHandler requires
     * `handlerOptions.stateSource` and state rows aren't keyed by entryType.
     *
     * @param  array<string, mixed>  $mapping  Full mapping.yaml tree.
     * @param  array<string, mixed>  $spec     Field spec row.
     * @param  array<string, mixed>  $opts     Current handlerOptions.
     * @return array<string, mixed>            Augmented handlerOptions.
     */
    /**
     * Phase 8.2 / D-15 — collapse dotted-path target handles into Craft 5
     * Matrix-of-entries payloads.
     *
     * Input:  fieldValues with mixed flat + dotted keys, e.g.
     *           ['title' => 'X', 'headerHome.heading' => 'Welcome', 'headerHome.subheading' => '...']
     * Output: dotted keys removed, replaced with single matrix-shaped value
     *           ['title' => 'X', 'headerHome' => ['new1' => ['type' => 'headerHero',
     *                                                        'enabled' => true,
     *                                                        'fields' => ['heading' => 'Welcome', 'subheading' => '...']]]]
     *
     * Inner entry-type resolution: looks up via Craft::$app->fields->getFieldByHandle.
     * If the field is not a Matrix or has no entry-types, the dotted entries
     * are left untouched and a WARN is logged. If the Matrix has multiple
     * inner entry-types, the first (alphabetically by handle) is picked and a
     * WARN is logged so the operator can re-mapping with explicit 3-part
     * dotted handles if needed.
     *
     * Operator-set Matrix value (already a flat assign for `headerHome` from
     * a top-level mapping) wins — sibling dotted entries are merged into it
     * if the value is array-shaped, otherwise dropped with a WARN.
     *
     * @param  array<string, mixed> $fieldValues
     * @param  array<string, mixed> $report  by-ref accumulator
     * @return array<string, mixed>
     */
    private function collapseDottedPathTargets(array $fieldValues, array &$report): array
    {
        // Group dotted keys by their leading prefix (the matrix-field handle).
        $groups = [];
        foreach ($fieldValues as $handle => $value) {
            if (!is_string($handle) || strpos($handle, '.') === false) {
                continue;
            }
            $parts = explode('.', $handle, 2);
            $matrixHandle = $parts[0];
            $subHandle = $parts[1] ?? '';
            if ($matrixHandle === '' || $subHandle === '') {
                continue;
            }
            $groups[$matrixHandle][$subHandle] = $value;
        }
        if ($groups === []) {
            return $fieldValues;
        }

        foreach ($groups as $matrixHandle => $subFields) {
            $innerType = $this->resolveMatrixInnerEntryType($matrixHandle, $report);
            if ($innerType === null) {
                // Resolution failed — keep dotted keys as-is (defense: never
                // silently drop data). The WARN logged inside the resolver
                // tells the operator what to fix.
                continue;
            }

            // Build the new block payload.
            $newBlock = [
                'new1' => [
                    'type'    => $innerType,
                    'enabled' => true,
                    'fields'  => $subFields,
                ],
            ];

            // Skip-existing: if the operator already set fieldValues[$matrixHandle]
            // (rare — would require a flat top-level mapping AND a dotted-path
            // mapping for the same matrix on the same entry), prefer the
            // operator's value and drop the dotted siblings with a WARN. We
            // do not try to merge — that would silently change operator intent.
            if (array_key_exists($matrixHandle, $fieldValues)) {
                $report['warnings'][] = sprintf(
                    'Matrix field %s already has a top-level value; dotted-path siblings (%s) skipped to preserve operator intent',
                    $matrixHandle,
                    implode(', ', array_keys($subFields)),
                );
                // Still remove the dotted entries — they would otherwise
                // hit Craft as unknown field handles and warn there.
                foreach (array_keys($subFields) as $sub) {
                    unset($fieldValues[$matrixHandle . '.' . $sub]);
                }
                continue;
            }

            $fieldValues[$matrixHandle] = $newBlock;
            foreach (array_keys($subFields) as $sub) {
                unset($fieldValues[$matrixHandle . '.' . $sub]);
            }
        }
        return $fieldValues;
    }

    /**
     * Phase 8.2 / D-15 — resolve a Matrix field's inner entry-type handle
     * from its handle. Returns null + WARN on any miss / ambiguity that the
     * caller treats as "leave dotted keys untouched".
     *
     * Test seam: $matrixInnerTypeMap (set by tests via reflection) overrides
     * Craft lookup so this method is exercisable without a live Craft fields
     * service.
     *
     * @param  array<string, mixed> $report
     */
    private function resolveMatrixInnerEntryType(string $matrixHandle, array &$report): ?string
    {
        // Test seam — see TransformServiceMatrixSubFieldTest.
        if (isset($this->matrixInnerTypeMap[$matrixHandle])) {
            return (string) $this->matrixInnerTypeMap[$matrixHandle];
        }

        $field = Craft::$app->fields->getFieldByHandle($matrixHandle);
        if ($field === null) {
            $report['warnings'][] = "Matrix field '{$matrixHandle}' not found in Craft — dotted-path target handles left as-is";
            return null;
        }
        if (!($field instanceof \craft\fields\Matrix)) {
            $report['warnings'][] = "Field '{$matrixHandle}' is not a Matrix field — cannot collapse dotted-path siblings";
            return null;
        }
        $entryTypes = $field->getEntryTypes();
        if ($entryTypes === []) {
            $report['warnings'][] = "Matrix field '{$matrixHandle}' has no inner entry-types — cannot collapse dotted-path siblings";
            return null;
        }
        if (count($entryTypes) > 1) {
            // Pick the first (alphabetical by handle) but warn so the operator
            // can re-map with an explicit 3-part dotted handle if the wrong
            // type was chosen.
            usort(
                $entryTypes,
                static fn($a, $b): int => strcmp((string) $a->handle, (string) $b->handle),
            );
            $report['warnings'][] = sprintf(
                'Matrix field %s has %d inner entry-types; auto-picked %s (re-map with explicit 3-part dotted handle to override)',
                $matrixHandle,
                count($entryTypes),
                (string) $entryTypes[0]->handle,
            );
        }
        $h = (string) $entryTypes[0]->handle;
        return $h !== '' ? $h : null;
    }

    private function applyTargetShorthand(array $mapping, array $spec, array $opts): array
    {
        $target = (string) ($spec['target'] ?? '');
        if ($target === '' || isset($opts['stateSource'])) {
            return $opts;
        }

        // Already an FQCN-slug (contains "_Entity_" or literal backslash) —
        // pass through; state table uses this exact format.
        if (str_contains($target, '_Entity_') || str_contains($target, '\\')) {
            $opts['stateSource'] = str_replace('\\', '_', $target);
            return $opts;
        }

        // Resolve entryType-handle shorthand via sections + nodeClasses.
        $sections = (array) ($mapping['sections'] ?? []);
        $nodeClasses = (array) ($mapping['nodeClasses'] ?? []);
        foreach ($sections as $sectionKey => $sectionSpec) {
            if (!is_array($sectionSpec)) {
                continue;
            }
            if ((string) ($sectionSpec['entryType'] ?? '') !== $target) {
                continue;
            }
            foreach ($nodeClasses as $fqcn => $nodeSpec) {
                if (is_array($nodeSpec) && (string) ($nodeSpec['section'] ?? '') === $sectionKey) {
                    $opts['stateSource'] = str_replace('\\', '_', trim((string) $fqcn, '\\'));
                    return $opts;
                }
            }
        }

        // Fallback: use target literally — may still fail downstream if it
        // doesn't match a real state source, but preserves legacy behaviour.
        $opts['stateSource'] = $target;
        return $opts;
    }

    /**
     * Promotes a `relation:` sub-key in a field spec to flat handlerOptions
     * understood by RelationHandler, and redirects `$source` to `'id'` so the
     * handler receives the page-part's own PK as legacyValue.
     *
     * Mapping.yaml shorthand:
     *   source: "<join_table>"        → opts[joinTable]
     *   relation.joinColumn           → opts[joinLocalColumn]
     *   relation.relatedIdColumn      → opts[joinForeignColumn]          (entry)
     *   relation.assetIdColumn        → opts[joinForeignColumn]          (asset)
     *   relation.orderBy              → opts[joinOrderBy]
     *   target: asset (implicit)      → opts[stateSource]='media', opts[stateKeyPrefix]='kuma_media:'
     *
     * Already-explicit handlerOptions keys are never overwritten.
     *
     * @param array<string, mixed> $fieldSpec
     * @param string               $source   mutated to 'id' when relation: key found
     * @param array<string, mixed> $opts     mutated in-place
     */
    private function applyRelationShorthand(array $fieldSpec, string &$source, array &$opts): void
    {
        $rel = $fieldSpec['relation'] ?? null;
        if (!is_array($rel)) {
            return;
        }

        // source is the join table name; page-part's own PK is the local key
        if (!isset($opts['joinTable'])) {
            $opts['joinTable'] = $source;
        }
        $source = 'id';

        if (!isset($opts['joinLocalColumn']) && isset($rel['joinColumn'])) {
            $opts['joinLocalColumn'] = (string) $rel['joinColumn'];
        }

        // Entry relation: relatedIdColumn is the FK pointing at the target entity
        if (!isset($opts['joinForeignColumn']) && isset($rel['relatedIdColumn'])) {
            $opts['joinForeignColumn'] = (string) $rel['relatedIdColumn'];
        }

        // Asset relation: assetIdColumn is the kuma_media id
        if (!isset($opts['joinForeignColumn']) && isset($rel['assetIdColumn'])) {
            $opts['joinForeignColumn'] = (string) $rel['assetIdColumn'];
            // Assets stored in state as source='media', key='kuma_media:{id}'
            if (!isset($opts['stateSource'])) {
                $opts['stateSource'] = 'media';
            }
            if (!isset($opts['stateKeyPrefix'])) {
                $opts['stateKeyPrefix'] = 'kuma_media:';
            }
        }

        if (!isset($opts['joinOrderBy']) && isset($rel['orderBy'])) {
            $opts['joinOrderBy'] = (string) $rel['orderBy'];
        }
    }

    private function resolveFieldSpecs(array $specs, array $row, ResolverContext $ctx, array &$report, array $mapping = []): array
    {
        $out = [];
        foreach ($specs as $targetHandle => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $handlerId = (string) ($spec['handler'] ?? 'plain');
            if ($handlerId === 'plainText') {
                $handlerId = 'plain';
            }
            try {
                $handler = $this->handlerRegistry->get($handlerId);
            } catch (Throwable) {
                $report['warnings'][] = "Unknown handler '{$handlerId}' for {$targetHandle}";
                continue;
            }

            $source = (string) ($spec['source'] ?? $targetHandle);
            $opts = (array) ($spec['handlerOptions'] ?? []);
            $this->applyRelationShorthand($spec, $source, $opts);
            $legacyValue = $row[$source] ?? null;
            $opts = $this->applyTargetShorthand($mapping, $spec, $opts);

            try {
                $out[$targetHandle] = $handler->resolve($legacyValue, $ctx, $opts);
            } catch (Throwable $e) {
                $report['warnings'][] = "Handler '{$handlerId}' failed on {$targetHandle}: " . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * B4 — instantiate ResolverContext with the exact v1 7-arg constructor.
     *
     * Uses named arguments so order-drift in the VO is caught at compile time.
     *
     * @param  array<string, int> $siteMap  locale → craft site id
     */
    private function buildContext(int $siteId, string $siteHandle, array $siteMap): ResolverContext
    {
        return new ResolverContext(
            siteId:     $siteId,
            siteHandle: $siteHandle,
            state:      $this->migrationState ?? $this->emptyStateReader(),
            ck:         $this->ckeditorRewriter,
            paths:      $this->resolvePaths(),
            siteMap:    $siteMap,
            legacyDb:   $this->legacyDb,
        );
    }

    /**
     * Return the AssetPathResolver — injected when available, constructed fresh otherwise.
     * AssetPathResolver is the v1-ported pure-function helper (all static methods); a fresh
     * instance is behaviourally identical to an injected one.
     */
    private function resolvePaths(): AssetPathResolver
    {
        return $this->assetPathResolver ?? new AssetPathResolver();
    }

    /**
     * Return a MigrationStateReader. When no state service has been injected (Transform can run
     * before MigrationStateService is wired), provide an anonymous-class
     * implementation whose answers correspond to "no prior migration state" — the correct
     * semantics for a first run.
     */
    private function emptyStateReader(): MigrationStateReader
    {
        return new class implements MigrationStateReader {
            public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
            {
                return null;
            }
            public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
            {
                return null;
            }
            public function get(string $source, string $key, ?int $siteId = null): ?array
            {
                return null;
            }
        };
    }

    /**
     * Build locale → craftSiteId map from mapping.sites and the running Craft sites table.
     *
     * @return array<string, int>
     */
    private function buildSiteMap(array $mapping): array
    {
        $sites = (array) ($mapping['sites'] ?? []);
        $out = [];
        foreach ($sites as $locale => $handle) {
            $locale = (string) $locale;
            $handleStr = (string) $handle;
            try {
                $site = Craft::$app->sites->getSiteByHandle($handleStr);
                if ($site !== null) {
                    $out[$locale] = (int) $site->id;
                }
            } catch (Throwable) {
                // Leave unresolved; ResolverContext ctor tolerates missing locales.
            }
        }
        if ($out === []) {
            // Fallback for pre-Craft-bootstrap contexts (e.g. unit tests).
            $out['nl'] = 1;
        }
        return $out;
    }

    /**
     * Hydrate a detail row with columns from one or more joined tables, exposed
     * under dotted keys `"<alias>.<col>"` so field specs written as
     * `source: "employee.job_title"` resolve against `$detail["employee.job_title"]`.
     *
     * Driven by `nodeSpec.joins`:
     *   joins:
     *     <alias>:
     *       table: <legacy_table_name>
     *       fk:    <fk_column_on_detail_row>   # e.g. employee_id
     *       pk:    <pk_column_on_joined_table> # defaults to "id"
     *
     * Silent no-op when joins is absent or legacyDb isn't injected. When the FK
     * is null/missing or the joined row can't be found, no dotted keys are
     * written (handlers see null and pass through their normal null-handling).
     *
     * @param  array<string, mixed> $detail   Detail row from the extracted JSON payload.
     * @param  array<string, mixed> $nodeSpec nodeClass spec from mapping.yaml.
     * @param  array<string, mixed> $report   by-ref accumulator for warnings.
     * @return array<string, mixed>
     */
    private function hydrateDetailJoins(array $detail, array $nodeSpec, array &$report): array
    {
        $joins = (array) ($nodeSpec['joins'] ?? []);
        if ($joins === [] || $this->legacyDb === null) {
            return $detail;
        }

        foreach ($joins as $alias => $joinSpec) {
            if (!is_string($alias) || $alias === '' || !is_array($joinSpec)) {
                continue;
            }
            $table = (string) ($joinSpec['table'] ?? '');
            $fkCol = (string) ($joinSpec['fk'] ?? '');
            $pkCol = (string) ($joinSpec['pk'] ?? 'id');
            if ($table === '' || $fkCol === '') {
                $report['warnings'][] = "joins alias '{$alias}' missing table/fk; skipped";
                continue;
            }

            // T-04-05-01 identifier whitelist — same discipline as ExtractService.
            if (
                !preg_match('/^[a-zA-Z0-9_]+$/', $table)
                || !preg_match('/^[a-zA-Z0-9_]+$/', $pkCol)
            ) {
                $report['warnings'][] = "joins alias '{$alias}' has non-whitelisted identifier; skipped";
                continue;
            }

            $fkValue = $detail[$fkCol] ?? null;
            if ($fkValue === null || $fkValue === '' || $fkValue === 0 || $fkValue === '0') {
                continue;
            }

            try {
                $joined = $this->legacyDb->queryOne(
                    "SELECT * FROM `{$table}` WHERE `{$pkCol}` = :pk LIMIT 1",
                    [':pk' => $fkValue],
                );
            } catch (Throwable $e) {
                $report['warnings'][] = "joins alias '{$alias}' query failed: " . $e->getMessage();
                continue;
            }
            if (!is_array($joined)) {
                continue;
            }

            foreach ($joined as $col => $val) {
                if (!is_string($col) || $col === '') {
                    continue;
                }
                $detail[$alias . '.' . $col] = $val;
            }
        }

        return $detail;
    }

    /**
     * Build synthetic _pageParts_{context} keys from the extracted page-parts list.
     *
     * Groups parts by their context slot and concatenates the HTML from any row
     * that has a 'content', 'body', or 'text' column (the common Kunstmaan text
     * column names). The resulting keys can be used as `source:` in mapping.yaml
     * for direct CKEditor field mapping without going through a pageBuilder matrix.
     *
     * Example output:
     *   ['_pageParts_main' => '<p>…</p><p>…</p>', '_pageParts_sidebar' => '…']
     *
     * @param  list<array<string, mixed>> $pageParts extracted page-part items
     * @return array<string, string>
     */
    private function buildPagePartColumns(array $pageParts): array
    {
        $byContext = [];
        foreach ($pageParts as $part) {
            $context = (string) ($part['context'] ?? 'main');
            $row = (array) ($part['row'] ?? []);
            $html = '';
            foreach (['content', 'body', 'text'] as $textCol) {
                if (isset($row[$textCol]) && is_string($row[$textCol]) && $row[$textCol] !== '') {
                    $html = $row[$textCol];
                    break;
                }
            }
            if ($html !== '') {
                $byContext[$context][] = $html;
            }
        }

        $result = [];
        foreach ($byContext as $context => $chunks) {
            $result["_pageParts_{$context}"] = implode("\n", $chunks);
        }
        return $result;
    }

    /**
     * B8/D-24a — derive the `_sourcePartRef` hidden field value for a block.
     *
     *   'App\Entity\PageParts\HeroPagePart' → 'HeroPagePart:123'
     *
     * EntryMigrationService reads this field to thread Craft block UIDs across re-runs
     * (Pitfall 3 mitigation).
     */
    private function deriveSourcePartRef(string $fqcn, int $legacyId): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        $simple = end($parts);
        if (!is_string($simple) || $simple === '') {
            $simple = $fqcn;
        }
        return $simple . ':' . $legacyId;
    }
}
