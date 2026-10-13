<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\finalize;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\db\Query;
use craft\fields\data\LinkData;
use craft\fields\Link as LinkField;
use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Throwable;

/**
 * The finalize pass: rewrite legacy references left in migrated CKEditor and Link fields.
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
final class ReferenceFinalizeService
{
    /**
     * The seam at Craft's element writes. Wired in Plugin::init(); read
     * through elements() so no call site has to cope with "not wired yet".
     */
    public ?ElementWriter $elementWriter = null;

    public CkeditorRewriterService $rewriter;

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

        if (!$this->hasRewritableFields()) {
            $report->warn('finalize: no CKEditor or Link fields exist on this install; nothing to rewrite.');

            return $report;
        }

        foreach ($this->candidates() as $siteId => $rows) {
            foreach ($rows as ['id' => $elementId, 'type' => $type]) {
                try {
                    $this->finalizeElement($elementId, $type, $siteId, $opts, $report);
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
     * Elements per site whose stored content still carries a legacy marker, with their type —
     * a global set or a category holds rich text as well as an entry does. Revisions and
     * trashed elements are history, not content, and are left alone.
     *
     * @return array<int, list<array{id: int, type: string}>>
     */
    private function candidates(): array
    {
        $query = (new Query())
            ->select(['es.elementId', 'es.siteId', 'e.type'])
            ->from(['es' => '{{%elements_sites}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[es.elementId]]')
            ->andWhere(['e.revisionId' => null, 'e.dateDeleted' => null]);

        $query->andWhere(LegacyMarkers::likeConditions('es.content'));

        $out = [];

        foreach ($query->all() as $row) {
            $out[(int) $row['siteId']][] = ['id' => (int) $row['elementId'], 'type' => (string) $row['type']];
        }

        return $out;
    }

    /**
     * Whether the install has a field this pass rewrites: CKEditor rich text, or a Link field
     * (a legacy media URL becomes an asset link).
     */
    private function hasRewritableFields(): bool
    {
        foreach (Craft::$app->fields->getAllFields() as $field) {
            if ($field instanceof CkeditorField || $field instanceof LinkField) {
                return true;
            }
        }

        return false;
    }

    /**
     * The element a shortlisted row is, loaded as the type the row names. An element type the
     * install no longer has, or a row that does not load, is warned rather than skipped silently.
     *
     * @param string $type the `elements.type` class
     */
    public function candidate(int $elementId, string $type, int $siteId, MigrationReport $report): ?ElementInterface
    {
        if (!is_a($type, ElementInterface::class, true)) {
            $report->warn(sprintf(
                'finalize: element %d site %d is a %s, which this install cannot load; left as it is',
                $elementId,
                $siteId,
                $type,
            ));

            return null;
        }

        $element = $this->elements()->findById($elementId, $type, $siteId);

        if ($element === null) {
            $report->warn(sprintf('finalize: element %d site %d (%s) could not be loaded; left as it is', $elementId, $siteId, $type));
        }

        return $element;
    }

    private function finalizeElement(
        int $elementId,
        string $type,
        int $siteId,
        MigrationOptions $opts,
        MigrationReport $report,
    ): void {
        $element = $this->candidate($elementId, $type, $siteId, $report);

        if ($element === null) {
            return;
        }

        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return;
        }

        $changed = false;

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof LinkField) {
                $changed = $this->finalizeLink($element, $field, $elementId, $siteId, $report) || $changed;

                continue;
            }

            // `instanceof` only. Craft 5 field *instances* may override the handle when a field
            // is placed on a layout, so the handle here is the instance's — `content`, say —
            // and never matches the global field handle. Filtering on that finds nothing.
            if (!$field instanceof CkeditorField) {
                continue;
            }

            $value = $element->getFieldValue($field->handle);
            $html = $value === null ? '' : (string) $value;

            if ($html === '' || !LegacyMarkers::inText($html)) {
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
        if ($element instanceof Element) {
            $element->resaving = true;
        }

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

    /**
     * A Link field still pointing at a legacy media file becomes an asset link.
     *
     * `link()` writes a legacy button URL as it found it, so a Link field can hold
     * `/uploads/media/…?token=[M<id>]` — 19 of them on Berkvens NL, all dead after the
     * migration. Counted as `linksRewritten`, apart from the rich-text `rewritten`, and a link
     * that cannot be rewritten is warned rather than left behind silently.
     */
    private function finalizeLink(ElementInterface $element, LinkField $field, int $elementId, int $siteId, MigrationReport $report): bool
    {
        $value = $element->getFieldValue($field->handle);

        if (!$value instanceof LinkData) {
            return false;
        }

        $serialized = $value->serialize();
        $rewritten = $this->rewriter->rewriteLink(
            is_array($serialized) ? $serialized : [],
            $siteId,
            array_map(strval(...), array_keys($field->getLinkTypes())),
        );

        foreach ($this->rewriter->consumeUnresolvedDiagnostics() as $diagnostic) {
            $report->warn(sprintf(
                'finalize: legacy media link %s left on element %d field %s site %d — %s',
                (string) ($diagnostic['token'] ?? ''),
                $elementId,
                $field->handle,
                $siteId,
                (string) ($diagnostic['reason'] ?? 'unresolved'),
            ));
        }

        if ($rewritten === null) {
            return false;
        }

        $element->setFieldValue($field->handle, $rewritten);
        $report->incr('linksRewritten');

        return true;
    }
}
