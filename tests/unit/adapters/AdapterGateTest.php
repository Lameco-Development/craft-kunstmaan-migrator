<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\adapters;

use lameco\kunstmaanmigrator\adapters\Adapter;
use lameco\kunstmaanmigrator\adapters\AdapterGate;
use lameco\kunstmaanmigrator\adapters\GateStatus;
use lameco\kunstmaanmigrator\models\Settings;
use lameco\kunstmaanmigrator\tests\support\InMemoryPluginRegistry;
use lameco\kunstmaanmigrator\tests\support\SettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * The gate itself, tested.
 *
 * It could not be reached before: the decision read `Craft::$app->plugins`
 * directly, so four modules settled for asserting on the English sentence a
 * `disabledWarnLine()` helper returned — through Reflection, because that was
 * private too. Six tests that proved a string had not changed, and nothing
 * that proved the gate decided correctly.
 *
 * With PluginRegistry as a seam the decision is an ordinary function of two
 * inputs, and the thing the tests assert is the thing that matters.
 */
final class AdapterGateTest extends TestCase
{
    private function settings(array $flags = []): Settings
    {
        return SettingsFactory::make($flags);
    }

    private function seo(): Adapter
    {
        return new Adapter('seo', 'SEO', 'seoEnabled', 'seomatic');
    }

    public function testAnEnabledAdapterWithItsPluginInstalledIsReady(): void
    {
        $gate = new AdapterGate(
            new InMemoryPluginRegistry(['seomatic' => '5.1.4']),
            $this->settings(['seoEnabled' => true]),
        );

        $result = $gate->check($this->seo());

        self::assertTrue($result->isReady());
        self::assertNull($result->reason(), 'a ready adapter has nothing to report');
    }

    public function testAnAdapterTheOperatorTurnedOffIsNotRun(): void
    {
        $gate = new AdapterGate(
            new InMemoryPluginRegistry(['seomatic' => '5.1.4']),
            $this->settings(['seoEnabled' => false]),
        );

        $result = $gate->check($this->seo());

        self::assertFalse($result->isReady());
        self::assertSame(GateStatus::DisabledByOperator, $result->status);
    }

    public function testAnAdapterWhosePluginIsMissingIsNotRun(): void
    {
        $gate = new AdapterGate(
            new InMemoryPluginRegistry(),
            $this->settings(['seoEnabled' => true]),
        );

        $result = $gate->check($this->seo());

        self::assertFalse($result->isReady());
        self::assertSame(GateStatus::PluginMissing, $result->status);
    }

    /**
     * The ordering is the point. Someone who switched SEO off should be told
     * they switched it off — "SEOmatic is not installed" is also true, but it
     * is not the reason, and acting on it wastes an afternoon installing a
     * plugin that was never the problem.
     */
    public function testTheOperatorsChoiceIsReportedAheadOfAMissingPlugin(): void
    {
        $gate = new AdapterGate(
            new InMemoryPluginRegistry(),
            $this->settings(['seoEnabled' => false]),
        );

        self::assertSame(GateStatus::DisabledByOperator, $gate->check($this->seo())->status);
    }

    public function testAnAdapterThatNeedsNoPluginRunsOnItsSwitchAlone(): void
    {
        $gate = new AdapterGate(
            new InMemoryPluginRegistry(),
            $this->settings(['translationsEnabled' => true]),
        );

        $result = $gate->check(new Adapter('translations', 'Translations', 'translationsEnabled'));

        self::assertTrue($result->isReady(), 'the translation pass writes Craft\'s own catalogs');
    }

    /**
     * An adapter naming a property Settings does not have is a wiring mistake.
     * Running it anyway would mean a switch the operator cannot reach.
     */
    public function testAnAdapterNamingAnUnknownSettingIsTreatedAsOff(): void
    {
        $gate = new AdapterGate(new InMemoryPluginRegistry(), $this->settings());

        $result = $gate->check(new Adapter('ghost', 'Ghost', 'thereIsNoSuchSetting'));

        self::assertFalse($result->isReady());
        self::assertSame(GateStatus::DisabledByOperator, $result->status);
    }

    public function testTheTwoSkipReasonsAreDistinguishable(): void
    {
        $disabled = (new AdapterGate(
            new InMemoryPluginRegistry(['seomatic' => '5.1.4']),
            $this->settings(['seoEnabled' => false]),
        ))->check($this->seo())->reason();

        $missing = (new AdapterGate(
            new InMemoryPluginRegistry(),
            $this->settings(['seoEnabled' => true]),
        ))->check($this->seo())->reason();

        self::assertNotSame($disabled, $missing);
        self::assertStringContainsString('disabled', (string) $disabled);
        self::assertStringContainsString('Settings::seoEnabled', (string) $disabled);
        self::assertStringContainsString('not installed', (string) $missing);
        self::assertStringContainsString('seomatic', (string) $missing);
    }
}
