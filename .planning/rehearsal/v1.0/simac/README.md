# Simac Rehearsal — Advisory Only

**Status:** STRUCTURAL SAMPLE ONLY for v1.0. Simac failures do NOT block the
v1.0 tag (Phase 5 / D-19). The required evidence is source-shape structure
that helps catch CQM-only assumptions; a Simac Craft target is not required
unless an operator separately configures one.

## Operator capture procedure

Run the structural source-shape audit against `~/Sites/simac-website/`
(multi-locale corpus):

```bash
php tools/audit-source-shapes.php ~/Sites/simac-website
```

Commit `source-shape-audit.txt` with structural rows only: counts, class names,
table names, relation types, relation metadata presence, and risk flags. Do
not commit source method bodies, property values, SQL row data, secrets, or
content samples.

If a Simac Craft target is later configured, the CQM rehearsal workflow can be
mirrored as an advisory exercise, but that is not a v1.0 release requirement.

## Mechanical gate

There is no mandatory Simac Craft mechanical gate for v1.0. Review the
structural audit for CQM-specific assumptions and record follow-up risks in
release notes or a later phase.
