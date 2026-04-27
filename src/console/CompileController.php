<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;
use Throwable;
use yii\console\ExitCode;

/**
 * Compile — derive the v1-shaped runtime ETL contract (`nodeClasses` /
 * `sections` / `sites`) from accepted column proposals + pageStructure.json
 * + Settings::localeMap. Writes the augmented mapping.yaml back via
 * MappingFile::writeAtomic.
 *
 * Why this exists: see MappingCompiler's docblock. The short version is that
 * v2's flat `proposals[]` audit trail and the ETL's nested `nodeClasses` /
 * `sections` / `sites` shape are two different contracts; without a step
 * that bridges them, `migrate` reads `[]` for nodeClasses and extracts zero
 * rows.
 *
 * Default behavior (no flags): refuses to overwrite existing `nodeClasses` /
 * `sections` / `sites` blocks — operators who hand-curate those structures
 * see a clear error rather than silent data loss. Pass `--overwrite` to
 * regenerate.
 *
 * Per-FQCN spot-update is intentionally NOT supported in v1.0 — the shape
 * is small enough to regenerate wholesale, and partial updates would
 * complicate idempotency + diff review.
 */
class CompileController extends Controller
{
    use NeverProductionTrait;

    public bool $overwrite = false;
    public bool $dryRun    = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'overwrite', 'dryRun',
        ]);
    }

    /**
     * `compile` (default) — derive nodeClasses + sections + sites and write
     * them back into mapping.yaml above the existing `proposals:` block.
     */
    public function actionIndex(): int
    {
        // D-20: NeverProduction guard FIRST.
        if (($gate = $this->enforceNeverProduction()) !== null) {
            return $gate;
        }

        $this->stdout("Compile: proposals + pageStructure → nodeClasses + sections + sites\n", Console::FG_CYAN);

        $plugin = Plugin::getInstance();
        $mappingPath = $plugin->mappingFile->resolvePath();

        // 1. Load mapping.yaml.
        try {
            $mapping = $plugin->mappingFile->load($mappingPath);
        } catch (Throwable $e) {
            $this->stderr("  FAIL load mapping.yaml: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $proposalCount = count((array) ($mapping['proposals'] ?? []));
        if ($proposalCount === 0) {
            $this->stderr("  FAIL mapping.yaml has no proposals — run `analyze` first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $this->stdout("  OK   mapping.yaml loaded ({$proposalCount} proposal rows)\n", Console::FG_GREEN);

        // 2. Refuse-to-overwrite guard.
        $hasExisting = isset($mapping['nodeClasses']) || isset($mapping['sections']) || isset($mapping['sites']);
        if ($hasExisting && !$this->overwrite) {
            $this->stderr(
                "  FAIL mapping.yaml already has nodeClasses / sections / sites blocks. "
                . "Pass --overwrite to regenerate (existing blocks will be replaced).\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }

        // 3. Load pageStructure.json (written by analyze).
        $storageDir = Craft::$app->path->getStoragePath() . '/migration';
        $pageStructurePath = $storageDir . '/pageStructure.json';
        if (!is_file($pageStructurePath)) {
            $this->stderr(
                "  FAIL pageStructure.json missing at {$pageStructurePath} — run `analyze` first.\n",
                Console::FG_RED,
            );
            return ExitCode::CONFIG;
        }
        $raw = (string) file_get_contents($pageStructurePath);
        $pageStructure = json_decode($raw, true);
        if (!is_array($pageStructure)) {
            $this->stderr("  FAIL pageStructure.json is not a JSON object.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        $this->stdout(
            "  OK   pageStructure.json loaded (" . count($pageStructure) . " page entities)\n",
            Console::FG_GREEN,
        );

        // 4. Resolve sites map. Precedence (highest first):
        //    a. existing mapping.yaml `sites:` block (operator-curated)
        //    b. Settings::localeMap (host config)
        //    c. auto-derived from schema-dump.json legacy locales × Craft sites
        //       (language-code match: legacy locale 'nl' → site whose language
        //       starts with 'nl-' or equals 'nl').
        $sites = (array) ($mapping['sites'] ?? []);
        $sitesSource = 'mapping.yaml sites: block';
        if ($sites === []) {
            $sites = (array) ($plugin->getSettings()->localeMap ?? []);
            $sitesSource = 'Settings::localeMap';
        }
        if ($sites === []) {
            $sites = $this->autoDeriveSitesFromLegacyLocales($storageDir);
            $sitesSource = 'auto-derived (schema-dump locales × Craft sites by language code)';
        }
        $this->stdout(sprintf(
            "  OK   sites map resolved: %d entries (source: %s)\n",
            count($sites),
            $sitesSource,
        ), Console::FG_GREEN);

        // 5. Compile.
        $compiled = $plugin->mappingCompiler->compile($mapping, $pageStructure, $sites);
        $report = $compiled['_compileReport'];

        if ((int) ($report['autoAssignedTargets'] ?? 0) > 0) {
            $this->stdout(sprintf(
                "  OK   auto-assigned targetEntryType on %d previously-empty proposal rows (basename heuristic)\n",
                (int) $report['autoAssignedTargets'],
            ), Console::FG_GREEN);
        }
        $this->stdout(sprintf(
            "  OK   compile produced %d nodeClasses + %d sections + %d sites\n",
            (int) $report['nodeClassesEmitted'],
            (int) $report['sectionsEmitted'],
            count($compiled['sites']),
        ), Console::FG_GREEN);

        if (!empty($report['skippedNodeClasses'])) {
            $this->stdout(
                "  WARN skipped " . count($report['skippedNodeClasses']) . " page entities:\n",
                Console::FG_YELLOW,
            );
            foreach ($report['skippedNodeClasses'] as $line) {
                $this->stdout("        - {$line}\n", Console::FG_YELLOW);
            }
        }
        foreach ((array) $report['warnings'] as $w) {
            $this->stdout("  WARN {$w}\n", Console::FG_YELLOW);
        }

        // 6. Dry-run early exit.
        if ($this->dryRun) {
            $this->stdout("  WARN dry-run — mapping.yaml NOT written. Drop --dry-run to persist.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        // 7. Serialize + write atomically.
        unset($compiled['_compileReport']); // never persist the report into mapping.yaml
        // Order keys so reads land in the operator-friendly order:
        //   sites → sections → nodeClasses → proposals (small-to-large)
        $ordered = [
            'sites'       => $compiled['sites'],
            'sections'    => $compiled['sections'],
            'nodeClasses' => $compiled['nodeClasses'],
            'proposals'   => $compiled['proposals'],
        ];
        try {
            $yaml = SymfonyYaml::dump($ordered, 8, 2, SymfonyYaml::DUMP_NULL_AS_TILDE);
        } catch (Throwable $e) {
            $this->stderr("  FAIL yaml dump: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $ok = $plugin->mappingFile->writeAtomic($mappingPath, $yaml);
        if (!$ok) {
            $this->stderr("  FAIL writeAtomic to {$mappingPath}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("  OK   mapping.yaml written → {$mappingPath}\n", Console::FG_GREEN);

        $this->stdout("\nCompile: PASS\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Derive a candidate locale → siteHandle map from schema-dump.json's
     * `locales` list cross-referenced with Craft's configured sites by
     * language-code prefix. Returns [] when either side is empty or no
     * languages match — operator must then hand-curate Settings::localeMap
     * or the mapping.yaml sites: block.
     *
     * Match rules:
     *   - exact: legacy locale equals Craft site language (e.g. 'en' === 'en')
     *   - prefix: legacy locale equals first segment of Craft language
     *     (e.g. 'nl' matches 'nl-NL' / 'nl-BE')
     *
     * When multiple Craft sites match a legacy locale, the primary site wins.
     *
     * @return array<string, string>  legacy locale → Craft site handle
     */
    private function autoDeriveSitesFromLegacyLocales(string $storageDir): array
    {
        $schemaPath = $storageDir . '/schema-dump.json';
        if (!is_file($schemaPath)) {
            return [];
        }
        $raw = (string) file_get_contents($schemaPath);
        $schema = json_decode($raw, true);
        $legacyLocales = (array) ($schema['locales'] ?? []);
        if ($legacyLocales === []) {
            return [];
        }

        $craftSites = Craft::$app->sites->getAllSites();
        if ($craftSites === []) {
            return [];
        }

        $out = [];
        foreach ($legacyLocales as $legacy) {
            $legacy = (string) $legacy;
            if ($legacy === '') {
                continue;
            }
            $bestHandle = null;
            $bestPrimary = false;
            foreach ($craftSites as $site) {
                $lang = (string) $site->language;
                $matches = ($lang === $legacy)
                    || (strpos($lang, $legacy . '-') === 0);
                if (!$matches) {
                    continue;
                }
                if ($bestHandle === null || (!$bestPrimary && $site->primary)) {
                    $bestHandle = (string) $site->handle;
                    $bestPrimary = (bool) $site->primary;
                }
            }
            if ($bestHandle !== null) {
                $out[$legacy] = $bestHandle;
            }
        }
        return $out;
    }
}
