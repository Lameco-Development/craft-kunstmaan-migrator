---
phase: 04-adapters-verify-settings
plan: 08
subsystem: verify
tags: [verify, baseline-html, spot-check, verbatim-port, file-io, optional-step]

# Dependency graph
requires:
  - phase: 04-adapters-verify-settings
    provides: Plan 04-03 SpotCheckUrlFetcher + fetchAndNormalize() (D-58 B1 fix preserved)
provides:
  - src/verify/CaptureBaselineHtmlService.php (73 LOC) — file-I/O service that snapshots URLs into baseline HTML files for diff-against-migrated-site spot checks
  - capture(string $urlListPath, string $outputDir): int contract (returns count of URLs successfully captured)
  - public ?SpotCheckUrlFetcher $fetcher = null DI seam (test affordance + Plan 04-09 setComponents wiring)
  - Operator-curated URL-list shape: `#`-comment + blank-line filter on spot-check-urls.txt
  - urlToSlug regex /[^a-zA-Z0-9_-]+/ for deterministic destination filenames
affects:
  - 04-09-verify-controller-and-plugin-wiring (DI registration + actionCaptureBaselineHtml)
  - 04-12-tests-and-reconciliation (Phase 4 test corpus — capture loop functional smoke)
  - Phase 5 rehearsal (operator runs `verify capture-baseline-html` against legacy Kunstmaan site BEFORE first migration)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - D-54 verbatim-port discipline (73 LOC body byte-for-byte from v1)
    - Public DI seam with `?? new SpotCheckUrlFetcher()` fallback — Phase 5 unit-test affordance
    - Try/catch + Craft::warning per-URL: partial-success contract preserved
    - Operator-curated URL-list shape — `#`-comments + blank lines filtered

key-files:
  created:
    - src/verify/CaptureBaselineHtmlService.php
  modified: []

key-decisions:
  - "Verbatim port from v1's craft/verify/CaptureBaselineHtmlService.php — only allowed reshape: namespace lameco\\\\kunstmaanmigrator\\\\craft\\\\verify → lameco\\\\kunstmaanmigrator\\\\verify (drop the three-tier layout per PROJECT.md Key Decision)."
  - "SpotCheckUrlFetcher import re-pointed at v2 location (lameco\\\\kunstmaanmigrator\\\\verify\\\\SpotCheckUrlFetcher) shipped in Plan 04-03."
  - "No v2 contract reshapes needed — service does NOT touch MigrationFilters, MigrationReport, or any of the v1↔v2 surface deltas. Pure file-I/O + per-URL fetch loop."
  - "DI wiring deferred to Plan 04-09 — public DI surface ($fetcher) preserved for setComponents()."
  - "VER-02's 'optional' wording (D-58) preserved structurally: this service is invoked by a separate operator action `verify/capture-baseline-html`, not the routine `verify` command."

patterns-established:
  - "Verify file-I/O service idiom: read URL list → filter `#`-comments + blank lines → per-URL fetch via injected SpotCheckUrlFetcher → write `<slug>.html` with try/catch + Craft::warning partial-success contract"

requirements-completed: [VER-02]

# Metrics
duration: 2m 15s
completed: 2026-04-26
---

# Phase 4 Plan 08: CaptureBaselineHtmlService verbatim port Summary

## One-liner

Verbatim port of v1's 73-LOC `CaptureBaselineHtmlService` to v2's flat `verify/` namespace — file-I/O service that reads `spot-check-urls.txt`, fetches each URL via the just-shipped `SpotCheckUrlFetcher` (Plan 04-03), and writes `<slug>.html` baseline snapshots for diff-against-migrated-site spot checks.

## Tasks Completed

| Task | Name                                                | Commit  | Files                                              |
| ---- | --------------------------------------------------- | ------- | -------------------------------------------------- |
| 01   | Port v1 CaptureBaselineHtmlService to v2 verify ns  | ddf9473 | src/verify/CaptureBaselineHtmlService.php (73 LOC) |

## What Shipped

`src/verify/CaptureBaselineHtmlService.php` — exact 73-LOC byte-for-byte port of v1's `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CaptureBaselineHtmlService.php`. Two reshapes applied (and only two):

1. Namespace `lameco\kunstmaanmigrator\craft\verify` → `lameco\kunstmaanmigrator\verify` (drop the three-tier layout per PROJECT.md Key Decision).
2. `SpotCheckUrlFetcher` use-statement re-pointed at v2 location (Plan 04-03).

