---
phase: 03-etl-pipeline-field-handlers
plan: 10
type: execute
wave: 3
depends_on: ['03-01']
files_modified:
  - src/fields/handlers/SplitNameHandler.php
autonomous: true
requirements: [FH-01]
must_haves:
  truths:
    - "SplitNameHandler is a Dutch composite-name splitter producing 5 parts: firstName / infix (tussenvoegsel) / lastName / prefix (academic title) / suffix (generation marker)."
    - "Three const token lists (PREFIX_TOKENS / INFIX_TOKENS / SUFFIX_TOKENS) are ported byte-for-byte from v1 lines 45-63 — Dutch tussenvoegsel handling depends on the exact INFIX_TOKENS contents."
    - "Defensive infix→lastName fallback (v1 lines 152-157) preserved: when 'Jan van' tokenises to firstName=Jan, infix=van, lastName='', the last infix token promotes to lastName so saves never violate non-empty lastName constraints."
    - "Per-part dispatcher: handlerOptions 'part' selects firstName|infix|lastName|prefix|suffix. The split() method is pure-function returning all 5 parts; resolve() picks the requested one."
  artifacts:
    - path: "src/fields/handlers/SplitNameHandler.php"
      provides: "FH-01 SplitName Dutch-aware composite-name splitter."
      min_lines: 160
---

<objective>
Verbatim port of v1's 176-LOC SplitNameHandler — the Dutch composite-name splitter. Three const token lists (PREFIX, INFIX, SUFFIX) drive the tokenization; the defensive infix→lastName fallback prevents empty-lastName saves.

Wave 3 — depends only on Plan 03-01 (FieldHandler interface, ResolverContext). Standalone — no other handler interactions.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-CONTEXT.md
@.planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md

