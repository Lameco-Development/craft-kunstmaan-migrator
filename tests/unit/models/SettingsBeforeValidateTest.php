<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\models;

use lameco\kunstmaanmigrator\db\KunstmaanEnvReader;
use lameco\kunstmaanmigrator\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The Kunstmaan project's own DATABASE_URL as a source of connection details
 * (Phase 4.1 / Plan 02 / D-07..D-09).
 *
 * This used to run in beforeValidate() and write onto the model's attributes.
 * That was harmless while settings only came from a config file; once the model
 * gained a control-panel Save button it meant the legacy project's credentials
 * being written into project config, committed and deployed. Resolution moved
 * into legacyConnection(), which returns rather than assigns — so these assert
 * on what the code connects with, not on what gets saved.
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

        $connection = $settings->legacyConnection();

        self::assertSame('h', $connection['host']);
        self::assertSame('u', $connection['user']);
        self::assertSame('p', $connection['password']);
        self::assertSame(3308, $connection['port']);
    }

    public function testPreservesPartiallyFilledOperatorValues(): void
    {
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p@dsn-host/d\n");
        $settings->legacyDbServer = 'operator-host';

        $connection = $settings->legacyConnection();

        // Operator-set field stays.
        self::assertSame('operator-host', $connection['host']);
        // Blanks filled from DSN.
        self::assertSame('u', $connection['user']);
        self::assertSame('p', $connection['password']);
    }

    public function testOperatorValuesWinWhenAllSet(): void
    {
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://x:y@z:9999/w\n");
        $settings->legacyDbServer = 'op-host';
        $settings->legacyDbUser = 'op-user';
        $settings->legacyDbPassword = 'op-pass';
        $settings->legacyDbPort = 5555; // explicit non-default operator port

        $connection = $settings->legacyConnection();

        self::assertSame('op-host', $connection['host']);
        self::assertSame('op-user', $connection['user']);
        self::assertSame('op-pass', $connection['password']);
        self::assertSame(5555, $connection['port']);
    }

    public function testNoOpWhenDsnAbsent(): void
    {
        // No .env files written — reader returns null DATABASE_URL.
        $settings = $this->makeSettingsWithEnv(null);

        $connection = $settings->legacyConnection();

        // A resolver has to answer with something connectable. Nothing configured
        // anywhere means the MySQL defaults, not nulls for the caller to handle.
        self::assertSame('127.0.0.1', $connection['host']);
        self::assertSame('root', $connection['user']);
        self::assertSame('', $connection['password']);
        self::assertSame(3306, $connection['port']);
    }

    public function testNoOpWhenDsnNonMysql(): void
    {
        // Reader exposes raw DSN (postgres) but parsed components are null per D-09.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=postgres://u:p@h:5432/d\n");

        $connection = $settings->legacyConnection();

        // D-09: the reader rejects a non-mysql scheme, so there is nothing to
        // resolve from and the defaults stand.
        self::assertSame('127.0.0.1', $connection['host']);
        self::assertSame('root', $connection['user']);
        self::assertSame('', $connection['password']);
        self::assertSame(3306, $connection['port']);
    }

    public function testPercentDecodedPasswordReachesField(): void
    {
        // Reader urldecodes the password before exposing it; Settings stores the decoded form.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p%40ss%2Fword@h/d\n");

        $connection = $settings->legacyConnection();

        self::assertSame('p@ss/word', $connection['password']);
    }

    public function testPortDefaultStaysUntouchedWhenDsnPortAbsent(): void
    {
        // DSN has no port; legacyDbPort stays at the property default 3306.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p@h/d\n");

        $connection = $settings->legacyConnection();

        self::assertSame(3306, $connection['port']);
    }

    public function testPortFillsFromDsnWhenAtDefault(): void
    {
        // legacyDbPort default 3306 acts as the "operator hasn't customized" sentinel.
        $settings = $this->makeSettingsWithEnv("DATABASE_URL=mysql://u:p@h:3307/d\n");

        $connection = $settings->legacyConnection();

        self::assertSame(3307, $connection['port']);
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
