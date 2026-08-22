<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Compile;

use Lameco\KumaCompile\Legacy\MediaIndex;
use Lameco\KumaCompile\Legacy\PartReader;
use Lameco\KumaCompile\Target\Slot;
use Lameco\KumaCompile\Target\TargetSchema;

/**
 * Turns one legacy pagepart row into one Matrix block payload.
 *
 * Field maps are written as `column | transform`; `link(...)` gathers the four legacy link
 * columns that repeat across a dozen parts into one button. Anything a map does not read
 * must appear under `ignore:` — the mapping validator enforces that, so this class can
 * assume every column was accounted for by a human.
 */
final class BlockBuilder
{
    public function __construct(
        private readonly PartReader $parts,
        private readonly Transforms $transforms,
        private readonly string $environment,
        private readonly ?TargetSchema $schema = null,
        private ?string $block = null,
        private readonly ?MediaIndex $media = null,
        private readonly ?EntityIndex $entities = null,
    ) {
    }

    /**
     * @param array<string, mixed> $spec the mapping's entry for this part
     * @return array{type:string, fields:array<string,mixed>}|null
     */
    public function build(string $partClass, int $partId, array $spec): ?array
    {
        $table = $spec['table'] ?? null;
        $block = $spec['block'] ?? null;

        if ($table === null || $block === null) {
            return null;
        }

        $row = $this->parts->row((string) $table, $partId);

        if ($row === null) {
            return null;
        }

        $this->block = (string) $block;
        $fields = $this->fieldsFrom($spec['map'] ?? [], $row, $partClass);
        $fields['_sourcePartRef'] = $this->sourceRef((string) $table, $partId);
        $fields = $this->stampNestedRefs($fields, $fields['_sourcePartRef']);

        $fields += $this->childrenOf($spec['children'] ?? [], (string) $block, $partId, $partClass);

        return ['type' => (string) $block, 'fields' => $fields];
    }

    /**
     * Child collections of one owner row, as Matrix blocks.
     *
     * `$owner` is the entry type the Matrix lives on — a block for a pagepart's children, a
     * page entry type for a page entity's own collections. Both ask the same question of the
     * schema: what nested type does this field hold?
     *
     * `$trackable` says these blocks sit at the top level of an entry, which is the level the
     * loader matches on. A page's collections do; a pagepart's children do not, because their
     * stability follows from the parent block's own ref. Without it a re-run appends instead
     * of replacing: three legacy branches became six, then nine.
     *
     * @param array<string, array<string, mixed>> $children
     * @return array<string, list<array{type:string, fields:array<string,mixed>}>>
     */
    public function childrenOf(
        array $children,
        string $owner,
        int $ownerId,
        string $context,
        bool $trackable = false,
    ): array {
        $previous = $this->block;
        $this->block = $owner;
        $out = [];

        foreach ($children as $field => $child) {
            if (!is_array($child) || ($child['table'] ?? '') === '' || ($child['fk'] ?? '') === '') {
                continue;
            }

            $rows = $this->parts->children(
                (string) $child['table'],
                (string) $child['fk'],
                $ownerId,
                (string) ($child['order'] ?? 'weight'),
            );

            $blocks = [];

            // A child's map addresses fields on the *nested* entry type, so that is the type the
            // schema must be asked about while it is evaluated — `commonLink` is a Link field on
            // `button`, and nothing at all on the block that owns the Matrix.
            $childType = $this->childBlockType((string) $field);

            foreach ($rows as $childRow) {
                $fields = $this->fieldsFrom($child['map'] ?? [], $childRow, $context . '.' . $field, $childType);

                // Every block carries its origin, at every depth. The loader threads these back
                // into the payload on a re-run so Craft updates a block in place instead of
                // replacing it — and a nested block that carries none is rebuilt every time,
                // which is what happened to `contentColumn` and `button` on every forced run.
                if (isset($childRow['id'])) {
                    $fields['_sourcePartRef'] = $this->sourceRef((string) $child['table'], (int) $childRow['id']);
                }

                $blocks[] = [
                    'type' => $childType,
                    'fields' => $fields,
                ];
            }

            if ($blocks !== []) {
                $out[(string) $field] = $blocks;
            }
        }

        $this->block = $previous;

        return $out;
    }

