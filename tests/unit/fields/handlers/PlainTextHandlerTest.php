<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

use lameco\kunstmaanmigrator\fields\handlers\PlainTextHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for PlainTextHandler.
 *
 * 4 modes: plain / ckeditor / link / dropdown. Pure mode-switch; the only
 * external surface is ResolverContext::$ck (CKEditor rewriter, exercised in
 * ckeditor mode) and ResolverContext::$state (MigrationStateReader, exercised
 * in link mode for the entry-id resolution branch).
 *
 * Coverage target: ≥ 70.0% line coverage on
 * src/fields/handlers/PlainTextHandler.php (D-08 directory prefix gate via
 * tools/check-coverage.php).
 */
final class PlainTextHandlerTest extends TestCase
{
    private function ctx(
        ?MigrationStateReader $state = null,
        ?CkeditorRewriterService $ck = null,
    ): ResolverContext {
        return new ResolverContext(
            siteId: 1,
            siteHandle: 'default',
            state: $state ?? $this->createStub(MigrationStateReader::class),
            ck: $ck ?? $this->createStub(CkeditorRewriterService::class),
            paths: $this->createStub(AssetPathResolver::class),
            siteMap: ['nl' => 1, 'en' => 2],
            legacyDb: null,
        );
    }

    // ---------- ctor + id() ----------

    public function testIdReturnsModeForKnownNonPlainMode(): void
    {
        self::assertSame('ckeditor', (new PlainTextHandler('ckeditor'))->id());
        self::assertSame('link', (new PlainTextHandler('link'))->id());
        self::assertSame('dropdown', (new PlainTextHandler('dropdown'))->id());
    }

    public function testIdReturnsPlainForDefaultMode(): void
    {
        self::assertSame('plain', (new PlainTextHandler())->id());
        self::assertSame('plain', (new PlainTextHandler('plain'))->id());
    }

    public function testUnknownModeThrowsAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("unknown mode 'bogus'");
        new PlainTextHandler('bogus');
    }

    // ---------- plain mode ----------

    public function testPlainModeCastsScalarToString(): void
    {
        $h = new PlainTextHandler('plain');
        self::assertSame('hello', $h->resolve('hello', $this->ctx()));
        self::assertSame('42', $h->resolve(42, $this->ctx()));
        self::assertSame('1', $h->resolve(true, $this->ctx()));
    }

    public function testPlainModeReturnsEmptyStringForNull(): void
    {
        $h = new PlainTextHandler('plain');
        self::assertSame('', $h->resolve(null, $this->ctx()));
    }

    // ---------- ckeditor mode ----------

    public function testCkeditorModeReturnsEmptyForNullOrEmptyString(): void
    {
        $h = new PlainTextHandler('ckeditor');
        self::assertSame('', $h->resolve(null, $this->ctx()));
        self::assertSame('', $h->resolve('', $this->ctx()));
    }

    public function testCkeditorModeRoutesNonEmptyValueThroughRewriter(): void
    {
        $ck = $this->createMock(CkeditorRewriterService::class);
        $ck->expects($this->once())
            ->method('rewrite')
            ->with('<p>body</p>', 1)
            ->willReturn('<p>rewritten</p>');

        $h = new PlainTextHandler('ckeditor');
        self::assertSame('<p>rewritten</p>', $h->resolve('<p>body</p>', $this->ctx(ck: $ck)));
    }

    // ---------- link mode ----------

    public function testLinkModeReturnsNullForEmptyOrNull(): void
    {
        $h = new PlainTextHandler('link');
        self::assertNull($h->resolve(null, $this->ctx()));
        self::assertNull($h->resolve('', $this->ctx()));
    }

    public function testLinkModeClassifiesEmailAndAddsMailtoPrefix(): void
    {
        $h = new PlainTextHandler('link');
        $result = $h->resolve('john@example.com', $this->ctx());
        self::assertSame(['type' => 'email', 'value' => 'mailto:john@example.com'], $result);
    }

    public function testLinkModePreservesExistingMailtoPrefix(): void
    {
        $h = new PlainTextHandler('link');
        $result = $h->resolve('mailto:john@example.com', $this->ctx());
        self::assertSame(['type' => 'email', 'value' => 'mailto:john@example.com'], $result);
    }

    public function testLinkModeResolvesEntryRefForSlashPrefixedPath(): void
    {
        $state = $this->createMock(MigrationStateReader::class);
        $state->expects($this->once())
            ->method('getTargetId')
            ->with('page', '/about', 1)
            ->willReturn(99);

        $h = new PlainTextHandler('link');
        $result = $h->resolve('/about', $this->ctx(state: $state));
        self::assertSame(['type' => 'entry', 'value' => 99], $result);
    }

    public function testLinkModeFallsBackToUrlWhenEntryLookupMisses(): void
    {
        $state = $this->createStub(MigrationStateReader::class);
        $state->method('getTargetId')->willReturn(null);

        $h = new PlainTextHandler('link');
        $result = $h->resolve('/missing-page', $this->ctx(state: $state));
        self::assertSame(['type' => 'url', 'value' => '/missing-page'], $result);
    }

    public function testLinkModeReturnsUrlForHttpScheme(): void
    {
        $h = new PlainTextHandler('link');
        $result = $h->resolve('https://example.com', $this->ctx());
        self::assertSame(['type' => 'url', 'value' => 'https://example.com'], $result);
    }

    // ---------- dropdown mode ----------

    public function testDropdownModeReturnsValueWhenInAllowedList(): void
    {
        $h = new PlainTextHandler('dropdown');
        $result = $h->resolve('red', $this->ctx(), ['allowed' => ['red', 'green', 'blue']]);
        self::assertSame('red', $result);
    }

    public function testDropdownModeReturnsNullForUnknownValueByDefault(): void
    {
        $h = new PlainTextHandler('dropdown');
        $result = $h->resolve('purple', $this->ctx(), ['allowed' => ['red', 'green', 'blue']]);
        self::assertNull($result);
    }

    public function testDropdownModeThrowsForUnknownValueWhenOnUnknownIsThrow(): void
    {
        $h = new PlainTextHandler('dropdown');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("unknown value 'purple'");
        $h->resolve('purple', $this->ctx(), [
            'allowed' => ['red', 'green', 'blue'],
            'onUnknown' => 'throw',
        ]);
    }

    public function testDropdownModeRequiresAllowedOption(): void
    {
        $h = new PlainTextHandler('dropdown');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires 'allowed' option");
        $h->resolve('red', $this->ctx(), []);
    }

    public function testDropdownModeTreatsNullAsEmptyStringLookup(): void
    {
        $h = new PlainTextHandler('dropdown');
        // null casts to '' which is not in ['red'] → returns null (skip default).
        self::assertNull($h->resolve(null, $this->ctx(), ['allowed' => ['red']]));
        // But '' IS in [''] → returns ''.
        self::assertSame('', $h->resolve(null, $this->ctx(), ['allowed' => ['', 'red']]));
    }
}
