<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\locale;

use lameco\kunstmaanmigrator\locale\LocalePreflight;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.1 / LOC-03 (D-11..D-13) — characterization tests for the Rung 0
 * advisory comparison helper.
 *
 * The helper is a pure static function: it compares the Kunstmaan project's
 * env DEFAULT_LOCALE against the first key of Settings::localeMap and returns
 * one of four status codes (silent / no-map / ok / warn). The helper does NOT
 * touch Plugin / Craft singletons — it's the pure-helper escape hatch
 * mirroring `LocalePreflight::languagePrefix()` so the doctor consult logic
 * is testable without a Craft bootstrap.
 *
 * Outcomes (per plan):
 *   - silent: env null/blank — D-13 no doctor row at all
 *   - no-map: env present, localeMap empty — operator hasn't curated yet (INFO row in caller)
 *   - ok:    env matches first localeMap key — OK row in caller
 *   - warn:  env mismatches first localeMap key — D-12 verbatim WARN copy in caller
 */
final class LocalePreflightRung0Test extends TestCase
{
    public function testReturnsSilentWhenEnvLocaleNull(): void
    {
        $result = LocalePreflight::compareEnvDefaultLocaleToLocaleMap(null, ['nl' => 'default']);

        self::assertSame('silent', $result['status']);
        self::assertNull($result['envLocale']);
        self::assertNull($result['firstHandle']);
    }

    public function testReturnsSilentWhenEnvLocaleEmptyString(): void
    {
        $result = LocalePreflight::compareEnvDefaultLocaleToLocaleMap('', ['nl' => 'default', 'en' => 'english']);

        self::assertSame('silent', $result['status']);
        self::assertNull($result['envLocale']);
        self::assertNull($result['firstHandle']);
    }

    public function testReturnsNoMapWhenLocaleMapEmpty(): void
    {
        $result = LocalePreflight::compareEnvDefaultLocaleToLocaleMap('nl', []);

        self::assertSame('no-map', $result['status']);
        self::assertSame('nl', $result['envLocale']);
        self::assertNull($result['firstHandle']);
    }

    public function testReturnsOkWhenEnvLocaleMatchesFirstKey(): void
    {
        $result = LocalePreflight::compareEnvDefaultLocaleToLocaleMap(
            'nl',
            ['nl' => 'default', 'en' => 'english'],
        );

        self::assertSame('ok', $result['status']);
        self::assertSame('nl', $result['envLocale']);
        self::assertSame('nl', $result['firstHandle']);
    }

    public function testReturnsWarnWhenEnvLocaleMismatchesFirstKey(): void
    {
        $result = LocalePreflight::compareEnvDefaultLocaleToLocaleMap(
            'nl',
            ['en' => 'english', 'nl' => 'default'],
        );

        self::assertSame('warn', $result['status']);
        self::assertSame('nl', $result['envLocale']);
        self::assertSame('en', $result['firstHandle']);
    }
}
