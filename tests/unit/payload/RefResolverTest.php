<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\payload;

use lameco\kunstmaanmigrator\load\MigrationStateReader;
use lameco\kunstmaanmigrator\payload\RefResolver;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic in-memory stand-in for the state table lookups
 * `RefResolver` needs — depending on the `MigrationStateReader` interface
 * (not the concrete `MigrationStateService`) is what makes this class
 * exercisable without booting a Craft application.
 */
final class FakeStateReader implements MigrationStateReader
{
    /** @var array<string, int> "<source>|<key>" => targetId */
    public array $targets = [];

    public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
    {
        return $this->targets[$source . '|' . $key] ?? null;
    }

    public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
    {
        return null;
    }

    public function get(string $source, string $key, ?int $siteId = null): ?array
    {
        return null;
    }
}

final class RefResolverTest extends TestCase
{
    public function testResolveParsesGrammarAndReturnsStateHit(): void
    {
        $reader = new FakeStateReader();
        $reader->targets['COM:nt_page|143'] = 881;

        $resolver = new RefResolver($reader);

        self::assertSame(881, $resolver->resolve('kuma:COM:nt_page:143'));
    }

    public function testResolveReturnsNullOnStateMiss(): void
    {
        $resolver = new RefResolver(new FakeStateReader());

        self::assertNull($resolver->resolve('kuma:COM:nt_page:999'));
    }

    public function testResolveQueriesStateWithEnvColonTableAsSourceAndIdAsKey(): void
    {
        $reader = new class implements MigrationStateReader {
            public ?string $capturedSource = null;
            public ?string $capturedKey = null;

            public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
            {
                $this->capturedSource = $source;
                $this->capturedKey = $key;

                return 42;
            }

            public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
            {
                return null;
            }

            public function get(string $source, string $key, ?int $siteId = null): ?array
            {
                return null;
            }
        };

        $resolver = new RefResolver($reader);
        $resolver->resolve('kuma:DE:nt_page:87');

        self::assertSame('DE:nt_page', $reader->capturedSource);
        self::assertSame('87', $reader->capturedKey);
    }

    public function testResolveReturnsNullForMalformedGrammarWithoutTouchingState(): void
    {
        $reader = new class implements MigrationStateReader {
            public bool $called = false;

            public function getTargetId(string $source, string $key, ?int $siteId = null): ?int
            {
                $this->called = true;

                return 1;
            }

            public function getTargetUid(string $source, string $key, ?int $siteId = null): ?string
            {
                return null;
            }

            public function get(string $source, string $key, ?int $siteId = null): ?array
            {
                return null;
            }
        };

        $resolver = new RefResolver($reader);

        self::assertNull($resolver->resolve('not-a-kuma-uid'));
        self::assertFalse($reader->called);
    }

    public function testParseSplitsEnvTableAndId(): void
    {
        self::assertSame(
            ['source' => 'DE:nt_page', 'key' => '87'],
            RefResolver::parse('kuma:DE:nt_page:87'),
        );
    }

    public function testParseReturnsNullForMalformedUid(): void
    {
        self::assertNull(RefResolver::parse('kuma:only-two-parts'));
        self::assertNull(RefResolver::parse('kuma:COM:Uppercase_Table:1'));
        self::assertNull(RefResolver::parse('kuma:COM:nt_page:not-a-number'));
    }
}
