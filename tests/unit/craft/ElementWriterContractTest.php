<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use craft\elements\Entry;
use Lameco\Kunstmaanmigrator\craft\CraftElementWriter;
use Lameco\Kunstmaanmigrator\craft\ElementWriter;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryElementWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The contract both adapters answer to.
 *
 * A fake that drifts from the adapter it stands in for is worse than no fake:
 * it turns green tests into evidence for the wrong thing. These pin the parts
 * of the contract the in-memory adapter has to get right — the defaults, and
 * the site-scoping rule findById inherits from Craft's getElementById.
 */
final class ElementWriterContractTest extends TestCase
{
    private function element(int $id): Entry
    {
        $entry = (new \ReflectionClass(Entry::class))->newInstanceWithoutConstructor();
        $entry->id = $id;

        return $entry;
    }

    public function testSaveDefaultsToValidatingWithoutPropagating(): void
    {
        $writer = new InMemoryElementWriter();
        $writer->save($this->element(1));

        self::assertTrue($writer->saved[0]['runValidation']);
        self::assertFalse(
            $writer->saved[0]['propagate'],
            'propagating to sites the payload never named is what leaked nested entries onto them',
        );
        self::assertTrue($writer->saved[0]['updateSearchIndex'], 'outside a run, Craft indexes on save as it always did');
    }

    /**
     * Deferral is a switch around the saves, and resuming says how many it
     * covered — the number the operator compares against the index stage.
     */
    public function testDeferringSearchIndexingCoversTheSavesInBetween(): void
    {
        $writer = new InMemoryElementWriter();

        $writer->save($this->element(1));
        $writer->deferSearchIndexing();
        $writer->deferSearchIndexing();
        $writer->save($this->element(2));
        $writer->save($this->element(3));
        $deferred = $writer->resumeSearchIndexing();
        $writer->save($this->element(4));

        self::assertSame(2, $deferred);
        self::assertSame([true, false, false, true], array_column($writer->saved, 'updateSearchIndex'));
        self::assertSame(0, $writer->resumeSearchIndexing(), 'resuming twice counts nothing twice');
    }

    public function testSiteIdsOfAnswersTheSitesAnElementWasWrittenOrDeclaredOn(): void
    {
        $writer = new InMemoryElementWriter();
        $onSiteTwo = $this->element(7);
        $onSiteTwo->siteId = 2;

        $writer->save($onSiteTwo);
        $writer->willLiveOn(7, [5]);

        self::assertSame([2, 5], $writer->siteIdsOf(7));
        self::assertSame([], $writer->siteIdsOf(8), 'an element with no row anywhere');
    }

    public function testDeleteDefaultsToTheSoftDeleteWindow(): void
    {
        $writer = new InMemoryElementWriter();
        $writer->delete($this->element(1));

        self::assertFalse($writer->deleted[0]['hardDelete'], 'a hard delete has to be asked for explicitly');
    }

    public function testFindByIdPrefersTheSiteScopedElement(): void
    {
        $writer = new InMemoryElementWriter();
        $anySite = $this->element(5);
        $siteFive = $this->element(5);

        $writer->willFind(5, $anySite);
        $writer->willFind(5, $siteFive, siteId: 5);

        self::assertSame($siteFive, $writer->findById(5, Entry::class, 5));
        self::assertSame($anySite, $writer->findById(5, Entry::class));
    }

    public function testFindByIdFallsBackToTheUnscopedElement(): void
    {
        $writer = new InMemoryElementWriter();
        $element = $this->element(6);
        $writer->willFind(6, $element);

        self::assertSame(
            $element,
            $writer->findById(6, Entry::class, 9),
            'Craft resolves an element for a site it was not explicitly loaded for',
        );
    }

    public function testFindByIdReturnsNullWhenNothingMatches(): void
    {
        self::assertNull((new InMemoryElementWriter())->findById(1, Entry::class));
    }

    public function testARefusedSaveIsReportedRatherThanThrown(): void
    {
        $writer = new InMemoryElementWriter();
        $element = $this->element(1);
        $writer->willRefuse($element);

        self::assertFalse($writer->save($element));
        self::assertSame([], $writer->saved, 'a refused save is not a save');
    }

    /**
     * The production adapter cannot run without a booted Craft, so what is
     * checkable is its source: a save is a pass-through with no retry around
     * it. The retry it once had ran inside the entry's transaction, which a
     * deadlock has already rolled back whole — the retried element then
     * committed on top of nothing. `run\WriteConflictRetry` retries the
     * payload instead; a `catch` reappearing here is that defect coming back.
     */
    public function testTheProductionAdapterRetriesNothing(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/craft/CraftElementWriter.php');
        $code = preg_replace('~^\s*(?:/\*\*|\*|//).*$~m', '', $source) ?? $source;

        self::assertDoesNotMatchRegularExpression('~\b(?:catch|usleep|sleep)\b~', $code);
    }

    /**
     * The production adapter is a pass-through and cannot run without a booted
     * Craft, so what is checkable here is that it has not drifted from the
     * interface the fake also implements.
     */
    public function testBothAdaptersShareTheSameSignatures(): void
    {
        foreach (['save', 'delete', 'findById', 'invalidateCaches', 'structureEntries', 'updateSlugAndUri'] as $method) {
            $interface = new ReflectionMethod(ElementWriter::class, $method);

            foreach ([CraftElementWriter::class, InMemoryElementWriter::class] as $adapter) {
                $actual = new ReflectionMethod($adapter, $method);

                self::assertSame(
                    $this->signature($interface),
                    $this->signature($actual),
                    sprintf('%s::%s has drifted from the interface', $adapter, $method),
                );
            }
        }
    }

    /**
     * The walk order is the whole contract of structureEntries(): a child's
     * URI is its parent's plus a slug, so a parent visited second leaves the
     * child with the prefix it had. The production query cannot run here, so
     * the order-by is pinned in its source.
     */
    public function testTheProductionWalkIsParentsFirstAndTouchesNoQueue(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(CraftElementWriter::class))->getFileName());

        self::assertStringContainsString("orderBy(['structureelements.lft' => SORT_ASC])", $source);
        self::assertStringContainsString('updateDescendants: false', $source);
        self::assertStringContainsString('queue: false', $source);
    }

    public function testTheFakeRecordsEverySiteAUriUpdateWouldReach(): void
    {
        $writer = new InMemoryElementWriter();
        $entry = $this->element(7);
        $entry->siteId = 1;
        $writer->willLiveOn(7, [1, 2, 3]);

        $writer->updateSlugAndUri($entry);

        self::assertSame([['id' => 7, 'siteIds' => [1, 2, 3]]], $writer->urisUpdated);
    }

    private function signature(ReflectionMethod $method): string
    {
        $parts = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parts[] = ($type instanceof ReflectionNamedType ? $type->getName() : 'mixed')
                . ' $' . $parameter->getName()
                . ($parameter->isDefaultValueAvailable() ? '=' . var_export($parameter->getDefaultValue(), true) : '');
        }

        $return = $method->getReturnType();

        return implode(', ', $parts) . ': ' . ($return instanceof ReflectionNamedType ? $return->getName() : 'mixed');
    }
}
