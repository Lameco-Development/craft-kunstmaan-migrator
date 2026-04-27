# CQM Rehearsal — v1.0 Ship Gate

**Status:** BLOCKING. CQM `kunstmaan-migrator/rehearsal/check` exit 0 is the v1.0 tag gate (Phase 5 / D-19, D-23).

## Operator capture procedure

Run the migration against `~/Sites/cqm-website/` on a dev host (not production — `NeverProductionTrait` enforces). Then commit these files into this directory:

| File | Source | Purpose |
|---|---|---|
| `REPORT.md` | `storage/migration/REPORT.md` (after `migrate --live`) | Rehearsal summary + skipped stages + asset RCA (Phase 4 / Plan 12 + Phase 4.1 / CFG-07) |
| `VERIFY.md` | `storage/migration/VERIFY-<ts>.md` (after `verify`; drop the timestamp suffix) | Count-match gate + URL diff gate output (Phase 4 / Plan 04) |
| `baseline.json` | `storage/migration/baseline.json` (after `verify capture-baseline`) | Light entity-count snapshot |
| `doctor-output.txt` | Captured stdout/stderr of `./craft kunstmaan-migrator/doctor` | All doctor checks passing |
| `mapping-summary.txt` | Counts of accepted/dropped/needs-review/proposed rows from CQM's `mapping.yaml` | Operator-side script |
| `allow-tokens.txt` | Optional, operator-curated | One CKEditor token literal per line; `#` comments OK |

## Mechanical gate

Once the artifacts are committed:

```bash
./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/cqm
```

Exit 0 = all three gates pass:

1. Counts within tolerance — every line in VERIFY.md `[1/2] Count-match gate` is OK or SKIP
2. Zero unresolved CKEditor tokens — no `[NT<id>]` / `[M<id>]` / `asset:<n>` in REPORT.md unless allow-listed
3. All assets RCA-tagged — every row in REPORT.md `## Asset RCA` has a non-empty reason

Exit 1 = at least one gate failed (per-gate failure summary on stderr).
Exit 2 = directory or required file missing.

## Privacy note

CQM data ships verbatim — no anonymization (Phase 5 / D-04). This is acceptable while the repo stays under `lameco/`. If the repo ever goes public, run a scrub pass on these files first (RELEASE-CHECKLIST.md flags this as a pre-publish gate, not a v1.0 ship gate).
