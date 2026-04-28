# Phase 8: Taxonomies & AI Proposer Coverage — Pattern Map

**Mapped:** 2026-04-27
**Files analyzed:** 22 (15 existing v2 surfaces extended + 5 new files + 2 doc artifacts)
**Analogs found:** 22 / 22

## File Classification

### Existing v2 surfaces — EXTEND (do not rewrite)

| File | Role | Data Flow | Closest Analog (in same file) | Match Quality |
|------|------|-----------|-------------------------------|---------------|
| `src/analyze/LlmClassifier.php` | proposer | request-response | `proposeNodeClasses` (lines 226-278) | exact |
| `src/source/DoctrineEntityParser.php` | source-parser | transform | own `Doctrine\ORM\Mapping\*` attribute scan | role-match |
| `src/source/KnowledgeBase.php` | KB-renderer | transform | `renderPagePartsMarkdown` (lines 83-141) | exact |
| `src/source/KunstmaanCoreTables.php` | constants | n/a | existing `SEO` / `REDIRECTS` consts (lines 29-30) | exact |
| `src/db/LegacyDbService.php` | load-service / DB | request-response | v1 `extTranslationsFor` (v1 file lines 214-250) | verbatim port |
| `src/compile/MappingCompiler.php` | compiler | transform | `compileImplicitBlocks` (lines 391-560) | exact |
| `src/console/CompileController.php` | CLI | request-response | `implicitBlocksEmitted` counter wiring (lines 198-203) | exact |
| `src/console/AnalyzeController.php` | CLI | request-response | step 6.5 implicit emitter + step 7.5 entity LLM (lines 195-366) | exact |
| `src/console/MigrateController.php` | CLI | request-response | `actionSeo` / `actionRetour` + step 6.5/6.6 SEO/Retour bolt-ons (lines 331-398) | exact |
| `src/console/DoctorController.php` | CLI | request-response | 9 existing checks dispatcher (lines 71-83) | exact |
| `src/mapping/MappingFile.php` | mapping-row | transform | `buildPagePartRow` (lines 135-161) + `buildNodeClassRow` (lines 216-228) | exact |
| `src/mapping/MappingAuditor.php` | auditor | transform | existing finding-kind loop (lines 88-203) | role-match |
| `src/load/AtomicMigrationService.php` | load-service | event-driven | own `migrateOneEntry` per-entry transaction (lines 60-128) | role-match (orchestration site for taxonomies-before-pages) |
| `src/models/Settings.php` | settings | n/a | `seoEnabled` / `retourEnabled` (lines 87-88) + rules entry (line 228) | exact |
| `src/templates/_settings.twig` | template | n/a | (no existing AI H2 group — new section) | role-match |
| `src/Plugin.php` | DI / wiring | n/a | `seoMigrationService` slot + DI fanout (Plugin.php lines 163-317) | exact |

### New files — CREATE

| File | Role | Data Flow | Closest Analog | Match Quality |
|------|------|-----------|----------------|---------------|
| `src/load/TaxonomyMigrationService.php` | load-service | CRUD | `~/Sites/craft-kunstmaan-migrator/src/bridge/load/TaxonomyMigrationService.php` (v1 brownfield, 443 LOC) | verbatim port |
| `tests/integration/load/TaxonomyMigrationTest.php` | test (integration) | event-driven | `tests/integration/transform/TransformImplicitContentTest.php` | exact |
| `tests/unit/compile/MappingCompilerTaxonomiesTest.php` | test (unit) | transform | `tests/unit/compile/MappingCompilerImplicitBlocksTest.php` | exact |
| `tests/unit/compile/MappingCompilerLayoutBlocksTest.php` | test (unit) | transform | `tests/unit/compile/MappingCompilerImplicitBlocksTest.php` | exact |
| `tests/unit/compile/MappingCompilerDataProvidersTest.php` | test (unit) | transform | `tests/unit/compile/MappingCompilerImplicitBlocksTest.php` | exact |

### Doc artifacts — CREATE

