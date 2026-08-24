<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Command;

use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\MappingDocument;
use Lameco\Kunstmaanmigrator\Source\Dsn;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\Target\CraftSchema;
use Lameco\Kunstmaanmigrator\Target\Note;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\SpecNotes;
use Lameco\Kunstmaanmigrator\Target\Suggester;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'suggest',
    description: 'Draft field maps for unmapped parts from the target content model\'s migration notes',
)]
final class SuggestCommand extends Command
{
    /** Legacy relation columns carry an `_id` suffix the specs omit. */
    private const RELATION_SUFFIX = '_id';

    protected function configure(): void
    {
        $this
            ->addArgument('mapping', InputArgument::REQUIRED, 'Path to the mapping YAML')
            ->addOption('specs', null, InputOption::VALUE_REQUIRED, 'Directory of block spec markdown files')
            ->addOption('craft', null, InputOption::VALUE_REQUIRED, 'Target Craft project root')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Legacy environment to resolve columns against')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include parts that already have a field map')
            ->addOption('apply', null, InputOption::VALUE_NONE,
                'Write the drafts into the mapping: every undecided part whose spec names it gets its '
                . 'block and field maps, leftover columns stay unreviewed — rows remain open until reviewed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mapping = Mapping::fromFile((string) $input->getArgument('mapping'));

        foreach (['specs', 'craft'] as $required) {
            if ($input->getOption($required) === null) {
                $io->error(sprintf('--%s is required', $required));

                return Command::INVALID;
            }
        }

        $notes = SpecNotes::fromDirectory((string) $input->getOption('specs'));
        $schema = CraftSchema::fromProjectConfig((string) $input->getOption('craft'));

        $envName = (string) ($input->getOption('env') ?? array_key_first($mapping->environments()));
        $database = (string) ($mapping->environments()[$envName]['database'] ?? '');
        $db = LegacyDatabase::connect($envName, $database, Dsn::fromEnvironment());

        if ($input->getOption('apply')) {
            $result = (new Suggester($notes, $schema))->prefill($mapping, $db);
            $document = MappingDocument::fromFile($mapping->path);

            foreach ($result['drafted'] as $part => $patch) {
                $document = $document->patch('parts', (string) $part, $patch);
            }

            $document->save();
            $io->success(sprintf(
                '%d parts drafted from the specs into %s — each stays open until its unreviewed columns are cleared.',
                count($result['drafted']),
                $mapping->path,
            ));

            foreach ($result['skipped'] as $part => $reason) {
                $io->writeln(sprintf('  <comment>·</comment> %s: %s', $part, $reason));
            }

            return Command::SUCCESS;
        }

        $unresolved = [];
        $emitted = 0;

        foreach ($mapping->partRows() as $part => $row) {
            if ($row->block() === null || $row->table() === null) {
                continue;
            }

            if (!$input->getOption('all') && $row->map() !== []) {
                continue;
            }

            $spec = $row->spec;
            $block = $row->block();
            $blockNotes = $notes->forBlock($block);

            if ($blockNotes === []) {
                $unresolved[] = sprintf('%s: no migration notes on `%s`', $part, $block);

                continue;
            }

            $columns = $db->columns((string) $spec['table']);
            $partMap = [];
            $itemMap = [];
            $dropped = [];

            foreach ($blockNotes as $note) {
                if ($note->kind === SpecNotes::DROPPED) {
                    $dropped = [...$dropped, ...$note->sources];

                    continue;
                }

                if (!$note->isMapped()) {
                    continue;
                }

                if ($note->scope === 'part') {
                    $this->resolveInto($note, $columns, $block, null, $schema, $partMap, $unresolved, $part, $spec['map'] ?? []);

                    continue;
                }

                [$field, $childColumns] = $this->childOf($spec, $db);

                if ($field === null) {
                    $unresolved[] = sprintf('%s: item-scoped note but no child collection in the mapping', $part);

                    continue;
                }

                $nested = $schema->nestedTypeOf($block, $field);
                $this->resolveInto(
                    $note,
                    $childColumns,
                    $block,
                    $nested,
                    $schema,
                    $itemMap,
                    $unresolved,
                    $part,
                    $spec['children'][$field]['map'] ?? [],
                );
            }

            if ($partMap === [] && $itemMap === []) {
                continue;
            }

            $emitted++;
            $output->writeln($this->yaml($part, $spec, $partMap, $itemMap, $dropped, $db));
        }

        $pagesEmitted = 0;

        // Page entities were outside this command entirely, so a page type could sit at three of
        // twelve mapped fields and nothing would say so — the readiness report only sees the
        // required ones, and `ignore:` swallows the rest without comment.
        foreach ($mapping->pageRows() as $page => $row) {
            if (!$row->compiles() || $row->table() === null) {
                continue;
            }

            $spec = $row->spec;
            $entryType = (string) $row->entryType();
            $pageNotes = $notes->forBlock($entryType);

            if ($pageNotes === []) {
                $unresolved[] = sprintf('%s: no migration notes on `%s`', $page, $entryType);

                continue;
            }

            if (!$db->hasTable((string) $spec['table'])) {
                $unresolved[] = sprintf('%s: `%s` does not exist in %s', $page, $spec['table'], $envName);

                continue;
            }

            $columns = $db->columns((string) $spec['table']);
            $pageMap = [];
            $dropped = [];

            foreach ($pageNotes as $note) {
                if ($note->kind === SpecNotes::DROPPED) {
                    $dropped = [...$dropped, ...$note->sources];

                    continue;
                }

                if ($note->isMapped()) {
                    $this->resolveInto($note, $columns, $entryType, null, $schema, $pageMap, $unresolved, $page, $spec['map'] ?? []);
                }
            }

            if ($pageMap === []) {
                continue;
            }

            $pagesEmitted++;
            $output->writeln($this->yaml($page, $spec, $pageMap, [], $dropped, $db));
        }

        $io->writeln(sprintf('# %d parts and %d page types drafted', $emitted, $pagesEmitted));

        if ($unresolved !== []) {
            $io->writeln('');
            $io->writeln(sprintf('# %d unresolved — review by hand:', count($unresolved)));

            foreach ($unresolved as $u) {
                $io->writeln('#   ' . $u);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Pair one note's legacy properties with its Craft fields, keeping only what exists at
     * both ends. A note naming several sources for one target means "whichever is set", so
     * the first that resolves wins.
     *
     * @param list<string> $columns
     * @param array<string, string> $map
     * @param list<string> $unresolved
     * @param array<string, mixed> $already targets the mapping already supplies
     */
    private function resolveInto(
        Note $note,
        array $columns,
        string $block,
        ?string $nested,
        TargetSchema $schema,
        array &$map,
        array &$unresolved,
        string $part,
        array $already = [],
    ): void {
        $owner = $nested ?? $block;

        // A row listing the same number of sources and targets is a parallel list — `email`,
        // `phoneNumber`, `faxNumber`, `website` → `contactEmail`, `phoneNumber`, `faxNumber`,
        // `websiteUrl` pairs in order. Treating it as "whichever source is set" gave all four
        // targets the first column that resolved, which drafts three wrong maps out of four.
        $paired = count($note->sources) > 1 && count($note->sources) === count($note->targets);

        foreach ($note->targets as $i => $target) {
            // Drafting a field the mapping already fills is noise; the point is what is absent.
            if (isset($already[$target])) {
                continue;
            }

            $slot = $schema->slot($owner, $target);

            if ($slot === null) {
                $unresolved[] = sprintf('%s: `%s` is not a field on `%s`', $part, $target, $owner);

                continue;
            }

            $column = null;

            foreach ($paired ? [$note->sources[$i]] : $note->sources as $source) {
                $column = $this->column($source, $columns);

                if ($column !== null) {
                    break;
                }
            }

            if ($column === null) {
                $unresolved[] = sprintf(
                    '%s: none of [%s] is a column on the legacy table (target `%s`)',
                    $part,
                    implode(', ', $note->sources),
                    $target,
                );

                continue;
            }

            $map[$target] = $this->expression($target, $column, $slot);
        }
    }

    /** @param list<string> $columns */
    private function column(string $property, array $columns): ?string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));

        foreach ([$snake, $snake . self::RELATION_SUFFIX, $property] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The mapping's child collection for this part, with its real columns.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function childOf(array $spec, LegacyDatabase $db): array
    {
        foreach ($spec['children'] ?? [] as $field => $child) {
            return [(string) $field, $db->columns((string) $child['table'])];
        }

        return [null, []];
    }

    /**
     * @param array<string, string> $partMap
     * @param array<string, string> $itemMap
     * @param list<string> $dropped
     */
    private function yaml(string $part, array $spec, array $partMap, array $itemMap, array $dropped, LegacyDatabase $db): string
    {
        $out = sprintf("  %s:\n", $part);

        if ($partMap !== []) {
            $out .= "    map:\n";

            foreach ($partMap as $field => $expression) {
                $out .= sprintf("      %-22s %s\n", $field . ':', $expression);
            }
        }

        if ($itemMap !== []) {
            [$field] = $this->childOf($spec, $db);
            $out .= "    children:\n";
            $out .= sprintf("      %s:\n        map:\n", $field);

            foreach ($itemMap as $target => $expression) {
                $out .= sprintf("          %-20s %s\n", $target . ':', $expression);
            }
        }

        if ($dropped !== []) {
            $out .= sprintf("    # spec says dropped: %s\n", implode(', ', $dropped));
        }

        return $out;
    }

    /**
     * Attach the transform a field's shape implies, so the draft is usable as written.
     *
     * The target's field type decides what an `_id` column becomes: an Assets field wants the
     * media path `asset` resolves, a relation field wants the `kuma:` sourceUid `ref` produces.
     * Guessing from the column name alone drafts `country_id | asset` for an Entries relation,
     * which resolves to a missing image rather than a country.
     */
    private function expression(string $field, string $column, ?Slot $slot = null): string
    {
        $relational = in_array($slot?->type, ['Entries', 'Categories', 'Users', 'Tags'], true);

        return match (true) {
            str_ends_with($column, '_id') && $relational => $column . ' | ref',
            str_ends_with($column, '_id') && $slot?->type === 'Assets' => $column . ' | asset',
            str_ends_with($column, '_id') && $slot !== null => $column,
            in_array($field, ['content', 'text', 'quote'], true) => $column . ' | ckeditor',
            in_array($field, ['heading', 'label', 'eyebrow', 'tabLabel', 'paneTitle', 'personName'], true)
                                                                              => $column . ' | inlineHtml',
            $field === 'colorScheme' => $column . ' | colorScheme',
            $field === 'titleLevel' => $column . ' | titleLevel',
            default => $column,
        };
    }
}
