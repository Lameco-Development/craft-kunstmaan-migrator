<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\locale\LocalePreflight;
use yii\base\Component;

/**
 * Renders REPORT.md from the schema dump + mapping proposals.
 *
 * Sections:
 *   1. Header (analyze timestamp, driver, table count)
 *   2. Locales (D-17 LOC-01 — paste-ready Craft sites: block when unmapped)
 *   3. Tables overview (top 25 by row count)
 *   4. Mapping summary (counts per status: accepted / proposed / dropped / needs-review)
 *
 * Per CONTEXT.md Claude's Discretion: "Aim one screenful per section."
 *
 * No file I/O — caller writes via MappingFile::writeAtomic.
 */
final class ReportBuilder extends Component
{
    /**
     * @param array{
     *   generatedAt: string, driver: string,
     *   tables: array<string, int>,
     *   columns: array<string, list<array<string, mixed>>>,
     *   locales: list<string>,
     * } $schemaDump
     * @param list<array<string, mixed>> $mappingProposals
     */
    public function render(array $schemaDump, array $mappingProposals): string
    {
        $sections = [];
        $sections[] = $this->renderHeader($schemaDump);
        $sections[] = $this->renderLocales($schemaDump['locales'] ?? []);
        $sections[] = $this->renderTablesOverview($schemaDump);
        $sections[] = $this->renderMappingSummary($mappingProposals);

        return implode("\n\n", array_filter($sections, static fn(string $s): bool => $s !== '')) . "\n";
    }

    /**
     * @param array<string, mixed> $schemaDump
     */
    private function renderHeader(array $schemaDump): string
    {
        $tableCount = count($schemaDump['tables'] ?? []);
        $columnCount = 0;
        foreach (($schemaDump['columns'] ?? []) as $cols) {
            $columnCount += count($cols);
        }
        $generatedAt = (string) ($schemaDump['generatedAt'] ?? '');
        $driver = (string) ($schemaDump['driver'] ?? '');
        return "# Kunstmaan Migration — Analyze Report\n\n"
            . "Generated: {$generatedAt}\n"
            . "Driver: {$driver}\n"
            . "Tables scanned: {$tableCount}\n"
            . "Columns scanned: {$columnCount}\n";
    }

    /**
     * D-17 LOC-01: paste-ready Craft sites: block when locales are unmapped.
     *
     * Public so AnalyzeController can render the same block on its locale-preflight
     * failure path (without that, the operator hits a hard-FAIL with no concrete
     * YAML to copy — the full REPORT.md is never written when preflight blocks).
     *
     * @param list<string> $detected
     */
    public function renderLocales(array $detected): string
    {
        if ($detected === []) {
            return "## Locales\n\nNo locales detected in `kuma_node_translations`.";
        }

        // Collect both Craft site handles AND their BCP 47 languages — either
        // can match a legacy Kunstmaan locale. (`?site=default` may have
        // language `nl-NL`; the language is the locale-meaningful field.)
        $craftHandles = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            $h = (string) $s->handle;
            $l = (string) $s->language;
            if ($h !== '') { $craftHandles[] = $h; }
            if ($l !== '' && $l !== $h) { $craftHandles[] = $l; }
        }
        $craftHandles = array_values(array_unique($craftHandles));
        $settingsLocales = (array) Plugin::getInstance()->getSettings()->defaultLocales;
        $resolved = Plugin::getInstance()->localePreflight->resolve($detected);

        // Split detected locales by resolution type for the report.
        $unmapped     = [];
        $prefixHits   = []; // legacy → craft handle (suggest localeMap entry)
        $exactHits    = [];
        $explicitHits = []; // legacy → craft handle (already in localeMap)
        foreach ($resolved as $legacy => $detail) {
            if (!$detail['matched']) {
                $unmapped[] = $legacy;
                continue;
            }
            switch ($detail['via']) {
                case 'localeMap': $explicitHits[$legacy] = $detail['target']; break;
                case 'prefix':    $prefixHits[$legacy]   = $detail['target']; break;
                default:          $exactHits[$legacy]    = $detail['target']; break;
            }
        }

