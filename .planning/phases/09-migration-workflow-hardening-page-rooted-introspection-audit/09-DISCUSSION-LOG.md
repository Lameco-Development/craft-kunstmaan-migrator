# Phase 9: Migration Workflow Hardening & Page-rooted Introspection Audit - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `09-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-04-28T12:04:07+02:00
**Phase:** 09 - Migration Workflow Hardening & Page-rooted Introspection Audit
**Areas discussed:** autonomous defaults selected because the workflow was started in yolo mode and the user was not available for interactive prompts

---

## Genericity and rehearsal posture

| Option | Description | Selected |
|--------|-------------|----------|
| CQM-only hardening | Optimize only for the currently installed CQM rehearsal pair. | |
| Generic with source-shape sampling | Keep CQM as primary integration target, but inspect Simac/Enreach source shapes to catch assumptions. | yes |
| Require all projects to have Craft targets | Block planning until Simac/Enreach Craft targets exist. | |

**Selected default:** Generic with source-shape sampling.
**Notes:** Matches the user's clarification that the plugin should be as generic as practical across Lameco Kunstmaan websites, while recognizing CQM is the installed Craft target.

---

## Page-rooted coverage contract

| Option | Description | Selected |
|--------|-------------|----------|
| Best-effort migration only | Keep migrating what current handlers see and rely on operator spot checks. | |
| Deterministic coverage accounting | Report each Page's direct fields, pageparts, assets, relations, taxonomies/dataProviders, SEO/redirects, and CKEditor references as migrated/dropped/out-of-scope. | yes |
| Full automatic migration of all discovered surfaces | Treat every discovered relation/subsystem as a must-migrate target. | |

**Selected default:** Deterministic coverage accounting.
**Notes:** This fits the user's acceptance that 100% migration is impossible, but silent omissions are not acceptable.

---

## Compile and workflow safety

| Option | Description | Selected |
|--------|-------------|----------|
| Docs-only compile step | Add `compile` to README/release docs but leave runtime behavior unchanged. | |
| Migrate preflight + explicit compile | Document `compile` and make `migrate` refuse when compiled blocks are missing. | yes |
| Auto-compile inside migrate | Let `migrate` run compile automatically. | |

**Selected default:** Migrate preflight + explicit compile.
**Notes:** Auto-compilation can alter operator-reviewed runtime structures. Explicit compile keeps mapping diffs reviewable and matches existing `CompileController` overwrite semantics.

---

## Mapping merge preservation

| Option | Description | Selected |
|--------|-------------|----------|
| Preserve only proposals | Keep current merge return shape. | |
| Preserve all top-level mapping data | Merge proposals while retaining existing compiled blocks and metadata. | yes |
| Split compiled blocks to another file | Avoid merge conflicts by adding a second runtime mapping artifact. | |

**Selected default:** Preserve all top-level mapping data.
**Notes:** Single `mapping.yaml` is a locked project decision; the bug is top-level data loss during merge, not the single-file model.

---

## Filter semantics

| Option | Description | Selected |
|--------|-------------|----------|
| Treat `--entities` as Craft handles | Make filters match Craft sections/entry types everywhere. | |
| Treat `--entities` as source entities and translate when needed | Normalize FQCN/basename at CLI boundaries, then map to Craft handles at Craft query surfaces. | yes |
| Add new flags for each dependency type | Add `--taxonomies`, `--assets`, `--relations`, etc. | |

**Selected default:** Source entities with translation when needed.
**Notes:** Preserves the Phase 2/8 three-flag cap and aligns with Page-rooted dependency closure.

---

## Failure semantics

| Option | Description | Selected |
|--------|-------------|----------|
| Continue and return OK | Preserve current behavior even when entries fail. | |
| Continue for diagnostics, return non-zero if anything failed | Keep per-entry diagnostics while making CI/operator outcome truthful. | yes |
| Stop on first entry failure | Fail fast immediately. | |

**Selected default:** Continue for diagnostics, return non-zero if anything failed.
**Notes:** Preserves Phase 3's useful failure report while fixing the green-run false signal.

---

## CKEditor unresolved marker safety

| Option | Description | Selected |
|--------|-------------|----------|
| Escape HTML entities only | Use `htmlspecialchars()` in the comment marker. | |
| Comment-safe encoded source | Store the legacy URL in delimiter-safe form such as base64url. | yes |
| Drop marker details entirely | Emit only a generic unresolved comment. | |

**Selected default:** Comment-safe encoded source.
**Notes:** HTML escaping alone does not make `--` safe inside comments.

---

## Asset preload behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Keep full `kuma_media` preload | Treat `--preload-assets` as a full media table import. | |
| Referenced-only preload | Preload only assets referenced by in-scope entries. | yes |
| Rename to full-media-preload | Keep behavior but make the flag explicit. | |

**Selected default:** Referenced-only preload.
**Notes:** Matches PROJECT.md's page-driven migration model and known orphan-media trade-off.

---

## Testing and release evidence

| Option | Description | Selected |
|--------|-------------|----------|
| Unit-only regression tests | Cover pure helpers only. | |
| Finding-driven tests + fixture/rehearsal checks | Add regression tests for audit findings and make empty fixture/rehearsal evidence visible. | yes |
| Full cross-project migration tests | Require runnable CQM, Simac, and Enreach migrations in CI. | |

**Selected default:** Finding-driven tests + fixture/rehearsal checks.
**Notes:** Cross-project CI would be brittle and likely impossible without configured targets/secrets, but source-shape sampling and CQM rehearsal evidence are required.

---

## the agent's Discretion

- Artifact names and exact report formats are left to planning.
- The exact split into plans/waves is left to planning.
- The exact source-shape sampling depth for Simac/Enreach is left to planning, with a minimum bar of identifying genericity risks.

## Deferred Ideas

- Full orphan-media import.
- Full generic embed/promote architecture if not necessary for v1.0.
- Craft schema generation/starter-kit writer seam.
- Non-content Kunstmaan subsystem migration.
