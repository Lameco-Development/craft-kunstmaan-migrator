# Phase 12: CP Migration Console & Queue Workflow - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `12-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-04-29  
**Phase:** 12-cp-migration-console-queue-workflow  
**Areas discussed:** CP surface shape, execution safety boundary, queue and run records, settings versus config, mapping review UX, permissions and destructive actions, live migration gates

---

## Area Selection

The user selected all proposed gray areas for discussion:

- CP surface shape
- Execution safety boundary
- Queue and run records
- Settings versus config
- Mapping review UX
- Permissions and destructive actions

No pending SQL todos were available to fold into this phase.

---

## CP Surface Shape

| Option | Description | Selected |
|--------|-------------|----------|
| Expand existing Utility into a tabbed migration console | Lowest-risk fit for a dev/staging migration tool; builds on `KunstmaanMappingUtility`. | yes |
| Create a dedicated CP section/nav item | More room and first-class navigation, but larger product surface. | |
| Keep only Settings + current Mapping Utility | Minimal, but does not satisfy the desired CP operator cockpit. | |

**User's choice:** Expand existing Utility into a tabbed migration console.  
**Notes:** The existing Utility is the starting point; a top-level CP section is deferred.

---

## Execution Safety Boundary

| Option | Description | Selected |
|--------|-------------|----------|
| CP can run safe stages and queued dry-run; live migration stays CLI-only for now | Safest incremental path. | |
| CP can run safe stages, queued dry-run, and queued live with strict gates | More useful CP surface; requires strong gating and run records. | yes |
| CP is insights-only; no execution from CP | Lowest risk, but not runnable via CP. | |

**User's choice:** CP can run safe stages, queued dry-run, and queued live with strict gates.  
**Notes:** Long-running stages should be queued, not run inline in web requests.

---

## Queue and Run Records

| Option | Description | Selected |
|--------|-------------|----------|
| Add first-class run records plus file-backed logs/artifacts; jobs update progress | Gives the CP reliable history/status beyond Craft queue rows while preserving inspectable files. | yes |
| Use Craft queue UI only; no plugin run table | Less code, but weak operator history and status model. | |
| File-backed run manifests only; no database table | Simple and diffable, but awkward for CP list/status queries. | |

**User's choice:** Add first-class run records plus file-backed logs/artifacts; jobs update progress.  
**Notes:** Run records should link to storage artifacts/logs.

---

## Settings Versus Config

| Option | Description | Selected |
|--------|-------------|----------|
| CP Settings for stable/site-safe options; advanced project-shape hints stay in config file | Keeps settings safe and understandable while preserving config for technical mapping policy. | yes |
| Expose most advanced mapping/config knobs in CP | Powerful but risks turning settings into a fragile mapping-authoring product. | |
| Avoid new settings; use config file only | Simple but less useful for CP operators. | |

**User's choice:** CP Settings for stable/site-safe options; advanced project-shape hints stay in config file.  
**Notes:** Advanced hints include `relationMirrorRules` and generic content block overrides.

---

## Mapping Review UX

| Option | Description | Selected |
|--------|-------------|----------|
| Improve existing mapping review with filters, warnings, batch actions, and handler/target editing | Builds on current mapping utility and keeps `mapping.yaml` canonical. | yes |
| Only link to existing mapping utility from dashboard | Too shallow for the requested operator cockpit. | |
| Build full Feed Me-style visual mapping authoring | Too broad for this phase and conflicts with the single-file mapping discipline. | |

**User's choice:** Improve existing mapping review with filters, warnings, batch actions, and handler/target editing.  
**Notes:** Structured `mapping.yaml` edits remain the persistence model.

---

## Permissions and Destructive Actions

| Option | Description | Selected |
|--------|-------------|----------|
| Custom permissions per capability; elevated session plus typed confirmation for live/truncate/reset | Fine-grained delegation. | |
| Admin-only for every CP migration action | Simpler and conservative for a dev/staging migration tool. | yes |
| Single utility permission; rely on NeverProduction only | Too weak for live/destructive actions. | |

**User's choice:** Admin-only for every CP migration action.  
**Notes:** Elevated session and typed confirmation still apply to destructive/high-risk actions.

---

## Live Migration Gates

The user accepted all recommended mandatory live gates:

- Non-production environment
- Admin user
- Elevated session
- Typed confirmation phrase
- Successful dry-run for the same filters/options
- Recent compile with no fatal warnings
- Queue worker readiness check
- Backup acknowledgement checkbox
- Mapping coverage has no unsupported/warning rows unless explicitly accepted

**Gate strictness:** If a gate cannot be verified automatically, block CP live execution and show the CLI command/manual remediation.  
**Analyze from CP:** Allow queued analyze when an API key is present and the operator confirms AI usage.

---

## the agent's Discretion

- Exact tab labels and CP layout details.
- Exact run-record schema and artifact directory naming.
- Exact queue job decomposition, provided jobs are progress-aware, gated, and do not duplicate CLI orchestration.

## Deferred Ideas

- Dedicated CP section/nav item.
- Full Feed Me-style mapping authoring.
- Delegated per-capability permissions.
- Queue worker management beyond readiness/remediation.

