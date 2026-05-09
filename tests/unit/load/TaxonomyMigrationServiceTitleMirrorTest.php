<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\load;

use lameco\kunstmaanmigrator\load\TaxonomyMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 2026-05-09 — Lock the title-mirror fallback in
 * `TaxonomyMigrationService::upsertOneEntry()`.
 *
 * Symptom (operator-spotted on dewert smoke 2026-05-09): every Category
 * entry in Craft renders as `[legacy id 1]` / `[legacy id 5]` etc. in
 * the CP listing. dewert's `App\Entity\Category` source schema has a
 * `name` column (no `title`), and the scaffolder routes `name → name`
 * because the Craft `category` entry type has its own `name` field.
 * `upsertOneEntry` only captured `$title` from a column whose `craftHandle`
 * was literally `'title'` — so without an explicit `name → title`
 * mapping, `$title` stayed empty and fell through to the
 * `[legacy id N]` placeholder.
 *
 * Fix: when no explicit title mapping exists, mirror from a
 * field-value with a title-like handle (`title`, `name`, `label`)
 * BEFORE the placeholder fallback. Operator can still pin an explicit
 * `name → title` mapping in mapping.yaml — that path captures
 * `$title` directly via the existing `if ($craftHandle === 'title')`
 * branch and the new fallback is bypassed.
 *
 * Source-string assertions because `upsertOneEntry` is a private method
 * that requires Craft + Element services to drive end-to-end. The
 * inserted block is mechanical — locking the line is sufficient.
 */
final class TaxonomyMigrationServiceTitleMirrorTest extends TestCase
{
    public function testTitleMirrorBranchExistsBeforePlaceholderFallback(): void
    {
        $source = $this->classSource();

        // The fallback loop must walk the canonical title-like handles in
        // priority order — explicit `title` first, then the common
        // alternatives operators name `title` columns as.
        self::assertStringContainsString(
            "foreach (['title', 'name', 'label'] as \$candidate) {",
            $source,
            'Title-mirror loop must enumerate title-like handles before placeholder.',
        );

        // The mirror reads from `$fieldValues` (the per-field map populated
        // earlier in the method) — NOT `$legacyRow` (raw DB columns) — so
        // operator handle renames are honored.
        self::assertStringContainsString(
            'isset($fieldValues[$candidate]) && $fieldValues[$candidate] !== \'\'',
            $source,
        );
    }

    public function testPlaceholderFallbackStillFiresWhenNoMirrorMatches(): void
    {
        $source = $this->classSource();
        // The legacy-id placeholder still exists post-mirror — it's the
        // "no candidate matched" final fallback. Don't accidentally remove
        // it (would surface as null `entry->title` and break the CP listing).
        self::assertStringContainsString(
            "\$title = sprintf('[legacy id %d]', \$legacyId);",
            $source,
        );
    }

    public function testExplicitTitleMappingTakesPrecedenceOverMirror(): void
    {
        $source = $this->classSource();

        // The explicit `if ($craftHandle === 'title')` branch sits BEFORE
        // the mirror loop — operator-pinned `name → title` mappings still
        // capture into $title via the original code path. Locking the
        // ordering invariant: explicit branch above the empty-title check.
        $explicitOffset = strpos($source, "if (\$craftHandle === 'title') {");
        $emptyCheckOffset = strpos($source, 'if ($title === \'\') {');

        self::assertNotFalse($explicitOffset, 'Explicit title-handler branch must exist.');
        self::assertNotFalse($emptyCheckOffset, 'Empty-title fallback check must exist.');
        self::assertLessThan(
            $emptyCheckOffset,
            $explicitOffset,
            'Explicit `craftHandle === \'title\'` branch must run BEFORE the empty-title mirror — otherwise an operator-pinned title mapping would always be overridden by the mirror.',
        );
    }

    private function classSource(): string
    {
        $file = (string) (new ReflectionClass(TaxonomyMigrationService::class))->getFileName();
        return (string) file_get_contents($file);
    }
}
