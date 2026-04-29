---
phase: 10-generic-migration-rehearsal-gap-closure
reviewed: 2026-04-28T00:00:00Z
depth: standard
files_reviewed: 25
files_reviewed_list:
  - src/compile/CraftTargetIntrospector.php
  - src/compile/MappingCompiler.php
  - src/console/CompileController.php
  - src/console/MigrateController.php
  - src/console/VerifyController.php
  - src/fields/handlers/RelationHandler.php
  - src/fields/ResolverContext.php
  - src/load/AtomicMigrationService.php
  - src/load/EntryMigrationService.php
  - src/load/TaxonomyMigrationService.php
  - src/models/Settings.php
  - src/Plugin.php
  - src/transform/TransformService.php
  - src/verify/CountGateService.php
  - tests/integration/load/EntryMigrationServiceTest.php
  - tests/integration/load/TaxonomyMigrationTest.php
  - tests/integration/transform/TransformCharacterizationTest.php
  - tests/unit/compile/CraftTargetIntrospectorTest.php
  - tests/unit/compile/MappingCompilerValidationTest.php
  - tests/unit/console/MigrateControllerCompilePreflightTest.php
  - tests/unit/console/MigrateControllerFailureExitTest.php
  - tests/unit/console/MigrateControllerTaxonomiesWiringTest.php
  - tests/unit/fields/RelationHandlerTaxonomyResolverTest.php
  - tests/unit/verify/CountGateServiceFiltersTest.php
  - tests/unit/verify/CountGateServiceTest.php
findings:
  critical: 0
  warning: 2
  info: 0
  total: 2
status: issues_found
---

# Phase 10: Code Review Report

**Reviewed:** 2026-04-28T00:00:00Z
**Depth:** standard
**Files Reviewed:** 25
**Status:** issues_found

## Summary

Reviewed the listed Phase 10 source and test changes with focus on migration semantics, taxonomy relation resolution, load/fallback behavior, verify count domains, and regression risk. PHP syntax checks passed for the changed source files, and no dangerous-function or hardcoded-secret patterns were found.

Two warning-level issues were found:

1. Taxonomy locale fallback uses legacy locale keys as Craft site handles, so locale maps such as `nl => default` / `en => enUs` can silently skip non-primary site fallback saves.
2. Transform-stage handler failures, including the new taxonomy lazy resolver failures, can be swallowed into `TransformService`'s local sentinel report and then discarded by `MigrateController`, allowing live migrations to continue with missing relation values and no operator-visible report warning/failure.

## Warnings

### WR-01: Taxonomy fallback resolves `mapping.sites` keys instead of Craft handles

**File:** `src/load/TaxonomyMigrationService.php:542-545`

**Issue:**

In the empty `ext_translations` fallback branch, the service iterates `mapping['sites']` as:

```php
foreach ($sites as $siteHandle => $_siteCfg) {
    $site = Craft::$app->sites->getSiteByHandle((string) $siteHandle);
```

However, the compiled mapping convention is locale to Craft site handle, for example `['nl' => 'default', 'en' => 'enUs']`. This means the fallback attempts to resolve Craft sites named `nl` and `en`, not `default` and `enUs`. For projects where locale keys differ from site handles, taxonomy fallback values are skipped for non-primary sites, leaving translated taxonomy entries incomplete or stale.

This is especially risky because the related test for the fallback path is marked incomplete and does not catch the mapping-shape mismatch.

**Fix:**

Resolve the Craft handle from the mapping value, while preserving compatibility with older array-shaped test mappings:

```php
$sites = (array) ($mapping['sites'] ?? []);
foreach ($sites as $legacyLocale => $siteCfg) {
    $siteHandle = is_array($siteCfg)
        ? (string) ($siteCfg['siteHandle'] ?? $legacyLocale)
        : (string) $siteCfg;

    if ($siteHandle === '') {
        continue;
    }

    $site = Craft::$app->sites->getSiteByHandle($siteHandle);
    if ($site === null) {
        continue;
    }

    if ($site->id === $primarySite->id) {
        continue;
    }

    // existing localized save logic...
}
```

Also add a regression test using a compiled-shape mapping such as:

```php
'sites' => [
    'nl' => 'default',
    'en' => 'enUs',
]
```

### WR-02: Transform handler failures can be discarded, hiding taxonomy relation data loss

**File:** `src/transform/TransformService.php:279-282` and `src/console/MigrateController.php:271-273`

**Issue:**

`TransformService::transformFields()` catches all handler exceptions and records them only in its local transform report:

```php
try {
    $fieldValues[$targetHandle] = $handler->resolve($legacyValue, $ctx, $opts);
} catch (Throwable $e) {
    $report['warnings'][] = "Handler '{$handlerId}' failed on {$targetHandle}: " . $e->getMessage();
}
```

But `MigrateController::actionIndex()` discards the `__report` sentinel entirely:

```php
if (isset($payload['__report'])) {
    continue; // sentinel - counters available via the Transform run report
}
```

With this phase, relation handling can now call `TaxonomyMigrationService::resolveReferenced()` during live transform. If that resolver throws due to invalid mapping, missing Craft target, validation failure, or save failure, the field is omitted and the migration can continue to load the page without the taxonomy relation. Because the sentinel warnings are not merged into the main `MigrationReport`, the final report can show a successful migration while relation data was dropped.

**Fix:**

At minimum, merge transform sentinel warnings into the main migration report in `MigrateController`:

```php
foreach ($plugin->transformService->run($extractedStream, $mapping, $filters, $this->buildTransformOptions($report), $transformProgress) as $payload) {
    if (isset($payload['__report'])) {
        foreach ((array) ($payload['__report']['warnings'] ?? []) as $warning) {
            $report->warn('Transform: ' . (string) $warning);
        }
        continue;
    }

    // existing payload write logic...
}
```

For live migrations, consider making handler failures blocking when they affect relation/taxonomy fields:

```php
} catch (Throwable $e) {
    $message = "Handler '{$handlerId}' failed on {$targetHandle}: " . $e->getMessage();
    $report['warnings'][] = $message;
    $ctx->report?->warn($message);

    if ($ctx->dryRun === false && $handlerId === 'relation') {
        throw $e;
    }
}
```

This avoids silently saving pages with missing taxonomy relations after a resolver failure.

---

_Reviewed: 2026-04-28T00:00:00Z_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: standard_
