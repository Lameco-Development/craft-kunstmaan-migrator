<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests;

use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Fixture: minimal host for the trait. Provides a stderr() that captures
 * output instead of writing to the real stream — the trait calls
 * $this->stderr() which on a real console controller hits stdout/stderr
 * via yii\console\Controller.
 */
final class NeverProductionFixture
{
    use NeverProductionTrait;

    public string $stderrOutput = '';
    public mixed $stderrLastArg = null;

    public function callEnforce(): ?int
    {
        return $this->enforceNeverProduction();
    }

    public function stderr(string $string, ...$args): int
    {
        $this->stderrOutput .= $string;
        $this->stderrLastArg = $args[0] ?? null;
        return strlen($string);
    }
}

/**
 * Test 5 (FND-04 / D-20) — guards the CRAFT_ENVIRONMENT=production refusal
 * path. UAT could not exercise this through a real console invocation when
 * the host bootstrap.php uses Dotenv::createUnsafeMutable (which lets .env
 * override inline shell env), so we drive the trait directly here.
 *
 * craft\helpers\App::env() resolves $_SERVER[$name] before getenv() / constants,
 * so injecting $_SERVER is enough to control what the trait sees.
 */
final class NeverProductionTraitTest extends TestCase
{
    private bool $hadPrevious = false;
    private mixed $previous = null;

    protected function setUp(): void
    {
        $this->hadPrevious = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $this->previous = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadPrevious) {
            $_SERVER['CRAFT_ENVIRONMENT'] = $this->previous;
        } else {
            unset($_SERVER['CRAFT_ENVIRONMENT']);
        }
    }

    public function testReturnsErrorExitCodeWhenEnvironmentIsProduction(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        $fixture = new NeverProductionFixture();
        $result = $fixture->callEnforce();

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $result);
        self::assertStringContainsString(
            'Refusing to run against CRAFT_ENVIRONMENT=production',
            $fixture->stderrOutput,
        );
        self::assertSame(Console::FG_RED, $fixture->stderrLastArg);
    }

    public function testReturnsNullWhenEnvironmentIsDev(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'dev';

        $fixture = new NeverProductionFixture();

        self::assertNull($fixture->callEnforce());
        self::assertSame('', $fixture->stderrOutput);
    }

    public function testReturnsNullWhenEnvironmentIsStaging(): void
    {
        $_SERVER['CRAFT_ENVIRONMENT'] = 'staging';

        $fixture = new NeverProductionFixture();

        self::assertNull($fixture->callEnforce());
        self::assertSame('', $fixture->stderrOutput);
    }

    public function testReturnsNullWhenEnvironmentIsUnset(): void
    {
        unset($_SERVER['CRAFT_ENVIRONMENT']);

        $fixture = new NeverProductionFixture();

        self::assertNull($fixture->callEnforce());
    }
}
