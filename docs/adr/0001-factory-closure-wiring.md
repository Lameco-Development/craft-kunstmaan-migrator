# ADR-0001 — Wire plugin components with factory closures, not `Instance::of()`

Date: 2026-08-24 · Status: accepted

## Context

`Plugin::init()` hand-wires ~75 sibling dependencies into `public ?Foo $dep`
slots after registering every service as a bare class name in `config()`.
A missed assignment surfaces as a null-dereference at first call, deep into a
migration run. The obvious declarative fix — component definitions carrying
`yii\di\Instance::of('siblingComponentId')` — was evaluated and rejected.

## Decision

Register each component as a zero-arg factory closure in `config()`
(`'transformService' => fn() => self::makeTransformService()`), with one
private static maker per service holding its wiring, Settings-derived
defaults, and inline constructions. `init()` keeps only event registrations,
`controllerNamespace`, and the `legacyDb` app-component guard.

## Why not `Instance::of()`

Verified against the vendored Yii source and confirmed empirically:
`ServiceLocator::get()` materialises definitions via `Yii::createObject()`,
which resolves `Instance` references through **`Yii::$container` only**
(`yii\di\Container::resolveDependencies()` passes itself; the documented
`Yii::$app` fallback is dead code on this path). Plugin components live in
the plugin module's ServiceLocator, which the container cannot see — so
`Instance::of('legacyDbService')` throws `NotInstantiableException`, in both
property and `__construct()` positions.

Registering the plugin's ids in `Craft::$container` to make `Instance` work
was rejected: no precedent in Craft core or major plugins, and it leaks ~60
plugin-private ids into a global namespace with a second source of truth.

Factory closures are the Craft-core idiom for exactly this
(`assetManager` in `craftcms/cms/src/config/app.php`), fail loudly
(`InvalidConfigException: Unknown component ID` on a typo'd sibling), and can
express the conditional Settings copies and inline `new` constructions that
no declarative form can.

## Consequences

- Wiring is lazy (first `get()`), not eager in `init()`; a boot test must
  build the full component graph so a broken factory cannot hide.
- Dependency slots become non-nullable typed properties; the runtime null
  guards and null-object fallbacks are deleted.
- Constructor injection remains possible later, but only stacked on
  container registration — revisit this ADR before attempting it.
