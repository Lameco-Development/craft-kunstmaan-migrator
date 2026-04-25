<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\locale;

use lameco\kunstmaanmigrator\locale\LocalePreflight;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the LocalePreflight matching ladder.
 *
 * Only the pure helper (`languagePrefix`) is exercised here — `resolve()` and
 * `ensure()` reach into Plugin / Craft singletons and need a Craft bootstrap,
 * which Phase 1 / D-21 explicitly excluded from the unit suite. They are
 * exercised in the Phase 5 rehearsal pass instead.
 */
final class LocalePreflightTest extends TestCase
{
    public function testLanguagePrefixStripsBcp47SubtagAfterDash(): void
    {
        self::assertSame('nl', LocalePreflight::languagePrefix('nl-NL'));
        self::assertSame('de', LocalePreflight::languagePrefix('de-AT'));
        self::assertSame('en', LocalePreflight::languagePrefix('en-GB'));
    }

    public function testLanguagePrefixStripsPosixSubtagAfterUnderscore(): void
    {
        self::assertSame('nl', LocalePreflight::languagePrefix('nl_BE'));
        self::assertSame('zh', LocalePreflight::languagePrefix('zh_Hans_CN'));
    }

    public function testLanguagePrefixReturnsBareCodeUnchanged(): void
    {
        self::assertSame('nl', LocalePreflight::languagePrefix('nl'));
        self::assertSame('en', LocalePreflight::languagePrefix('en'));
    }

    public function testLanguagePrefixLowercasesInput(): void
    {
        self::assertSame('nl', LocalePreflight::languagePrefix('NL-NL'));
        self::assertSame('de', LocalePreflight::languagePrefix('DE'));
    }

    public function testLanguagePrefixHandlesEmptyString(): void
    {
        self::assertSame('', LocalePreflight::languagePrefix(''));
    }
}
