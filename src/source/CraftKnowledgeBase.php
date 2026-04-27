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
 * Sibling of `KnowledgeBase` (which renders the SOURCE Kunstmaan schema). Both
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
                $fields[] = [
                    'handle' => $fhandle,
                    'type'   => $this->shortClassName(get_class($field)),
                ];
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

    /** Trim Craft\\fields\\PlainText → PlainText for compact prompt formatting. */
    private function shortClassName(string $fqcn): string
    {
        $tail = strrchr($fqcn, '\\');
        return $tail !== false ? substr($tail, 1) : $fqcn;
    }
}