    /**
     * @param array<string, string> $map
     * @param array<string, mixed> $row
     * @param ?string $owner the entry type these targets name, when the caller knows it — a page
     *   map addresses a page entry type, and without it the schema is asked about whichever block
     *   happened to be built last.
     * @return array<string, mixed>
     */
    public function fieldsFrom(array $map, array $row, string $context, ?string $owner = null): array
    {
        $previous = $this->block;

        if ($owner !== null) {
            $this->block = $owner;
        }

        $fields = [];

        foreach ($map as $target => $expression) {
            $value = $this->evaluate((string) $expression, $row, $context, (string) $target);

            if ($value !== null && $value !== '' && $value !== []) {
                $this->assign($fields, (string) $target, $value);
            }
        }

        $this->block = $previous;

        return $fields;
    }

    /** `column`, `column | transform`, `column | ref(Entity)`, or `link(url, text, newWindow, type)`. */
    private function evaluate(string $expression, array $row, string $context, string $target = ''): mixed
    {
        $expression = trim($expression);

        if (preg_match('/^link\((.*)\)$/', $expression, $m) === 1) {
            return $this->link(array_map('trim', explode(',', $m[1])), $row, $target);
        }

        if (preg_match('/^address\((.*)\)$/s', $expression, $m) === 1) {
            return $this->address($m[1], $row, $context);
        }

        // One target, two legacy columns that may each hold it. A case page's brand is in
        // `brand_id` on 122 rows and only in `brand_url`'s internal-link form on 53 more.
        if (preg_match('/^coalesce\((.*)\)$/s', $expression, $m) === 1) {
            foreach ($this->splitArguments($m[1]) as $alternative) {
                $value = $this->evaluate($alternative, $row, $context);

                if ($value !== null && $value !== '' && $value !== []) {
                    return $value;
                }
            }

            return null;
        }

        $parts = array_map('trim', explode('|', $expression));
        $column = array_shift($parts);
        $value = $row[$column] ?? null;

        foreach ($parts as $transform) {
            // A legacy FK holds a row id; the loader resolves an element id from a sourceUid.
            // Building that uid needs the entity's table, which only the mapping knows — so it
            // happens here rather than inside a transform that has no access to it.
            if (preg_match('/^ref\((\w+)\)$/', $transform, $m) === 1) {
                $uid = $this->entities?->uidFor($m[1], $value, $this->environment);

                // A relation field takes a list of element ids. The loader replaces each
                // `_ref` node in place, so the list has to be here already — handing it a
                // bare node saved without complaint and related nothing.
                return $uid === null ? null : [['_ref' => $uid]];
            }

            // `lookup(Entity.column)` follows a foreign key to a column on the row it points
            // at. The country code an address needs is not on the page's own table: it is on
            // the country row the page's `country_id` names.
            if (preg_match('/^lookup\((\w+)\.(\w+)\)$/', $transform, $m) === 1) {
                $value = $this->lookup($m[1], $m[2], $value);

                continue;
            }

            // A legacy media column holds an id; the loader resolves a path. Translating
            // here keeps the `asset` transform itself free of any database access.
            if ($transform === 'asset') {
                $value = $this->media?->pathFor($value) ?? null;

                if ($value === null) {
                    $this->transforms->recordMissingAsset($context, $row[$column] ?? null);

                    return null;
                }
            }

            $value = $this->transforms->apply($transform, $value, $context);
        }

        return $value;
    }

    /**
     * A Craft Address element, gathered from the columns a legacy table spreads it across.
     *
     * Written as `address(addressLine1=street, postalCode=postal_code, ...)`: named because an
     * address has nine usable parts and no natural order, and each value is a full expression
     * so a country code can arrive through `lookup()`.
     *
     * The loader turns `_address` into the shape Craft's Addresses field reads, because only it
     * knows whether the entry already owns an address to update rather than replace.
     *
     * @return array{_address: array<string, string>}|null
     */
    private function address(string $arguments, array $row, string $context): ?array
    {
        $parts = [];

        foreach ($this->splitArguments($arguments) as $argument) {
            if (!str_contains($argument, '=')) {
                continue;
            }

            [$name, $expression] = array_map(trim(...), explode('=', $argument, 2));
            $value = $this->evaluate($expression, $row, $context);

            if ($value !== null && $value !== '' && is_scalar($value)) {
                $parts[$name] = (string) $value;
            }
        }

        // A street and nothing else is still an address; no parts at all is not one.
        return $parts === [] ? null : ['_address' => $parts];
    }

