# Phase 12: CP Migration Console & Queue Workflow - Context

**Gathered:** 2026-04-29
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 12 adds a Craft Control Panel operator cockpit for the existing Kunstmaan -> Craft migration workflow. The CP should make the migration easier to understand, review, launch, and audit from dev/staging Craft, without replacing the CLI as the canonical/debuggable backend.

This phase delivers a tabbed CP migration console, first-class migration run records, file-backed logs/artifacts, queue-backed safe actions, guarded dry-run execution, guarded live execution, and a stronger mapping review UX. It must preserve the existing safety model: no production migration, no runtime AI outside analyze, `mapping.yaml` remains the mapping source of truth, long-running stages do not run inside web requests, and failed/unsupported migration surfaces stay visible.

</domain>

<decisions>
## Implementation Decisions

### CP Surface Shape

- **D-01:** Expand the existing `KunstmaanMappingUtility` into a tabbed migration console under Craft Utilities rather than adding a dedicated CP section/nav item.
- **D-02:** The CP console should include tabs or equivalent subviews for dashboard/readiness, analyze, mapping review, compile, migration run/dry-run/live controls, insights/reports, and dangerous/reset operations where appropriate.
- **D-03:** A dedicated top-level CP section is out of scope for this phase. Revisit only if the Utility surface becomes too cramped after implementation.

### Execution Safety Boundary

- **D-04:** CP actions may trigger safe stages and queued migrations. Safe stages include doctor/preflight checks, analyze, compile, verify/report generation, dry-run migration, and live migration when strict gates pass.
- **D-05:** No long-running migration stage should execute inline inside a web request. CP controllers enqueue jobs, create/update run records, or show the equivalent CLI command/remediation.
- **D-06:** CLI remains canonical and scriptable. CP execution must reuse the same orchestration services as CLI rather than shelling out to `php craft ...` or duplicating controller logic.
- **D-07:** Analyze may be launched from CP as a queued job only when an Anthropic API key is present and the admin explicitly confirms AI usage. Analyze remains the only stage that may call Anthropic.

### Live Migration Gates

- **D-08:** Queued live migration from CP is allowed, but only behind strict gates.
- **D-09:** Mandatory live gates: non-production environment, admin user, elevated session, typed confirmation phrase, successful dry-run for the same filters/options, recent compile with no fatal warnings, queue worker readiness check, backup acknowledgement checkbox, and mapping coverage with no unsupported/warning rows unless those rows were explicitly accepted.
- **D-10:** If a live gate cannot be verified automatically, block CP live execution and show the CLI command/manual remediation. Do not offer a "warn but continue" CP override for unverifiable gates.
- **D-11:** The production hard-block must apply independently in CP controllers and queue jobs, not only in console controllers.

### Queue and Run Records

- **D-12:** Add first-class migration run records instead of relying only on Craft's generic queue UI. Run records should track stage, mode, status, filters/options, initiating admin, queue job id(s), started/finished timestamps, log path, report/artifact path(s), summary JSON, and failure details.
- **D-13:** Keep detailed logs/reports as files under `storage/migration` or a run-specific subdirectory so operators can inspect and diff artifacts outside the CP.
- **D-14:** Queue jobs should update progress through Craft's job progress APIs and write meaningful status back to the run record.
- **D-15:** Use batched jobs or staged jobs for large/long-running work. Do not assume one monolithic queue job can safely process every site within default queue time-to-run constraints.
- **D-16:** Queue worker readiness is a first-class CP insight and live gate. If reliable queue execution is not configured/provable, CP should block live queue execution and show the CLI command instead.

### Settings versus Config

- **D-17:** CP Settings should expose stable, site-safe options only: connectivity/source path, locale map, default filters, adapter toggles, queue/execution allowance, and retention/report defaults where useful.
- **D-18:** Advanced project-shape hints stay in `config/kunstmaan-migrator.php` for now. This includes relation mirror rules, generic content block overrides, and similarly technical mapping-policy knobs.
- **D-19:** The settings UI should remain concise. Avoid turning plugin settings into a full mapping-authoring product.

### Mapping Review UX

- **D-20:** Improve the existing mapping review utility instead of building a full Feed Me-style visual mapper.
- **D-21:** Mapping review improvements should include filters, status/warning visibility, batch actions, target/handler editing, and clearer display of why a row is unsafe, unsupported, accepted, dropped, or out of scope.
- **D-22:** CP mapping edits must continue to update the canonical single `mapping.yaml` through structured, atomic helpers. Do not introduce draft mapping files or a parallel database-backed mapping source of truth.

