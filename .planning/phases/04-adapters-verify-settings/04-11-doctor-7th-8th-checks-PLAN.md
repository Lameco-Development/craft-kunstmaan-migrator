---
plan: 11
phase: 04
title: "Doctor 7th + 8th checks: adapter health + verify baseline presence"
wave: 4
depends_on: ["04-09"]
files_modified:
  - src/console/DoctorController.php
autonomous: true
requirements_addressed: [ADP-01, ADP-02, ADP-03, VER-01]
---

# Plan 04-11: Doctor 7th + 8th checks

## Objective

Extend the existing `DoctorController` (Phase 1 / Plan 04 base + Phase 02.1 / Plan 01 5th + Phase 3 / Plan 13 6th check) with two informational-only checks per D-69:

1. **7th check — Adapter health.** Report SEOmatic + Retour presence as `OK <plugin> v<version> installed` or `INFO <plugin> not installed (adapter will skip)`. Always returns true (informational, never FAIL — adapter absence is by design per ADP-01..03).
2. **8th check — Verify baseline presence.** Report `OK baseline.json present at <path>` or `INFO baseline.json missing — run verify capture-baseline first`. Always returns true.

The plain-text OK/INFO/WARN/FAIL discipline (Phase 1 / D-19) is preserved.

## Context

- D-69: both checks always exit OK (`return true`); INFO is the new badge for informational-only conditions (vs WARN which still implies actionable concern).
- D-19: plain-text + ANSI color discipline. INFO uses `Console::FG_YELLOW` (same as WARN, distinguished by the `INFO` prefix label).
- Phase 1 / D-20: gate-first idiom on actionIndex.
- ADP-03: composer.json already lists SEOmatic + Retour as `suggest` (Phase 1 manifest) — Doctor's check is the operator-facing surface that confirms detection works.

## Tasks

<task id="01">
  <action>
Add two new private methods to `src/console/DoctorController.php` and call them from `actionIndex` after the existing 6th check.

Locate the orchestration sequence (Phase 1 / Plan 04 base around lines 56-63 plus Phase 02.1 + Phase 3 extensions). It currently looks like:

```php
$ok = true;
$ok = $this->checkLegacyDb()             && $ok;
$ok = $this->checkApiKey()               && $ok;
$ok = $this->checkStorageDir()           && $ok;
$ok = $this->checkMappingFile()          && $ok;
$ok = $this->checkKunstmaanSourcePath()  && $ok;
$ok = $this->checkStateTable()           && $ok;
```

Append two new lines:

```php
// Phase 4 extensions — D-69. Both always return true (INFO not FAIL):
$ok = $this->checkAdapterPlugins()       && $ok;
$ok = $this->checkVerifyBaseline()       && $ok;
```

Add the two private methods (place near `checkApiKey` / `checkMappingFile` for consistency with Phase 1 / Plan 04 file layout):

```php
/**
 * Check #7 (D-69): adapter plugin presence — informational only.
 * SEOmatic + Retour are optional per ADP-01..03; absence is not a FAIL.
 */
private function checkAdapterPlugins(): bool
{
    $seomatic = Craft::$app->plugins->getPlugin('seomatic');
    if ($seomatic !== null) {
        $version = (string) $seomatic->getVersion();
        $this->stdout("  OK   seomatic v{$version} installed\n", Console::FG_GREEN);
    } else {
        $this->stdout("  INFO seomatic not installed (adapter will skip)\n", Console::FG_YELLOW);
    }

    $retour = Craft::$app->plugins->getPlugin('retour');
    if ($retour !== null) {
        $version = (string) $retour->getVersion();
        $this->stdout("  OK   retour v{$version} installed\n", Console::FG_GREEN);
    } else {
        $this->stdout("  INFO retour not installed (adapter will skip)\n", Console::FG_YELLOW);
    }
    return true; // D-69: always OK — adapter absence is informational.
}

/**
 * Check #8 (D-69): verify baseline presence — informational only.
 * Operators may run doctor before capturing baseline.
 */
private function checkVerifyBaseline(): bool
{
    $path = Craft::$app->path->getStoragePath() . '/migration/baseline.json';
    if (is_file($path)) {
        $this->stdout("  OK   baseline.json present at {$path}\n", Console::FG_GREEN);
    } else {
        $this->stdout(
            "  INFO baseline.json missing — run `verify capture-baseline` first if you want to gate migrate runs.\n",
            Console::FG_YELLOW,
        );
    }
    return true; // D-69: always OK.
}
```

