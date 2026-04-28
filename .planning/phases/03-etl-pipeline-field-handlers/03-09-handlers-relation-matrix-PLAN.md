---
phase: 03-etl-pipeline-field-handlers
plan: 09
type: execute
wave: 3
depends_on: ['03-01', '03-02']
files_modified:
  - src/fields/handlers/RelationHandler.php
  - src/fields/handlers/MatrixHandler.php
autonomous: true
requirements: [FH-01]
must_haves:
  truths:
    - "RelationHandler dispatches to one of three resolution paths based on options shape: resolveViaJoinTable (when joinTable option present), resolveViaJoinTranslation (when joinTranslation option present), resolveDirect (default fallback)."
    - "RelationHandler T-06-02-01 mitigation preserved: every join-table identifier MUST match ^[A-Za-z0-9_]+$ before sprintf-interpolation; scalar values bind as named PDO parameters; LIMIT casts to int."
    - "MatrixHandler runtime contract per CONTEXT D-49 — MAPPING-DRIVEN: dispatches on options shape between (a) generic itemTable/fkCol/blockType path (v1 verbatim) and (b) page-part path keyed on (pagePartClass, parentPageClass, context). Single MatrixHandler class; advisor-locked option (a) per PATTERNS §20."
    - "MatrixHandler block-array key pattern 'new1', 'new2', ... preserved verbatim — required by Craft 5 setFieldValue() semantics for new-block creation."
  artifacts:
    - path: "src/fields/handlers/RelationHandler.php"
      provides: "FH-01 Relation handler with 3 dispatch paths."
      min_lines: 290
    - path: "src/fields/handlers/MatrixHandler.php"
      provides: "FH-01 Matrix handler with D-49 mapping-driven page-part dispatch."
      min_lines: 100
  key_links:
    - from: "src/fields/handlers/MatrixHandler.php"
      to: "src/db/LegacyDbService.php"
      via: "$ctx->legacyDb->streamQuery()"
      pattern: "streamQuery"
---

<objective>
Two handler ports — RelationHandler (verbatim) + MatrixHandler (verbatim with D-49 dispatch enhancement).

**MatrixHandler D-49 decision (advisor-locked, PATTERNS §20 default stance):** Single `MatrixHandler` class with options-shape dispatch. Branch (a) `[itemTable, fkCol, blockType]` is the v1 generic path; branch (b) `[pagePartClass, parentPageClass, context, targetMatrixField, targetBlockType, fields]` is the page-part path keyed on the mapping.yaml pagePart row tuple. Documented in RECONCILIATION as `enhanced — D-49 page-part path added`.

Wave 3 — depends on Plan 03-01 (FieldHandler interface, ResolverContext).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md
@.planning/phases/02.1-source-introspection/02.1-CONTEXT.md

<interfaces>
RelationHandler 3-dispatch:
```php
public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
{
    if (!isset($options['stateSource']) || $options['stateSource'] === '') {
        throw new \RuntimeException("RelationHandler requires 'stateSource' option.");
    }
    $source = (string) $options['stateSource'];

    if (isset($options['joinTable']))      return $this->resolveViaJoinTable(...);
    if (isset($options['joinTranslation'])) return $this->resolveViaJoinTranslation(...);
    return $this->resolveDirect($legacyValue, $ctx, $source);
}
```

