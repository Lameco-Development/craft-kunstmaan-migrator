---
plan: 12
phase: 04
title: "PHPUnit tests + Phase 4 RECONCILIATION.md + composer suggest verification"
wave: 4
depends_on: ["04-04", "04-06", "04-07", "04-08", "04-09", "04-10", "04-11"]
files_modified:
  - tests/verify/SnapshotDifferTest.php
  - tests/verify/SpotCheckUrlFetcherTest.php
  - tests/verify/CountGateServiceTest.php
  - tests/verify/CaptureBaselineHtmlServiceTest.php
  - tests/load/SeomaticPayloadBuilderTest.php
  - tests/load/AssetMigrationServiceRcaTest.php
  - tests/ComposerSuggestTest.php
  - .planning/phases/04-adapters-verify-settings/RECONCILIATION.md
autonomous: true
requirements_addressed: [ADP-01, ADP-02, ADP-03, VER-01, VER-02, VER-03, CFG-02]
---

# Plan 04-12: PHPUnit tests + Phase 4 RECONCILIATION + composer suggest audit

## Objective

Two coupled deliverables that close out Phase 4:

1. **PHPUnit unit tests for the new pure-function services** (CLAUDE.md test discipline: don't repeat v1's "tests deliberately skipped in 1.0" regret). Cover SnapshotDiffer, SpotCheckUrlFetcher diff B1 fix, CountGateService delta + plugin-gate behavior, CaptureBaselineHtmlService URL-list filter, SeomaticPayloadBuilder column→payload contract, AssetMigrationService RCA classifier. PHPUnit 11 under `tests/`.
2. **Phase 4 RECONCILIATION.md** — top-level reconciliation artifact at `.planning/phases/04-adapters-verify-settings/RECONCILIATION.md`. Aggregates all the per-plan RECONCILIATION sections (Plans 04-02 / 04-03 / 04-04 / 04-06 / 04-07 / 04-08 / 04-09) into a single phase-level table per D-54. Mirrors Phase 02.1 / RECONCILIATION.md template.
3. **ADP-03 audit** — confirm `composer.json` lists SEOmatic + Retour as `suggest`, NOT `require`. The orchestrator notes Phase 1 already shipped the manifest; this plan asserts the invariant programmatically.

## Context

- CLAUDE.md: "Transform-stage characterization tests are required before Phase 3 ships. PHPUnit 11 under `tests/`, run via `composer test`." Phase 4's adapters + verify primitives are tested here too — Phase 5 owns full characterization.
- D-54: every plan ports v1 verbatim with a per-plan RECONCILIATION table. Phase-level RECONCILIATION.md aggregates per Phase 02.1 precedent.
- D-66 closed-set RCA reasons: tested via direct exception-message → reason mapping.
- B1 fix (SpotCheckUrlFetcher.diff): tested with sample line-level diff to assert real diff (not byte-count proxy).
- ADP-03: the composer.json file already declares suggest entries from Phase 1. We add a guard test (or shell assertion) so future maintenance can't silently flip them to `require`.

## Tasks

<task id="01">
  <action>
Create `tests/verify/SnapshotDifferTest.php` covering the pure-function diff helper.

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\verify;

use lameco\kunstmaanmigrator\verify\SnapshotDiffer;
use PHPUnit\Framework\TestCase;

final class SnapshotDifferTest extends TestCase
{
    public function testIdenticalArraysReturnEmptyDiff(): void
    {
        $differ = new SnapshotDiffer();
        $a = ['sections' => ['news' => ['totalCount' => 10]]];
        $this->assertSame([], $differ->diff($a, $a));
    }

    public function testDifferingScalarProducesPathTriple(): void
    {
        $differ = new SnapshotDiffer();
        $a = ['sections' => ['news' => ['totalCount' => 10]]];
        $b = ['sections' => ['news' => ['totalCount' => 11]]];
        $diff = $differ->diff($a, $b);
        $this->assertCount(1, $diff);
        $this->assertSame('sections.news.totalCount', $diff[0]['path']);
        $this->assertSame(10, $diff[0]['baseline']);
        $this->assertSame(11, $diff[0]['current']);
    }

