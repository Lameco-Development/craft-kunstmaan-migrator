<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Report;

use Lameco\KumaCompile\Legacy\LegacyDatabase;

/**
 * What one legacy environment costs to migrate, before a Craft project exists.
 *
 * The question someone scoping a quote has to answer in an hour, and the one every surface in
 * this toolchain refused: `doctor` needs the target installed, `coverage` and `readiness` need
 * a mapping, and `init` emits a skeleton that is a checklist rather than a judgment. Every
 * number below is already read at `init` time — this reports it instead of writing it into a
 * YAML nobody has filled in yet.
 *
 * Deliberately no verdict. "Two days" depends on how many of the 79 pagepart classes collapse
 * into one Craft block, which is the half a machine cannot know. What it can do is put the
 * counts that decide it on one line, and make the shape comparable across sites so twelve of
 * them can be ranked without opening twelve skeletons.
 */
final readonly class Survey
{
    /**
     * @param array<string, int>  $partClasses  short pagepart class => live placements
     * @param array<string, int>  $pageTypes    short page entity    => live pages
     * @param array<string, int>  $locales      legacy lang          => live pages
     * @param array<string, int>  $contexts     Kunstmaan context    => live placements
     * @param array<string, ?int> $volumes      table-backed counts; null means the table is absent
     */
    public function __construct(
        public string $environment,
        public string $database,
        public array $partClasses,
        public array $pageTypes,
        public array $locales,
        public array $contexts,
        public array $volumes,
        public int $livePages,
        public int $livePlacements,
        public int $allPartRefs,
        /** @var array<string, int> non-core tables carrying the (ref_entity_name, ref_id) sidecar pair => rows */
        public array $sidecarTables = [],
    ) {
    }

    public static function of(LegacyDatabase $db): self
    {
        $snapshot = $db->snapshot();

        return new self(
            environment: $db->environment,
            database: $db->database,
            partClasses: $snapshot->partPlacements,
            pageTypes: $snapshot->pageTypes,
            locales: $snapshot->pagesByLocale,
            contexts: $db->livePlacementsByContext(),
            volumes: [
                'nodes' => $db->countOrNull('kuma_nodes', 'deleted = 0'),
                'nodeVersions' => $db->countOrNull('kuma_node_versions'),
                'media' => $db->countOrNull('kuma_media', 'deleted = 0'),
                'mediaFolders' => $db->countOrNull('kuma_folders', 'deleted = 0'),
                'redirects' => $db->countOrNull('kuma_redirects'),
                'formSubmissions' => $db->countOrNull('kuma_form_submissions'),
                'users' => $db->countOrNull('kuma_users'),
                'queuedPublishes' => $db->countOrNull('kuma_node_queued_node_translation_actions'),
            ],
            livePages: array_sum($snapshot->pageTypes),
            livePlacements: array_sum($snapshot->partPlacements),
            allPartRefs: $snapshot->allPartRefs,
            sidecarTables: self::sidecarTables($db),
        );
    }

    /**
     * Tables decorating pages through Kunstmaan's polymorphic ref, found by column
     * signature. The name differs per site — header tab here, something else elsewhere —
     * which is exactly why a scoping survey looks for the pair and not a name.
     *
     * @return array<string, int> table => rows
     */
    private static function sidecarTables(LegacyDatabase $db): array
    {
        $out = [];

        foreach ($db->tables() as $table) {
            if (str_starts_with($table, 'kuma_')) {
                continue;
            }

            $columns = $db->columns($table);

            if (in_array('ref_entity_name', $columns, true) && in_array('ref_id', $columns, true)) {
                $out[$table] = (int) ($db->countOrNull($table) ?? 0);
            }
        }

        arsort($out);

        return $out;
    }

    /**
     * The live share of all pagepart rows.
     *
     * Kunstmaan clones the whole pagepart graph per node version, so the raw table is roughly
     * twenty times the live content. A quote written off the raw count is twenty times too big.
     */
    public function liveShare(): float
    {
        return $this->allPartRefs > 0 ? $this->livePlacements / $this->allPartRefs : 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'database' => $this->database,
            'livePages' => $this->livePages,
            'livePlacements' => $this->livePlacements,
            'liveShare' => round($this->liveShare(), 4),
            'partClassCount' => count($this->partClasses),
            'pageTypeCount' => count($this->pageTypes),
            'localeCount' => count($this->locales),
            'locales' => $this->locales,
            'contexts' => $this->contexts,
            'volumes' => $this->volumes,
            'sidecarTables' => $this->sidecarTables,
            'partClasses' => $this->partClasses,
            'pageTypes' => $this->pageTypes,
        ];
    }
}
