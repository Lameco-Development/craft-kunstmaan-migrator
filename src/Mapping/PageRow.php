<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * One `pages:` row, as the compiler and the checks read it.
 *
 * Carries the lane-wide default contexts so that `contexts()` is the one place
 * the fallback is decided: the page's own `contexts:`, else `defaults.contexts`,
 * else `Mapping::DEFAULT_CONTEXTS` — and a context with no `field:` streams into
 * `pageBuilder`. Three consumers used to pick three different fallbacks for the
 * same page, and the checks disagreed with the compiler about where blocks land.
 */
final class PageRow
{
    /** Compiled into an entry — the default. */
    public const PAGES = 'pages';

    /** `drop:` — deliberately not migrated, with a reason. */
    public const DROPPED = 'dropped';

    /** `manual:` — rebuilt by hand after the run, with a reason. */
    public const MANUAL = 'manual';

    /** `target: page` — a context whose part fills the page's own fields, not a Matrix. */
    public const PAGE_TARGET = 'page';

    private const DEFAULT_FIELD = 'pageBuilder';

    private const DEFAULT_SECTION = 'pages';

    /**
     * @param array<string, mixed> $spec the row as the file holds it
     * @param array<string, array<string, mixed>> $defaultContexts already normalised by `Mapping`
     */
    private function __construct(
        public readonly string $name,
        public readonly array $spec,
        private readonly array $defaultContexts,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $defaultContexts
     */
    public static function fromSpec(string $name, mixed $spec, array $defaultContexts): self
    {
        return new self($name, is_array($spec) ? $spec : [], $defaultContexts);
    }

    /** One of PAGES, DROPPED, MANUAL. */
    public function disposition(): string
    {
        return match (true) {
            isset($this->spec['manual']) => self::MANUAL,
            isset($this->spec['drop']) => self::DROPPED,
            default => self::PAGES,
        };
    }

    /**
     * Whether the pages lane owns this page. `manual:` and `drop:` both say
     * "deliberately not migrated", and the schema stops checking a row at
     * either — so does everything that compiles or measures one.
     */
    public function isMigrated(): bool
    {
        return $this->disposition() === self::PAGES;
    }

    /** Migrated, and with an entry type to become — the compiler's gate. */
    public function compiles(): bool
    {
        return $this->isMigrated() && $this->entryType() !== null;
    }

    /** The Craft section the entry lands in; `pages` when the row does not say. */
    public function section(): string
    {
        return $this->string('section') ?? self::DEFAULT_SECTION;
    }

    public function entryType(): ?string
    {
        return $this->string('entryType');
    }

    public function table(): ?string
    {
        return $this->string('table');
    }

    /** The legacy column carrying the publication date, when the node's `created` is not it. */
    public function postDate(): ?string
    {
        return $this->string('postDate');
    }

    public function live(): ?int
    {
        return isset($this->spec['live']) ? (int) $this->spec['live'] : null;
    }

    /** @return array<string, mixed> Craft field => legacy column expression */
    public function map(): array
    {
        return $this->arrayOf('map');
    }

    /** @return array<string, array<string, mixed>> Matrix field => child collection spec */
    public function children(): array
    {
        return array_filter($this->arrayOf('children'), is_array(...));
    }

    /**
     * The Kunstmaan contexts this page's block stream is read from, each with the
     * Craft field its blocks land in — per context, so `right_column` can fill a
     * sidebar Matrix beside the Page Builder. Every context has a `field`; the
     * rest of the target (`prepend:`, which prepends within that field) is passed through.
     *
     * @return array<string, array<string, mixed>> context => target
     */
    public function contexts(): array
    {
        return array_filter($this->allContexts(), static fn(array $target): bool => !self::isPageTarget($target));
    }

    /**
     * The contexts declared `target: page`: their first `consumedBy: page` part is written onto
     * this page's own fields rather than streamed into a Matrix. Never part of `contexts()`, so
     * nothing that reasons about block fields — the allow-list checks, `contextFields()` — sees one.
     *
     * @return list<string>
     */
    public function pageContexts(): array
    {
        return array_keys(array_filter($this->allContexts(), self::isPageTarget(...)));
    }

    /** @param array<string, mixed> $target */
    public static function isPageTarget(array $target): bool
    {
        return ($target['target'] ?? null) === self::PAGE_TARGET;
    }

    /** @return array<string, array<string, mixed>> */
    private function allContexts(): array
    {
        $own = $this->spec['contexts'] ?? null;

        return is_array($own) ? self::normaliseContexts($own) : $this->defaultContexts;
    }

    /** @return list<string> the Craft fields blocks land in, deduplicated, in context order */
    public function contextFields(): array
    {
        return array_values(array_unique(array_map(
            static fn(array $target): string => (string) $target['field'],
            array_values($this->contexts()),
        )));
    }

    /**
     * The page's main builder: the first context's field. Each context's blocks land in that
     * context's own field; this is only where a page-level block with no context of its own —
     * the form block, when `forms.field` names none — is written.
     */
    public function builderField(): string
    {
        return $this->contextFields()[0] ?? self::DEFAULT_FIELD;
    }

    /**
     * Gives every block context a `field`. The only place that default lives.
     *
     * @param array<string, mixed> $contexts
     * @return array<string, array<string, mixed>>
     */
    public static function normaliseContexts(array $contexts): array
    {
        $out = [];

        foreach ($contexts as $context => $target) {
            $target = is_array($target) ? $target : [];

            // A page context lands on no Matrix, so it gets no default `field:` — one would read
            // as a builder the entry type lacks, and every check on block fields would trip on it.
            if (self::isPageTarget($target)) {
                $out[(string) $context] = $target;

                continue;
            }

            $field = $target['field'] ?? null;
            $target['field'] = is_string($field) && $field !== '' ? $field : self::DEFAULT_FIELD;
            $out[(string) $context] = $target;
        }

        return $out;
    }

    private function string(string $key): ?string
    {
        $value = $this->spec[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function arrayOf(string $key): array
    {
        $value = $this->spec[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
