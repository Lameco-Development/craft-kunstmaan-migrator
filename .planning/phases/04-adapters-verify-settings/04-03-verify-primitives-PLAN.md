---
plan: 03
phase: 04
title: "Verify primitives — SnapshotDiffer + SpotCheckUrlFetcher verbatim port"
wave: 2
depends_on: ["04-01"]
files_modified:
  - src/verify/SnapshotDiffer.php
  - src/verify/SpotCheckUrlFetcher.php
autonomous: true
requirements_addressed: [VER-02]
---

# Plan 04-03: Verify primitives — SnapshotDiffer + SpotCheckUrlFetcher verbatim port

## Objective

Port v1's two pure-function verify primitives verbatim into a new `src/verify/` directory:
- `SnapshotDiffer.php` (128 LOC): pure deep-diff helper that emits `[{path, baseline, current}]` triples.
- `SpotCheckUrlFetcher.php` (234 LOC): HTTP fetch + DOM normalize + line-level diff. Preserves the **B1 fix** (real diff, not byte-count proxy) verbatim — that fix replaced v1's earlier false-pass byte-count comparison.

These are consumed by Plan 04-08 (`CaptureBaselineHtmlService`) and Plan 04-09 (`VerifyController`). SnapshotDiffer is ported but stays unused at v1.0 (Gate 1 ships count-match, not deep-diff) — RECONCILIATION below documents the "ported in advance, not wired yet" disposition.

## Context

- D-54: verbatim port + RECONCILIATION.
- D-58: Gate 2 backbone is `SpotCheckUrlFetcher::diff()` — the B1 fix is the load-bearing reason this port exists.
- Namespace reshape: `craft\verify` → `verify` (v2 dropped the three-tier layout).
- DI wiring lives in Plan 04-09.

## Tasks

<task id="01">
  <action>
Create directory `src/verify/` if it does not exist (mkdir is implicit via the Write tool).

Create `src/verify/SnapshotDiffer.php`. Copy `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SnapshotDiffer.php` byte-for-byte. Reshape ONLY:
- `namespace lameco\kunstmaanmigrator\craft\verify;` → `namespace lameco\kunstmaanmigrator\verify;`

Preserve: the `META_IGNORE` const (`['generatedAt', 'gitSha']`), `compareAssoc`, `compareList`, `compareValue`, the `diff()` public method signature with the docblock `@return array<int, array{path: string, baseline: mixed, current: mixed}>`. Zero refactor — the body is pure-function.
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/verify/SnapshotDiffer.php (entire file — verbatim source)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (SnapshotDiffer section)
  </read_first>
  <acceptance_criteria>
    - `test -f src/verify/SnapshotDiffer.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\verify;' src/verify/SnapshotDiffer.php` returns `1`
    - `grep -c 'class SnapshotDiffer extends Component' src/verify/SnapshotDiffer.php` returns `1`
    - `grep -c "META_IGNORE" src/verify/SnapshotDiffer.php` returns at least `1`
    - `grep -E "'generatedAt'.*'gitSha'|'gitSha'.*'generatedAt'" src/verify/SnapshotDiffer.php` returns at least `1`
    - `grep -c 'public function diff(' src/verify/SnapshotDiffer.php` returns `1`
    - `grep -c 'private function compareAssoc(' src/verify/SnapshotDiffer.php` returns `1`
    - `grep -c 'private function compareList(' src/verify/SnapshotDiffer.php` returns `1`
    - `grep -c 'craft.verify' src/verify/SnapshotDiffer.php` returns `0` (no leftover namespace references)
    - `php -l src/verify/SnapshotDiffer.php` outputs `No syntax errors detected`
    - Line count within ±5 of v1's 128 LOC: `[ $(wc -l < src/verify/SnapshotDiffer.php) -ge 123 ] && [ $(wc -l < src/verify/SnapshotDiffer.php) -le 133 ]`
  </acceptance_criteria>
</task>

<task id="02">
  <action>
Create `src/verify/SpotCheckUrlFetcher.php`. Copy `~/Sites/craft-kunstmaan-migrator/src/craft/verify/SpotCheckUrlFetcher.php` byte-for-byte. Reshape ONLY:
- `namespace lameco\kunstmaanmigrator\craft\verify;` → `namespace lameco\kunstmaanmigrator\verify;`

Preserve verbatim:
- `STRIP_PATTERNS` constant (the volatile-markup regex list at v1 lines 34-46) — load-bearing for stable diffs.
- The `diff()` method (v1 lines 78-111) — this IS the B1 fix; do NOT modernize, refactor, or "improve" the line-level diff loop. Port byte-for-byte.
- `fetchAndNormalize` + `normalize` + the dual-path Guzzle/streams fetch fallback (v1 lines 139-179).
- `diffAgainstBaseline` stub (v1 lines 125-133) — keep as a stub returning `[]`. RECONCILIATION below documents this stub's disposition.

