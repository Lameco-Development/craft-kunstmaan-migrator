<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\models;

use lameco\kunstmaanmigrator\tests\support\SettingsFactory;
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
            static fn (array $rule): bool => ($rule[1] ?? null) === 'validateIsEnvReference',
        );

        self::assertCount(1, $rules, 'exactly one attribute is guarded, and it is the secret one');
        self::assertSame(['legacyDbPassword'], array_values($rules)[0][0]);
    }
}
