<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\models;

use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\source\KunstmaanEnvReader;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Settings::beforeValidate() (Phase 4.1 / Plan 02 / D-07..D-09).
 *
 * Pure-PHPUnit shape: no Craft bootstrap. The hook uses a protected
 * `getEnvReader()` test seam — tests subclass Settings and override the seam
 * to return a real KunstmaanEnvReader pre-loaded via its `loadFromPath()`
 * test seam (the same escape hatch KunstmaanEnvReaderTest uses). Reader is
 * `final`, so we cannot mock by subclass — but a real reader fed real .env
 * content gives identical observable behaviour with zero magic.
 *
 * Eight outcome states covered (Plan 04.1-02 <behavior>):
 *   - All blanks + reader returns mysql DSN → all 4 + port fill from DSN
 *   - Partial operator values + reader present → only blanks fill
 *   - All operator-set → no field changes (operator wins)
 *   - All blanks + no .env files → no-op (DSN absent)
 *   - All blanks + DSN non-mysql (parsed components null per D-09) → no-op
 *   - Percent-decoded password reaches legacyDbPassword unchanged
 *   - DSN has no port → legacyDbPort default 3306 stays untouched
 *   - DSN has port + legacyDbPort still at default 3306 → port fills from DSN
 */
final class SettingsBeforeValidateTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kuma-settings-before-validate-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (['.env', '.env.example'] as $f) {
                $p = $this->tmpDir . '/' . $f;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testFillsAllBlanksFromDsn(): void
    {
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p@h:3308/d\n");

        $settings->beforeValidate();

        self::assertSame('h', $settings->legacyDbServer);
        self::assertSame('u', $settings->legacyDbUser);
        self::assertSame('p', $settings->legacyDbPassword);
        self::assertSame('d', $settings->legacyDbDatabase);
        self::assertSame(3308, $settings->legacyDbPort);
    }

    public function testPreservesPartiallyFilledOperatorValues(): void
    {
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p@dsn-host/d\n");
        $settings->legacyDbServer = 'operator-host';

        $settings->beforeValidate();

        // Operator-set field stays.
        self::assertSame('operator-host', $settings->legacyDbServer);
        // Blanks filled from DSN.
        self::assertSame('u', $settings->legacyDbUser);
        self::assertSame('p', $settings->legacyDbPassword);
        self::assertSame('d', $settings->legacyDbDatabase);
    }

    public function testOperatorValuesWinWhenAllSet(): void
    {
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://x:y@z:9999/w\n");
        $settings->legacyDbServer = 'op-host';
        $settings->legacyDbUser = 'op-user';
        $settings->legacyDbPassword = 'op-pass';
        $settings->legacyDbDatabase = 'op-db';
        $settings->legacyDbPort = 5555; // explicit non-default operator port

        $settings->beforeValidate();

        self::assertSame('op-host', $settings->legacyDbServer);
        self::assertSame('op-user', $settings->legacyDbUser);
        self::assertSame('op-pass', $settings->legacyDbPassword);
        self::assertSame('op-db', $settings->legacyDbDatabase);
        self::assertSame(5555, $settings->legacyDbPort);
    }

    public function testNoOpWhenDsnAbsent(): void
    {
        // No .env files written — reader returns null DATABASE_URL.
        $settings = $this->makeSettingsWithEnv(null);

        $settings->beforeValidate();

        self::assertNull($settings->legacyDbServer);
        self::assertNull($settings->legacyDbUser);
        self::assertNull($settings->legacyDbPassword);
        self::assertNull($settings->legacyDbDatabase);
        self::assertSame(3306, $settings->legacyDbPort); // default untouched
    }

    public function testGenericContentBlockOverridesAreOperatorConfigurable(): void
    {
        $settings = $this->makeSettingsWithEnv(null);
        $settings->genericContentBlockOverrides = [
            'pageBuilder' => [
                'blockType' => 'richTextBlock',
                'fieldHandle' => 'bodyCopy',
            ],
        ];

        $safeRule = array_values(array_filter(
            $settings->rules(),
            static fn(array $rule): bool => ($rule[1] ?? null) === 'safe'
                && in_array('genericContentBlockOverrides', (array) ($rule[0] ?? []), true),
        ));

        self::assertNotEmpty($safeRule);
        self::assertSame(
            'bodyCopy',
            $settings->genericContentBlockOverrides['pageBuilder']['fieldHandle'],
        );
    }

