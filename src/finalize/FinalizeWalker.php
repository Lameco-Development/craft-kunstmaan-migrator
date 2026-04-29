<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\finalize;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\filter\MappingFilterTranslator;
use lameco\kunstmaanmigrator\Plugin;
use Craft;
use craft\elements\Entry;
use yii\base\Component;

/**
 * Greenfield finalize-pass orchestrator. No v1 analog — v1 did inline rewriting only via
 * PlainTextHandler 'ckeditor' mode. FIN-01 mandates a finalize-time pass that walks every
 * CKEditor field across every migrated entry; this class is that pass.
 *
 * Designed at Phase 3 / Plan 03-06 per PATTERNS.md "No Analog Found" section.
 *
 * Discipline:
 *  - propagate=false on every save: each (entry, siteId) pair is the unit of work, the same
 *    multi-site discipline EntryMigrationService preserves (Plan 03-08). The walker MUST NOT
 *    use Craft's auto-propagation — propagation already happened during the load pass.
 *  - Idempotent re-run: if the rewriter output equals the current value the entry is not
 *    saved (diff guard). Running walk() a second time is a no-op when nothing has changed.
 *  - No swallowed exceptions: a saveElement() failure throws \RuntimeException with the
 *    entry id + site id + first validation error; MigrateController emits to REPORT.md.
 *
 * @see CkeditorRewriterService
 */
final class FinalizeWalker extends Component
{
    public ?CkeditorRewriterService $rewriter = null;

    /**
     * Walk every migrated Craft entry, find CKEditor fields, call $rewriter->rewrite() with the
     * cross-entry caches now warmed (since all referenced entries exist after the load pass).
     * Persists via Craft::$app->elements->saveElement(propagate: false) to honor
     * EntryMigrationService's multi-site discipline (saved per (entry, siteId) pair).
     *
     * Returns counts: processed = total CKEditor field reads; rewritten = fields whose value
     * changed; unresolvable = fields that emitted at least one <!-- MIGRATION:UNRESOLVED ... -->
     * comment after rewriting.
     *
     * @param  (callable(int $done, int $total): void)|null $onProgress
     *         Optional progress callback fired once per entry walked. `$total` is the
     *         pre-counted (entry, site) pair count from `$query->count()`. Null skips
     *         emission (test-path silent behaviour).
     * @return array{processed: int, rewritten: int, unresolvable: int, unresolvedDiagnostics: list<array<string, mixed>>, outOfScopeDiagnostics: list<array<string, mixed>>}
     */
    public function walk(MigrationFilters $filters, ?callable $onProgress = null): array
    {
        if ($this->rewriter === null) {
            throw new \RuntimeException(
                'FinalizeWalker: $rewriter (CkeditorRewriterService) is not wired. '
                . 'Plugin::init() (Plan 03-14) is responsible for sibling-DI wiring.',
            );
        }

        $processed = 0;
        $rewritten = 0;
        $unresolvable = 0;
        $unresolvedDiagnostics = [];
        $outOfScopeDiagnostics = [];

        $this->rewriter->resetLookupCaches();

        // Build entries query — wildcard site id walks every (entry, site) pair so the walker
        // can rewrite per-site values independently. The entries returned are duplicated
        // across sites (one Entry instance per site), which is what we want — propagate=false
        // saves each per-site entry as its own unit of work.
        $query = Entry::find()->siteId('*');

        // Optional Craft scope from source-domain entity filters. D-17/09-02B:
        // translate Kunstmaan FQCN/basename filters through compiled mapping
        // before touching Craft query surfaces.
        $translatedScope = $this->loadTranslatedScopeForEntityFilters($filters);
        if ($translatedScope['sectionHandles'] !== []) {
            $query->section($translatedScope['sectionHandles']);
        }
        if ($translatedScope['entryTypeHandles'] !== []) {
            $query->type($translatedScope['entryTypeHandles']);
        }

        // Pre-count for the progress callback. Skipped when no callback is wired so
        // the no-progress path stays a single COUNT query lighter.
        $progressTotal = $onProgress !== null ? (int) $query->count() : 0;
        $progressDone = 0;

        foreach ($query->each(50) as $entry) {
            /** @var Entry $entry */
            $fieldLayout = $entry->getFieldLayout();
            if ($fieldLayout === null) {
                continue;
            }

            $entryDirty = false;

            foreach ($fieldLayout->getCustomFields() as $field) {
                // Detect CKEditor field by class — class_exists guard avoids a hard composer
                // dep at PHP-lint time. craftcms/ckeditor is required at runtime when any
                // migrated entry actually carries a CKEditor field.
                if (!class_exists(\craft\ckeditor\Field::class, true)) {
                    continue;
                }
                if (!($field instanceof \craft\ckeditor\Field)) {
                    continue;
                }

                $processed++;

                // CKEditor field values in Craft 5 are \craft\ckeditor\data\FieldData
                // objects, not strings. Cast to string for the rewriter input.
                $current = (string) $entry->getFieldValue($field->handle);
                if ($current === '') {
                    continue;
                }

                $rewrittenHtml = $this->rewriter->rewrite($current, $entry->siteId);
                foreach ($this->rewriter->consumeUnresolvedDiagnostics() as $diagnostic) {
                    $unresolvedDiagnostics[] = [
                        'entryId' => (int) $entry->id,
                        'siteId' => (int) $entry->siteId,
                        'fieldHandle' => (string) $field->handle,
                        'source' => 'FinalizeWalker',
                    ] + $diagnostic;
                }
                foreach ($this->rewriter->consumeOutOfScopeDiagnostics() as $diagnostic) {
                    $outOfScopeDiagnostics[] = [
                        'entryId' => (int) $entry->id,
                        'siteId' => (int) $entry->siteId,
                        'fieldHandle' => (string) $field->handle,
                        'source' => 'FinalizeWalker',
                    ] + $diagnostic;
                }

                if (str_contains($rewrittenHtml, '<!-- MIGRATION:UNRESOLVED')) {
                    $unresolvable++;
                }

                if ($rewrittenHtml === $current) {
                    // Idempotent re-run: nothing changed, no save needed.
                    continue;
                }

                $entry->setFieldValue($field->handle, $rewrittenHtml);
                $entryDirty = true;
                $rewritten++;
            }

            if ($entryDirty) {
                // propagate=false discipline: per-site save only — propagation already happened
                // during the load pass (EntryMigrationService).
                $ok = Craft::$app->elements->saveElement($entry, true, false);
                if (!$ok) {
                    $errors = $entry->getFirstErrors();
                    $first = is_array($errors) && !empty($errors) ? (string) reset($errors) : '(no validation error)';
                    throw new \RuntimeException(sprintf(
                        'FinalizeWalker: saveElement failed for entry id=%d siteId=%d: %s',
                        (int) $entry->id,
                        (int) $entry->siteId,
                        $first,
                    ));
                }
            }

            $progressDone++;
            if ($onProgress !== null) {
                $onProgress($progressDone, $progressTotal);
            }
        }

        return [
            'processed' => $processed,
            'rewritten' => $rewritten,
            'unresolvable' => $unresolvable,
            'unresolvedDiagnostics' => $unresolvedDiagnostics,
            'outOfScopeDiagnostics' => $outOfScopeDiagnostics,
        ];
    }

