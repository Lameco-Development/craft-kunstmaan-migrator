---
phase: 04-adapters-verify-settings
plan: 03
subsystem: verify-primitives
tags: [verify, port, snapshot-differ, spot-check, B1-fix, verbatim]
status: complete
requires:
  - phase: 04
    plan: "01"
    artifact: "src/models/Settings.php — verifyCountTolerance + verifyUrlDiffThreshold typed properties (foundation for verify gate; not directly read here, consumed downstream by Plan 04-04 / 04-09)"
provides:
  - artifact: "src/verify/SnapshotDiffer.php"
    summary: "128 LOC verbatim port of v1 craft/verify/SnapshotDiffer.php. Pure-function deep-diff helper that emits [{path, baseline, current}] triples comparing two baseline snapshot arrays. META_IGNORE = ['generatedAt', 'gitSha']. Ported in advance per D-54 RECONCILIATION; not wired at v1.0 (Gate 1 ships count-match, not deep-diff). Future verify capture-baseline --deep will wire it."
  - artifact: "src/verify/SpotCheckUrlFetcher.php"
    summary: "234 LOC verbatim port of v1 craft/verify/SpotCheckUrlFetcher.php. HTTP fetch + DOM normalize + line-level diff service. Preserves the B1 fix byte-for-byte: real array_flip set-comparison line-level diff (replaces v1's earlier byte-count proxy that produced false-pass results). STRIP_PATTERNS preserved verbatim — CSRF input/meta, Blitz comments, Vite HMR client, ISO data-attrs, cache-buster query strings. Dual-path Guzzle → streams fetch fallback ported. diffAgainstBaseline kept as Plan 09 stub returning []."
affects:
  - "Plan 04-08 (CaptureBaselineHtmlService) — consumer; injects SpotCheckUrlFetcher as the fetcher dependency."
  - "Plan 04-09 (VerifyController + Plugin::config wiring) — registers both components in DI; VerifyController::actionIndex consumes SpotCheckUrlFetcher::diff() (the B1-fix call site that motivated this port)."
  - "Future verify capture-baseline --deep — will consume SnapshotDiffer (currently ported but unused at v1.0 per RECONCILIATION)."
tech-stack:
  added: []
  patterns:
    - "Verbatim port discipline (D-54) — body byte-for-byte from v1; only namespace reshape (craft\\verify → verify) per the v2 flat layout (PROJECT.md three-tier-layout drop)."
    - "Yii Component DI (extends yii\\base\\Component) preserved for v2 Plugin::config() compatibility."
    - "Dual-path HTTP fetch — Craft::createGuzzleClient() primary, stream_context_create + file_get_contents fallback for sandboxed test environments without Guzzle."
    - "DOM-level comment removal via DOMDocument + DOMXPath('//comment()'), then regex strip list, then whitespace collapse (`/[ \\t]+/` → single space, `/\\n\\s*\\n+/` → double newline)."
    - "Line-level diff via array_flip set comparison (B1 fix) — preserves v1's anti-false-pass guarantee."
key-files:
  created:
    - "src/verify/SnapshotDiffer.php (128 LOC) — verbatim port from v1 craft/verify/SnapshotDiffer.php with namespace flatten only."
    - "src/verify/SpotCheckUrlFetcher.php (234 LOC) — verbatim port from v1 craft/verify/SpotCheckUrlFetcher.php with namespace flatten only. B1 fix preserved byte-for-byte."
  modified: []
