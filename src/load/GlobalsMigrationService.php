<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Craft;
use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\adapters\GatedAdapter;
use Lameco\Kunstmaanmigrator\adapters\MigrationAdapter;
use Lameco\Kunstmaanmigrator\Compile\GlobalsCompiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\craft\NavigationGateway;
use Lameco\Kunstmaanmigrator\craft\VerbbNavigationGateway;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Throwable;
use verbb\navigation\elements\Node as NavNode;
use yii\base\Component;

/**
 * The `globals:` lane: site-wide pageparts that belong to no single page.
 *
 * Validated by `Schema` since the DSL was written and read by nothing, so 169
 * placements of footer content had no destination and the mapping's own note
 * said "Targets unresolved — globalSettings fields vs. the navigation plugin".
 *
 * The answer is the navigation plugin, and the mapping now says so per context
 * rather than this class deciding: `globalSettings` carries three scalars — a
 * logo, a phone number, an email — and this lane is titled columns of links,
 * which is a shape only a nav can hold. Which context lands in which nav is a
 * content decision and belongs in the reviewable file next to the field
 * mappings, not in PHP.
 */
class GlobalsMigrationService extends Component implements MigrationAdapter
{
    use GatedAdapter;

    private const STATE_SOURCE = 'global';

    /** Kunstmaan writes an internal link as `[NT<node translation id>]`. */
    private const INTERNAL_LINK = '/^\[NT(\d+)\]$/';

    public ?MigrationStateService $stateService = null;

    public ?ElementWriter $elementWriter = null;

    public ?NavigationGateway $navigationGateway = null;

    public function handle(): string
    {
        return 'globals';
    }

    private function elements(): ElementWriter
    {
        return $this->elementWriter ??= new CraftElementWriter();
    }

    private function navigation(): NavigationGateway
    {
        return $this->navigationGateway ??= new VerbbNavigationGateway();
    }

