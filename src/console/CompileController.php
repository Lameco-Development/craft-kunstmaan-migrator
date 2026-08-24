<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\console;

use lameco\kunstmaanmigrator\OptionalPlugins;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use lameco\kunstmaanmigrator\audit\PageRootedCoverageAuditor;
use lameco\kunstmaanmigrator\audit\PageRootedSurfaceDiscovery;
use lameco\kunstmaanmigrator\compile\GraphCompatibilityValidator;
use lameco\kunstmaanmigrator\NeverProductionTrait;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\workflow\CompileWorkflow;
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

        $result = (new CompileWorkflow())->run([
            'overwrite' => $this->overwrite,
            'dryRun' => $this->dryRun,
        ], function (array $event): void {
            $stream = (string) ($event['stream'] ?? 'stdout');
            $message = (string) ($event['message'] ?? '');
            if ($stream === 'stderr') {
                $this->stderr($message);
                return;
            }
            $this->stdout($message);
        });

        return (int) ($result['summary']['exitCode'] ?? ExitCode::UNSPECIFIED_ERROR);
    }

    private function summarizeGraphCompatibilityRows(array $rows): array
    {
        $out = [];
        $relationIntent = [];

        foreach ($rows as $row) {
            if (
                (string) ($row['severity'] ?? '') !== 'fatal'
                && (string) ($row['code'] ?? '') === 'relation_intent_required'
            ) {
                $source = (string) ($row['sourceRef'] ?? '');
                $target = (string) ($row['targetRef'] ?? '');
                $relationIntent[] = $source . ($target !== '' ? ' -> ' . $target : '');
                continue;
            }

            $out[] = $row;
        }

        if ($relationIntent !== []) {
            $examples = array_slice($relationIntent, 0, 5);
            $suffix = count($relationIntent) > count($examples)
                ? '; examples: ' . implode(', ', $examples) . ', +' . (count($relationIntent) - count($examples)) . ' more'
                : '; examples: ' . implode(', ', $examples);
            $out[] = [
                'severity' => 'warning',
                'code' => 'relation_intent_required',
                'message' => count($relationIntent) . ' graph relation(s) have FK evidence but no explicit intent yet (reference, promote, embed, drop, or out_of_scope)' . $suffix,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function summarizeCompileWarnings(array $warnings): array
    {
        $out = [];
        $pageBuilderGroups = [];
        $deduped = [];

        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                continue;
            }
            if (preg_match(
                '/^(?<fqcn>[^:]+): pageBuilderHandle `(?<matrix>[^`]+)` not propagated for (?<source>.+?) because entry-type `(?<entryType>[^`]+)` does not own that Matrix field(?<tail>.*)$/',
                $warning,
                $m,
            ) === 1) {
                $hasFallback = str_contains($m['tail'], 'flatPagePartContent');
                $key = implode('|', [$m['fqcn'], $m['matrix'], $m['entryType'], $hasFallback ? 'fallback' : 'no-fallback']);
                $pageBuilderGroups[$key]['fqcn'] = $m['fqcn'];
                $pageBuilderGroups[$key]['matrix'] = $m['matrix'];
                $pageBuilderGroups[$key]['entryType'] = $m['entryType'];
                $pageBuilderGroups[$key]['hasFallback'] = $hasFallback;
                $pageBuilderGroups[$key]['sources'][] = $m['source'];
                continue;
            }

            $deduped[$warning] = ($deduped[$warning] ?? 0) + 1;
        }

        foreach ($deduped as $warning => $count) {
            $out[] = $count > 1 ? "{$warning} (repeated {$count}x)" : $warning;
        }

        foreach ($pageBuilderGroups as $group) {
            $sources = array_values(array_unique((array) $group['sources']));
            $examples = array_slice($sources, 0, 3);
            $suffix = count($sources) > count($examples)
                ? ', +' . (count($sources) - count($examples)) . ' more'
                : '';
            $out[] = sprintf(
                '%s: %d page-part mapping(s) not propagated from pageBuilderHandle `%s` because entry-type `%s` does not own that Matrix field%s; examples: %s%s.',
                $group['fqcn'],
                count($sources),
                $group['matrix'],
                $group['entryType'],
                $group['hasFallback']
                    ? '; content is preserved via flatPagePartContent fallback'
                    : ' and no flatPagePartContent fallback is available',
                implode(', ', $examples),
                $suffix,
            );
        }

        return $out;
    }

    /**
     * Normalize optional relation metadata embedded by source scanners into the
     * shape consumed by PageRootedSurfaceDiscovery. Scanners differ by project,
     * so this accepts common keys and otherwise returns an empty map, which the
     * discovery service converts into explicit unsupported relation descriptors.
     *
     * @param array<string, mixed> $pageStructure
     * @return array<string, list<array<string, mixed>>>
     */
    private function relationMetadataFromPageStructure(array $pageStructure): array
    {
        $out = [];
        foreach ($pageStructure as $fqcn => $record) {
            if (!is_string($fqcn) || !is_array($record)) {
                continue;
            }
            $relations = [];
            foreach (['relations', 'relationMetadata', 'doctrineRelations'] as $key) {
                foreach ((array) ($record[$key] ?? []) as $relation) {
                    if (is_array($relation)) {
                        $relations[] = $relation;
                    }
                }
            }
            if ($relations !== []) {
                $out[$fqcn] = $relations;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonObject(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Snapshot Craft's currently-configured entry-type handles. Used by the
     * compile validation step to flag compiled section: handles that don't
     * exist in Craft (which would fail per-entry at migrate --live time).
     *
     * @return list<string>
     */
    private function craftEntryTypeHandles(): array
    {
        $out = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $et) {
            $h = (string) $et->handle;
            if ($h !== '') {
                $out[] = $h;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Build the schema facade consumed by CraftTargetIntrospector.
     *
     * @return array<string, mixed>
     */
    private function craftTargetSchema(Plugin $plugin): array
    {
        $sections = [];
        foreach ($plugin->craftKnowledgeBase->sectionToEntryTypes() as $handle => $entryTypes) {
            $sections[$handle] = ['entryTypes' => $entryTypes];
        }

        $entryTypes = [];
        foreach ($plugin->craftKnowledgeBase->buildFieldIndex() as $entryType => $fields) {
            $fieldMap = [];
            foreach ((array) $fields as $field) {
                if (!is_array($field)) { continue; }
                $handle = (string) ($field['handle'] ?? '');
                if ($handle === '' || str_contains($handle, '.')) { continue; }
                $fieldMap[$handle] = ['type' => strtolower((string) ($field['classification'] ?? $field['type'] ?? 'plain'))];
                if (isset($field['blocks']) && is_array($field['blocks'])) {
                    $fieldMap[$handle]['blocks'] = $field['blocks'];
                }
            }
            $entryTypes[(string) $entryType] = ['fields' => $fieldMap];
        }

        $volumes = [];
        try {
            foreach (Craft::$app->volumes->getAllVolumes() as $volume) {
                $handle = (string) $volume->handle;
                if ($handle !== '') { $volumes[] = $handle; }
            }
        } catch (Throwable) {
            $volumes = [];
        }

        return [
            'sections' => $sections,
            'entryTypes' => $entryTypes,
            'volumes' => array_values(array_unique($volumes)),
            'plugins' => [
                'seomatic' => OptionalPlugins::has(OptionalPlugins::SEOMATIC),
                'retour' => OptionalPlugins::has(OptionalPlugins::RETOUR),
            ],
        ];
    }

    /**
     * Suggest up to 3 Craft entry-type handles closest to a derived candidate.
     * Order: case-insensitive exact, then candidate-as-substring-of-craft,
     * then craft-as-substring-of-candidate, then Levenshtein-nearest. Empty
     * list when nothing within plausible edit distance.
     *
     * @param  list<string> $craftHandles
     * @return list<string>
     */
    private function suggestCraftHandle(string $candidate, array $craftHandles): array
    {
        if ($candidate === '' || $craftHandles === []) {
            return [];
        }
        $candidateLc = strtolower($candidate);
        $tier1 = []; // case-insensitive exact
        $tier2 = []; // substring match either direction
        $tier3 = []; // levenshtein-nearest
        foreach ($craftHandles as $h) {
            $hLc = strtolower($h);
            if ($hLc === $candidateLc) {
                $tier1[] = $h;
            } elseif (str_contains($hLc, $candidateLc) || str_contains($candidateLc, $hLc)) {
                $tier2[] = $h;
            } else {
                $dist = levenshtein($candidateLc, $hLc);
                if ($dist <= max(3, (int) (strlen($candidateLc) / 3))) {
                    $tier3[] = [$h, $dist];
                }
            }
        }
        usort($tier3, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $tier3 = array_map(static fn(array $p): string => (string) $p[0], $tier3);
        $merged = array_merge($tier1, $tier2, $tier3);
        return array_slice(array_values(array_unique($merged)), 0, 3);
    }

    /**
     * Derive a candidate locale → siteHandle map from kunstmaan-schema.json's
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
        $schemaPath = $storageDir . '/kunstmaan-schema.json';
        if (!is_file($schemaPath)) {
            $legacySchemaPath = $storageDir . '/schema-dump.json';
            if (!is_file($legacySchemaPath)) {
                return [];
            }
            $schemaPath = $legacySchemaPath;
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
