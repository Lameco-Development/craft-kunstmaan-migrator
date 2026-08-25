<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\base\Element;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use DateTime;
use DateTimeImmutable;
use Lameco\Kunstmaanmigrator\load\BlockIdentity;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * applyPerSiteData is the one funnel every site save goes through — the
 * primary-site first save and every reload-before-save alike. These tests pin
 * the native-property promotion (parentId, postDate, expiryDate, authorId),
 * the native-key strip, the field-layout filter, the homepage slug fallback
 * and the block-UID threading, all through stub entries so none of it needs a
 * booted Craft.
 */
final class EntryMigrationServiceApplyPerSiteDataTest extends TestCase
{
    private function apply(
        Entry $entry,
        array $data,
        array $blockUidMap = [],
        ?string $stateSource = null,
    ): void {
        $blocks = new BlockIdentity(new InMemoryElementWriter(), ['default' => $blockUidMap]);
        (new ReflectionMethod(EntryMigrationService::class, 'applyPerSiteData'))
            ->invoke(new EntryMigrationService(), $entry, $data, $blocks, null, $stateSource, '5', 'default');
    }

    public function testAParentIdInThePayloadLandsOnTheEntry(): void
    {
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['parentId' => 7, 'fieldValues' => []]);

        self::assertSame(7, $entry->getParentId());
    }

    public function testATypedImmutablePostDateIsCoercedToTheMutableDateTimeCraftRequires(): void
    {
        // Entry::$postDate is typed ?DateTime — handing it a DateTimeImmutable
        // is a TypeError, which is exactly what a caller passing extract output
        // straight through would trigger.
        $entry = ApplyStubEntry::make();

        $this->apply($entry, [
            'postDate' => new DateTimeImmutable('2020-01-02 03:04:05'),
            'fieldValues' => [],
        ]);

        self::assertInstanceOf(DateTime::class, $entry->postDate);
        self::assertSame('2020-01-02 03:04:05', $entry->postDate->format('Y-m-d H:i:s'));
    }

    public function testAStringPostDateFromAMappedFieldIsPromotedAndStrippedFromCustomFields(): void
    {
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['fieldValues' => ['postDate' => '2019-05-06 07:08:09', 'body' => 'x']]);

        self::assertSame('2019-05-06 07:08:09', $entry->postDate?->format('Y-m-d H:i:s'));
        self::assertArrayNotHasKey('postDate', $entry->capturedFieldValues);
    }

    public function testAnUnparseablePostDateFallsBackToNowSoTheEntryStaysRoutable(): void
    {
        // resaving=true suppresses Craft's own postDate default; a NULL
        // postDate leaves the entry pending and the frontend 404s.
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['fieldValues' => ['postDate' => 'not-a-date']]);

        self::assertInstanceOf(DateTime::class, $entry->postDate);
    }

    public function testAnExistingPostDateSurvivesAReRunWithNoSourceDate(): void
    {
        $entry = ApplyStubEntry::make();
        $entry->postDate = new DateTime('2001-09-09 01:46:40');

        $this->apply($entry, ['fieldValues' => []]);

        self::assertSame('2001-09-09 01:46:40', $entry->postDate->format('Y-m-d H:i:s'));
    }

    public function testAnExpiryDateIsPromotedToTheNativeProperty(): void
    {
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['fieldValues' => ['expiryDate' => '2030-01-01 00:00:00']]);

        self::assertSame('2030-01-01 00:00:00', $entry->expiryDate?->format('Y-m-d H:i:s'));
        self::assertArrayNotHasKey('expiryDate', $entry->capturedFieldValues);
    }

    public function testAMappedAuthorIdBecomesTheEntryAuthor(): void
    {
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['fieldValues' => ['authorId' => '12']]);

        self::assertSame([12], $entry->capturedAuthorIds);
    }

    public function testAZeroAuthorIdLeavesCraftsDefaultAuthorAlone(): void
    {
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['fieldValues' => ['authorId' => 0]]);

        self::assertNull($entry->capturedAuthorIds);
    }

    public function testNativeKeysNeverReachSetFieldValues(): void
    {
        // CustomFieldBehavior rejects title/slug/etc. as unknown properties, so
        // letting them through fails the save on every entry that maps one.
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['fieldValues' => [
            'title' => 'T',
            'slug' => 's',
            'enabled' => true,
            'parentId' => 3,
            'authorId' => 12,
            'body' => 'kept',
        ]]);

        self::assertSame(['body'], array_keys($entry->capturedFieldValues));
    }

    public function testAFieldTheLayoutDoesNotDeclareIsDroppedInsteadOfFailingTheSave(): void
    {
        // The drop is reported through a direct Craft::warning() call — unlike
        // every other warning in the class it skips the class_exists guard, so
        // the alias has to exist before this branch runs (in production it
        // always does; Composer does not autoload it).
        if (!class_exists(\Craft::class, false)) {
            require_once dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
        }

        $entry = ApplyStubEntry::make(layoutFieldHandles: ['body']);

        $this->apply($entry, ['fieldValues' => ['body' => 'kept', 'ghostField' => 'stale mapping row']]);

        self::assertSame(['body' => 'kept'], $entry->capturedFieldValues);
    }

    public function testAHomePageWithoutASlugGetsCraftsHomepageUriMarker(): void
    {
        // Kunstmaan stores no slug for the lvl=0 homepage; without the marker
        // Craft derives "home" from the title and the site root moves to /home.
        $entry = ApplyStubEntry::make();

        $this->apply($entry, ['slug' => null, 'fieldValues' => []], stateSource: 'App_Entity_Pages_HomePage');

        self::assertSame(Element::HOMEPAGE_URI, $entry->slug);
    }

    public function testAMissingSlugOnAnOrdinaryPageLeavesTheExistingSlugInPlace(): void
    {
        $entry = ApplyStubEntry::make();
        $entry->slug = 'contact';

        $this->apply($entry, ['slug' => '', 'fieldValues' => []], stateSource: 'App_Entity_Pages_TextPage');

        self::assertSame('contact', $entry->slug);
    }

    public function testKnownBlockUidsAreThreadedIntoTheMatrixPayloadBeforeTheSave(): void
    {
        // Re-runs must update the existing nested entry in place; a "new1" key
        // where an id is known duplicates the block on every run.
        $entry = ApplyStubEntry::make();

        $this->apply(
            $entry,
            ['fieldValues' => ['pageBuilder' => [
                'new1' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:5', 'content' => 'x']],
                'new2' => ['type' => 'text', 'fields' => ['_sourcePartRef' => 'Text:6', 'content' => 'y']],
            ]]],
            blockUidMap: ['Text:5' => '901'],
        );

        $captured = $entry->capturedFieldValues['pageBuilder'];

        // PHP casts the numeric-string key to int; Craft reads it back as an
        // element id either way.
        self::assertSame([901, 'new2'], array_keys($captured));
        self::assertArrayNotHasKey('_sourcePartRef', $captured[901]['fields']);
    }
}

