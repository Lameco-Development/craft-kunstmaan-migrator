<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\FieldProvenance;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Target\Slot;
use Lameco\Kunstmaanmigrator\Target\TargetSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts lane feeds a page through its context fields — every context's own field (#89),
 * read the way the compiler reads them: the page's `contexts:`, else `defaults.contexts`.
 */
final class FieldProvenanceTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        defaults:
          contexts:
            content: { field: pageBuilderBerkvensNl }
            right_column: { field: berkvensNlSidebar }
        pages:
          ContentPage:
            table: content_pages
            section: pages
            entryType: contentPage
          CataloguePage:
            table: catalogue_pages
            section: pages
            entryType: cataloguePage
            contexts:
              content: { field: pageBuilderBerkvensNl }
              footer_content: { field: berkvensNlBelowListing }
        parts:
          Text: { table: text_parts, block: textBlock }
        YAML;

    private function provenance(): FieldProvenance
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, self::MAPPING);

        $schema = new class() implements TargetSchema {
            public function hasEntryType(string $handle): bool
            {
                return $this->slots($handle) !== [];
            }

            public function hasSection(string $handle): bool
            {
                return true;
            }

            public function sectionType(string $handle): ?string
            {
                return null;
            }

            public function sectionEntryTypes(string $handle): ?array
            {
                return null;
            }

            public function slots(string $entryType): array
            {
                $matrix = static fn(string $handle): Slot => new Slot($handle, 'Matrix', false, ['textBlock']);

                return match ($entryType) {
                    'contentPage' => [
                        'pageBuilderBerkvensNl' => $matrix('pageBuilderBerkvensNl'),
                        'berkvensNlSidebar' => $matrix('berkvensNlSidebar'),
                    ],
                    'cataloguePage' => [
                        'pageBuilderBerkvensNl' => $matrix('pageBuilderBerkvensNl'),
                        'berkvensNlSidebar' => $matrix('berkvensNlSidebar'),
                        'berkvensNlBelowListing' => $matrix('berkvensNlBelowListing'),
                    ],
                    default => [],
                };
            }

            public function slot(string $entryType, string $field): ?Slot
            {
                return $this->slots($entryType)[$field] ?? null;
            }

            public function requiredFields(string $entryType): array
            {
                return [];
            }

            public function pathFor(string $entryType, string $field): ?string
            {
                return null;
            }

            public function nestedTypeOf(string $entryType, string $field): ?string
            {
                return null;
            }
        };

        return FieldProvenance::of(Mapping::fromFile($path), $schema);
    }

    /** @return array<string, ?int> field => parts count */
    private function partsCounts(string $entryType): array
    {
        return array_map(
            static fn(array $state): ?int => $state['partsCount'],
            $this->provenance()->coverage('page', $entryType)['fields'],
        );
    }

    #[Test]
    public function every_default_context_field_is_fed_by_the_parts_lane(): void
    {
        self::assertSame(
            ['pageBuilderBerkvensNl' => 1, 'berkvensNlSidebar' => 1],
            $this->partsCounts('contentPage'),
        );
    }

    #[Test]
    public function a_pages_own_contexts_decide_which_fields_the_parts_lane_feeds(): void
    {
        // The catalogue page streams `footer_content` and not `right_column`: its sidebar is
        // unfed, and the Matrix below its listing is not.
        self::assertSame(
            ['pageBuilderBerkvensNl' => 1, 'berkvensNlSidebar' => null, 'berkvensNlBelowListing' => 1],
            $this->partsCounts('cataloguePage'),
        );
    }
}
