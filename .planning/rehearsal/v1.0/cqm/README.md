# CQM Rehearsal — v1.0 Ship Gate

**Status:** BLOCKING. The restored-backup CQM full workflow is the Phase 10
closing gate and the v1.0 tag gate. Focused reruns are useful while
implementing fixes, but they do **not** close Phase 10.

## Restored-backup closing workflow

Run the full workflow against `~/Sites/cqm-website/` on a dev host with the
configured CQM Craft target at `~/Sites/cqm-craft-website/` (not production —
`NeverProductionTrait` enforces). Start by restoring the pre-live backup:

```bash
cd ~/Sites/cqm-craft-website
php craft db/restore storage/backups/craft-starter-kit--2026-04-28-131310--v5.9.20.sql --interactive=0
php craft kunstmaan-migrator/doctor
php craft kunstmaan-migrator/analyze
php craft kunstmaan-migrator/map
php craft kunstmaan-migrator/compile
php craft kunstmaan-migrator/migrate --dry-run
php craft kunstmaan-migrator/migrate --live
php craft kunstmaan-migrator/verify
php tools/audit-source-shapes.php ~/Sites/cqm-website
```

Strict acceptance bar:

1. `storage/migration/REPORT.md` shows **zero entry failures** and **zero stage
   failures** after the restored-backup `migrate --live` run.
2. `kunstmaan-migrator/verify` labels count domains explicitly:
   Craft baseline/current drift, migration-created state counts, and
   source/transformed parity where source-derived expected counts exist.
3. Page-rooted closure is a hard gate. Entries, page parts, relations, assets,
   taxonomies, SEO, redirects, and CKEditor references must be migrated or
   explicitly classified as dropped/out_of_scope.
4. Explicit dropped/out_of_scope Page-rooted rows may appear in
   `PAGE-ROOTED-COVERAGE.md` or `REPORT.md` only as classified coverage/report
   rows; they must not increment entry failure or stage failure counts.
5. The closing proof fails if any page-owned referenced content surface is
   unclassified, silently omitted, unresolved without an accepted reason, or
   warning/unsupported without explicit release acceptance.

Recommended inspection commands after the full workflow:

```bash
grep -n "failed\|Failures\|entry failures\|stage failures" storage/migration/REPORT.md
grep -n "fallback\|Matrix title\|sparse locale\|taxonomy locale\|taxonomyMode" storage/migration/REPORT.md
grep -n "Page-rooted\|unsupported\|warning\|out_of_scope\|dropped" storage/migration/PAGE-ROOTED-COVERAGE.md storage/migration/REPORT.md
grep -n "baseline/current\|migration-created\|source parity\|domain" storage/migration/VERIFY-*.md
```

The source-shape audit is structural only: keep counts, class names, table
names, relation types, metadata presence, and risk flags; do not copy source
method bodies, property values, SQL rows, secrets, or content samples.

Then commit these files into this directory:

| File | Source | Purpose |
|---|---|---|
| `REPORT.md` | `storage/migration/REPORT.md` (after `migrate --live`) | Rehearsal summary + skipped stages + asset RCA (Phase 4 / Plan 12 + Phase 4.1 / CFG-07) |
| `VERIFY.md` | `storage/migration/VERIFY-<ts>.md` (after `verify`; drop the timestamp suffix) | Count-match gate + URL diff gate output (Phase 4 / Plan 04) |
| `PAGE-ROOTED-COVERAGE.md` | `storage/migration/PAGE-ROOTED-COVERAGE.md` (after `compile`) | Page-rooted migrated/dropped/out_of_scope/unsupported/warning review evidence |
| `baseline.json` | `storage/migration/baseline.json` (after `verify capture-baseline`) | Light entity-count snapshot |
| `doctor-output.txt` | Captured stdout/stderr of `./craft kunstmaan-migrator/doctor` | All doctor checks passing |
| `mapping-summary.txt` | Counts of accepted/dropped/needs-review/proposed rows from CQM's `mapping.yaml` | Operator-side script |
| `source-shape-audit.txt` | `php tools/audit-source-shapes.php ~/Sites/cqm-website` | Structural genericity sample output |
| `allow-tokens.txt` | Optional, operator-curated | One CKEditor token literal per line; `#` comments OK |

## Mechanical gate

Once the artifacts are committed:

```bash
./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm
```

Exit 0 = all gates pass:

1. Counts are domain-labeled in VERIFY.md. Craft baseline/current drift is
   informational; migration-created state counts are reported separately;
   source/transformed parity is blocking only when source-derived expected
   counts are available.
2. Zero entry failures and zero stage failures in REPORT.md.
3. Zero unresolved CKEditor tokens — no `[NT<id>]` / `[M<id>]` / `asset:<n>` in
   REPORT.md unless allow-listed.
4. All assets RCA-tagged — every row in REPORT.md `## Asset RCA` has a
   non-empty reason.
5. Page-rooted coverage has no unclassified, silently omitted, unsupported, or
   warning rows without explicit release acceptance; accepted dropped/out_of_scope
   rows remain classified rows and do not count as failures.

Exit 1 = at least one gate failed (per-gate failure summary on stderr).
Exit 2 = directory or required file missing.

## Privacy note

CQM data ships verbatim — no anonymization (Phase 5 / D-04). This is acceptable while the repo stays under `lameco/`. If the repo ever goes public, run a scrub pass on these files first (RELEASE-CHECKLIST.md flags this as a pre-publish gate, not a v1.0 ship gate).
