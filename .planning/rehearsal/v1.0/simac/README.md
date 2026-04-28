# Simac Rehearsal — Advisory Only

**Status:** ADVISORY. Failures here do NOT block the v1.0 tag (Phase 5 / D-19). Captured for cross-client matrix signal; informs Phase 5.1 / NEXT-04 if cross-client correctness blocks adoption.

## Operator capture procedure

Same shape as `.planning/rehearsal/v1.0/cqm/README.md`, but against `~/Sites/simac-website/` (multi-locale corpus).

Required files: `REPORT.md`, `VERIFY.md`, `baseline.json`, `doctor-output.txt`, `mapping-summary.txt`. Optional: `allow-tokens.txt`.

## Mechanical gate

```bash
./craft kunstmaan-migrator/rehearsal/check .planning/rehearsal/v1.0/simac
```

Exit 1 here = a Phase 5.1 / NEXT-04 input. Document the failure mode in the v1.0 RELEASE-CHECKLIST.md "Simac advisory" section, but do not block the tag.
