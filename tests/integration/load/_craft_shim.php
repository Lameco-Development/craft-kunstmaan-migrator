<?php

declare(strict_types=1);

// Plan 08-15 / TaxonomyMigrationTest — minimal global `Craft` class shim.
//
// TaxonomyMigrationService::migrateAll() calls `Craft::warning(...)` on the
// empty-taxonomies short-circuit path. The real Craft global class lives at
// `vendor/craftcms/cms/src/Craft.php` and extends `\Yii` → `\yii\BaseYii`.
// Loading the real Yii.php registers a class-loading autoloader (prepend=true)
// that subtly changes runtime behavior in unrelated tests — surfacing latent
// PHP 8.5 warnings in `KunstmaanEnvReaderTest::testReturnsBlankWhenSourcePathUnset`
// and `TransformImplicitContentTest::testSyntheticImplicitPagePartProducesMatrixBlock`.
// (Both tests transiently call `Plugin::getInstance()`; with Yii's autoloader
// installed, the static lookup returns null instead of throwing, then PHP 8.5
// raises a warning when properties are read on null.)
//
// Rather than introduce that test-suite-wide noise, we shim the two static
// methods these focused service tests actually invoke. Anything additional must be
// added here explicitly.

if (!class_exists(\Craft::class, false)) {
    class Craft
    {
        // NOTE: deliberately NOT defining a public static $app property.
        // The pre-existing TransformImplicitContentTest exercises a code path
        // that does `Craft::$app->sites->...` inside a try/catch (Throwable)
        // block; without the property defined, the access raises a
        // catchable PHP Error ("Access to undeclared static property") that
        // the surrounding catch swallows cleanly. Defining `$app = null`
        // here would change that to a non-catchable PHP warning
        // ("Attempt to read property on null") and surface a noise warning
        // in the unrelated pre-existing test. Leaving the property absent
        // preserves the baseline behavior for that test while letting our
        // own static-method calls (warning/info) resolve.

        public static function warning(mixed $message, string $category = 'application'): void
        {
            // No-op: matches Yii's signature so the call site doesn't error.
        }

        public static function info(mixed $message, string $category = 'application'): void
        {
            // No-op: matches Yii's signature so the call site doesn't error.
        }

        public static function getAlias(string $alias): string
        {
            if ($alias === '@storage' || str_starts_with($alias, '@storage/')) {
                return sys_get_temp_dir() . '/craft-test-storage' . substr($alias, strlen('@storage'));
            }

            return $alias;
        }
    }
}
