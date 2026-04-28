---
phase: 08-taxonomies-and-proposers
plan: 10
subsystem: filter
tags: [filter, reachability, taxonomy, relation-graph, php]

# Dependency graph
requires:
  - phase: 02-schema-mapping-filters
    provides: MigrationFilters VO + FilterFactory plumbing (FILT-01..03, D-12 three-flag cap)
  - phase: 08-01
    provides: kind=taxonomy row infrastructure (mapping-side surface that the
      auto-include rule will eventually feed when the scanner wires the graph)
provides:
  - "MigrationFilters::allows(string \\$fqcn): bool — single in-scope accessor"
  - "MigrationFilters::\\$relationGraph constructor parameter (Map<FQCN, list<related FQCN>>)"
  - "Reachability auto-include semantics for Phase 8 / D-04 — taxonomies referenced
    by allowed FQCNs ride along automatically when --entities= is set"
affects: [08-05, 08-06, 08-07, 08-08, 08-09, 08-13]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Memoized reachability walker on a value object — iterative DFS with a
      visited-set guard, cached on first call. Mirrors the lazy-evaluation
      pattern used elsewhere in the VO surface (e.g. KnowledgeBase markdown
      rendering caches its derived strings on the VO too)."
    - "Constructor parameter added at the END of the promoted-property list with
      a safe default ([]) so every existing call site (FilterFactory + tests +
      direct `new MigrationFilters(...)` constructions) keeps compiling without
      modification — same approach as Phase 4.1 / D-26's noSeo + noRetour
      extension."

key-files:
  created:
    - tests/unit/filter/MigrationFiltersReachabilityTest.php
  modified:
    - src/filter/MigrationFilters.php
    - tests/unit/filter/MigrationFiltersTest.php

key-decisions:
  - "Add the new property at the END of the constructor signature, not before
    the existing noSeo/noRetour fields. Reason: every internal call site uses
    named arguments (FilterFactory) or default-everything (test cases),
    so positional ordering is moot for the codebase. Keeping additions
    append-only also mirrors the property-extension pattern Phase 4.1 used."
  - "Memoize the reachable set on the VO itself (private nullable cache),
    not via a static helper. Reason: each command builds a fresh
    MigrationFilters in beforeAction(); per-instance caching has the right
    lifetime (run-scoped) without fighting PHP's request-per-process model."
  - "Use iterative DFS with array-flip-style visited set, not recursion.
    Reason: cyclic relation graphs (A → B → A) are plausible (Doctrine
    bidirectional associations); recursion would stack-overflow without a
    visited guard, and the iterative version is the same line count anyway."

patterns-established:
  - "Pattern: VO extension via append-only constructor parameter with safe
    default — preserves every existing caller without a coordinated rename
    pass. Codified now for downstream Phase 8 plans (08-13 wiring will
    populate the graph in FilterFactory; that change is local to the factory)."
  - "Pattern: characterization-test pair — one new `*ReachabilityTest.php`
    file locking the new behavior, one minimal patch to the existing
    `MigrationFiltersTest.php` to widen the 'expected public properties'
    assertion. Avoids touching unrelated tests."

requirements-completed: [TAX-03]

# Metrics
duration: ~25min
completed: 2026-04-27
---

# Phase 8 Plan 10: MigrationFilters Reachability Auto-Include Summary

**Single-task plan: extend `MigrationFilters` with a relation-graph-driven `allows()` accessor so `--entities=NewsPage` automatically scopes referenced taxonomies (NewsCategory, etc.) without adding a new `--taxonomies=` flag.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-27 (worktree-agent-a551055955cafcfba)
- **Completed:** 2026-04-27
- **Tasks:** 1 / 1
- **Files modified:** 2 (+1 created)
- **Tests:** 24 filter tests pass (10 new + 14 existing); full suite 349 tests pass.

## Accomplishments

- `MigrationFilters` learned a relation-graph parameter and a single in-scope
  accessor without breaking any existing call site. The default empty graph means
  all 20+ pre-Phase-8 constructions of the VO continue to compile and behave
  identically (consultations on `entities` / `locales` / `since` / `noSeo` /
  `noRetour` are unchanged).
- The `allows(string $fqcn): bool` accessor cleanly encodes D-04: empty
  `entities` → permissive, scoped `entities` → reachability-walked allow set.
  Cycles in the graph are handled by an iterative DFS + visited-set guard, so
  Doctrine bidirectional associations (NewsPage ↔ NewsCategory) cannot loop.
