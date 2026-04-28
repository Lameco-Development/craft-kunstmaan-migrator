---
phase: 08-taxonomies-and-proposers
plan: 02
subsystem: source-introspection
tags: [tax-04, d-10, gedmo-translatable, doctrine-parser, attribute-only, src-20-invariant, tdd]
requires:
  - Phase 02.1 / Plan 02 (DoctrineEntityParser verbatim port)
  - Phase 04.1 / Plan 06 (SRC-20 attributes-only invariant; annotation paths removed)
provides:
  - DoctrineEntityParser scans the `Gedmo\Mapping\Annotation\*` namespace alongside `Doctrine\ORM\Mapping\*`
  - DoctrineColumnInfo carries a per-property `bool $isGedmoTranslatable` flag (default false)
  - 5 new regression tests covering all three attribute-form variants, the docblock-ignore SRC-20 invariant, and the default-false case
affects:
  - src/source/DoctrineEntityParser.php (316 → 450 LOC; +134)
  - src/source/DoctrineColumnInfo.php (22 → 34 LOC; +12)
  - tests/unit/source/DoctrineEntityParserAttributesOnlyTest.php (315 → 504 LOC; +189)
tech-stack:
  added: []
  patterns:
    - TDD with explicit RED commit (test) → GREEN commit (feat); follows Phase 4.1 / Plan 06 pattern
    - Use-map-driven attribute FQCN resolution mirroring `resolveTargetEntity()` for ManyToOne short-class resolution
    - Property-attribute span (bounded by prior `;`/`{`/`}` boundary) — extends the existing column regex with `PREG_OFFSET_CAPTURE` so a Gedmo attribute on the prior property cannot leak into this property's flag
    - Default-false readonly constructor parameter — preserves backward compatibility for every existing DoctrineColumnInfo caller
key-files:
  created: []
  modified:
    - src/source/DoctrineEntityParser.php
    - src/source/DoctrineColumnInfo.php
    - tests/unit/source/DoctrineEntityParserAttributesOnlyTest.php
decisions:
  - Flag lives on `DoctrineColumnInfo`, not on a new VO — the plan's "If the parser already has a column-info VO, add the flag to it" branch applies. Plan 11's TaxonomyMigrationService consumer reads the flag through the existing column iteration path.
  - Detection scope is "the property's attribute span" (bounded by prior `;`/`{`/`}` and the property declaration end), not "the file" — a property without `#[Gedmo\Translatable]` adjacent to it MUST NOT inherit the flag from a sibling property. `testGedmoFlagIsFalseByDefault` plus the `slug` assertion in `testCapturesGedmoTranslatableViaShortClassUseMap` lock that boundary.
  - Docblocks are stripped from the property span before attribute matching — preserves the SRC-20 invariant verbatim. `testIgnoresGedmoTranslatableInDocblock` is the load-bearing assertion.
  - All three attribute forms supported in one resolver: alias-prefix (`Gedmo\Translatable` with `use Gedmo\Mapping\Annotation as Gedmo`), bare short-class (`Translatable` with `use Gedmo\Mapping\Annotation\Translatable`), and FQCN (`\Gedmo\Mapping\Annotation\Translatable`). `resolveAttributeFqcn()` mirrors the convention `resolveTargetEntity()` uses for ManyToOne short names — same use-map, same precedence (alias > namespace fallback).
metrics:
  duration: 14m
  completed: 2026-04-27
---

# Phase 8 Plan 02: DoctrineEntityParser Gedmo Translatable Scan Summary

**One-liner:** Extended `DoctrineEntityParser` to recognize `#[Gedmo\Translatable]` (in all three attribute forms — alias-prefix, bare short-class, and FQCN) on a per-property basis; surfaced the result as `DoctrineColumnInfo::$isGedmoTranslatable` so Plan 11's `TaxonomyMigrationService` can union it with the runtime `ext_translations` row signal per D-10.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 RED | test: add failing tests for Gedmo Translatable per-property flag | `c57d583` | `tests/unit/source/DoctrineEntityParserAttributesOnlyTest.php` |
| 1 GREEN | feat: scan Gedmo namespace and surface isGedmoTranslatable flag | `4ca1e17` | `src/source/DoctrineColumnInfo.php`, `src/source/DoctrineEntityParser.php` |