        $out = "## Locales\n\n"
            . "Detected Kunstmaan locales: " . implode(', ', $detected) . "\n"
            . "Currently mapped (Craft sites + Settings::defaultLocales): "
                . implode(', ', array_unique(array_merge($craftHandles, $settingsLocales))) . "\n"
            . "Unmapped: " . ($unmapped === [] ? '(none)' : implode(', ', $unmapped)) . "\n";

        if ($explicitHits !== []) {
            $out .= "\nResolved via Settings::localeMap:\n";
            foreach ($explicitHits as $l => $t) {
                $out .= "  - {$l} → {$t}\n";
            }
        }
        if ($prefixHits !== []) {
            $out .= "\nResolved by language-prefix (loose match):\n";
            foreach ($prefixHits as $l => $t) {
                $out .= "  - {$l} → {$t}\n";
            }
        }

        if ($unmapped !== []) {
            // For each unmapped locale, suggest BOTH a localeMap entry (if a near-match
            // craft handle exists by language prefix that simply hasn't matched yet —
            // shouldn't occur after the resolve() pass, but defensive) and a fresh site.
            $out .= "\nUnmapped locale(s) — choose ONE of these per locale:\n\n"
                . "**Option A — set `Settings::localeMap`** (recommended when a Craft site exists with a different handle):\n\n"
                . "```php\n// config/kunstmaan-migrator.php\nreturn [\n    '*' => [\n        'localeMap' => [\n";
            foreach ($unmapped as $l) {
                $prefix = LocalePreflight::languagePrefix($l);
                $hint = '';
                foreach ($craftHandles as $h) {
                    if (LocalePreflight::languagePrefix($h) === $prefix) {
                        $hint = " // suggested: \$craftHandle = '{$h}'";
                        break;
                    }
                }
                $out .= "            '{$l}' => '<craft-site-handle>',{$hint}\n";
            }
            $out .= "        ],\n    ],\n];\n```\n\n"
                . "**Option B — add new Craft sites** (when the legacy locale should land on its own dedicated site):\n\n"
                . "```php\n// config/sites.php\nreturn [\n";
            foreach ($unmapped as $l) {
                $out .= "    '{$l}' => ['language' => '{$l}', 'baseUrl' => 'https://example.com/'],\n";
            }
            $out .= "    // ...\n];\n```\n\nRe-run analyze after either change.\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schemaDump
     */
    private function renderTablesOverview(array $schemaDump): string
    {
        $tables = $schemaDump['tables'] ?? [];
        if ($tables === []) {
            return "## Tables\n\nNo tables found.";
        }
        arsort($tables);
        $top = array_slice($tables, 0, 25, true);
        $lines = ["## Tables (top 25 by row count)\n"];
        $lines[] = "| Table | Rows |";
        $lines[] = "|-------|-----:|";
        foreach ($top as $t => $n) {
            $lines[] = sprintf('| `%s` | %d |', $t, $n);
        }
        if (count($tables) > 25) {
            $lines[] = "\n_…and " . (count($tables) - 25) . " more._";
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $mappingProposals
     */
    private function renderMappingSummary(array $mappingProposals): string
    {
        if ($mappingProposals === []) {
            return "## Mapping Summary\n\nNo proposals yet. Run analyze first.";
        }
        $counts = ['accepted' => 0, 'proposed' => 0, 'dropped' => 0, 'needs-review' => 0, '(other)' => 0];
        foreach ($mappingProposals as $row) {
            $s = (string) ($row['status'] ?? '');
            if (isset($counts[$s])) {
                $counts[$s]++;
            } else {
                $counts['(other)']++;
            }
        }
        $total = count($mappingProposals);
        $lines = ["## Mapping Summary\n", "Total rows: {$total}\n"];
        foreach ($counts as $status => $n) {
            $lines[] = "- {$status}: {$n}";
        }
        return implode("\n", $lines);
    }
}
