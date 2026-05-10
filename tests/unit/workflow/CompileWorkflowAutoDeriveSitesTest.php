<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\workflow;

use lameco\kunstmaanmigrator\workflow\CompileWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 2026-05-10 — Lock the locale-source contract in
 * `CompileWorkflow::autoDeriveSitesFromLegacyLocales()`.
 *
 * Symptom (operator-spotted during P14a verification 2026-05-09):
 * `migrate --live` failed preflight with "Missing compiled block(s):
 * sites" on a freshly-compiled mapping. Investigation: compile's
 * sites resolver had three precedence rungs (mapping.yaml sites
 * block → Settings::localeMap → auto-derive from
 * `kunstmaan-schema.json#locales`), and all three returned empty.
 * Root cause: `kunstmaan-schema.json` is now written by
 * `KunstmaanPageWalker::walk()` (the graph format —
 * `{assets, constraints, entities, graphVersion, ...}`) which
 * doesn't carry a `locales` field. The earlier `KunstmaanSchemaDumper`
 * shape that DID include locales was replaced; the auto-derive method
 * silently broke because its file-format assumption was outdated.
 *
 * Fix: `LocalePreflight::detect()` is the canonical source per
 * CONTEXT.md D-17. Going direct sidesteps the file-format drift and
 * works regardless of which shape happens to live at
 * `kunstmaan-schema.json` at any given migration phase.
 *
 * Source-string assertions because `autoDeriveSitesFromLegacyLocales`
 * is a private method on a Yii Component — driving it end-to-end
 * needs a Craft container + legacy DB + populated migration state.
 * The change is mechanical and the test corpus around CompileWorkflow
 * already exercises end-to-end behaviour for callers; this lock
 * defends against future refactors silently regressing the contract.
 */
final class CompileWorkflowAutoDeriveSitesTest extends TestCase
{
    public function testLocalesComeFromLocalePreflightNotKunstmaanSchema(): void
    {
        $source = $this->classSource();

        // The canonical signal — locales must come from the LocalePreflight
        // service, NOT from a JSON file that may or may not carry the field.
        self::assertStringContainsString(
            'Plugin::getInstance()->localePreflight->detect()',
            $source,
        );

        // Defensive assertion: the obsolete file-read path must not
        // re-appear. Lock both the legacy filename AND the
        // `$schema['locales']` access pattern that this method used to
        // perform. A future refactor that re-introduces either should
        // also re-think the contract.
        self::assertStringNotContainsString(
            "\$schema['locales']",
            $source,
        );
        self::assertStringNotContainsString(
            "schema-dump.json",
            $source,
        );
    }

    public function testGracefullyHandlesLocalePreflightFailure(): void
    {
        $source = $this->classSource();

        // Detect-throws-graceful path: when LocalePreflight::detect raises
        // (legacy DB unreachable — operator running compile outside the
        // migration host), we return [] so the upstream caller falls
        // through to Settings::localeMap / mapping.yaml sites: block.
        // Lock the catch handler so this path doesn't accidentally
        // become a hard error.
        self::assertMatchesRegularExpression(
            '/catch\s*\(\s*Throwable\s+\$e\s*\)\s*\{[^}]*return\s*\[\]/s',
            $source,
            'autoDeriveSitesFromLegacyLocales must catch Throwable from LocalePreflight::detect and return [] (lets upstream rungs cover the case).',
        );
    }

    public function testCraftSiteMatchingByLanguageCodePreserved(): void
    {
        $source = $this->classSource();

        // The downstream language-code matching logic stays unchanged —
        // legacy locale 'nl' matches Craft site whose language equals
        // 'nl' or starts with 'nl-'. Don't accidentally swap to
        // matching against site handles (the LocalePreflight::resolve
        // path returns mixed handle/language values, which would break
        // the legacy-locale → site-handle mapping the compile step
        // requires).
        self::assertStringContainsString(
            'Craft::$app->sites->getAllSites()',
            $source,
        );
        self::assertStringContainsString(
            "(\$lang === \$legacy)",
            $source,
            'Exact-match rung must compare against site->language.',
        );
        self::assertStringContainsString(
            "strpos(\$lang, \$legacy . '-') === 0",
            $source,
            'Prefix-match rung must compare against site->language with `-` separator.',
        );
    }

    private function classSource(): string
    {
        $file = (string) (new ReflectionClass(CompileWorkflow::class))->getFileName();
        return (string) file_get_contents($file);
    }
}