Every other byte preserved verbatim:

- `extends Component` Yii base for v2 Plugin::config() compatibility.
- 11-line class-header docblock documenting D-17 golden-URL gate context.
- `public ?SpotCheckUrlFetcher $fetcher = null` DI seam — null-coalesce fallback `$fetcher = $this->fetcher ?? new SpotCheckUrlFetcher()` preserved as the Phase 5 unit-test affordance.
- `capture(string $urlListPath, string $outputDir): int` signature returns count of URLs successfully captured.
- Two `RuntimeException` guards: `is_file($urlListPath)` ("URL list not found") and `is_dir($outputDir) && !mkdir(..., 0755, true) && !is_dir($outputDir)` ("Cannot create baseline dir") — error contract intact.
- `file()` + `array_map('trim', $lines)` + `array_filter(static fn(string $l): bool => $l !== '' && !str_starts_with($l, '#'))` — operator-curated URL-list shape preserved (the `#`-comment + blank-line filter is the canonical `spot-check-urls.txt` shape per D-Discretion).
- Capture loop: `fetcher->fetchAndNormalize($url)` → `urlToSlug($url)` → `file_put_contents($destination, $html)` with try/catch wrapping each URL → `Craft::warning("Baseline capture failed for {$url}: {$e->getMessage()}", __METHOD__)` on per-URL failure (partial-success contract — one bad URL doesn't kill the whole capture run).
- `urlToSlug` private method `preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline'` — slug determinism preserved.

LOC: 73 (exact match with v1 — within ±5 of plan acceptance criterion).

## Verification

- `php -l src/verify/CaptureBaselineHtmlService.php` → No syntax errors detected.
- `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\verify\CaptureBaselineHtmlService();'` → instantiates clean.
- All plan acceptance grep checks pass:
  - namespace `lameco\kunstmaanmigrator\verify` count 1
  - `class CaptureBaselineHtmlService extends Component` count 1
  - `public ?SpotCheckUrlFetcher $fetcher` count 1
  - `public function capture(` count 1
  - `fetchAndNormalize` count 1
  - `str_starts_with($l, '#')` count 1 (URL-list comment filter preserved)
  - `private function urlToSlug(` count 1
  - `preg_replace('/[^a-zA-Z0-9_-]+/'` count 1 (slug regex)
  - `file_put_contents` count 1
  - `craft.verify` count 0 (no leftover namespace references)
  - LOC 73 (in 68..78 band)
- `composer test` exits 0 (60 tests / 137 assertions — unchanged baseline; no test additions per plan, Plan 04-12 owns Phase 4 test corpus).

## Deviations from Plan

None — plan executed exactly as written. No v2 contract reshapes triggered: the service does not touch `MigrationFilters`, `MigrationReport`, `LegacyDbService`, or any of the v1↔v2 surface deltas. Pure file-I/O + per-URL fetch loop on top of the Plan 04-03 `SpotCheckUrlFetcher`.

## Wave 2 closure

Plan 04-08 is the final verbatim-port plan in Wave 2. With this commit, every Phase 4 verify-stage and adapter-stage service body is in `src/`:

- 04-02: SeomaticPayloadBuilder (165 LOC).
- 04-03: SnapshotDiffer (128) + SpotCheckUrlFetcher (234).
- 04-04: CountGateService (177) + BaselineCounterService (204, shape-derived).
- 04-06: SeoMigrationService.
- 04-07: RedirectMigrationService (683).
- 04-08: CaptureBaselineHtmlService (73) — this plan.

Wave 3 unblocks: Plan 04-09 wires every service into `Plugin::config()` and `Plugin::init()` and lands `VerifyController`; Plan 04-10 extends `MigrateController` with `actionSeo`/`actionRetour`; Plan 04-11 adds the doctor 7th + 8th checks; Plan 04-12 ships PHPUnit unit tests + Phase 4 RECONCILIATION.md + the ADP-03 composer-suggest guard.

## Requirements Closure

- **VER-02 (spot-check baseline HTML capture):** service-level **complete** — `CaptureBaselineHtmlService` ready. Final closure waits on Plan 04-09 (DI wiring + `VerifyController::actionCaptureBaselineHtml` operator action).

## Self-Check: PASSED

- File `src/verify/CaptureBaselineHtmlService.php` — FOUND
- Commit `ddf9473` — FOUND
