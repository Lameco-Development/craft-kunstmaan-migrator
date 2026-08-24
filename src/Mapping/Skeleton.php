<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

use Lameco\Kunstmaanmigrator\Source\EntityTableIndex;

use Lameco\Kunstmaanmigrator\Source\Introspection;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;

/**
 * Generates a mapping skeleton from a live Kunstmaan database.
 *
 * The point is that nobody hand-writes the inventory. Every live pagepart class, page type,
 * locale, real table name, real column list and child collection is discovered and written
 * out; what a human supplies is the half a machine cannot know — which Craft block each
 * legacy part becomes.
 *
 * Every generated entry is deliberately *invalid* until someone fills it in: parts have no
 * disposition, so `validate` fails until each one is resolved. A skeleton that passed
 * validation would be a skeleton you could forget to finish.
 */
final class Skeleton
{
    /** Kunstmaan's child-collection foreign keys are conventionally `<part>_pp_id`. */
    private const CHILD_FK_SUFFIX = '_pp_id';

    /** Columns every pagepart table has; never worth listing as unmapped. */
    private const BORING_COLUMNS = ['id'];

    public function __construct(
        private readonly EntityTableIndex $entities,
        private readonly ?Introspection $introspection = null,
    ) {
    }

    /** @param array<string, LegacyDatabase> $databases environment => connection */
    public function generate(array $databases): string
    {
        $parts = [];
        $pages = [];
        $locales = [];

        foreach ($databases as $env => $db) {
            foreach ($db->livePartPlacements() as $class => $n) {
                $parts[$class] = ($parts[$class] ?? 0) + $n;
            }

            foreach ($db->livePageTypes() as $entity => $n) {
                $pages[$entity] = ($pages[$entity] ?? 0) + $n;
            }

            $locales[$env] = $db->livePagesByLocale();
        }

        arsort($parts);
        arsort($pages);

        $probe = reset($databases);
        $childTables = $probe instanceof LegacyDatabase ? $this->childTables($probe) : [];

        $out = $this->header($parts, $pages);
        $out .= $this->environments($databases, $locales);
        $out .= $this->pagesSection($pages);
        $out .= $this->entitiesSection($probe instanceof LegacyDatabase ? $probe : null);
        $out .= $this->sequenceSection();
        $out .= $this->partsSection($parts, $probe, $childTables);
        $out .= $this->sidecarsSection($probe instanceof LegacyDatabase ? $probe : null);
        $out .= $this->tailSections();

        return $out;
    }

    /**
     * Candidate entities — the non-node tables pages and parts relate to.
     *
     * A mapping whose entities lane starts empty stays empty: nothing else ever
     * mentions the taxonomy tables, and every page FK into one is a relation with
     * nowhere to point. The introspection artifact knows them exactly — they are
     * the Doctrine association targets of the page and pagepart classes — so the
     * skeleton lists each with its real table and a title guess, and fails
     * validation until section, entry type and the dedupe decision are filled in.
     */
    private function entitiesSection(?LegacyDatabase $db): string
    {
        $candidates = $this->entityCandidates();

        $out = "\n# ─────────────────────────────────────────────────────────────────────────────\n";
        $out .= "# Non-node tables that become entries of their own — every page FK into one is a\n";
        $out .= "# relation with nowhere to point until the table migrates; a map reaches it with\n";
        $out .= "# `ref(<Name>)`. dedupe: true when the same id means the same thing in every\n";
        $out .= "# database; false when ids are reused for unrelated rows per database.\n";
        $out .= "entities:";

        if ($candidates === []) {
            return $out . " {}\n";
        }

        foreach ($candidates as $name => $candidate) {
            // Rows, not placements: an entity table's rows are the entries it becomes.
            $rows = $db?->countOrNull($candidate['table']);

            $out .= sprintf("\n  %s:\n", $name);
            $out .= sprintf("    # related to by %s\n", implode(', ', $candidate['referencedBy']));

            if ($rows !== null) {
                $out .= sprintf("    live: %d\n", $rows);
            }

            $out .= sprintf("    table: %s\n", $candidate['table']);
            $out .= "    section: ~                          # TODO: Craft section handle\n";
            $out .= "    entryType: ~                        # TODO: Craft entry type handle\n";
            $out .= sprintf("    title: %s\n", $candidate['title'] ?? '~                            # TODO: the column that names the entry');
            $out .= "    dedupe: ~                           # TODO: true or false — see above\n";

            if ($candidate['columns'] !== []) {
                $out .= sprintf("    unreviewed: [%s]\n", implode(', ', $candidate['columns']));
            }
        }

        return $out . "\n";
    }

