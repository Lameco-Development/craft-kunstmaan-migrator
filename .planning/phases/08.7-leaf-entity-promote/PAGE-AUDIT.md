---
phase: 08.7-leaf-entity-promote
artifact: page-audit
generated: 2026-04-28
project: CQM (cqm-craft-website + cqm-website legacy)
---

# Kunstmaan Page Audit — coverage of all 28 page entities

Cross-references three sources:

1. **`pageStructure.json`** — 28 page entities the source scanner found.
2. **`kuma_node_translations`** (online=1) — actual published pages per entity in legacy.
3. **`mapping.yaml` proposals + nodeClasses** — what compile derives.
4. **Craft sections + entry types** — what targets exist in CQM.
5. **Craft entry counts per section** — what's actually been migrated so far.

## Source-side reality (legacy CQM)

Online published pages per Kunstmaan entity (NL+EN):

| Kunstmaan Page | Online rows | Legacy table |
|---|---:|---|
| NewsPage | 437 | `lameco_websitebundle_newspages` |
| EmployeePage | 104 | `lameco_websitebundle_employee_pages` |
| CaseStudyPage | 99 | `lameco_websitebundle_case_study_pages` |
| MethodPage | 75 | `lameco_websitebundle_methodpages` |
| TextPage | 64 | `lameco_websitebundle_text_pages` |
| FieldPage | 24 | `lameco_websitebundle_fieldpages` |
| TextPagePlus | 12 | `lameco_websitebundle_text_page_plus` |
| EventRegisterPage | 7 | `lameco_websitebundle_event_register_pages` |
| (singletons — 2 each: NL+EN) | 2 | HomePage, NewsOverviewPage, FormPage, CaseStudyOverviewPage, SearchPage, EmployeeOverviewPage, TopLevelPage, FooterPage, ContactPage, VacancyPage, LoginPage, FieldOverviewPage, MethodOverviewPage, DocumentsPage, DocumentPage |
| (single-locale singletons) | 1 | ErrorPage, ContactSuccessPage, VacancyOverviewPage, VacancyFormPage |

**No FormSuccessPage rows online** — explains the long-standing compile WARN `App\Entity\Pages\FormSuccessPage skipped: no accepted columns`. Not a bug; just no data.

## Coverage breakdown — 28 entities

### ✅ Fully mapped (status=accepted, sensible target) — 14

These are good. Field maps may still be sparse, but the routing is correct.

| Kunstmaan Page | targetSection | targetEntryType | nodeClasses fields | pageBuilder |
|---|---|---|---:|---|
| CaseStudyOverviewPage | caseOverviewPage | caseOverviewPage | 1 | — |
| CaseStudyPage | casePages | casePage | 3 | pageBuilder |
| ContactPage | contactPage | contactPage | 3 | pageBuilder |
| ContactSuccessPage | thanksPages | contentPage | 1 | pageBuilder |
| DocumentsPage | handoutsPage | handoutsPage | 1 | — |
| EmployeeOverviewPage | teamOverviewPage | teamOverviewPage | 1 | — |
| **EmployeePage** | **teamPages** | **teamMember** | **0** | pageBuilderCondensed |
| ErrorPage | errorPage | errorPage | 3 | pageBuilder |
| FormSuccessPage | thanksPages | contentPage | (no rows) | — |
| HomePage | homePage | homePage | 3 | pageBuilder |
| NewsOverviewPage | newsOverviewPage | newsOverviewPage | 0 | pageBuilderCondensed |
| NewsPage | newsPages | newsPage | 1 | — |
| TextPage | contentPages | contentPage | 1 | — |
| TextPagePlus | contentPages | contentPage | 2 | — |

**EmployeePage has 0 fields[]** — the section change shipped today; field mappings still need to come from re-analyze (will populate `_rel:employee.<col>` references like `role ← _rel:employee.job_title`, `linkEmail ← _rel:employee.email`, etc.).

