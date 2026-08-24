<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 2026-05-09 — Lock the HomePage state-source detection used by
 * `applyPerSiteData()` to fall back to Craft's `__home__` HOMEPAGE_URI
 * marker when the source `kuma_node_translations.slug` is NULL.
 *
 * Kunstmaan's lvl=0 homepage row stores no slug (it IS the site root);
 * without this fallback, Craft auto-derives `slug = "home"` from the
 * entry title and routes the migrated entry to `/nl/home` instead of
 * `/nl/`. Verified against dewert / deklerk / simac source DBs:
 * `kuma_node_translations.slug IS NULL` for the lvl=0 HomePage row on
 * every portfolio site.
 *
 * Detection is FQCN-suffix based (no per-project allowlist), matching
 * Lameco's convention of naming the homepage entity literally
 * `App\Entity\Pages\HomePage`.
 */
final class EntryMigrationServiceHomepageSlugTest extends TestCase
{
    public function testRecognisesCanonicalHomePageStateSource(): void
    {
        $rm = new ReflectionMethod(EntryMigrationService::class, 'isHomePageStateSource');
        self::assertTrue((bool) $rm->invoke(null, 'App_Entity_Pages_HomePage'));
    }

    public function testRecognisesProjectNamespaceVariants(): void
    {
        // Suffix check is robust against project-namespace drift (e.g.
        // `Acme\Site\Entity\Pages\HomePage` slug-form).
        $rm = new ReflectionMethod(EntryMigrationService::class, 'isHomePageStateSource');
        self::assertTrue((bool) $rm->invoke(null, 'Acme_Site_Entity_Pages_HomePage'));
        self::assertTrue((bool) $rm->invoke(null, 'Lameco_Foo_Entity_Pages_HomePage'));
    }

    public function testDoesNotMatchNonHomePageEntities(): void
    {
        $rm = new ReflectionMethod(EntryMigrationService::class, 'isHomePageStateSource');
        // Defensive: only `_HomePage` suffix matches. Substring-anywhere
        // would risk false positives like `_HomePageContent` if a project
        // ever named a page-part that way.
        self::assertFalse((bool) $rm->invoke(null, 'App_Entity_Pages_TextPage'));
        self::assertFalse((bool) $rm->invoke(null, 'App_Entity_Pages_NewsPage'));
        self::assertFalse((bool) $rm->invoke(null, 'App_Entity_Pages_HomePagePart'));
        self::assertFalse((bool) $rm->invoke(null, 'singleton'));
        self::assertFalse((bool) $rm->invoke(null, ''));
    }
}