    /**
     * @return array<string, array{table: string, title: ?string, columns: list<string>, referencedBy: list<string>}>
     */
    private function entityCandidates(): array
    {
        if ($this->introspection === null) {
            return [];
        }

        $entities = $this->introspection->entities;
        $out = [];

        foreach ($entities as $class => $spec) {
            $basename = self::basename((string) $class);

            // Relations *from* the content tree are what make a table content.
            if (!str_ends_with($basename, 'Page') && !str_ends_with($basename, 'PagePart')) {
                continue;
            }

            foreach ((array) ($spec['associations'] ?? []) as $assoc) {
                $target = (string) ($assoc['target'] ?? '');
                $targetBase = self::basename($target);

                if ($target === ''
                    || str_starts_with($target, 'Kunstmaan\\')
                    || str_starts_with($target, 'Gedmo\\')
                    || str_ends_with($targetBase, 'Page')
                    || str_ends_with($targetBase, 'PagePart')
                    || !isset($entities[$target])
                    || ($entities[$target]['mappedSuperclass'] ?? false)
                    || !in_array($assoc['kind'] ?? '', ['ManyToOne', 'ManyToMany'], true)) {
                    continue;
                }

                $columns = array_keys((array) ($entities[$target]['columns'] ?? []));
                $columnNames = [];

                foreach ((array) ($entities[$target]['columns'] ?? []) as $field => $column) {
                    $columnNames[] = (string) (is_array($column) ? ($column['column'] ?? $field) : $field);
                }

                $title = in_array('name', $columnNames, true) ? 'name'
                    : (in_array('title', $columnNames, true) ? 'title' : null);

                $out[$targetBase] ??= [
                    'table' => (string) ($entities[$target]['table'] ?? ''),
                    'title' => $title,
                    'columns' => array_values(array_diff($columnNames, ['id', $title ?? ''])),
                    'referencedBy' => [],
                ];
                $out[$targetBase]['referencedBy'][] = $basename;
            }
        }

        foreach ($out as &$candidate) {
            $candidate['referencedBy'] = array_values(array_unique($candidate['referencedBy']));
            sort($candidate['referencedBy']);
        }

        ksort($out);

        return $out;
    }

    private static function basename(string $class): string
    {
        $parts = explode('\\', $class);

        return (string) end($parts);
    }

    /**
     * Candidate sidecar tables, discovered by column signature rather than by name.
     *
     * A per-page tab entity — Enreach's header tab, another site's whatever-it-calls-it —
     * always carries Kunstmaan's polymorphic ref pair, `ref_entity_name` + `ref_id`. That
     * pair is the whole convention, so any non-core table holding both is offered here with
     * its columns as map candidates. What a human supplies is the half a machine cannot
     * know: which Craft page fields each column becomes.
     */
    private function sidecarsSection(?LegacyDatabase $db): string
    {
        $out = "\n# ─────────────────────────────────────────────────────────────────────────────\n";
        $out .= "# Per-page sidecar entities, keyed by the polymorphic (ref_entity_name, ref_id)\n";
        $out .= "# pair — header/footer tabs, structured data. Discovered by column signature;\n";
        $out .= "# map each table's columns onto page fields, or declare drop:/manual: with a reason.\n";
        $out .= "sidecars:";

        $found = 0;

        foreach ($db?->tables() ?? [] as $table) {
            if (str_starts_with($table, 'kuma_')) {
                continue;
            }

            $columns = $db->columns($table);

            if (!in_array('ref_entity_name', $columns, true) || !in_array('ref_id', $columns, true)) {
                continue;
            }

            $found++;
            $rest = array_values(array_diff($columns, ['id', 'ref_id', 'ref_entity_name']));
            $out .= sprintf("\n  %s:\n", $this->sidecarName($table));
            $out .= sprintf("    live: %d\n", $db->liveSidecarRows($table));
            $out .= sprintf("    table: %s\n", $table);
            $out .= "    map: {}\n";
            $out .= sprintf("    ignore: [%s]\n", implode(', ', $rest));
        }

        if ($found === 0) {
            $out .= " {}\n";
        }

        return $out . "\n";
    }

    /** `lameco_websitebundle_header_tabs` → `headerTab`; the mapping key is free to differ. */
    private function sidecarName(string $table): string
    {
        $token = (string) preg_replace('/^[a-z0-9]+_[a-z0-9]+bundle_/', '', $table);
        $token = rtrim($token, 's');

        return lcfirst(str_replace('_', '', ucwords($token, '_')));
    }