### ⚠️ Routed to fallback `contentPage` (status=needs-review with sensible target) — 8

These have a reasonable target but `status=needs-review`, so MappingCompiler ignores them and routes via `Settings::defaultEntryType=contentPage` fallback. **Easy fix: flip `status: needs-review` → `status: accepted` per row.** No re-analyze needed.

| Kunstmaan Page | LLM-proposed target | Sensible? |
|---|---|---|
| DocumentPage | contentPages | ✅ yes — generic doc page fits contentPages |
| EventRegisterPage | contentPages | 🟡 OK — no event-specific section in Craft |
| FieldOverviewPage | contentPages | ✅ yes — overview page |
| FieldPage | contentPages | ✅ yes — generic content (FieldPage has heading, content, summary, image — all map to pageBuilder blocks) |
| FormPage | contentPages | 🟡 OK — no form-specific section |
| MethodOverviewPage | contentPages | ✅ yes |
| MethodPage | contentPages | ✅ yes — same shape as FieldPage |
| SearchPage | searchPage / commonEntry | ✅ yes — section exists, just unaccepted |
| VacancyOverviewPage | contentPages | 🟡 OK — no vacancy-specific section |
| VacancyPage | contentPages | 🟡 OK — could justify a `vacancyPages` section but not required |

### ❌ status=needs-review with **empty targetSection** — 4

These genuinely need an operator decision. The LLM didn't pick a section.

