<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\AssetMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use yii\base\Component;
use yii\base\Event;

/**
 * withClassEventDetached() — the mechanism behind the size-cap bypass retry.
 * The contract: class-level handlers are gone for exactly the callback's
 * duration and come back verbatim (order included) even when the callback
 * throws. The starter-kit's cap must be back in force for editor uploads the
 * moment the retry returns.
 *
 * Exercised against a plain component; the Asset save itself needs a booted
 * Craft and stays with the integration suite.
 */
final class AssetMigrationServiceEventDetachTest extends TestCase
{
    private const EVENT = 'probe';

    protected function tearDown(): void
    {
        Event::off(DetachProbeComponent::class, self::EVENT);
    }

    public function testHandlersAreDetachedDuringTheCallbackAndRestoredAfter(): void
    {
        $hits = [];
        Event::on(DetachProbeComponent::class, self::EVENT, static function() use (&$hits): void {
            $hits[] = 'a';
        });
        Event::on(DetachProbeComponent::class, self::EVENT, static function() use (&$hits): void {
            $hits[] = 'b';
        });

        $during = $this->detached(
            static fn(): bool => Event::hasHandlers(DetachProbeComponent::class, self::EVENT),
        );

        self::assertFalse($during);
        self::assertTrue(Event::hasHandlers(DetachProbeComponent::class, self::EVENT));

        // Restored in attach order — other code may depend on handler order.
        (new DetachProbeComponent())->trigger(self::EVENT);
        self::assertSame(['a', 'b'], $hits);
    }

    public function testTheRestoreSurvivesAThrowingCallback(): void
    {
        Event::on(DetachProbeComponent::class, self::EVENT, static function(): void {
        });

        try {
            $this->detached(static function(): never {
                throw new RuntimeException('save blew up');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException) {
        }

        self::assertTrue(Event::hasHandlers(DetachProbeComponent::class, self::EVENT));
    }

    public function testTheCallbackResultIsReturnedWhenNothingWasAttached(): void
    {
        self::assertSame('through', $this->detached(static fn(): string => 'through'));
    }

    public function testTheSizeCapRetryBailsWhenTheTempFileVanished(): void
    {
        $service = new AssetMigrationService();

        $asset = (new ReflectionMethod($service, 'retrySaveWithoutSizeCap'))
            ->invoke($service, [], 'big.pdf', 1, '/nowhere/kmig-' . uniqid());

        self::assertNull($asset);
    }

    private function detached(callable $fn): mixed
    {
        return (new ReflectionMethod(AssetMigrationService::class, 'withClassEventDetached'))
            ->invoke(new AssetMigrationService(), DetachProbeComponent::class, self::EVENT, $fn);
    }
}

/**
 * @internal
 */
final class DetachProbeComponent extends Component
{
}
