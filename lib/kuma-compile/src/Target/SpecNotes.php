<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Target;

/**
 * Field-level migration notes read from the target content model's markdown specs.
 *
 * Each block spec carries a `Migration notes (Kunstmaan → Craft)` table pairing legacy
 * properties with the fields they become, and naming what was deliberately dropped. That
 * table is the field map somebody already thought through — reading it beats re-deriving it
 * from column names, which is how a part ends up mapped to a block with no fields at all.
 *
 * The tables are prose, so parsing is best-effort by design: every row is classified, and
 * anything that cannot be resolved is reported rather than guessed at.
 */
final class SpecNotes
{
    public const MAPPED = 'mapped';
    public const DROPPED = 'dropped';
    public const STRUCTURAL = 'structural';
    public const ORDER = 'order';

    /** @param array<string, list<Note>> $byBlock block handle => notes */
    private function __construct(private readonly array $byBlock)
    {
    }

    public static function fromDirectory(string $dir): self
    {
        $dir = rtrim($dir, '/');

        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf('No spec directory at %s', $dir));
        }

        $byBlock = [];

        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $block = basename($file, '.md');
            $notes = self::parse((string) file_get_contents($file));

            if ($notes !== []) {
                $byBlock[$block] = $notes;
            }
        }

        return new self($byBlock);
    }

    /** @return list<Note> */
    private static function parse(string $markdown): array
    {
        if (preg_match('/##\s*Migration notes.*?(?=\n##\s|\z)/su', $markdown, $m) !== 1) {
            return [];
        }

        $notes = [];
        $seenHeader = false;

        foreach (explode("\n", $m[0]) as $line) {
            $line = trim($line);

            if (!str_starts_with($line, '|')) {
                continue;
            }

            // The header row names the source entity; the next is the ---|--- separator.
            if (preg_match('/^\|[\s:|-]+\|$/', $line) === 1) {
                $seenHeader = true;

                continue;
            }

            if (!$seenHeader) {
                continue;
            }

            $cells = array_map(trim(...), array_slice(explode('|', $line), 1, -1));

            if (count($cells) < 2) {
                continue;
            }

            $note = self::row($cells[0], $cells[1]);

            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    private static function row(string $left, string $right): ?Note
    {
        [$scope, $sources] = self::sourceSide($left);

        if ($sources === []) {
            return null;
        }

        $kind = match (true) {
            (bool) preg_match('/\bMatrix order\b/i', $right) => self::ORDER,
            (bool) preg_match('/\(\s*dropped/i', $right)     => self::DROPPED,
            // A row whose target is the entry's place in the tree names a section, not a
            // field: "tree parent (child of `eventOverviewPage`)" was read as a field handle
            // and reported as a divergence against every editorial page type.
            (bool) preg_match('/\bentries in the\b|\btaxonomy\b|\bEntries →|\b(tree|structure) parent\b/i', $right) => self::STRUCTURAL,
            default                                          => self::MAPPED,
        };

        return new Note(
            scope: $scope,
            sources: $sources,
            targets: $kind === self::MAPPED ? self::targetSide($right) : [],
            kind: $kind,
            note: $right,
        );
    }

    /**
     * `part \`title\``, `item \`title\` / \`content\``, `` `content` `` — the optional leading
     * word says whether the property is on the pagepart or on one of its child rows.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function sourceSide(string $cell): array
    {
        $scope = 'part';

        if (preg_match('/^(part|item|child)\b/i', $cell, $m) === 1) {
            $scope = strtolower($m[1]) === 'part' ? 'part' : 'item';
        }

        preg_match_all('/`([^`]+)`/', $cell, $m);
        $sources = [];

        foreach ($m[1] as $raw) {
            foreach (preg_split('#\s*/\s*#', $raw) ?: [] as $candidate) {
                $candidate = trim($candidate);

                // Rows sometimes qualify a property as `Entity.property`.
                if (str_contains($candidate, '.')) {
                    $candidate = substr((string) strrchr($candidate, '.'), 1);
                }

                if ($candidate !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $candidate) === 1) {
                    $sources[] = $candidate;
                }
            }
        }

        return [$scope, array_values(array_unique($sources))];
    }

    /**
     * The target cell names one or more Craft fields, sometimes prefixed by the nested entry
     * they belong to (`card heading`, `usp text`) and sometimes bolded with a decision note.
     *
     * @return list<string>
     */
    private static function targetSide(string $cell): array
    {
        preg_match_all('/`([^`]+)`/', $cell, $m);
        $targets = [];

        foreach ($m[1] as $raw) {
            foreach (preg_split('#\s*/\s*#', $raw) ?: [] as $candidate) {
                $candidate = trim($candidate);

                if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $candidate) === 1) {
                    $targets[] = $candidate;
                }
            }
        }

        return array_values(array_unique($targets));
    }

    /** @return list<Note> */
    public function forBlock(string $block): array
    {
        return $this->byBlock[$block] ?? [];
    }

    /** @return list<string> */
    public function blocks(): array
    {
        return array_keys($this->byBlock);
    }

    public function isEmpty(): bool
    {
        return $this->byBlock === [];
    }
}