decisions:
  - "Verbatim-port discipline (D-54): both files are byte-for-byte copies of v1 craft/verify/* with the single allowed reshape — `namespace lameco\\kunstmaanmigrator\\craft\\verify;` → `namespace lameco\\kunstmaanmigrator\\verify;`. Every docblock, every comment, every constant, every method body is identical to v1."
  - "B1 fix preserved (D-58): SpotCheckUrlFetcher::diff() lines 78-111 are load-bearing for Gate 2 backbone. The earlier v1 implementation used a byte-count proxy comparison that produced false-pass results; the current array_flip set-comparison line-level diff is the fix. Do NOT modernize, refactor, or 'improve' this body — port byte-for-byte. Verified post-port: `diff('<html>foo</html>', '<html>bar</html>')` returns 123 chars of unified-style `+ ` / `- ` line markers, not a byte count."
  - "STRIP_PATTERNS load-bearing for stable cross-run diffs: CSRF input/meta tags, Blitz HTML comments, Vite HMR client script, ISO-8601 data-attrs, `?v=` / `?ts=` cache-buster query strings. All six regex entries preserved verbatim from v1 lines 34-46. Dropping any one of these breaks the spot-check stability invariant."
  - "Dual-path Guzzle/streams fetch fallback ported: required for sandboxed/test environments where Guzzle composer install isn't guaranteed. Try Craft::createGuzzleClient first (production path), fall back to stream_context_create + file_get_contents on any Throwable. Same User-Agent header on both paths so server-side logs identify spot-check traffic uniformly."
  - "diffAgainstBaseline kept as a stub returning []: VerifyController::actionIndex (Plan 04-09) implements the real baseline-diff loop following v1's pattern — read spot-check-urls.txt, fetch each URL, normalize, diff against captured baseline HTML files. The stub's signature (string $urlListPath, string $baselineTimestamp) is the stable Plan 04-09 wiring contract. Parameters are explicitly `unset()` so static analyzers don't flag them as unused."
  - "SnapshotDiffer ported in advance, not wired at v1.0 (D-54 RECONCILIATION): Gate 1 ships count-match (CountGateService scalar comparison, Plan 04-04), not deep-diff. SnapshotDiffer is infrastructure-in-advance — 128 LOC pure-function with zero maintenance cost. Reintroduce when `verify capture-baseline --deep` lands (deferred milestone)."
  - "DI registration deferred to Plan 04-09 (per plan contract). This plan ships both files only; Plugin::config() untouched."
metrics:
  completed: "2026-04-26"
  tasks-completed: "2/2"
  total-loc-added: "362 (128 SnapshotDiffer + 234 SpotCheckUrlFetcher; both exact match with v1 line counts)"
  test-suite: "60 tests / 137 assertions (unchanged from baseline; no test additions per plan — Plan 04-12 owns Phase 4 test corpus)"
---

# Phase 4 Plan 03: Verify primitives — SnapshotDiffer + SpotCheckUrlFetcher Summary

**Verbatim port of v1's two pure-function verify primitives into `src/verify/` under the v2 flat namespace `lameco\kunstmaanmigrator\verify`. `SnapshotDiffer.php` (128 LOC, deep-diff helper) and `SpotCheckUrlFetcher.php` (234 LOC, HTTP fetch + DOM normalize + line-level diff with the load-bearing B1 fix). Only reshape: `craft\verify` → `verify`. SnapshotDiffer is ported in advance and stays unused at v1.0 (Gate 1 ships count-match, not deep-diff); SpotCheckUrlFetcher is consumed by Plan 04-08 and Plan 04-09 (the B1-fix call site is `VerifyController::actionIndex`).**

## Status

**COMPLETE.** Two tasks executed and committed; both files PSR-4-loadable; B1 fix verified end-to-end (`diff('<html>foo</html>', '<html>bar</html>')` returns a non-empty unified-style line diff with `+ ` / `- ` prefixes — not a byte count); composer test green (60 tests / 137 assertions — unchanged baseline). DI wiring deferred to Plan 04-09 per plan contract.

## Performance

- **Duration:** ~12 min
- **Started:** 2026-04-26T20:30:00Z (approx)
- **Completed:** 2026-04-26T20:42:00Z (approx)
- **Tasks:** 2/2
- **Files created:** 2 (`src/verify/SnapshotDiffer.php`, `src/verify/SpotCheckUrlFetcher.php`)
- **Files modified:** 0

## Tasks Completed

| Task | Name                                          | Commit  | Files                                    |
| ---- | --------------------------------------------- | ------- | ---------------------------------------- |
| 1    | Verbatim port SnapshotDiffer                  | 5430f19 | `src/verify/SnapshotDiffer.php`          |
| 2    | Verbatim port SpotCheckUrlFetcher (B1 fix)    | aaa8e3f | `src/verify/SpotCheckUrlFetcher.php`     |