    public function testRelationMirrorRulesAreOperatorConfigurable(): void
    {
        $settings = $this->makeSettingsWithEnv(null);
        $settings->relationMirrorRules = [[
            'targetField' => 'contactCta.teamMember',
            'sourceField' => 'caseTeamMembers',
        ]];

        $safeRule = array_values(array_filter(
            $settings->rules(),
            static fn(array $rule): bool => ($rule[1] ?? null) === 'safe'
                && in_array('relationMirrorRules', (array) ($rule[0] ?? []), true),
        ));

        self::assertNotEmpty($safeRule);
        self::assertSame(
            'contactCta.teamMember',
            $settings->relationMirrorRules[0]['targetField'],
        );
    }

    public function testStableCpExecutionAndRetentionDefaults(): void
    {
        $settings = $this->makeSettingsWithEnv(null);

        self::assertTrue($settings->allowCpQueueActions);
        self::assertFalse($settings->allowCpLiveQueueAction);
        self::assertSame(30, $settings->runRecordRetentionDays);
        self::assertSame(30, $settings->artifactRetentionDays);
        self::assertSame([], $settings->defaultFilters);
    }

    public function testStableCpExecutionAndRetentionFieldsAreValidated(): void
    {
        $settings = $this->makeSettingsWithEnv(null);

        $booleanRules = array_values(array_filter(
            $settings->rules(),
            static fn(array $rule): bool => ($rule[1] ?? null) === 'boolean'
                && in_array('allowCpQueueActions', (array) ($rule[0] ?? []), true)
                && in_array('allowCpLiveQueueAction', (array) ($rule[0] ?? []), true),
        ));
        $integerRules = array_values(array_filter(
            $settings->rules(),
            static fn(array $rule): bool => ($rule[1] ?? null) === 'integer'
                && in_array('runRecordRetentionDays', (array) ($rule[0] ?? []), true)
                && in_array('artifactRetentionDays', (array) ($rule[0] ?? []), true),
        ));
        $safeRules = array_values(array_filter(
            $settings->rules(),
            static fn(array $rule): bool => ($rule[1] ?? null) === 'safe'
                && in_array('defaultFilters', (array) ($rule[0] ?? []), true),
        ));

        self::assertNotEmpty($booleanRules);
        self::assertNotEmpty($integerRules);
        self::assertNotEmpty($safeRules);
    }

    public function testNoOpWhenDsnNonMysql(): void
    {
        // Reader exposes raw DSN (postgres) but parsed components are null per D-09.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=postgres://u:p@h:5432/d\n");

        $settings->beforeValidate();

        self::assertNull($settings->legacyDbServer);
        self::assertNull($settings->legacyDbUser);
        self::assertNull($settings->legacyDbPassword);
        self::assertNull($settings->legacyDbDatabase);
        self::assertSame(3306, $settings->legacyDbPort);
    }

    public function testPercentDecodedPasswordReachesField(): void
    {
        // Reader urldecodes the password before exposing it; Settings stores the decoded form.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p%40ss%2Fword@h/d\n");

        $settings->beforeValidate();

        self::assertSame('p@ss/word', $settings->legacyDbPassword);
    }

    public function testPortDefaultStaysUntouchedWhenDsnPortAbsent(): void
    {
        // DSN has no port; legacyDbPort stays at the property default 3306.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p@h/d\n");

        $settings->beforeValidate();

        self::assertSame(3306, $settings->legacyDbPort);
    }

    public function testPortFillsFromDsnWhenAtDefault(): void
    {
        // legacyDbPort default 3306 acts as the "operator hasn't customized" sentinel.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p@h:3307/d\n");

        $settings->beforeValidate();

        self::assertSame(3307, $settings->legacyDbPort);
    }

    /**
     * Build a real KunstmaanEnvReader pre-loaded from a temp directory.
     * When `$envContents` is null, no .env file is written — the reader
     * loads, finds nothing, and all accessors return null.
     */
    private function makeReader(?string $envContents): KunstmaanEnvReader
    {
        if ($envContents !== null) {
            file_put_contents($this->tmpDir . '/.env.example', $envContents);
        }
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        return $reader;
    }

    /**
     * Build a Settings subclass whose protected getEnvReader() seam returns
     * the supplied reader instead of routing through Plugin::getInstance().
     */
    private function makeSettingsWithEnv(?string $envContents): Settings
    {
        $reader = $this->makeReader($envContents);
        return new class($reader) extends Settings {
            public function __construct(private KunstmaanEnvReader $injectedReader)
            {
                parent::__construct();
            }

            /**
             * Override the env-attribute parser behaviour: instantiating it
             * routes through Yii::createObject(), which requires a Yii bootstrap
             * the unit suite intentionally lacks. Tests inject the env reader
             * directly via getEnvReader() and don't depend on $VAR-string parsing.
             */
            public function behaviors(): array
            {
                return [];
            }

            protected function getEnvReader(): KunstmaanEnvReader
            {
                return $this->injectedReader;
            }
        };
    }
}