    public function migrateAll(MigrationOptions $opts, EnvironmentContext $context): MigrationReport
    {
        $report = new MigrationReport();

        if (!$this->isGateOpen($report)) {
            return $report;
        }

        if ($context->mapping === null || $context->legacy === null) {
            $report->warn('The globals lane compiles from the mapping and reads the legacy database; one of them was not supplied.');

            return $report;
        }

        if (!$this->navigation()->isAvailable()) {
            $report->warn('verbb/navigation is not installed; no global navigation was written.');

            return $report;
        }

        $localeToSiteId = $context->sites->localeToSiteId();
        $compiler = new GlobalsCompiler(
            $context->mapping,
            new Transforms($context->mapping->all()['transforms'] ?? []),
        );

        $records = [];
        $compiler->compile($context->legacy, $context->name, static function(array $r) use (&$records): void {
            $records[] = $r;
        });

        foreach ($compiler->skipped() as $reason => $count) {
            $report->warn(sprintf('%d skipped: %s', $count, $reason));
        }

        foreach ($records as $record) {
            $this->loadRecord($record, $localeToSiteId, $opts, $context, $report);
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, int>   $localeToSiteId
     */
    private function loadRecord(
        array $record,
        array $localeToSiteId,
        MigrationOptions $opts,
        EnvironmentContext $context,
        MigrationReport $report,
    ): void {
        $locale = (string) ($record['locale'] ?? '');
        $siteId = $localeToSiteId[$locale] ?? null;

        if ($siteId === null) {
            // A locale the mapping deliberately does not migrate. Counted, not
            // warned: `sp` and `ru` are declared non-goals with a reason.
            $report->incr('skipped');

            return;
        }

        $navHandle = str_starts_with((string) $record['target'], 'nav:')
            ? substr((string) $record['target'], 4)
            : '';
        $navId = $navHandle === '' ? null : $this->navigation()->navIdByHandle($navHandle);

        if ($navId === null) {
            $report->incr('failed');
            $report->warn(sprintf('No nav "%s" for context %s.', $navHandle, (string) $record['context']));

            return;
        }

        $report->incr('compiled');

        if ($opts->dryRun) {
            return;
        }

        $parentId = $this->upsertNode($record, $navId, $siteId, null, $opts, $context, $report);

        if ($parentId === null) {
            return;
        }

        foreach ((array) ($record['children'] ?? []) as $child) {
            $child['context'] = $record['context'];
            $this->upsertNode($child, $navId, $siteId, $parentId, $opts, $context, $report);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function upsertNode(
        array $record,
        int $navId,
        int $siteId,
        ?int $parentId,
        MigrationOptions $opts,
        EnvironmentContext $context,
        MigrationReport $report,
    ): ?int {
        $sourceUid = sprintf('%s@%d', (string) $record['sourceUid'], $siteId);
        $existingId = $this->stateService?->getTargetId(self::STATE_SOURCE, $sourceUid, $siteId);

        if ($existingId !== null && !$opts->force) {
            return $existingId;
        }

        $node = $existingId === null
            ? null
            : $this->elements()->findById($existingId, NavNode::class, $siteId);
        $node ??= new NavNode();

        $node->navId = $navId;
        $node->siteId = $siteId;
        $node->title = (string) ($record['title'] ?? '') ?: 'Untitled';
        $node->newWindow = (bool) ($record['newWindow'] ?? false);
        $node->enabled = true;

        $url = (string) ($record['url'] ?? '');
        $entryId = $this->entryFor($url, $context);

        if ($entryId !== null) {
            // An internal link resolves to the entry it became, so the nav follows
            // the page if it is ever moved — which is the whole reason to relate
            // rather than to store a path.
            $node->type = Entry::class;
            $node->elementId = $entryId;
            $node->url = null;
        } else {
            $node->type = null;
            $node->elementId = null;
            $node->url = $url !== '' ? $url : '#';

            if (preg_match(self::INTERNAL_LINK, $url) === 1) {
                $report->warn(sprintf('%s: %s resolves to no migrated entry; kept as a literal URL.', $sourceUid, $url));
            }
        }

        if ($parentId !== null) {
            $parent = $this->elements()->findById($parentId, NavNode::class, $siteId);

            if ($parent instanceof NavNode) {
                // Verbb reads a node's parent from its temp registry rather than
                // from the database during a request, so a child saved without
                // this lands at the root of the nav.
                $this->navigation()->registerTempNodes([$parent]);
                $node->setParent($parent);
            }
        }

        try {
            if (!$this->elements()->save($node)) {
                $report->incr('failed');
                $report->warn(sprintf('%s: %s', $sourceUid, implode('; ', $node->getErrorSummary(true))));

                return null;
            }
        } catch (Throwable $e) {
            $report->incr('failed');
            $report->warn(sprintf('%s: %s', $sourceUid, $e->getMessage()));

            return null;
        }

        $this->stateService?->record(
            self::STATE_SOURCE,
            $sourceUid,
            'nav_node',
            (int) $node->id,
            null,
            $siteId,
            ['context' => (string) ($record['context'] ?? ''), 'nav' => $navId],
        );

        $report->incr($existingId === null ? 'created' : 'updated');

        return (int) $node->id;
    }

    /**
     * The Craft entry a Kunstmaan `[NT<id>]` link points at.
     *
     * One node is one entry, recorded as `<ENV>:kuma_nodes` keyed by node id —
     * the same identity NavigationMigrationService resolves against, because
     * two lanes disagreeing about what a node became is how navigation once
     * migrated zero nodes while reporting no failure.
     */
    private function entryFor(string $url, EnvironmentContext $context): ?int
    {
        if (preg_match(self::INTERNAL_LINK, $url, $m) !== 1 || $context->legacy === null) {
            return null;
        }

        try {
            $statement = $context->legacy->pdo()->prepare(
                'SELECT node_id FROM kuma_node_translations WHERE id = ?'
            );
            $statement->execute([(int) $m[1]]);
            $nodeId = $statement->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        if ($nodeId === false || $nodeId === null) {
            return null;
        }

        return $this->stateService?->getTargetId(
            sprintf('%s:kuma_nodes', $context->name),
            (string) (int) $nodeId,
            null,
        );
    }
}
