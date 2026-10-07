<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\console;

use Lameco\Kunstmaanmigrator\console\MigrateController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `migrate` reads the mapping the plugin settings name, as `doctor` does, so the run
 * commands do not need `--mapping=` repeated on every call. The flag still wins.
 */
final class MigrateMappingPathTest extends TestCase
{
    #[Test]
    public function without_the_flag_the_configured_mapping_path_is_used(): void
    {
        self::assertSame(
            'migration/berkvens-nl/mapping.yaml',
            MigrateController::mappingPathFor('', 'migration/berkvens-nl/mapping.yaml'),
        );
    }

    #[Test]
    public function the_flag_overrides_the_configured_mapping_path(): void
    {
        self::assertSame(
            'migration/other/mapping.yaml',
            MigrateController::mappingPathFor('migration/other/mapping.yaml', 'migration/berkvens-nl/mapping.yaml'),
        );
    }

    #[Test]
    public function with_neither_there_is_no_mapping(): void
    {
        self::assertSame('', MigrateController::mappingPathFor('', ''));
    }
}
