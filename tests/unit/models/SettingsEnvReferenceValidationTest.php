<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\models;

use Lameco\Kunstmaanmigrator\models\Settings;
use Lameco\Kunstmaanmigrator\tests\support\SettingsFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The validator that made the settings screen unsaveable.
 *
 * `validateIsEnvReference` exists to keep a literal password out of project
 * config, and it read the attribute directly. But
 * EnvAttributeParserBehavior swaps every parsed attribute for its *resolved*
 * value in beforeValidate() and restores it in afterValidate() — so by the time
 * the validator ran, `$KUMA_DB_PASSWORD` had already become the password, and
 * the validator rejected it.
 *
 * The effect: any project that had done exactly what the field asks could not
 * save its plugin settings at all. Nothing caught it because the tests called
 * the validator directly, which is the one path where the swap has not
 * happened.
 */
final class SettingsEnvReferenceValidationTest extends TestCase
{
    /**
     * Reproduces what Yii does: capture the typed value, then let the env parser
     * replace the attribute with what it resolves to.
     */
    private function settingsMidValidation(string $typed, string $resolvesTo): Settings
    {
        $settings = SettingsFactory::make();
        $settings->legacyDbPassword = $typed;

        // What beforeValidate() does first; the behavior swaps the attribute
        // immediately afterwards, on the event parent::beforeValidate() fires.
        $settings->captureTypedValues();
        $settings->legacyDbPassword = $resolvesTo;

        return $settings;
    }

    public function testAnEnvReferenceThatResolvesIsAccepted(): void
    {
        $settings = $this->settingsMidValidation('$KUMA_DB_PASSWORD', 'the-actual-password');

        $settings->validateIsEnvReference('legacyDbPassword');

        self::assertFalse(
            $settings->hasErrors('legacyDbPassword'),
            'the resolved value was mistaken for a typed one, which is what made the screen unsaveable',
        );
    }

    /**
     * The validator still has to do its job: a literal password is not swapped,
     * so nothing is stored unparsed, and it must be rejected.
     */
    public function testALiteralPasswordIsStillRejected(): void
    {
        $settings = SettingsFactory::make();
        $settings->legacyDbPassword = 'hunter2';

        $settings->validateIsEnvReference('legacyDbPassword');

        self::assertTrue($settings->hasErrors('legacyDbPassword'));
    }

    public function testAnEmptyValueIsNotAnError(): void
    {
        $settings = SettingsFactory::make();
        $settings->legacyDbPassword = '';

        $settings->validateIsEnvReference('legacyDbPassword');

        self::assertFalse($settings->hasErrors('legacyDbPassword'));
    }

    /**
     * The plugin declares this attribute to the behavior; without that the
     * value is never swapped and the whole question is moot.
     */
    public function testThePasswordIsDeclaredToTheEnvParser(): void
    {
        $behaviors = (new ReflectionClass(Settings::class))
            ->newInstanceWithoutConstructor()
            ->behaviors();

        self::assertContains('legacyDbPassword', $behaviors['parser']['attributes']);
    }

    /**
     * The capture only helps if validation actually runs it. Reflection rather
     * than a call, because parent::beforeValidate() fires the event that
     * attaches behaviours and that needs a Yii application.
     */
    public function testValidationCapturesTypedValuesBeforeTheParserRuns(): void
    {
        $class = new ReflectionClass(Settings::class);

        self::assertSame(
            Settings::class,
            $class->getMethod('beforeValidate')->getDeclaringClass()->getName(),
            'Settings must hook beforeValidate to capture what the operator typed',
        );

        $source = implode('', array_slice(
            file((string) $class->getFileName()) ?: [],
            $class->getMethod('beforeValidate')->getStartLine() - 1,
            $class->getMethod('beforeValidate')->getEndLine() - $class->getMethod('beforeValidate')->getStartLine() + 1,
        ));

        self::assertStringContainsString('captureTypedValues()', $source);
    }
}
