<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

use Craft;
use DateTimeImmutable;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetMigrationService;
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
 * Task 8 extends the same `fieldValues` walk to resolve the two other
 * legacy-media references the contract promises: `{"_asset": "<path>"}`
 * nodes (via `AssetMigrationService::resolveFromLegacyUrl`, same
 * present/omit contract as an unresolved `_ref`) and `{{kuma:media:<id>}}`
 * tokens embedded in string field values (via
 * `CkeditorRewriterService::rewriteCurlyMediaTokens()` — the narrow
 * curly-token-only primitive, never the full `rewrite()` pipeline; see
 * `rewriteMediaTokens()` below).
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
        private readonly AssetMigrationService $assetService,
        private readonly CkeditorRewriterService $ckeditorRewriter,
        ?callable $transactionRunner = null,
        /**
         * Refresh an entry that already exists.
         *
         * EntryMigrationService short-circuits on a re-run unless told otherwise, which is
         * right for resuming an interrupted load and wrong for reloading after the payload
         * changed. Without this the loader reports a save and writes nothing.
         */
        private readonly bool $force = false,
    ) {
        $this->refResolver = new RefResolver($stateService);
        $this->transactionRunner = $transactionRunner ?? static function (callable $fn) {
            return Craft::$app->getDb()->transaction($fn);
        };
    }

    /** Whether an already-existing entry is refreshed rather than left untouched. */
    public function refreshesExisting(): bool
    {
        return $this->force;
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
        $existingEntryId = $this->stateService->getTargetId($stateSource, $stateKey);
        $wasAlreadySaved = $existingEntryId !== null;

        $deferredRefs = [];
        $unresolvedAssets = [];
        $mediaTokenIssues = [];
        $droppedAddresses = [];
        $perSite = [];
        foreach ($p->sites as $handle => $site) {
            $parentId = null;
            if ($site['parentRef'] !== null) {
                $parentId = $this->refResolver->resolve($site['parentRef']);
                if ($parentId === null) {
                    // parentRef lives outside the fieldValues tree, so there is no
                    // nested path to record — 'field' => 'parentId' is already the
                    // full location.
                    $deferredRefs[] = ['field' => 'parentId', 'site' => $handle, 'ref' => $site['parentRef'], 'path' => []];
                }
            }

            // Numeric site id for CkeditorRewriterService's ref-token format
            // ({asset:<id>@<siteId>:url}) — siteByHandle() is guaranteed to
            // resolve here since save() only ever runs against an
            // already-PayloadValidator-checked Payload (see class docblock).
            $siteId = (int) ($this->gateway->siteByHandle((string) $handle)['id'] ?? 0);

            $perSite[$handle] = [
                'enabled' => $site['enabled'],
                'title' => (string) ($site['title'] ?? ''),
                'slug' => (string) ($site['slug'] ?? ''),
                'fieldValues' => $this->resolveFieldValues(
                    $site['fieldValues'],
                    (string) $handle,
                    $siteId,
                    $deferredRefs,
                    $unresolvedAssets,
                    $mediaTokenIssues,
                    $existingEntryId,
                    (string) ($site['title'] ?? ''),
                    $droppedAddresses,
                ),
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
            $this->force,
        );

        // A Craft Address supports the primary site and no other, so an address on a payload
        // that never names that site was dropped above. The entry itself does exist there —
        // Craft propagates it across the section's sites — so the address is written once,
        // against that row, which is what an untranslatable field means.
        $droppedAddresses = $this->writeAddressesOnPrimarySite((int) $entry->id, $droppedAddresses);

        // Reflects THIS run's deferred state, overwriting whatever a
        // previous run left behind — Task 5's fixup pass owns clearing
        // individually-resolved entries between runs; a fresh pass-1 save
        // should never leave stale pendingRefs pointing at refs this save
        // already resolved directly.
        $meta = ['pendingRefs' => $deferredRefs];

        // What the SEO pass looks for. `SeoMigrationService` reads `refIdsByLocale` — its own
        // comments call it "the authoritative per-locale ref_id map written by ExtractService",
        // a v1 service that does not exist here, so nothing ever wrote it. The map was always
        // empty, the service correctly refused to guess, and every entry got an explicit empty
        // SEO payload that the report counted as `updated`. 204 of them, all blank.
        //
        // `legacyClass` matters just as much: without it `resolveLegacyRef` falls back to
        // turning the state source into a class name, so `LV:kuma_nodes` became the class
        // `LV:kuma\nodes` — which matches no row, and never warns.
        $refIds = $p->legacy['refIds'];

        if ($p->legacy['class'] !== '' && $refIds !== []) {
            $meta['legacyClass'] = $p->legacy['class'];
            $meta['legacyEntityId'] = reset($refIds);
            $meta['refIdsByLocale'] = $refIds;
        }

        $this->stateService->updateMeta($stateSource, $stateKey, null, $meta);

        foreach ($p->aliases as $alias) {
            $this->stateService->recordAlias($alias, $p->sourceUid, (int) $entry->id);
        }

        return new SaveResult(
            sourceUid: $p->sourceUid,
            entryId: (int) $entry->id,
            created: !$wasAlreadySaved,
            deferredRefs: $deferredRefs,
            unresolvedAssets: $unresolvedAssets,
            mediaTokenIssues: $mediaTokenIssues,
            droppedAddresses: $droppedAddresses,
        );
    }

    /**
     * Recursively resolve every `_ref`/`_asset` node and `{{kuma:media:<id>}}`
     * string token anywhere in a site's fieldValues tree (top-level or
     * nested inside a matrix block's `fields`, per docs/loader-contract.md).
     *
     * @param array<string, mixed> $fieldValues
     * @param list<array{field: string, site: string, ref: string, path: list<int|string>}> $deferredRefs
     * @param list<array{field: string, site: string, path: list<int|string>, asset: string}> $unresolvedAssets
     * @param list<array<string, mixed>> $mediaTokenIssues
     * @return array<string, mixed>
     */
    private function resolveFieldValues(
        array $fieldValues,
        string $siteHandle,
        int $siteId,
        array &$deferredRefs,
        array &$unresolvedAssets,
        array &$mediaTokenIssues,
        ?int $existingEntryId = null,
        string $ownerTitle = '',
        array &$droppedAddresses = [],
    ): array {
        $out = [];
        foreach ($fieldValues as $fieldHandle => $value) {
            $resolved = $this->resolveNode(
                $value,
                (string) $fieldHandle,
                $siteHandle,
                $siteId,
                $deferredRefs,
                $unresolvedAssets,
                $mediaTokenIssues,
                [$fieldHandle],
                $existingEntryId,
                $ownerTitle,
                $droppedAddresses,
            );
            if (!$resolved['present']) {
                continue;
            }
            $out[$fieldHandle] = $resolved['value'];
        }

        return $out;
    }

    /**
     * @param list<int|string> $path Path from the site's `fieldValues` root
     *   down to `$node` itself (i.e. `$node` lives at `fieldValues[...$path]`).
     *   When `$node` turns out to be an unresolved `_ref`/`_asset`, the
     *   recorded `path` drops this array's own last segment — the index/key
     *   that addresses the node within its immediate container — since that
     *   node is spliced out of the saved payload (see docs/loader-contract.md
     *   "Two-pass `_ref` resolution semantics").
     * @param list<array{field: string, site: string, ref: string, path: list<int|string>}> $deferredRefs
     * @param list<array{field: string, site: string, path: list<int|string>, asset: string}> $unresolvedAssets
     * @param list<array<string, mixed>> $mediaTokenIssues
     * @return array{present: bool, value: mixed}
     */
    private function resolveNode(
        mixed $node,
        string $fieldHandle,
        string $siteHandle,
        int $siteId,
        array &$deferredRefs,
        array &$unresolvedAssets,
        array &$mediaTokenIssues,
        array $path,
        ?int $existingEntryId = null,
        string $ownerTitle = '',
        array &$droppedAddresses = [],
    ): array {
        if (is_string($node)) {
            if (!str_contains($node, '{{kuma:media:')) {
                return ['present' => true, 'value' => $node];
            }

            return [
                'present' => true,
                'value' => $this->rewriteMediaTokens($node, $fieldHandle, $siteHandle, $siteId, $path, $mediaTokenIssues),
            ];
        }

        if (!is_array($node)) {
            return ['present' => true, 'value' => $node];
        }

        if (array_key_exists('_address', $node) && is_array($node['_address'])) {
            // A Craft Address supports the primary site and no other, so an Addresses field is
            // effectively untranslatable: saving one against any other site throws
            // "Attempting to save an element in an unsupported site" and takes the whole entry
            // with it. Every address already in this corpus sits on the primary site. So the
            // address is written once, there, and omitted from the other sites' field values —
            // which is what "not translatable" means, not a value being lost.
            if ($siteId !== $this->gateway->primarySite()['id']) {
                $droppedAddresses[] = [
                    'field' => $fieldHandle,
                    'site' => $siteHandle,
                    'path' => $path,
                    'parts' => $node['_address'],
                    'ownerTitle' => $ownerTitle,
                ];

                return ['present' => false, 'value' => null];
            }

            return [
                'present' => true,
                'value' => $this->addressValue($node['_address'], $fieldHandle, $siteHandle, $path, $existingEntryId, $ownerTitle),
            ];
        }

        if (array_key_exists('_ref', $node) && is_string($node['_ref'])) {
            $resolvedId = $this->refResolver->resolve($node['_ref']);
            if ($resolvedId === null) {
                $deferredRefs[] = [
                    'field' => $fieldHandle,
                    'site' => $siteHandle,
                    'ref' => $node['_ref'],
                    'path' => array_slice($path, 0, -1),
                ];

                // Unresolved _ref: no bogus id is written — the node is
                // dropped from its containing list/map entirely.
                return ['present' => false, 'value' => null];
            }

            return ['present' => true, 'value' => $resolvedId];
        }

        // A Craft Link field pointing at an entry stores a reference tag, not an id — the same
        // `{entry:<id>@<siteId>:url}` form the control panel writes. The compiler cannot know the
        // Craft id, so it hands over the source uid and the tag is assembled here.
        if (array_key_exists('_linkRef', $node) && is_string($node['_linkRef'])) {
            $resolvedId = $this->refResolver->resolve($node['_linkRef']);

            if ($resolvedId === null) {
                // A link is *set* at its own slot, not appended to a container, so the whole
                // path is recorded — including the slot itself — and `kind` tells the fixup
                // pass to write a link there rather than push an id into a list.
                $deferredRefs[] = [
                    'field' => $fieldHandle,
                    'site' => $siteHandle,
                    'ref' => $node['_linkRef'],
                    'path' => $path,
                    'kind' => 'link',
                    'link' => array_intersect_key($node, array_flip(['label', 'target'])),
                ];

                return ['present' => false, 'value' => null];
            }

            // `type` is not optional here. Craft only sniffs the link type when the value is a
            // bare string; hand it a map — which is the only way to carry a label — and it
            // defaults to `url`, then fails the reference tag as an invalid URL and takes the
            // whole entry with it.
            $link = [
                'type' => 'entry',
                'value' => sprintf('{entry:%d@%d:url}', $resolvedId, $siteId),
            ];

            foreach (['label', 'target'] as $key) {
                if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
                    $link[$key] = $node[$key];
                }
            }

            return ['present' => true, 'value' => $link];
        }

        if (array_key_exists('_asset', $node) && is_string($node['_asset'])) {
            $resolvedId = $this->assetService->resolveFromLegacyUrl($node['_asset']);
            if ($resolvedId <= 0) {
                $unresolvedAssets[] = [
                    'field' => $fieldHandle,
                    'site' => $siteHandle,
                    'path' => array_slice($path, 0, -1),
                    'asset' => $node['_asset'],
                ];

                // Unresolved _asset: no bogus id is written — the node is
                // dropped from its containing list/map entirely, same
                // fail-forward contract as an unresolved _ref.
                return ['present' => false, 'value' => null];
            }

            // A relation field takes a list of element ids. Handing Craft a bare integer
            // saved without complaint and related nothing: 80 assets were created and none
            // were attached to the block that asked for them.
            return ['present' => true, 'value' => [$resolvedId]];
        }

        $isList = array_is_list($node);
        $out = [];
        foreach ($node as $key => $childValue) {
            $child = $this->resolveNode(
                $childValue,
                $fieldHandle,
                $siteHandle,
                $siteId,
                $deferredRefs,
                $unresolvedAssets,
                $mediaTokenIssues,
                [...$path, $key],
                $existingEntryId,
                $ownerTitle,
                $droppedAddresses,
            );
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

    /**
     * Write the addresses the per-site pass had to drop, against the primary site.
     *
     * Only a top-level address field can be placed this way: one nested inside a Matrix is
     * owned by a block whose identity is not known here, so it stays dropped and stays
     * counted. `partnerBranch.branchAddress` is the case that hits this.
     *
     * @param list<array{field: string, site: string, path: list<int|string>, parts: array<string, mixed>, ownerTitle: string}> $dropped
     * @return list<array{field: string, site: string}> what is still unwritten, for the report
     */
    private function writeAddressesOnPrimarySite(int $entryId, array $dropped): array
    {
        $primary = $this->gateway->primarySite();
        $unwritten = [];
        $written = [];

        foreach ($dropped as $address) {
            $isTopLevel = $address['path'] === [$address['field']];

            // One address per field is enough: the field is not translatable, so every site's
            // copy holds the same value and the first is as good as the last.
            if (!$isTopLevel || isset($written[$address['field']])) {
                if (!$isTopLevel) {
                    // `field` here is the top-level handle the resolver walked in from; the
                    // path's last segment is the address field itself, which is what an
                    // operator needs to find it.
                    $unwritten[] = [
                        'field' => (string) (end($address['path']) ?: $address['field']),
                        'site' => $address['site'],
                    ];
                }

                continue;
            }

            $value = $this->addressValue(
                $address['parts'],
                $address['field'],
                $primary['handle'],
                $address['path'],
                $entryId,
                $address['ownerTitle'],
            );

            if ($this->entryService->resaveEntryFieldForSite($entryId, $primary['handle'], $address['field'], $value)) {
                $written[$address['field']] = true;

                continue;
            }

            $unwritten[] = ['field' => $address['field'], 'site' => $primary['handle']];
        }

        return $unwritten;
    }

    /**
     * Turn an `_address` node into the shape Craft's Addresses field reads.
     *
     * The field takes `[<address id> => <parts>]` and treats an unrecognised key as a new
     * address, deleting whatever it replaced. Left at `new1` every run, a re-load would
     * therefore delete and recreate the address element each time — the values would come out
     * identical and the element id would not, which is churn a migration should not produce.
     * So an entry that already owns an address at this field reuses that id.
     *
     * Only a top-level address field can be matched this way. One nested inside a Matrix is
     * owned by a block whose identity is not known until the block is saved, so it is written
     * as new; `partnerBranch.branchAddress` is the case that hits this.
     *
     * @param array<string, mixed> $parts
     * @param list<int|string> $path
     * @return array<array-key, array<string, mixed>>
     */
    private function addressValue(
        array $parts,
        string $fieldHandle,
        string $siteHandle,
        array $path,
        ?int $existingEntryId,
        string $ownerTitle = '',
    ): array {
        // Craft's Address layout marks its Label required by default, and an address with no
        // label fails the *whole entry* — 423 partner pages, all with the same message and
        // none of them naming the field that caused it. The mapping should supply a label;
        // when the legacy column behind it is blank, the owner's own title is a better answer
        // than losing the page.
        if (trim((string) ($parts['title'] ?? '')) === '' && $ownerTitle !== '') {
            $parts['title'] = $ownerTitle;
        }

        $key = 'new1';

        if ($existingEntryId !== null && $path === [$fieldHandle]) {
            $current = $this->entryService->readEntryFieldValueForSite($existingEntryId, $siteHandle, $fieldHandle);
            $existingId = $current === null ? null : array_key_first($current);

            if (is_int($existingId) || (is_string($existingId) && ctype_digit($existingId))) {
                $key = $existingId;
            }
        }

        return [$key => $parts];
    }

    /**
     * Resolve every `{{kuma:media:<id>}}` token in a string field value
     * (docs/loader-contract.md) via `CkeditorRewriterService::rewriteCurlyMediaTokens()`
     * — the narrow, curly-token-only primitive, NOT the full `rewrite()`
     * pipeline (Task 8 review Finding 1). The payload load path only ever
     * promises `{{kuma:media:<id>}}` rewriting, so it must never also run
     * `rewrite()`'s `[NT<id>]`/`[M<id>]` placeholder resolution, raw
     * `<img src="/uploads/media/...">` rewriting, or `kma-*` class/empty-`<p>`
     * stripping — a normal body merely sharing a paragraph with a media token
     * would otherwise get those unrelated transformations for free.
     *
     * Id resolution itself is left entirely to
     * `CkeditorRewriterService::rewriteCurlyMediaTokens()`'s own lazy
     * `AssetResolver::resolveFromLegacyId()` fallback (wired to the same
     * `AssetMigrationService` singleton in production, `Plugin::init()`) —
     * no pre-seeding here, so each distinct id costs at most one resolver
     * call (Task 8 review Finding 2). Unresolved ids are left as an inert
     * visible marker by that method itself (never silently dropped); this
     * method only adds `field`/`site`/`path` context to whatever diagnostics
     * it produced, filtered to the `media_token` family so a shared
     * diagnostic buffer can never leak an unrelated token family's shape into
     * `mediaTokenIssues`.
     *
     * @param list<int|string> $path
     * @param list<array<string, mixed>> $mediaTokenIssues
     */
    private function rewriteMediaTokens(
        string $html,
        string $fieldHandle,
        string $siteHandle,
        int $siteId,
        array $path,
        array &$mediaTokenIssues,
    ): string {
        $rewritten = $this->ckeditorRewriter->rewriteCurlyMediaTokens($html, $siteId);

        foreach ($this->ckeditorRewriter->consumeUnresolvedDiagnostics() as $diagnostic) {
            if (($diagnostic['tokenFamily'] ?? null) !== 'media_token') {
                continue;
            }
            $mediaTokenIssues[] = ['field' => $fieldHandle, 'site' => $siteHandle, 'path' => $path] + $diagnostic;
        }

        return $rewritten;
    }
}