## What Landed

### `src/verify/SnapshotDiffer.php` (128 LOC, NEW)

Pure-function deep-diff service. Class declaration:

```php
namespace lameco\kunstmaanmigrator\verify;

use yii\base\Component;

class SnapshotDiffer extends Component
{
    private const META_IGNORE = ['generatedAt', 'gitSha'];

    public function diff(array $baseline, array $current): array
    { ... }
}
```

Preserved verbatim from v1:
- `META_IGNORE = ['generatedAt', 'gitSha']` constant — the volatile run-to-run keys ignored during diff.
- Public `diff(array $baseline, array $current): array` returning `array<int, array{path: string, baseline: mixed, current: mixed}>`.
- Private helpers `compareAssoc`, `compareList`, `compareValue` — list-vs-assoc dispatch via `array_is_list()`, baseline's order is authoritative for list comparison.
- Asymmetric-list handling (`baseHas && !curHas` → emit with `current: null`; `!baseHas && curHas` → emit with `baseline: null`) — preserves v1's "missing-side null" invariant.

Reshape applied (only):
- `namespace lameco\kunstmaanmigrator\craft\verify;` → `namespace lameco\kunstmaanmigrator\verify;`

### `src/verify/SpotCheckUrlFetcher.php` (234 LOC, NEW)

HTTP fetch + DOM normalize + line-level diff service. Class declaration:

```php
namespace lameco\kunstmaanmigrator\verify;

use Craft;
use DOMComment;
use DOMDocument;
use DOMXPath;
use Throwable;
use yii\base\Component;

class SpotCheckUrlFetcher extends Component
{
    private const USER_AGENT = 'kunstmaan-migrator-spotcheck/1.0';
    private const STRIP_PATTERNS = [ /* 6 regex entries */ ];

    public function fetchAndNormalize(string $url): string { ... }
    public function diff(string $urlOrHtml, string $otherHtml): string { /* B1 fix */ }
    public function diffAgainstBaseline(string $urlListPath, string $baselineTimestamp): array { return []; }
}
```

Preserved verbatim from v1:
- `USER_AGENT = 'kunstmaan-migrator-spotcheck/1.0'` so server-side logs can identify spot-check traffic uniformly.
- `STRIP_PATTERNS` (6 entries, lines 34-46): CSRF input + meta tags, Blitz HTML comments, Vite HMR client script, ISO-8601 timestamp data-attrs, `?v=` / `?ts=` cache-buster query strings.
- `diff(string $urlOrHtml, string $otherHtml): string` — **the B1 fix, lines 78-111**: real line-level diff via `array_flip(...)` set comparison instead of a byte-count proxy. Returns empty string when normalized bodies match; otherwise unified-ish `+ line` / `- line` prefixed text.
- `fetchAndNormalize` + `normalize` + dual-path Guzzle/streams fetch fallback (lines 139-179) — required for sandboxed test envs without Guzzle.
- `diffAgainstBaseline` stub returning `[]` (lines 125-133) — Plan 09 wiring contract; parameters explicitly `unset()`.
- DOM-level normalize: load through `DOMDocument` with `<?xml encoding="UTF-8">` prefix + `LIBXML_NOWARNING | LIBXML_NOERROR`, walk `//comment()` xpath and remove all `DOMComment` nodes, then re-serialize.
- Whitespace normalization: collapse runs of spaces/tabs to single space; collapse runs of blank lines to double newline; final `trim()`.

Reshape applied (only):
- `namespace lameco\kunstmaanmigrator\craft\verify;` → `namespace lameco\kunstmaanmigrator\verify;`

## Verification

End-to-end checks performed post-port:

- **PSR-4 autoload:** `php -r 'require "vendor/autoload.php"; new \lameco\kunstmaanmigrator\verify\SnapshotDiffer(); new \lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher();'` exits 0 with `OK` printed.
- **B1 fix preserved:** `php -r '$f = new \lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher(); echo $f->diff("<html>foo</html>", "<html>bar</html>");'` returns 123 characters of unified-style line markers:
  ```
  - <?xml encoding="UTF-8"><html><body><p>bar</p></body></html>
  + <?xml encoding="UTF-8"><html><body><p>foo</p></body></html>
  ```
  (i.e. real line-level diff with `+ ` and `- ` prefixes, NOT a byte count).
