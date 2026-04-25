# Phase 2: Schema, Mapping & Filters - Context

**Gathered:** 2026-04-25
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 2 builds the **mapping authoring surface** plus the **cross-cutting filter and locale machinery** that every subsequent stage depends on. By the end of Phase 2 an operator can:

1. Run `kunstmaan-migrator/analyze` against a Kunstmaan dump and end up with:
   - `storage/migration/schema-dump.json` (every legacy table + column + fillRate + sqlType + samples)
   - `storage/migration/REPORT.md` (human-readable analyze summary, including the paste-ready `sites:` block when locales are unmapped)
   - A single `mapping.yaml` populated by 9 deterministic heuristics first, then Anthropic Haiku for residuals, with each row carrying `status: proposed | accepted | dropped | needs-review`.
2. Run `kunstmaan-migrator/map` as an interactive rubber-stamp loop walking every `proposed` / `needs-review` row in mapping.yaml, persisting decisions atomically per keypress.
3. Be hard-blocked by `migrate --live` until every data-bearing legacy column has a final mapping decision (`accepted` or `dropped`); be soft-warned by `migrate --dry-run`.
4. Be soft-warned by a `mapping-audit` pass that compares mapping.yaml's `(targetEntryType, targetHandle)` references against the live Craft FieldLayout, with findings emitted to console + `MAPPING-AUDIT.md`.
5. Have Kunstmaan locales auto-detected from `kuma_node_translations.lang` and a `LocalePreflight` service that hard-FAILs every legacy-reading command on any unmapped locale.
6. Have a `MigrationFilters` value object (entities allow-list, locales subset, `--since=YYYY-MM-DD`) constructed by every command's `beforeAction()` and threaded through extract/transform/load/verify stages from the moment those stages exist (Phase 3+).

**Out of scope for Phase 2** (deferred):
- Extract / transform / load / finalize action *bodies* (Phase 3). Phase 2 only ships the `MigrationFilters` value object plumbing — the stages that consume it land in Phase 3.
- SEOmatic / Retour adapters, the `verify` parity gate (Phase 4).
- The CP Settings page UI (Phase 4 / CFG-01) — Settings model is already populated from Phase 1 / D-15.
- Transform-stage characterization fixtures (Phase 5 / TST-02).
- `--max-per-entity=N` filter cap — **dropped from FILT-01** per operator instruction (see D-12 below).
- `--force-reanalyze` flag and `CONFLICTS.md` sidecar for analyze re-runs — deferred (D-04 captures the simpler skip-existing default).
- Per-mapping `meta.sinceColumns` override for `--since` — deferred (D-11 captures the column-presence default).

</domain>

<decisions>
## Implementation Decisions

### mapping.yaml shape & status semantics

- **D-01:** Top-level structure of `mapping.yaml` is a **flat `proposals:` list**. No grouping by entryType. No two-level `meta:` block in v1.0. Each row carries the canonical v1 wire shape (port from `MappingDraftWriter::buildDraftPayload`):

  ```yaml
  proposals:
    - table: kuma_news_page
      column: body_richtext
      targetEntryType: newsArticle
      targetHandle: body
      handler: ckeditor
      confidence: high
      rationale: 'auto-match: sqlType=LONGTEXT → richtext field'
      fillRate: 0.94
      sqlType: LONGTEXT
      samples: ['<p>...</p>', '<h2>...</h2>', '<p>...</p>']
      status: accepted
  ```

  Identifying tuple is `(table, column, targetEntryType)`. The flat shape matches v1's `MappingDraftWriter` byte-for-byte and is the easiest to PR-diff.

- **D-02:** **Confidence-tier → status mapping** (set during analyze; mutated only by `map` rubber-stamp loop or `--auto-accept-high`):

  | Source | Confidence | Initial status |
  |---|---|---|
  | Heuristic | high | `accepted` |
  | Heuristic | medium (Dutch alias only) | `proposed` |
  | LLM (Anthropic) | high | `proposed` |
  | LLM (Anthropic) | medium / low | `needs-review` |
  | Heuristic (zero fill rate) | n/a | `dropped` |

  Heuristics get more trust than the LLM because they're deterministic and reviewable. Only the zero-fill heuristic auto-drops; everything else lands in some operator-visible status. `--auto-accept-high` (CLI flag on `analyze`) promotes LLM-high `proposed` rows directly to `accepted` for non-interactive CI/re-run paths (matches MAP-05 wording).

