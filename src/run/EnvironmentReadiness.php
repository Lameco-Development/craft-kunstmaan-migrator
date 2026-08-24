<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\run;

/**
 * What one environment's preflight found.
 */
final class EnvironmentReadiness
{
    /**
     * @param list<array{path: string, readable: bool, fallback: bool}> $mediaRoots
     * @param list<string> $localesWithoutSite legacy locales the mapping points at a Craft site that does not exist
     * @param list<string> $localesNotMigrated legacy locales the mapping deliberately leaves out
     */
    public function __construct(
        public readonly string $name,
        public readonly string $database,
        public readonly bool $databaseReachable,
        public readonly ?string $connectionError,
        public readonly ?int $nodeCount,
        public readonly array $mediaRoots,
        public readonly array $localesWithoutSite,
        public readonly array $localesNotMigrated,
    ) {
    }

    public function isReady(): bool
    {
        return $this->problems() === [];
    }

    /**
     * Everything that would make this environment migrate badly, in the order
     * an operator can act on it.
     *
     * A locale the mapping deliberately leaves out is not a problem — it is a
     * decision, recorded with a reason, and reporting it as a fault would train
     * people to ignore this list.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (!$this->databaseReachable) {
            $problems[] = $this->connectionError === null
                ? sprintf('Cannot connect to %s.', $this->database)
                : sprintf('Cannot connect to %s — %s', $this->database, $this->connectionError);

            return $problems;
        }

        if ($this->nodeCount === null) {
            $problems[] = sprintf('%s has no kuma_nodes table — wrong database?', $this->database);
        } elseif ($this->nodeCount === 0) {
            $problems[] = sprintf('%s has no nodes to migrate.', $this->database);
        }

        foreach ($this->mediaRoots as $root) {
            if (!$root['readable']) {
                $problems[] = sprintf(
                    '%s directory is missing or unreadable: %s',
                    $root['fallback'] ? 'Fallback uploads' : 'Uploads',
                    $root['path'],
                );
            }
        }

        foreach ($this->localesWithoutSite as $locale) {
            $problems[] = sprintf('Locale "%s" points at a Craft site that does not exist.', $locale);
        }

        return $problems;
    }

    /**
     * A missing fallback directory is worth saying out loud but does not stop a
     * run: the primary root answers most lookups, and the chain exists because
     * some media only lives in the other site's uploads.
     */
    public function isBlocked(): bool
    {
        if (!$this->databaseReachable || $this->nodeCount === null || $this->nodeCount === 0) {
            return true;
        }

        if ($this->localesWithoutSite !== []) {
            return true;
        }

        foreach ($this->mediaRoots as $root) {
            if (!$root['readable'] && !$root['fallback']) {
                return true;
            }
        }

        return false;
    }
}
