<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\elements\Entry;
use craft\models\FieldLayout;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A merged entry receives multiple contributors — a page entity plus a
 * `single:` config row (Kunstmaan AbstractConfig). The config row carries no
 * title of its own, and applying it must not blank the title an earlier
 * contributor set. Same rule the slug branch has always had.
 */
final class EntryTitlePreservationTest extends TestCase
{
    private function apply(Entry $entry, array $data): void
    {
        (new ReflectionMethod(EntryMigrationService::class, 'applyPerSiteData'))
            ->invoke(new EntryMigrationService(), $entry, $data, null);
    }

    public function testANullTitleLeavesTheExistingEntryTitleInPlace(): void
    {
        $entry = TitleStubEntry::withTitle('Footer');

        $this->apply($entry, ['title' => null, 'fieldValues' => []]);

        self::assertSame('Footer', $entry->title);
    }

    public function testAConcreteTitleStillOverwrites(): void
    {
        $entry = TitleStubEntry::withTitle('Footer');

        $this->apply($entry, ['title' => 'Contact', 'fieldValues' => []]);

        self::assertSame('Contact', $entry->title);
    }

    public function testAFieldValuesTitleStillWinsWhenTheNativeOneIsAbsent(): void
    {
        $entry = TitleStubEntry::withTitle('Footer');

        $this->apply($entry, ['title' => null, 'fieldValues' => ['title' => 'Mapped title']]);

        self::assertSame('Mapped title', $entry->title);
    }
}

/**
 * Built without Entry's constructor (no booted app); the two members the
 * title path touches beyond plain properties are stubbed out.
 *
 * @internal
 */
final class TitleStubEntry extends Entry
{
    public static function withTitle(string $title): self
    {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $entry->title = $title;

        return $entry;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return null;
    }

    public function setFieldValues(array $values): void
    {
    }
}
