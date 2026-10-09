<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\run\MaintenanceGuard;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryRedirectGuard;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryUriJobGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Retour's URI-change redirects, held for every pass that writes.
 *
 * Xidoor's load saves an entry in xidoorEn, propagates that slug, then saves each other
 * site with its own — and Retour stored a 301 for each change: 35 redirects from paths
 * nobody ever visited. The hold rides on the maintenance guard both callers already arm
 * around every writing pass.
 */
final class RedirectHoldTest extends TestCase
{
    private InMemoryRedirectGuard $redirects;

    protected function setUp(): void
    {
        $this->redirects = new InMemoryRedirectGuard();
    }

    private function guard(): MaintenanceGuard
    {
        return new MaintenanceGuard(new InMemoryUriJobGuard(), new InMemoryElementWriter(), $this->redirects);
    }

    public function testAUriChangeInsideARunCreatesNoRedirectAndTheSettingComesBackAfter(): void
    {
        $redirects = $this->redirects;

        $this->guard()->guard(new RunSettings(), new RunTally(), static function() use ($redirects): void {
            $redirects->uriChanged('/companies', '/bedrijven');
        });

        self::assertSame([], $redirects->created);
        self::assertTrue($redirects->createUriChangeRedirects, 'an editor\'s slug change after the run is redirected again');
        $redirects->uriChanged('/bedrijven', '/onze-bedrijven');
        self::assertSame(['/bedrijven -> /onze-bedrijven'], $redirects->created);
    }

    public function testTheSettingComesBackWhenThePassThrows(): void
    {
        try {
            $this->guard()->guard(new RunSettings(), new RunTally(), static function(): void {
                throw new RuntimeException('deadlock');
            });
            self::fail('the exception propagates');
        } catch (RuntimeException) {
        }

        self::assertTrue($this->redirects->createUriChangeRedirects);
        self::assertSame(['suspend', 'resume'], $this->redirects->transitions);
    }

    /** `--entries-only` settles no URIs but still writes, and still changes them. */
    public function testAnEntriesOnlyRunIsHeldToo(): void
    {
        $redirects = $this->redirects;

        $this->guard()->guard(new RunSettings(entriesOnly: true), new RunTally(), static function() use ($redirects): void {
            $redirects->uriChanged('/contact', '/contactez-nous');
        });

        self::assertSame([], $redirects->created);
        self::assertTrue($redirects->createUriChangeRedirects);
    }

    public function testADryRunWritesNothingSoHoldsNothing(): void
    {
        $this->guard()->guard(new RunSettings(dryRun: true), new RunTally(), static function(): void {
        });

        self::assertSame([], $this->redirects->transitions);
    }

    public function testAProjectThatTurnedItOffKeepsItOff(): void
    {
        $this->redirects->createUriChangeRedirects = false;

        $this->guard()->guard(new RunSettings(), new RunTally(), static function(): void {
        });

        self::assertFalse($this->redirects->createUriChangeRedirects);
    }

    /** The batched job arms per batch and disarms after it: each pair suspends and resumes once. */
    public function testArmAndDisarmPairUp(): void
    {
        $guard = $this->guard();
        $guard->arm(new RunSettings());
        $guard->arm(new RunSettings());
        $guard->disarm(new RunTally());
        $guard->disarm(new RunTally());

        self::assertSame(['suspend', 'resume'], $this->redirects->transitions);
        self::assertTrue($this->redirects->createUriChangeRedirects);
    }
}
