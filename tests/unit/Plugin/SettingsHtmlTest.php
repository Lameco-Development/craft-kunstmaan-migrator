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
    private const TWIG_PATH = __DIR__ . '/../../../templates/_settings.twig';
    private const PLUGIN_PATH = __DIR__ . '/../../../src/Plugin.php';

    private const STABLE_SETTINGS_FIELDS = [
        'legacyDbServer', 'legacyDbPort', 'legacyDbDatabase',
        'legacyDbUser', 'legacyDbPassword', 'anthropicApiKey',
        'kunstmaanSourcePath', 'mappingPath', 'localeMap', 'defaultFilters',
        'joinFkRelations', 'seoEnabled', 'retourEnabled',
        'allowCpQueueActions', 'allowCpLiveQueueAction',
        'runRecordRetentionDays', 'artifactRetentionDays',
    ];

    private const STRIPPED_FIELDS = [
        'legacyDbCharset', 'legacyDbTablePrefix',
        'llmModel', 'llmTimeout', 'llmInterChunkDelay',
        'defaultEntities', 'defaultLocales', 'defaultSince',
        'defaultMaxPerEntity', 'dryRunDefault',
        'verifyCountTolerance', 'verifyUrlDiffThreshold',
        'seoTableName', 'redirectsTableName',
        'defaultEntryType', 'defaultBlockType',
        'proposeLayout', 'proposeProviders',
        'genericContentBlockOverrides', 'relationMirrorRules',
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

    public function testSecretsUseMaskedCpFields(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        // G-03 fix per D-19: legacyDbPassword must use the passwordField macro.
        self::assertMatchesRegularExpression(
            "/forms\\.passwordField\\(\\{[^}]*id:\\s*'legacyDbPassword'/s",
            $body,
        );
        // Phase 12 keeps Anthropic display read-only/masked in CP settings.
        self::assertMatchesRegularExpression(
            "/forms\\.textField\\(\\{[^}]*id:\\s*'anthropicApiKey'/s",
            $body,
        );
        self::assertStringContainsString("name: 'anthropicApiKeyMasked'", $body);
        self::assertStringContainsString('readonly: true', $body);
        self::assertStringNotContainsString('value: settings.anthropicApiKey,', $body);
    }

    public function testFiveStableH2Groups(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        self::assertSame(5, preg_match_all('/<h2>/', $body), 'expected exactly five <h2> tags');
        self::assertStringContainsString("'Connectivity'|t", $body);
        self::assertStringContainsString("'Mapping'|t", $body);
        self::assertStringContainsString("'Execution'|t", $body);
        self::assertStringContainsString("'Adapters'|t", $body);
        self::assertStringContainsString("'Retention'|t", $body);
        self::assertStringNotContainsString("'AI'|t", $body);
        self::assertStringNotContainsString("'Fallback'|t", $body);
    }

    public function testStableSettingsFieldsPresent(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        foreach (self::STABLE_SETTINGS_FIELDS as $name) {
            self::assertStringContainsString(
                "id: '{$name}'",
                $body,
                "stable field {$name} must appear in _settings.twig",
            );
        }
    }

    public function testAdvancedAndProjectShapeFieldsAbsent(): void
    {
        $body = file_get_contents(self::TWIG_PATH);
        foreach (self::STRIPPED_FIELDS as $name) {
            self::assertStringNotContainsString(
                "id: '{$name}'",
                $body,
                "stripped field {$name} MUST NOT appear in _settings.twig (advanced/project-shape settings stay config-only)",
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
        self::assertStringContainsString("\$event->roots['kunstmaan-migrator'] = dirname(__DIR__) . '/templates'", $body);
        self::assertStringContainsString("'kunstmaan-migrator/_settings.twig'", $body);
        self::assertStringContainsString("'localeOptions' =>", $body);
        self::assertStringContainsString("'siteHandleOptions' =>", $body);
        self::assertStringContainsString('private function resolveLocaleOptions', $body);
        self::assertStringContainsString('private function resolveSiteHandleOptions', $body);
    }
}
