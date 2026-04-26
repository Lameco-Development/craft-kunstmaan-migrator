# Phase 4: Adapters, Verify & Settings - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-26
**Phase:** 04-adapters-verify-settings
**Areas discussed:** Adapter integration shape, Verify scope & determinism, CP Settings page shape, Verbosity & rehearsal report

---

## Adapter integration shape

### Q1: Where should SEOmatic + Retour migration run within the `migrate --live` pipeline?

| Option | Description | Selected |
|--------|-------------|----------|
| Bolt-on stages (Recommended) | extract → transform → load → finalize → seo → retour. v1's SeoMigrationService runs LAST so kuma_seo image refs resolve via state lookup. New stages skip when plugin absent. | |
| Standalone sub-actions only | `migrate/seo` and `migrate/retour` exist but `migrate --live` does NOT call them. | |
| Bolt-on + sub-actions both | Both: `migrate --live` runs them in sequence AND standalone sub-actions exist for resume/debug — mirrors Phase 3's ETL-02 pattern. | ✓ |

**User's choice:** Bolt-on + sub-actions both
**Notes:** Symmetric with Phase 3's per-stage commands; preserves both the one-shot rehearsal experience and resume/debug surface.

### Q2: Where does runtime plugin detection live?

| Option | Description | Selected |
|--------|-------------|----------|
| Inside the adapter service (Recommended) | Service short-circuits with WARN if plugin absent — mirrors v1's CONFIG-08 gate. | ✓ |
| Conditional Plugin::config() registration | Boot-time plugin presence check; registers service only when plugin exists. | |
| Controller-level guard | Controller checks `getPlugin('seomatic')` before calling service. | |

**User's choice:** Inside the adapter service
**Notes:** Single seam; controller stays thin; matches v1.

### Q3: Absence-warning shape when adapter plugin missing during `migrate --live`?

| Option | Description | Selected |
|--------|-------------|----------|
| WARN once + REPORT.md note (Recommended) | One stderr WARN at stage start + row in REPORT.md `## Skipped stages`. Audit-preserving. | ✓ |
| WARN once only | Stderr line, nothing in REPORT.md. | |
| Per-entry WARN | Every entry gets a skip line. Noisy. | |

**User's choice:** WARN once + REPORT.md note

### Q4: Do SEO + Retour need mapping.yaml rows, or are they hardcoded?

| Option | Description | Selected |
|--------|-------------|----------|
| Hardcoded behavior (Recommended) | v1's services read `kuma_seo` / `kuma_redirects` directly. Table-name overrides via Settings (`seoTableName`, `redirectsTableName`). | ✓ |
| Mapping.yaml-driven | Per-locale SEO + per-redirect-flag mapping rows go through analyze/map. | |
| Hybrid | Hardcoded core path; mapping.yaml `verify` + `adapters` blocks for tolerances + table-name overrides. | |

**User's choice:** Hardcoded behavior
**Notes:** kuma_seo / kuma_redirects schemas are stable Kunstmaan vendor schemas; analyze coverage of these tables would be churn for zero gain.

---

## Verify scope & determinism

### Q1: VER-02 (HTML/URL spot-check) — ship in Phase 4 or defer?

| Option | Description | Selected |
|--------|-------------|----------|
| Ship full v1 shape (Recommended) | Port `verify/capture-baseline-html` + URL diff in `verify/index`. v1's B1 fix (real diff vs byte count) ports too. | ✓ |
| Counts-only in v1.0 | VER-01 + VER-03 only; URL spot-check is TODO. Cuts ~300 LOC. | |
| Counts + URL but URL is opt-in | Both gates exist; Gate 2 only runs with `--url-spot-check` flag. | |

**User's choice:** Ship full v1 shape
**Notes:** CQM rehearsal benefits from URL diff coverage; B1 fix already debugged in v1.

### Q2: What goes into the baseline JSON snapshot?

| Option | Description | Selected |
|--------|-------------|----------|
| Counts + light metadata (Recommended) | Per-entry-type counts, asset count, taxonomy counts, redirect count, SEOmatic bundle count. | ✓ |
| Full v1 deterministic SHA shape | Port BaselineSnapshotService (525 LOC) — per-entry contentSha256, asset hash_file SHA, sorted by tuples. | |
| Counts now + content-SHA opt-in (`--deep`) | Default = counts; `--deep` flag adds SHAs. Future-proofs without forcing the cost. | |

**User's choice:** Counts + light metadata
**Notes:** v1's full deterministic snapshot is overkill for the operator workflow; SHA path is deferred to a follow-up.

### Q3: Where do tolerance + URL diff threshold values live?

| Option | Description | Selected |
|--------|-------------|----------|
| Settings + CLI override (Recommended) | Settings::$verifyCountTolerance (1%) + Settings::$verifyUrlDiffThreshold (5%); CLI flags override per-run. | ✓ |
| mapping.yaml verify block (v1 style) | `verify.tolerance` keys in mapping.yaml — v1 pattern. | |
| CLI flags only | Per-run only; loses persistence. | |

**User's choice:** Settings + CLI override
**Notes:** Keeps mapping.yaml clean of config noise; matches Phase 2 / D-10 ladder.

### Q4: Verify report output format?

