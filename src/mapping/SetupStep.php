<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

/**
 * The steps of setting a migration up, in order.
 *
 * Declared once so the screens and the controller cannot disagree about what
 * comes next — a wizard whose "step 2 of 4" is written into four templates is
 * a wizard that says "3 of 4" on two of them within a month.
 */
enum SetupStep: string
{
    case Connect = 'connect';
    case Sites = 'sites';
    case Locales = 'locales';
    case Review = 'review';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Connect, self::Sites, self::Locales, self::Review];
    }

    public function number(): int
    {
        return array_search($this, self::all(), true) + 1;
    }

    public function title(): string
    {
        return match ($this) {
            self::Connect => 'Connect',
            self::Sites => 'Choose sites',
            self::Locales => 'Match languages',
            self::Review => 'Review',
        };
    }

    /**
     * What this step asks of the operator, in one line.
     *
     * On the screen rather than in a manual: the question a step is asking is
     * the thing somebody needs to read, and it is the thing they will not go
     * looking for.
     */
    public function question(): string
    {
        return match ($this) {
            self::Connect => 'Where is the old site’s database?',
            self::Sites => 'Which of these sites are you migrating?',
            self::Locales => 'Where should each language and its files go?',
            self::Review => 'Ready to create the mapping?',
        };
    }

    public function next(): ?self
    {
        return self::all()[$this->number()] ?? null;
    }

    public function previous(): ?self
    {
        return self::all()[$this->number() - 2] ?? null;
    }

    public function isBefore(self $other): bool
    {
        return $this->number() < $other->number();
    }
}
