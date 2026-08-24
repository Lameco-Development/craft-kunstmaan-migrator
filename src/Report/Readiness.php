<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Report;

use Lameco\Kunstmaanmigrator\Compile\Transforms;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Mapping\PageRow;
use Lameco\Kunstmaanmigrator\Mapping\PartRow;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;

/**
 * Every field on every Craft entry type the mapping writes to, and whether the mapping has
 * anything to put in it.
 *
 * Two questions off one walk. `requirements()` asks the load-blocking one — a required field with
 * no value. `unfilled()` asks the one nobody was asking: an *optional* field no lane touches,
 * which is invisible until an editor opens the page and the hero is blank.
 *
 * `TargetCheck::unfilledRequired()` answers a narrower question — required fields of the blocks
 * named by `parts:`. That misses three quarters of the target: nested Matrix entry types
 * (`contentColumn.content`, the 152-heading blocker, is one), page entry types, and the types a
 * `promote:` writes into. It also credits nothing to the sequence lane, so a block whose heading
 * arrives by absorption reads as unfilled.
 *
 * Everything here is derived from the mapping and the project config. Fill rates come later, from
 * the database, because whether a mapped column is *actually* populated is not a schema question.
 */
final class Readiness
{
    /**
     * Entry fields an adapter lane fills, which the mapping therefore never names.
     *
     * Hard-coded because the loader hard-codes it too: `SeomaticPayloadBuilder` writes
     * `setFieldValue('seo', ...)` with the handle in the source, not from the mapping. Reading
     * this off the mapping is impossible; leaving it out puts the single most-placed field in the
     * corpus at the top of a report of holes, which is how a report stops being read.
     *
     * @var array<string, string>
     */
    private const ADAPTER_FIELDS = ['seo' => 'seo-adapter'];

    private readonly Transforms $transforms;

    public function __construct(
        private readonly Mapping $mapping,
        private readonly TargetSchema $schema,
    ) {
        $this->transforms = new Transforms($this->mapping->transforms());
    }

    /** @return list<Requirement> */
    public function requirements(): array
    {
        return array_values(array_filter($this->all(), static fn(Requirement $r): bool => $r->required));
    }

