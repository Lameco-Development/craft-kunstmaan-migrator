<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Compile;

use Lameco\Kunstmaanmigrator\Source\MediaIndex;
use Lameco\Kunstmaanmigrator\Source\PartReader;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;

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
        private ?string $lang = null,
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
        $map = $spec['map'] ?? [];
        $fields = $this->fieldsFrom($map, $row, $partClass);
        $fields['_sourcePartRef'] = $this->sourceRef((string) $table, $partId);
        $fields = $this->stampNestedRefs($fields, $fields['_sourcePartRef']);

        $children = $this->childrenOf($spec['children'] ?? [], (string) $block, $partId, $partClass);
        $firstChild = $this->firstChildOf($spec['firstChild'] ?? [], (string) $block, $partId, $partClass);
        $fields += $children;
        $fields += $firstChild;

        // A row existing is not the same as a row saying anything. `contentMediaVariant` and
        // its kin are filled by a literal in the map (`'band'`), never by a column, so they are
        // set on every row regardless of what that row held — which is how Craft's own required-
        // field validation passed a ContentMedia block with no heading, text or image, and it
        // rendered as nothing on the page. A block earns its place by having at least one field
        // that reads the row (or a nested/first child collection) end up non-empty.
        if ($children === [] && $firstChild === [] && !$this->hasSubstance($map, $fields)) {
            return null;
        }

        return ['type' => (string) $block, 'fields' => $fields];
    }

    /**
     * Whether a compiled block carries content, not just its fixed defaults.
     *
     * Generic on purpose: it does not know "heading" from "variant" by name, only by how the
     * map produced them. A quoted literal (`'band'`) is a design fact the mapping supplies
     * because no column carries it, so it is excluded; everything else in the map reads the
     * row (a column, `link()`, `ref()`, `coalesce()`, ...) and only appears in `$fields` when
     * `fieldsFrom()` found a real value there.
     *
     * @param array<string, string> $map
     * @param array<string, mixed> $fields
     */
    private function hasSubstance(array $map, array $fields): bool
    {
        foreach ($map as $target => $expression) {
            if ($this->isFixedValue((string) $expression)) {
                continue;
            }

            if (preg_match('/^\w+/', (string) $target, $m) === 1 && isset($fields[$m[0]])) {
                return true;
            }
        }

        return false;
    }

    /** A quoted literal (`'band'`, optionally piped through a further transform) is never content. */
    private function isFixedValue(string $expression): bool
    {
        $column = array_map('trim', explode('|', trim($expression)))[0] ?? '';

        return preg_match("/^'(.*)'$/", $column) === 1;
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
     * A child row may itself carry a `children:` — the same shape one level down, recursed
     * rather than given a second mechanism. That is what turns a page-owned collection into
     * one wrapper block with N nested rows instead of N top-level blocks: `productBrandItems`
     * lands as one `cardsBlock` holding N `cardsCards`, not N `cardsBlock`s. A row's `block:`
     * names its type explicitly, for when the field it lands in — a page builder Matrix — takes
     * more than one type and the schema cannot guess which this row means.
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
        ?string $lang = null,
    ): array {
        $previousBlock = $this->block;
        $previousLang = $this->lang;
        $this->block = $owner;

        if ($lang !== null) {
            $this->lang = $lang;
        }

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
            // `button`, and nothing at all on the block that owns the Matrix. `block:` overrides
            // the schema guess, needed the moment the owning field allows more than one type.
            $childType = ($child['block'] ?? '') !== '' ? (string) $child['block'] : $this->childBlockType((string) $field);

            foreach ($rows as $childRow) {
                $fields = $this->fieldsFrom($child['map'] ?? [], $childRow, $context . '.' . $field, $childType, $this->lang);

                // Every block carries its origin, at every depth. The loader threads these back
                // into the payload on a re-run so Craft updates a block in place instead of
                // replacing it — and a nested block that carries none is rebuilt every time,
                // which is what happened to `contentColumn` and `button` on every forced run.
                if (isset($childRow['id'])) {
                    $fields['_sourcePartRef'] = $this->sourceRef((string) $child['table'], (int) $childRow['id']);

                    if (($child['children'] ?? []) !== []) {
                        $fields += $this->childrenOf(
                            (array) $child['children'],
                            $childType,
                            (int) $childRow['id'],
                            $context . '.' . $field,
                            false,
                            $this->lang,
                        );
                    }
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

        $this->block = $previousBlock;
        $this->lang = $previousLang;

        return $out;
    }

    /**
     * The first row of one child collection, its `map:` written straight onto the block being
     * built rather than into a nested Matrix.
     *
     * `children:` is right when every row still matters; some collections only ever needed
     * their first row once the thing they used to be — a slider, a carousel — was retired as a
     * concept (ContentWithSlider: the client's own call was "just carry over the first image, a
     * slider adds nothing any more"). Forcing that through `children:` would still produce a
     * one-block Matrix for content that is really flat. `firstChild:` reads the same row shape
     * as a child collection (`table`, `fk`, `order`, `map`) but has no field of its own to sit
     * in: its `map:` targets are paths on `$owner`, exactly like the part's own `map:`.
     *
     * The row picked is the first in the collection's existing order — the same `order:` a
     * `children:` collection would sort by, `id` when the legacy table has no sequence column
     * of its own. An empty collection contributes nothing, the same as any `map:` expression
     * that evaluates to null; there is no error to raise over a slider with zero slides.
     *
     * @param array<string, array<string, mixed>> $firstChild
     * @return array<string, mixed>
     */
    public function firstChildOf(array $firstChild, string $owner, int $ownerId, string $context): array
    {
        $previous = $this->block;
        $this->block = $owner;
        $fields = [];

        foreach ($firstChild as $name => $child) {
            if (!is_array($child) || ($child['table'] ?? '') === '' || ($child['fk'] ?? '') === '') {
                continue;
            }

            $rows = $this->parts->children(
                (string) $child['table'],
                (string) $child['fk'],
                $ownerId,
                (string) ($child['order'] ?? 'weight'),
            );

            $row = $rows[0] ?? null;

            if ($row !== null) {
                $fields += $this->fieldsFrom($child['map'] ?? [], $row, $context . '.' . $name);
            }
        }

        $this->block = $previous;

        return $fields;
    }

    /**
     * @param array<string, string> $map
     * @param array<string, mixed> $row
     * @param ?string $owner the entry type these targets name, when the caller knows it — a page
     *   map addresses a page entry type, and without it the schema is asked about whichever block
     *   happened to be built last.
     * @param ?string $lang the site locale being compiled, for `lookup(node.title)` — the node
     *   tree keeps one title per locale, and without this the first one it has stands in.
     * @return array<string, mixed>
     */
    public function fieldsFrom(array $map, array $row, string $context, ?string $owner = null, ?string $lang = null): array
    {
        $previousBlock = $this->block;
        $previousLang = $this->lang;

        if ($owner !== null) {
            $this->block = $owner;
        }

        if ($lang !== null) {
            $this->lang = $lang;
        }

        $fields = [];

        foreach ($map as $target => $expression) {
            $value = $this->evaluate((string) $expression, $row, $context, (string) $target);

            if ($value !== null && $value !== '' && $value !== []) {
                $this->assign($fields, (string) $target, $value);
            }
        }

        $this->block = $previousBlock;
        $this->lang = $previousLang;

        return $fields;
    }

    /** `column`, `column | transform`, `column | ref(Entity)`, or `link(url, text, newWindow, type)`. */
    private function evaluate(string $expression, array $row, string $context, string $target = ''): mixed
    {
        $expression = trim($expression);

        if (preg_match('/^link\((.*)\)$/', $expression, $m) === 1) {
            return $this->link(array_map('trim', explode(',', $m[1])), $row, $target);
        }

        if (preg_match('/^links\((.*)\)$/', $expression, $m) === 1) {
            return $this->links($m[1], $row, $target);
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

        // One target, several columns that each hold part of it. ContactPerson keeps prose in
        // both `content` and `contact_person_content` — 80 live rows fill both, so picking one
        // (which is what coalesce does) drops the other's text.
        if (preg_match('/^concat\((.*)\)$/s', $expression, $m) === 1) {
            $values = [];

            foreach ($this->splitArguments($m[1]) as $piece) {
                $value = $this->evaluate($piece, $row, $context);

                if (is_scalar($value) && trim((string) $value) !== '') {
                    $values[] = (string) $value;
                }
            }

            return $values === [] ? null : implode("\n", $values);
        }

        // One target, several expressions on a single line, joined by a literal that is not
        // itself content — a card heading built from a country and a brand ("Netherlands by
        // Acme") needs the pieces on one line, which is what `concat`'s newline join cannot
        // give it. `join(' by ', country_id | lookup(Country.title), brand_id | lookup(node.title))`.
        if (preg_match('/^join\((.*)\)$/s', $expression, $m) === 1) {
            $arguments = $this->splitArguments($m[1]);
            $separator = array_shift($arguments) ?? '';
            $separator = preg_match("/^'(.*)'$/", $separator, $sm) === 1 ? $sm[1] : $separator;

            $values = [];

            foreach ($arguments as $piece) {
                $value = $this->evaluate($piece, $row, $context);

                if (is_scalar($value) && trim((string) $value) !== '') {
                    $values[] = (string) $value;
                }
            }

            return $values === [] ? null : implode($separator, $values);
        }

        $parts = array_map('trim', explode('|', $expression));
        $column = array_shift($parts);

        // `m2m(join_table, owner_column, target_column)` reads the ids the owning row selects
        // through a ManyToMany join table — a relation Doctrine keeps in a table of two foreign
        // keys, which no column expression could reach. Pipes onward like any value; in practice
        // always into `ref()`, which turns each id into the entry it became.
        // `'band'` is a literal: a value the mapping decides because the legacy table has no
        // column that carries it — ContentHighlight has no background_color, and its variant
        // is a design fact, not data.
        if (preg_match("/^'(.*)'$/", (string) $column, $m) === 1) {
            $column = null;
            $value = $m[1];
        } elseif (preg_match('/^m2m\((.*)\)$/', (string) $column, $m) === 1) {
            $args = array_map(trim(...), explode(',', $m[1]));

            if (count($args) !== 3 || !isset($row['id'])) {
                return null;
            }

            $value = $this->parts->m2m($args[0], $args[1], $args[2], (int) $row['id']);
        } else {
            $value = $row[(string) $column] ?? null;
        }

        foreach ($parts as $transform) {
            // A legacy FK holds a row id; the loader resolves an element id from a sourceUid.
            // Building that uid needs the entity's table, which only the mapping knows — so it
            // happens here rather than inside a transform that has no access to it.
            if (preg_match('/^ref\((\w+)\)$/', $transform, $m) === 1) {
                // A relation field takes a list of element ids. The loader replaces each
                // `_ref` node in place, so the list has to be here already — handing it a
                // bare node saved without complaint and related nothing. A list input (an
                // m2m read) keeps its order; an id that resolves to nothing is dropped,
                // because it points at content that did not migrate.
                $refs = [];

                foreach (is_array($value) ? $value : [$value] as $id) {
                    $uid = $this->entities?->uidFor($m[1], $id, $this->environment);

                    if ($uid !== null) {
                        $refs[] = ['_ref' => $uid];
                    }
                }

                return $refs === [] ? null : $refs;
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

    /**
     * One column of the entity row a foreign key points at — or, for `node.title`, the title
     * of the node it names.
     *
     * A node has no `title` column of its own: Kunstmaan keeps it on the translation row, one
     * per locale, which a plain row read by id cannot reach. `brand_id` on `ProductBrandItem`
     * names a BrandPage *node*, and a card heading needs the text an editor sees, not a
     * relation — the same reason `ref()` cannot stand in here.
     */
    private function lookup(string $entity, string $column, mixed $foreignKey): mixed
    {
        if ($foreignKey === null || !ctype_digit((string) $foreignKey)) {
            return null;
        }

        if ($entity === 'node' && $column === 'title') {
            return $this->entities?->titleOfNode((int) $foreignKey, $this->lang);
        }

        $table = $this->entities?->tableFor($entity);

        return $table === null ? null : ($this->parts->row($table, (int) $foreignKey)[$column] ?? null);
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
        $url = $urlCol !== null ? (string) ($row[$urlCol] ?? '') : '';
        $label = $textCol !== null ? (string) ($row[$textCol] ?? '') : '';
        $newWindow = isset($columns[2]) && (int) ($row[$columns[2]] ?? 0) === 1;

        $link = $this->oneLink($url, $label, $newWindow);

        if ($link === null) {
            return null;
        }

        $resolved = $this->resolveTarget($target);
        $slot = $resolved === null ? null : $this->schema?->slot($resolved[0], $resolved[1]);

        if ($slot === null || !$slot->isMatrix()) {
            return $link;
        }

        $nested = $this->schema?->nestedTypeOf($resolved[0], $resolved[1]) ?? $resolved[1];
        $linkHandle = $this->soleSlotOfType($nested, 'Link');

        if ($linkHandle === null) {
            return null;
        }

        $fields = [$linkHandle => $link];
        $style = $this->styleValue($columns[3] ?? null, $row, $target);
        $styleHandle = $style !== '' ? $this->soleSlotOfType($nested, 'Dropdown') : null;

        if ($styleHandle !== null) {
            $fields[$styleHandle] = $style;
        }

        return [['type' => $nested, 'fields' => $fields]];
    }

    /**
     * The fourth `link()` column — the button style — may pipe through a transform:
     * `link(url, text, new_window, tertiary_link_type | buttonType)`.
     *
     * The style lands in a Dropdown, and a dropdown validates its vocabulary: Kunstmaan
     * stores CSS classes (`btn-outline-white`) where Craft offers `primary|secondary`, and
     * writing the class raw failed the whole entry. The first three columns stay bare column
     * names — they feed `oneLink()`, whose shape is fixed.
     */
    private function styleValue(?string $expression, array $row, string $context): string
    {
        if ($expression === null || $expression === '') {
            return '';
        }

        if (!str_contains($expression, '|')) {
            return trim((string) ($row[$expression] ?? ''));
        }

        $parts = array_map('trim', explode('|', $expression));
        $value = $row[array_shift($parts)] ?? null;

        foreach ($parts as $transform) {
            $value = $this->transforms->apply($transform, $value, $context);
        }

        return trim((string) ($value ?? ''));
    }

    /**
     * Several single-URL columns, one button each — `links(twitter=Twitter, linkedin=LinkedIn)`.
     *
     * `link()` gathers the four-column pattern one legacy link is spread across; this is its
     * complement for the opposite shape, N sibling URL columns each carrying a whole link whose
     * label the table never stored. SocialMedia holds five network columns and targets
     * `linksBlock.buttons`, a required Matrix nothing could fill.
     *
     * Matrix targets only: a Craft Link field holds one link, and a part with several columns
     * has several.
     *
     * @return list<array{type: string, fields: array<string, mixed>}>|null
     */
    private function links(string $arguments, array $row, string $target): ?array
    {
        $resolved = $this->resolveTarget($target);
        $slot = $resolved === null ? null : $this->schema?->slot($resolved[0], $resolved[1]);

        if ($slot === null || !$slot->isMatrix()) {
            return null;
        }

        $nested = $this->schema?->nestedTypeOf($resolved[0], $resolved[1]) ?? $resolved[1];
        $linkHandle = $this->soleSlotOfType($nested, 'Link');

        if ($linkHandle === null) {
            return null;
        }

        $blocks = [];

        foreach ($this->splitArguments($arguments) as $argument) {
            // A nested `link(...)` argument is one four-column group — how a table holding
            // several whole links (primary/secondary/tertiary) becomes several buttons.
            if (preg_match('/^link\((.*)\)$/', $argument, $lm) === 1) {
                $columns = array_map('trim', explode(',', $lm[1]));
                $link = $this->oneLink(
                    (string) ($row[$columns[0] ?? ''] ?? ''),
                    isset($columns[1]) ? (string) ($row[$columns[1]] ?? '') : '',
                    isset($columns[2]) && (int) ($row[$columns[2]] ?? 0) === 1,
                );

                if ($link === null) {
                    continue;
                }

                $fields = [$linkHandle => $link];
                $style = $this->styleValue($columns[3] ?? null, $row, $target);
                $styleHandle = $style !== '' ? $this->soleSlotOfType($nested, 'Dropdown') : null;

                if ($styleHandle !== null) {
                    $fields[$styleHandle] = $style;
                }

                $blocks[] = ['type' => $nested, 'fields' => $fields];

                continue;
            }

            [$column, $label] = str_contains($argument, '=')
                ? array_map(trim(...), explode('=', $argument, 2))
                : [$argument, ''];

            $link = $this->oneLink((string) ($row[$column] ?? ''), $label, false);

            if ($link !== null) {
                $blocks[] = ['type' => $nested, 'fields' => [$linkHandle => $link]];
            }
        }

        return $blocks === [] ? null : $blocks;
    }

    /**
     * One link map from a URL, a label and a new-window flag. A link with no URL is not a
     * link, it is an empty row an editor left behind.
     *
     * Kunstmaan writes an internal link as `[NT<id>]`, which a Link field rejects outright.
     * It addresses a node translation, and the node is what becomes an entry, so it is
     * handed over as a ref for the loader to turn into a reference tag.
     *
     * @return array<string, string>|null
     */
    private function oneLink(string $url, string $label, bool $newWindow): ?array
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $ref = $this->entities?->uidFor('nodeLink', $url, $this->environment);
        $link = $ref !== null ? ['_linkRef' => $ref] : $this->linkTarget($url);

        if (trim($label) !== '') {
            $link['label'] = $label;
        }

        if ($newWindow) {
            $link['target'] = '_blank';
        }

        return $link;
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

    /** The slot a target names, walked through any nested positions it addresses. */
    private function slotFor(string $target): ?Slot
    {
        $resolved = $this->resolveTarget($target);

        return $resolved === null ? null : $this->schema?->slot($resolved[0], $resolved[1]);
    }

    /**
     * The entry type and field a target path lands on.
     *
     * `buttons` names a field on the block being built; `cards[0].buttons` names one on the
     * nested card type. Without the walk, `link()` aimed at an indexed position could not see
     * it was feeding a Matrix and emitted a bare link map Craft discards without a word.
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolveTarget(string $target): ?array
    {
        if ($this->schema === null || $this->block === null || $target === '') {
            return null;
        }

        $owner = $this->block;

        while (preg_match('/^(\w+)\[\d+\]\.(.+)$/', $target, $m) === 1) {
            $nested = $this->schema->nestedTypeOf($owner, $m[1]);

            if ($nested === null) {
                return null;
            }

            [$owner, $target] = [$nested, $m[2]];
        }

        if (str_contains($target, '.') || str_contains($target, '[')) {
            return null;
        }

        return [$owner, $target];
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