MatrixHandler dispatch (D-49 enhanced):
```php
public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
{
    // Branch (b): page-part path (D-49 mapping-driven)
    if (isset($options['pagePartClass'])) {
        return $this->resolvePagePartMatrix($legacyValue, $ctx, $options);
    }
    // Branch (a): v1 verbatim generic itemTable/fkCol/blockType path
    return $this->resolveGenericMatrix($legacyValue, $ctx, $options);
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port RelationHandler — 3-dispatch with T-06-02-01 mitigation preserved</name>
  <files>src/fields/handlers/RelationHandler.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/RelationHandler.php (v1, 312 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §19 (RelationHandler — full reshape recipe)
    - src/fields/FieldHandler.php (Plan 03-01)
    - src/fields/ResolverContext.php (Plan 03-01)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/RelationHandler.php` to `src/fields/handlers/RelationHandler.php`. Apply per PATTERNS §19:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\fields\handlers` → `lameco\kunstmaanmigrator\fields\handlers`.

    **2. Retarget imports:**
    - `FieldHandler` → `lameco\kunstmaanmigrator\fields\FieldHandler`
    - `ResolverContext` → `lameco\kunstmaanmigrator\fields\ResolverContext`

    **3. Drop and replace MigrationConfigError if present.**

    **4. PRESERVE BYTE-FOR-BYTE — the 3-dispatch resolve() method** (PATTERNS §19, v1 lines 63-83):
    The dispatch order matters: stateSource validation → joinTable check → joinTranslation check → direct fallback. Same order, same conditions.

    **5. PRESERVE BYTE-FOR-BYTE — T-06-02-01 mitigation** (PATTERNS §19, v1 docblock lines 49-54):
    - Every join-table identifier MUST match `^[A-Za-z0-9_]+$` before sprintf-interpolation into SQL.
    - Scalar values bind as named PDO parameters (`:fk`, `:locale`, etc.) — never sprintf-interpolated.
    - LIMIT casts to int via `(int) $options['limit']`.

    The whitelist regex is the load-bearing safety check. Locate via `grep -n "A-Za-z0-9_" ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/RelationHandler.php`. Preserve verbatim.

    **6. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: any of the 3 private resolve methods (resolveViaJoinTable / resolveViaJoinTranslation / resolveDirect), the SQL query shapes, the bound-parameter pattern, the LIMIT cast, the identifier whitelist regex, the empty-value early returns.
  </action>
  <verify>
    <automated>php -l src/fields/handlers/RelationHandler.php</automated>
  </verify>
  <done>
    - `src/fields/handlers/RelationHandler.php` exists; `php -l` returns "No syntax errors".
    - File has at least 290 lines.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields\\\\handlers;" src/fields/handlers/RelationHandler.php` returns 1.
    - `grep -c "implements FieldHandler" src/fields/handlers/RelationHandler.php` returns 1.
    - `grep -c "resolveViaJoinTable\\|resolveViaJoinTranslation\\|resolveDirect" src/fields/handlers/RelationHandler.php` >= 3 (all 3 dispatch methods).
    - `grep -c "stateSource" src/fields/handlers/RelationHandler.php` >= 1 (option name preserved).
    - `grep -c "joinTable\\|joinTranslation" src/fields/handlers/RelationHandler.php` >= 2 (option dispatch preserved).
    - `grep -c "A-Za-z0-9_" src/fields/handlers/RelationHandler.php` >= 1 (T-06-02-01 identifier whitelist preserved).
    - `grep -c "MigrationConfigError" src/fields/handlers/RelationHandler.php` returns 0.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/fields/handlers/RelationHandler.php` returns zero matches.
  </done>
</task>

<task type="auto">
  <name>Task 2: Verbatim port MatrixHandler with D-49 page-part dispatch enhancement</name>
  <files>src/fields/handlers/MatrixHandler.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/MatrixHandler.php (v1, 112 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §20 (MatrixHandler — full reshape recipe + D-49 dispatch)
    - .planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md (D-49 mapping-driven contract)
    - .planning/phases/02.1-source-introspection/02.1-CONTEXT.md (D-34 pagePart row schema: pagePartClass + parentPageClass + context + targetEntryType + targetMatrixField + targetBlockType + fields[])
    - src/fields/FieldHandler.php (Plan 03-01)
    - src/fields/ResolverContext.php (Plan 03-01 — confirm $ctx->legacyDb is the typed ?LegacyDbService property)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/MatrixHandler.php` to `src/fields/handlers/MatrixHandler.php`. Apply per PATTERNS §20:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\fields\handlers` → `lameco\kunstmaanmigrator\fields\handlers`.

    **2. Retarget imports:**
    - `FieldHandler` → `lameco\kunstmaanmigrator\fields\FieldHandler`
    - `ResolverContext` → `lameco\kunstmaanmigrator\fields\ResolverContext`

    **3. Drop and replace MigrationConfigError if present.**

    **4. PRESERVE BYTE-FOR-BYTE — v1's generic Matrix path** (PATTERNS §20, v1 lines 47-110):
    - Required-options validation (`itemTable`, `fkCol`, `blockType`, plus one of `valueCol` or `bodyCol`).
    - SQL: `SELECT * FROM <itemTable> WHERE <fkCol> = :fk ORDER BY <orderBy>`.
    - The streaming `$ctx->legacyDb->streamQuery($sql, [':fk' => $fkValue])` iteration.
    - The block array key pattern `'new' . $n` (`new1`, `new2`, ...) — required by Craft 5 setFieldValue semantics.
    - The fields-hash-build per row.
    - The LegacyDbService null-check throw at the top of resolve().

    Move v1's body into a private helper method `resolveGenericMatrix(mixed $legacyValue, ResolverContext $ctx, array $options): array`.

    **5. ADD — D-49 page-part dispatch** (advisor-locked option (a) — single class, options-shape dispatch).

    Refactor the public `resolve()` method to dispatch:
    ```php
    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
    {
        // D-49 page-part path: keyed on mapping.yaml pagePart row tuple.
        // Triggered when caller passes 'pagePartClass' option (only TransformService does this,
        // when walking page-part rows for an entry).
        if (isset($options['pagePartClass'])) {
            return $this->resolvePagePartMatrix($legacyValue, $ctx, $options);
        }

        // v1 generic path: itemTable/fkCol/blockType.
        return $this->resolveGenericMatrix($legacyValue, $ctx, $options);
    }
    ```

    **6. ADD — `resolvePagePartMatrix` private helper.** This is the D-49 page-part path. Behavior contract (executor implements):

    Required options (validated at method entry, throw `\RuntimeException` if missing):
    - `pagePartClass` (string) — e.g. `App\Entity\PageParts\TextPagePart`
    - `parentPageClass` (string) — e.g. `App\Entity\Pages\NewsPage`
    - `context` (string) — e.g. `main`
    - `targetMatrixField` (string) — Craft Matrix field handle on the parent entry type
    - `targetBlockType` (string) — Craft Matrix block-type handle
    - `fields` (array) — handler-options map keyed on Craft block-type field handle, each value is a FieldSpec carrying handler + options

    Behavior:
    1. The `$legacyValue` here is the array of pre-fetched page-part rows for this (parent entry node version, context, pagePartClass) combination — TransformService walks the JOIN chain (kuma_node_versions → kuma_main_pageparts → kuma_page_part_refs) and passes the rows as `$legacyValue`.
    2. Iterate each pre-fetched row. For each, build a Craft Matrix block:
       ```php
       $blocks['new' . $n] = [
           'type' => $options['targetBlockType'],
           'enabled' => true,
           'fields' => [...resolved fields per FieldSpec walk...],
       ];
       ```
    3. For each `$options['fields'][$craftFieldHandle]` FieldSpec, call the corresponding handler from the registry — but the registry is on TransformService not on the context. **Decision (advisor-locked):** the page-part field walk routes back through TransformService via a callback OR via `$ctx`. Cleanest: TransformService pre-resolves each block's `fields` hash before calling MatrixHandler — so MatrixHandler's pagePart path consumes already-resolved rows + already-resolved fields-hashes. Reshape: the `$legacyValue` is `array<int, array{rowFields: array<string,mixed>}>` and MatrixHandler just wraps with the block-array shape.

    Mark with this docblock above `resolvePagePartMatrix`:
    ```php
    /**
     * D-49 page-part Matrix path. The pre-resolution of FieldSpec walks is owned by TransformService;
     * by the time we land here, $legacyValue is a list of already-built Craft block-fields hashes,
     * and we just wrap them in the new1/new2/... key shape that Craft 5 setFieldValue expects.
     *
     * Expected $legacyValue shape: list<array{fields: array<string, mixed>}>.
     */
    ```

    **7. PRESERVE BYTE-FOR-BYTE — block array key pattern.** Both paths (generic + pagePart) use `'new' . $n` keys. Required by Craft 5 setFieldValue semantics for new-block creation.

    **8. Add `declare(strict_types=1);` if v1 omits.**

    DO NOT change: the generic-path SQL fragment, the streaming iteration, the LegacyDbService null-check, the fields-hash assembly logic in the generic path.
  </action>
  <verify>
    <automated>php -l src/fields/handlers/MatrixHandler.php</automated>
  </verify>
  <done>
    - `src/fields/handlers/MatrixHandler.php` exists; `php -l` returns "No syntax errors".
    - File has at least 100 lines.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields\\\\handlers;" src/fields/handlers/MatrixHandler.php` returns 1.
    - `grep -c "implements FieldHandler" src/fields/handlers/MatrixHandler.php` returns 1.
    - `grep -c "resolveGenericMatrix" src/fields/handlers/MatrixHandler.php` returns 1.
    - `grep -c "resolvePagePartMatrix" src/fields/handlers/MatrixHandler.php` returns 1.
    - `grep -c "pagePartClass" src/fields/handlers/MatrixHandler.php` >= 1 (D-49 dispatch trigger).
    - `grep -c "itemTable\\|fkCol\\|blockType" src/fields/handlers/MatrixHandler.php` >= 3 (generic-path required options preserved).
    - `grep -c "streamQuery" src/fields/handlers/MatrixHandler.php` >= 1 (generic-path streaming preserved).
    - `grep -c "'new' . \\$n" src/fields/handlers/MatrixHandler.php` >= 1 (Craft 5 new-block key pattern preserved).
    - `grep -c "MigrationConfigError" src/fields/handlers/MatrixHandler.php` returns 0.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/fields/handlers/MatrixHandler.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## RelationHandler reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/RelationHandler.php` (312 LOC)