- **D-03:** **Drops record their reason in the existing `rationale` field**. No separate `dropReason`/`dropSource` field on the row schema. Rationale is overloaded (it's both "why this target field" for accepted/proposed rows and "why we dropped" for dropped rows), but the overloading is acceptable: the row's `status` already disambiguates the reading.

  Drop-reason vocabulary in rationale (free text, not enum, but conventional):
  - `'fill-rate is 0 — no data in source'` (heuristic auto-drop)
  - `'no Craft target — operator-decided drop in map loop'` (operator)
  - `'LLM rejected — no semantically reasonable target'` (LLM-rejected)

- **D-04:** **Re-run `analyze` is skip-existing**. Existing rows in mapping.yaml are preserved verbatim (operator decisions are sacred). Only newly-discovered `(table, column, targetEntryType)` tuples are appended as `proposed`. CLAUDE.md is explicit: "Existing rows are never overwritten without an explicit operator action" (MAP-04). No `--force-reanalyze` flag in v1.0; no `CONFLICTS.md` sidecar — these are deferred.

  The merge driver is the v1 `MappingDraftReader` semantics, ported (303 LOC reference). Index existing rows by the `(table, column, targetEntryType)` tuple; new proposals only land if the tuple is absent.

### `map` rubber-stamp UX (greenfield in v2 — v1 had no equivalent)

- **D-05:** **Row presentation is a compact one-screen block** rendered for each `proposed` / `needs-review` row, fitting 80×24 terminals. Modeled after `git rebase -i` / `git add -p` ergonomics:

  ```
  [42/127] kuma_news_page.body_richtext
  ─────────────────────────────────────────────────────────
  Proposed: newsArticle.body  (handler: ckeditor, confidence: high)
  Rationale: auto-match: sqlType=LONGTEXT → richtext field
  Fill rate: 94%        SQL type: LONGTEXT
  Samples:
    1. <p>Lorem ipsum dolor sit amet, consectetur adipi…
    2. <h2>Bedrijfsnieuws</h2><p>De afgelopen week heef…
    3. <p>Voor meer informatie kunt u contact opnemen v…
  ─────────────────────────────────────────────────────────
  [a]ccept  [d]rop  [r]emap  [s]kip  [q]uit:
  ```

  Sample values truncated to 60 chars each; max 3 samples shown. The progress counter `[42/127]` references rows in `{proposed, needs-review}` only (counts of accepted/dropped are not part of the loop's denominator).

- **D-06:** **`[r]emap` is a two-step picker**. First prompt: handler enum:

  ```
  Handler? [a]sset / [c]keditor / [d]ate / [e]mail / [l]ink /
           [m]atrix / [p]lain / [r]elation / [u]rl / [b]ack
  ```

  Second prompt: numbered list of Craft fields on the target entry type, **filtered to the chosen handler classification**:

  ```
  Pick a target handle for newsArticle (asset fields):
    1) heroImage
    2) ogImage
    3) gallery
    [t]ype manually  [b]ack
  >
  ```

  `[t]ype manually` is the free-text fallback for the rare case the picker doesn't list the operator's intended handle (e.g. fields from a recent project-config sync). Free-text input is validated against the live `Craft::$app->fields->getLayoutByType(EntryType::class)` before persistence — invalid handles are rejected with the available-handles list re-shown.

- **D-07:** **Atomic per-decision write after every keypress**. Each `[a]` / `[d]` / `[r]` press immediately rewrites mapping.yaml via the v1 `MappingDraftWriter::writeAtomic` pattern (tmp file + `rename()`). Operator can Ctrl+C at any point and lose nothing. Aligns with CLAUDE.md `atomic-always-on`. Disk I/O cost is negligible (mapping.yaml is hundreds of KB; rename is microseconds).

  `[s]kip` does **not** mutate the row's status — it just advances the loop. Skipped rows resurface in the next `map` invocation (D-08).

- **D-08:** **`map` is stateless across invocations**. mapping.yaml is the only state. Each `map` invocation:
  1. Loads mapping.yaml.
  2. Iterates rows where `status ∈ {proposed, needs-review}` in file order.
  3. Prompts the operator for each.
  4. Applies the keypress: `[a]` → `status: accepted`; `[d]` → `status: dropped` (rationale prompt: free-text reason); `[r]` → handler+handle picker (D-06); `[s]` → no mutation, advance; `[q]` → exit cleanly.

  Operators can run, walk away, come back next day, run again — the loop picks up exactly where it left off because the file is always current. No `.map-session.json` sidecar.

  `--auto-accept-high` (CLI flag on `map`) non-interactively promotes every `status: proposed` row whose `confidence == high` directly to `accepted`, then exits. Mirrors the `analyze --auto-accept-high` behavior so operators get the same flag at both write sites.

### MigrationFilters scope & semantics (v2 redesign — NOT v1's MigrationFilters model)

> **Important:** v1's `lameco\kunstmaanmigrator\models\MigrationFilters` (48 LOC, post-Craft filtering: `includeDeleted`, `includeOffline`, `includeDrafts`) is the wrong shape for v2. v2 redesigns the value object for **legacy-side scoping**. The v1 file is reference-only.

- **D-09:** **`--entities` allow-list filters on Kunstmaan source classes**, matched against the `kuma:NewsPage`-style identifier in mapping.yaml's `table` column (or the source-class form derived from it). Operator thinks in legacy terms when scoping a rehearsal ("just news first"). Multiple Kunstmaan classes can collapse into a single Craft entry type; the filter is the granular legacy-side dimension.

  Surface: CLI `--entities=NewsPage,EventPage`; Settings `defaultEntities: [NewsPage]`; value-object property `entities: list<string>` (empty list = unbounded).

- **D-10:** **CLI flag overrides Settings::default* per-filter**; unspecified flags fall through to Settings. Empty-string CLI value clears the default for that one filter. Each filter is independent (you can override `--entities` while leaving `--locales` on the Settings default). This is the operator-friendly shape: pin team-wide defaults in `config/kunstmaan-migrator.php`, override per-invocation as needed.

- **D-11:** **`--since=YYYY-MM-DD` uses column-presence detection**. At extract time, for each source row, check the source row's column list:
  - If a column named `date` is present → gate the row by `date >= --since`.
  - If absent → the row passes through unaffected (the filter is a no-op for that source).

  The `date` column is the Kunstmaan `AbstractArticlePage` convention (per operator domain insight 2026-04-25): article-style pages (news, blog, events) extend `AbstractArticlePage` which provides a `date` property; non-article pages (RootPage, LandingPage, OverviewPage) have no semantic publish date. Column-presence detection means we don't need PHP class introspection of legacy classes that aren't in our codebase — we only have the SQL dump.

  No WARN line when `--since` is set on a source without a `date` column (silent no-op is fine; operators understand that scope filters are selective). No per-mapping override (`meta.sinceColumns: { kuma_news_page: published_at }`) in v1.0 — deferred until a real driver appears.

- **D-12:** **`--max-per-entity=N` is DROPPED from v1.0 scope** (operator instruction, 2026-04-25). The cap was named in `FILT-01` and ROADMAP Phase 2 success criterion 5; both need patching when this phase ships:
  - REQUIREMENTS.md FILT-01: drop the `--max-per-entity=N` clause.
  - ROADMAP.md Phase 2 success criterion 5: drop `--max-per-entity=` from the four-flag list (becomes three).
  - The `MigrationFilters` value object Phase 2 ships with **three** filters: `entities`, `locales`, `since`. Not four.

  Rationale: rehearsal scoping is already covered by `--entities` (single class) + `--since` (recent-only window). The cap was speculative scope. Roadmap as a deferred NEXT-* item if a real driver appears.

- **D-13:** **Filters apply uniformly through every stage** (FILT-02). The `MigrationFilters` value object is constructed once per CLI invocation by a shared `beforeAction()` helper on a base controller (or a `FilterFactory` service), reading the merged Settings + CLI args. The value object is then injected into every stage service (extract / transform / verify) so a row excluded at extract is also absent from verify counts. Phase 2 ships only the value object + the `beforeAction()` factory + the CLI flag wiring; Phase 3 stages consume it. **All five top-level CLI commands** (`doctor`, `analyze`, `map`, `migrate`, `verify`) accept the three flags (FILT-03 amended per D-12 — three flags, not four).

  `doctor` accepts the flags but ignores them (doctor doesn't read legacy data, so filters are a no-op there). `analyze` uses them to scope schema-dump and proposal generation. `map` uses them to scope which `proposed`/`needs-review` rows it walks. `migrate` and `verify` consume them in Phase 3+.

### Coverage gate, mapping-audit & locale preflight

- **D-14:** **Coverage gate definition (MAP-06):** a "data-bearing legacy column" is **every column in `schema-dump.json` with `fillRate > 0` AND not in the structural-ignore list**. The structural-ignore list ships as a constant in the coverage service:

  ```php
  // Kunstmaan structural columns we never migrate as data.
  private const STRUCTURAL_IGNORE = [
      'id', 'parent_id', 'lft', 'rgt', 'lvl', 'tree_root',
      'created', 'updated', 'createdBy', 'updatedBy',
      'internal_name', 'discr', 'public', 'hidden_from_nav',
      'children_index', 'sequencenumber', 'ref',
      // ... (researcher to flesh out from v1 brownfield + Kunstmaan core schema)
  ];
  ```

  A column is "covered" when there is at least one mapping.yaml row with matching `(table, column)` and `status ∈ {accepted, dropped}`. Rows with `status ∈ {proposed, needs-review}` do not count as covered.

- **D-15:** **Gate behavior is the same check, different exit semantics**:
  - `migrate --live`: coverage verdict treated as a hard fail. Exit 1, no Craft writes if any data-bearing column lacks a final decision.
  - `migrate --dry-run`: same verdict surfaced as console WARN lines, dry-run continues so the operator sees what would happen if coverage were fixed.

  No `--skip-coverage` escape hatch in v1.0 (operators clear the gate by marking columns `dropped` in mapping.yaml — the right mechanism). Coverage check lives in a single `CoverageAuditor` service called by both code paths.

- **D-16:** **Mapping-audit drift findings (MAP-07):** runs as part of `analyze` and as a precondition of `migrate --live`. Output is **console WARN lines + `storage/migration/MAPPING-AUDIT.md`** artifact. Default behavior is **warn-only** (doesn't block `analyze`). `--audit-strict` flag (on `analyze`) elevates findings to fail-state; `migrate --live` always runs the audit in `--audit-strict` mode (a Craft FieldLayout drift means the migration would write to a phantom field — that's a hard fail).

  Findings are structured: `(table, column) → (targetEntryType, targetHandle)` references that don't resolve in the live `Craft::$app->fields->getLayoutByType(EntryType::class)`, plus handler/classification mismatches (e.g. row says `handler: ckeditor` but Craft field is `plainText`). Port the rule set from v1 `MappingValidator.php` (647 LOC) — that's the canonical drift-detection reference.

- **D-17:** **Locale auto-detect + preflight:**
  - **LOC-01 detection:** `analyze` queries `SELECT DISTINCT lang FROM kuma_node_translations` and emits a paste-ready Craft `sites:` block in `REPORT.md`:

    ```markdown
    ## Locales

    Detected Kunstmaan locales: nl, fr, en, de

    Currently mapped in Settings::defaultLocales: nl

    Unmapped: fr, en, de

    Add these to your Craft `config/sites.php` (or set Settings::defaultLocales to map them):

    ```php
    return [
        'nl' => ['language' => 'nl-BE', 'baseUrl' => 'https://example.com/'],
        'fr' => ['language' => 'fr-BE', 'baseUrl' => 'https://example.com/fr/'],
        // ...
    ];
    ```

    Re-run analyze after the sites are mapped.
    ```

  - **LOC-02 preflight:** every legacy-reading command (`analyze`, `map`, `migrate`, `verify`) calls a `LocalePreflight::ensure()` service first. If any detected Kunstmaan locale lacks a Craft site mapping (in `Settings::defaultLocales` or a future mapping.yaml `meta.locales` block), the command FAILs immediately, listing the unmapped locales and the same paste-ready block. **No silent default-locale fallthrough** (PROJECT.md is a hard rule).

  Preflight is gated on `--locales` being unset OR set to a value that includes an unmapped locale. If `--locales=nl` is explicitly set and only `nl` is mapped, preflight passes (operator-scoped run is fine).

### Claude's Discretion

- **`schema-dump.json` exact format**: field order, whether to nest fillRate stats per-column or per-table, whether to include row counts. Pick a shape that the heuristic + LLM proposal pipeline can consume cheaply. Use v1's `AnalyzeController::actionSchema()` as the reference and adjust if Phase 3 needs a different shape.
- **`REPORT.md` content**: counts (per-table row counts, per-column fillRate distribution), the locales block (D-17), any heuristic + LLM proposal summary (counts per status). Researcher to flesh out the section list. Aim for one screenful per section.
- **CLI controller layout**: `MapController` (new — Phase 2 introduces) with `actionIndex()` as the rubber-stamp loop. `AnalyzeController` (new — Phase 2 introduces) with `actionIndex()` as the analyze entrypoint. Both controllers live in `src/console/` per Phase 1 / D-03.
- **Service layout under src/**: most likely `src/analyze/` (HeuristicProposer port, LlmClassifier port, SchemaDumper, ReportBuilder), `src/mapping/` (MappingFile reader/writer with status-on-row, CoverageAuditor, MappingAuditor), `src/filter/` (MigrationFilters value object, FilterFactory). Researcher + planner to confirm the directory shape.
- **Anthropic-specific knobs**: timeout default (v1 was 60s), batch size (v1 was 10 per chunk), inter-batch sleep (v1 was 20s — a rate-limit hedge). Keep v1's defaults unless there's a reason to change.
- **Heuristic ordering**: port v1's nine in v1's order (zero-fill → exact name → *_id → TEXT → *_image → *_date → *_url → *_email → Dutch alias). Don't reshuffle without a reason.
- **`--no-ai` flag behavior**: v1's analyze had `--no-ai` to skip the LLM call when the API key was unavailable. Useful for offline development and CI where ANTHROPIC_API_KEY isn't set. Keep it; semantics: residual columns that would have gone to LLM land as `status: needs-review` with rationale `'LLM call skipped (--no-ai or no API key)'` instead of getting Anthropic-routed proposals.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project planning (this repo)

- `CLAUDE.md` — locked architectural ground rules: single mapping.yaml with per-row status, optional adapters, plugin-owned legacy DB, CLI-only operator surface, atomic-always-on, runtime-zero-AI, NeverProductionTrait. **Hard constraints.**
- `.planning/PROJECT.md` — full vision, constraints, Key Decisions table. The "Context" section's notes on what to port verbatim from v1 vs redesign are load-bearing for Phase 2 (especially "AI proposal pipeline with 9 deterministic heuristics first, then Anthropic Haiku for residuals" — port without redesign).
- `.planning/REQUIREMENTS.md` §"Schema + Mapping (MAP)" — MAP-01..07 (Phase 2 mapping requirements).
- `.planning/REQUIREMENTS.md` §"Filtering (FILT)" — FILT-01..03 (Phase 2 filter requirements). **D-12 patches FILT-01 to drop --max-per-entity.**
- `.planning/REQUIREMENTS.md` §"Locale handling (LOC)" — LOC-01..02 (Phase 2 locale requirements).
- `.planning/REQUIREMENTS.md` §"Out of Scope" — defines the v1.0 scope wall.
- `.planning/ROADMAP.md` §"Phase 2: Schema, Mapping & Filters" — 5 success criteria the verifier checks against. **D-12 patches success criterion 5 to drop --max-per-entity (three flags, not four).**
- `.planning/phases/01-foundation-connectivity/01-CONTEXT.md` — Phase 1 decisions. D-15 (Settings model declares Phase 2-4 fields upfront — `llmModel`, `llmTimeout`, `mappingPath`, `defaultEntities`, `defaultLocales`, `defaultSince`, `defaultMaxPerEntity`, `dryRunDefault`); D-23 (NeverProductionTrait pattern); D-20 (gate-first idiom for controller actions).

### v1.x brownfield reference (read-only — `~/Sites/craft-kunstmaan-migrator/`)

**Port near-verbatim:**
- `src/bridge/services/HeuristicProposer.php` (407 LOC) — the 9 deterministic heuristics + Dutch alias map. Port verbatim under v2's flatter namespace; the algorithm is correct.
- `src/bridge/services/LlmClassifier.php` (481 LOC) — Anthropic Haiku batch caller. Port; adjust to emit rows that map directly to v2's confidence-tier → status (D-02). Keep v1's chunk size (10), inter-batch sleep (20s), timeout (60s) defaults unless there's a reason.

**Port with adjustments for status-on-row format:**
- `src/bridge/services/MappingDraftReader.php` (303 LOC) — merge semantics reference. Port the indexing strategy (`(table, column, targetEntryType)` tuple as identity) for D-04 skip-existing.
- `src/bridge/services/MappingDraftWriter.php` (384 LOC) — atomic-write reference (`writeAtomic` tmp+rename). Drop the v1 four-bucket structure (`mapping.yaml`, `.draft`, `.drops`, `DESIGN-GAPS.md`) — v2 has one file with status-on-row.
- `src/bridge/mapping/MappingLoader.php` (269 LOC) — runtime mapping reader. Phase 2 ships a v2 reader that understands `status` per row; Phase 3 consumes it.
- `src/bridge/mapping/MappingValidator.php` (647 LOC) — drift-detection rule set. Port the rules for the v2 `MappingAuditor` (D-16), simplifying for the single-file format.

**Replace with v2 status-on-row routing (do NOT port):**
- `src/bridge/services/ProposalRouter.php` — replace with status-assignment logic per D-02. v2 has no four-way bucket; routing is just "set the status field on the row."

**Reference for shape, do NOT port wholesale:**
- `src/bridge/console/controllers/AnalyzeController.php` (2138 LOC) — v1's analyze had sub-actions (`preflight`, `seed-extract`, `detect-locales`, `coverage`, `schema`, `propose-mappings`, `apply-proposals`, `mapping-audit`, `relation-sample-check`). v2 collapses to a single `AnalyzeController::actionIndex()` entrypoint per PROJECT.md operator workflow (~5 commands surface). Read for algorithmic detail and for the schema-dump format; do not port the multi-action shape.
- `src/models/MigrationFilters.php` (48 LOC) — **DIFFERENT scope from v2**. v1 filters Craft entries post-migration (includeDeleted/Offline/Drafts); v2 filters legacy-side rows (entities/locales/since). Reference only.

**Do NOT carry forward:**
- `src/craft/utilities/MappingDraftUtility.php` and `src/craft/controllers/MappingDraftController.php` — v1's CP utility for reviewing mapping.yaml.draft. PROJECT.md is explicit: "No CP runner utility, no inline mapping editor in the CP. CLI is canonical." v2 has only the `map` CLI loop.

### Doc patches required when this phase ships

- **REQUIREMENTS.md FILT-01**: drop the `--max-per-entity=N` clause from the value-object surface description.
- **ROADMAP.md Phase 2 success criterion #5**: drop `--max-per-entity=` from the flag list (becomes three flags: `--entities=`, `--locales=`, `--since=`).
- **PROJECT.md**: no changes needed (the v1.0 headline scope already says "Filter spec from day one (entity allow-list, locale subset, `--since` date floor)" without listing `--max-per-entity`).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable assets (port near-verbatim from v1)

- **`HeuristicProposer`** (407 LOC): the 9 deterministic heuristics + Dutch alias map. Algorithm is correct as-is. Port under v2's flatter namespace (`lameco\kunstmaanmigrator\analyze\HeuristicProposer` or similar — researcher to confirm the slot).
- **`LlmClassifier`** (481 LOC): Anthropic Haiku batch caller. Port with status-on-row output adjustments.
- **`MappingDraftWriter::writeAtomic`** (atomic tmp+rename): the I/O pattern for `map`'s per-keypress persistence (D-07). Port verbatim.
- **`MappingValidator`** drift rule set (647 LOC): port for the new `MappingAuditor` (D-16), simplifying for the single-file format.
- **`MappingDraftReader`** merge indexing (303 LOC): port the `(table, column, targetEntryType)` indexing for D-04 skip-existing.

### Established patterns (v1 + Phase 1) to follow

- **Console controller idiom (Phase 1 / D-03)**: controllers live in `src/console/` flat, single `controllerNamespace = lameco\kunstmaanmigrator\console`. Phase 2 adds `AnalyzeController` and `MapController` here.
- **NeverProduction gate (Phase 1 / D-20)**: every legacy-reading action's first statement is `if (($gate = $this->enforceNeverProduction()) !== null) return $gate;`. Applies to AnalyzeController, MapController, and any service entrypoint that touches legacy DB.
- **Atomic write pattern (CLAUDE.md)**: every mapping.yaml mutation goes through tmp+rename. Operator can Ctrl+C anywhere without corrupting state.
- **Plain-text OK/FAIL output (Phase 1 / D-19)**: ANSI colors via `Console::FG_GREEN` / `Console::FG_RED` / `Console::FG_CYAN`. Two-space indent. Exit 0 on success, 1 on any FAIL.
- **Settings-then-env fallback (Phase 1 / D-12)**: Phase 2 reads `Settings::defaultEntities`, `Settings::defaultLocales`, `Settings::defaultSince` (and `mappingPath` for the file location). The `??=` env-fallback in `Settings::init()` already handles the env overrides.

### Greenfield items (no v1 analog — Phase 2 builds from scratch)

- **`map` CLI rubber-stamp loop**: v1 had no equivalent (only the `MappingDraftUtility` CP page and `analyze/apply-proposals` non-interactive). The compact-block UX (D-05), two-step `[r]emap` picker (D-06), atomic-per-keypress persistence (D-07), and stateless-resume (D-08) are all new.
- **Status-on-row mapping.yaml format**: v1 had four files (`mapping.yaml` + `.draft` + drops timestamped + DESIGN-GAPS.md). v2 has one file with `status` on each row. The reader and writer are reshaped accordingly.
- **`MigrationFilters` value object (legacy-side scoping)**: v1's same-named class is post-Craft filtering. v2 redesigns from scratch.
- **`LocalePreflight` service**: v1's `AnalyzeController::actionDetectLocales` is the detection half; the gating preflight is new (LOC-02 hard-FAIL semantics).
- **`MAPPING-AUDIT.md` artifact**: v1 logged drift findings to console only; v2 ships them as a persistent artifact under `storage/migration/`.
- **Doctor's mapping check (deferred from Phase 1 / D-17)**: Phase 1 deferred the mapping-file health check. Phase 2 adds it back to `DoctorController::actionIndex()` — a fourth check that validates `Settings::mappingPath` resolves, the file is readable, and parses as YAML with the expected top-level `proposals:` key.

### Integration points

- **Plugin::config()**: Phase 2 adds new components — at least `LegacyDbService` is already registered (Phase 1). Phase 2 components likely include `HeuristicProposer`, `LlmClassifier`, `MappingFile` (reader+writer), `MigrationFilters` factory, `LocalePreflight`. Researcher to confirm the registry shape.
- **`controllerNamespace` (Phase 1 / D-03)**: AnalyzeController + MapController land in `src/console/` and resolve via the existing namespace switch.
- **`config/kunstmaan-migrator.php`**: Settings model already declares `defaultEntities` / `defaultLocales` / `defaultSince` / `mappingPath` / `llmModel` / `llmTimeout` (Phase 1 / D-15). Phase 2 starts reading them.
- **`storage/migration/`**: Phase 1 / D-18 auto-creates the directory in `doctor`. Phase 2 writes `schema-dump.json`, `REPORT.md`, `MAPPING-AUDIT.md` here.

</code_context>

<specifics>
## Specific Ideas

- **AbstractArticlePage convention drives `--since`** (operator domain insight, 2026-04-25): Kunstmaan article-style pages (news, blog, events) extend `AbstractArticlePage` which provides a `date` property. Non-article pages (`RootPage`, `LandingPage`, `OverviewPage`) do not. Column-presence detection on the source row's `date` column (D-11) is the right shape because we don't have the legacy PHP class hierarchy in our codebase — only the SQL dump.
- **`--max-per-entity` is dropped from v1.0 scope** (operator decision, 2026-04-25). FILT-01 and ROADMAP Phase 2 success criterion 5 patch when this phase ships. `MigrationFilters` is a three-property value object, not four.
- **Heuristics get more trust than the LLM** (D-02): heuristic-high lands `accepted` directly; LLM-high requires rubber-stamp (`proposed`). This is the trust calibration baked into the status assignment table — deterministic 9-heuristic matches are PR-reviewable in code; LLM proposals are not.
- **Aggressive use of v1's brownfield is the right move for Phase 2.** Five v1 services (HeuristicProposer, LlmClassifier, MappingDraftReader, MappingDraftWriter, MappingValidator) total ~1900 LOC. Most of it ports near-verbatim under v2's flatter namespace. Only `ProposalRouter` (101 LOC, four-way bucketing) gets fully replaced; the rest is mechanical reshaping for the status-on-row format. Phase 2 is largely a reshaping exercise on proven v1 algorithms — not a redesign.
- **`map` is the highest-value greenfield element.** v1 had no rubber-stamp CLI loop. The compact-block UX + two-step picker + atomic-per-keypress + stateless-resume design is what makes the operator workflow promised by PROJECT.md actually pleasant.

</specifics>

<deferred>
## Deferred Ideas

- **`--force-reanalyze` flag**: re-runs `analyze` and regenerates `proposed`/`needs-review`/`dropped` rows from scratch while preserving `accepted` rows. Useful when Craft schema changes mid-rehearsal. Not needed for v1.0; revisit if a real driver appears.
- **`CONFLICTS.md` sidecar for analyze re-runs**: surface the diff between fresh-proposal and existing mapping.yaml row when proposals have drifted. Higher signal but more machinery for an edge case. Punt.
- **Per-mapping `meta.sinceColumns` override** (`{ kuma_news_page: published_at }`): allows non-AbstractArticlePage sources or non-`date`-named columns to participate in the `--since` filter. Not needed for the CQM rehearsal; revisit if a non-standard Kunstmaan project surfaces.
- **`--max-per-entity=N` filter cap**: dropped from v1.0 (D-12). Roadmap as a deferred NEXT-* if rehearsal scoping ever needs cap semantics that `--entities` + `--since` can't cover.
- **Multi-list `--since` column candidates** (`date`, `publishDate`, `published_at`, `datum`): single column (`date`) for v1.0; broaden if multiple Kunstmaan variants surface.
- **`--audit-strict` as the default behavior of `analyze`**: currently `analyze` runs mapping-audit warn-only by default and `--audit-strict` is opt-in. Revisit if operators consistently miss drift findings in the warn flood.
- **LLM provider abstraction**: PROJECT.md captures NEXT-03 — Anthropic-only is the v1 stance. `LlmClassifier` stays Anthropic-specific in Phase 2.
- **Schema-dump format versioning**: `schema-dump.json` may want a `formatVersion` field so future analyze runs can refuse to consume v1.x dumps. Probably YAGNI for v1.0 (no v1.x dumps exist yet); add when a second format ships.
- **`--no-ai` flag short-form** (e.g. `-N`): low priority; long form is fine.

</deferred>

---

*Phase: 02-schema-mapping-filters*
*Context gathered: 2026-04-25*
