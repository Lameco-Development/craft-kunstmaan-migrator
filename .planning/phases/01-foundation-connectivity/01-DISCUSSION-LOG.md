# Phase 1: Foundation & Connectivity - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-25
**Phase:** 01-foundation-connectivity
**Areas discussed:** Source layout, State table schema, Legacy DB wiring, Settings + doctor edges

---

## Source layout

### Q1 — top-level src/ shape

| Option | Description | Selected |
|--------|-------------|----------|
| Stage-based (Recommended) | Top-level dirs map to operator's mental model — Doctor, Analyze, Map, Migrate, Verify. Cross-cutting bits (Db, Migrations, Models) sit alongside. | ✓ |
| Concern-based (Symfony-ish) | Horizontal slicing — all controllers in src/Console/, all services in src/Service/. Familiar to Symfony devs. | |
| Truly flat | No subfolders until pain forces it. ~30+ peer files. | |
| Hybrid Pipeline/ | Top-level concerns + a single src/Pipeline/ with stage subdirs. | |

**User's choice:** Stage-based.
**Notes:** Locks the vertical-slice direction PROJECT.md called for; v1's three-tier `kunstmaan/`/`bridge/`/`craft/` is fully retired.

### Q2 — migrate/install location

| Option | Description | Selected |
|--------|-------------|----------|
| Migrate/MigrateController stub (Recommended) | Phase 1 ships actionInstall on the same controller Phase 3 fills out. CLI URL stays kunstmaan-migrator/migrate/install. | ✓ |
| Migrations/InstallController.php | Dedicated controller; decouples install from the Migrate pipeline. CLI URL would change. | |
| Doctor/DoctorController | Tucks install into doctor — breaks v1 muscle memory. | |

**User's choice:** Migrate/MigrateController stub.

### Q3 — namespace casing (initial pass)

| Option | Description | Selected |
|--------|-------------|----------|
| StudlyCaps dirs (Recommended) | src/Doctor/, src/Migrate/. FQCN: lameco\kunstmaanmigrator\Doctor\DoctorController. | |
| All lowercase (v1 style) | src/doctor/, src/migrate/. FQCN: lameco\kunstmaanmigrator\doctor\DoctorController. | |

**User's choice:** "Other" — asked Claude to check Craft CMS best practices.
**Notes:** Claude verified the Craft framework, Yii2, and major Craft plugins (nystudio107/seomatic, verbb/hyper, putyourlightson/sprig, craftcms/commerce) all use lowercase namespace segments. Re-asked.

### Q3' — namespace casing (after Craft research)

| Option | Description | Selected |
|--------|-------------|----------|
| Lowercase dirs, match Craft (Recommended) | src/doctor/. Idiomatic Craft, consistent with root namespace and v1. | ✓ |
| StudlyCaps dirs anyway | Non-idiomatic for Craft. | |

**User's choice:** Lowercase dirs, match Craft.

### Q4 — console controllers physical home

| Option | Description | Selected |
|--------|-------------|----------|
| Flat src/console/ (Recommended) | All console controllers together. Stage dirs hold services/handlers/models. Single controllerNamespace works out of the box. | ✓ |
| Inside stage dir | src/doctor/DoctorController.php, etc. Requires Yii controllerMap (multiple namespaces). | |

**User's choice:** Flat src/console/.

---

## State table schema

### Q1 — schema choice

| Option | Description | Selected |
|--------|-------------|----------|
| v1.x schema verbatim (Recommended) | Match v1's Install.php byte-for-byte: source / sourceKey / targetType / targetId / targetUid / siteId / meta / dateCreated / dateUpdated. UNIQUE (source, sourceKey, siteId). | ✓ |
| REQUIREMENTS.md schema | 5 columns: legacy_class / legacy_id / craft_id / migrated_at / status. Cleaner, but breaks v1→v2 in-place upgrade unless we ship a data-migration step. | |
| v1 schema + status column | Hybrid; status may end up unused if it really belongs in mapping.yaml. | |

**User's choice:** v1.x schema verbatim.
**Notes:** Resolves contradiction between REQUIREMENTS.md FND-02 (5 different columns listed) and PROJECT.md Key Decisions ("kept verbatim"). v1.x rehearsal hosts win — they have rows in this shape. Both planning docs need correction.

### Q2 — schemaVersion

| Option | Description | Selected |
|--------|-------------|----------|
| Declare 2.0.0 (Recommended) | Match v1's current schemaVersion. No migrate/up runs on swap-in. | |
| Reset to 1.0.0 | Treat v2 as a fresh plugin. Cleaner mental model for clean rewrite. | ✓ |

**User's choice:** Reset to 1.0.0.
**Notes:** Has a wrinkle for v1.x→v2 swap-in hosts (declared 1.0.0 < installed 2.0.0). Flagged for planner research; UPGRADING.md may need a `project-config/sync` step.

### Q3 — legacy migration files