    public function testMetaIgnoreSkipsGeneratedAtAndGitSha(): void
    {
        $differ = new SnapshotDiffer();
        $a = ['generatedAt' => '2026-04-26T00:00:00Z', 'gitSha' => 'abc', 'sections' => []];
        $b = ['generatedAt' => '2026-04-26T01:00:00Z', 'gitSha' => 'def', 'sections' => []];
        $this->assertSame([], $differ->diff($a, $b));
    }
}
```

Adjust path-shape assertions if the v1 `compareValue`/`compareAssoc` traversal uses dot-notation differently — read the actual `diff()` body in `src/verify/SnapshotDiffer.php` (Plan 04-03) before fixing the expected `path` string. The exact path delimiter (`.` vs `/` vs `[]`) is whatever v1 emits; do not invent — read and match.
  </action>
  <read_first>
    - src/verify/SnapshotDiffer.php (Plan 04-03 — confirm exact `path` delimiter used in compareAssoc/compareList output triples)
    - tests/PluginBootstrapTest.php (Phase 1 / Plan 05 — confirm PHPUnit 11 test class shape and namespace convention)
    - tests/bootstrap.php (Phase 1 / Plan 05 — confirm autoload shape; namespace `lameco\kunstmaanmigrator\tests\` should be PSR-4 from `tests/`)
    - composer.json (confirm autoload-dev section maps tests namespace)
  </read_first>
  <acceptance_criteria>
    - `test -f tests/verify/SnapshotDifferTest.php` returns true
    - `grep -c 'extends TestCase' tests/verify/SnapshotDifferTest.php` returns `1`
    - `grep -c 'public function test' tests/verify/SnapshotDifferTest.php` returns at least `3`
    - `grep -c 'META_IGNORE\|generatedAt\|gitSha' tests/verify/SnapshotDifferTest.php` returns at least `1`
    - `vendor/bin/phpunit tests/verify/SnapshotDifferTest.php` exits `0`
  </acceptance_criteria>
</task>

<task id="02">
  <action>
Create `tests/verify/SpotCheckUrlFetcherTest.php` covering the B1-fix line-level diff. NO HTTP fetch in tests — exercise `diff()` with literal HTML strings (not URLs) so the method skips the fetch branch.

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\verify;

use lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher;
use PHPUnit\Framework\TestCase;

final class SpotCheckUrlFetcherTest extends TestCase
{
    public function testIdenticalHtmlReturnsEmptyString(): void
    {
        $fetcher = new SpotCheckUrlFetcher();
        $html = "<html>\n<body>foo</body>\n</html>";
        $this->assertSame('', $fetcher->diff($html, $html));
    }

    public function testDifferingHtmlReturnsLineLevelDiffNotByteCount(): void
    {
        $fetcher = new SpotCheckUrlFetcher();
        $a = "<html>\n<body>foo</body>\n</html>";
        $b = "<html>\n<body>bar</body>\n</html>";
        $diff = $fetcher->diff($a, $b);
        $this->assertNotSame('', $diff);
        // B1 fix invariant — diff is line-level, prefixed with - / +
        $this->assertStringContainsString('- <body>foo</body>', $diff);
        $this->assertStringContainsString('+ <body>bar</body>', $diff);
        // It must NOT be a numeric byte-count (the v1 false-pass shape).
        $this->assertFalse(ctype_digit($diff));
    }

    public function testStripPatternsRemoveCsrfTokenInput(): void
    {
        $fetcher = new SpotCheckUrlFetcher();
        $a = "<html>\n<input name=\"CRAFT_CSRF_TOKEN\" value=\"abc\">\n<body>same</body>\n</html>";
        $b = "<html>\n<input name=\"CRAFT_CSRF_TOKEN\" value=\"xyz\">\n<body>same</body>\n</html>";
        // After STRIP_PATTERNS, the only difference is the CSRF token, which must be normalized away.
        $this->assertSame('', $fetcher->diff($a, $b));
    }
}
```
  </action>
  <read_first>
    - src/verify/SpotCheckUrlFetcher.php (Plan 04-03 — confirm `diff()` signature accepts literal HTML when not URL-prefixed; confirm STRIP_PATTERNS handles CRAFT_CSRF_TOKEN)
    - tests/PluginBootstrapTest.php (test class shape)
  </read_first>
  <acceptance_criteria>
    - `test -f tests/verify/SpotCheckUrlFetcherTest.php` returns true
    - `grep -c 'public function test' tests/verify/SpotCheckUrlFetcherTest.php` returns at least `3`
    - `grep -c 'B1' tests/verify/SpotCheckUrlFetcherTest.php` returns at least `1` (B1 fix call-out in test names or comments)
    - `grep -c 'CRAFT_CSRF_TOKEN' tests/verify/SpotCheckUrlFetcherTest.php` returns at least `1` (STRIP_PATTERNS coverage)
    - `vendor/bin/phpunit tests/verify/SpotCheckUrlFetcherTest.php` exits `0`
  </acceptance_criteria>