| Kunstmaan Page | Suggested targets | Decision needed |
|---|---|---|
| FooterPage | `globalSettings` (single)? Or a new `footerSettings`? | Likely globalSettings — Kunstmaan FooterPage usually models footer config. CQM's globalSettings is single. Could fold into it. |
| LoginPage | `globalSettings`? Or DROP? | Probably DROP — login is a Craft built-in. Or migrate as a contentPage with the legacy login content (heading, subtext) preserved for reference. |
| TopLevelPage | DROP? Or `contentPages`? | Likely DROP — TopLevelPage is usually a Kunstmaan structural marker, not user-facing content. |
| VacancyFormPage | (currently has tgtType=`formContentBlock` — wrong, that's a block, not entry-type) | DROP or contentPages. The `formContentBlock` is a page-builder block, not a section target. |

## Taxonomies

| Taxonomy | Status | targetSection | targetEntryType | Action |
|---|---|---|---|---|
| App\Entity\CaseStudyCategory | accepted | caseCategories | caseCategory | ✅ correct |
| App\Entity\NewsCategory | accepted | **(empty)** | newsCategory | ❌ **must fix** — set targetSection=`newsCategories` |
| App\Entity\Employee | dropped | (n/a) | (n/a) | ✅ correct (page-wins; EmployeePage absorbs) |
| App\Entity\NewsAuthor | needs-review | (empty) | teamMember | 🟡 decide — could relate to teamMember (one-of-many news authors) or DROP if NewsPage embeds via relation |
| App\Entity\CaseStudyAuthor | needs-review | casePages | casePage | 🟡 looks wrong — it's an author entity, not a page. Should probably be DROP and embedded via _rel: on CaseStudyPage |
| App\Entity\Document | needs-review | handoutsPage | documentRow | 🟡 looks like it should be DROPPED — Document data lives inside DocumentsPage's matrix |
| App\Entity\DocumentCategory | needs-review | (empty) | — | 🟡 likely DROP if no Craft category target |
| App\Entity\ListItem | needs-review | (empty) | bulletListItem | 🟡 should be DROP (it's a block-level item, embedded in matrix) |
| App\Entity\PageParts\TextSectionPagePart | (taxonomy mis-classified) | (empty) | textContentBlock | ❌ wrong kind — should be a pagePart, not taxonomy. Drop or fix during re-analyze. |
| Various supporting entities | dropped | — | — | ✅ correct (ClientItem, Configuration, EmployeeItem, EventRegistration, FooterItem, etc.) |

## Craft sections vs Kunstmaan coverage

Craft sections that ARE wired up to a Kunstmaan source:

✅ caseCategories (caseCategory taxonomy — 24 entries)
✅ caseOverviewPage (CaseStudyOverviewPage — 2 entries)
✅ casePages (CaseStudyPage — 100 entries)
✅ contactPage (ContactPage — 2)
✅ contentPages (TextPage, TextPagePlus + fallback bucket — **246 entries** — bloated)
✅ errorPage (ErrorPage — 2)
✅ handoutsPage (DocumentsPage — 2)
✅ homePage (HomePage — 2)
✅ newsCategories (gets newsCategory taxonomy — **2 entries** — likely under-populated, blocked by missing targetSection)
✅ newsOverviewPage (NewsOverviewPage — 2)
✅ newsPages (NewsPage — **51 entries**, but legacy has 437/2≈218 unique nodes — under-migrated; needs investigation)
✅ teamOverviewPage (EmployeeOverviewPage — 2)
✅ teamPages (EmployeePage — **2 entries** — section change just shipped; needs migrate run + fields[] populated)
✅ thanksPages (ContactSuccessPage / FormSuccessPage — 2)
🟡 searchPage (SearchPage — 2 — but proposal still needs-review)
🟡 topics (4 entries — unclear what populated this; not in current page mapping)

Craft sections **without a source** (probably operator-curated content or unused):

- cookieConsentPage (Craft built-in — 2 placeholder entries)
- globalSettings (Craft singleton — could host FooterPage)
- llmSettings (Craft AI singleton — not migration territory)

## Open coverage questions

1. **newsPages 51 entries vs legacy 437/2≈218 unique nodes** — there's a 4× shortfall. Need to investigate whether the migrate run filtered something or whether state-table de-duplication is hiding rows.
2. **contentPages 246 entries** — likely bloated by fallback routing. Once the 8 needs-review pages get accepted (sticking with contentPages), the count should make sense.
3. **`App\Entity\PageParts\TextSectionPagePart`** classified as `kind: taxonomy` — this is wrong. Should be `kind: pagePart`. The compile-stage taxonomy validator skips it; the page-part path doesn't pick it up. Loses textContentBlock coverage.

## Action plan (operator-curatable hand-edits)

These are small, deterministic edits to `mapping.yaml` proposals — no re-analyze needed.

| # | Edit | Impact |
|---:|---|---|
| 1 | Set `App\Entity\NewsCategory.targetSection: newsCategories` | newsCategory taxonomy starts migrating (currently skipped) |
| 2 | Flip 8 nodeClass rows from `status: needs-review` → `accepted` (DocumentPage, EventRegisterPage, FieldOverviewPage, FieldPage, FormPage, MethodOverviewPage, MethodPage, SearchPage, VacancyOverviewPage, VacancyPage) | 9 pages exit fallback, route to their proposed sections |
| 3 | Decide on the 4 empty-targetSection pages: FooterPage, LoginPage, TopLevelPage, VacancyFormPage | Stops compile fallback from masking them |
| 4 | Set Document, ListItem, EmployeeItem-like supporting entities to `status: dropped` (already done for some) | Cleans up "incomplete taxonomy" warnings |
| 5 | Re-classify `App\Entity\PageParts\TextSectionPagePart` as `kind: pagePart` (or drop) | Recovers textContentBlock coverage |

After (2)+(3) the compile fallback list drops from **14 to ≤4**. After re-analyze (when credits restored), the empty fields[] should populate for EmployeePage, NewsOverviewPage, etc.

## What's missing entirely (no source mapping at all)

Nothing major — every Kunstmaan page entity has SOME proposal. The gap is between "proposal exists" and "proposal accepted with the right target." The audit shows we're tracking 28/28 entities.

Page-parts coverage was not audited here (that's a separate dimension — see issue 6 / Phase 2 for implicit-content rows).