| Option | Description | Selected |
|--------|-------------|----------|
| Markdown only (Recommended) | `storage/migration/VERIFY-<ts>.md` per v1 / VER-03. | ✓ |
| Markdown + JSON sidecar | Both `VERIFY-<ts>.md` + `VERIFY-<ts>.json` for machine consumption. | |
| JSON only | Machine-first. | |

**User's choice:** Markdown only
**Notes:** JSON sidecar deferred until NEXT-04 cross-client matrix actually consumes it.

---

## CP Settings page shape

### Q1: How should the CP Settings page be organized?

| Option | Description | Selected |
|--------|-------------|----------|
| Single page, grouped sections (Recommended) | One `_settings.twig` with H2-separated Connectivity / AI / Defaults / Verify / Adapters sections. | ✓ |
| Tabbed sub-pages | Tabs for each section. | |
| Read-only + env-var hint | Form is read-only; env vars / config file authoritative. | |

**User's choice:** Single page, grouped sections

### Q2: Array field rendering?

| Option | Description | Selected |
|--------|-------------|----------|
| Craft EditableTable (Recommended) | Built-in macro; type-checked add/remove rows. | ✓ |
| Plain textareas (one entry per line) | Cheapest; no validation. | |
| Comma-separated text inputs | Single-line CSV. Ugliest. | |

**User's choice:** Craft EditableTable
**Notes:** Native CP look; `localeMap` two-column shape doesn't render well as CSV.

### Q3: Anthropic API key field shape?

| Option | Description | Selected |
|--------|-------------|----------|
| Masked password input + env hint (Recommended) | `<input type="password">` with help text noting env-var override. Doctor presence-only invariant preserved. | ✓ |
| Plain text with env-only recommendation | Visible field with strong inline warning. | |
| Read-only — env var only | Field grayed out; env-only configuration. | |

**User's choice:** Masked password input + env hint

### Q4: Where does the CP entry surface in the navigation?

| Option | Description | Selected |
|--------|-------------|----------|
| Settings → Plugins only (Recommended) | Standard Craft plugin settings flow; `hasCpSettings = true` already wires it. | ✓ |
| Plugins + top-level utility entry | Adds `Utilities → Kunstmaan Migrator`. Drifts toward dropped CP runner. | |
| Plugins + read-only status mirror | NEXT-02 opportunistically. | |

**User's choice:** Settings → Plugins only
**Notes:** Aligns with PROJECT.md out-of-scope (no top-level CP nav for v1).

---

## Verbosity & rehearsal report

### Q1: How does CFG-02 verbosity layer relate to Yii's built-in `-v` flag?

| Option | Description | Selected |
|--------|-------------|----------|
| Layer on top of Yii's `-v` (Recommended) | `-v` = stage timings, `-vv` = per-entry detail, `-vvv` = SQL traces. Counted invocations in controller. | ✓ |
| Replace with our own `--log-level=` | Single `--log-level=warn|info|debug|trace` flag. | |
| Default to our `-v..-vvv` shape only | Map Yii's `-v` to our levels. | |

**User's choice:** Layer on top of Yii's `-v`
**Notes:** Operators familiar with `-v..-vvv` (ssh / git / rsync / docker) get matching muscle memory.

### Q2: What does "asset RCA logging" actually log?

| Option | Description | Selected |
|--------|-------------|----------|
| Per-asset failure root-cause line (Recommended) | Structured RCA line: reason enum + legacy_id + relative path. Logs at `-v`; included in REPORT.md verbatim. | ✓ |
| Just per-stage timing | RCA = stage timings only. | |
| Verbose dump of asset resolution chain | Full per-asset chain breadcrumbs. | |

**User's choice:** Per-asset failure root-cause line

### Q3: Log file shape (CFG-02: `storage/migration/*.log`)?

| Option | Description | Selected |
|--------|-------------|----------|
| One file per run, timestamped (Recommended) | `storage/migration/migrate-<ts>.log` per run. Self-rotating by name. | ✓ |
| Single rolling `migrate.log` | Single appender file with size rotation. | |
| Per-stage files (`extract.log`, ...) | Separate file per stage. | |

**User's choice:** One file per run, timestamped

### Q4: CFG-03 rehearsal report — extend Phase 3's REPORT.md or new artifact?

| Option | Description | Selected |
|--------|-------------|----------|
| Extend Phase 3's REPORT.md (Recommended) | New sections (`## Asset RCA`, `## Skipped stages`, `## Rehearsal summary`) added to existing REPORT.md. | ✓ |
| New `REHEARSAL-<ts>.md` artifact | Distinct artifact per `migrate --live` run. | |
| Markdown + JSON sidecar | REPORT.md + REPORT-<ts>.json. | |

**User's choice:** Extend Phase 3's REPORT.md
**Notes:** Avoids artifact proliferation; verify keeps its own VERIFY-<ts>.md as a separate concern.

---

## Claude's Discretion

(Captured in CONTEXT.md `<decisions>` → "Claude's Discretion" — researcher / planner pick file boundaries, exact Plugin::config() registration order, Plugin::init() wiring shape, REPORT.md section ordering, VerifyController-vs-MigrateController surface, per-stage timing instrumentation point, exact `OK`/`INFO`/`WARN`/`FAIL` shape for doctor's 7th + 8th checks.)

## Deferred Ideas

(Full list in CONTEXT.md `<deferred>`. Headlines: full deterministic snapshot port, JSON sidecar, top-level CP nav, log file rotation, per-stage log files, mapping.yaml verify block, CP form connection-test button, `--max-failures` flag.)