| File | Role | Data Flow | Closest Analog | Match Quality |
|------|------|-----------|----------------|---------------|
| `.planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md` | doc | n/a | `.planning/phases/04.1-*/04.1-07-RECONCILIATION.md` (and earlier RECONCILIATIONs cited in CONTEXT D-08) | exact (D-08 mandate) |
| `CHANGELOG.md` | doc | n/a | repo-root file (already shipped Phase 5 / Plan 05-08) — append "Known omissions in v1.0" subsection under v1.0 entry | role-match (extend, don't replace) |

## Pattern Assignments

### `src/analyze/LlmClassifier.php` — extend with three new proposers

**Analog:** `proposeNodeClasses()` lines 226-278 (in same file). Same shape applies to `proposeNonPageEntities()`, `proposeLayoutBlocks()`, `proposeDataProviders()`.

**Method-signature pattern** (lines 226-232):
```php
public function proposeNodeClasses(
    array $pageStructure,
    array $craftEntryTypeHandles,
    string $kbLegacyMd,
    string $kbCraftMd,
    ?callable $onChunk = null,
): array {
    if ($pageStructure === [] || $craftEntryTypeHandles === []) {
        return [];
    }
    $apiKey = (string) (Plugin::getInstance()->getSettings()->anthropicApiKey ?? '');
    if ($apiKey === '') {
        throw new MappingProposalException(
            'ANTHROPIC_API_KEY is not set. Set it in .env or plugin settings, or re-run with --no-ai.',
        );
    }
```

**Chunked call + per-chunk delegation pattern** (lines 260-278):
```php
$chunks = array_chunk($entries, 8);
$chunkTotal = count($chunks);
$all = [];
$i = 0;
foreach ($chunks as $chunk) {
    $i++;
    $startedAt = microtime(true);
    $proposals = $this->proposeNodeClassChunk(
        $chunk, $craftEntryTypeHandles, $kbLegacyMd, $kbCraftMd,
        $client, $apiKey, $model, $timeout,
    );
    $all = array_merge($all, $proposals);
    if ($onChunk !== null) {
        $onChunk($i, $chunkTotal, count($chunk), count($proposals), microtime(true) - $startedAt);
    }
}
return $all;
```

**Mirror three times:**
1. `proposeNonPageEntities(array $entityIndex, array $craftEntryTypeHandles, string $kbLegacyMd, string $kbCraftMd, ?callable $onChunk = null): array` — input is `KunstmaanSourceScanner::scan()['entities']` filtered to non-Page entities (those whose FQCN is not in `pageStructure`). Output rows: `kind=taxonomy` (high) / `kind=taxonomy + status=needs-review` (medium/low) / `kind=column` drop with `reason="not-taxonomy-likely-supporting"` (D-06 confidence-tier ladder; D-32 row shape). Closed set still `craftEntryTypeHandles` (D-02 — same closed-set validation as nodeClass).
2. `proposeLayoutBlocks(array $pageStructure, array $matrixCatalog, string $kbLegacyMd, string $kbCraftMd, ?callable $onChunk = null): array` — heuristic-trigger gated (D-12: only fire when parent entry-type's Matrix catalog has header-shaped or wrap-shaped block). Output: `kind=nodeClass` rows with `headerBlock` / `bodyWrapBlock` / `bodyColumn` filled in (skip-existing).
3. `proposeDataProviders(array $orphanPageParts, array $matrixCatalog, string $kbLegacyMd, string $kbCraftMd, ?callable $onChunk = null): array` — orphan trigger per D-13 (`kuma_page_part_refs` row absent AND source table not joined to `kuma_node_versions`). Output: dataProvider proposals — shape TBD during planning (use `MappingFile::buildDataProviderRow()`).

**Gotcha:** The `MappingProposalException` API-key guard exists in every proposer; do not skip it. The `--no-ai` blanket flag bypasses every `propose*()` call at the controller seam (AnalyzeController line 305: `$skipEntityLlm = $this->noAi || $apiKeyForEntityStep === '' || $pageStructure === [];`). Phase 8's two new gates (`Settings::proposeLayout` + `Settings::proposeProviders` and CLI mirrors `--no-layout` / `--no-providers`) sit BESIDE `--no-ai`, not inside it (D-14).

---

### `src/source/DoctrineEntityParser.php` — extend Gedmo namespace scan

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/schema/DoctrineEntityParser.php` (v1 brownfield) — but note SRC-20 (Phase 4.1) already collapsed the v2 parser to attributes-only.

**What to add (D-10):** A second use-map / FQCN resolver branch that scans the `Gedmo\Mapping\Annotation\*` namespace alongside the existing `Doctrine\ORM\Mapping\*` scan. Capture `#[Gedmo\Translatable]` (and the equivalent `Gedmo\Mapping\Annotation\Translatable` short-class hit through the `use` map) on a per-property basis so each `DoctrineColumnInfo` carries a `bool $isGedmoTranslatable` (or analogous flag).

**SRC-20 constraint applies:** Annotations only when they survive in the targeted projects. D-10 says signal #1 is the source attribute (Gedmo `#[Translatable]`), signal #2 is the runtime row in `ext_translations`. Implement the union — the parser surfaces the static signal; `TaxonomyMigrationService::migrate()` consumes the runtime signal.

**Gotcha:** Do NOT reintroduce the v1 annotation parser — `DoctrineEntityParser` is attributes-only after Phase 4.1 / Plan 04.1-06. Gedmo support is added as a second attribute-namespace scan, not as an annotation-parser revival.

---

### `src/source/KnowledgeBase.php` — extend with `renderTaxonomiesMarkdown()`

**Analog:** `renderPagePartsMarkdown()` lines 83-141 (in same file). Mirror exactly.

**Header pattern** (lines 113-118):
```php
$out   = [];
$out[] = '# Kunstmaan Page Parts (deep introspection, generated ' . $now->format(DATE_ATOM) . ')';
$out[] = '';
$out[] = '_All page part types discovered from `kuma_page_part_refs`. Full column, relation and join-table detail._';
$out[] = '_Page parts are reusable: a single type may appear on multiple page types._';
$out[] = '';
```

**LegacyDb-driven discovery pattern** (lines 120-136):
```php
$discoveredPpFqcns = [];
try {
    $ppDbRows = $this->legacyDb->queryAll(
        'SELECT DISTINCT page_part_entityname FROM ' . KunstmaanCoreTables::PAGE_PART_REFS
        . ' WHERE page_part_entityname IS NOT NULL ORDER BY page_part_entityname',
    );
    foreach ($ppDbRows as $r) {
        $fqcn = (string) ($r['page_part_entityname'] ?? '');
        if ($fqcn !== '') {
            $discoveredPpFqcns[] = $fqcn;
        }
    }
} catch (\Throwable $e) {
    $out[] = sprintf('_Could not discover page parts from DB: %s_', $e->getMessage());
    return implode("\n", $out);
}
```

**Mirror as `renderTaxonomiesMarkdown(?array $mapping, DateTimeInterface $now): string`:**
- Walk the `KunstmaanSourceScanner::scan()['entities']` instead of `kuma_page_part_refs` (taxonomies are non-page Doctrine entities — they do NOT have FK rows in `kuma_page_part_refs`).
- For each candidate FQCN: render columns table (reuse `renderTableColumns()` private at line 498), Gedmo-translatable flag if attribute scan found one, sample row count via `legacyDb->queryScalar('SELECT COUNT(*) FROM `' . $sourceTable . '`')`.
- Output is fed to `proposeNonPageEntities()` exactly the way `kbPagePartsEarly` (AnalyzeController line 325) is fed to `proposeNodeClasses()`.

---

### `src/source/KunstmaanCoreTables.php` — add `EXT_TRANSLATIONS` constant

**Analog:** lines 24-30 (in same file).

**Existing pattern**:
```php
public const NODES             = 'kuma_nodes';
public const NODE_TRANSLATIONS = 'kuma_node_translations';
public const NODE_VERSIONS     = 'kuma_node_versions';
public const PAGE_PART_REFS    = 'kuma_page_part_refs';
public const MEDIA             = 'kuma_media';
public const SEO               = 'kuma_seo';
public const REDIRECTS         = 'kuma_redirects';
```

**Add:**
```php
public const EXT_TRANSLATIONS = 'ext_translations';
```

**Gotcha:** The Gedmo table is `ext_translations` (NOT `kuma_ext_translations`) — confirmed by v1 LegacyDbService line 233. It's not Kunstmaan-prefixed because Gedmo Translatable is a generic Doctrine extension that Kunstmaan happens to use.

---

### `src/db/LegacyDbService.php` — restore `extTranslationsFor()` (verbatim port)

**Analog:** v1 `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/db/LegacyDbService.php` lines 214-250 (verbatim — D-08 mandate).

**Verbatim port target** (v1 lines 214-250):
```php
/**
 * @param string|string[] $fqcns
 * @return array<string, array<string, string>>
 */
public function extTranslationsFor(string|array $fqcns, int $id): array
{
    $list = is_array($fqcns) ? array_values($fqcns) : [$fqcns];
    if ($list === []) {
        return [];
    }
    $namedParams = [':foreignKey' => $id];
    $placeholders = [];
    foreach ($list as $i => $fqcn) {
        $key = ':fqcn' . $i;
        $namedParams[$key] = $fqcn;
        $placeholders[] = $key;
    }
    $inClause = implode(',', $placeholders);
    $rows = $this->db()
        ->createCommand(
            "SELECT object_class, locale, field, content FROM ext_translations"
            . " WHERE object_class IN ($inClause) AND foreign_key = :foreignKey",
            $namedParams,
        )
        ->queryAll();
    $result = [];
    foreach ($rows as $row) {
        $locale = (string) $row['locale'];
        $field = (string) $row['field'];
        $content = (string) ($row['content'] ?? '');
        $result[$locale][$field] = $content;
    }
    return $result;
}
```

**Reshape (only where v2 architectural ground rules require):**
- Replace the literal `'ext_translations'` table name with `KunstmaanCoreTables::EXT_TRANSLATIONS` per the v2 convention (LegacyDbService line 119 already uses `KunstmaanCoreTables::NODE_TRANSLATIONS` consistently).
- Preserve named-bind-parameter shape (Yii 2 / PDO positional-index mismatch is a real bug — never use `?` placeholders here).
- Preserve canonical-FQCN-first iteration semantics from the v1 docblock.

**Gotcha:** D-09 says when `ext_translations` is empty, treat the source-locale row as the only locale. That logic lives in `TaxonomyMigrationService::migrate()`, NOT here — `extTranslationsFor()` returns `[]` and the consumer interprets it.

---

### `src/compile/MappingCompiler.php` — add `compileTaxonomies()` private

**Analog:** `compileImplicitBlocks()` lines 391-560 (in same file).

**Method signature pattern** (lines 391-396):
```php
private function compileImplicitBlocks(
    array $proposals,
    array $pageStructure,
    array $existingPageParts,
    array $nodeClasses,
): array {
    $pagePartsOut = [];
    foreach ($existingPageParts as $k => $v) {
        if (is_string($k) && is_array($v)) {
            $pagePartsOut[$k] = $v;
        }
    }
```

**Filter-and-fold loop pattern** (lines 437-493):
```php
foreach ($proposals as $row) {
    if (!is_array($row)) { continue; }
    if (((string) ($row['kind'] ?? '')) !== 'pagePart') { continue; }
    if (((string) ($row['pagePartClass'] ?? '')) !== '__implicit_content__') { continue; }
    if (((string) ($row['status'] ?? '')) !== 'accepted') { continue; }
    // ... build $key, $fieldsOut, validation warnings ...
    // Skip-existing: operator-curated mapping.pageParts wins.
    if (isset($pagePartsOut[$key])) {
        continue;
    }
    $pagePartsOut[$key] = [
        'target' => $blockType,
        'fields' => $fieldsOut,
    ];
    $emitted++;
```

**Caller-site pattern** (lines 327-339):
```php
// Phase 7: implicit-content page-part compilation. Walk accepted kind=pagePart
// rows whose pagePartClass is the synthetic '__implicit_content__' marker
$existingPageParts = (array) ($mapping['pageParts'] ?? []);
[$pagePartsOut, $nodeClasses, $implicitEmitted, $implicitWarnings] =
    $this->compileImplicitBlocks($proposals, $pageStructure, $existingPageParts, $nodeClasses);
ksort($pagePartsOut);
ksort($nodeClasses);
$warnings = array_merge($warnings, $implicitWarnings);
```

**Compile-report counter pattern** (lines 347-357):
```php
'_compileReport' => [
    'nodeClassesEmitted'        => count($nodeClasses),
    'sectionsEmitted'           => count($sections),
    // ...
    'implicitBlocksEmitted'     => $implicitEmitted,
    'warnings'                  => $warnings,
],
```

**Mirror as `compileTaxonomies(array $proposals, array $existingTaxonomies): array` returning `[taxonomiesOut, taxonomiesEmitted, warnings]`:**
- Filter by `kind === 'taxonomy'` AND `status === 'accepted'`.
- Identity key = `fqcn` (D-07 — identity tuple is `(kind, fqcn)`, not the structural-triple page-parts use).
- Output shape per D-07: `{ fqcn, sourceTable, targetSection, targetEntryType, status, confidence, rationale }`. NO nested `fields[]` (D-07 — field-level mapping is inferred from matching `kind=column` rows on the same `sourceTable`, exact convention `nodeClasses` uses).
- Skip-existing: `if (isset($taxonomiesOut[$fqcn])) continue;` — operator decisions sacred (MAP-04).

**Wire counters in `_compileReport`:** Add `taxonomiesEmitted`, `layoutBlocksEmitted`, `dataProvidersEmitted` (Phase 7's `implicitBlocksEmitted` precedent).

**Gotcha:** Mirror also needed for layout-block compile (folds proposed `headerBlock` / `bodyWrapBlock` / `bodyColumn` into `nodeClasses[fqcn]` — same shape as the `compileImplicitBlocks` mutation of `nodeClasses` at lines 495-525).

---

### `src/console/CompileController.php` — surface `taxonomiesEmitted` counter

**Analog:** `implicitBlocksEmitted` counter render (lines 198-203):
```php
$implicitEmitted = (int) ($report['implicitBlocksEmitted'] ?? 0);
if ($implicitEmitted > 0) {
    $this->stdout(sprintf(
        "  OK   compiled %d implicit-content page-part block(s) into mapping.pageParts + nodeClasses.pageBuilderHandle\n",
        $implicitEmitted,
    ), Console::FG_GREEN);
}
```

**Mirror three times:** `taxonomiesEmitted` → `"  OK   compiled %d taxonomy block(s) into mapping.taxonomies"`; `layoutBlocksEmitted` → `"  OK   compiled %d page-builder layout block(s) into mapping.nodeClasses"`; `dataProvidersEmitted` → `"  OK   compiled %d dataProvider block(s) into mapping.dataProviders"`.

---

### `src/mapping/MappingFile.php` — add `buildTaxonomyRow()` helper

**Analog:** `buildPagePartRow()` lines 135-161 + `buildNodeClassRow()` lines 216-228 (in same file).

**Closer fit is `buildNodeClassRow()`** because taxonomies have NO nested `fields[]` (D-07):
```php
public function buildNodeClassRow(array $proposal, string $initialStatus): array
{
    return [
        'kind'            => 'nodeClass',
        'fqcn'            => (string) ($proposal['fqcn'] ?? ''),
        'sourceTable'     => (string) ($proposal['sourceTable'] ?? ''),
        'targetEntryType' => (string) ($proposal['targetEntryType'] ?? ''),
        'targetSection'   => (string) ($proposal['targetSection'] ?? ''),
        'confidence'      => (string) ($proposal['confidence'] ?? 'medium'),
        'rationale'       => (string) ($proposal['rationale'] ?? ''),
        'status'          => $initialStatus,
    ];
}
```

**Mirror as:**
```php
public function buildTaxonomyRow(array $proposal, string $initialStatus): array
{
    return [
        'kind'            => 'taxonomy',
        'fqcn'            => (string) ($proposal['fqcn'] ?? ''),
        'sourceTable'     => (string) ($proposal['sourceTable'] ?? ''),
        'targetSection'   => (string) ($proposal['targetSection'] ?? ''),
        'targetEntryType' => (string) ($proposal['targetEntryType'] ?? ''),
        'confidence'      => (string) ($proposal['confidence'] ?? 'medium'),
        'rationale'       => (string) ($proposal['rationale'] ?? ''),
        'status'          => $initialStatus,
    ];
}
```

**Identity-key pattern** (lines 241-260):
```php
private function identityKey(array $row): string
{
    $kind = (string) ($row['kind'] ?? 'column');
    if ($kind === 'pagePart') {
        return 'pagePart|' . ($row['pagePartClass'] ?? '')
            . '|' . ($row['parentPageClass'] ?? '')
            . '|' . ($row['context'] ?? '');
    }
    // (existing nodeClass + column branches follow)
```

**Add taxonomy branch** (per D-07, identity tuple = `(kind, fqcn)`):
```php
if ($kind === 'taxonomy') {
    return 'taxonomy|' . ($row['fqcn'] ?? '');
}
```

---

### `src/mapping/MappingAuditor.php` — extend with `kind=taxonomy` audit

**Analog:** existing finding-kind loop (lines 88-203) + `buildV1ShapedMapping()` adapter (lines 215-).

**Existing finding-kind dispatch pattern** (lines 95-160):
```php
foreach ($mappingProposals as $row) {
    $status = (string) ($row['status'] ?? '');
    if ($status === 'dropped') {
        if (strlen((string) ($row['rationale'] ?? '')) < 10) {
            $findings[] = [
                'table'           => (string) ($row['table'] ?? ''),
                // ...
                'kind'            => 'drop-rationale-missing',
                'detail'          => "...",
            ];
        }
        continue;
    }
    $entryHandle = (string) ($row['targetEntryType'] ?? '');
    // ... missing-entry-type / missing-handle / handler-classification-mismatch
}
```

**Extend audit() to validate `kind === 'taxonomy'` rows:**
- For each `kind=taxonomy` row with `status=accepted`: verify `targetSection` resolves via `Craft::$app->entries->getSectionByHandle($section)` (mirror line 124 entryType cache pattern). Emit `'missing-section'` finding kind on miss.
- Verify `targetEntryType` resolves via the existing `getEntryTypeByHandle` cache (line 124). Emit `'missing-entry-type'` (existing finding kind, reuse).
- For accepted taxonomies: emit a `'taxonomy-no-column-rows'` warning when no `kind=column` rows exist on the same `sourceTable` (D-07: field-level mapping is inferred from column rows; taxonomy with zero columns can't migrate any data).

**Gotcha:** Block-availability validator (lines 186-200) does NOT apply to taxonomies — they're not Matrix-block-shaped. The taxonomy-row branch is independent.

---

### `src/load/TaxonomyMigrationService.php` (NEW) — verbatim port

**Analog:** `~/Sites/craft-kunstmaan-migrator/src/bridge/load/TaxonomyMigrationService.php` (443 LOC, v1 brownfield) — D-08 mandates verbatim port.

**Verbatim port target — class skeleton** (v1 lines 47-81):
```php
class TaxonomyMigrationService extends Component
{
    public ?MigrationStateService $migrationState = null;
    public ?LegacyDbService $legacyDb = null;
    public ?MappingLoader $mappingLoader = null;

    public function migrateAll(MigrationOptions $opts): MigrationReport
    {
        $report = new MigrationReport();
        $mapping = $this->mappingLoader->load();
        $taxonomies = (array) ($mapping['taxonomies'] ?? []);
        if ($taxonomies === []) {
            return $report;
        }
        foreach ($taxonomies as $fqcn => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['action'] ?? null) === 'SKIP') {
                $report->incr('skipped');
                continue;
            }
            $this->migrateOneTaxonomy((string) $fqcn, $row, $opts, $report);
        }
        $this->loadAssetSecondPass($mapping, $report);
        return $report;
    }
}
```

**Per-row atomic transaction pattern** (v1 lines 196-226):
```php
foreach ($rows as $legacyRow) {
    $legacyId = (int) ($legacyRow['id'] ?? 0);
    if ($legacyId <= 0) {
        $report->incr('failed');
        continue;
    }
    try {
        Craft::$app->db->transaction(function () use (
            $fqcn, $gedmoFqcns,
            $section, $entryType, $stateSource, $legacyId, $legacyRow,
            $fieldsMap, $opts, $report,
        ): void {
            $this->upsertOneEntry(
                $section->id, $entryType->id, $stateSource,
                $fqcn, $gedmoFqcns, $legacyId, $legacyRow,
                $fieldsMap, $opts, $report,
            );
        });
    } catch (Throwable $e) {
        $report->incr('failed');
        Craft::warning(sprintf(
            'TaxonomyMigrationService: %s id=%d failed: %s',
            $stateSource, $legacyId, $e->getMessage(),
        ), __METHOD__);
    }
}
```

**Site-agnostic state-row pattern** (v1 lines 322-329):
```php
$this->migrationState->record(
    $stateSource,
    (string) $legacyId,
    'entry',
    (int) $entry->id,
    $entry->uid,
    null,           // <-- siteId: null = site-agnostic
);
```

**Per-locale Gedmo translation overlay** (v1 lines 364-432) — port verbatim. Key points:
- Skip the primary site (canonical save already wrote NL).
- Normalize Craft language to base locale: `strtolower(explode('-', $site->language)[0])` (line 396).
- `propagateChanges=false` on the per-site re-save (line 430).

**v1 → v2 reshape (D-54 / D-08 verbatim-port discipline) — list every dropped or reshaped rule in RECONCILIATION.md:**
1. **Single mapping.yaml** — replace v1's `MappingLoader` injection with v2's `MappingFile` (`Plugin::getInstance()->mappingFile->load($path)`). v1 read three files (`mapping.yaml` + `.draft` + `mapping-drops-{ts}.yaml`); v2 reads one.
2. **Atomic-always-on** — already enforced by the per-row `Craft::$app->db->transaction` block (v1 lines 197-214); no `--atomic` flag check to remove.
3. **D-09 empty-table fallback** — when `extTranslationsFor()` returns `[]`, copy the source-locale row across every site in `mapping.sites` (NEW behavior, not in v1). Doctor's 10th check WARNs when `ext_translations` is empty so the operator sees the path was taken.
4. **`taxonomies:` block shape** — v1 row carries `{ section, entryType, sourceTable, fields, gedmoFqcns, action: SKIP? }`. v2 mirrors the same payload but emitted by the COMPILER (`compileTaxonomies()`) from per-row `kind=taxonomy` proposals + same-`sourceTable` `kind=column` rows. Operator-edited `mapping.taxonomies` block always wins (skip-existing).
5. **Detection inside the service (Phase 4 / D-56)** — `migrateAll()` short-circuits with a single WARN when no `taxonomies` block (or empty block) is present. Mirror `SeoMigrationService::migrateAll()` lines 131-138 (settings-disabled warn) + lines 142-149 (plugin-not-installed warn). For taxonomies the gate is "no `kind=taxonomy` rows accepted" — emit `$report->warn("No taxonomies in mapping; taxonomy migration skipped.");` and return.

**Gotchas:**
- Taxonomy entry types do NOT have `kunstmaanSourceId` field attached (v1 docblock lines 41-45). Do NOT call `setFieldValue('kunstmaanSourceId', ...)` — state-table is the only identity record.
- Enable for ALL sites globally (v1 lines 268-272) — taxonomies are language-neutral (single name column), so they should not respect Section's `enabledByDefault=false`.
- `sourceTable` regex whitelist (v1 lines 159-163: `preg_match('/^[a-z0-9_]+$/', $sourceTable)`) — preserve verbatim. SQL injection defense.

---

### `src/load/AtomicMigrationService.php` — wire taxonomies as topological-pre stage

**Analog:** `MigrateController::actionIndex` step ordering (lines 164-329, esp. step 5 load + step 6.5/6.6 SEO/Retour bolt-ons).

**Wave-order pattern** (MigrateController lines 282-302):
```php
// Step 5: load — per-entry atomic write (or dry-run print).
if (!$this->live) {
    $this->stdout("  WARN load skipped (dry-run; pass --live to write entries)\n", Console::FG_YELLOW);
} else {
    if ($this->preloadAssets) { ... }
    $loadExit = $this->runLoadFromDisk($transformedDir, $opts, $report);
    if ($loadExit !== ExitCode::OK) {
        return $loadExit;
    }
}
```

**D-03 mandate:** Taxonomies migrate BEFORE pages. Insert `taxonomies` stage in `MigrateController::actionIndex` between step 4 (transform complete) and step 5 (load entries). This is NOT a position inside `AtomicMigrationService` (which is per-entry) — taxonomies are a stage above it.

**Mirror SEO/Retour stage shape** (MigrateController lines 336-365 — actionIndex bolt-on for `actionSeo`):
```php
if ($this->live) {
    if ($filters->noTaxonomies ?? false) {  // hypothetical filter flag — D-04 disallows; this is a service-internal short-circuit only
        $report->warn(self::cliBypassTaxonomiesWarnLine());
        $this->stdout("  WARN taxonomies skipped via [...]\n", Console::FG_YELLOW);
    } else {
        try {
            $taxonomyReport = $plugin->taxonomyMigrationService->migrateAll($opts);
        } catch (Throwable $e) {
            $this->stderr("  FAIL taxonomies: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->mergeReport($report, $taxonomyReport, 'taxonomies');
        $this->stdout(sprintf(
            "  Stage taxonomies: created=%d updated=%d skipped=%d failed=%d\n",
            (int) ($taxonomyReport->counts['created'] ?? 0),
            // ...
        ), Console::FG_GREEN);
    }
}
```

**Gotchas:**
- D-04 says NO new filter flag (`--taxonomies=` rejected). Filter scoping is reachability-based: when `--entities=NewsPage,CaseStudyPage` is set, `MigrationFilters` auto-includes the FQCNs of taxonomies referenced by allowed FQCNs (computed at extract-time from the FK index).
- The taxonomies-migrate-before-pages mandate (D-03) means RelationHandler resolves category FKs via the existing state-table without any `relation:deferred` finalize-pass. REC-02's deferred-relation marker stays unimplemented.
- A `migrate/taxonomies` resume sub-action (analogous to `actionSeo` / `actionRetour` at MigrateController lines 428-510) should ship for operator debug. Note that all sub-actions still gate on `enforceNeverProduction()` first.

---

### `src/console/AnalyzeController.php` — wire 3 new proposers + `--no-layout` / `--no-providers` flags

**Analog:** step 7.5 entity LLM (lines 297-366) + step 6.5 implicit-content emitter (lines 195-255).

**Skip-gate pattern** (lines 304-316):
```php
$apiKeyForEntityStep = (string) ($plugin->getSettings()->anthropicApiKey ?? '');
$skipEntityLlm = $this->noAi || $apiKeyForEntityStep === '' || $pageStructure === [];
$nodeClassProposals = [];
$craftEntryTypeHandles = $plugin->craftKnowledgeBase->entryTypeHandles();
$kbCraftMd = '';
if ($skipEntityLlm) {
    $reason = $this->noAi
        ? '--no-ai set'
        : ($apiKeyForEntityStep === '' ? 'ANTHROPIC_API_KEY not set' : 'no page entities discovered');
    $this->stdout(
        "  WARN entity-level LLM skipped ({$reason}) — nodeClass rows will fall back to compiler basename heuristic\n",
        Console::FG_YELLOW,
    );
}
```

**Flag declaration pattern** (lines 49-66):
```php
public bool $noAi = false;
public bool $autoAcceptHigh = false;
public bool $auditStrict = false;
public bool $sourceStrict = false;
public ?string $entities = null;
public ?string $locales = null;
public ?string $since = null;

public function options($actionID): array
{
    return array_merge(parent::options($actionID), [
        'noAi', 'autoAcceptHigh', 'auditStrict', 'sourceStrict',
        'entities', 'locales', 'since',
    ]);
}
```

**Mirror flag declarations:**
```php
public bool $noLayout = false;
public bool $noProviders = false;
// in options():
'noAi', 'autoAcceptHigh', 'auditStrict', 'sourceStrict',
'noLayout', 'noProviders',  // NEW — Phase 8 / D-14
'entities', 'locales', 'since',
```

**Skip-gate for layout proposer** (D-14 mirrors ADP-04 settings + CLI ladder):
```php
$skipLayout = $this->noLayout
    || !Plugin::getInstance()->getSettings()->proposeLayout
    || $this->noAi
    || $apiKeyForEntityStep === ''
    || $pageStructure === [];
```

**Insertion site for the three new proposer steps:**
- Step 7.6 (NEW) — `proposeNonPageEntities()` for taxonomies. Runs AFTER step 7.5 entity LLM (mirrors its KB-markdown reuse pattern at line 320). Output via `mappingFile->buildTaxonomyRow()` per row.
- Step 7.7 (NEW) — `proposeLayoutBlocks()` for headerBlock/bodyWrapBlock/bodyColumn. Heuristic-trigger gated (D-12).
- Step 7.8 (NEW) — `proposeDataProviders()` for orphan page-parts. Heuristic-trigger gated (D-13).

All three feed proposals into the merge step (lines 600+, search for `->merge(`) — same flow as nodeClass + pagePart proposals.

---

### `src/console/MigrateController.php` — wire taxonomies stage + `actionTaxonomies` sub-action

**Analog:** `actionSeo` (lines 428-...) and `actionRetour` (lines 488-510); SEO/Retour stage bolt-ons in `actionIndex` (lines 331-398).

**Stage-bolt-on pattern** (lines 336-365): see "AtomicMigrationService" assignment above for verbatim excerpt.

**Sub-action pattern** (lines 428-449 actionSeo skeleton):
```php
public function actionSeo(): int
{
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    $this->openLogFile($this->defaultLogPath());
    // ... set up filters, mapping, opts ...
    // Phase 4.1 / D-26: actionSeo IS the SEO sub-action; honoring --no-seo
    // here would defeat its purpose. Force noSeo=false; pass --no-retour
    // through unchanged (inert here — actionSeo never invokes Retour).
    $filters = $plugin->filterFactory->fromCli($this->entities, $this->locales, $this->since, false, $this->noRetour);
    // ...
}
```

**Mirror as `actionTaxonomies()`:**
- Same NeverProduction gate, same log-file open, same filter build.
- Run order: D-03 — taxonomies BEFORE pages. The sub-action is for resume / debug; the actionIndex bolt-on inserts taxonomies at the right wave position (between transform-complete and load-entries).

**Flag declaration:** D-04 disallows `--no-taxonomies` (preserves D-12 three-flag cap). Operator's escape hatch is `Settings::proposeLayout` / `proposeProviders` for the proposer side; for the load side, omit the row from `taxonomies:` block or set `action: SKIP` (the v1 contract).

---

### `src/console/DoctorController.php` — add 10th check for `ext_translations`

**Analog:** existing 9 checks dispatcher (lines 71-83) and `checkAdapterPlugins` / `checkVerifyBaseline` (always-true INFO checks at lines 78-79).

**Dispatch insertion pattern** (lines 71-83):
```php
$ok = $this->checkLegacyDb()             && $ok;
$ok = $this->checkApiKey()               && $ok;
$ok = $this->checkStorageDir()           && $ok;
$ok = $this->checkMappingFile()          && $ok;
$ok = $this->checkKunstmaanSourcePath()  && $ok;
$ok = $this->checkStateTable()           && $ok;
$ok = $this->checkAdapterPlugins()       && $ok;
$ok = $this->checkVerifyBaseline()       && $ok;
$ok = $this->checkKunstmaanEnvSource()   && $ok;
$ok = $this->checkLocalePreflightRung0() && $ok;
// NEW (Phase 8): 11th — ext_translations presence; WARN-only on empty per D-09.
$ok = $this->checkExtTranslations()      && $ok;
```

**Always-true WARN-or-INFO pattern** (mirror `checkLegacyDb` lines 102-125, but never return false per D-09 mandate "WARN-only when empty"):
```php
private function checkExtTranslations(): bool
{
    try {
        $svc = Plugin::getInstance()->legacyDbService;
        $count = (int) $svc->queryScalar(
            'SELECT COUNT(*) FROM ' . KunstmaanCoreTables::EXT_TRANSLATIONS,
        );
        if ($count === 0) {
            $this->stdout(
                "  WARN ext_translations is empty — Gedmo Translatable taxonomy migration will fall back "
                . "to source-locale-only (D-09 monolingual-Kunstmaan default).\n",
                Console::FG_YELLOW,
            );
            return true;  // WARN, not FAIL
        }
        $this->stdout("  OK   ext_translations populated ({$count} rows)\n", Console::FG_GREEN);
        return true;
    } catch (Throwable) {
        $this->stdout(
            "  INFO ext_translations table not present in legacy DB — Gedmo Translatable absent\n",
            Console::FG_YELLOW,
        );
        return true;  // INFO, not FAIL
    }
}
```

**Gotcha:** This becomes the 11th check (10 currently exist per the file's docblock at lines 25-31). Keep INFO/WARN-never-FAIL semantics — the docblock at line 80 "always return true (INFO not FAIL)" applies here.

---

### `src/models/Settings.php` — add `proposeLayout` + `proposeProviders` booleans

**Analog:** `seoEnabled` / `retourEnabled` properties (lines 86-88) + `rules()` entry (line 228).

**Property-declaration pattern** (lines 84-88):
```php
// Phase 4.1 / D-24 — adapter explicit-disable. Defaults to true so existing
// operators see no behavior change; flip to false to skip the adapter even
// when the plugin IS installed. CLI --no-seo / --no-retour bypass per-run.
public bool $seoEnabled = true;
public bool $retourEnabled = true;
```

**Mirror:**
```php
// Phase 8 / D-14 — AI proposer scope gates. Defaults to true (proposers run);
// flip to false to disable per Settings persistence. CLI --no-layout / --no-providers
// bypass per-run.
public bool $proposeLayout = true;
public bool $proposeProviders = true;
```

**Rules entry** (line 228):
```php
[['seoEnabled', 'retourEnabled'], 'boolean'],
```

**Mirror:**
```php
[['seoEnabled', 'retourEnabled', 'proposeLayout', 'proposeProviders'], 'boolean'],
```

---

### `src/templates/_settings.twig` — surface 2 new fields under "AI" H2 group

**Analog:** existing H2 groups at lines 19, 75, 120 (`Connectivity` / `Mapping` / `Fallback`).

**H2 group pattern** (line 19):
```twig
<h2>{{ 'Connectivity'|t('kunstmaan-migrator') }}</h2>
```

**Bool field pattern** — note: there is no existing `lightswitchField` example in this template, but the standard Craft idiom (per Phase 4 / D-62 grouped-section convention CONTEXT cites) is:
```twig
{{ forms.lightswitchField({
    label: 'Propose page-builder layout (header / body-wrap / body-column)'|t('kunstmaan-migrator'),
    id: 'proposeLayout',
    name: 'proposeLayout',
    on: settings.proposeLayout,
    instructions: 'When ON, analyze proposes Matrix layout slots (headerBlock / bodyWrapBlock / bodyColumn) for each accepted nodeClass. Heuristic-gated by Matrix catalog. CLI --no-layout bypasses per-run.'|t('kunstmaan-migrator'),
}) }}
```

**Insertion site:** Phase 4 / D-62 says "AI" H2 group. The existing template only has Connectivity / Mapping / Fallback (CFG-05 slimmed it Phase 4.1). Phase 8 introduces an "AI" H2 group BETWEEN `Mapping` and `Fallback` carrying `proposeLayout` + `proposeProviders`. (Optional follow-up: when CFG-05 advanced fields move back to CP later, `seoEnabled` / `retourEnabled` may relocate here too — out of Phase 8 scope.)

---

### `src/Plugin.php` — register `TaxonomyMigrationService` Yii component

**Analog:** `seoMigrationService` slot (line 163) + DI fanout (lines 308-329).

**Component slot pattern** (lines 163-170):
```php
'seoMigrationService'        => SeoMigrationService::class,
'seomaticPayloadBuilder'     => SeomaticPayloadBuilder::class,
'redirectMigrationService'   => RedirectMigrationService::class,
```

**Mirror:**
```php
'taxonomyMigrationService'   => TaxonomyMigrationService::class,
```

**DI fanout pattern** (lines 308-317):
```php
// SEO migration service.
$this->seoMigrationService->legacyDb     = $this->legacyDbService;
$this->seoMigrationService->stateService = $this->migrationStateService;
$this->seoMigrationService->seoPayload   = $this->seomaticPayloadBuilder;
$this->seoMigrationService->sites        = $this->resolveSitesMap();

// Redirect migration service.
$this->redirectMigrationService->legacyDb     = $this->legacyDbService;
$this->redirectMigrationService->stateService = $this->migrationStateService;
$this->redirectMigrationService->sites        = $this->resolveSitesMap();
```

**Mirror for taxonomyMigrationService:**
```php
// Taxonomy migration service (Phase 8 / D-08 verbatim port).
$this->taxonomyMigrationService->legacyDb       = $this->legacyDbService;
$this->taxonomyMigrationService->migrationState = $this->migrationStateService;
$this->taxonomyMigrationService->mappingFile    = $this->mappingFile;  // v2 replacement for v1's MappingLoader
```

**@property-read docblock** (line 101):
```php
* @property-read SeoMigrationService $seoMigrationService
```

**Mirror:**
```php
* @property-read TaxonomyMigrationService $taxonomyMigrationService
```

---

### `tests/integration/load/TaxonomyMigrationTest.php` (NEW)

**Analog:** `tests/integration/transform/TransformImplicitContentTest.php`.

**Suite-namespace + class-skeleton pattern** (lines 5-32):
```php
namespace lameco\kunstmaanmigrator\tests\integration\transform;

use lameco\kunstmaanmigrator\fields\FieldHandlerRegistry;
// ...
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 — end-to-end loop closure for the implicit-content pipeline.
 * ...
 */
final class TransformImplicitContentTest extends TestCase
{
    public function testSyntheticImplicitPagePartProducesMatrixBlock(): void
    {
        // build mapping shaped exactly as MappingCompiler emits
        $mapping = [ ... ];
        // build extracted shape exactly as ExtractService writes
        $extracted = [ ... ];
        // drive TransformService::run() and assert output shape
    }
}
```

**Mirror as `tests/integration/load/TaxonomyMigrationTest.php`:**
- Drive `TaxonomyMigrationService::migrateAll(MigrationOptions)` with a synthetic `mapping.taxonomies` block + a `LegacyDbService` test stub returning a hard-coded `kuma_*` taxonomy table.
- Assert: per-row `Craft::$app->db->transaction()` invocation, state-row `record()` call shape (siteId=null), and per-locale Gedmo overlay path (D-08 verbatim) when `extTranslationsFor()` returns non-empty.
- Edge case: D-09 — when `extTranslationsFor()` returns `[]`, assert source-locale row is copied across every site in `mapping.sites`.

**Gotcha:** Like the implicit-content test, this is a `final class` extending `PHPUnit\Framework\TestCase` directly (NOT Craft's `BaseTestCase`). It exercises pure service code with stubbed DB, not a Craft instance.

---

### `tests/unit/compile/MappingCompilerTaxonomiesTest.php` + `MappingCompilerLayoutBlocksTest.php` + `MappingCompilerDataProvidersTest.php` (NEW)

**Analog:** `tests/unit/compile/MappingCompilerImplicitBlocksTest.php`.

**Test-method pattern** (lines 25-64):
```php
public function testAcceptedImplicitRowEmitsPagePartsEntryAndWiresPageBuilder(): void
{
    $mapping = [
        'proposals' => [
            $this->columnRow('news_pages', 'title', 'newsPage', 'title'),
            $this->implicitRow(
                parentPageClass: 'NewsPage',
                context: 'main',
                targetMatrixField: 'pageBuilder',
                targetBlockType: 'textContentBlock',
                fields: [...],
            ),
        ],
    ];
    $pageStructure = [
        'App\\Entity\\Pages\\NewsPage' => ['tableName' => 'news_pages', 'contexts' => []],
    ];
    $compiled = $this->compiler->compile($mapping, $pageStructure, ['nl' => 'default']);
    // assertions on compiled.pageParts, compiled.nodeClasses, compiled._compileReport.implicitBlocksEmitted
}
```

**Mirror three times** with `taxonomyRow` / `layoutRow` / `dataProviderRow` helpers; assertions on `compiled.taxonomies` / `compiled.nodeClasses[fqcn].headerBlock` etc. / `compiled.dataProviders` and the matching `_compileReport.taxonomiesEmitted` / `layoutBlocksEmitted` / `dataProvidersEmitted` counters.

---

### `.planning/phases/08-taxonomies-and-proposers/08-RECONCILIATION.md` (NEW)

**Analog:** prior phase RECONCILIATION.md files cited in CONTEXT (Phase 4 / D-54 + Phase 4.1 + Phase 02.1 / Plan 09).

**Required content (D-08):**
- Rule-by-rule disposition table mapping every v1 `TaxonomyMigrationService` rule (the 443 LOC walkthrough) to v2 disposition: `kept-verbatim` / `reshaped-for-single-mapping.yaml` / `reshaped-for-atomic-always-on` / `dropped`.
- Specific entries:
  - **Three mapping files → single mapping.yaml** — v1 reads from `MappingLoader` (its own three-file merge); v2 reads `MappingFile->load()`.
  - **`mapping.taxonomies` block shape** — v1 raw-edited block vs v2 compiler-emitted block from `kind=taxonomy` rows + same-`sourceTable` `kind=column` rows.
  - **Restored `extTranslationsFor()` on v2 LegacyDbService** — note that v2's slimmer port dropped this; D-08 / SRC-11 `??=` mandate restored it.
  - **Empty `ext_translations` fallback (D-09)** — NEW behavior, not in v1; document as intentional reshape.
  - **REC-02 `relation:deferred`** — D-03 confirms still unimplemented post-Phase 8 (taxonomies-before-pages avoids the deferred-relation case).

---

### `CHANGELOG.md` — add "Known omissions in v1.0" section under v1.0 entry

**Analog:** repo-root `CHANGELOG.md` already exists (Phase 5 / Plan 05-08 in Keep-a-Changelog format).

**Section content** (per CONTEXT specifics + ROADMAP Phase 8 success criterion 8):
> Listed Kunstmaan surfaces this migrator deliberately does NOT cover: FormBundle, SearchBundle, MenuBundle, user accounts / roles / ACLs, `kuma_translations` (i18n string catalog — distinct from `ext_translations` Gedmo Translatable), media folder hierarchy, asset metadata (alt text / focal point), slug history (Retour-style mining beyond `kuma_redirects`), drafts / non-public node versions.

**Cross-link:** `README.md` Quickstart section + `PROJECT.md` Out of Scope table.

---

## Shared Patterns

### Service short-circuit on empty mapping
**Source:** `src/load/SeoMigrationService.php` lines 131-149.
**Apply to:** `TaxonomyMigrationService::migrateAll()`.
```php
if (!Plugin::getInstance()->getSettings()->seoEnabled) {
    Craft::info(
        'SEOmatic adapter explicitly disabled via Settings::seoEnabled; skipping SEO migration pass.',
        'kunstmaanmigrator',
    );
    $report->warn(self::disabledWarnLine());
    return $report;
}
if (Craft::$app->plugins->getPlugin('seomatic') === null) {
    Craft::warning(
        'SEOmatic plugin not installed; skipping SEO migration pass.',
        'kunstmaanmigrator',
    );
    $report->warn('SEOmatic plugin not installed; SEO migration skipped.');
    return $report;
}
```
For taxonomies the gate is "no `kind=taxonomy` rows accepted in `mapping.taxonomies`" — emit a single distinct warn-line and return.

### NeverProduction gate (always FIRST in any controller action)
**Source:** every Controller action in `src/console/`. Example AnalyzeController line 71:
```php
public function actionIndex(): int
{
    // FND-04 / D-20: NeverProduction guard FIRST — before any legacy DB read.
    if (($gate = $this->enforceNeverProduction()) !== null) {
        return $gate;
    }
    // ...
}
```
**Apply to:** `MigrateController::actionTaxonomies()` (resume sub-action — same pattern).

### Atomic-always-on per-row transaction
**Source:** v1 `TaxonomyMigrationService.php` lines 197-214 (verbatim port target).
**Apply to:** Every per-row write in `TaxonomyMigrationService` and any new load-side service. No `--atomic` flag.

### Settings + CLI override ladder (D-14 mirrors ADP-04)
**Source:** Phase 4.1 / D-24 — `Settings::seoEnabled` + `--no-seo` flag.
- Settings property defaults to `true` (preserves current-with-D-11-on-by-default behavior).
- CLI flag `--no-layout` / `--no-providers` declared via `public bool $noLayout = false;` + entry in `options($actionID)` array.
- Settings-disabled gate runs FIRST; CLI flag emits a distinct warn-line copy so REPORT.md skipped-stages aggregation can pattern-match the four disable paths (Phase 4.1 / D-26 / D-27 contract).

### `--no-ai` is the blanket override
**Source:** AnalyzeController line 305: `$skipEntityLlm = $this->noAi || $apiKeyForEntityStep === '' || $pageStructure === [];`.
**Apply to:** Every new proposer's skip-gate. `--no-ai` shuts off ALL LLM calls — operator escape hatch for runtime-zero-AI compliance + cost control. The new `--no-layout` / `--no-providers` are scope-specific bypass; `--no-ai` is global.

### Compile-report counter
**Source:** `MappingCompiler::compile()` `_compileReport` block lines 347-359.
**Apply to:** Every new compiler emission must surface its count alongside `implicitBlocksEmitted`: `taxonomiesEmitted`, `layoutBlocksEmitted`, `dataProvidersEmitted`. Surfaced by `CompileController` (lines 198-203 pattern).

## No Analog Found

| File | Role | Reason |
|------|------|--------|
| (none) | — | All Phase 8 files map to a strong codebase analog. |

## Metadata

**Analog search scope:**
- `src/analyze/` (LlmClassifier proposers)
- `src/compile/` (MappingCompiler)
- `src/source/` (KnowledgeBase, DoctrineEntityParser, KunstmaanCoreTables)
- `src/db/` (LegacyDbService — verbatim-port site)
- `src/load/` (Seo / Redirect / AtomicMigration as adapter-stage analogs; Taxonomy as v1 brownfield port target)
- `src/console/` (Analyze / Migrate / Doctor / Compile — 4 of 6 controllers extended in Phase 8)
- `src/mapping/` (MappingFile, MappingAuditor)
- `src/models/Settings.php`
- `src/templates/_settings.twig`
- `src/Plugin.php` (DI wiring)
- `tests/integration/transform/TransformImplicitContentTest.php` + `tests/unit/compile/MappingCompilerImplicitBlocksTest.php` (Phase 7 test precedents)
- `~/Sites/craft-kunstmaan-migrator/src/bridge/load/TaxonomyMigrationService.php` (v1 brownfield, verbatim-port target)
- `~/Sites/craft-kunstmaan-migrator/src/kunstmaan/db/LegacyDbService.php` (v1 brownfield, `extTranslationsFor()` source)

**Files scanned:** 23 v2 + 2 v1 = 25 total. Three contained the strongest-fit analogs (LlmClassifier::proposeNodeClasses, MappingCompiler::compileImplicitBlocks, v1 TaxonomyMigrationService). All other files extended in Phase 8 use the existing v2 patterns documented above.

**Pattern extraction date:** 2026-04-27.

**Verbatim-port reminder (D-08):** RECONCILIATION.md is REQUIRED for this phase. v1 → v2 differences expected and must each be documented:
1. single mapping.yaml (vs v1's three files)
2. atomic-always-on (no flag)
3. LegacyDbService surface restoration (`extTranslationsFor()`)
4. D-09 empty-`ext_translations` fallback (NEW v2 behavior)
5. taxonomies-before-pages run order (D-03; avoids `relation:deferred`)
6. compiler-emitted `taxonomies:` block shape (vs v1's raw-edited block)