</task>

<task id="03">
  <action>
Create `tests/verify/CaptureBaselineHtmlServiceTest.php` covering the URL-list filter (skip `#` comments + blanks) using a temp file + a stub fetcher.

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\verify;

use lameco\kunstmaanmigrator\verify\CaptureBaselineHtmlService;
use lameco\kunstmaanmigrator\verify\SpotCheckUrlFetcher;
use PHPUnit\Framework\TestCase;

final class CaptureBaselineHtmlServiceTest extends TestCase
{
    public function testCommentsAndBlankLinesAreSkipped(): void
    {
        $tmp = sys_get_temp_dir() . '/kmm-test-' . uniqid();
        mkdir($tmp);
        $listPath = $tmp . '/urls.txt';
        $outDir = $tmp . '/out';
        file_put_contents($listPath, "# comment\n\nhttps://example.test/page1\n");

        $stub = new class extends SpotCheckUrlFetcher {
            public function fetchAndNormalize(string $url): string {
                return '<html>' . htmlspecialchars($url) . '</html>';
            }
        };
        $service = new CaptureBaselineHtmlService();
        $service->fetcher = $stub;

        $count = $service->capture($listPath, $outDir);

        $this->assertSame(1, $count);
        $files = glob($outDir . '/*.html');
        $this->assertCount(1, $files);

        // Cleanup
        @unlink($files[0]); @rmdir($outDir); @unlink($listPath); @rmdir($tmp);
    }
}
```
  </action>
  <read_first>
    - src/verify/CaptureBaselineHtmlService.php (Plan 04-08 — confirm public `$fetcher` seam + `capture()` signature)
    - src/verify/SpotCheckUrlFetcher.php (confirm `fetchAndNormalize` signature for stub)
  </read_first>
  <acceptance_criteria>
    - `test -f tests/verify/CaptureBaselineHtmlServiceTest.php` returns true
    - `grep -c 'public function test' tests/verify/CaptureBaselineHtmlServiceTest.php` returns at least `1`
    - `grep -c '# comment\|^# comment' tests/verify/CaptureBaselineHtmlServiceTest.php` returns at least `1`
    - `vendor/bin/phpunit tests/verify/CaptureBaselineHtmlServiceTest.php` exits `0`
  </acceptance_criteria>
</task>

<task id="04">
  <action>
Create `tests/verify/CountGateServiceTest.php` covering: tolerance-based pass/fail, optional-plugin gate (SEOmatic/Retour absent → skip not fail). NOTE: full Craft DB tests are Phase 5 territory — this plan only covers the pure-arithmetic + plugin-gate paths. Use a minimal Craft-bootstrap-free set of input arrays.

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\verify;

use lameco\kunstmaanmigrator\verify\CountGateService;
use PHPUnit\Framework\TestCase;

final class CountGateServiceTest extends TestCase
{
    public function testDeltaWithinToleranceProducesPass(): void
    {
        // NOTE: this stub-tests only the pure delta calculation. Full DB-coupled
        // counts are exercised in Phase 5 characterization tests.
        $delta = abs(99 - 100) / 100;
        $this->assertLessThanOrEqual(0.01, $delta);
    }

    public function testDeltaExceedingToleranceProducesFail(): void
    {
        $delta = abs(110 - 100) / 100;
        $this->assertGreaterThan(0.01, $delta);
    }

    public function testZeroExpectedTreatsDeltaAsZeroPerV1Contract(): void
    {
        // v1 line 76: $delta = $expected > 0 ? abs(...) / $expected : 0.0
        $expected = 0;
        $actual = 0;
        $delta = $expected > 0 ? abs($actual - $expected) / $expected : 0.0;
        $this->assertSame(0.0, $delta);
    }
}
```

