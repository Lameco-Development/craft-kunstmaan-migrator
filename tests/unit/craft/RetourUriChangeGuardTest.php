<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\RetourUriChangeGuard;
use nystudio107\retour\models\Settings;
use nystudio107\retour\Retour;
use PHPUnit\Framework\TestCase;

/**
 * A run moves URIs through states nobody ever visited; Retour's reflex redirect for each of
 * them made 480 redirects and rewrote two real ones on the Enreach staging copy.
 */
final class RetourUriChangeGuardTest extends TestCase
{
    private mixed $previous = null;

    protected function setUp(): void
    {
        $this->previous = Retour::$settings ?? null;
    }

    protected function tearDown(): void
    {
        Retour::$settings = $this->previous;
    }

    public function testRetoursUriChangeRedirectsAreOffForTheRestOfTheRun(): void
    {
        $settings = (new \ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
        $settings->createUriChangeRedirects = true;
        Retour::$settings = $settings;

        self::assertTrue(RetourUriChangeGuard::suspend());
        self::assertFalse(Retour::$settings->createUriChangeRedirects);
    }

    public function testWithoutLoadedRetourSettingsNothingHappens(): void
    {
        Retour::$settings = null;

        self::assertFalse(RetourUriChangeGuard::suspend());
    }
}
