<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use Craft;
use yii\base\Component;

/**
 * CraftKnowledgeBase — renders the operator's TARGET Craft schema (sections,
 * entry types, custom field layouts) into LLM-prompt-friendly markdown plus a
 * structured field index keyed by entry-type handle.
 *
 * Sibling of `KunstmaanKnowledgeBase` (which renders the SOURCE Kunstmaan schema). Both
 * are fed to LlmClassifier and the new EntityClassifier so the AI sees BOTH
 * sides of the proposed mapping rather than guessing target handles into the
 * void.
 *
 * Pure read on Craft's services — no DB writes, no schema mutations. Every
 * method is idempotent and side-effect-free; the markdown rendered for a given
 * Craft state is stable across calls.
 *
 * Why this exists (gap closure): AnalyzeController previously shipped both
 * `buildCraftFieldIndex()` returning [] (Plan 03 placeholder) and an empty
 * `$targetKbMarkdown = ''` argument to LlmClassifier::batchPropose. The LLM
 * therefore proposed `targetEntryType` / `targetHandle` against an unknown
 * target schema — which is why every column-level proposal came back with
 * `targetEntryType=''` (it had nothing to propose against). Wiring this
 * service into AnalyzeController closes both holes in one pass.
 */
final class CraftKnowledgeBase extends Component
{
    /**
     * Return a structured snapshot of the Craft target schema.
     *
     * Analyze persists this beside `kunstmaan-schema.json` so operators and tests can
     * inspect both sides of the mapping contract as files: Kunstmaan source
     * structure in `kunstmaan-schema.json`, Craft target structure here.
     *
     * @return array<string, mixed>
     */
    public function dumpTargetSchema(): array
    {
        $volumes = [];
        try {
            foreach (Craft::$app->volumes->getAllVolumes() as $volume) {
                $handle = (string) $volume->handle;
                if ($handle !== '') {
                    $volumes[] = $handle;
                }
            }
        } catch (\Throwable) {
            $volumes = [];
        }

        return [
            'generatedAt' => date('c'),
            'sections' => $this->sectionToEntryTypes(),
            'entryTypes' => $this->buildFieldIndex(),
            'entryTypeFlatHandles' => $this->entryTypeFlatHandles(),
            'flatPagePartCandidates' => $this->flatPagePartCandidates(),
            'matrixFields' => $this->matrixFieldCatalog(),
            'volumes' => array_values(array_unique($volumes)),
            'plugins' => [
                'seomatic' => Craft::$app->plugins->getPlugin('seomatic') !== null,
                'retour' => Craft::$app->plugins->getPlugin('retour') !== null,
            ],
        ];
    }