    /** @param array<string, int> $parts @param array<string, int> $pages */
    private function header(array $parts, array $pages): string
    {
        return <<<YAML
            # Mapping skeleton — generated by `craft kunstmaan-migrator/mapping/init`.
            #
            # Discovered from the live legacy database: {$this->count($parts)} pagepart classes
            # ({$this->sum($parts)} live placements) and {$this->count($pages)} page types
            # ({$this->sum($pages)} live pages).
            #
            # WHAT YOU FILL IN
            #   parts.*   — the Craft block each legacy pagepart becomes, and its field map.
            #   pages.*   — the section + entry type each legacy page becomes.
            #   ignore:   — every legacy column you are deliberately not migrating, each with a
            #               reason: `ignore: {latitude: "folded into partnerAddress"}`.
            #   unreviewed: — what this generator could not place. `mapping/check` fails
            #               while any entry remains, so the file cannot look finished before it is.
            #
            # This file does NOT validate as generated: every part lacks a disposition, so
            # `mapping/check` fails until each one is resolved. That is intentional — a
            # skeleton you could forget to finish is worse than one that fails loudly.
            #
            # Fill it in from Utilities → Kunstmaan Migration, or here directly, then run
            # `craft kunstmaan-migrator/mapping/check <this file>` as you go.

            version: 1


            YAML;
    }

    /**
     * @param array<string, LegacyDatabase> $databases
     * @param array<string, array<string, int>> $locales
     */
    private function environments(array $databases, array $locales): string
    {
        $out = "# ─────────────────────────────────────────────────────────────────────────────\n";
        $out .= "# Every legacy locale needs a Craft site handle, or an explicit !unmapped declaration.\n";
        $out .= "environments:\n";

        foreach ($databases as $env => $db) {
            $out .= sprintf("  %s:\n", $env);
            $out .= sprintf("    database: %s\n", $db->database);
            $out .= "    mediaRoot: ~                        # TODO: legacy media root on disk\n";
            $out .= "    locales:\n";

            foreach ($locales[$env] ?? [] as $lang => $pages) {
                $out .= sprintf(
                    "      %-10s ~                     # TODO: Craft site handle — %s live pages\n",
                    $lang . ':',
                    number_format($pages),
                );
            }

            $out .= "\n";
        }

        return $out;
    }

    /** @param array<string, int> $pages */
    private function pagesSection(array $pages): string
    {
        $out = "# ─────────────────────────────────────────────────────────────────────────────\n";
        $out .= "pages:\n";

        foreach ($pages as $entity => $live) {
            $table = $this->entities->tableFor($entity);
            $out .= sprintf("  %s:\n", $entity);
            $out .= sprintf("    live: %d\n", $live);
            $out .= sprintf("    table: %s\n", $table ?? '~                    # TODO: source table');
            $out .= "    section: ~                          # TODO\n";
            $out .= "    entryType: ~                        # TODO\n";
        }

        return $out . "\n";
    }

    private function sequenceSection(): string
    {
        return <<<'YAML'
            # ─────────────────────────────────────────────────────────────────────────────
            # Window rules over the ordered part list of a context, applied before any block is
            # emitted. Use these where one Craft block corresponds to a *run* of legacy parts —
            # most commonly an inline heading part absorbed by the block that follows it.
            #
            # sequence:
            #   - id: heading-absorb
            #     match: Header > *
            #     guard: target.hasField(heading) && target.heading == empty
            #     action: absorb
            #     map: { heading: head.title }
            #     runs: first
            #     else: heading-standalone
            sequence: []


            YAML;
    }