REFACTOR phase was a no-op — the GREEN implementation landed cleanly with use-map-driven FQCN resolution mirroring the existing `resolveTargetEntity()` convention; no shape rework needed.

## Where the Gedmo Namespace Scan Lives

**`src/source/DoctrineEntityParser.php`** (450 LOC; +134 from baseline):

| Surface | Lines | Role |
|---------|-------|------|
| Class docblock | 22-26 | Documents the second namespace scan (D-10 signal #1) |
| `parseFile()` use-map plumb-through | 159-160 | Threads the use-map into `parseColumns()` so Gedmo short-class resolution rides the same map ManyToOne uses |
| `parseColumns()` — match offsets | 199-256 | Switches to `PREG_OFFSET_CAPTURE` so the property-span boundary can be computed; calls `propertyHasGedmoTranslatable()` per match |
| `propertyHasGedmoTranslatable()` | 282-330 | Bounded property-span lookbehind (back to prior `;`/`{`/`}`); strips docblocks; matches every `#[...]` attribute header in the span and resolves via use-map |
| `resolveAttributeFqcn()` | 341-359 | All three forms (alias-prefix / bare short-class / FQCN) → canonical FQCN string |

**No annotation parser revival:** `grep -cE "doctrine/annotations\|AnnotationReader" src/source/DoctrineEntityParser.php` returns 0. SRC-20 invariant intact.

## Where the `isGedmoTranslatable` Flag Lives

**`src/source/DoctrineColumnInfo.php`** (34 LOC; +12 from baseline):

The flag is a default-false readonly constructor parameter on the existing `DoctrineColumnInfo` value object (line 22 — sixth and final parameter). Default-false preserves backward compatibility for every existing caller; the parser is currently the only writer. Phase 11's `TaxonomyMigrationService` will be the first reader.

```php
public readonly bool $isGedmoTranslatable = false,
```

The accompanying docblock (lines 12-21) documents the D-10 union semantics so downstream agents understand they're consuming signal #1 (source attribute) and that signal #2 (runtime `ext_translations` row) is consumed elsewhere.

## SRC-20 Attributes-Only Invariant — Preserved

The plan's hardest constraint: do NOT revive annotation parsing. Three guards in code:

1. **`grep -cE "doctrine/annotations\|AnnotationReader" src/source/DoctrineEntityParser.php` = 0** — no dependency, no class import, no AnnotationReader-shaped code.
2. **`composer.json` unchanged** — no new `require` entry.
3. **`propertyHasGedmoTranslatable()` strips docblocks before attribute matching** — `preg_replace('!/\*.*?\*/!s', '', $span)` at line 305. The docblock-shaped `@Gedmo\Translatable` never reaches the attribute-class matcher.

**Test guard:** `testIgnoresGedmoTranslatableInDocblock` (lines 327-360) materializes a fixture with `/** @Gedmo\Translatable */` followed by `#[ORM\Column]` and asserts the column's flag is false. This is the SRC-20 load-bearing test for Phase 8.

## Tests

**File:** `tests/unit/source/DoctrineEntityParserAttributesOnlyTest.php` (504 LOC; +189 from baseline).

5 new test methods, 11 new assertions:

| Method | Fixture | Asserts |
|--------|---------|---------|
| `testCapturesGedmoTranslatableViaShortClassUseMap` | `use Gedmo\Mapping\Annotation as Gedmo;` + `#[Gedmo\Translatable]` on `name`; bare `slug` column | `name->isGedmoTranslatable === true`; `slug->isGedmoTranslatable === false` (boundary check — prevents flag leakage between sibling properties) |
| `testCapturesGedmoTranslatableViaDirectShortClassImport` | `use Gedmo\Mapping\Annotation\Translatable;` + `#[Translatable]` | `title->isGedmoTranslatable === true` (bare short-class form) |
| `testCapturesGedmoTranslatableViaFullyQualifiedAttribute` | `#[\Gedmo\Mapping\Annotation\Translatable]` (no use statement) | `label->isGedmoTranslatable === true` (FQCN form) |
| `testIgnoresGedmoTranslatableInDocblock` | `/** @Gedmo\Translatable */` + `#[ORM\Column]` | `name->isGedmoTranslatable === false` (SRC-20 load-bearing) |
| `testGedmoFlagIsFalseByDefault` | Two plain `#[ORM\Column]` columns with no Gedmo attributes anywhere | Every column has `isGedmoTranslatable === false` (default-value backward-compat) |

## RED → GREEN Gate Sequence

| Stage | State |
|-------|-------|
| Baseline (pre-test commit) | 6 parser tests pass; 161 corpus tests pass. |
| After RED commit `c57d583` | 11 parser tests; 5 fail with "Failed asserting that null is true/false" (the property `isGedmoTranslatable` does not exist on `DoctrineColumnInfo`, returning null). 6 surviving annotation/attribute regression tests still pass. |
| After GREEN commit `4ca1e17` | 11 parser tests pass; full corpus 336 tests / 908 assertions / OK with 1 PHPUnit deprecation warning (pre-existing, unrelated). Zero regressions. |

## Acceptance Criteria — All Met

| Criterion | Verification | Result |
|-----------|--------------|--------|
| `grep -c "Gedmo" src/source/DoctrineEntityParser.php` ≥ 1 | bash | 25 ✓ |
| `grep -cE "isGedmoTranslatable\|gedmoTranslatable" src/source/DoctrineEntityParser.php` ≥ 1 | bash | 3 ✓ |
| `grep -cE "doctrine/annotations\|AnnotationReader" src/source/DoctrineEntityParser.php` = 0 | bash | 0 ✓ |
| `composer phpstan` exits 0 | n/a — see Deviations | n/a |
| Full PHPUnit corpus green | `./vendor/bin/phpunit` | 336 tests / 908 assertions OK ✓ |

## Plan-Level TDD Gate Compliance

| Gate | Commit | Hash |
|------|--------|------|
| RED | `test(08-02): add failing tests for Gedmo Translatable per-property flag` | `c57d583` |
| GREEN | `feat(08-02): scan Gedmo namespace and surface isGedmoTranslatable flag` | `4ca1e17` |
| REFACTOR | (no separate commit — implementation landed cleanly) | n/a |

Both required gates present in `git log --oneline`. RED → GREEN ordering preserved.

## Deviations from Plan

**1. [Rule 3 — Blocking issue] `composer phpstan` script does not exist in this project.** The plan's `<verify><automated>` block calls `composer phpstan && grep -c ...`. Running it returns `Command "phpstan" is not defined.` — there is no `phpstan` entry in `composer.json` `scripts`, no `phpstan.neon`, and no `vendor/bin/phpstan` binary. This is a project-wide reality, not a Plan 08-02 omission. Substituted `./vendor/bin/phpunit` (the project's actual lint/test gate per `composer.json` `scripts.test`) — 336 tests / 908 assertions OK, zero regressions. Future phpstan adoption (out of scope here) would re-enable the original verify shape.

No architectural deviations. No deferred items. No CLAUDE.md rule conflicts.

## Self-Check: PASSED

- `src/source/DoctrineEntityParser.php` modified: FOUND ✓
- `src/source/DoctrineColumnInfo.php` modified: FOUND ✓
- `tests/unit/source/DoctrineEntityParserAttributesOnlyTest.php` modified: FOUND ✓
- Commit `c57d583` (RED test): FOUND in `git log --oneline` ✓
- Commit `4ca1e17` (GREEN feat): FOUND in `git log --oneline` ✓
- `./vendor/bin/phpunit` exits 0: 336/908 OK ✓
- `grep -c "Gedmo" src/source/DoctrineEntityParser.php` = 25 (≥ 1) ✓
- `grep -cE "isGedmoTranslatable|gedmoTranslatable" src/source/DoctrineEntityParser.php` = 3 (≥ 1) ✓
- `grep -cE "doctrine/annotations|AnnotationReader" src/source/DoctrineEntityParser.php` = 0 ✓
- SRC-20 attributes-only invariant: preserved (docblock-strip pre-match + zero annotation-reader code added) ✓