    /**
     * Fields on a target the mapping writes to that no lane supplies at all.
     *
     * The mirror of `requirements()`, and the question `readiness` could not answer: an optional
     * field nothing fills is not a load blocker, so it never appeared here — and 37 hero field
     * instances across 20 entry types stayed empty on every migrated entry without one report
     * saying so. Grouping is the caller's job; what this owes is one row per placement.
     *
     * @return list<Requirement>
     */
    public function unfilled(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(Requirement $r): bool => !$r->required && !$r->isSupplied(),
        ));
    }

    /** @return list<Requirement> */
    public function all(): array
    {
        return [...$this->fromPages(), ...$this->fromEntities(), ...$this->fromParts(), ...$this->fromSequence()];
    }

    /**
     * The entities lane was never walked, so a required field on a taxonomy target was
     * invisible — `country.flag` is a required Assets field with no legacy source, and
     * nothing said so until the countries table was actually mapped.
     *
     * @return list<Requirement>
     */
    private function fromEntities(): array
    {
        $out = [];

        foreach ($this->mapping->entityRows() as $entity => $row) {
            $entryType = $row->entryType();

            if ($entryType === null || !$this->schema->hasEntryType($entryType)) {
                continue;
            }

            $out = [...$out, ...$this->against(
                lane: 'entities',
                subject: $entity,
                entryType: $entryType,
                map: $row->map(),
                extra: [],
                live: null,
            )];
        }

        return $out;
    }

    /** @return list<Requirement> */
    private function fromPages(): array
    {
        $out = [];

        foreach ($this->mapping->pageRows() as $page => $row) {
            $entryType = $row->entryType();

            if (!$row->compiles() || !$this->schema->hasEntryType((string) $entryType)) {
                continue;
            }

            $out = [...$out, ...$this->against(
                lane: 'pages',
                subject: $page,
                entryType: (string) $entryType,
                map: $row->map(),
                extra: $this->contextFields($row) + $this->sidecarFields(),
                live: $row->live(),
            )];
        }

        return $out;
    }

    /** @return list<Requirement> */
    private function fromParts(): array
    {
        $out = [];
        $forms = $this->mapping->forms();

        foreach ($this->mapping->partRows() as $part => $row) {
            if (!$row->compilesToBlocks()) {
                continue;
            }

            $live = $row->live();
            $map = $row->map();

            foreach ($row->blocks() as $block) {
                if (!$this->schema->hasEntryType($block)) {
                    continue;
                }

                $extra = [];

                // The forms lane emits its own block and fills the form relation itself, so a
                // part targeting that block is not responsible for the field.
                if ($block === $forms->emitBlock && $forms->emitField !== null) {
                    $extra[$forms->emitField] = 'forms';
                }

                foreach ($row->promote() as $promo) {
                    if (isset($promo['relation'])) {
                        $extra[(string) $promo['relation']] = 'promote';
                    }
                }

                foreach (array_keys($row->children()) as $field) {
                    $extra[(string) $field] = 'children';
                }

                foreach ($this->absorbedFields($row, $block) as $field) {
                    $extra[$field] ??= 'sequence';
                }

                $out = [...$out, ...$this->against('parts', $part, $block, $map, $extra, $live)];
                $out = [...$out, ...$this->nested($row, $block)];
            }

            $out = [...$out, ...$this->promoted($row)];
        }

        return $out;
    }

    /**
     * Nested Matrix entry types, reached two ways: declared as a `children:` collection, or
     * addressed inline by a `field[0].sub` path in the parent map.
     *
     * @return list<Requirement>
     */
    private function nested(PartRow $part, string $block): array
    {
        $out = [];
        $children = $part->children();

        foreach ($children as $field => $child) {
            $type = $this->schema->nestedTypeOf($block, (string) $field);

            if ($type === null) {
                continue;
            }

            $out = [...$out, ...$this->against(
                lane: 'parts',
                subject: $part->name,
                entryType: $type,
                map: $child['map'] ?? [],
                extra: $this->absorbedInto($part, (string) $field, $type),
                live: $part->live(),
                label: sprintf('%s.%s[]', $block, $field),
            )];
        }

        foreach ($this->inlineNestedMaps($part->map()) as $field => $map) {
            if (isset($children[$field])) {
                continue;
            }

            $type = $this->schema->nestedTypeOf($block, $field);

            if ($type === null) {
                continue;
            }

            $out = [...$out, ...$this->against(
                lane: 'parts',
                subject: $part->name,
                entryType: $type,
                map: $map,
                extra: $this->absorbedInto($part, $field, $type),
                live: $part->live(),
                label: sprintf('%s.%s[]', $block, $field),
            )];
        }

        return $out;
    }

    /** @return list<Requirement> */
    private function promoted(PartRow $part): array
    {
        $out = [];

        foreach ($part->promote() as $table => $promo) {
            $entryType = (string) ($promo['entryType'] ?? '');

            if ($entryType === '' || !$this->schema->hasEntryType($entryType)) {
                continue;
            }

            $out = [...$out, ...$this->against(
                lane: 'promote',
                subject: $part->name,
                entryType: $entryType,
                map: $promo['map'] ?? [],
                extra: [],
                live: $part->live(),
                label: sprintf('%s (from %s)', $entryType, $table),
            )];
        }

        return $out;
    }

    /** @return list<Requirement> */
    private function fromSequence(): array
    {
        $out = [];

        foreach ($this->mapping->sequence() as $rule) {
            if (($rule['action'] ?? '') !== 'emit' || !isset($rule['block'])) {
                continue;
            }

            $block = (string) $rule['block'];

            if (!$this->schema->hasEntryType($block)) {
                continue;
            }

            $subject = (string) ($rule['id'] ?? 'sequence');
            $map = $rule['map'] ?? [];

            $out = [...$out, ...$this->against('sequence', $subject, $block, $map, [], null)];

            foreach ($this->inlineNestedMaps($map) as $field => $nestedMap) {
                $type = $this->schema->nestedTypeOf($block, $field);

                if ($type !== null) {
                    $out = [...$out, ...$this->against(
                        lane: 'sequence',
                        subject: $subject,
                        entryType: $type,
                        map: $nestedMap,
                        extra: [],
                        live: null,
                        label: sprintf('%s.%s[]', $block, $field),
                    )];
                }
            }
        }

        return $out;
    }

    /**
     * One entry type's required fields against what a map supplies.
     *
     * @param array<string, mixed> $map    target field => source expression
     * @param array<string, string> $extra target field => the lane that fills it instead
     * @return list<Requirement>
     */
    private function against(
        string $lane,
        string $subject,
        string $entryType,
        array $map,
        array $extra,
        ?int $live,
        ?string $label = null,
    ): array {
        $out = [];

        // A Matrix field is supplied by any path that addresses into it: `contentColumns[0].content`
        // fills `contentColumns`, and reading the key literally is how the required Matrix itself
        // reads as unfilled on the very parts that populate it.
        $addressed = [];

        foreach (array_keys($map) as $key) {
            $root = preg_split('/[\[.]/', (string) $key)[0];

            if ($root !== (string) $key) {
                $addressed[$root] = true;
            }
        }

        foreach ($this->schema->slots($entryType) as $field => $slot) {
            $field = (string) $field;
            $source = $map[$field] ?? null;
            $supplier = match (true) {
                $source !== null => 'map',
                isset($addressed[$field]) => 'map',
                isset($extra[$field]) => $extra[$field],
                default => null,
            };

            $out[] = new Requirement(
                lane: $lane,
                subject: $subject,
                target: $label ?? $entryType,
                field: $field,
                source: $source !== null ? (string) $source : null,
                supplier: $supplier,
                live: $live,
                totalTransform: $source !== null && $this->survivesEmpty((string) $source),
                craftDefault: $slot->default,
                required: $slot->required,
            );
        }

        return $out;
    }

    /**
     * The Matrix fields a page's block stream lands in.
     *
     * `contexts:` names them — per page, or from `defaults:` — and the blocks lane fills them, not
     * the page's `map:`. Without this every page entry type reads as never filling its own page
     * builder, which is the one field on it that always is filled.
     *
     * @return array<string, string>
     */
    private function contextFields(PageRow $page): array
    {
        $fields = self::ADAPTER_FIELDS;

        foreach ($page->contextFields() as $field) {
            $fields[$field] = 'blocks';
        }

        return $fields;
    }

    /**
     * Page fields the sidecars lane fills — the hero set, on every page a sidecar decorates.
     *
     * Credited the same way `contexts:` is: without this, `heroTitle` reads as a hole on
     * every page entry type on the very corpus whose header tabs supply it.
     *
     * @return array<string, string> target field => 'sidecars'
     */
    private function sidecarFields(): array
    {
        $fields = [];

        foreach ($this->mapping->sidecarRows() as $sidecar) {
            if (!$sidecar->isMigrated()) {
                continue;
            }

            foreach (array_keys($sidecar->map()) as $key) {
                $root = (string) preg_split('/[\[.]/', (string) $key)[0];
                $fields[$root] ??= 'sidecars';
            }

            foreach (array_keys($sidecar->children()) as $field) {
                $fields[(string) $field] ??= 'sidecars';
            }
        }

        return $fields;
    }

    /**
     * Fields the heading-absorb rules write onto a block, when this part accepts absorption and
     * the heading lands at block level rather than inside a nested row.
     *
     * @return list<string>
     */
    private function absorbedFields(PartRow $part, string $block): array
    {
        if ($part->refusesAbsorption() || $part->absorbInto() !== null) {
            return [];
        }

        $fields = [];

        foreach ($this->absorbRules() as $rule) {
            foreach (array_keys($rule['map'] ?? []) as $field) {
                if ($this->schema->slot($block, (string) $field) !== null) {
                    $fields[] = (string) $field;
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * The same, for a part that declares `absorbInto: <field>[0]` — the heading lands on the
     * nested row rather than on the block.
     *
     * @return array<string, string>
     */
    private function absorbedInto(PartRow $part, string $field, string $nestedType): array
    {
        $into = $part->absorbInto();

        if ($into === null || $this->fieldOfPath($into) !== $field) {
            return [];
        }

        $extra = [];

        foreach ($this->absorbRules() as $rule) {
            foreach (array_keys($rule['map'] ?? []) as $target) {
                if ($this->schema->slot($nestedType, (string) $target) !== null) {
                    $extra[(string) $target] = 'sequence';
                }
            }
        }

        return $extra;
    }

    /** @return list<array<string, mixed>> */
    private function absorbRules(): array
    {
        return array_values(array_filter(
            $this->mapping->sequence(),
            static fn(array $rule): bool => ($rule['action'] ?? '') === 'absorb',
        ));
    }

    /**
     * `field[0].sub: expr` entries regrouped per Matrix field.
     *
     * @param array<string, mixed> $map
     * @return array<string, array<string, mixed>>
     */
    private function inlineNestedMaps(array $map): array
    {
        $grouped = [];

        foreach ($map as $target => $expression) {
            if (preg_match('/^(\w+)\[\d+\]\.(\w+)$/', (string) $target, $m) === 1) {
                $grouped[$m[1]][$m[2]] = $expression;
            }
        }

        return $grouped;
    }

    private function fieldOfPath(string $path): string
    {
        return explode('[', $path)[0];
    }

    /**
     * Whether a mapped expression still yields a value when its source column is empty.
     *
     * `background_color | variant` is the case that makes this necessary: the column is empty on
     * most rows, but the transform turns empty into `base`, so a fill-rate check reading the raw
     * column alone would report a blocker that does not exist. Asking the real transform is
     * cheaper than restating its rules here, and cannot drift from them.
     */
    private function survivesEmpty(string $expression): bool
    {
        $expression = trim($expression);

        if ($expression === '' || str_contains($expression, '(')) {
            return false;
        }

        $pipeline = array_map('trim', explode('|', $expression));
        array_shift($pipeline);

        if ($pipeline === []) {
            return false;
        }

        foreach (['', null] as $empty) {
            $value = $empty;

            foreach ($pipeline as $transform) {
                try {
                    $value = $this->transforms->apply($transform, $value);
                } catch (\InvalidArgumentException) {
                    return false;
                }
            }

            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }
}
