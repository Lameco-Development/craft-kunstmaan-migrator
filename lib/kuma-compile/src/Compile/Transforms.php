<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Compile;

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

    /**
     * Every transform, and what it does, in words.
     *
     * Declared here beside the implementations so the two cannot drift, and so
     * an editor can offer them rather than expecting somebody to know that
     * `niv | titleLevel` is how a legacy heading level becomes a Craft one.
     *
     * @return array<string, string> name => what it does
     */
    public static function available(): array
    {
        return [
            'bool' => 'Yes/no — 1 becomes true, anything else false',
            'ckeditor' => 'Rich text — keeps the formatting, rewrites old links and images',
            'inlineHtml' => 'Plain text — strips block tags, keeps bold and italic',
            'titleLevel' => 'Heading level — h1 becomes h2 where the field has no h1',
            'colorScheme' => 'Colour — maps the old palette onto the one Craft offers',
            'variant' => 'Variant — maps the old style name onto Craft’s',
            'asset' => 'File — turns a legacy media id into the migrated asset',
            'ref' => 'Relation — turns a legacy id into the entry it became',
            'url' => 'Web address — adds https:// to a bare domain',
            'mailto' => 'Email link',
            'tel' => 'Phone link',
            'externalUrl' => 'External link only — refuses internal legacy links',
            'beforeComma' => 'The part before the first comma',
            'afterComma' => 'The part after the first comma',
        ];
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
            'externalUrl' => $this->externalUrl($value, $context),
            'beforeComma' => $this->commaPart($value, 0),
            'afterComma'  => $this->commaPart($value, 1),
            'url'         => $this->url($value, $context),
            'mailto'      => $this->scheme($value, 'mailto:', $context),
            'tel'         => $this->scheme($value, 'tel:', $context),
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

    /**
     * A column that holds either a URL or a Kunstmaan internal link, reduced to the URL.
     *
     * `[NT<id>]` is not a URL and a Craft Link field rejects it outright — 175 of 225 case
     * pages carry that form in `brand_url` and every one of them failed to save. Dropping it
     * here is only correct because the relation it expresses has a field of its own; the
     * count says how many rows depend on that being true.
     */
    private function externalUrl(mixed $value, ?string $context): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        if (preg_match(EntityIndex::INTERNAL_LINK, $text) === 1) {
            $this->record('externalUrl', $text, 'internal link, not a URL', $context);

            return null;
        }

        return $text;
    }

    /**
     * One side of a `Name, role` string.
     *
     * The legacy `contact_person` column holds both halves of what the target models as two
     * fields — 22 case pages carry "Phil Lewin, headteacher" in one varchar. Splitting on the
     * first comma is what the old template did when it rendered them, and a name without a
     * comma is a name with no role rather than a parse failure.
     */
    private function commaPart(mixed $value, int $index): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        $parts = array_map(trim(...), explode(',', $text, 2));

        return ($parts[$index] ?? '') !== '' ? $parts[$index] : null;
    }

    /**
     * A bare domain, given the scheme a Link field needs.
     *
     * Legacy website columns are what an editor typed: 29 German partners have
     * `www.oesedv.de` and Craft rejects every one of them — "Website is invalid" — failing the
     * whole entry. Anything that cannot be a host at all is dropped and counted rather than
     * handed over to fail.
     */
    private function url(mixed $value, ?string $context): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $text) === 1) {
            return $text;
        }

        // A host, optionally with a path: at least one dot and a plausible TLD.
        if (preg_match('~^[\w-]+(\.[\w-]+)+(?:[/?#].*)?$~u', $text) === 1) {
            $this->record('url', $text, 'https://' . $text, $context);

            return 'https://' . $text;
        }

        $this->record('url', $text, 'not a URL', $context);

        return null;
    }

    /**
     * A Link field restricted to `email` or `tel` stores its value with the scheme attached.
     * A bare address reads as a URL and Craft rejects it — "Email no longer allows URL
     * links" — which failed every DE partner page on a column holding a perfectly good
     * address.
     */
    private function scheme(mixed $value, string $scheme, ?string $context = null): ?string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        if ($scheme === 'tel:') {
            // A dialable value: keep digits and a leading +, drop the spacing and brackets
            // people type into a CMS.
            $dialable = (string) preg_replace('/(?!^\+)[^0-9+]/', '', str_replace(' ', '', $text));

            // One partner's phone column holds the word "Senpro". Stripping it to nothing left
            // a bare `tel:`, which Craft rejects and which fails the whole entry.
            if (preg_match('/\d/', $dialable) !== 1) {
                $this->record('tel', $text, 'no dialable digits', $context);

                return null;
            }

            $text = $dialable;
        }

        if ($scheme === 'mailto:') {
            // A column holding `a@x.de; c@x.de; g@x.de` is three addresses in a field that
            // takes one. The first is the one the old template showed.
            $addresses = preg_split('/[;,]/', $text) ?: [$text];

            if (count($addresses) > 1) {
                $this->record('mailto', $text, trim($addresses[0]), $context);
            }

            $text = trim($addresses[0]);

            if ($text === '' || !str_contains($text, '@')) {
                return null;
            }
        }

        return str_starts_with($text, $scheme) ? $text : $scheme . $text;
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

    /**
     * A media column pointing at a row that is deleted or gone. Counted rather than dropped
     * silently — a missing image is a content decision, not a detail.
     */
    public function recordMissingAsset(?string $context, mixed $id): void
    {
        if ($id !== null && $id !== '') {
            $this->record('asset', 'media:' . (string) $id, 'unresolved', $context);
        }
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
