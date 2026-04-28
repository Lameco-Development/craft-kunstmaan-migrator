---
plan: 08
phase: 04
title: "CaptureBaselineHtmlService verbatim port"
wave: 3
depends_on: ["04-03"]
files_modified:
  - src/verify/CaptureBaselineHtmlService.php
autonomous: true
requirements_addressed: [VER-02]
---

# Plan 04-08: CaptureBaselineHtmlService verbatim port

## Objective

Port v1's `craft/verify/CaptureBaselineHtmlService.php` (73 LOC) verbatim to `src/verify/CaptureBaselineHtmlService.php`. File-I/O service that reads `spot-check-urls.txt`, fetches each URL via `SpotCheckUrlFetcher` (Plan 04-03), writes `<slug>.html` files. Consumed by Plan 04-09's `VerifyController::actionCaptureBaselineHtml`.

## Context

- D-54: verbatim port + RECONCILIATION.
- D-58: VER-02's "optional" wording preserved structurally — `verify capture-baseline-html` is a separate operator action.
- The `#`-comment + blank-line filter is the canonical operator-curated URL-list shape.
- 73 LOC pure-PHP — no v2 reshape required beyond namespace.
- DI wiring lives in Plan 04-09.

## Tasks

<task id="01">
  <action>
Create `src/verify/CaptureBaselineHtmlService.php`. Copy v1's `~/Sites/craft-kunstmaan-migrator/src/craft/verify/CaptureBaselineHtmlService.php` body byte-for-byte. Reshape ONLY:

1. **Namespace** `craft\verify` → `verify`.
2. **`SpotCheckUrlFetcher` import** — point at the v2 location (`lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher`, just shipped in Plan 04-03).

Preserve verbatim:
- `public ?SpotCheckUrlFetcher $fetcher = null;` DI seam.
- The `capture(string $urlListPath, string $outputDir): int` signature.
- `is_file($urlListPath)` → throw RuntimeException ("URL list not found").
- `is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)` → throw RuntimeException ("Cannot create baseline dir").
- Fallback `$fetcher = $this->fetcher ?? new SpotCheckUrlFetcher();` (test seam).
- `file($urlListPath)` → `array_map('trim', $lines)` → `array_filter` with `static fn(string $l): bool => $l !== '' && !str_starts_with($l, '#')` (operator-curated URL-list shape, D-Discretion).
- The capture loop: `fetcher->fetchAndNormalize($url)` → `urlToSlug($url)` → `file_put_contents($destination, $html)` with try/catch + `Craft::warning` on error.
- `urlToSlug` private method: `preg_replace('/[^a-zA-Z0-9_-]+/', '_', $url) ?? 'baseline'`.

Body is 73 LOC; copy verbatim.
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/verify/CaptureBaselineHtmlService.php (entire file — verbatim source)
    - src/verify/SpotCheckUrlFetcher.php (Plan 04-03 just-shipped — confirm v2 namespace + fetchAndNormalize signature)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (CaptureBaselineHtmlService section)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-58 — VER-02 optional structural wording)
  </read_first>
  <acceptance_criteria>
    - `test -f src/verify/CaptureBaselineHtmlService.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\verify;' src/verify/CaptureBaselineHtmlService.php` returns `1`
    - `grep -c 'class CaptureBaselineHtmlService extends Component' src/verify/CaptureBaselineHtmlService.php` returns `1`
    - `grep -c 'public ?SpotCheckUrlFetcher \$fetcher' src/verify/CaptureBaselineHtmlService.php` returns `1`
    - `grep -c 'public function capture(' src/verify/CaptureBaselineHtmlService.php` returns `1`
    - `grep -c 'fetchAndNormalize' src/verify/CaptureBaselineHtmlService.php` returns at least `1`
    - `grep -c "str_starts_with(\$l, '#')" src/verify/CaptureBaselineHtmlService.php` returns at least `1` (URL-list comment filter preserved)
    - `grep -c 'private function urlToSlug(' src/verify/CaptureBaselineHtmlService.php` returns `1`
    - `grep -c "preg_replace('/\\[\\^a-zA-Z0-9_-\\]+/'" src/verify/CaptureBaselineHtmlService.php` returns at least `1` (slug regex)
    - `grep -c 'file_put_contents' src/verify/CaptureBaselineHtmlService.php` returns at least `1`
    - `grep -c 'craft.verify' src/verify/CaptureBaselineHtmlService.php` returns `0` (no leftover namespace references)
    - `php -l src/verify/CaptureBaselineHtmlService.php` outputs `No syntax errors detected`
    - Line count within ±5 of v1's 73 LOC: `[ $(wc -l < src/verify/CaptureBaselineHtmlService.php) -ge 68 ] && [ $(wc -l < src/verify/CaptureBaselineHtmlService.php) -le 78 ]`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

## Verification

- Static load: `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\verify\CaptureBaselineHtmlService();'` runs without errors.
- Functional smoke (deferred to Plan 04-12 unit test): given a temp `urls.txt` with one entry plus a `#` comment plus a blank line, the comment + blank are skipped and exactly 1 `<slug>.html` file is written.
- DI wiring deferred to Plan 04-09.

## must_haves

- File `src/verify/CaptureBaselineHtmlService.php` exists with v2 namespace.
- `SpotCheckUrlFetcher` import points at the v2 location.
- `#`-comment + blank-line URL-list filter intact.
- `urlToSlug` regex intact.
- `composer test` stays green.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| Namespace `lameco\kunstmaanmigrator\craft\verify` | **reshaped** — flattened to `verify`. |
| `SpotCheckUrlFetcher` import path | **reshaped** — points at v2 `lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher`. |
| Public DI seam `?SpotCheckUrlFetcher $fetcher` with `?? new SpotCheckUrlFetcher()` fallback | **ported** — Phase 5 unit-test affordance. |
| `is_file` + `is_dir`/`mkdir` guard with RuntimeException throws | **ported** — error contract preserved. |
| `array_filter` with `'#'`-prefix + blank-line filter | **ported** — operator-curated URL-list shape (D-Discretion `spot-check-urls.txt`). |
| `Craft::warning(...)` on per-URL fetch failure (catch but continue) | **ported** — partial-success contract. |
| `urlToSlug` regex `/[^a-zA-Z0-9_-]+/` | **ported** — slug determinism. |
