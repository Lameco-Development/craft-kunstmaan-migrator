<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * The `forms:` lane's facts, as the compiler and the reports read them.
 *
 * The lane's per-field rows stay on `Mapping::formFields()`; this is the rest of
 * it: whether it is declared at all, which Kunstmaan context(s) hold a page's
 * form, and the block the lane emits (with the field on it the lane fills itself).
 *
 * Every COM form-context page turned out to be a `PotionsLandingPage` — until
 * `PotionsFormPage` (Shomi) surfaced with the same fields sitting in `main`
 * instead, because that page type's own pagepart admin allows them there. `main`
 * is also the page builder lane's default context for every page type, so the
 * lane cannot simply read `main` everywhere: it would try to read a form out of
 * any page that happens to share that context, which is every page. `overrides:`
 * is the escape hatch — a page entity named there reads its own context list in
 * place of the lane-wide default, so `main` is scoped to the one page type that
 * actually carries form fields there. The pagepart class itself stays the real
 * guard against double-compiling: `Schema::checkLaneCollisions()` already forbids
 * a class from being claimed by both `forms:` and `parts:`, so wherever the page
 * builder lane also walks `main` it finds no block mapping for `SingleLineText`
 * and friends and skips them, the same way it always has.
 */
final class FormsLane
{
    private const DEFAULT_CONTEXT = 'form';

    /**
     * @param list<string> $contexts every context the lane reads, for a page entity with
     *        no `overrides:` entry of its own; `$context` is `$contexts[0]`, kept for
     *        callers that only ever dealt with one
     * @param array<string, list<string>> $contextsByEntity a page entity's own context
     *        list, read in place of `$contexts` entirely — not merged with it
     */
    private function __construct(
        public readonly bool $declared,
        public readonly string $context,
        public readonly array $contexts,
        public readonly array $contextsByEntity,
        public readonly ?string $emitBlock,
        public readonly ?string $emitField,
    ) {
    }

    public static function fromSpec(mixed $spec): self
    {
        if (!is_array($spec) || $spec === []) {
            return new self(false, self::DEFAULT_CONTEXT, [self::DEFAULT_CONTEXT], [], null, null);
        }

        $emit = is_array($spec['emit'] ?? null) ? $spec['emit'] : [];
        $contexts = self::contextList($spec['contexts'] ?? $spec['context'] ?? null);

        return new self(
            declared: true,
            context: $contexts[0],
            contexts: $contexts,
            contextsByEntity: self::overridesFrom($spec['overrides'] ?? null),
            emitBlock: self::string($emit, 'block'),
            emitField: self::string($emit, 'field'),
        );
    }

    /**
     * The contexts this lane reads for one page entity: its own `overrides:` entry
     * when the mapping names one, else the lane-wide default.
     *
     * @return list<string>
     */
    public function contextsFor(string $entity): array
    {
        return $this->contextsByEntity[$entity] ?? $this->contexts;
    }

    /** @param array<string, mixed> $spec */
    private static function string(array $spec, string $key): ?string
    {
        $value = $spec[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Accepts the original singular `context: form` as well as a `contexts: [...]`
     * list, so an existing mapping keeps working unedited.
     *
     * @return list<string>
     */
    private static function contextList(mixed $value): array
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $out[] = $item;
                }
            }

            $out = array_values(array_unique($out));

            return $out !== [] ? $out : [self::DEFAULT_CONTEXT];
        }

        return is_string($value) && $value !== '' ? [$value] : [self::DEFAULT_CONTEXT];
    }

    /**
     * `overrides:` names a page entity and either a singular `context:` or a
     * `contexts:` list, the same grammar as the lane-wide default.
     *
     * @return array<string, list<string>> page entity => contexts
     */
    private static function overridesFrom(mixed $spec): array
    {
        if (!is_array($spec)) {
            return [];
        }

        $out = [];

        foreach ($spec as $entity => $override) {
            if (!is_string($entity) || $entity === '') {
                continue;
            }

            $override = is_array($override) ? $override : [];
            $out[$entity] = self::contextList($override['contexts'] ?? $override['context'] ?? null);
        }

        return $out;
    }
}