<interfaces>
SplitNameHandler:
```php
namespace lameco\kunstmaanmigrator\fields\handlers;

final class SplitNameHandler implements FieldHandler
{
    private const PREFIX_TOKENS = [/* dr / ir / drs / prof / mr / mw / ing / mrs / ms — Dutch academic + honorific titles */];
    private const INFIX_TOKENS  = [/* van / de / der / den / ten / ter / het / 't / op / aan / bij / in / uit / over / onder / achter / la / le / du / des / del / da / di / von / zu — Dutch tussenvoegsels */];
    private const SUFFIX_TOKENS = [/* jr / sr / i / ii / iii / iv / v — generation markers */];

    public function id(): string;                                                    // returns 'splitName'
    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed;
}
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Verbatim port SplitNameHandler with Dutch token const lists preserved byte-for-byte</name>
  <files>src/fields/handlers/SplitNameHandler.php</files>
  <read_first>
    - ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php (v1, 176 LOC — full file)
    - .planning/phases/03-etl-pipeline-field-handlers/03-PATTERNS.md §21 (SplitNameHandler — full reshape recipe)
    - src/fields/FieldHandler.php (Plan 03-01)
    - src/fields/ResolverContext.php (Plan 03-01)
  </read_first>
  <action>
    Copy `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` to `src/fields/handlers/SplitNameHandler.php`. Apply per PATTERNS §21:

    **1. Namespace flatten:** `lameco\kunstmaanmigrator\bridge\fields\handlers` → `lameco\kunstmaanmigrator\fields\handlers`.

    **2. Retarget imports:**
    - `FieldHandler` → `lameco\kunstmaanmigrator\fields\FieldHandler`
    - `ResolverContext` → `lameco\kunstmaanmigrator\fields\ResolverContext`

    **3. Drop and replace MigrationConfigError if present.**

    **4. PRESERVE BYTE-FOR-BYTE — the three const token lists** (PATTERNS §21, v1 lines 45-63). The exact Dutch token contents are load-bearing for CQM rehearsal correctness:

    ```php
    private const PREFIX_TOKENS = [
        'dr', 'dr.', 'ir', 'ir.', 'drs', 'drs.', 'prof', 'prof.',
        'mr', 'mr.', 'mw', 'mw.', 'ing', 'ing.', 'mrs', 'mrs.', 'ms', 'ms.',
    ];
    private const INFIX_TOKENS = [
        'van', 'de', 'der', 'den', 'ten', 'ter', 'het', "'t", 'op',
        'aan', 'bij', 'in', 'uit', 'over', 'onder', 'achter',
        'la', 'le', 'du', 'des', 'del', 'da', 'di', 'von', 'zu',
    ];
    private const SUFFIX_TOKENS = ['jr', 'jr.', 'sr', 'sr.', 'i', 'ii', 'iii', 'iv', 'v'];
    ```

    Verify the v1 lists match exactly (run `grep -A 4 "PREFIX_TOKENS\|INFIX_TOKENS\|SUFFIX_TOKENS" ~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` to confirm).

    **5. PRESERVE BYTE-FOR-BYTE — per-part dispatcher** (PATTERNS §21, v1 lines 72-89):
    The handlerOptions `part` key selects one of `firstName|infix|lastName|prefix|suffix`. Pure-function `split()` (lines 96-160) returns all 5 parts; `resolve()` picks the requested one.

    **6. PRESERVE BYTE-FOR-BYTE — defensive infix→lastName fallback** (PATTERNS §21, v1 lines 152-157):
    When tokenizing "Jan van" produces firstName=Jan, infix=van, lastName='', the last infix token promotes to lastName. This prevents saves with empty lastName fields.

    **7. Add `declare(strict_types=1);` if v1 omits.**

    **8. Add `final` modifier to the class** (v2 convention — handlers are final unless explicitly extensible).

    DO NOT change: the split() method body, the tokenization algorithm, the case-insensitive token matching, the apostrophe-aware "'t" handling, the early-empty-value guards.
  </action>
  <verify>
    <automated>php -l src/fields/handlers/SplitNameHandler.php</automated>
  </verify>
  <done>
    - `src/fields/handlers/SplitNameHandler.php` exists; `php -l` returns "No syntax errors".
    - File has at least 160 lines.
    - `grep -c "namespace lameco\\\\kunstmaanmigrator\\\\fields\\\\handlers;" src/fields/handlers/SplitNameHandler.php` returns 1.
    - `grep -c "implements FieldHandler" src/fields/handlers/SplitNameHandler.php` returns 1.
    - `grep -c "PREFIX_TOKENS\\|INFIX_TOKENS\\|SUFFIX_TOKENS" src/fields/handlers/SplitNameHandler.php` >= 3 (all three const lists present).
    - `grep -c "'van'" src/fields/handlers/SplitNameHandler.php` >= 1 (canonical Dutch tussenvoegsel preserved).
    - `grep -c "'der'" src/fields/handlers/SplitNameHandler.php` >= 1.
    - `grep -c "'tussenvoegsel'\\|infix" src/fields/handlers/SplitNameHandler.php` >= 1.
    - `grep -c "function split" src/fields/handlers/SplitNameHandler.php` >= 1 (pure-function 5-part splitter present).
    - `grep -c "MigrationConfigError" src/fields/handlers/SplitNameHandler.php` returns 0.
    - `grep -rn "lameco\\\\kunstmaanmigrator\\\\\\(bridge\\|craft\\|kunstmaan\\)" src/fields/handlers/SplitNameHandler.php` returns zero matches.
  </done>
</task>

</tasks>

<reconciliation>
## SplitNameHandler reconciliation

**v1 file:** `~/Sites/craft-kunstmaan-migrator/src/bridge/fields/handlers/SplitNameHandler.php` (176 LOC)
**v2 file:** `src/fields/handlers/SplitNameHandler.php` (~165 LOC after namespace flatten)

| v1 Rule (file:line) | Description | Disposition | v2 location / rationale |
|---|---|---|---|
| Lines 45-63 — three const token lists (PREFIX_TOKENS / INFIX_TOKENS / SUFFIX_TOKENS) | Dutch tussenvoegsel + academic title + generation-marker tokens. | ported byte-for-byte | Same file. CQM rehearsal correctness depends on the exact Dutch token contents. |
| Lines 72-89 — per-part dispatcher | `part` option selects firstName/infix/lastName/prefix/suffix. | ported verbatim | Same file. |
| Lines 96-160 — pure-function `split()` 5-part returner | Tokenization core. | ported verbatim | Same file. |
| Lines 152-157 — defensive infix→lastName fallback | Prevents empty lastName saves on names like "Jan van". | ported verbatim | Same file — load-bearing. |
| (v2 convention) — `final` class modifier | v1 omits. | added | v2 convention; behavioral equivalence preserved. |
| MigrationConfigError throws | If present. | dropped intentionally | `\RuntimeException`. |

### Counts (Plan 03-10 only)
| Pair | ported | dropped intentionally | dropped accidentally |
|---|---:|---:|---:|
| SplitNameHandler | 4 | 1 (MigrationConfigError if present) | 0 |
</reconciliation>

<verification>
- `php -l` exits 0.
- All three Dutch token const lists preserved byte-for-byte.
- Defensive infix→lastName fallback preserved.
- Pure-function split() + per-part dispatcher preserved.
</verification>

<success_criteria>
- SplitNameHandler ports verbatim with Dutch tokens preserved exactly.
- Defensive empty-lastName guard preserved.
- 4 ported / 1 dropped intentionally / 0 dropped accidentally.
</success_criteria>

<output>
Create `.planning/phases/03-etl-pipeline-field-handlers/03-10-handlers-splitname-SUMMARY.md`.
</output>
