# Plan 11-06 Summary: Promoted/shared target ETL support

## Outcome

Promoted/shared relation targets now have their own extract, transform, load ordering, and state-row identity while owner page extracts remain source-faithful with raw FK values.

## Implemented

- Extended `ExtractService` to read `mapping.promotedTargets` and extract those source entities as standalone records under `extracted/promoted_<stateSource>/<id>.json`.
- Promoted extract records carry `kind=promotedTarget`, `stateSource`, `stateKey`, the promoted target contract, per-site detail rows, and `kunstmaanSourceId=<stateSource>:<id>`.
- Owner detail extracts keep raw FK columns such as `employee_id`; relation-expanded helper data remains opt-in through the existing relation-join flag.
- Extended `TransformService` to recognize promoted target records before owner filter checks and emit standalone Craft payloads using the promoted target contract's `targetSection`, `targetEntryType`, `stateSource`, `sourceRef`, `targetRef`, and `relationIntent`.
- Updated load ordering in `MigrateController` so transformed promoted target payloads are loaded before owner entries, even when `--entities` scopes the owner page type.
- Added `EntryMigrationService::savePromotedTargetForSites()` and routed promoted payloads through it from `AtomicMigrationService`, reusing the existing idempotent state-table path.

## Verification

- `php -l src/extract/ExtractService.php && php -l src/transform/TransformService.php && php -l src/load/EntryMigrationService.php && php -l src/load/AtomicMigrationService.php && php -l src/console/MigrateController.php`
- `vendor/bin/phpunit tests/unit/extract/ExtractServicePromotedTargetsTest.php tests/unit/extract/ExtractServiceFkJoinTest.php tests/unit/transform/TransformServicePromotedTargetsTest.php tests/unit/load/EntryMigrationServicePromotedTargetsTest.php tests/unit/console/MigrateControllerPromotedTargetsTest.php --testdox`
- `vendor/bin/phpunit tests/unit/extract tests/unit/transform tests/unit/load tests/unit/console tests/unit/compile --testdox`
- Plan 11-06 acceptance greps for promoted/stateSource wiring, raw owner FK semantics, no production `NewsPage`/`Employee` hardcoding, and unchanged taxonomy-only `resolveReferenced` behavior.

## Notes

- Promoted target extraction currently reads the configured promoted source table and localizes fields with Gedmo `ext_translations` when available.
- Load idempotency is inherited from the existing `stateSource`/`stateKey` lookup and skip/update behavior.
