# Enreach Rehearsal — Advisory Only

**Status:** STRUCTURAL SAMPLE ONLY for v1.0. Enreach failures do NOT block the
v1.0 tag (Phase 5 / D-19). The required evidence is source-shape structure for
the 7-locale stress source; an Enreach Craft target is not required unless an
operator separately configures one.

## Operator capture procedure

Run the structural source-shape audit against `~/Sites/enreach-website/`:

```bash
php tools/audit-source-shapes.php ~/Sites/enreach-website
```

Commit `source-shape-audit.txt` with structural rows only: counts, class names,
table names, relation types, relation metadata presence, and risk flags. Do
not commit source method bodies, property values, SQL row data, secrets, or
content samples.

If an Enreach Craft target is later configured, the CQM rehearsal workflow can
be mirrored as an advisory exercise, but that is not a v1.0 release
requirement.

## Mechanical gate

There is no mandatory Enreach Craft mechanical gate for v1.0. Review the
structural audit for CQM-specific assumptions and record follow-up risks in
release notes or a later phase.
