<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\finalize;

use lameco\kunstmaanmigrator\filter\MigrationFilters;
use lameco\kunstmaanmigrator\filter\MappingFilterTranslator;
use lameco\kunstmaanmigrator\Plugin;
use Craft;
use craft\db\Query;
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
     * Kunstmaan placeholder probe — `[NT123]` (NodeTranslation internal-link)
     * or `[M456]` (Media reference). The rewriter's regexes are stricter
     * (anchor + boundary), this probe just answers "does this string
     * contain enough of a placeholder to be worth running through the
     * rewriter?". Keep cheap — we test it against EVERY string field on
     * EVERY migrated entry, including matrix blocks.
     */
    private const PLACEHOLDER_PROBE = '/\[(?:NT|M)\d+\]|\{entry:\d+@\d+:url\}/';

    /**
     * Second finalize pass — placeholder rewriting in NON-CKEditor string
     * fields. The primary `walk()` only touches `craft\ckeditor\Field`
     * instances, so PlainText fields carrying `[NT<id>]` / `[M<id>]`
     * placeholders (Lameco's footer-link `linkPagePart.url` is the
     * canonical example — Kunstmaan source stored these as plain text
     * with a render-time `replace_url` filter) leak through unresolved.
     *
     * Coverage difference vs walk():
     *   - walk(): top-level entries only (Entry::find() default), CKEditor
     *     fields only.
     *   - walkPlaceholders(): top-level + matrix-block entries (queried
     *     via direct SQL against `elements_sites.content` placeholder
     *     LIKE), every NON-CKEditor field (avoids double-rewriting
     *     CKEditor fields walk() already handled).
     *
     * Conservative: only rewrites a field's value when it actually
     * matches PLACEHOLDER_PROBE. Plain strings without placeholders are
     * untouched — no risk of false-positive rewrite on legitimate text.
     *
     * @return array{processed: int, rewritten: int, unresolvable: int, unresolvedDiagnostics: list<array<string, mixed>>, outOfScopeDiagnostics: list<array<string, mixed>>}
     */
    public function walkPlaceholders(MigrationFilters $filters, ?callable $onProgress = null): array
    {
        if ($this->rewriter === null) {
            throw new \RuntimeException(
                'FinalizeWalker: $rewriter (CkeditorRewriterService) is not wired.',
            );
        }

        $processed = 0;
        $rewritten = 0;
        $unresolvable = 0;
        $unresolvedDiagnostics = [];
        $outOfScopeDiagnostics = [];

        // Walk-time cache reset is owned by walk(); calling it again here
        // would purge warmed lookups mid-pipeline. The per-rewrite cache
        // hits are valid across this second pass anyway.

        // Direct SQL — find every (elementId, siteId) whose content JSON
        // contains a placeholder fragment. LIKE is cheap on indexed
        // longtext, and the probe is intentionally over-inclusive:
        // false positives (e.g. content with literal `[M` in unrelated
        // text) are filtered out per-field below by the strict
        // PLACEHOLDER_PROBE regex.
        // INNER JOIN against `elements` so we only see live, canonical
        // elements — exclude soft-deleted (`dateDeleted IS NOT NULL`),
        // drafts, and revisions. Without this filter, rows from prior
        // migration runs (truncated entries whose elements_sites
        // hadn't been GC'd) flood the candidate set, and
        // `getElementById` returns null for every one of them
        // (Craft excludes deleted+revision by default). On the dewert
        // smoke target 283 raw candidates → 40 live ones (244 leftover
        // from earlier truncate-then-rerun cycles).
        $candidates = (new Query())
            ->select(['es.elementId', 'es.siteId'])
            ->from(['es' => '{{%elements_sites}}'])
            ->innerJoin(['el' => '{{%elements}}'], 'el.id = es.elementId')
            ->where([
                'and',
                ['or',
                    ['like', 'es.content', '[NT'],
                    ['like', 'es.content', '[M'],
                    // {entry:N@S:url} tokens persist from prior finalize
                    // pass that ran BEFORE this method post-processed them
                    // to URLs. Re-running resolves those too.
                    ['like', 'es.content', '{entry:'],
                ],
                ['el.dateDeleted' => null],
                ['el.draftId' => null],
                ['el.revisionId' => null],
            ])
            ->all();

        $progressTotal = count($candidates);
        $progressDone = 0;

        foreach ($candidates as $row) {
            $elementId = (int) ($row['elementId'] ?? 0);
            $siteId = (int) ($row['siteId'] ?? 0);
            if ($elementId <= 0 || $siteId <= 0) {
                $progressDone++;
                continue;
            }

            // Element::find via getElementById handles BOTH top-level
            // entries (sectionId IS NOT NULL) AND nested matrix blocks
            // (primaryOwnerId IS NOT NULL) without scoping.
            $element = Craft::$app->elements->getElementById($elementId, null, $siteId);
            if (!($element instanceof Entry)) {
                $progressDone++;
                continue;
            }
            $fieldLayout = $element->getFieldLayout();
            if ($fieldLayout === null) {
                $progressDone++;
                continue;
            }

            $entryDirty = false;
            foreach ($fieldLayout->getCustomFields() as $field) {
                // Skip CKEditor fields — walk() handles them. Avoids
                // double-rewriting (which would be idempotent but wasteful).
                if (class_exists(\craft\ckeditor\Field::class, true)
                    && $field instanceof \craft\ckeditor\Field
                ) {
                    continue;
                }

                $value = $element->getFieldValue($field->handle);
                if (!is_scalar($value) && $value !== null) {
                    // Skip array/object field values (Asset queries, Entry
                    // queries, Matrix queries). The placeholder is a
                    // string-shape concern; structured field values
                    // already resolve via state map.
                    continue;
                }
                $current = (string) ($value ?? '');
                if ($current === '' || preg_match(self::PLACEHOLDER_PROBE, $current) !== 1) {
                    continue;
                }

                $processed++;
                $rewritten_value = $this->rewriter->rewrite($current, $siteId);

                // Critical: rewriter emits Craft's `{entry:ID@SITE:url}`
                // reference tokens. Craft's reference-token resolver runs
                // ONLY for HTML/CKEditor field rendering — PlainText
                // fields would render the token verbatim (`href="{entry:...}"`).
                // For non-CKEditor scope we need actual URL strings, so
                // post-process every `{entry:...:url}` to its resolved URL.
                $rewritten_value = $this->resolveEntryRefTokensToUrls($rewritten_value);

                foreach ($this->rewriter->consumeUnresolvedDiagnostics() as $diagnostic) {
                    $unresolvedDiagnostics[] = [
                        'entryId' => $elementId,
                        'siteId' => $siteId,
                        'fieldHandle' => (string) $field->handle,
                        'source' => 'FinalizeWalker::walkPlaceholders',
                    ] + $diagnostic;
                }
                foreach ($this->rewriter->consumeOutOfScopeDiagnostics() as $diagnostic) {
                    $outOfScopeDiagnostics[] = [
                        'entryId' => $elementId,
                        'siteId' => $siteId,
                        'fieldHandle' => (string) $field->handle,
                        'source' => 'FinalizeWalker::walkPlaceholders',
                    ] + $diagnostic;
                }

                if (str_contains($rewritten_value, '<!-- MIGRATION:UNRESOLVED')) {
                    $unresolvable++;
                }
                if ($rewritten_value === $current) {
                    continue;
                }

                $element->setFieldValue($field->handle, $rewritten_value);
                $entryDirty = true;
                $rewritten++;
            }

            if ($entryDirty) {
                $ok = Craft::$app->elements->saveElement($element, true, false);
                if (!$ok) {
                    $errors = $element->getFirstErrors();
                    $first = is_array($errors) && !empty($errors) ? (string) reset($errors) : '(no validation error)';
                    throw new \RuntimeException(sprintf(
                        'FinalizeWalker::walkPlaceholders: saveElement failed for element id=%d siteId=%d: %s',
                        $elementId,
                        $siteId,
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
     * Resolve Craft `{entry:ID@SITE:url}` reference tokens to actual URL
     * strings. Used in `walkPlaceholders` after the rewriter, because
     * PlainText fields don't trigger Craft's reference-token resolver
     * (it runs at HTML render time on rich-text fields only). Without
     * this post-process, `linkPagePart.url` would store the literal
     * `{entry:14198@1:url}` token and templates would emit
     * `href="{entry:14198@1:url}"` verbatim.
     *
     * Token shape per CkeditorRewriterService: `{entry:<id>@<siteId>:url}`.
     * Variant lookups (`:title`, etc.) aren't emitted by the rewriter,
     * so this method only handles `:url`. Tokens that fail resolution
     * (entry deleted between rewrite and resolve) collapse to `'#'` —
     * the same fallback the rewriter uses for `[M]`/`[NT]` misses.
     */
    private function resolveEntryRefTokensToUrls(string $value): string
    {
        if (!str_contains($value, '{entry:')) {
            return $value;
        }
        return (string) preg_replace_callback(
            '/\{entry:(\d+)@(\d+):url\}/',
            static function (array $m): string {
                $entryId = (int) $m[1];
                $siteId = (int) $m[2];
                $entry = Craft::$app->entries->getEntryById($entryId, $siteId);
                if ($entry === null) {
                    return '#';
                }
                $url = (string) ($entry->getUrl() ?? '#');
                return $url !== '' ? $url : '#';
            },
            $value,
        );
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