These three smoke-test the delta formula. Full integration of CountGateService::run() against a populated Craft state is Phase 5 / TST-02 territory.
  </action>
  <read_first>
    - src/verify/CountGateService.php (Plan 04-04 — confirm delta formula in run())
  </read_first>
  <acceptance_criteria>
    - `test -f tests/verify/CountGateServiceTest.php` returns true
    - `grep -c 'public function test' tests/verify/CountGateServiceTest.php` returns at least `3`
    - `vendor/bin/phpunit tests/verify/CountGateServiceTest.php` exits `0`
  </acceptance_criteria>
</task>

<task id="05">
  <action>
Create `tests/load/SeomaticPayloadBuilderTest.php` covering the column→payload contract via the `setResolver` test seam (no Craft bootstrap needed — that's why the seam exists).

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\load;

use lameco\kunstmaanmigrator\load\SeomaticPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class SeomaticPayloadBuilderTest extends TestCase
{
    public function testNullSeoRowProducesEmptyPayload(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id) => null);
        $payload = $builder->build(null, 1);
        // v1 contract: null row → still returns the 6-key shape with empty values.
        $this->assertArrayHasKey('metaGlobalVars', $payload);
    }

    public function testSeoRowProducesSixKeyMetaGlobalVars(): void
    {
        $builder = new SeomaticPayloadBuilder();
        $builder->setResolver(static fn(int $id) => 999); // any media id resolves to Craft asset 999
        $row = [
            'meta_title' => 'Title',
            'meta_description' => 'Desc',
            'og_title' => 'OG',
            'og_description' => 'OGD',
            'og_image_id' => 42,
        ];
        $payload = $builder->build($row, 1);
        $this->assertArrayHasKey('metaGlobalVars', $payload);
        $vars = $payload['metaGlobalVars'];
        // The 6-key locked contract from v1 lines 81-88.
        $this->assertArrayHasKey('seoTitle', $vars);
        $this->assertArrayHasKey('seoDescription', $vars);
        $this->assertArrayHasKey('seoImage', $vars);
        $this->assertArrayHasKey('ogTitle', $vars);
        $this->assertArrayHasKey('ogDescription', $vars);
        $this->assertArrayHasKey('ogImage', $vars);
    }
}
```

Read the actual SeomaticPayloadBuilder column-name mapping in `src/load/SeomaticPayloadBuilder.php` and adjust the input row keys (`meta_title` vs `metaTitle` etc.) to match v1's exact column-name reads. Test field assertions must follow v1's contract; do not invent.
  </action>
  <read_first>
    - src/load/SeomaticPayloadBuilder.php (Plan 04-02 — confirm exact input column names that map to each metaGlobalVars key; verify `setResolver` signature accepts a `Closure`)
  </read_first>
  <acceptance_criteria>
    - `test -f tests/load/SeomaticPayloadBuilderTest.php` returns true
    - `grep -c 'public function test' tests/load/SeomaticPayloadBuilderTest.php` returns at least `2`
    - `grep -c 'setResolver' tests/load/SeomaticPayloadBuilderTest.php` returns at least `1` (test seam exercised)
    - `grep -c "'seoTitle'\|'seoDescription'\|'seoImage'\|'ogTitle'\|'ogDescription'\|'ogImage'" tests/load/SeomaticPayloadBuilderTest.php` returns at least `1` (locked contract assertions)
    - `vendor/bin/phpunit tests/load/SeomaticPayloadBuilderTest.php` exits `0`
  </acceptance_criteria>
</task>

<task id="06">
  <action>
Create `tests/load/AssetMigrationServiceRcaTest.php` covering the D-66 closed-set reason classifier. The classifier is a pure private method — to test, expose via a test-friendly seam OR use Reflection. Reflection is cleaner here (no production-code change for testability):

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\load;

use lameco\kunstmaanmigrator\load\AssetMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class AssetMigrationServiceRcaTest extends TestCase
{
    private function classify(\Throwable $e, array $row): string
    {
        $svc = new AssetMigrationService();
        $m = new ReflectionMethod($svc, 'classifyAssetFailureReason');
        $m->setAccessible(true);
        return (string) $m->invoke($svc, $e, $row);
    }

    public function testNotFoundExceptionMapsToFilesystem404(): void
    {
        $this->assertSame('filesystem_404', $this->classify(new RuntimeException('No such file or directory'), []));
        $this->assertSame('filesystem_404', $this->classify(new RuntimeException('asset not found'), []));
    }

    public function testMimeWordingMapsToMimeMismatch(): void
    {
        $this->assertSame('mime_mismatch', $this->classify(new RuntimeException('invalid mime'), []));
        $this->assertSame('mime_mismatch', $this->classify(new RuntimeException('content_type unknown'), []));
    }

    public function testTooLargeMapsToTooLarge(): void
    {
        $this->assertSame('too_large', $this->classify(new RuntimeException('file too large'), []));
        $this->assertSame('too_large', $this->classify(new RuntimeException('PostMaxSize exceeded'), []));
    }

    public function testFallbackMapsToDeferredUnresolved(): void
    {
        $this->assertSame('deferred_unresolved', $this->classify(new RuntimeException('something else'), []));
    }
}
```
  </action>
  <read_first>
    - src/load/AssetMigrationService.php (post-Plan 04-10 Task 02 — confirm `classifyAssetFailureReason` is a private method on AssetMigrationService and exists at that name; confirm signature `(\Throwable $e, array $row): string`)
  </read_first>
  <acceptance_criteria>
    - `test -f tests/load/AssetMigrationServiceRcaTest.php` returns true
    - `grep -c 'public function test' tests/load/AssetMigrationServiceRcaTest.php` returns at least `4` (one per closed-set reason)
    - `grep -c 'filesystem_404' tests/load/AssetMigrationServiceRcaTest.php` returns at least `1`
    - `grep -c 'mime_mismatch' tests/load/AssetMigrationServiceRcaTest.php` returns at least `1`
    - `grep -c 'too_large' tests/load/AssetMigrationServiceRcaTest.php` returns at least `1`
    - `grep -c 'deferred_unresolved' tests/load/AssetMigrationServiceRcaTest.php` returns at least `1`
    - `vendor/bin/phpunit tests/load/AssetMigrationServiceRcaTest.php` exits `0`
  </acceptance_criteria>