    /**
     * Split `a=b, c=d | lookup(E.f)` on the commas that separate arguments, not the ones
     * inside a nested call.
     *
     * @return list<string>
     */
    private function splitArguments(string $arguments): array
    {
        $out = [];
        $depth = 0;
        $current = '';

        foreach (str_split($arguments) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $out[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $out[] = trim($current);
        }

        return $out;
    }

    /** One column of the entity row a foreign key points at. */
    private function lookup(string $entity, string $column, mixed $foreignKey): mixed
    {
        $table = $this->entities?->tableFor($entity);

        if ($table === null || $foreignKey === null || !ctype_digit((string) $foreignKey)) {
            return null;
        }

        return $this->parts->row($table, (int) $foreignKey)[$column] ?? null;
    }

    /**
     * The legacy link columns collapse into one link. A link with no URL is not a link, it is
     * an empty row an editor left behind.
     *
     * The shape depends on where it lands, and the schema is what knows: a Craft `Link` field
     * takes one value keyed `value`, while a Matrix of buttons takes a list of blocks with the
     * link on the nested entry type. Emitting a list of `url` keys — which is what this did —
     * satisfies neither, and Craft discards both without a word.
     *
     * @param list<string> $columns
     */
    private function link(array $columns, array $row, string $target = ''): mixed
    {
        [$urlCol, $textCol] = [$columns[0] ?? null, $columns[1] ?? null];
        $url = $urlCol !== null ? trim((string) ($row[$urlCol] ?? '')) : '';

        if ($url === '') {
            return null;
        }

        // Kunstmaan writes an internal link as `[NT<id>]`, which a Link field rejects outright.
        // It addresses a node translation, and the node is what becomes an entry, so it is
        // handed over as a ref for the loader to turn into a reference tag.
        $ref = $this->entities?->uidFor('nodeLink', $url, $this->environment);

        $link = $ref !== null ? ['_linkRef' => $ref] : $this->linkTarget($url);

        if ($textCol !== null && trim((string) ($row[$textCol] ?? '')) !== '') {
            $link['label'] = (string) $row[$textCol];
        }

        if (isset($columns[2]) && (int) ($row[$columns[2]] ?? 0) === 1) {
            $link['target'] = '_blank';
        }

        $slot = $this->slotFor($target);

        if ($slot === null || !$slot->isMatrix()) {
            return $link;
        }

        $nested = $this->childBlockType($target);
        $linkHandle = $this->soleSlotOfType($nested, 'Link');

        if ($linkHandle === null) {
            return null;
        }

        $fields = [$linkHandle => $link];
        $styleColumn = $columns[3] ?? null;
        $style = $styleColumn !== null ? trim((string) ($row[$styleColumn] ?? '')) : '';
        $styleHandle = $style !== '' ? $this->soleSlotOfType($nested, 'Dropdown') : null;

        if ($styleHandle !== null) {
            $fields[$styleHandle] = $style;
        }

        return [['type' => $nested, 'fields' => $fields]];
    }

    /**
     * Which kind of link a legacy URL column is holding.
     *
     * Craft only sniffs the type when the value is a bare string. A map — the only way to carry
     * a label — defaults to `url`, so a column holding a bare address is validated as a URL and
     * fails the whole entry. Four live pages died on `sales.sp@enreach.com` that way.
     *
     * @return array<string, string>
     */
    private function linkTarget(string $url): array
    {
        foreach (['mailto:' => 'email', 'tel:' => 'tel', 'sms:' => 'sms'] as $prefix => $type) {
            if (stripos($url, $prefix) === 0) {
                return ['type' => $type, 'value' => $url];
            }
        }

        // The old templates wrote the scheme; the column holds the bare address.
        if (filter_var($url, FILTER_VALIDATE_EMAIL) !== false) {
            return ['type' => 'email', 'value' => 'mailto:' . $url];
        }

        return ['value' => $url];
    }

    /** The slot a simple target names on the entry type currently being built. */
    private function slotFor(string $target): ?Slot
    {
        if ($this->schema === null || $this->block === null || $target === '') {
            return null;
        }

        // A target that addresses a nested position already says where it lands.
        if (str_contains($target, '.') || str_contains($target, '[')) {
            return null;
        }

        return $this->schema->slot($this->block, $target);
    }

    /**
     * The one field of this type on an entry type, when there is exactly one.
     *
     * Reading it from the schema rather than naming `commonLink` keeps this working on a target
     * whose button block calls its fields something else.
     */
    private function soleSlotOfType(string $entryType, string $type): ?string
    {
        if ($this->schema === null) {
            return null;
        }

        $found = [];

        foreach ($this->schema->slots($entryType) as $handle => $slot) {
            if ($slot->type === $type) {
                $found[] = (string) $handle;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Give every nested block an origin of its own, derived from its parent's.
     *
     * A `contentColumns[0].content` path synthesises a block that no legacy row backs, so there
     * is no id to name it by — but the loader still needs to recognise it on a re-run, or Craft
     * rebuilds it under a parent it otherwise reuses. The parent's ref plus the path is stable
     * for exactly as long as the parent is, which is the property that matters.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function stampNestedRefs(array $fields, string $parentRef): array
    {
        foreach ($fields as $handle => $value) {
            if (!is_array($value) || $value === [] || $handle === '_sourcePartRef') {
                continue;
            }

            foreach ($value as $index => $block) {
                if (!is_array($block) || !isset($block['type']) || !is_array($block['fields'] ?? null)) {
                    continue;
                }

                $ref = $block['fields']['_sourcePartRef']
                    ?? sprintf('%s#%s[%s]', $parentRef, $handle, (string) $index);

                $block['fields']['_sourcePartRef'] = $ref;
                $block['fields'] = $this->stampNestedRefs($block['fields'], $ref);
                $value[$index] = $block;
            }

            $fields[$handle] = $value;
        }

        return $fields;
    }

    /** `a.b` and `a[0].b` address nested Matrix positions. */
    private function assign(array &$fields, string $path, mixed $value): void
    {
        if (!str_contains($path, '.') && !str_contains($path, '[')) {
            $fields[$path] = $value;

            return;
        }

        if (preg_match('/^(\w+)\[(\d+)\]\.(.+)$/', $path, $m) === 1) {
            [$field, $index, $rest] = [$m[1], (int) $m[2], $m[3]];
            $fields[$field] ??= [];
            $fields[$field][$index] ??= ['type' => $this->childBlockType($field), 'fields' => []];
            $this->assign($fields[$field][$index]['fields'], $rest, $value);

            return;
        }

        [$head, $rest] = explode('.', $path, 2);
        $fields[$head] ??= [];
        $this->assign($fields[$head], $rest, $value);
    }

    /**
     * The nested entry type of a Matrix field, read from the target schema.
     *
     * This used to be a convention table — the singular of the field handle — which happened
     * to be right for most fields and silently wrong for the rest. The schema knows.
     */
    private function childBlockType(string $field): string
    {
        if ($this->schema !== null && $this->block !== null) {
            $nested = $this->schema->nestedTypeOf($this->block, $field);

            if ($nested !== null) {
                return $nested;
            }
        }

        return $field;
    }

    /**
     * A stable identity for one Matrix block, carried as `_sourcePartRef`.
     *
     * The loader strips this before saving, then uses it to match the blocks it just wrote
     * back to their source rows so the next run updates them in place. Without it Craft has
     * no way to recognise a block it already created, and a re-run appends instead of
     * replacing. The legacy row's own primary key is the natural identity: it is stable for
     * as long as the source database is.
     */
    private function sourceRef(string $table, int $id): string
    {
        return sprintf('%s:%s:%d', $this->environment, $table, $id);
    }

    public function environment(): string
    {
        return $this->environment;
    }
}
