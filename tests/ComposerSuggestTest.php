<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests;

use PHPUnit\Framework\TestCase;

/**
 * Plan 04-12 Task 08 — ADP-03 invariant guard.
 *
 * Asserts composer.json keeps SEOmatic + Retour as `suggest` entries and
 * NOT in `require`. The Phase 1 manifest shipped this configuration; this
 * test prevents a future maintainer from silently flipping either to a
 * hard requirement, which would break the runtime-detection contract
 * (PROJECT.md "Optional SEOmatic / Retour adapters" — runtime detection
 * via Craft::$app->plugins->getPlugin(...)).
 */
final class ComposerSuggestTest extends TestCase
{
    public function testSeomaticAndRetourAreSuggestNotRequire(): void
    {
        $composerPath = __DIR__ . '/../composer.json';
        $this->assertFileExists($composerPath);

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $this->assertIsArray($composer);

        $require = $composer['require'] ?? [];
        $suggest = $composer['suggest'] ?? [];

        // ADP-03: SEOmatic must NOT be in require — known package names cover
        // both historical (nystudio107/seomatic) and current (nystudio107/craft-seomatic).
        $this->assertArrayNotHasKey('nystudio107/seomatic', $require, 'ADP-03: SEOmatic must NOT be in require');
        $this->assertArrayNotHasKey('nystudio107/craft-seomatic', $require, 'ADP-03: SEOmatic must NOT be in require');
        $this->assertArrayNotHasKey('craftcms/seomatic', $require, 'ADP-03: SEOmatic must NOT be in require');

        // ADP-03: Retour must NOT be in require.
        $this->assertArrayNotHasKey('nystudio107/retour', $require, 'ADP-03: Retour must NOT be in require');
        $this->assertArrayNotHasKey('nystudio107/craft-retour', $require, 'ADP-03: Retour must NOT be in require');

        // Both should appear as suggest entries (case-insensitive substring match
        // accommodates either historical or current package-name conventions).
        $allSuggestKeys = strtolower(implode(' ', array_keys($suggest)));
        $this->assertStringContainsString('seomatic', $allSuggestKeys, 'ADP-03: SEOmatic should be present in suggest');
        $this->assertStringContainsString('retour', $allSuggestKeys, 'ADP-03: Retour should be present in suggest');
    }
}
