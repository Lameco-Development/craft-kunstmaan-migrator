<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\source;

use lameco\kunstmaanmigrator\source\KunstmaanEnvReader;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for KunstmaanEnvReader (Phase 4.1 / Plan 01 / D-01..D-09).
 *
 * Pure-PHPUnit shape: no Craft bootstrap. The reader exposes a `loadFromPath()`
 * test seam that takes a directory path directly, side-stepping the
 * Plugin::getInstance()->kunstmaanSourcePathResolver round-trip used by
 * production code. This mirrors LocalePreflightTest's static-helper escape
 * hatch.
 *
 * Twelve outcome states covered (Plan 04.1-01 <behavior>):
 *   - .env / .env.example precedence (4 tests: example-only, env-overrides,
 *     missing-source, invalid-source)
 *   - 2-key whitelist enforcement (1 test — secrets must NEVER reach the reader)
 *   - DSN parsing edge cases (4 tests: mysql full, mysql+pdo alias,
 *     non-mysql reject, percent-decode)
 *   - DEFAULT_LOCALE read (1 test)
 *   - Defensive failure modes (2 tests: no env files at all, malformed input)
 */
final class KunstmaanEnvReaderTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kuma-env-reader-' . uniqid('', true);
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

    public function testReadsDatabaseUrlFromEnvExampleWhenEnvAbsent(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DATABASE_URL=mysql://u:p@h:3306/db\n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertSame('mysql://u:p@h:3306/db', $reader->getDatabaseUrl());
    }

    public function testEnvOverridesEnvExamplePerKey(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DATABASE_URL=mysql://a:a@a:3306/a\n");
        file_put_contents($this->tmpDir . '/.env', "DATABASE_URL=mysql://b:b@b:3306/b\n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertSame('mysql://b:b@b:3306/b', $reader->getDatabaseUrl());
    }

    public function testIgnoresKeysOutsideWhitelist(): void
    {
        $contents = <<<ENV
MOLLIE_API_KEY=test_secret_should_never_appear
SAML_PRIVATE_KEY=should_never_appear
DATABASE_URL=mysql://u:p@h:3306/db
ELASTICSEARCH_URL=http://es:9200
GTM_ID=GTM-XXXX
RECAPTCHA_SECRET=secret
DEFAULT_LOCALE=nl
MULTI_LANGUAGE=true
REQUIRED_LOCALES=nl,en
ENV;
        file_put_contents($this->tmpDir . '/.env.example', $contents);
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertSame('mysql://u:p@h:3306/db', $reader->getDatabaseUrl());
        self::assertSame('nl', $reader->getDefaultLocale());
        // The reader exposes ONLY 2 whitelisted accessors; no MOLLIE/SAML/etc.
        // surface exists. found() should report only the whitelisted keys.
        $found = $reader->found();
        self::assertContains('DATABASE_URL', $found);
        self::assertContains('DEFAULT_LOCALE', $found);
        self::assertCount(2, $found);
    }

    public function testReturnsBlankWhenSourcePathUnset(): void
    {
        $reader = new KunstmaanEnvReader();
        // No loadFromPath call — simulates production code path where resolver returned null.
        // We invoke nothing; defensive accessors must return null without exception.
        self::assertNull($reader->getDatabaseUrl());
        self::assertNull($reader->getDefaultLocale());
        self::assertNull($reader->getDsnHost());
        self::assertNull($reader->getDsnPort());
        self::assertNull($reader->getDsnUser());
        self::assertNull($reader->getDsnPassword());
        self::assertNull($reader->getDsnDatabase());
    }

    public function testReturnsBlankWhenSourcePathInvalid(): void
    {
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath('/nonexistent/path/does/not/exist/' . uniqid());
        self::assertNull($reader->getDatabaseUrl());
        self::assertNull($reader->getDefaultLocale());
    }

    public function testReturnsBlankWhenNoEnvFiles(): void
    {
        // tmpDir exists but is empty — no .env / .env.example
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertNull($reader->getDatabaseUrl());
        self::assertNull($reader->getDefaultLocale());
    }

    public function testParsesMysqlDsnHostUserPasswordDatabasePort(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DATABASE_URL=mysql://user:pass@host:3307/dbname\n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertSame('host', $reader->getDsnHost());
        self::assertSame('user', $reader->getDsnUser());
        self::assertSame('pass', $reader->getDsnPassword());
        self::assertSame('dbname', $reader->getDsnDatabase());
        self::assertSame(3307, $reader->getDsnPort());
    }

    public function testParsesMysqlPdoSchemeAlias(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DATABASE_URL=mysql+pdo://user:pass@host/db\n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertSame('host', $reader->getDsnHost());
        self::assertSame('user', $reader->getDsnUser());
        self::assertSame('db', $reader->getDsnDatabase());
    }

    public function testRejectsNonMysqlScheme(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DATABASE_URL=postgres://user:pass@host:5432/db\n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        // Raw DSN string still exposed (so doctor / consumers can see it),
        // but parsed components return null per D-09 (only mysql honored).
        self::assertSame('postgres://user:pass@host:5432/db', $reader->getDatabaseUrl());
        self::assertNull($reader->getDsnHost());
        self::assertNull($reader->getDsnUser());
        self::assertNull($reader->getDsnPassword());
        self::assertNull($reader->getDsnDatabase());
        self::assertNull($reader->getDsnPort());
    }

    public function testPercentDecodesPasswordSpecialChars(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DATABASE_URL=mysql://u:p%40ss%2Fword@h/db\n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertSame('p@ss/word', $reader->getDsnPassword());
    }

    public function testReadsDefaultLocale(): void
    {
        file_put_contents($this->tmpDir . '/.env.example', "DEFAULT_LOCALE=nl\n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertSame('nl', $reader->getDefaultLocale());
    }

    public function testMalformedEnvDoesNotThrow(): void
    {
        // Symfony Dotenv throws on malformed input — reader MUST swallow.
        file_put_contents($this->tmpDir . '/.env.example', "==no equals==\n!@#\$%\n  \n garbage with spaces \n");
        $reader = new KunstmaanEnvReader();
        $reader->loadFromPath($this->tmpDir);
        self::assertNull($reader->getDatabaseUrl());
        self::assertNull($reader->getDefaultLocale());
    }
}