/**
 * Built without Entry's constructor (no booted app). Captures the two calls
 * the promotion path ends in — setFieldValues and setAuthorIds — and answers
 * getFieldLayout/getType from test-supplied stubs instead of services.
 *
 * @internal
 */
final class ApplyStubEntry extends Entry
{
    public array $capturedFieldValues = [];

    public ?array $capturedAuthorIds = null;

    private ?FieldLayout $stubLayout = null;

    /** @param list<string>|null $layoutFieldHandles null = no layout (filter skipped) */
    public static function make(?array $layoutFieldHandles = null): self
    {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        if ($layoutFieldHandles !== null) {
            $entry->stubLayout = ApplyStubFieldLayout::withHandles($layoutFieldHandles);
        }

        return $entry;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->stubLayout;
    }

    public function getType(): EntryType
    {
        $type = (new \ReflectionClass(EntryType::class))->newInstanceWithoutConstructor();
        $type->handle = 'stubType';

        return $type;
    }

    public function setFieldValues(array $values): void
    {
        $this->capturedFieldValues = $values;
    }

    public function setAuthorIds(array|string|int|null $authorIds): void
    {
        $this->capturedAuthorIds = array_map(intval(...), (array) $authorIds);
    }
}

/**
 * @internal
 */
final class ApplyStubFieldLayout extends FieldLayout
{
    /** @var list<PlainText> */
    private array $stubFields = [];

    /** @param list<string> $handles */
    public static function withHandles(array $handles): self
    {
        $layout = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        foreach ($handles as $handle) {
            $field = (new \ReflectionClass(PlainText::class))->newInstanceWithoutConstructor();
            $field->handle = $handle;
            $layout->stubFields[] = $field;
        }

        return $layout;
    }

    public function getCustomFields(): array
    {
        return $this->stubFields;
    }
}