### Permissions and Destructive Actions

- **D-23:** Phase 12 CP migration actions are admin-only. Do not introduce delegated custom permissions in this phase.
- **D-24:** Destructive/high-risk CP actions such as live migration, truncate/reset, or artifact/state cleanup require an elevated session and typed confirmation in addition to admin status.
- **D-25:** CP forms must use Craft's normal CP request, POST, CSRF, and controller validation patterns.

### the agent's Discretion

- Exact tab names, controller/action names, queue job class names, run table column names, and run artifact directory naming are left to the planner, as long as the decisions above are satisfied.
- Planner may decide whether to implement run records as an install migration on the existing plugin track or a new migration file after `Install.php`; either way, state table compatibility must not be broken.
- Planner may choose the sequence of service extraction versus CP UI delivery, but CP queue jobs must not duplicate console controller orchestration.
- Planner may decide whether the first implementation uses a single staged queue job, multiple chained jobs, or `BaseBatchedJob`, provided dry-run/live progress is visible and resumability/failure states are explicit.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 12 Scope and Project Ground Rules

- `.planning/ROADMAP.md` - Phase 12 row and section; establishes CP Migration Console & Queue Workflow as the next v1.0 phase.
- `.planning/STATE.md` - current Phase 11 completion state and Phase 12 roadmap evolution note.
- `.planning/PROJECT.md` §"Core Value", §"Operator workflow", §"Migration model", §"Out of Scope (v1)", and §"Key Decisions" - source of truth for CLI-first workflow, page-driven migration, runtime-zero-AI, single `mapping.yaml`, and prior CP runner caution.
- `.planning/REQUIREMENTS.md` - current requirement set and existing CFG/VER/ETL/FILT constraints that Phase 12 must not regress.
- `CLAUDE.md` - locked architectural ground rules for this repository.

### Prior Decisions That Constrain Phase 12

- `.planning/phases/04-adapters-verify-settings/04-CONTEXT.md` - CP Settings shape, adapter/verify observability, log/report conventions, and previous decision to avoid a broad CP utility runner.
- `.planning/phases/09-migration-workflow-hardening-page-rooted-introspection-audit/09-CONTEXT.md` - canonical workflow hardening, CLI remains canonical, visible skip/failure requirements, and genericity constraints.
- `.planning/phases/10-generic-migration-rehearsal-gap-closure/10-CONTEXT.md` - generic fallback/load hardening and rehearsal-driven safety posture.
- `.planning/phases/11-relation-target-introspection-promotion/11-CONTEXT.md` - graph artifacts, compile validation, promoted/shared target ordering, and Phase 11 proof state that CP insights should surface.

### Current v2 Implementation Seams

- `src/Plugin.php` - registers CP settings, web controller namespace, CP utility, template roots, migration services, and service DI wiring.
- `src/templates/_settings.twig` - current CP settings fragment and boundary for stable settings.
- `src/utilities/KunstmaanMappingUtility.php` - current CP Utility to expand into the migration console.
- `src/controllers/MappingController.php` - current CP controller for structured mapping row edits.
- `templates/_mapping/index.twig` - current mapping review UI to extend.
- `src/mapping/MappingReview.php` - shared mapping review helpers for CP/CLI surfaces.
- `src/mapping/MappingFile.php` - canonical mapping read/write/update helpers and atomic artifact writes.
- `src/console/AnalyzeController.php` - current analyze orchestration that queue jobs/services must reuse or extract from.
- `src/console/CompileController.php` - current compile orchestration and graph/target validation behavior.
- `src/console/MigrateController.php` - current dry-run/live/full pipeline orchestration, report/log behavior, and destructive truncate gates.
- `src/console/VerifyController.php` - current verify/reporting orchestration.
- `src/NeverProductionTrait.php` - console-only production hard-block that needs a CP/job equivalent.
- `src/migrations/Install.php` - current install migration and state-table compatibility baseline.

### Brownfield v1 References to Learn From, Not Blindly Port

- `~/Sites/craft-kunstmaan-migrator/src/craft/queue/MigrationJob.php` - thin queue wrapper around per-entry atomic migration, progress throttling, production re-check, scalar serialization lesson.
- `~/Sites/craft-kunstmaan-migrator/src/bridge/queue/PipelineJob.php` - chained stage queue design and asset barrier pattern; useful as a cautionary reference for overgrown queue orchestration.
- `~/Sites/craft-kunstmaan-migrator/src/craft/controllers/MappingDraftController.php` - CP write-path safety patterns: admin gate, POST, CSRF, writable checks, failure surfacing.
- `~/Sites/craft-kunstmaan-migrator/src/craft/utilities/MappingDraftUtility.php` - Utility badge/count and truncation ideas; do not port the old draft-file model.

