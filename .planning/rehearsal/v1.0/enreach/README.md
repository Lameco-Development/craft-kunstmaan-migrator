# Enreach Rehearsal — Advisory Only

**Status:** ADVISORY. Failures here do NOT block the v1.0 tag (Phase 5 / D-19). 7-locale stress target — captured for cross-client matrix signal.

## Operator capture procedure

Same shape as `.planning/rehearsal/v1.0/cqm/README.md`, but against `~/Sites/enreach-website/`.

Required files: `REPORT.md`, `VERIFY.md`, `baseline.json`, `doctor-output.txt`, `mapping-summary.txt`. Optional: `allow-tokens.txt`.

## Mechanical gate

```bash
./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/enreach
```

Exit 1 here = a Phase 5.1 / NEXT-04 input.
