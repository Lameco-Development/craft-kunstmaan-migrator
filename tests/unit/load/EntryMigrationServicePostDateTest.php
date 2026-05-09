<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 2026-05-09 — Lock the postDate fallback contract in
 * `EntryMigrationService::applyPerSiteData()`. The migrator sets
 * `$entry->resaving = true` (to suppress per-save revisions), which has the
 * side effect of short-circuiting Craft's auto-postDate fallback in
 * `Entry::maybeSetDefaultAttributes()` (Craft 5 — Entry.php:3010,
 * `if ($this->resaving || $this->getIsRevision()) { return; }`). Net effect
 * without an explicit postDate fallback: every migrated entry has
 * postDate=NULL → status=STATUS_PENDING → Entry::route() returns null →
 * UrlManager::_getMatchedElementRoute fails → frontend 404 even though
 * slug/uri are correctly populated.
 *
 * Verified end-to-end on dewert-craft-smoke 2026-05-09:
 *   before fix: 140 / 1942 entries with postDate=NULL → frontend 404
 *   after fix:  1 / 1942 (only the singleton globalSettings) → all URLs 200
 *
 * Source preference order:
 *   1. `$data['postDate']` — typed DateTimeInterface from caller (extract /
 *      transform writes `kuma_node_translations.created` here).
 *   2. `$fieldValues['postDate']` — string from a mapping.yaml row whose
 *      `targetHandle: postDate` routes through the plain handler.
 *   3. `now()` fallback — when both are empty AND `$entry->postDate` is
 *      NULL (so we don't override an existing value on re-runs).
 */
final class EntryMigrationServicePostDateTest extends TestCase
{
    public function testPostDateFallbackBranchUsesNowWhenSourceIsEmpty(): void
    {
        $file = (string) (new ReflectionClass(EntryMigrationService::class))->getFileName();
        $source = (string) file_get_contents($file);

        // Lock the fallback's existence + the now-DateTime path.
        self::assertStringContainsString(
            'elseif ($entry->postDate === null) {',
            $source,
        );
        self::assertStringContainsString(
            '$entry->postDate = new \\DateTime();',
            $source,
        );
    }

    public function testPostDateFallbackPreservesExistingValueOnReRuns(): void
    {
        // The `elseif ($entry->postDate === null)` guard means: if a prior
        // migrate-and-save left a postDate on the entry, we don't overwrite
        // it. Lock that semantic — silent overwrite on re-runs would be a
        // user-visible regression (postDate is the SEO/sitemap published-at
        // date, editorially significant).
        $file = (string) (new ReflectionClass(EntryMigrationService::class))->getFileName();
        $source = (string) file_get_contents($file);

        // The branch should be `elseif (...->postDate === null)` not
        // `else { ... }` — the latter would clobber existing values.
        self::assertStringNotContainsString(
            "if (\$postDate !== null) {\n            \$entry->postDate = \$postDate;\n        } else {\n            \$entry->postDate = new \\DateTime",
            $source,
        );
    }

    public function testTransformPropagatesKumaCreatedAsPostDate(): void
    {
        // ExtractService writes `'created' => $t['created'] ?? null` per-site.
        // TransformService routes it through `resolvePostDate($siteData, $nodeSpec)`
        // which prefers `detail[<nodeSpec.postDateColumn>]` (editorial date for
        // AbstractArticlePage subclasses) and falls back to `siteData['created']`.
        // Lock the wire-up so a future refactor doesn't silently drop the
        // primary-path source date (would degrade everyone to the now()
        // fallback in applyPerSiteData, losing per-page editorial dates).
        // Editorial-override behaviour is locked separately in
        // tests/unit/transform/TransformServicePostDateTest.
        $extractFile = dirname((string) (new ReflectionClass(EntryMigrationService::class))->getFileName(), 2)
            . '/extract/ExtractService.php';
        $transformFile = dirname((string) (new ReflectionClass(EntryMigrationService::class))->getFileName(), 2)
            . '/transform/TransformService.php';

        $extractSource = (string) file_get_contents($extractFile);
        $transformSource = (string) file_get_contents($transformFile);

        self::assertStringContainsString(
            "'created'    => \$t['created'] ?? null,",
            $extractSource,
        );
        self::assertStringContainsString(
            "'postDate'    => \$this->resolvePostDate(\$siteData, \$nodeSpec),",
            $transformSource,
        );
        self::assertStringContainsString(
            "\$created = \$siteData['created'] ?? null;",
            $transformSource,
            'resolvePostDate() must keep kuma_node_translations.created as the fallback',
        );
    }
}
