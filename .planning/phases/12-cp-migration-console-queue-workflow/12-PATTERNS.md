# Phase 12: CP Migration Console & Queue Workflow - Pattern Map

**Mapped:** 2026-04-29  
**Source:** gsd-pattern-mapper output, condensed for planner consumption  
**Scope:** Craft CP utility, controllers, queue jobs, run records, workflow services, safety gates, templates, and tests.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/Plugin.php` | config/provider | event-driven + request-response | `src/Plugin.php` | exact |
| `src/utilities/KunstmaanMappingUtility.php` | utility | request-response | `src/utilities/KunstmaanMappingUtility.php` | exact |
| `src/controllers/MappingController.php` | controller | request-response + file-I/O | `src/controllers/MappingController.php` | exact |
| `src/controllers/MigrationConsoleController.php` | controller | request-response + queue dispatch | `src/controllers/MappingController.php`; brownfield `MappingDraftController.php` | role-match |
| `src/queue/jobs/MigrationStageJob.php` | queue job | event-driven + batch + file-I/O | brownfield `src/craft/queue/MigrationJob.php` | brownfield-only |
| `src/queue/jobs/MigrationPipelineJob.php` or staged equivalent | queue job | event-driven + staged batch | brownfield `src/bridge/queue/PipelineJob.php` | brownfield-only |
| `src/services/MigrationRunService.php` or `src/runs/MigrationRunService.php` | service/repository | CRUD + file-I/O | `src/load/MigrationStateService.php` | role-match |
| `src/records/MigrationRunRecord.php` | model/record | CRUD | `src/migrations/Install.php`; `src/load/MigrationStateService.php` | partial |
| `src/migrations/*CreateMigrationRuns.php` | migration | CRUD/schema | `src/migrations/Install.php` | exact |
| `src/workflow/AnalyzeWorkflow.php` | service | batch + file-I/O | `src/console/AnalyzeController.php` | role-match |
| `src/workflow/CompileWorkflow.php` | service | transform + file-I/O | `src/console/CompileController.php` | role-match |
| `src/workflow/MigrateWorkflow.php` | service | batch + CRUD + file-I/O | `src/console/MigrateController.php` | role-match |
| `src/workflow/VerifyWorkflow.php` | service | batch + file-I/O | `src/console/VerifyController.php` | role-match |
| `src/safety/MigrationSafety.php` | utility | request-response + event-driven guard | `src/NeverProductionTrait.php`; brownfield queue guard | role-match |
| `src/safety/MigrationGateService.php` | service | validation + request-response | `src/console/CompileController.php`; `src/console/MigrateController.php` | role-match |
| `templates/_console/*.twig` | CP templates | request-response + form dispatch | `templates/_mapping/index.twig`; `src/templates/_settings.twig` | role-match |
| `tests/unit/runs/MigrationRunServiceTest.php` | test | CRUD | `tests/unit/mapping/MappingFileTest.php` | role-match |
| `tests/unit/queue/MigrationStageJobTest.php` | test | event-driven | `tests/unit/console/MigrateControllerCompilePreflightTest.php` | partial |
| `tests/integration/PluginConsoleRegistrationTest.php` | test | config/event-driven | `tests/integration/PluginBootstrapTest.php` | exact |

## Existing Patterns to Preserve

### Plugin registration and wiring

Use the existing `src/Plugin.php` component/event pattern:

- `Plugin::config()` declares plugin components.
- `init()` switches controller namespace between console and web.
- CP Utility is registered through `Utilities::EVENT_REGISTER_UTILITIES`.
- CP template root is registered through `CraftView::EVENT_REGISTER_CP_TEMPLATE_ROOTS`.
- Related services are wired as siblings in `init()`.
- Settings render through `settingsHtml()` and `kunstmaan-migrator/_settings.twig`.

Phase 12 should add component slots for run service, gate service, workflow services, and queue orchestration without changing the existing plugin type or controller namespace pattern.

### Existing utility shell

Use `src/utilities/KunstmaanMappingUtility.php` as the exact utility pattern:

- Keep the utility under Craft Utilities.
- Rename display copy to `Kunstmaan Migration Console`.
- Keep the icon `shuffle` unless a native Craft migration/queue icon is clearly available.
- Render the new console shell through Craft CP template mode.
- Collect view variables through a controller/static view-model method, matching `MappingController::utilityVariables()`.

### CP controller validation

Use `src/controllers/MappingController.php` as the base pattern and extend it for Phase 12:

- All mutation actions call `requireCpRequest()` and `requirePostRequest()`.
- Phase 12 actions also call `requireAdmin()` because user chose admin-only CP migration actions.
- Live/destructive actions additionally call `requireElevatedSession()`.
- Invalid parameters surface via Craft flash messages and redirect back to the utility.
- Redirects preserve tab/filter query parameters.
- Mapping writes continue through `MappingFile` atomic helpers.

### Queue job design

No v2 queue job exists. Use the brownfield job only for principles:

- Queue jobs must be thin shells with serialization-safe public scalar/array properties.
- Jobs re-check `CRAFT_ENVIRONMENT !== production` inside `execute()`, not only in controllers.
- Jobs reconstruct runtime filters/options inside `execute()`.
- Jobs delegate real work to shared workflow services; they must not duplicate console controller bodies.
- Jobs update run records at queued/running/progress/succeeded/failed boundaries.
- Use `setProgress()` sparingly and include operator-readable progress text.

### Run records

Use `src/load/MigrationStateService.php` and `src/migrations/Install.php` as data-access and schema analogs:

- Prefer a service/repository for mutation logic instead of putting behavior in an ActiveRecord.
- Suggested table: `{{%kunstmaanmigrator_runs}}`.
- Suggested columns: `id`, `stage`, `mode`, `status`, `filters` JSON, `options` JSON, `gateSnapshot` JSON nullable, `initiatedByUserId`, `queueJobId`, `progress`, `logPath`, `artifactPaths` JSON, `summary` JSON, `failure` JSON/text, `dateStarted`, `dateFinished`, `dateCreated`, `dateUpdated`.
- Add indexes for status, stage/mode, queue job id, and dates.
- Preserve run records on uninstall unless explicitly told otherwise.

### Shared workflow extraction

Console controllers remain canonical operator commands, but Phase 12 needs shared workflow services so CP/queue does not shell out or duplicate logic:

- Extract analyze orchestration from `src/console/AnalyzeController.php` behind a service that accepts a typed options object and reporter/progress callbacks.
- Extract compile orchestration from `src/console/CompileController.php` behind a service that returns compile status/artifact metadata/fatal warning counts.
- Extract migrate orchestration from `src/console/MigrateController.php` behind a service that preserves dry-run/live behavior, filters, report/log writing, and existing production refusal.
- Extract verify/report orchestration from `src/console/VerifyController.php` behind a service that returns artifact paths and pass/fail metadata.
- Console controllers should become adapters over these services, not separate implementations.

### Production and gate safety

Use `src/NeverProductionTrait.php` as the console-specific analog, but add a CP/job-safe helper:

- `MigrationSafety::isProduction(): bool`
- `MigrationSafety::environmentName(): string`
- `MigrationSafety::assertNotProductionForCp(): void`
- `MigrationSafety::assertNotProductionForJob(): void`

Gate service should return structured gate results for UI rendering and controller decisions. CP live migration blocks when any gate fails or is unverifiable; there is no CP “warn but continue” for unverifiable live gates.

### Twig/Craft CP UI

Use existing `templates/_mapping/index.twig` and `src/templates/_settings.twig` patterns:

- Prefer Craft CP native classes, forms, panes, tables, notices, tabs, buttons, and form macros.
- Use POST + `csrfInput()` + `actionInput(...)` for all mutations.
- Use GET/query params for tab selection, filters, and search.
- Keep custom CSS local and prefixed with `km-console-` or `km-map-`.
- Do not introduce a JS/CSS build pipeline.

Recommended partials:

- `templates/_console/index.twig`
- `templates/_console/_tabs.twig`
- `templates/_console/_readiness.twig`
- `templates/_console/_analyze.twig`
- `templates/_console/_mapping.twig`
- `templates/_console/_compile.twig`
- `templates/_console/_runs.twig`
- `templates/_console/_run-detail.twig`
- `templates/_console/_reports.twig`
- `templates/_console/_danger-zone.twig`

### Tests

Closest analogs:

- `tests/unit/mapping/MappingFileTest.php` for file/atomic behavior.
- `tests/unit/console/MigrateControllerCompilePreflightTest.php` for controller/preflight behavior.
- `tests/integration/PluginBootstrapTest.php` for plugin registration/wiring.

Phase 12 tests should cover:

- run service create/update/list/find behavior;
- migration table idempotency;
- job serialization-safe properties and production refusal;
- admin/elevated session requirements on CP controller actions where practical;
- gate service outputs for live/dry-run/analyze;
- mapping batch actions do not bypass `mapping.yaml` canonical writes;
- utility registration and template rendering variables.
