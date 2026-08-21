---
handle: demoBlock
---
# Demo block

## Fields

| Label | Handle |
|---|---|
| Title | `heading` |

## Migration notes (Kunstmaan → Craft)

Covers **Demo**.

| Kunstmaan (`DemoPagePart`) | New field |
|---|---|
| `title` | `heading` |
| part `backgroundColor` / `color` | **`colorScheme`** — added 2026-07-21 (**D55**) |
| item `tabTitle` | tab `tabLabel` |
| item `weight` | Matrix order |
| `niv` | *(dropped — level comes from `titleLevel`)* |
| `Faq` items (question / answer) | entries in the [[faqs]] section |

## Sample content
