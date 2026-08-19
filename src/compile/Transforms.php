<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\compile;

/**
 * The named value transforms a mapping may pipe a column through.
 *
 * Every lossy transform records what it lost. A migration that silently clamps or drops
 * values has no audit trail, and the whole point of this tool is that the things it could
 * not carry across are countable afterwards.
 */
final class Transforms
{
    /** @var list<array{transform:string, from:mixed, to:mixed, part:?string}> */
    private array $lossReport = [];

    /** @param array<string, mixed> $config the mapping's `transforms:` section */
    public function __construct(private readonly array $config = [])
    {
    }

    public function apply(string $name, mixed $value, ?string $context = null): mixed
    {
        return match ($name) {
            'titleLevel'  => $this->titleLevel($value, $context),
            'colorScheme' => $this->colorScheme($value, $context),
            'variant'     => $this->variant($value),
            'bool'        => $value !== null && (int) $value === 1,
            'ckeditor'    => $this->ckeditor($value),
            'inlineHtml'  => $this->inlineHtml($value),
            'asset'       => $value === null ? null : ['_asset' => (string) $value],
            'ref'         => $value === null ? null : ['_ref' => (string) $value],
            default       => throw new \InvalidArgumentException(sprintf('Unknown transform `%s`', $name)),
        };
    }

    /**
     * Normalise the legacy `niv` and clamp it to what the target field offers.
     *
     * `niv` is a free varchar: some rows hold a bare digit, and h1 has no counterpart in a
     * field whose options start at h2.
     */
    private function titleLevel(mixed $value, ?string $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = strtolower(trim((string) $value));
        $level = ctype_digit($raw) ? 'h' . $raw : $raw;

        [$min, $max] = $this->config['titleLevel']['clamp'] ?? ['h2', 'h6'];
        $allowed = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $i = array_search($level, $allowed, true);

        if ($i === false) {
            $this->record('titleLevel', $value, $min, $context);

            return $min;
        }

        $clamped = $allowed[max(array_search($min, $allowed, true), min($i, array_search($max, $allowed, true)))];

        if ($clamped !== $level) {
            $this->record('titleLevel', $level, $clamped, $context);
        }

        return $clamped;
    }

    /** Collapse the legacy colour vocabulary onto the four schemes the target offers. */
    private function colorScheme(mixed $value, ?string $context): ?string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        $map = $this->config['colorScheme']['map'] ?? [];

        if (array_key_exists($raw, $map)) {
            $scheme = (string) $map[$raw];

            if ($raw !== '' && $scheme !== $raw) {
                $this->record('colorScheme', $raw, $scheme, $context);
            }

            return $scheme;
        }

        $this->record('colorScheme', $raw, 'white', $context);

        return 'white';
    }

    /**
     * Derive a variant the legacy data does not carry.
     *
     * `boxed` is never derived: nothing distinguishes it in the source, so an editor opts in
     * afterwards rather than the migration guessing.
     */
    private function variant(mixed $value): string
    {
        return trim((string) ($value ?? '')) === '' ? 'base' : 'band';
    }

    /**
     * A heading value, unwrapped.
     *
     * Half the legacy heading rows store their text wrapped in a block tag. The target field
     * renders its value inside an element chosen by titleLevel, so a surviving <p> would nest
     * a paragraph inside a heading. Inline markup is kept — the field allows a highlighted
     * word — and only the outer block wrapper goes.
     */
    private function inlineHtml(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        while (preg_match('/^<(p|div|h[1-6])(\s[^>]*)?>(.*)<\/\1>$/is', $text, $m) === 1) {
            $text = trim($m[3]);
        }

        $text = trim(strip_tags($text, '<strong><b><em><i><br><span><sup><sub>'));

        return $text === '' ? null : $text;
    }

    /** Legacy HTML, with media references parked for the loader to rewrite. */
    private function ckeditor(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return preg_replace(
            '/\/media\/(\d+)\//',
            '{{kuma:media:$1}}',
            (string) $value,
        );
    }

    private function record(string $transform, mixed $from, mixed $to, ?string $context): void
    {
        $this->lossReport[] = ['transform' => $transform, 'from' => $from, 'to' => $to, 'part' => $context];
    }

    /**
     * What the transforms could not carry across, grouped for reporting.
     *
     * @return array<string, array<string, int>>
     */
    public function losses(): array
    {
        $grouped = [];

        foreach ($this->lossReport as $loss) {
            $key = sprintf('%s -> %s', (string) $loss['from'], (string) $loss['to']);
            $grouped[$loss['transform']][$key] = ($grouped[$loss['transform']][$key] ?? 0) + 1;
        }

        foreach ($grouped as &$counts) {
            arsort($counts);
        }

        return $grouped;
    }

    public function lossCount(): int
    {
        return count($this->lossReport);
    }
}