| Option | Description | Selected |
|--------|-------------|----------|
| Only Install.php (Recommended) | Single migration file. v1's m000000_000000_install_migration_state.php and m260425_000000_upgrade_to_v2.php are v1-internal archaeology. | ✓ |
| Carry forward v1's legacy migration files | Byte-level continuity at the cost of clean-rewrite clarity. | |

**User's choice:** Only Install.php.

---

## Legacy DB wiring

### Q1 — connection registration mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Register as 'legacyDb' at boot (Recommended) | If no 'legacyDb' app component exists, Plugin::init() builds one from env vars. v1.x hosts that already declare legacyDb keep their declaration. Greenfield hosts get the plugin's wiring. LegacyDbService unchanged. | ✓ |
| Lazy-construct inside service | LegacyDbService holds its own private Connection. Cleanest decoupling but ignores any pre-existing legacyDb component. | |
| New private handle 'kunstmaanLegacyDb' | Avoid any collision; old legacyDb becomes unused. | |

**User's choice:** Register as 'legacyDb' at boot.

### Q2 — env var naming

| Option | Description | Selected |
|--------|-------------|----------|
| CRAFT_LEGACY_DB_* mirroring Craft (Recommended) | SERVER, PORT, DATABASE, USER, PASSWORD, CHARSET, TABLE_PREFIX. Driver hardcoded to mysql. | ✓ |
| Single DSN env var | Compact .env, harder to override pieces. | |
| KUNSTMAAN_DB_* prefix | Plugin-prefixed namespace. | |

**User's choice:** CRAFT_LEGACY_DB_* mirroring Craft.

### Q3 — Settings vs env in Phase 1

| Option | Description | Selected |
|--------|-------------|----------|
| Settings model wired with env fallback (Recommended) | Settings.php is canonical source; each field defaults to its env var. config/kunstmaan-migrator.php overrides work today; CP form plugs in for Phase 4 with no refactor. | ✓ |
| Env-only in Phase 1 | Settings stub; LegacyDbService reads env directly. Phase 4 retrofits Settings. | |

**User's choice:** Settings model wired with env fallback.

---

## Settings + doctor edges

### Q1 — Settings model field set in Phase 1

| Option | Description | Selected |
|--------|-------------|----------|
| Only what Phase 1 actually reads (Recommended) | Legacy DB params + anthropicApiKey only. Phase 2-4 add fields when those phases need them. | |
| Full v2 surface upfront | All v2 settings declared in Phase 1 (DB + Anthropic + LLM model/timeout + mappingPath + filters + dryRunDefault). | ✓ |
| Only Anthropic + DB; defer mappingPath | Effectively identical to option 1. | |

**User's choice:** Full v2 surface upfront.
**Notes:** Trades some dead fields in Phase 1 for a stable Settings shape from day one — Phase 4 doesn't have to refactor the model.

### Q2 — doctor mapping check in Phase 1

| Option | Description | Selected |
|--------|-------------|----------|
| Skip the check entirely in Phase 1 (Recommended) | 3 honest checks now; mapping-file check lands in Phase 2 with the loader/validator. CONN-03 amended. | ✓ |
| Soft INFO line | Doctor probes path existence, prints INFO not FAIL when absent. | |
| Stub that always returns OK with TODO | Maintains 4-check shape but feels dishonest. | |

**User's choice:** Skip the check entirely in Phase 1.

### Q3 — doctor and storage/migration/

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-create on first doctor run (Recommended) | mkdir + chmod 0755 if missing, then verify write perm. Side-effecting but only creates a known-good directory under storage/. | ✓ |
| FAIL if missing, instruct operator | Pure check, no side effects. | |
| Auto-create on plugin install | Install.php creates the directory; doctor only verifies. | |

**User's choice:** Auto-create on first doctor run.

### Q4 — CI workflow shape

| Option | Description | Selected |
|--------|-------------|----------|
| GitHub Actions, single job, PHP 8.3 (Recommended) | composer validate --strict + composer install + composer test. ubuntu-latest. Single PHP version. | ✓ |
| Matrix PHP 8.3 + 8.4 | Doubles CI time; few tests in Phase 1. | |
| Just composer scripts, no CI workflow | Contradicts FND-05. | |

**User's choice:** GitHub Actions, single job, PHP 8.3.

---

## Claude's Discretion

Items where the user said "you decide" or that are pure implementation detail downstream agents handle:

- Settings model property typing (PHP property types vs Craft Model `rules()`).
- `storage/migration/` exact resolution (`Craft::$app->path->getStoragePath()` etc.).
- Test file naming and `tests/bootstrap.php` shape.
- Placeholder `settingsHtml()` template content for Phase 1.
- Whether `LegacyDbService` is a Yii Component or plain class with constructor injection.
- README.md / UPGRADING.md scope (minimal Phase 1; full content lands Phase 5).

## Deferred Ideas

- Multiple PHP versions in CI matrix (deferred until a real driver appears).
- Full unit suite + characterization fixtures + plugin-load smoke (Phase 5 / TST-01..04).
- CP Settings UI form (Phase 4 / CFG-01).
- Rector / Deptrac dev tooling (dropped from v2 composer.json).
- Driver abstraction for legacy DB (hardcoded mysql).