    /**
     * @return array{
     *   sectionHandles: list<string>,
     *   entryTypeHandles: list<string>,
     *   unmappedSourceEntities: list<string>
     * }
     */
    private function loadTranslatedScopeForEntityFilters(MigrationFilters $filters): array
    {
        if ($filters->entities === []) {
            return [
                'sectionHandles' => [],
                'entryTypeHandles' => [],
                'unmappedSourceEntities' => [],
            ];
        }

        $plugin = Plugin::getInstance();
        $mappingPath = $plugin->mappingFile->resolvePath();
        if (!is_file($mappingPath)) {
            throw new \RuntimeException(
                'Entity filters require compiled mapping for finalize. Run `./craft kunstmaan-migrator/compile` first.',
            );
        }

        $compiledMapping = $plugin->mappingFile->load($mappingPath);
        if ((array) ($compiledMapping['nodeClasses'] ?? []) === [] || (array) ($compiledMapping['sections'] ?? []) === []) {
            throw new \RuntimeException(
                'Entity filters require compiled mapping nodeClasses/sections for finalize. Run `./craft kunstmaan-migrator/compile` first.',
            );
        }

        $translatedScope = (new MappingFilterTranslator())->translate($compiledMapping, $filters);
        if ($translatedScope['unmappedSourceEntities'] !== []) {
            throw new \RuntimeException(
                'Entity filters are not present in compiled mapping: '
                . implode(', ', $translatedScope['unmappedSourceEntities'])
                . '. Run `./craft kunstmaan-migrator/analyze` and `./craft kunstmaan-migrator/compile`, or adjust --entities.',
            );
        }

        return $translatedScope;
    }
}
