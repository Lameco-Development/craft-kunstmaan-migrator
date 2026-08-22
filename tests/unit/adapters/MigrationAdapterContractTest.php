<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\adapters;

use lameco\kunstmaanmigrator\adapters\Adapter;
use lameco\kunstmaanmigrator\adapters\AdapterRegistry;
use lameco\kunstmaanmigrator\adapters\MigrationAdapter;
use lameco\kunstmaanmigrator\load\NavigationMigrationService;
use lameco\kunstmaanmigrator\load\RedirectMigrationService;
use lameco\kunstmaanmigrator\load\SeoMigrationService;
use lameco\kunstmaanmigrator\load\TranslationMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The registry is an execution list, not a display list.
 *
 * It used to be both in name and neither in fact: EVENT_REGISTER_ADAPTERS put a
 * row on the settings screen, and a hard-coded array of four decided what
 * actually ran. An adapter someone registered was rendered and never called.
 */
final class MigrationAdapterContractTest extends TestCase
{
    /** @return list<array{class-string, string}> */
    public static function builtInServices(): array
    {
        return [
            [SeoMigrationService::class, 'seo'],
            [RedirectMigrationService::class, 'redirects'],
            [NavigationMigrationService::class, 'navigation'],
            [TranslationMigrationService::class, 'translations'],
        ];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('builtInServices')]
    public function testEveryBuiltInPassIsAMigrationAdapter(string $class, string $handle): void
    {
        self::assertTrue(
            (new ReflectionClass($class))->implementsInterface(MigrationAdapter::class),
            $class . ' does not declare the interface it already had',
        );
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('builtInServices')]
    public function testHandlesMatchTheRegistry(string $class, string $handle): void
    {
        $reflection = new ReflectionClass($class);
        $service = $reflection->newInstanceWithoutConstructor();

        self::assertSame($handle, $service->handle());
        self::assertNotNull((new AdapterRegistry())->byHandle($handle));
    }

    /**
     * The point of the whole thing: a third-party adapter is reachable through
     * the same call the built-ins are, so the loop that runs them runs it too.
     */
    public function testARegisteredAdapterResolvesToSomethingRunnable(): void
    {
        $pass = new class implements MigrationAdapter {
            public function handle(): string
            {
                return 'acme';
            }

            public function migrateAll(
                \lameco\kunstmaanmigrator\load\MigrationOptions $opts,
                \lameco\kunstmaanmigrator\run\EnvironmentContext $context,
            ): \lameco\kunstmaanmigrator\load\MigrationReport {
                return new \lameco\kunstmaanmigrator\load\MigrationReport();
            }
        };

        $adapter = new Adapter('acme', 'Acme', 'acmeEnabled', null, static fn () => $pass);

        self::assertSame($pass, $adapter->service());
    }

    /**
     * `redirects` was the one exception, because its records come from the
     * mapping rather than a table and the old signature could carry neither the
     * mapping nor a connection. EnvironmentContext carries both, so there is no
     * longer an adapter the loop cannot run — which is the difference between a
     * registry and a list.
     */
    public function testEveryRegisteredAdapterIsRunnableByTheLoop(): void
    {
        $withoutFactory = [];

        foreach ((new AdapterRegistry())->all() as $adapter) {
            if ($adapter->factory === null) {
                $withoutFactory[] = $adapter->handle;
            }
        }

        self::assertSame([], $withoutFactory);
    }
}
