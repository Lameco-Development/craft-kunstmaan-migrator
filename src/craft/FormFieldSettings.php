<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

/**
 * The shapes Formie stores a field's settings in, built from what a mapping can say.
 *
 * The mapping speaks in legacy terms — a list of options, an attribute that marks a
 * field, a sentence with a link in it. Formie stores each of those in a shape of its
 * own: option rows, label/value attribute rows, a ProseMirror document. Translating
 * between the two belongs with the gateway, for the same reason the field vocabulary
 * does: which legacy class becomes which `type:` is the project's decision, and what
 * that type means to Formie is this plugin's.
 *
 * Deliberately free of Formie symbols so it is testable without the plugin installed —
 * `VerbbFormieGateway` is the only caller, and the seam's in-memory twin needs none of
 * this to stand in for it.
 */
final class FormFieldSettings
{
    /** The tags a legacy consent label may carry. Measured: across 1,855 rows, only `a`. */
    private const MARKS = [
        'a' => 'link',
        'strong' => 'bold',
        'b' => 'bold',
        'em' => 'italic',
        'i' => 'italic',
    ];

    /**
     * An options field's rows.
     *
     * A mapping supplies the labels — `choices | lines` — and the value is the label,
     * which is what the legacy form posted: Kunstmaan's Choice has no separate value
     * column, so inventing one would change what every existing integration receives.
     *
     * Null rather than an empty list when there is nothing to set: assigning `[]` would
     * take away the placeholder row Formie's own Dropdown ships with.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function options(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        $out = [];

        foreach ($value as $option) {
            if (is_array($option)) {
                // Already in Formie's shape — a mapping that states its own values.
                $out[] = $option;

                continue;
            }

            $label = trim((string) $option);

            if ($label === '') {
                continue;
            }

            $out[] = ['label' => $label, 'value' => $label, 'isDefault' => false];
        }

        return $out === [] ? null : $out;
    }

    /**
     * A field's HTML attributes.
     *
     * Formie stores them as rows of label/value rather than as a map, which is what
     * `getInputAttributes()` folds back into one. A mapping states the map — it is the
     * readable half — and this is the fold in the other direction.
     *
     * @return list<array{label: string, value: string}>|null
     */
    public static function inputAttributes(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        $out = [];

        foreach ($value as $name => $attribute) {
            if (is_array($attribute)) {
                $out[] = $attribute;

                continue;
            }

            $out[] = ['label' => (string) $name, 'value' => (string) $attribute];
        }

        return $out === [] ? null : $out;
    }

    /**
     * A rich-text field's ProseMirror document, from the HTML a legacy column holds.
     *
     * Formie's `Agree` keeps its description this way, and the description is the whole
     * point of the field: a consent label is "I agree to the <a>terms</a>", and 674 of
     * the corpus's 1,855 legacy checkbox labels carry exactly that anchor. Rendered as a
     * plain field label the tags would print literally, which is why the type is `agree`
     * and not `checkboxes`.
     *
     * The subset is what the corpus holds — text, `a`, and the emphasis tags — plus `br`
     * and `p`, which cost nothing to support and are the two an editor adds next. A tag
     * outside it is dropped and its words kept: losing a `<span>` is nothing, losing the
     * sentence inside it is the consent text.
     *
     * A link keeps the target and rel the source states and invents neither. The legacy
     * anchors state no target, so a migrated consent link opens in the same tab exactly
     * as it did on the legacy site.
     *
     * @return list<array<string, mixed>>|null null when there are no words to show
     */
    public static function prose(mixed $value): ?array
    {
        $html = trim((string) ($value ?? ''));

        if ($html === '') {
            return null;
        }

        $paragraphs = [];

        foreach (self::paragraphs($html) as $paragraph) {
            $content = self::inline($paragraph);

            if ($content !== []) {
                $paragraphs[] = [
                    'type' => 'paragraph',
                    'attrs' => ['textAlign' => 'start'],
                    'content' => $content,
                ];
            }
        }

        return $paragraphs === [] ? null : $paragraphs;
    }

    /**
     * The document split into paragraphs. A legacy label is one; `<p>` is honoured for
     * the rows an editor pasted from a rich-text field.
     *
     * @return list<string>
     */
    private static function paragraphs(string $html): array
    {
        $split = preg_split('~</p\s*>|<p(?:\s[^>]*)?>~i', $html) ?: [$html];

        return array_values(array_filter(array_map('trim', $split), static fn(string $p): bool => $p !== ''));
    }

    /**
     * One paragraph's text nodes, each carrying the marks that were open over it.
     *
     * @return list<array<string, mixed>>
     */
    private static function inline(string $html): array
    {
        $nodes = [];
        /** @var list<array<string, mixed>> $open */
        $open = [];
        $offset = 0;

        while (preg_match('~<(/?)([a-z0-9]+)((?:\s[^>]*)?)/?>~i', $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$tag, $at] = [$m[0][0], (int) $m[0][1]];
            self::pushText($nodes, substr($html, $offset, $at - $offset), $open);

            $name = strtolower($m[2][0]);
            $closing = $m[1][0] === '/';
            $offset = $at + strlen($tag);

            if ($name === 'br') {
                $nodes[] = ['type' => 'hardBreak'];

                continue;
            }

            $mark = self::MARKS[$name] ?? null;

            if ($mark === null) {
                // Not a mark we carry — `<span>`, `<div>`, whatever else. The tag goes,
                // the text on either side of it does not.
                continue;
            }

            if ($closing) {
                array_pop($open);

                continue;
            }

            $open[] = $mark === 'link'
                ? ['type' => 'link', 'attrs' => self::linkAttributes($m[3][0])]
                : ['type' => $mark];
        }

        self::pushText($nodes, substr($html, $offset), $open);

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $marks
     */
    private static function pushText(array &$nodes, string $text, array $marks): void
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($text === '') {
            return;
        }

        $marks = array_values($marks);
        $last = array_key_last($nodes);

        // A dropped tag leaves the text on either side of it in one run, and ProseMirror
        // stores a run as one node: `<span>Accept</span> the terms` is one sentence, not
        // two adjacent nodes that happen to render side by side.
        if ($last !== null
            && $nodes[$last]['type'] === 'text'
            && ($nodes[$last]['marks'] ?? []) === $marks) {
            $nodes[$last]['text'] .= $text;

            return;
        }

        $node = ['type' => 'text'];

        if ($marks !== []) {
            $node['marks'] = $marks;
        }

        $node['text'] = $text;
        $nodes[] = $node;
    }

    /**
     * @return array{href: ?string, target: ?string, rel: ?string, class: ?string}
     */
    private static function linkAttributes(string $attributes): array
    {
        $read = static function(string $name) use ($attributes): ?string {
            $pattern = sprintf('~\b%s\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $name);

            if (preg_match($pattern, $attributes, $m) !== 1) {
                return null;
            }

            $value = html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $value === '' ? null : $value;
        };

        return [
            'href' => $read('href'),
            'target' => $read('target'),
            'rel' => $read('rel'),
            'class' => $read('class'),
        ];
    }
}
