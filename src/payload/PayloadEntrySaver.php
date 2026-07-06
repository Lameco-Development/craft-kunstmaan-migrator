<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

use Craft;
use DateTimeImmutable;
use lameco\kunstmaanmigrator\load\EntryMigrationService;
use lameco\kunstmaanmigrator\load\MigrationStateService;
use RuntimeException;

/**
 * Live save path for one payload (docs/loader-contract.md). Maps
 * `section`/`entryType`/site handles to Craft ids via `SchemaGateway`,
 * resolves `parentRef`/`_ref` sourceUids via `RefResolver`, and hands the
 * built `$perSite` array to the existing, vetted
 * `EntryMigrationService::saveEntryForSites()` — this class does not
 * duplicate any of that save logic, only the payload → `$perSite` mapping
 * and the two-pass deferred-ref bookkeeping (docs/loader-contract.md
 * "Two-pass `_ref` resolution semantics").
 *
 * By design, `save()` is only ever called with a `Payload` that already
 * passed `PayloadValidator` — `LoadController`'s live branch validates the
 * whole batch first and refuses to save anything when any violation exists.
 * The section/entryType null-checks below are a defensive backstop, not the
 * primary gate.
 */
final class PayloadEntrySaver
{
    private readonly RefResolver $refResolver;

    /** @var callable(callable(): SaveResult): SaveResult */
    private $transactionRunner;

    /**
     * @param ?callable(callable(): SaveResult): SaveResult $transactionRunner
     *   Defaults to `Craft::$app->getDb()->transaction()` (commits on
     *   success, rolls back and rethrows on failure — `LoadController`'s
     *   fail-forward loop catches the rethrow into `failed[]`). Tests inject
     *   a passthrough runner so `save()` is exercisable without a live
     *   Craft application.
     */
    public function __construct(
        private readonly SchemaGateway $gateway,
        private readonly EntryMigrationService $entryService,
        private readonly MigrationStateService $stateService,
        ?callable $transactionRunner = null,
    ) {
        $this->refResolver = new RefResolver($stateService);
        $this->transactionRunner = $transactionRunner ?? static function (callable $fn) {
            return Craft::$app->getDb()->transaction($fn);
        };
    }

    public function save(Payload $p): SaveResult
    {
        return ($this->transactionRunner)(fn (): SaveResult => $this->doSave($p));
    }

    private function doSave(Payload $p): SaveResult
    {
        $section = $this->gateway->sectionByHandle($p->section);
        if ($section === null) {
            throw new RuntimeException(sprintf('PayloadEntrySaver: unknown section "%s".', $p->section));
        }

        $entryType = $this->gateway->entryTypeByHandle($p->entryType);
        if ($entryType === null) {
            throw new RuntimeException(sprintf('PayloadEntrySaver: unknown entry type "%s".', $p->entryType));
        }

        $parsed = RefResolver::parse($p->sourceUid);
        if ($parsed === null) {
            throw new RuntimeException(sprintf(
                'PayloadEntrySaver: sourceUid "%s" does not match the kuma:<ENV>:<table>:<id> grammar.',
                $p->sourceUid,
            ));
        }
        [$stateSource, $stateKey] = [$parsed['source'], $parsed['key']];

        // "created" is decided BEFORE the save — saveEntryForSites() records
        // into this exact (stateSource, stateKey) pair, so a pre-existing row
        // means this call is an update, not a create.
        $wasAlreadySaved = $this->stateService->getTargetId($stateSource, $stateKey) !== null;

        $deferredRefs = [];
        $perSite = [];
        foreach ($p->sites as $handle => $site) {
            $parentId = null;
            if ($site['parentRef'] !== null) {
                $parentId = $this->refResolver->resolve($site['parentRef']);
                if ($parentId === null) {
                    $deferredRefs[] = ['field' => 'parentId', 'site' => $handle, 'ref' => $site['parentRef']];
                }
            }

            $perSite[$handle] = [
                'enabled' => $site['enabled'],
                'title' => (string) ($site['title'] ?? ''),
                'slug' => (string) ($site['slug'] ?? ''),
                'fieldValues' => $this->resolveFieldValues($site['fieldValues'], (string) $handle, $deferredRefs),
                'parentId' => $parentId,
                'postDate' => $site['postDate'] !== null ? new DateTimeImmutable($site['postDate']) : null,
            ];
        }

        $entry = $this->entryService->saveEntryForSites(
            $section['id'],
            $entryType['id'],
            $stateSource,
            $stateKey,
            $perSite,
        );

        // Reflects THIS run's deferred state, overwriting whatever a
        // previous run left behind — Task 5's fixup pass owns clearing
        // individually-resolved entries between runs; a fresh pass-1 save
        // should never leave stale pendingRefs pointing at refs this save
        // already resolved directly.
        $this->stateService->updateMeta($stateSource, $stateKey, null, ['pendingRefs' => $deferredRefs]);

        foreach ($p->aliases as $alias) {
            $this->stateService->recordAlias($alias, $p->sourceUid, (int) $entry->id);
        }

        return new SaveResult(
            sourceUid: $p->sourceUid,
            entryId: (int) $entry->id,
            created: !$wasAlreadySaved,
            deferredRefs: $deferredRefs,
        );
    }

    /**
     * Recursively resolve every `_ref` node anywhere in a site's fieldValues
     * tree (top-level or nested inside a matrix block's `fields`, per
     * docs/loader-contract.md). `_asset` nodes carry no `_ref` key so they
     * fall through the generic recursion untouched, exactly as the contract
     * requires ("resolved by the existing asset field handler, not by this
     * class").
     *
     * @param array<string, mixed> $fieldValues
     * @param list<array{field: string, site: string, ref: string}> $deferredRefs
     * @return array<string, mixed>
     */
    private function resolveFieldValues(array $fieldValues, string $siteHandle, array &$deferredRefs): array
    {
        $out = [];
        foreach ($fieldValues as $fieldHandle => $value) {
            $resolved = $this->resolveNode($value, (string) $fieldHandle, $siteHandle, $deferredRefs);
            if (!$resolved['present']) {
                continue;
            }
            $out[$fieldHandle] = $resolved['value'];
        }

        return $out;
    }

    /**
     * @param list<array{field: string, site: string, ref: string}> $deferredRefs
     * @return array{present: bool, value: mixed}
     */
    private function resolveNode(mixed $node, string $fieldHandle, string $siteHandle, array &$deferredRefs): array
    {
        if (!is_array($node)) {
            return ['present' => true, 'value' => $node];
        }

        if (array_key_exists('_ref', $node) && is_string($node['_ref'])) {
            $resolvedId = $this->refResolver->resolve($node['_ref']);
            if ($resolvedId === null) {
                $deferredRefs[] = ['field' => $fieldHandle, 'site' => $siteHandle, 'ref' => $node['_ref']];

                // Unresolved _ref: no bogus id is written — the node is
                // dropped from its containing list/map entirely.
                return ['present' => false, 'value' => null];
            }

            return ['present' => true, 'value' => $resolvedId];
        }

        $isList = array_is_list($node);
        $out = [];
        foreach ($node as $key => $childValue) {
            $child = $this->resolveNode($childValue, $fieldHandle, $siteHandle, $deferredRefs);
            if (!$child['present']) {
                continue;
            }
            if ($isList) {
                $out[] = $child['value'];
            } else {
                $out[$key] = $child['value'];
            }
        }

        return ['present' => true, 'value' => $out];
    }
}
