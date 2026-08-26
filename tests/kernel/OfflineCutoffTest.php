<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Tests\Kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An entry's site rows are exactly the node's online translations, plus the offline ones
 * recent enough to be editorial work in progress.
 *
 * Kunstmaan switches a translation off rather than deleting it, so a corpus accumulates
 * years of dead locales. Carrying every one of them as a disabled row fills Craft with
 * pages nobody will ever publish. The cutoff keeps the ones an editor has touched since
 * and drops the rest, and a locale the node was never translated into stays absent either
 * way — inventing a row there is what lets Craft propagate a ghost into it.
 */
final class OfflineCutoffTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          COM:
            database: com
            locales: { en: comEnUs, nl: comNlNl, zz: comNlNl }
        defaults:
          structuralEntryType: contentPage
          offlineCutoff: '2026-03-01'
        pages:
          HomePage:
            table: home_pages
            section: homePage
            entryType: homePage
          CasePage:
            table: case_pages
            section: pages
            entryType: casePage
        YAML;

    private function db(): LegacyDatabase
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, parent_id INTEGER, deleted INTEGER, lft INTEGER, ref_entity_name TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, ref_entity_name TEXT, ref_id INTEGER, updated TEXT)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, slug TEXT, url TEXT,
                     created TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_page_part_refs
                    (id INTEGER, pageId INTEGER, pageEntityname TEXT, context TEXT,
                     sequencenumber INTEGER, page_part_id INTEGER, page_part_entityname TEXT)');
        $pdo->exec('CREATE TABLE kuma_media (id INTEGER, url TEXT, deleted INTEGER)');
        $pdo->exec('CREATE TABLE home_pages (id INTEGER)');
        $pdo->exec('CREATE TABLE case_pages (id INTEGER)');
        $pdo->exec('INSERT INTO home_pages VALUES (500)');
        $pdo->exec('INSERT INTO case_pages VALUES (600), (601), (602), (603), (604), (605), (606), (607), (608), (609), (610)');

        $pdo->exec("INSERT INTO kuma_nodes VALUES
                    (1,  NULL, 0, 1, 'App\\Entity\\Pages\\HomePage'),
                    (20, 1,    0, 2, 'App\\Entity\\Pages\\CasePage'),
                    (21, 1,    0, 3, 'App\\Entity\\Pages\\CasePage'),
                    (22, 1,    0, 4, 'App\\Entity\\Pages\\CasePage'),
                    (23, 1,    0, 5, 'App\\Entity\\Pages\\CasePage'),
                    (24, 1,    0, 6, 'App\\Entity\\Pages\\CasePage'),
                    (25, 1,    0, 7, 'App\\Entity\\Pages\\CasePage'),
                    (26, 1,    0, 8, 'App\\Entity\\Pages\\CasePage'),
                    (27, 26,   0, 9, 'App\\Entity\\Pages\\CasePage')");

        // Each translation points at its own page entity, which is what makes field values
        // differ per locale. `updated` is when that version was last saved.
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (90, 'App\\Entity\\Pages\\HomePage', 500, '2024-01-01 00:00:00'),
                    (91, 'App\\Entity\\Pages\\CasePage', 600, '2024-05-01 00:00:00'),
                    (92, 'App\\Entity\\Pages\\CasePage', 601, '2025-11-01 00:00:00'),
                    (93, 'App\\Entity\\Pages\\CasePage', 602, '2024-05-01 00:00:00'),
                    (94, 'App\\Entity\\Pages\\CasePage', 603, '2026-06-01 00:00:00'),
                    (95, 'App\\Entity\\Pages\\CasePage', 604, '2024-05-01 00:00:00'),
                    (96, 'App\\Entity\\Pages\\CasePage', 605, '2024-05-01 00:00:00'),
                    (97, 'App\\Entity\\Pages\\CasePage', 606, '2026-06-01 00:00:00'),
                    (98, 'App\\Entity\\Pages\\CasePage', 607, '2024-05-01 00:00:00'),
                    (99, 'App\\Entity\\Pages\\CasePage', 608, '2026-06-01 00:00:00'),
                    (100, 'App\\Entity\\Pages\\CasePage', 609, '2026-06-01 00:00:00'),
                    (101, 'App\\Entity\\Pages\\CasePage', 610, '2024-05-01 00:00:00')");

        // 20 · EN online, NL switched off in 2025 — dead weight
        // 21 · EN online, NL switched off but edited in June 2026 — work in progress
        // 22 · EN online, never translated into NL at all
        // 23 · EN online, NL a draft that was never published — no public version to read
        // 24 · NL switched off but recent, and no published locale at all
        // 25 · NL online and ZZ switched off but recent — both map to comNlNl
        // 26 · rescued-only, and the parent of 27 · which is published
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (1, 1,  'en', 'Home',   NULL,       NULL, NULL, 1, 90),
                    (2, 20, 'en', 'Stale',  'stale',    NULL, NULL, 1, 91),
                    (3, 20, 'nl', 'Oud',    'oud',      NULL, NULL, 0, 92),
                    (4, 21, 'en', 'Fresh',  'fresh',    NULL, NULL, 1, 93),
                    (5, 21, 'nl', 'Nieuw',  'nieuw',    NULL, NULL, 0, 94),
                    (6, 22, 'en', 'Lonely', 'lonely',   NULL, NULL, 1, 95),
                    (7, 23, 'en', 'Draft',  'draft',    NULL, NULL, 1, 96),
                    (8, 23, 'nl', 'Concept','concept',  NULL, NULL, 0, NULL),
                    (9, 24, 'nl', 'Alleen', 'alleen',   NULL, NULL, 0, 97),
                    (10, 25, 'nl', 'Echt',  'echt',     NULL, NULL, 1, 98),
                    (11, 25, 'zz', 'Spook', 'spook',    NULL, NULL, 0, 99),
                    (12, 26, 'nl', 'Ouder', 'ouder',    NULL, NULL, 0, 100),
                    (13, 27, 'en', 'Kind',  'kind',     NULL, NULL, 1, 101)");

        return new LegacyDatabase($pdo, 'COM', 'com');
    }

    /** @return array<string, array<string, mixed>> */
    private function compile(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);
        $out = [];

        (new Compiler(Mapping::fromFile($path), new Transforms()))
            ->compile($this->db(), 'COM', static function(array $p) use (&$out): void {
                $out[(string) $p['sourceUid']] = $p;
            });

        return $out;
    }

    #[Test]
    public function an_offline_translation_older_than_the_cutoff_produces_no_site_row(): void
    {
        // Node 20's NL translation was switched off and last saved in November 2025. It owns
        // no Craft row at all: not a disabled one, not a slug claim. The locale simply does
        // not exist for this page.
        $sites = $this->compile()['kuma:COM:kuma_nodes:20']['sites'];

        self::assertArrayNotHasKey('comNlNl', $sites);
        self::assertSame(['comEnUs'], array_keys($sites));
    }

    #[Test]
    public function an_offline_translation_newer_than_the_cutoff_is_migrated_disabled(): void
    {
        // Node 21's NL translation is switched off but was saved in June 2026 — an editor is
        // working on it. It comes across carrying its own title and slug, and it comes across
        // *disabled*: publishing a locale the old site deliberately kept dark would put
        // unreviewed copy live at cutover, and an editor flipping one switch is the cheap
        // direction to be wrong in.
        $sites = $this->compile()['kuma:COM:kuma_nodes:21']['sites'];

        self::assertArrayHasKey('comNlNl', $sites);
        self::assertFalse($sites['comNlNl']['enabled']);
        self::assertSame('nieuw', $sites['comNlNl']['slug']);
        self::assertSame('Nieuw', $sites['comNlNl']['title']);

        self::assertTrue($sites['comEnUs']['enabled']);
    }

    #[Test]
    public function a_locale_the_node_was_never_translated_into_gets_nothing(): void
    {
        // Node 22 exists only in EN. No NL row is invented for it, at any age: a row Craft did
        // not ask for is what lets it propagate the primary site's slug into that locale and
        // collide with the real entry there.
        self::assertSame(['comEnUs'], array_keys($this->compile()['kuma:COM:kuma_nodes:22']['sites']));
    }

    #[Test]
    public function an_offline_translation_that_was_never_published_is_not_rescued(): void
    {
        // Node 23's NL translation has no public version, so there is no page entity to read
        // its content from — Kunstmaan keeps an unpublished edit in a draft version instead.
        // Recency cannot help it, and a row with no content is not worth writing.
        self::assertSame(['comEnUs'], array_keys($this->compile()['kuma:COM:kuma_nodes:23']['sites']));
    }

    #[Test]
    public function a_node_published_in_no_mapped_locale_is_not_an_entry(): void
    {
        // Node 24 has one translation, switched off and recent enough to rescue — but nothing
        // published anywhere. Rescuing it alone would emit an entry enabled on no site, which
        // the loader refuses as NO_ENABLED_SITE, so the whole payload would fail validation.
        // The cutoff adds locales to a page that is published somewhere; it does not revive
        // a page that is published nowhere.
        self::assertArrayNotHasKey('kuma:COM:kuma_nodes:24', $this->compile());
    }

    #[Test]
    public function a_published_translation_outranks_a_rescued_one_on_the_same_site(): void
    {
        // Node 25 is published in NL and switched off in ZZ, and the mapping sends both to
        // comNlNl. The published one owns the site — a rescued translation must never
        // displace live content, whichever order the locales arrive in.
        $sites = $this->compile()['kuma:COM:kuma_nodes:25']['sites'];

        self::assertTrue($sites['comNlNl']['enabled']);
        self::assertSame('echt', $sites['comNlNl']['slug']);
    }

    #[Test]
    public function a_rescued_only_ancestor_still_hands_its_segment_to_a_published_child(): void
    {
        // Node 26 is rescued-eligible but published nowhere, so it is not a page. Node 27
        // beneath it is published, and needs 26's segment in its URL. Counting 26 as an
        // ordinary parent would end the ancestor walk at a node nothing ever emits, leaving
        // 27 pointing at a parent that does not arrive.
        $payloads = $this->compile();

        self::assertArrayHasKey('kuma:COM:kuma_nodes:27', $payloads);
        self::assertArrayHasKey('kuma:COM:kuma_nodes:26', $payloads);
        self::assertTrue($payloads['kuma:COM:kuma_nodes:26']['structural']);
        self::assertSame(
            'kuma:COM:kuma_nodes:26',
            $payloads['kuma:COM:kuma_nodes:27']['sites']['comEnUs']['parentRef'],
        );
    }

    #[Test]
    public function a_stale_offline_ancestor_still_owns_its_path_segment(): void
    {
        // The cutoff decides which pages exist, not which URLs are right. An ancestor that is
        // offline and stale still hands its slug to the online pages beneath it, which is the
        // whole reason structural placeholders exist — see StructuralAncestorTest.
        $payloads = $this->compile();

        self::assertArrayHasKey('kuma:COM:kuma_nodes:20', $payloads);
        self::assertArrayNotHasKey('structural', $payloads['kuma:COM:kuma_nodes:20']);
    }
}
