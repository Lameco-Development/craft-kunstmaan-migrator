<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\PayloadWriter;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\Dsn;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The same mapping against the same database must compile to the same bytes.
 *
 * "Run it again and get what you expect" is the property the whole design exists for, and it was
 * asserted nowhere — the one re-run bug found so far (Matrix block identity drifting between
 * runs) was found by hand, in a target, after it had already written wrong data.
 *
 * This needs a real legacy database, so it skips unless one is configured. That makes it a check
 * you run against a corpus rather than a unit test, which is the only honest way to test a
 * compiler whose input is a 40-table Kunstmaan schema.
 *
 *   KUMA_TEST_MAPPING=/path/to/mapping.yaml KUMA_TEST_CRAFT=/path/to/craft \
 *   KUMA_DB_PASSWORD=… vendor/bin/phpunit
 */
final class CompileDeterminismTest extends TestCase
{
    private const LIMIT = 40;

    #[Test]
    public function compiling_twice_produces_identical_payloads(): void
    {
        [$mapping, $schema] = $this->fixture();
        $environment = (string) array_key_first($mapping->environments());

        $first = $this->compile($mapping, $schema, $environment);
        $second = $this->compile($mapping, $schema, $environment);

        self::assertNotSame('', $first, 'the fixture compiled nothing — the check would pass vacuously');
        self::assertSame($first, $second, 'a second compile of the same input produced different payloads');
    }

    #[Test]
    public function source_uids_are_stable_and_unique_within_a_run(): void
    {
        // sourceUid is the idempotency key: it is what makes a re-run an update rather than a
        // second copy. A duplicate means two legacy nodes would collapse onto one Craft entry.
        [$mapping, $schema] = $this->fixture();
        $environment = (string) array_key_first($mapping->environments());

        $uids = [];

        foreach (explode("\n", trim($this->compile($mapping, $schema, $environment))) as $line) {
            if ($line === '') {
                continue;
            }

            $payload = json_decode($line, true);
            $uid = $payload['sourceUid'] ?? null;

            self::assertIsString($uid, 'every payload carries a sourceUid');
            self::assertArrayNotHasKey($uid, $uids, sprintf('duplicate sourceUid `%s`', (string) $uid));
            $uids[$uid] = true;
        }

        self::assertNotSame([], $uids);
    }

    private function compile(Mapping $mapping, ?CraftSchema $schema, string $environment): string
    {
        $db = LegacyDatabase::connect(
            $environment,
            (string) $mapping->environments()[$environment]['database'],
            Dsn::fromEnvironment(),
        );

        $handle = fopen('php://memory', 'r+');
        $writer = new PayloadWriter($handle);
        $compiler = new Compiler($mapping, new Transforms($mapping->all()['transforms'] ?? []), $schema);

        $compiler->compile($db, $environment, $writer->write(...), self::LIMIT);

        rewind($handle);
        $out = (string) stream_get_contents($handle);
        fclose($handle);

        return $out;
    }

    /** @return array{0: Mapping, 1: ?CraftSchema} */
    private function fixture(): array
    {
        $mappingPath = getenv('KUMA_TEST_MAPPING');

        if ($mappingPath === false || !is_file($mappingPath)) {
            self::markTestSkipped('Set KUMA_TEST_MAPPING to a mapping whose environments are reachable.');
        }

        $mapping = Mapping::fromFile($mappingPath);
        $environment = array_key_first($mapping->environments());

        if ($environment === null) {
            self::markTestSkipped('The mapping declares no environments.');
        }

        try {
            LegacyDatabase::connect(
                (string) $environment,
                (string) $mapping->environments()[$environment]['database'],
                Dsn::fromEnvironment(),
            );
        } catch (\PDOException $e) {
            self::markTestSkipped(sprintf('Legacy database unreachable: %s', $e->getMessage()));
        }

        $craftRoot = getenv('KUMA_TEST_CRAFT');
        $schema = $craftRoot !== false && is_dir($craftRoot) ? CraftSchema::fromProjectConfig($craftRoot) : null;

        return [$mapping, $schema];
    }
}