Class extends `yii\base\Component`. Imports: ensure any `Craft::createGuzzleClient()` references resolve to the standard Craft 5 client — v1 already uses this seam.
  </action>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/craft/verify/SpotCheckUrlFetcher.php (entire file — verbatim source, especially diff() B1 fix)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (SpotCheckUrlFetcher section, B1 fix call-out)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-58 — B1 fix preservation requirement)
  </read_first>
  <acceptance_criteria>
    - `test -f src/verify/SpotCheckUrlFetcher.php` returns true
    - `grep -c '^namespace lameco\\\\kunstmaanmigrator\\\\verify;' src/verify/SpotCheckUrlFetcher.php` returns `1`
    - `grep -c 'class SpotCheckUrlFetcher extends Component' src/verify/SpotCheckUrlFetcher.php` returns `1`
    - `grep -c 'STRIP_PATTERNS' src/verify/SpotCheckUrlFetcher.php` returns at least `1`
    - `grep -c 'CRAFT_CSRF_TOKEN' src/verify/SpotCheckUrlFetcher.php` returns `1` (volatile-markup strip preserved)
    - `grep -c 'public function diff(' src/verify/SpotCheckUrlFetcher.php` returns `1`
    - `grep -c 'public function fetchAndNormalize(' src/verify/SpotCheckUrlFetcher.php` returns `1`
    - `grep -E "createGuzzleClient" src/verify/SpotCheckUrlFetcher.php` returns at least `1` (Craft Guzzle seam)
    - `grep -c 'array_flip(\$liveLines)' src/verify/SpotCheckUrlFetcher.php` returns at least `1` (B1 fix line-level diff body present)
    - `grep -c 'public function diffAgainstBaseline(' src/verify/SpotCheckUrlFetcher.php` returns `1` (stub preserved)
    - `grep -c 'craft.verify' src/verify/SpotCheckUrlFetcher.php` returns `0` (no leftover namespace references)
    - `php -l src/verify/SpotCheckUrlFetcher.php` outputs `No syntax errors detected`
    - Line count within ±10 of v1's 234 LOC: `[ $(wc -l < src/verify/SpotCheckUrlFetcher.php) -ge 224 ] && [ $(wc -l < src/verify/SpotCheckUrlFetcher.php) -le 244 ]`
    - `composer test` exits `0` (after both task 01 and 02)
  </acceptance_criteria>
</task>

## Verification

- Both files load via Composer's PSR-4 autoloader: `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\verify\SnapshotDiffer(); new \lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher();'` runs without errors.
- B1 fix preserved: `SpotCheckUrlFetcher::diff('<html>foo</html>', '<html>bar</html>')` returns a non-empty string with `+ ` and `- ` prefixes (real line-level diff, not a byte count).
- DI wiring deferred to Plan 04-09.

## must_haves

- `src/verify/SnapshotDiffer.php` exists and is a verbatim port of v1's body (META_IGNORE, compareAssoc/List/Value, diff()).
- `src/verify/SpotCheckUrlFetcher.php` exists and preserves the B1-fix line-level diff body byte-for-byte.
- The volatile-markup `STRIP_PATTERNS` are intact (CSRF token + Blitz comment + Vite client + data-iso-timestamp + cache-buster query string).
- `composer test` stays green.

## RECONCILIATION

| v1 rule | v2 disposition |
|---|---|
| `SnapshotDiffer` namespace `lameco\kunstmaanmigrator\craft\verify` | **reshaped** — flattened to `lameco\kunstmaanmigrator\verify`. |
| `SnapshotDiffer` body (compareAssoc/List/Value, META_IGNORE) | **ported** — pure-function, no maintenance cost. |
| `SnapshotDiffer` runtime use at v1.0 | **dropped intentionally** — Gate 1 (count-match) does not need deep-diff. Reintroduce when `verify capture-baseline --deep` lands (deferred). The class is ported as infrastructure-in-advance, not wired into VerifyController. PATTERNS.md call-out preserved. |
| `SpotCheckUrlFetcher` namespace `lameco\kunstmaanmigrator\craft\verify` | **reshaped** — flattened to `lameco\kunstmaanmigrator\verify`. |
| `SpotCheckUrlFetcher::diff()` line-level B1 fix (lines 78-111) | **ported** — byte-for-byte. Replaced v1's earlier byte-count proxy that produced false-pass results. Do not "improve". |
| `STRIP_PATTERNS` volatile-markup regexes | **ported** — load-bearing for stable cross-run diffs (CSRF, Blitz, Vite, data-iso-ts, cache-buster). |
| Dual-path Guzzle/streams fetch fallback | **ported** — required for sandboxed test environments without Guzzle. |
| `diffAgainstBaseline` stub returning `[]` | **ported as stub** — VerifyController::actionIndex implements the actual baseline-diff loop (v1 pattern, preserved in Plan 04-09). |
