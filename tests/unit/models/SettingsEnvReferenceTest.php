<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\models;

use Lameco\Kunstmaanmigrator\tests\support\SettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * The guard against a credential reaching git.
 *
 * Craft writes plugin settings into project config, which is committed and
 * deployed. The migrator only ever runs locally, against a legacy database
 * only that machine can reach — so a password typed into the settings screen
 * would be a secret shipped to production for no benefit at all.
 */
final class SettingsEnvReferenceTest extends TestCase
{
    public function testAnEnvironmentVariableNameIsAccepted(): void
    {
        $settings = SettingsFactory::make(['legacyDbPassword' => '$CRAFT_LEGACY_DB_PASSWORD']);
        $settings->validateIsEnvReference('legacyDbPassword');

        self::assertFalse($settings->hasErrors('legacyDbPassword'));
    }

    public function testABlankPasswordIsAcceptedBecauseNotEveryDatabaseHasOne(): void
    {
        $settings = SettingsFactory::make(['legacyDbPassword' => '']);
        $settings->validateIsEnvReference('legacyDbPassword');

        self::assertFalse($settings->hasErrors('legacyDbPassword'));
    }

    public function testARawPasswordIsRejected(): void
    {
        $settings = SettingsFactory::make(['legacyDbPassword' => 'hunter2']);
        $settings->validateIsEnvReference('legacyDbPassword');

        self::assertTrue($settings->hasErrors('legacyDbPassword'));
    }

    /**
     * The message has to say what to type instead. "Invalid" would send someone
     * looking for a typo in a password that is perfectly valid.
     */
    public function testTheRejectionSaysWhatToDoInstead(): void
    {
        $settings = SettingsFactory::make(['legacyDbPassword' => 'hunter2']);
        $settings->validateIsEnvReference('legacyDbPassword');

        $error = $settings->getFirstError('legacyDbPassword');

        self::assertStringContainsString('$CRAFT_LEGACY_DB_PASSWORD', (string) $error);
        self::assertStringContainsString('project config', (string) $error);
    }

    public function testThePasswordIsTheAttributeUnderGuard(): void
    {
        $settings = SettingsFactory::make();

        $rules = array_filter(
            $settings->rules(),
            static fn(array $rule): bool => ($rule[1] ?? null) === 'validateIsEnvReference',
        );

        self::assertCount(1, $rules, 'exactly one attribute is guarded, and it is the secret one');
        self::assertSame(['legacyDbPassword'], array_values($rules)[0][0]);
    }

    /**
     * The typo this exists to catch: `$KUMA_DB_PASSWORDd` saved cleanly, read
     * as empty, and surfaced an hour later as "cannot connect". It starts with
     * a `$`, so the leak guard was satisfied — but the variable does not exist.
     */
    public function testAnEnvironmentVariableThatDoesNotExistIsRejected(): void
    {
        $settings = SettingsFactory::make(['legacyDbPassword' => '$KUMA_DB_PASSWORD_THAT_IS_NOT_SET']);
        $settings->validateEnvReferenceResolves('legacyDbPassword');

        self::assertTrue($settings->hasErrors('legacyDbPassword'));
        self::assertStringContainsString('not set in this environment', (string) $settings->getFirstError('legacyDbPassword'));
    }

    public function testAnEnvironmentVariableThatExistsIsAccepted(): void
    {
        $_SERVER['KUMA_TEST_VAR_THAT_EXISTS'] = 'yes';

        try {
            $settings = SettingsFactory::make(['legacyDbPassword' => '$KUMA_TEST_VAR_THAT_EXISTS']);
            $settings->validateEnvReferenceResolves('legacyDbPassword');

            self::assertFalse($settings->hasErrors('legacyDbPassword'));
        } finally {
            unset($_SERVER['KUMA_TEST_VAR_THAT_EXISTS']);
        }
    }

    public function testAPlainValueIsNotCheckedForResolution(): void
    {
        // The leak guard owns that verdict; this one only reads $VAR references.
        $settings = SettingsFactory::make(['mappingPath' => '/some/path/enreach.yaml']);
        $settings->validateEnvReferenceResolves('mappingPath');

        self::assertFalse($settings->hasErrors('mappingPath'));
    }

    /**
     * Resolution must not write onto the attributes. Craft persists this model
     * to project config, so a resolved value on an attribute is a secret headed
     * for git — which is exactly how the settings screen came to arrive
     * pre-filled with a password nobody typed.
     */
    public function testResolvingTheConnectionLeavesTheStoredSettingsAlone(): void
    {
        $_SERVER['KUMA_DB_PASSWORD'] = 'super-secret';

        try {
            $settings = SettingsFactory::make();
            $connection = $settings->legacyConnection();

            self::assertSame('super-secret', $connection['password'], 'the code that connects gets the value');
            self::assertNull($settings->legacyDbPassword, 'and what gets saved stays empty');
        } finally {
            unset($_SERVER['KUMA_DB_PASSWORD']);
        }
    }
}
