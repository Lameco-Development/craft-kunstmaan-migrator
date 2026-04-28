<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\Plugin;

use PHPUnit\Framework\TestCase;

/**
 * Phase 4.1 / Plan 04 / CFG-05 + CFG-06.
 *
 * File-content assertions on _settings.twig + Plugin.php settingsHtml.
 * No Craft bootstrap — all checks are static-text invariants.
 */
final class SettingsHtmlTest extends TestCase
{
    private const TWIG_PATH = __DIR__ . '/../../../src/templates/_settings.twig';
    private const PLUGIN_PATH = __DIR__ . '/../../../src/Plugin.php';

    private const NINE_ESSENTIAL_FIELDS = [
        'legacyDbServer', 'legacyDbPort', 'legacyDbDatabase',
        'legacyDbUser', 'legacyDbPassword', 'anthropicApiKey',
        'kunstmaanSourcePath', 'mappingPath', 'localeMap',
    ];

    private const FOURTEEN_STRIPPED_FIELDS = [
        'legacyDbCharset', 'legacyDbTablePrefix',
        'llmModel', 'llmTimeout', 'llmInterChunkDelay',
        'defaultEntities', 'defaultLocales', 'defaultSince',
        'defaultMaxPerEntity', 'dryRunDefault',
        'verifyCountTolerance', 'verifyUrlDiffThreshold',
        'seoTableName', 'redirectsTableName',
    ];

    public function testFragmentShapeHasNoExtends(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        self::assertNotFalse($body);
        self::assertStringNotContainsString(
            '{% extends',
            $body,
            'G-01 fix invariant per D-19: _settings.twig must be a fragment.',
        );
    }

    public function testBothSecretsUsePasswordField(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        // G-03 fix per D-19: both legacyDbPassword and anthropicApiKey must use forms.passwordField.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($body, 'forms.passwordField'),
            'G-03 fix invariant: both secret fields must use forms.passwordField macro.',
        );
        // Each of the two specific id values must appear within a passwordField block.
        self::assertMatchesRegularExpression(
            "/forms\\.passwordField\\(\\{[^}]*id:\\s*'legacyDbPassword'/s",
            $body,
        );
        self::assertMatchesRegularExpression(
            "/forms\\.passwordField\\(\\{[^}]*id:\\s*'anthropicApiKey'/s",
            $body,
        );
    }

    public function testFourH2GroupsConnectivityMappingAiFallback(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        self::assertSame(4, preg_match_all('/<h2>/', $body), 'expected exactly four <h2> tags');
        self::assertStringContainsString("'Connectivity'|t", $body);
        self::assertStringContainsString("'Mapping'|t", $body);
        self::assertStringContainsString("'AI'|t", $body);
        self::assertStringContainsString("'Fallback'|t", $body);
    }

    public function testNineEssentialFieldsPresent(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        foreach (self::NINE_ESSENTIAL_FIELDS as $name) {
            self::assertStringContainsString(
                "id: '{$name}'",
                $body,
                "essential field {$name} must appear in _settings.twig",
            );
        }
    }

    public function testFourteenStrippedFieldsAbsent(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        foreach (self::FOURTEEN_STRIPPED_FIELDS as $name) {
            self::assertStringNotContainsString(
                "id: '{$name}'",
                $body,
                "stripped field {$name} MUST NOT appear in _settings.twig (moved to config/kunstmaan-migrator.example.php per D-15)",
            );
        }
    }

    public function testLocaleMapDropdownShape(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        // D-21: legacy column = select-when-options-exist, free-text fallback
        self::assertStringContainsString(
            "type: localeOptions ? 'select' : 'singleline'",
            $body,
        );
        // D-22: craft column = always select
        self::assertMatchesRegularExpression(
            "/craft:\\s*\\{[^}]*type:\\s*'select'/s",
            $body,
        );
        // Both columns reference the pre-computed options arrays
        self::assertStringContainsString('options: localeOptions', $body);
        self::assertStringContainsString('options: siteHandleOptions', $body);
    }

    public function testPluginSettingsHtmlPayloadIncludesDropdownOptions(): void
    {
        $body = file_get_contents(self::PLUGIN_PATH);
        self::assertStringContainsString("'localeOptions' =>", $body);
        self::assertStringContainsString("'siteHandleOptions' =>", $body);
        self::assertStringContainsString('private function resolveLocaleOptions', $body);
        self::assertStringContainsString('private function resolveSiteHandleOptions', $body);
    }
}