    /**
     * Render Craft's section + entry-type + custom-field catalog as markdown.
     * Intended as the `$targetKbMarkdown` argument to LlmClassifier so the
     * LLM can pick a real entry-type handle and a real field handle.
     *
     * Format mirrors the Kunstmaan KB markdown style (one ## per entry type,
     * bulleted field rows underneath) so prompts stay parallel.
     */
    public function renderEntryTypesMarkdown(): string
    {
        $entryTypes = Craft::$app->entries->getAllEntryTypes();
        $sections = Craft::$app->entries->getAllSections();

        // Index entryType.id → list of section handles that include it. Operators
        // think in terms of "where do entries of this type live"; the LLM uses
        // this to disambiguate same-handled types across sections (rare in
        // practice but the data is cheap to surface).
        $entryTypeIdToSections = [];
        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $et) {
                $entryTypeIdToSections[(int) $et->id][] = (string) $section->handle;
            }
        }

        $out = [];
        $out[] = '# Craft target schema';
        $out[] = '';
        $out[] = '_All entry types in this Craft install. Pick the best-matching `handle` for FQCN/section proposals; pick a field `handle` from the listed custom fields per entry type._';
        $out[] = '';

        // Stable sort by handle for prompt reproducibility (LLM cache friendliness).
        usort($entryTypes, static fn($a, $b): int => strcmp((string) $a->handle, (string) $b->handle));

        foreach ($entryTypes as $entryType) {
            $handle = (string) $entryType->handle;
            $name = (string) $entryType->name;
            $sectionHandles = $entryTypeIdToSections[(int) $entryType->id] ?? [];
            sort($sectionHandles);

            $out[] = sprintf('## %s', $handle);
            $out[] = sprintf('- **name**: %s', $name);
            if ($sectionHandles !== []) {
                $out[] = sprintf('- **sections**: %s', implode(', ', array_unique($sectionHandles)));
            } else {
                $out[] = '- **sections**: _(none — likely a nested-block entry type or unattached)_';
            }
            $description = (string) ($entryType->description ?? '');
            if ($description !== '') {
                $out[] = sprintf('- **description**: %s', $description);
            }

            $fieldLayout = $entryType->getFieldLayout();
            if ($fieldLayout !== null) {
                $fields = $fieldLayout->getCustomFields();
                if ($fields !== []) {
                    $out[] = '- **fields**:';
                    foreach ($fields as $field) {
                        $fhandle = (string) $field->handle;
                        $ftype = $this->shortClassName(get_class($field));
                        $out[] = sprintf('  - `%s` (%s)', $fhandle, $ftype);

                        // Phase 8.2 / D-15 — expand Matrix fields into their
                        // allowed inner entry-types + sub-fields. Lets the
                        // residual-column LLM propose dotted-path targets like
                        // `banner_heading -> headerHome.heading` when a column
                        // cluster on the source page table maps into a
                        // nested-block shape on the Craft entry-type. Generic:
                        // works for any Matrix-of-sub-entry-types pattern, not
                        // just `headerBlock` / `bodyWrapBlock` / `bodyColumn`
                        // slot names. One level deep — Craft Matrix-of-Matrix
                        // is a power-user shape we deliberately do not surface.
                        if ($field instanceof \craft\fields\Matrix) {
                            foreach ($field->getEntryTypes() as $innerEt) {
                                $innerEtHandle = (string) $innerEt->handle;
                                if ($innerEtHandle === '') { continue; }
                                $innerLayout = $innerEt->getFieldLayout();
                                if ($innerLayout === null) { continue; }
                                $innerFields = $innerLayout->getCustomFields();
                                if ($innerFields === []) { continue; }
                                $out[] = sprintf(
                                    '    - inner `%s` block — sub-fields:',
                                    $innerEtHandle,
                                );
                                foreach ($innerFields as $innerField) {
                                    $innerHandle = (string) $innerField->handle;
                                    if ($innerHandle === '') { continue; }
                                    $innerType = $this->shortClassName(get_class($innerField));
                                    $out[] = sprintf(
                                        '      - `%s.%s` (%s) — write as `%s`',
                                        $fhandle,
                                        $innerHandle,
                                        $innerType,
                                        $fhandle . '.' . $innerHandle,
                                    );
                                }
                            }
                        }
                    }
                }
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Build the structured field index that LlmClassifier consumes for the
     * `allowed=[...]` per-residual hint inside its prompt.
     *
     * @return array<string, list<array{handle: string, type: string, classification?: string}>>
     *         keyed by entry-type handle
     */
    public function buildFieldIndex(): array
    {
        $out = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
            $handle = (string) $entryType->handle;
            if ($handle === '') {
                continue;
            }
            $fieldLayout = $entryType->getFieldLayout();
            if ($fieldLayout === null) {
                $out[$handle] = [];
                continue;
            }
            $fields = [];
            foreach ($fieldLayout->getCustomFields() as $field) {
                $fhandle = (string) $field->handle;
                if ($fhandle === '') {
                    continue;
                }
                $fields[] = $this->describeField($field);

                // Phase 8.2 / D-15 — flatten Matrix sub-entry-type sub-fields
                // into the index using dotted-path handles (e.g.
                // `headerHome.heading`). The residual-column LLM uses this
                // index for its `allowed=[...]` hint, so emitting the dotted
                // forms here lets it propose them directly. Walk one level
                // deep to mirror the markdown surface (Matrix-of-Matrix is
                // intentionally out of scope).
                if ($field instanceof \craft\fields\Matrix) {
                    foreach ($field->getEntryTypes() as $innerEt) {
                        $innerLayout = $innerEt->getFieldLayout();
                        if ($innerLayout === null) { continue; }
                        foreach ($innerLayout->getCustomFields() as $innerField) {
                            $innerHandle = (string) $innerField->handle;
                            if ($innerHandle === '') { continue; }
                            $sub = $this->describeField($innerField);
                            $sub['handle'] = $fhandle . '.' . $innerHandle;
                            $sub['classification'] = 'matrixSub';
                            $fields[] = $sub;
                        }
                    }
                }
            }
            $out[$handle] = $fields;
        }
        return $out;
    }

    /**
     * Returns the bare list of entry-type handles. Used by EntityClassifier as
     * the closed set the LLM may pick from — and by CompileController's
     * post-compile validation step to flag mismatches.
     *
     * @return list<string>
     */
    public function entryTypeHandles(): array
    {
        $out = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $et) {
            $h = (string) $et->handle;
            if ($h !== '') {
                $out[] = $h;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * Phase 8.7 / D-40 — flat-handle catalog per entry type.
     *
     * Returns `entryTypeHandle => list<fieldHandle>` for every entry type's
     * top-level custom fields. `MappingCompiler` consumes this to validate
     * that a column proposal's `targetHandle` actually exists on the chosen
     * entry-type before writing it into `nodeClasses[fqcn].fields[]` —
     * silent-empty bugs like `content → newsPage::content` (newsPage has no
     * `content` field) are caught at compile time rather than producing
     * orphan field assignments that get dropped at save time.
     *
     * Includes the built-in `title` and `slug` handles per entry type
     * because EntryMigrationService writes them as native Craft fields
     * (not custom fields). Excludes Matrix sub-fields — those are handled
     * via dotted-path targets, not flat ones.
     *
     * @return array<string, list<string>>
     */
    public function entryTypeFlatHandles(): array
    {
        $out = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
            $handle = (string) $entryType->handle;
            if ($handle === '') {
                continue;
            }
            $handles = ['title', 'slug'];  // built-in native fields
            $layout = $entryType->getFieldLayout();
            if ($layout !== null) {
                foreach ($layout->getCustomFields() as $field) {
                    $h = (string) $field->handle;
                    if ($h !== '' && !str_contains($h, '.')) {
                        $handles[] = $h;
                    }
                }
            }
            $out[$handle] = array_values(array_unique($handles));
        }
        return $out;
    }

    /**
     * Phase 8.7 / D-39 — auto-detect entry types that should fold page-part
     * content into a flat ckeditor field instead of a Matrix block.
     *
     * Returns `entryTypeHandle => flatCkeditorFieldHandle` for every entry
     * type whose field layout has NO Matrix field AND has at least one
     * `craft\ckeditor\Field`. Used by `MappingCompiler::compile()` to
     * auto-emit `nodeClasses[fqcn].flatPagePartContent: <handle>` when the
     * operator hasn't set it manually — so `teamMember` (and any future
     * matrix-less entry type with a `ckeditorDefault`) automatically picks
     * up TextPagePart bio content without per-project hand-curation.
     *
     * Tie-breaking when an entry type has multiple ckeditor fields: prefer
     * the one named `ckeditorDefault`, then `content`, then `body`, then
     * the first one alphabetically. Operator can always override the
     * auto-detected value via the explicit `flatPagePartContent` key.
     *
     * @return array<string, string>
     */
    public function flatPagePartCandidates(): array
    {
        if (!class_exists(\craft\ckeditor\Field::class, true)) {
            // Project doesn't have the ckeditor plugin — no candidates.
            return [];
        }
        $preferred = ['ckeditorDefault', 'content', 'body'];
        $out = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
            $handle = (string) $entryType->handle;
            if ($handle === '') {
                continue;
            }
            $layout = $entryType->getFieldLayout();
            if ($layout === null) {
                continue;
            }
            $hasMatrix = false;
            $ckHandles = [];
            foreach ($layout->getCustomFields() as $field) {
                if ($field instanceof \craft\fields\Matrix) {
                    $hasMatrix = true;
                    break;
                }
                if ($field instanceof \craft\ckeditor\Field) {
                    $ckHandles[] = (string) $field->handle;
                }
            }
            if ($hasMatrix || $ckHandles === []) {
                continue;
            }
            // Tie-break: preferred-name match, else alphabetical first.
            $pick = null;
            foreach ($preferred as $p) {
                if (in_array($p, $ckHandles, true)) {
                    $pick = $p;
                    break;
                }
            }
            if ($pick === null) {
                sort($ckHandles);
                $pick = $ckHandles[0];
            }
            $out[$handle] = $pick;
        }
        return $out;
    }

    /**
     * Returns sectionHandle → list of entry-type handles it permits.
     * EntityClassifier uses this to suggest the section a proposed entry
     * type lives in (operator-friendly downstream — `sections[X].entryType=Y`).
     *
     * @return array<string, list<string>>
     */
    public function sectionToEntryTypes(): array
    {
        $out = [];
        foreach (Craft::$app->entries->getAllSections() as $section) {
            $handle = (string) $section->handle;
            $ets = [];
            foreach ($section->getEntryTypes() as $et) {
                $h = (string) $et->handle;
                if ($h !== '') {
                    $ets[] = $h;
                }
            }
            $out[$handle] = array_values(array_unique($ets));
        }
        return $out;
    }

    /**
     * Phase 6 — enumerate every Matrix field in the project + the block-type
     * entry handles each Matrix accepts. Drives the page-part LLM proposer
     * (proposePagePartBlocks) so the AI picks (matrixField, blockType) pairs
     * that actually exist together.
     *
     * @return array<string, list<string>>  matrixFieldHandle → list of blockType handles
     */
    public function matrixFieldCatalog(): array
    {
        $out = [];
        foreach (Craft::$app->fields->getAllFields() as $field) {
            if (!($field instanceof \craft\fields\Matrix)) {
                continue;
            }
            $handle = (string) $field->handle;
            if ($handle === '') {
                continue;
            }
            $blocks = [];
            foreach ($field->getEntryTypes() as $bt) {
                $bh = (string) $bt->handle;
                if ($bh !== '') {
                    $blocks[] = $bh;
                }
            }
            // Multiple Matrix fields can share a handle across different entry types
            // (rare but possible) — merge their block-type lists.
            $out[$handle] = array_values(array_unique(array_merge(
                $out[$handle] ?? [],
                $blocks,
            )));
        }
        ksort($out);
        return $out;
    }

    /**
     * Render the Matrix catalog as LLM-prompt markdown. Keep it tight — one
     * line per (matrixField → blockTypes) pair so prompt token cost stays
     * bounded as projects grow.
     */
    public function renderMatrixCatalogMarkdown(): string
    {
        $catalog = $this->matrixFieldCatalog();
        if ($catalog === []) {
            return '_No Matrix fields configured in this Craft install._';
        }
        $out = [];
        $out[] = '# Craft Matrix-field block catalog';
        $out[] = '';
        $out[] = '_For page-part proposals: pick a `targetMatrixField` and a `targetBlockType` from the same row._';
        $out[] = '';
        foreach ($catalog as $matrixHandle => $blocks) {
            sort($blocks);
            $out[] = sprintf('- **%s**: %s', $matrixHandle, implode(', ', $blocks));
        }
        return implode("\n", $out);
    }

    /**
     * Phase 8.6 — entry-type → owned Matrix-field handles lookup.
     *
     * Walks the entry-type's field layout and returns every Matrix field handle
     * the entry-type's layout actually contains. The page-part LLM proposer
     * uses this to constrain the closed-set catalog per-row: when proposing a
     * (matrixField, blockType) pair for a page-part whose parent page lives on
     * entry-type X, the only legal Matrix fields are those X's layout owns.
     *
     * Without this scoping, 8.3's auto-resolve could route a page's page-parts
     * into a *different* Matrix that happens to share a block-type handle
     * (CQM symptom: HomePage → pageBuilderCondensed instead of pageBuilder
     * because both Matrices include `textContentBlock`).
     *
     * Returns an empty list when the entry-type has no field layout, no
     * Matrix fields, or doesn't exist on the project. Empty list = "no
     * scoping known" — caller falls back to the unconstrained catalog.
     *
     * @return list<string>  matrix-field handles owned by the entry-type
     */
    public function matrixFieldsForEntryType(string $entryTypeHandle): array
    {
        if ($entryTypeHandle === '') {
            return [];
        }
        $out = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
            if ((string) $entryType->handle !== $entryTypeHandle) {
                continue;
            }
            $fieldLayout = $entryType->getFieldLayout();
            if ($fieldLayout === null) {
                return [];
            }
            foreach ($fieldLayout->getCustomFields() as $field) {
                if (!($field instanceof \craft\fields\Matrix)) {
                    continue;
                }
                $h = (string) $field->handle;
                if ($h !== '' && !in_array($h, $out, true)) {
                    $out[] = $h;
                }
            }
            break; // entry-type handle is unique within the project
        }
        return $out;
    }

    /**
     * Flat list of all block-type handles across every Matrix field. Used by
     * the page-part LLM proposer's closed-set validation (the LLM may not
     * invent block-type handles).
     *
     * @return list<string>
     */
    public function allBlockTypeHandles(): array
    {
        $catalog = $this->matrixFieldCatalog();
        $out = [];
        foreach ($catalog as $blocks) {
            foreach ($blocks as $b) {
                $out[] = $b;
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /**
     * Phase 8.6 / D-28 — describe a Craft field with enough type-specific
     * metadata that the LLM proposer can avoid plausible-name-matches that
     * fail at handler-time.
     *
     * Per-type meta keys (omitted when not applicable):
     *   - Dropdown    → `options: [...]`           (the values the field accepts;
     *                                                 free-text source values must
     *                                                 match one of these or the
     *                                                 mapping won't stick)
     *   - Matrix      → `allowedBlockTypes: [...]` (entry-type handles)
     *   - Entries     → `sources: [...]`           (section handles the relation
     *                                                 may target — drives
     *                                                 stateSource picks for the
     *                                                 relation handler)
     *   - Assets      → `allowedKinds: [...]`      ("image" / "video" / etc.)
     *
     * Other field types (PlainText, CKEditor, Date, Lightswitch, Number, …) get
     * just `{handle, type}` — those have no closed-set metadata that needs
     * surfacing.
     *
     * @return array{handle: string, type: string, options?: list<string>, allowedBlockTypes?: list<string>, blocks?: array<string, array{fields: list<string>}>, sources?: list<string>, allowedKinds?: list<string>}
     */
    private function describeField(\craft\base\FieldInterface $field): array
    {
        $out = [
            'handle' => (string) $field->handle,
            'type'   => $this->shortClassName(get_class($field)),
        ];

        if ($field instanceof \craft\fields\Dropdown) {
            $opts = [];
            foreach ((array) $field->options as $opt) {
                if (!is_array($opt)) { continue; }
                $val = (string) ($opt['value'] ?? '');
                if ($val !== '') {
                    $opts[] = $val;
                }
            }
            if ($opts !== []) {
                $out['options'] = $opts;
            }
        }

        if ($field instanceof \craft\fields\Matrix) {
            $blocks = [];
            $blockSchema = [];
            foreach ($field->getEntryTypes() as $bt) {
                $bh = (string) $bt->handle;
                if ($bh !== '') {
                    $blocks[] = $bh;
                }
                $layout = $bt->getFieldLayout();
                if ($bh !== '' && $layout !== null) {
                    $subFields = [];
                    foreach ($layout->getCustomFields() as $subField) {
                        $subHandle = (string) $subField->handle;
                        if ($subHandle !== '') {
                            $subFields[] = $subHandle;
                        }
                    }
                    $blockSchema[$bh] = ['fields' => array_values(array_unique($subFields))];
                }
            }
            if ($blocks !== []) {
                $out['allowedBlockTypes'] = $blocks;
            }
            if ($blockSchema !== []) {
                $out['blocks'] = $blockSchema;
            }
        }

        if ($field instanceof \craft\fields\Entries) {
            // Entries field's `sources` is either '*' (any) or a list of
            // `section:<uid>` strings. Convert UIDs to section handles when
            // possible so the LLM can choose a stateSource that matches the
            // migrated section.
            $rawSources = $field->sources;
            $sources = [];
            if (is_array($rawSources)) {
                foreach ($rawSources as $src) {
                    if (!is_string($src)) { continue; }
                    if (str_starts_with($src, 'section:')) {
                        $uid = substr($src, strlen('section:'));
                        $section = \Craft::$app->entries->getSectionByUid($uid);
                        if ($section !== null && $section->handle !== '') {
                            $sources[] = $section->handle;
                        }
                    } elseif ($src !== '*' && $src !== '') {
                        $sources[] = $src;
                    }
                }
            }
            if ($sources !== []) {
                $out['sources'] = $sources;
            }
        }

        if ($field instanceof \craft\fields\Assets) {
            $kinds = is_array($field->allowedKinds) ? array_values($field->allowedKinds) : [];
            $kinds = array_filter($kinds, static fn($k): bool => is_string($k) && $k !== '');
            if ($kinds !== []) {
                $out['allowedKinds'] = array_values($kinds);
            }
        }

        return $out;
    }

    /** Trim Craft\\fields\\PlainText → PlainText for compact prompt formatting. */
    private function shortClassName(string $fqcn): string
    {
        $tail = strrchr($fqcn, '\\');
        return $tail !== false ? substr($tail, 1) : $fqcn;
    }
}
