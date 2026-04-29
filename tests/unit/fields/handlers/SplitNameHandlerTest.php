<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\fields\handlers;

use lameco\kunstmaanmigrator\fields\handlers\SplitNameHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use lameco\kunstmaanmigrator\finalize\CkeditorRewriterService;
use lameco\kunstmaanmigrator\load\AssetPathResolver;
use lameco\kunstmaanmigrator\load\MigrationStateReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 5 / TST-01 / D-10 — direct unit tests for SplitNameHandler.
 *
 * Pure stateless name splitter; no DI beyond the ResolverContext shape (which
 * the handler does not actually consume). Exercises the public split() helper
 * directly + the resolve() options.part dispatch.
 *
 * Coverage target: ≥ 70.0% line coverage on
 * src/fields/handlers/SplitNameHandler.php (D-08 directory prefix gate).
 */
final class SplitNameHandlerTest extends TestCase
{
    private function ctx(): ResolverContext
    {
        return new ResolverContext(
            siteId: 1,
            siteHandle: 'default',
            state: $this->createStub(MigrationStateReader::class),
            ck: $this->createStub(CkeditorRewriterService::class),
            paths: $this->createStub(AssetPathResolver::class),
            siteMap: ['nl' => 1],
            legacyDb: null,
        );
    }

    // ---------- split() pure helper ----------

    public function testSplitsTwoTokenName(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('John Smith');
        self::assertSame('John', $parts['firstName']);
        self::assertSame('Smith', $parts['lastName']);
        self::assertSame('', $parts['prefix']);
        self::assertSame('', $parts['infix']);
        self::assertSame('', $parts['suffix']);
    }

    public function testSplitsThreeTokenNameWithoutInfix(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('Mary Jane Watson');
        self::assertSame('Mary', $parts['firstName']);
        self::assertSame('', $parts['infix']);
        // 'Jane Watson' is the lastName because no token in the middle is an infix.
        self::assertSame('Jane Watson', $parts['lastName']);
    }

    public function testSingleTokenIsFirstName(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('Madonna');
        self::assertSame('Madonna', $parts['firstName']);
        self::assertSame('', $parts['lastName']);
    }

    public function testEmptyInputReturnsAllEmptyParts(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('');
        foreach (['firstName', 'infix', 'lastName', 'prefix', 'suffix'] as $key) {
            self::assertSame('', $parts[$key], "expected '$key' empty");
        }
    }

    public function testTrimsLeadingAndTrailingWhitespaceAndCollapsesInner(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('  John   Smith  ');
        self::assertSame('John', $parts['firstName']);
        self::assertSame('Smith', $parts['lastName']);
    }

    public function testHandlesAccentedCharacters(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('Renée Müller');
        self::assertSame('Renée', $parts['firstName']);
        self::assertSame('Müller', $parts['lastName']);
    }

    public function testStripsLeadingAcademicPrefix(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('Dr. Jan Smith');
        self::assertSame('Dr.', $parts['prefix']);
        self::assertSame('Jan', $parts['firstName']);
        self::assertSame('Smith', $parts['lastName']);
    }

    public function testRecognisesDutchInfixSingleToken(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('Jan de Vries');
        self::assertSame('Jan', $parts['firstName']);
        self::assertSame('de', $parts['infix']);
        self::assertSame('Vries', $parts['lastName']);
    }

    public function testRecognisesDutchInfixMultiToken(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('Jan van der Meer');
        self::assertSame('Jan', $parts['firstName']);
        self::assertSame('van der', $parts['infix']);
        self::assertSame('Meer', $parts['lastName']);
    }

    public function testStripsTrailingSuffix(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('John Smith Jr.');
        self::assertSame('John', $parts['firstName']);
        self::assertSame('Smith', $parts['lastName']);
        self::assertSame('Jr.', $parts['suffix']);
    }

    public function testStripsTrailingAcademicSuffix(): void
    {
        $h = new SplitNameHandler();
        $parts = $h->split('Arjen Sijtsma MSc');
        self::assertSame('Arjen', $parts['firstName']);
        self::assertSame('Sijtsma', $parts['lastName']);
        self::assertSame('MSc', $parts['suffix']);
    }

    public function testInfixOnlyTailPromotesLastInfixToLastName(): void
    {
        // Defensive fallback: "Jan van" — infix consumed everything → promote
        // the last infix token to lastName so entry doesn't save with empty lastName.
        $h = new SplitNameHandler();
        $parts = $h->split('Jan van');
        self::assertSame('Jan', $parts['firstName']);
        self::assertSame('', $parts['infix']);
        self::assertSame('van', $parts['lastName']);
    }

    // ---------- resolve() options dispatch ----------

    public function testIdReturnsSplitName(): void
    {
        self::assertSame('splitName', (new SplitNameHandler())->id());
    }

    public function testResolveReturnsRequestedPart(): void
    {
        $h = new SplitNameHandler();
        $ctx = $this->ctx();
        self::assertSame('Jan', $h->resolve('Jan van der Meer', $ctx, ['part' => 'firstName']));
        self::assertSame('van der', $h->resolve('Jan van der Meer', $ctx, ['part' => 'infix']));
        self::assertSame('Meer', $h->resolve('Jan van der Meer', $ctx, ['part' => 'lastName']));
    }

    public function testResolveReturnsEmptyForNullOrEmptyValue(): void
    {
        $h = new SplitNameHandler();
        $ctx = $this->ctx();
        self::assertSame('', $h->resolve(null, $ctx, ['part' => 'firstName']));
        self::assertSame('', $h->resolve('', $ctx, ['part' => 'lastName']));
    }

    public function testResolveThrowsForMissingOrInvalidPart(): void
    {
        $h = new SplitNameHandler();
        $ctx = $this->ctx();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handlerOptions.part must be one of');
        $h->resolve('John Smith', $ctx, ['part' => 'middleName']);
    }
}
