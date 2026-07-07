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
        $unresolvedAssets = [];
        $mediaTokenIssues = [];
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
            unresolvedAssets: $unresolvedAssets,
            mediaTokenIssues: $mediaTokenIssues,
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

            return ['present' => true, 'value' => $resolvedId];
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