### Craft CMS 5 Documentation

- `https://github.com/craftcms/docs/blob/main/docs/5.x/extend/utilities.md` - Craft CP Utilities registration and permissions behavior.
- `https://github.com/craftcms/docs/blob/main/docs/5.x/extend/controllers.md` - CP controller request validation, `requireCpRequest()`, `requirePostRequest()`, `requireAdmin()`, `requireElevatedSession()`, and CSRF behavior.
- `https://github.com/craftcms/docs/blob/main/docs/5.x/extend/queue-jobs.md` - `BaseJob`, `setProgress()`, `BaseBatchedJob`, `Queue::push()`, and warnings that critical tasks should not blindly rely on the default queue.
- `https://github.com/craftcms/docs/blob/main/docs/5.x/extend/cp-section.md` - CP section option; intentionally deferred for this phase.
- `https://github.com/craftcms/docs/blob/main/docs/5.x/extend/user-permissions.md` - permission APIs; noted, but Phase 12 chooses admin-only CP actions.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- `KunstmaanMappingUtility` already registers a Craft Utility and renders CP content through the plugin template root; this is the natural shell for a tabbed migration console.
- `MappingController::utilityVariables()` already loads `mapping.yaml`, scopes rows to a selected page entity, computes status counts, and exposes Craft target options.
- `MappingFile::writeAtomic()` / `writeAtomicJson()` / `updateRow()` are the safe write primitives for mapping and artifact files.
- Console controllers already encode the real orchestration and safety checks for analyze, compile, migrate, and verify. Phase 12 should extract reusable workflow services from them rather than introducing shell-outs.
- Existing reports (`REPORT.md`, `VERIFY-<ts>.md`, `PAGE-ROOTED-COVERAGE.md`, graph artifacts, logs) provide the first CP insights without inventing new analysis.

### Established Patterns

- Legacy-reading/destructive commands gate first on `NeverProductionTrait`.
- Operator-reviewed mapping decisions are sacred; commands should refuse or require explicit overwrite rather than silently mutating reviewed structures.
- Runtime-zero-AI remains locked: only analyze may call Anthropic.
- Settings expose stable operator preferences; advanced per-project mapping policy belongs in config.
- Markdown/JSON/file artifacts under `storage/migration` are part of the operator audit trail and should remain inspectable outside CP.

### Integration Points

- `Plugin::init()` web branch currently registers only `KunstmaanMappingUtility`; Phase 12 can register additional Utility classes or keep one Utility with internal tabs.
- CP controllers should live under `src/controllers/` and use Craft controller validation at action entry.
- Queue jobs should live under a new queue/jobs namespace and carry only serialization-safe scalar/array properties.
- A run-record service/repository should sit between CP controllers, queue jobs, and workflow services.
- A shared workflow service should become the boundary consumed by CLI controllers and queue jobs.

</code_context>

<specifics>
## Specific Ideas

- The user explicitly wants Phase 12, not a future v1.1 by default.
- The user selected the full CP direction: tabbed Utility, queued safe stages, queued live migration with strict gates, first-class run records, stable CP settings plus advanced config file, improved mapping review UX, and admin-only CP access.
- The user chose queued live migration from CP as in scope, provided strict gates pass.
- Gate failure posture is strict: if a live gate cannot be verified automatically, block and show the CLI command/manual remediation instead of allowing an override.
- Analyze from CP is allowed only with API-key presence and explicit AI-usage confirmation.

</specifics>

<deferred>
## Deferred Ideas

- Dedicated top-level CP section/nav item is deferred unless the tabbed Utility proves insufficient.
- Full Feed Me-style visual mapping authoring is deferred; Phase 12 improves structured mapping review instead.
- Delegated per-capability CP permissions are deferred; Phase 12 uses admin-only CP actions.
- Custom queue infrastructure/worker management beyond readiness detection and remediation guidance is deferred.
- Deep deterministic content snapshot verification remains outside Phase 12 unless planning finds it essential for live CP gates.
- Orphan-media sync remains `NEXT-05` / recovery tooling, not part of the CP migration console.

</deferred>

---

*Phase: 12-cp-migration-console-queue-workflow*
*Context gathered: 2026-04-29*