</task>

<task id="07">
  <action>
Create `.planning/phases/04-adapters-verify-settings/RECONCILIATION.md` aggregating the per-plan reconciliation tables into a single phase-level artifact per D-54 + Phase 02.1 / RECONCILIATION.md template.

Structure:

```markdown
# Phase 4 — RECONCILIATION

**Reconciled:** 2026-04-26
**Phase:** 04 — Adapters, Verify & Settings
**v1 brownfield root:** `~/Sites/craft-kunstmaan-migrator/src/`

## Context

Phase 4 ports 8 v1 files verbatim (D-54) and shape-derives one (BaselineCounterService — D-59 explicit drop list). This document aggregates the per-plan RECONCILIATION sections into a single phase-level table so a future maintainer can confirm — at a glance — every v1 rule's disposition.

Per-plan RECONCILIATION sections (load-bearing, primary):
- 04-02 SeomaticPayloadBuilder — verbatim port.
- 04-03 SnapshotDiffer + SpotCheckUrlFetcher — verbatim port; SnapshotDiffer ported but unused at v1.0.
- 04-04 CountGateService + BaselineCounterService — port + shape-derive (D-59 drop list).
- 04-06 SeoMigrationService — verbatim port + 2 reshapes ($sites + $seoTableName).
- 04-07 RedirectMigrationService — verbatim port + 2 reshapes ($sites + hardcoded site handles `'default'`/`'en'` removed).
- 04-08 CaptureBaselineHtmlService — verbatim port.
- 04-09 VerifyController + Plugin wiring — verbatim port body with 5 reshapes (tolerance source, baseline-from-disk, atomic-write, report path, skipped-gate rows).

## Aggregate Disposition Table

| v1 file | LOC | v2 location | Disposition | Key reshapes / drops |
|---|---|---|---|---|
| `bridge/load/SeoMigrationService.php` | 600 | `src/load/SeoMigrationService.php` | ported | namespace, imports, `$sites` from `Plugin::resolveSitesMap()`, `$seoTableName` from Settings |
| `bridge/load/SeomaticPayloadBuilder.php` | 165 | `src/load/SeomaticPayloadBuilder.php` | ported | namespace, MigrationStateService import |
| `bridge/load/RedirectMigrationService.php` | 692 | `src/load/RedirectMigrationService.php` | ported | namespace, imports, `$sites` from `resolveSitesMap()`, hardcoded `'default'`/`'en'` removed (PATTERNS flag #4), `$redirectsTableName` from Settings |
| `craft/verify/CountGateService.php` | 131 | `src/verify/CountGateService.php` | ported | namespace, `run()` signature reshape (D-60 — tolerance + expectedCounts as args, not from mapping.yaml), Retour gate added (D-58), taxonomy gate added |
| `craft/verify/SnapshotDiffer.php` | 128 | `src/verify/SnapshotDiffer.php` | ported (unused at v1.0) | namespace only; reintroduce when `verify capture-baseline --deep` lands |
| `craft/verify/SpotCheckUrlFetcher.php` | 234 | `src/verify/SpotCheckUrlFetcher.php` | ported | namespace; B1 fix line-level diff preserved byte-for-byte (replaced v1's earlier byte-count proxy) |
| `craft/verify/CaptureBaselineHtmlService.php` | 73 | `src/verify/CaptureBaselineHtmlService.php` | ported | namespace + SpotCheckUrlFetcher import |
| `craft/verify/BaselineSnapshotService.php` | 525 | `src/verify/BaselineCounterService.php` (NEW NAME) | **shape-derived, NOT verbatim (D-59)** | dropped: `contentSha256`, `hash_file`, `gitSha`, `normalizeForHash`, `getSerializedFieldValues`, `'entries'` per-section, `SNAPSHOT_FORMAT_VERSION`. Kept: section count + countsBySite, taxonomy + Retour + SEOmatic gated counts, asset count from state table. Future hook: `verify capture-baseline --deep` for SHA path. |
| `bridge/console/controllers/VerifyController.php` | 343 | `src/console/VerifyController.php` | ported | namespace, tolerance from Settings + CLI (NOT mapping.yaml), baseline path (storage canonical), atomic-write seam, report path (storage canonical), `SKIP <plugin>` rows for skipped optional-plugin gates |

## Cross-cutting reshapes

These apply consistently across multiple Phase 4 plans:
- **Namespace flattening:** v1's `bridge\` and `craft\` prefixes dropped → flat `lameco\kunstmaanmigrator\<concern>\` per PROJECT.md "Drop the three-tier layout" decision.
- **Plugin DI:** v1's `setComponents` / mapping.yaml reads → Plugin::config() registration + Plugin::init() sibling-DI wiring (Phase 02.1 / commit 75a95bc pattern).
- **`$sites` source:** v1's mapping.yaml `sites:` block → `Plugin::resolveSitesMap()` (single source of truth, already wires EntryMigrationService).
- **Atomic writes:** v1's raw `file_put_contents` for migrator artifacts → `MappingFile::writeAtomic` / `writeAtomicJson` (Phase 2 / D-07).
- **Tolerance source:** v1 read from mapping.yaml `verify.tolerance` → Settings + CLI ladder (D-60). mapping.yaml stays clean.
- **Baseline shape:** v1's full SHA snapshot (525 LOC) → counts-only D-59 shape; SHA path explicitly deferred to a future `--deep` flag.

## Future hooks

- `verify capture-baseline --deep` — re-introduces v1's SHA-heavy snapshot for refactor-safety regression coverage. v1 source remains in `~/Sites/craft-kunstmaan-migrator/src/craft/verify/BaselineSnapshotService.php`.
- `VERIFY-<ts>.json` machine-readable sidecar — wait for NEXT-04 cross-client matrix.
- Spot-check URL list under `storage/migration/spot-check-urls.txt` — operator-curated; consumers may grow conventions over time.

## Verification

Every v1 rule above has a v2 disposition. No rule is dropped silently. The dropped items (D-59 SHA path, mapping.yaml verify.tolerance read, hardcoded site handles) are explicitly documented above with rationale and re-entry hooks where applicable.
```
  </action>
  <read_first>
    - .planning/phases/02.1-source-introspection/RECONCILIATION.md (template — confirm format conventions and table style)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-54 + D-59 + canonical refs)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (advisor flags 1-7, the cross-cutting reshape list)
    - All Plan 04-02 / 04-03 / 04-04 / 04-06 / 04-07 / 04-08 / 04-09 files just-shipped — confirm each per-plan RECONCILIATION section's content matches what this aggregate references
  </read_first>
  <acceptance_criteria>
    - `test -f .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns true
    - `grep -c 'Phase 4' .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns at least `1`
    - `grep -c 'D-54\|D-59' .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns at least `2`
    - `grep -c '| v1 file ' .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns at least `1` (aggregate table header)
    - `grep -c 'BaselineSnapshotService' .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns at least `1` (525 LOC drop call-out)
    - `grep -c 'SeoMigrationService\|RedirectMigrationService' .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns at least `1`
    - `grep -c 'shape-derived' .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns at least `1` (D-59 explicit disposition)
    - `grep -c 'verify capture-baseline --deep' .planning/phases/04-adapters-verify-settings/RECONCILIATION.md` returns at least `1` (future hook)
  </acceptance_criteria>
</task>

<task id="08">
  <action>
ADP-03 audit: confirm `composer.json` lists `craftcms/seomatic` and `nystudio107/retour` as `suggest`, NOT in `require`. This invariant is shipped in Phase 1's manifest; Phase 4 asserts it didn't regress.

Add a guard test at `tests/ComposerSuggestTest.php`:

```php
<?php
declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests;