Both methods strictly use the two-space indent + 5-char left-padded prefix format (`OK   ` / `INFO `) per Phase 1 / D-19 visual convention. Color: green for OK, yellow for INFO.

Update the section header rendered before the new checks (if DoctorController prints "Running 6 checks..." or similar at the top of actionIndex, bump the count to 8).
  </action>
  <read_first>
    - src/console/DoctorController.php (entire file — locate actionIndex orchestration lines 56-63 + per-check method shape; find the introductory stdout that announces check count)
    - .planning/phases/04-adapters-verify-settings/04-PATTERNS.md (DoctorController section, exact method bodies)
    - .planning/phases/04-adapters-verify-settings/04-CONTEXT.md (D-69)
    - .planning/phases/01-foundation-connectivity/01-CONTEXT.md (D-19 plain-text OK/WARN/FAIL discipline + D-20 gate-first idiom)
  </read_first>
  <acceptance_criteria>
    - `grep -c 'private function checkAdapterPlugins(' src/console/DoctorController.php` returns `1`
    - `grep -c 'private function checkVerifyBaseline(' src/console/DoctorController.php` returns `1`
    - `grep -c '\$this->checkAdapterPlugins()' src/console/DoctorController.php` returns at least `1` (called from actionIndex)
    - `grep -c '\$this->checkVerifyBaseline()' src/console/DoctorController.php` returns at least `1` (called from actionIndex)
    - `grep -E "getPlugin\('seomatic'\)" src/console/DoctorController.php` returns at least `1`
    - `grep -E "getPlugin\('retour'\)" src/console/DoctorController.php` returns at least `1`
    - `grep -c 'INFO seomatic not installed' src/console/DoctorController.php` returns at least `1`
    - `grep -c 'INFO retour not installed' src/console/DoctorController.php` returns at least `1`
    - `grep -c 'INFO baseline.json missing' src/console/DoctorController.php` returns at least `1`
    - `grep -c 'baseline.json' src/console/DoctorController.php` returns at least `1`
    - `grep -c 'D-69' src/console/DoctorController.php` returns at least `1`
    - `grep -c 'return true; // D-69' src/console/DoctorController.php` returns at least `2` (always-OK invariant in both methods)
    - `php -l src/console/DoctorController.php` outputs `No syntax errors detected`
    - `composer test` exits `0`
  </acceptance_criteria>
</task>

## Verification

- `composer test` exits 0.
- Manual smoke (deferred to Phase 5): `./craft kunstmaan-migrator/doctor` on a fresh dev install (no SEOmatic / no Retour / no baseline.json) prints two INFO lines + still exits 0; same command on a host with both plugins installed prints `OK seomatic vX.Y installed` + `OK retour vX.Y installed`.

## must_haves

- DoctorController emits 7th + 8th checks per D-69.
- Both checks always return true (never FAIL on adapter absence or missing baseline).
- INFO lines use `Console::FG_YELLOW` and the `INFO ` 5-char prefix.
- `composer test` stays green.

## RECONCILIATION

This plan extends an existing v2 file rather than porting from v1. v1's DoctorController had different checks (queue check, mapping check) that v2 already disposed of in earlier phases. No verbatim port — just a structural extension following Phase 1 / D-19 + D-20 + D-69.

| v1 / prior rule | v2 disposition |
|---|---|
| Phase 1 / D-19 plain-text OK/WARN/FAIL with ANSI color | **extended** — INFO joins the badge set (yellow, like WARN, distinguished by prefix). |
| Phase 1 / D-20 gate-first idiom | **preserved** — actionIndex still calls `enforceNeverProduction()` first. |
| Phase 02.1 / D-31 doctor 5th check (KunstmaanSourcePath) | **preserved** — sequence unchanged. |
| Phase 3 / D-13 doctor 6th check (state table) | **preserved** — sequence unchanged. |
| New 7th check (adapter health) — D-69 | **added** — INFO on absence, never FAIL (ADP-01..03 optional). |
| New 8th check (verify baseline) — D-69 | **added** — INFO on absence, never FAIL (operator may run doctor first). |