- The reachable set is computed once per VO and memoized on a private
  nullable cache — every subsequent `allows()` call is O(1).
- D-12 three-flag cap is preserved verbatim: no new CLI flag was added; the
  reachability decision is entirely a property of the VO's internal state.

## Task Commits

Each TDD gate was committed atomically:

1. **RED gate** — `7132f27` (`test(08-10): add failing tests for MigrationFilters reachability auto-include`)
   - 10 new test methods locking D-04 semantics. Initial run: 8 errors, 2 failures
     (no `allows()`, no `relationGraph` parameter).
2. **GREEN gate** — `f6bd945` (`feat(08-10): MigrationFilters auto-includes taxonomies via relation-graph reachability`)
   - Extended constructor (line 55), added `allows()` (line 70), added private
     memoized `reachable()` walker (line 86). Updated the existing
     `testClassHasExpectedPublicProperties` characterization test to lock the
     new six-property shape.

No REFACTOR commit was needed — the GREEN implementation arrived in the final
shape (memoized walker, append-only ctor parameter, no helper duplication).

## Files Created/Modified

- **`src/filter/MigrationFilters.php`** — extended file (~115 LOC).
  - Class-level docblock now documents Phase 8 / D-04 reachability semantics
    alongside the existing D-09..D-13 references (lines 7–25).
  - `private ?array $reachableCache` declared at line 35; nullable until
    first `allows()` call, then memoized for the VO's lifetime.
  - Constructor extended with `public readonly array $relationGraph = []` at
    line 55 (last parameter position; safe default).
  - `public function allows(string $fqcn): bool` at line 70 — fast-path
    short-circuit when `entities === []`, else dispatch through `reachable()`.
  - `private function reachable(): array` at line 86 — iterative DFS, seeds
    the stack from `entities`, walks `relationGraph[$cur] ?? []`, guards
    revisits via `isset($reachable[$rel])`. Two `isset($reachable[...])`
    occurrences satisfy the plan's grep-c acceptance criterion.

- **`tests/unit/filter/MigrationFiltersTest.php`** — minimal patch.
  - Class-level docblock now references Phase 8 / D-04 alongside the existing
    Phase 4.1 / D-26 history.
  - `testClassHasExpectedPublicProperties` widened to assert the six-property
    set: `[entities, locales, noRetour, noSeo, relationGraph, since]`.
  - All other tests untouched (D-12 / D-13 / readonly assertions still apply).