**v2 file:** `src/fields/handlers/RelationHandler.php` (~295 LOC after namespace flatten)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 63-83 — 3-dispatch resolve() | stateSource validate + joinTable/joinTranslation/direct dispatch. | ported verbatim | Same file. |
| Lines 49-54 docblock — T-06-02-01 mitigation | Identifier whitelist regex + bound parameters + LIMIT int cast. | ported verbatim | Load-bearing safety. |
| Private resolve* method bodies | The 3 dispatch implementations. | ported verbatim | Same file. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException`. |

## MatrixHandler reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/MatrixHandler.php` (112 LOC)
**v2 file:** `src/fields/handlers/MatrixHandler.php` (~140 LOC after D-49 enhancement)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 47-110 — generic Matrix resolve | itemTable/fkCol/blockType + streamQuery + new1/new2 keys. | ported verbatim — relocated to `resolveGenericMatrix` private method | Single class, options-shape dispatch. PATTERNS §20 advisor-locked option (a). |
| Line 103 — `'new' . $n` block-array key | Craft 5 setFieldValue semantics for new blocks. | ported verbatim | Same file, both paths. |
| (D-49 v2-only) — `resolvePagePartMatrix` private method | Page-part Matrix path keyed on (pagePartClass, parentPageClass, context). | new in v2 (greenfield — D-49) | Mapping-driven runtime contract. TransformService pre-resolves field hashes; MatrixHandler wraps with new-block keys. Documented in CONTEXT D-49. |
| `resolve()` public dispatch | v1 had no dispatch (single body). | enhanced — D-49 dispatch added | Branches on `isset($options['pagePartClass'])` to select pagePart vs generic path. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException`. |

### Counts (Plan 03-09 only)
| Pair | ported | dropped intentionally | dropped accidentally | new in v2 |
|---|---:|---:|---:|---:|
| RelationHandler | 3 | 1 (MigrationConfigError if present) | 0 | 0 |
| MatrixHandler | 3 (generic path retained) | 1 (MigrationConfigError if present) | 0 | 1 (D-49 page-part path) |
</reconciliation>

<verification>
- `php -l` exits 0 for both files.
- RelationHandler 3-dispatch + T-06-02-01 mitigation preserved.
- MatrixHandler dispatches between generic v1 path and D-49 page-part path on options shape.
- Block-array key `'new' . $n` pattern preserved in both Matrix paths.
</verification>

<success_criteria>
- 2 handler files port verbatim with D-49 enhancement on MatrixHandler.
- Reconciliation documents 6 ported / 2 dropped intentionally / 0 dropped accidentally / 1 greenfield (D-49 page-part path).
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-09-handlers-relation-matrix-SUMMARY.md`.
</output>
