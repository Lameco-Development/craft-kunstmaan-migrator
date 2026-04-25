<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
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
     * @param list<string> $detected
     */
    private function renderLocales(array $detected): string
    {
        if ($detected === []) {
            return "## Locales\n\nNo locales detected in `kuma_node_translations`.";
        }

        $craftHandles = [];
        foreach (Craft::$app->getSites()->getAllSites() as $s) {
            $craftHandles[] = (string) $s->handle;
        }
        $settingsLocales = (array) Plugin::getInstance()->getSettings()->defaultLocales;

        $unmapped = [];
        foreach ($detected as $l) {
            if (!in_array($l, $craftHandles, true) && !in_array($l, $settingsLocales, true)) {
                $unmapped[] = $l;
            }
        }

        $primaryHandle = (string) (Craft::$app->getSites()->getPrimarySite()->handle ?? 'default');

        $out = "## Locales\n\n"
            . "Detected Kunstmaan locales: " . implode(', ', $detected) . "\n"
            . "Currently mapped (Craft sites + Settings::defaultLocales): "
                . implode(', ', array_unique(array_merge($craftHandles, $settingsLocales))) . "\n"
            . "Unmapped: " . ($unmapped === [] ? '(none)' : implode(', ', $unmapped)) . "\n";

        if ($unmapped !== []) {
            $out .= "\nAdd these to your Craft `config/sites.php` (or set Settings::defaultLocales to map them):\n\n"
                . "```php\nreturn [\n";
            foreach ($detected as $l) {
                $suggested = in_array($l, $craftHandles, true) ? $l : $primaryHandle;
                $out .= "    '{$l}' => ['language' => '{$l}', 'baseUrl' => 'https://example.com/'],   // suggested handle: {$suggested}\n";
            }
            $out .= "    // ...\n];\n```\n\nRe-run analyze after the sites are mapped.\n";
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