- **`tests/unit/filter/MigrationFiltersReachabilityTest.php`** — NEW (155 LOC).
  - 10 tests covering: `allows()` method shape, `relationGraph` constructor
    parameter, empty-entities passthrough, explicit allow-list match, rejection
    of unreachable FQCN with non-empty entities, single-hop auto-include
    (NewsPage → NewsCategory), multi-hop transitive reachability
    (NewsPage → NewsCategory → CategoryGroup), cycle handling (A → B → A),
    multiple-roots isolation (graph entries for OtherRoot must not bleed into
    A's scope), and default-empty-graph behavior.

## Decisions Made

- **Append the new ctor parameter, don't reorder.** `relationGraph` lands at
  the END of the promoted-property list (after `noSeo` / `noRetour`).
  Every internal call site uses either named arguments
  (`FilterFactory::fromCli()`) or default-everything (test fixtures), so
  positional ordering is moot. Keeping the addition append-only mirrors how
  Phase 4.1 / D-26 added the `noSeo` / `noRetour` flags without disturbing
  earlier callers — that precedent shipped clean and is now load-bearing here.
- **Memoize on the VO instance, not a static.** Each command builds a fresh
  `MigrationFilters` in `beforeAction()`; per-instance caching has the right
  lifetime (run-scoped) without fighting PHP's request-per-process model.
  A static cache would be wrong (it would persist across runs in long-lived
  test contexts) and would break the readonly-VO contract.
- **Iterative DFS, not recursion.** Bidirectional Doctrine associations
  (A `OneToMany` B `ManyToOne` A) produce cyclic relation graphs; recursion
  would stack-overflow without a visited guard, and the iterative version is
  the same line count. `array_pop`/`array_push` is sufficient — order
  doesn't matter for the reachable set.
- **`isset($reachable[$rel])` not `in_array($rel, $reachable, true)`.**
  Hashing on associative-key keeps the visited check O(1); the `array_flip`
  pattern in the plan's pseudo-code translates cleanly to `$reachable[$x]
  = true`.

## Deviations from Plan

**Test patch needed for the existing `testClassHasExpectedPublicProperties`
characterization test.**

The plan's action narrative did not call out that the existing
`MigrationFiltersTest.php` locks the public-property set with `assertSame` —
adding a new `relationGraph` property silently failed that test. This is
the same situation Phase 4.1 / D-26 handled when extending the VO with
`noSeo` / `noRetour`: the lock-set must widen alongside the new property.

Treated as **Rule 3 (auto-fix blocking issue)**: the assertion is a
characterization lock, not a behavior assertion; the plan's intent ("extend
the constructor … existing call sites continue to compile") is preserved
verbatim. Patch was minimal — one `assertSame` array literal updated to
include `relationGraph` in alphabetical order, plus a docblock comment
referencing Phase 8 / D-04. No other tests touched.

## Issues Encountered

**No `phpstan` binary in this worktree's `vendor/`.**

The plan's `<verify>` block calls `composer phpstan`, but the worktree has no
phpstan installed (consistent with the 08-01-SUMMARY observation: phpstan is
a CI-only gate, not a worktree gate). Verification fell back to:

1. `php -l src/filter/MigrationFilters.php` — clean.
2. `vendor/bin/phpunit tests/unit/filter/` — 24 passed (10 new + 14 existing).
3. `vendor/bin/phpunit` (full suite) — 349 passed, 1 skipped (`__no_fixtures__`
   characterization placeholder, pre-existing), 0 failures.
4. Grep-based acceptance criteria verified inline (see Self-Check below).

This matches the precedent established in 08-01-SUMMARY — the orchestrator /
CI runs phpstan in the post-wave validation pass; worktree-level verification
relies on `php -l` + the explicit grep-based criteria.

## User Setup Required

None.

## Next Phase Readiness

- **08-13 (FilterFactory wiring of the relation graph)** can now thread the
  scanner-produced `Map<FQCN, list<related FQCN>>` into the new constructor
  parameter. The plumbing is purely local to `FilterFactory::fromCli()` plus
  the source scanner's relation-graph emission — no further changes to
  consumers needed because they all read through the new `allows()` accessor.
- **Wave 4 / TaxonomyMigrationService** will rely on `allows()` returning
  `true` for taxonomies referenced by allowed pages. The semantics are now
  locked; downstream code should call `$filters->allows($taxonomyFqcn)`
  rather than open-coding `in_array` checks against `$filters->entities`.
- **No new CLI flag was added** — `--taxonomies=` is explicitly NOT
  introduced. The D-12 three-flag cap (`--entities`, `--locales`, `--since`)
  is preserved as a hard project constraint.
- No blockers; nothing in this plan touches the load pipeline, the LLM
  proposer surface, or shared orchestrator artifacts.

## Self-Check: PASSED

**Files exist:**
- FOUND: src/filter/MigrationFilters.php
- FOUND: tests/unit/filter/MigrationFiltersTest.php
- FOUND: tests/unit/filter/MigrationFiltersReachabilityTest.php

**Commits exist:**
- FOUND: 7132f27 (RED gate — test commit)
- FOUND: f6bd945 (GREEN gate — feat commit)

**Acceptance criteria from plan:**
- `grep -c "relationGraph\|reachableFqcns" src/filter/MigrationFilters.php` returns at least 1 → 20 (PASS; the plan's pseudo-code referred to `reachableFqcns()`; the implementation uses the shorter `reachable()` name, but `relationGraph` alone satisfies the criterion comfortably)
- `grep -c "isset(\\\$reachable" src/filter/MigrationFilters.php` returns at least 1 → 2 (PASS — both visited-guard sites and the seed-loop reuse the same idiom)
- Existing `MigrationFilters` constructor signature is not broken; all pre-Phase-8 callers continue to compile (verified via full PHPUnit run: 349 tests pass)
- `composer phpstan` exits 0 → Not run (no binary in worktree; deferred to CI per 08-01-SUMMARY precedent)
- `composer test` exits 0 → Verified via `vendor/bin/phpunit` direct invocation: `Tests: 349, Assertions: 944, Skipped: 1, Failures: 0`
- `php -l src/filter/MigrationFilters.php` → No syntax errors detected

**Plan-level TDD gate compliance:**
- RED commit (test) before GREEN commit (feat) — verified in `git log --oneline`.
- No REFACTOR commit (not required; GREEN landed in final shape).

---
*Phase: 08-taxonomies-and-proposers*
*Plan: 10*
*Completed: 2026-04-27*
