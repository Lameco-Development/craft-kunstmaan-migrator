<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * One `parts:` row, as the compiler and the checks read it.
 *
 * The row grammar — which key names the block, what counts as "not compiled",
 * where a `switch:` keeps its cases — used to be re-parsed at every consumer,
 * and the answers drifted: two sites took a part out of the blocks lane only
 * when it carried *both* `drop:` and `manual:`, the rest on either. This is
 * the one reading. `disposition()` is the one truth table; everything else is
 * a typed view of the same spec.
 *
 * `MappingRow` is the editor's lane-agnostic view of the same row (decided /
 * dropped / open); this one answers the kernel's questions.
 */
final class PartRow
{
    /** Compiled into Page Builder blocks — the default when no disposition says otherwise. */
    public const BLOCKS = 'blocks';

    /** `drop:` — deliberately not migrated, with a reason. */
    public const DROPPED = 'dropped';

    /** `manual:` — rebuilt by hand after the run, with a reason. */
    public const MANUAL = 'manual';

    /** `consumedBy: sequence` — a heading or separator the sequence rules absorb or re-emit. */
    public const SEQUENCE = 'sequence';

    /** @param array<string, mixed> $spec the row as the file holds it */
    private function __construct(
        public readonly string $name,
        public readonly array $spec,
    ) {
    }

    /** A non-mapping value (`Foo: ~`) reads as an empty row: undecided, with nothing to build. */
    public static function fromSpec(string $name, mixed $spec): self
    {
        return new self($name, is_array($spec) ? $spec : []);
    }

    /** One of BLOCKS, DROPPED, MANUAL, SEQUENCE. */
    public function disposition(): string
    {
        return match (true) {
            ($this->spec['consumedBy'] ?? null) === 'sequence' => self::SEQUENCE,
            isset($this->spec['drop']) => self::DROPPED,
            isset($this->spec['manual']) => self::MANUAL,
            default => self::BLOCKS,
        };
    }

    /**
     * Whether any lane reads this part's table. A `drop:` OR a `manual:` takes
     * it out — either one, not both; a sequence-consumed part is still read.
     */
    public function isMigrated(): bool
    {
        return !in_array($this->disposition(), [self::DROPPED, self::MANUAL], true);
    }

    /** Whether the blocks lane owns this part: migrated, and not handed to the sequence rules. */
    public function compilesToBlocks(): bool
    {
        return $this->disposition() === self::BLOCKS;
    }

    /**
     * Whether somebody has decided about this row: a target, a switch, or a
     * reason it has none. A suggester drafting over a decided row would replace
     * a decision with a guess.
     */
    public function isDecided(): bool
    {
        return !$this->compilesToBlocks() || $this->blocks() !== [] || isset($this->spec['switch']);
    }

    /** The reason behind a `drop:` or `manual:`; null when the row is compiled or the reason was left out. */
    public function reason(): ?string
    {
        $reason = $this->spec['drop'] ?? $this->spec['manual'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /** The single block a `block:` names. A `switch:` part has none here; see `blocks()`. */
    public function block(): ?string
    {
        return $this->string('block');
    }

    /**
     * Every block this part can become: the `block:` and each `switch:` case, deduplicated.
     *
     * @return list<string>
     */
    public function blocks(): array
    {
        $blocks = [];

        if (($block = $this->block()) !== null) {
            $blocks[] = $block;
        }

        foreach ($this->switchCases() as $case) {
            if (isset($case['block']) && is_string($case['block']) && $case['block'] !== '') {
                $blocks[] = $case['block'];
            }
        }

        return array_values(array_unique($blocks));
    }

    /** @return list<array<string, mixed>> */
    public function switchCases(): array
    {
        $cases = $this->spec['switch'] ?? [];

        return is_array($cases) ? array_values(array_filter($cases, is_array(...))) : [];
    }

    public function table(): ?string
    {
        return $this->string('table');
    }

    public function live(): ?int
    {
        return isset($this->spec['live']) ? (int) $this->spec['live'] : null;
    }

    /** @return array<string, mixed> Craft field path => legacy column expression */
    public function map(): array
    {
        return $this->arrayOf('map');
    }

    /** @return array<string, array<string, mixed>> Matrix field => child collection spec */
    public function children(): array
    {
        return array_filter($this->arrayOf('children'), is_array(...));
    }

    /** @return array<string, array<string, mixed>> child table => promotion spec */
    public function promote(): array
    {
        return array_filter($this->arrayOf('promote'), is_array(...));
    }

    /**
     * `absorbInto: <field>[0]` — the nested path a sequence-absorbed heading lands at on
     * this part. Null when absent (the schema derives the path) and when the part refuses
     * absorption; `refusesAbsorption()` tells those two apart.
     */
    public function absorbInto(): ?string
    {
        return $this->string('absorbInto');
    }

    /** `absorbInto: false` — the target block carries no heading, so nothing may be absorbed into it. */
    public function refusesAbsorption(): bool
    {
        return ($this->spec['absorbInto'] ?? null) === false;
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