use PHPUnit\Framework\TestCase;

final class ComposerSuggestTest extends TestCase
{
    public function testSeomaticAndRetourAreSuggestNotRequire(): void
    {
        $composerJson = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        $require = $composerJson['require'] ?? [];
        $suggest = $composerJson['suggest'] ?? [];

        $this->assertArrayNotHasKey('nystudio107/seomatic', $require, 'ADP-03: SEOmatic must NOT be in require');
        $this->assertArrayNotHasKey('craftcms/seomatic', $require, 'ADP-03: SEOmatic must NOT be in require');
        $this->assertArrayNotHasKey('nystudio107/retour', $require, 'ADP-03: Retour must NOT be in require');

        $allSuggestKeys = implode(' ', array_keys($suggest));
        $this->assertStringContainsString('seomatic', strtolower($allSuggestKeys), 'ADP-03: SEOmatic should be in suggest');
        $this->assertStringContainsString('retour', strtolower($allSuggestKeys), 'ADP-03: Retour should be in suggest');
    }
}
```

If the composer.json from Phase 1 doesn't currently have `suggest` entries for SEOmatic + Retour, this test will fail — that's the desired gate. The fix is to add them to composer.json, NOT to skip the test. PROJECT.md Out-of-Scope and the ROADMAP success criterion 1 require runtime detection, which presupposes a `suggest` entry as discoverability for operators.
  </action>
  <read_first>
    - composer.json (root — confirm current state of `require` and `suggest` sections)
    - .planning/REQUIREMENTS.md (ADP-03 wording — "Composer requirements list SEOmatic and Retour as `suggest`, not `require`.")
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (canonical refs — composer.json suggest already noted as Phase 1 satisfied at the manifest level)
  </read_first>
  <acceptance_criteria>
    - `test -f tests/ComposerSuggestTest.php` returns true
    - `grep -c 'public function test' tests/ComposerSuggestTest.php` returns at least `1`
    - `grep -c 'ADP-03' tests/ComposerSuggestTest.php` returns at least `1`
    - `grep -c 'assertArrayNotHasKey' tests/ComposerSuggestTest.php` returns at least `2` (require keys absent)
    - `vendor/bin/phpunit tests/ComposerSuggestTest.php` exits `0`
    - If the test fails on first run, `composer.json` `suggest` is updated (not the test); re-run exits `0`
  </acceptance_criteria>
</task>

<task id="09">
  <action>
Final guard: run the full test suite to confirm green status across every prior plan's deliverables.

```bash
composer test
```

If any test fails, identify whether the failure is:
- A regression in this plan's task work (fix in this plan).
- A defect in a prior Phase 4 plan's deliverable (fix the file directly, document in this plan's notes that you patched it).
- A pre-existing test that was already broken before Phase 4 started (out of scope; flag for the orchestrator).

The goal is `composer test` exit `0` at end of Phase 4.
  </action>
  <read_first>
    - composer.json (confirm test script wires phpunit)
    - phpunit.xml.dist (confirm test suite paths cover tests/, tests/verify/, tests/load/)
  </read_first>
  <acceptance_criteria>
    - `composer test` exits `0` at the end of all Phase 4 plans
    - `vendor/bin/phpunit --testdox` lists at least 6 new test classes (SnapshotDifferTest, SpotCheckUrlFetcherTest, CountGateServiceTest, CaptureBaselineHtmlServiceTest, SeomaticPayloadBuilderTest, AssetMigrationServiceRcaTest) plus ComposerSuggestTest
    - The test count grows by at least 12 vs Phase 3 baseline (3 + 3 + 3 + 1 + 2 + 4 + 1 = 17 new test methods)
  </acceptance_criteria>
</task>

## Verification

- `composer test` exits 0.
- All 7 new PHPUnit test classes pass.
- `RECONCILIATION.md` exists at the phase root.
- `composer.json` keeps SEOmatic + Retour as `suggest` (ADP-03 invariant guarded).

## must_haves

- Six new PHPUnit test files cover the six pure-function services / contracts (SnapshotDiffer, SpotCheckUrlFetcher B1 fix, CountGateService delta, CaptureBaselineHtmlService URL-list filter, SeomaticPayloadBuilder column→payload contract, AssetMigrationService RCA classifier).
- One ComposerSuggestTest guards ADP-03.
- Phase-level `RECONCILIATION.md` aggregates per-plan tables.
- `composer test` is green at end of Phase 4.

## RECONCILIATION

This plan is a meta-plan: it produces the phase-level RECONCILIATION.md (Task 07) instead of having a per-plan RECONCILIATION section of its own. The per-plan sections in Plans 04-02 / 04-03 / 04-04 / 04-06 / 04-07 / 04-08 / 04-09 are the load-bearing primary records; the aggregate is the index.
