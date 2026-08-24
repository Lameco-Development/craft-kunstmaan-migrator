# 0002 — Nothing secret reaches project config

Status: Accepted · 2026-08 · Source: `CLAUDE.md` ground rules; `Settings::validateIsEnvReference()`

## Context

Craft persists plugin settings into project config, which is committed and
deployed. A credential written onto a `Settings` attribute would ship to
every environment and every developer's checkout.

## Decision

The connection, the mapping path and asset placement are
`config/kunstmaan-migrator.php` + `.env` concerns. The `Settings` model
still carries those properties so the config file can populate them, but
the credential fields store an environment variable *name*;
`Settings::validateIsEnvReference()` refuses a literal value. No code path
writes a resolved value onto a `Settings` attribute.

## Consequences

- `EnvironmentPipeline::dsnFromSettings()` resolves at run time from the
  variable name; nothing resolved is ever stored.
- Any new secret-bearing setting follows the same rule: name in config,
  value in `.env`.