- **PHP lint:** `php -l` clean on both files.
- **LOC parity with v1:** SnapshotDiffer 128 LOC (exact), SpotCheckUrlFetcher 234 LOC (exact). No body drift.
- **No leftover three-tier namespace refs:** `grep -c 'craft.verify'` returns 0 on both files.
- **composer test:** 60 tests / 137 assertions — unchanged baseline. No new tests in this plan; Plan 04-12 owns the Phase 4 test corpus.

## Deviations from Plan

None. Plan executed exactly as written. The acceptance-criterion grep patterns for `^namespace lameco\\kunstmaanmigrator\\verify;` are markdown-escaped in the PLAN.md (`\\\\` literals); the actual shell pattern is `\\` (single literal backslash before `kunstmaanmigrator` and `verify`), which I confirmed against the file output. No semantic deviation — the namespace literal in the PHP file matches both spellings unambiguously.

## RECONCILIATION

Carried forward from PLAN.md verbatim — see plan for the full table:

| v1 rule                                                                | v2 disposition                                                                                                                                                                                                |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SnapshotDiffer` namespace `lameco\kunstmaanmigrator\craft\verify`     | **reshaped** — flattened to `lameco\kunstmaanmigrator\verify`.                                                                                                                                                |
| `SnapshotDiffer` body (compareAssoc/List/Value, META_IGNORE)           | **ported** — pure-function, no maintenance cost.                                                                                                                                                              |
| `SnapshotDiffer` runtime use at v1.0                                   | **dropped intentionally** — Gate 1 (count-match) does not need deep-diff. Reintroduce when `verify capture-baseline --deep` lands (deferred). Class is ported as infrastructure-in-advance, not wired in DI. |
| `SpotCheckUrlFetcher` namespace `lameco\kunstmaanmigrator\craft\verify`| **reshaped** — flattened to `lameco\kunstmaanmigrator\verify`.                                                                                                                                                |
| `SpotCheckUrlFetcher::diff()` line-level B1 fix (lines 78-111)         | **ported** — byte-for-byte. Replaced v1's earlier byte-count proxy that produced false-pass results. Do not "improve".                                                                                        |
| `STRIP_PATTERNS` volatile-markup regexes                               | **ported** — load-bearing for stable cross-run diffs (CSRF, Blitz, Vite, data-iso-ts, cache-buster).                                                                                                          |
| Dual-path Guzzle/streams fetch fallback                                | **ported** — required for sandboxed test environments without Guzzle.                                                                                                                                         |
| `diffAgainstBaseline` stub returning `[]`                              | **ported as stub** — VerifyController::actionIndex implements the actual baseline-diff loop (v1 pattern, preserved in Plan 04-09).                                                                            |

## What's Next

Phase 4 Wave 1 advances to **Plan 04-04** (CountGateService verbatim port + BaselineCounterService shape-derived per D-59 — VER-01, VER-03), then **Plan 04-05** (CP `_settings.twig` template — CFG-01 completion). Wave 2 picks up Plans 04-06 / 04-07 (SeoMigrationService + RedirectMigrationService verbatim ports — ADP-01, ADP-02), then Plan 04-08 (CaptureBaselineHtmlService) which is the first downstream consumer of `SpotCheckUrlFetcher`. Plan 04-09 wires both verify primitives into `Plugin::config()` and the new `VerifyController` (the B1-fix call site).

## Self-Check: PASSED

- `src/verify/SnapshotDiffer.php` — FOUND (128 LOC)
- `src/verify/SpotCheckUrlFetcher.php` — FOUND (234 LOC)
- Commit `5430f19` — FOUND in `git log`
- Commit `aaa8e3f` — FOUND in `git log`
- B1 fix line-level diff verified end-to-end (non-empty unified-style output)
- composer test green (60 / 137)
