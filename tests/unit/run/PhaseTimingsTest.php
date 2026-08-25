<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\run;

use Lameco\Kunstmaanmigrator\Compile\Compiler;
use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Payload\PayloadValidator;
use Lameco\Kunstmaanmigrator\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\run\EnvironmentPipeline;
use Lameco\Kunstmaanmigrator\run\RunSettings;
use Lameco\Kunstmaanmigrator\run\RunTally;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryUriJobGuard;
use PHPUnit\Framework\TestCase;

/**
 * The compile and validate phases, timed by the pipeline itself.
 *
 * The compiler emits into the handler, so `compile` is a unit's wall time
 * minus what its payloads cost to handle — the one subtraction both callers
 * make, the console once around the walk and the job once per unit.
 */
final class PhaseTimingsTest extends TestCase
{
    private function pipeline(): EnvironmentPipeline
    {
        $transforms = new Transforms([]);
        $gateway = new class() implements SchemaGateway {
            public function sectionByHandle(string $handle): ?array
            {
                return null;
            }

            public function entryTypeByHandle(string $handle): ?array
            {
                return null;
            }

            public function siteByHandle(string $handle): ?array
            {
                return null;
            }

            public function primarySite(): array
            {
                return ['id' => 1, 'handle' => 'default'];
            }

            public function fieldHandlesFor(string $entryTypeHandle): array
            {
                return [];
            }

            public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
            {
                return [];
            }

            public function fieldSlotsFor(string $entryTypeHandle): array
            {
                return [];
            }
        };

        return new EnvironmentPipeline(
            new PayloadValidator($gateway),
            null,
            new Compiler(Mapping::fromArray([]), $transforms),
            $transforms,
            new InMemoryUriJobGuard(),
            new InMemoryElementWriter(),
        );
    }

    /** @return array<string, mixed> */
    private function raw(int $id): array
    {
        return [
            'sourceUid' => 'kuma:COM:nt_page:' . $id,
            'section' => 'pages',
            'entryType' => 'contentPage',
            'sites' => ['default' => ['enabled' => true, 'title' => 'Page ' . $id, 'slug' => 'page-' . $id, 'fieldValues' => []]],
        ];
    }

    public function testACompileUnitIsTimedNetOfWhatItsPayloadsCostToHandle(): void
    {
        $pipeline = $this->pipeline();
        $tally = new RunTally();
        $context = EnvironmentFactory::make('COM', ['nl' => 'default'], ['default' => [1, 'nl-NL', true]]);
        $settings = new RunSettings(dryRun: true);

        $pipeline->timeCompile($tally, function() use ($pipeline, $context, $settings, $tally): void {
            $pipeline->processOne($this->raw(1), $context, $settings, $tally);
            $pipeline->processOne($this->raw(2), $context, $settings, $tally);
        });

        self::assertSame(2, $tally->timings['compile']['count'], 'counted per payload the unit emitted');
        self::assertSame(2, $tally->timings['validate']['count']);
        self::assertGreaterThan(0.0, $tally->timings['validate']['seconds']);
        self::assertGreaterThanOrEqual(0.0, $tally->timings['compile']['seconds']);
        self::assertSame(2, $tally->counts['invalid'], 'the fake gateway knows no section, so both were refused after validation');
    }

    public function testAUnitThatEmitsNothingStillClosesTheCompilePhase(): void
    {
        $tally = new RunTally();

        $this->pipeline()->timeCompile($tally, static function(): void {
        });

        self::assertSame(0, $tally->timings['compile']['count']);
        self::assertArrayNotHasKey('validate', $tally->timings);
    }

    public function testAUnitThatThrowsStillPutsItsTimeOnTheTally(): void
    {
        $tally = new RunTally();

        try {
            $this->pipeline()->timeCompile($tally, static function(): void {
                throw new \RuntimeException('unit died');
            });
            self::fail('the throw must reach the caller');
        } catch (\RuntimeException) {
        }

        self::assertArrayHasKey('compile', $tally->timings);
    }
}
