<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\finalize;

use Craft;
use craft\ckeditor\Field as CkeditorField;
use craft\db\Query;
use craft\elements\Entry;
use lameco\kunstmaanmigrator\craft\CraftElementWriter;
use lameco\kunstmaanmigrator\craft\ElementWriter;
use lameco\kunstmaanmigrator\load\MigrationOptions;
use lameco\kunstmaanmigrator\load\MigrationReport;
use Throwable;

/**
 * The finalize pass: rewrite legacy references left in migrated rich text.
 *
 * `CkeditorRewriterService::rewrite()` has always known how to do this — legacy
 * `<img src="/uploads/media/...">`, `[M<id>]` media placeholders and `[NT<id>]` internal links —
 * and nothing ever called it. The load path deliberately calls only the narrow
 * `rewriteCurlyMediaTokens()`, because a body that merely shares a paragraph with a media token
 * must not also get `kma-*` class stripping and link resolution for free. So the full pipeline was
 * always meant to run afterwards, as its own pass. This is that pass.
 *
 * It runs last for a reason: `[NT<id>]` resolves a legacy node translation to the entry it became,
 * and `/uploads/media/...` resolves to a migrated asset. Neither can be answered until the entries
 * and assets are in the state table.
 *
 * KNOWN GAP — media URLs resolve only when the asset was already migrated. Internal `[NT<id>]` links do: a real run over the
 * Enreach COM corpus rewrote 863 of them into `{entry:...}` tokens. Image references resolve to
 * `{asset:...}` only when the asset already exists in state — 24 of 177 on that corpus.
 *
 * What is left needs the asset service's media roots, which this pass does not configure.
 * `AssetMigrationService::resolveFromLegacyUrl()` looks the URL up by `legacy_url:sha1(path)`,
 * and when there is no such row it falls back to *ingesting* the file from `mediaRoot()` and the
 * configured fallback roots. The main migrate flow sets those per environment; a `--finalize-only`
 * run does not, so a reference to media that no payload happened to pull in cannot be materialised
 * — even though the row is right there in `kuma_media` (spot-checked: ids 1384, 1656-1659, all
 * `deleted = 0`). Wiring the media roots into this pass is the next step. See HANDOVER.md.
 *
 * Candidates are found by content rather than by walking ownership. A migrated page's rich text
 * can sit on the entry, on a nested block, or on a block nested inside that — but wherever it
 * lives, it is an `elements_sites` row whose content still carries a legacy marker. Asking the
 * database that question directly is both cheaper and less fragile than recursing through Matrix
 * fields looking for something that might not be there.
 */
final class CkeditorFinalizeService
{
    /**
     * The seam at Craft's element writes. Wired in Plugin::init(); read
     * through elements() so no call site has to cope with "not wired yet".
     */
    public ?ElementWriter $elementWriter = null;

    public CkeditorRewriterService $rewriter;

    /** Markers the rewriter knows how to resolve. Used to shortlist rows worth loading. */
    private const MARKERS = [
        '/uploads/media/',
        '[NT',
        '%5BNT',
        '[M',
    ];

    public function __construct(?CkeditorRewriterService $rewriter = null)
    {
        if ($rewriter !== null) {
            $this->rewriter = $rewriter;
        }
    }

    private function elements(): ElementWriter
    {
        return $this->elementWriter ??= new CraftElementWriter();
    }

    public function run(MigrationOptions $opts, ?MigrationReport $report = null): MigrationReport
    {
        $report ??= new MigrationReport();

        $ckeditorFieldIds = $this->ckeditorFieldUids();

        if ($ckeditorFieldIds === []) {
            $report->warn('finalize: no CKEditor fields exist on this install; nothing to rewrite.');

            return $report;
        }

        foreach ($this->candidates() as $siteId => $elementIds) {
            foreach ($elementIds as $elementId) {
                try {
                    $this->finalizeElement((int) $elementId, (int) $siteId, $ckeditorFieldIds, $opts, $report);
                } catch (Throwable $e) {
                    $report->incr('failed');
                    $report->warn(sprintf(
                        'finalize: element %d site %d — %s',
                        $elementId,
                        $siteId,
                        $e->getMessage(),
                    ));
                }
            }
        }

        return $report;
    }

    /**
     * Element ids per site whose stored content still carries a legacy marker.
     *
     * @return array<int, list<int>>
     */
    private function candidates(): array
    {
        $query = (new Query())
            ->select(['elementId', 'siteId'])
            ->from(['{{%elements_sites}}']);

        $conditions = ['or'];

        foreach (self::MARKERS as $marker) {
            // No fourth operand: Yii's `false` there means "match the value literally", which
            // turns a contains-check into an equality-check and finds nothing.
            $conditions[] = ['like', 'content', $marker];
        }

        $query->where($conditions);

        $out = [];

        foreach ($query->all() as $row) {
            $out[(int) $row['siteId']][] = (int) $row['elementId'];
        }

        return $out;
    }

    /** @return list<string> field uids */
    private function ckeditorFieldUids(): array
    {
        $uids = [];

        foreach (Craft::$app->fields->getAllFields() as $field) {
            if ($field instanceof CkeditorField) {
                $uids[] = $field->handle;
            }
        }

        return $uids;
    }

    /**
     * @param list<string> $ckeditorHandles
     */
    private function finalizeElement(
        int $elementId,
        int $siteId,
        array $ckeditorHandles,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void {
        /** @var Entry|null $element */
        $element = Entry::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(false)
            ->one();

        if ($element === null) {
            return;
        }

        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return;
        }

        $changed = false;

        foreach ($layout->getCustomFields() as $field) {
            // `instanceof` only. Craft 5 field *instances* may override the handle when a field
            // is placed on a layout, so the handle here is the instance's — `content`, say —
            // and never matches the global field handle. Filtering on that finds nothing.
            if (!$field instanceof CkeditorField) {
                continue;
            }

            $value = $element->getFieldValue($field->handle);
            $html = $value === null ? '' : (string) $value;

            if ($html === '' || !$this->carriesMarker($html)) {
                continue;
            }


            $rewritten = $this->rewriter->rewrite($html, $siteId);

            foreach ($this->rewriter->consumeUnresolvedDiagnostics() as $diagnostic) {
                $report->warn(sprintf(
                    'finalize: unresolved %s on element %d field %s site %d',
                    (string) ($diagnostic['tokenFamily'] ?? 'reference'),
                    $elementId,
                    $field->handle,
                    $siteId,
                ));
            }

            if ($rewritten === $html) {
                continue;
            }

            $element->setFieldValue($field->handle, $rewritten);
            $changed = true;
            $report->incr('rewritten');
        }

        if (!$changed || $opts->dryRun) {
            return;
        }

        // Save scoped to this site. A nested block is saved in its own right rather than through
        // its owner: re-saving the owner would put its whole Matrix through another write for the
        // sake of one string, and Matrix maintenance is exactly the machinery this migration has
        // had the most trouble with.
        $element->resaving = true;

        if (!$this->elements()->save($element)) {
            $report->incr('failed');
            $report->warn(sprintf(
                'finalize: save failed for element %d site %d — %s',
                $elementId,
                $siteId,
                json_encode($element->getErrors()),
            ));

            return;
        }

        $report->incr('saved');
    }

    private function carriesMarker(string $html): bool
    {
        foreach (self::MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        return false;
    }
}
