<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use lameco\kunstmaanmigrator\console\MigrateController;
use lameco\kunstmaanmigrator\extract\ExtractService;
use lameco\kunstmaanmigrator\models\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Phase 8.5 / D-24 — characterize the `--no-rel-join` CLI flag wiring on
 * MigrateController + the Settings::joinFkRelations gate.
 *
 * The action body itself touches Craft DI (Plugin::getInstance, ExtractService
 * wiring through Plugin::init), so we exercise the surface contracts via
 * Reflection — same pattern as MigrateControllerReportEmptyStateTest +
 * MigrateControllerSyncAssetsTest. The behavioral contract under test:
 *
 *   1. Property `noRelJoin` exists, defaults false.
 *   2. Flag is registered in options('extract') + options('index').
 *   3. Private helper `applyNoRelJoinOverride` flips
 *      `extractService->joinFkRelations` to false when the flag is set.
 *   4. Settings::joinFkRelations exists, defaults false, is in rules().
 */
final class MigrateControllerNoRelJoinFlagTest extends TestCase
{
    public function testNoRelJoinPropertyDefaultsFalse(): void
    {
        $defaults = (new ReflectionClass(MigrateController::class))->getDefaultProperties();
        self::assertArrayHasKey('noRelJoin', $defaults);
        self::assertFalse($defaults['noRelJoin']);

        $rp = new ReflectionProperty(MigrateController::class, 'noRelJoin');
        $type = $rp->getType();
        self::assertNotNull($type);
        // @phpstan-ignore-next-line — getType() returns ReflectionNamedType for non-union props
        self::assertSame('bool', $type->getName());
    }

    public function testNoRelJoinIsRegisteredInOptionsForExtractAndIndex(): void
    {
        // Reflection-only construction: skip the parent Yii constructor so
        // we can call options() without a module/DI bootstrap.
        $rc = new ReflectionClass(MigrateController::class);
        /** @var MigrateController $controller */
        $controller = $rc->newInstanceWithoutConstructor();

        foreach (['extract', 'index'] as $actionID) {
            $opts = $controller->options($actionID);
            self::assertContains(
                'noRelJoin',
                $opts,
                "options('{$actionID}') must expose --no-rel-join",
            );
        }
    }

    public function testApplyNoRelJoinOverrideFlipsServiceFlagWhenSet(): void
    {
        $rc = new ReflectionClass(MigrateController::class);
        /** @var MigrateController $controller */
        $controller = $rc->newInstanceWithoutConstructor();

        // Seed the public option as if `--no-rel-join` was passed on the CLI.
        $controller->noRelJoin = true;

        // Build a thin Plugin shim whose only requirement is exposing an
        // ExtractService at the `->extractService` accessor. Plugin's accessor
        // is Yii Component magic — easiest to satisfy is a stub class with
        // the property declared; the helper only reads/writes
        // `$plugin->extractService->joinFkRelations`.
        $pluginShim = new NoRelJoinPluginShim();
        $pluginShim->extractService = new ExtractService();
        $pluginShim->extractService->joinFkRelations = true;

        $m = new ReflectionMethod(MigrateController::class, 'applyNoRelJoinOverride');
        // Suppress stdout chatter — the helper writes a banner via
        // `$this->stdout(...)`, which traverses Yii's Console controller
        // interface. With newInstanceWithoutConstructor() the controller
        // has no `stdout`/`stderr` wired; we capture & discard.
        ob_start();
        try {
            // @phpstan-ignore-next-line — Plugin shim shape is intentional
            $m->invoke($controller, $pluginShim);
        } finally {
            ob_end_clean();
        }

        self::assertFalse(
            $pluginShim->extractService->joinFkRelations,
            '--no-rel-join must flip extractService->joinFkRelations to false',
        );
    }

    public function testApplyNoRelJoinOverrideIsNoopWhenFlagAbsent(): void
    {
        $rc = new ReflectionClass(MigrateController::class);
        /** @var MigrateController $controller */
        $controller = $rc->newInstanceWithoutConstructor();
        $controller->noRelJoin = false;

        $pluginShim = new NoRelJoinPluginShim();
        $pluginShim->extractService = new ExtractService();
        $pluginShim->extractService->joinFkRelations = true;

        $m = new ReflectionMethod(MigrateController::class, 'applyNoRelJoinOverride');
        ob_start();
        try {
            // @phpstan-ignore-next-line — Plugin shim shape is intentional
            $m->invoke($controller, $pluginShim);
        } finally {
            ob_end_clean();
        }

        self::assertTrue(
            $pluginShim->extractService->joinFkRelations,
            'Without --no-rel-join the helper must leave the service flag untouched',
        );
    }

    public function testSettingsJoinFkRelationsDefaultsFalseAndIsInRules(): void
    {
        $rc = new ReflectionClass(Settings::class);
        $defaults = $rc->getDefaultProperties();
        self::assertArrayHasKey('joinFkRelations', $defaults);
        self::assertFalse(
            $defaults['joinFkRelations'],
            'Settings::joinFkRelations defaults false so extracted JSON remains source-faithful',
        );

        // Settings::init() calls App::env (Craft helper) which requires the
        // Yii class to be loaded. Skip the constructor — rules() is pure
        // and does not touch any Yii state.
        /** @var Settings $settings */
        $settings = $rc->newInstanceWithoutConstructor();
        $rulesContainsJoinFk = false;
        foreach ($settings->rules() as $rule) {
            $attrs = (array) ($rule[0] ?? []);
            $type  = (string) ($rule[1] ?? '');
            if ($type === 'boolean' && in_array('joinFkRelations', $attrs, true)) {
                $rulesContainsJoinFk = true;
                break;
            }
        }
        self::assertTrue(
            $rulesContainsJoinFk,
            'Settings::rules() must register joinFkRelations as a boolean for CP form binding',
        );
    }
}

/**
 * Bare Plugin-shape stub: declares only the `extractService` slot the
 * `applyNoRelJoinOverride` helper reads. We can't subclass the real Plugin
 * (touches Craft + Yii bootstrap on construct) and we don't need to — the
 * helper's signature accepts `Plugin $plugin` but PHP's runtime check is
 * structural for our reflective `invoke()` call site here.
 *
 * NB: PHP DOES enforce parameter types at runtime in normal calls, but
 * `ReflectionMethod::invoke()` honors the same checks. To pass the Plugin
 * type check we extend the real Plugin class via a private subclass that
 * overrides nothing — just to satisfy the type — but skip its constructor.
 */
final class NoRelJoinPluginShim extends \lameco\kunstmaanmigrator\Plugin
{
    public ?ExtractService $extractService = null;

    public function __construct()
    {
        // Skip parent constructor: Plugin extends craft\base\Plugin which
        // chains into Yii's Module + craft\base\PluginTrait — both pull
        // services and assume Craft is bootstrapped. We only need the
        // `extractService` slot for the helper-under-test.
    }
}