    /**
     * @param array<string, int> $parts
     * @param array<string, list<array{table: string, fk: string}>> $childTables
     */
    private function partsSection(array $parts, LegacyDatabase|false $probe, array $childTables): string
    {
        $out = "# ─────────────────────────────────────────────────────────────────────────────\n";
        $out .= "# Ordered by live volume: the top of this list is where the content actually is.\n";
        $out .= "parts:\n";

        foreach ($parts as $class => $live) {
            $table = $this->entities->tableFor($class);

            $out .= sprintf("  %s:\n", $class);
            $out .= sprintf("    live: %d\n", $live);
            $out .= sprintf("    table: %s\n", $table ?? '~                    # TODO: source table');
            $out .= "    block: ~                            # TODO: Craft block handle,\n";
            $out .= "                                        #  or drop:/manual:/consumedBy:\n";

            if ($table !== null && $probe instanceof LegacyDatabase && $probe->hasTable($table)) {
                $columns = array_values(array_diff($probe->columns($table), self::BORING_COLUMNS));

                if ($columns !== []) {
                    $out .= "    map: {}                             # TODO: legacy column -> Craft field\n";
                    // Not `ignore:`. A generator cannot know what is deliberately dropped, and
                    // writing its leftovers there makes them indistinguishable from decisions.
                    $out .= sprintf(
                        "    unreviewed: [%s]\n",
                        implode(', ', $columns),
                    );
                }

                // Doctrine relations are authoritative; the FK-name heuristic is the fallback.
                $children = $this->entities->childrenOf($class) ?? $childTables[$table] ?? [];

                if ($children !== []) {
                    $out .= "    children:\n";

                    // A part may own several collections (Feature has both feature_items and
                    // feature_highlight_items), so each needs its own placeholder key — a
                    // repeated `~:` would silently collapse to one entry.
                    foreach ($children as $i => $child) {
                        $childColumns = array_values(array_diff(
                            $probe->columns($child['table']),
                            [...self::BORING_COLUMNS, $child['fk'], 'weight'],
                        ));

                        $out .= sprintf(
                            "      TODO_%d:                           # TODO: target Matrix field handle for %s\n",
                            $i + 1,
                            $child['table'],
                        );
                        $out .= sprintf("        table: %s\n", $child['table']);
                        $out .= sprintf("        fk: %s\n", $child['fk']);
                        $out .= "        order: weight\n";
                        $out .= sprintf("        unreviewed: [%s]\n", implode(', ', $childColumns));
                    }
                }
            }
        }

        return $out . "\n";
    }

    private function tailSections(): string
    {
        return <<<'YAML'
            # ─────────────────────────────────────────────────────────────────────────────
            # Legacy form-context parts become a Formie form plus a block that references it.
            forms:
              context: form
              target: formie
              fields: {}

            # Footer / navigation contexts become globals, not entries.
            globals: {}

            # Legacy redirect pages become Retour rules.
            redirects: {}

            transforms: {}

            # Deliberate non-goals. Anything live that appears in no other lane must be declared
            # here with a reason, or `coverage` reports it as a hole and exits non-zero.
            unmapped:
              pageTypes: {}
              parts: {}
            YAML;
    }

    /**
     * Child collection tables, keyed by the parent pagepart table.
     *
     * Discovery is by the `<token>_pp_id` foreign-key convention. The token does not map
     * cleanly onto a table name: `benefit_pp_id` belongs to `..._benefits_page_parts`
     * (plural), and `google_maps_pp_id` suffix-matches both `..._google_maps_page_parts`
     * and `..._content_google_maps_page_parts`. Ambiguity resolves to the shortest
     * candidate, which is the one whose token starts at a real boundary.
     *
     * @return array<string, list<array{table: string, fk: string}>>
     */
    private function childTables(LegacyDatabase $db): array
    {
        $partTables = array_values(array_filter(
            $db->tables(),
            static fn(string $t): bool => str_ends_with($t, '_page_parts'),
        ));

        $children = [];

        foreach ($db->tables() as $table) {
            if (str_ends_with($table, '_page_parts')) {
                continue;
            }

            foreach ($db->columns($table) as $column) {
                if (!str_ends_with($column, self::CHILD_FK_SUFFIX)) {
                    continue;
                }

                $token = substr($column, 0, -strlen(self::CHILD_FK_SUFFIX));
                $parent = $this->parentTableFor($token, $partTables);

                if ($parent !== null) {
                    $children[$parent][] = ['table' => $table, 'fk' => $column];
                }
            }
        }

        return $children;
    }

    /**
     * @param list<string> $partTables
     */
    private function parentTableFor(string $token, array $partTables): ?string
    {
        $candidates = [];

        foreach ($partTables as $table) {
            foreach ([$token, $token . 's'] as $form) {
                if ($table === $form . '_page_parts' || str_ends_with($table, '_' . $form . '_page_parts')) {
                    $candidates[] = $table;

                    break;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));

        return $candidates[0];
    }

    /** @param array<string, int> $counts */
    private function count(array $counts): string
    {
        return number_format(count($counts));
    }

    /** @param array<string, int> $counts */
    private function sum(array $counts): string
    {
        return number_format(array_sum($counts));
    }
}
