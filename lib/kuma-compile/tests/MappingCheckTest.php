<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Legacy\Introspection;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Mapping\MappingCheck;
use Lameco\KumaCompile\Mapping\MappingException;
use Lameco\KumaCompile\Target\Slot;
use Lameco\KumaCompile\Target\SpecNotes;
use Lameco\KumaCompile\Target\TargetSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One verdict for four renderers: the Craft check, the standalone validate,
 * the migrate preflight and the CP button must refuse for the same reason in
 * the same words — and warn about the same things.
 */
final class MappingCheckTest extends TestCase
{
    private function schema(): TargetSchema
    {
        return new class() implements TargetSchema {
            public function hasEntryType(string $handle): bool
            {
                return in_array($handle, ['contentPage', 'contentBlock'], true);
            }

            public function hasSection(string $handle): bool
            {
                return $handle === 'pages';
            }

            public function slots(string $entryType): array
            {
                return match ($entryType) {
                    'contentPage' => ['summary' => new Slot('summary', 'PlainText', false)],
                    'contentBlock' => ['contentColumns' => new Slot('contentColumns', 'PlainText', true)],
                    default => [],
                };
            }

            public function slot(string $entryType, string $field): ?Slot
            {
                return $this->slots($entryType)[$field] ?? null;
            }

            public function requiredFields(string $entryType): array
            {
                return $entryType === 'contentBlock' ? ['contentColumns'] : [];
            }

            public function pathFor(string $entryType, string $field): ?string
            {
                return $this->slot($entryType, $field) !== null ? '' : null;
            }

            public function nestedTypeOf(string $entryType, string $field): ?string
            {
                return null;
            }
        };
    }

    private function mapping(string $yaml): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    /** @return array{0: string, 1: list<string>}|null */
    private function verdict(string $yaml): ?array
    {
        return (new MappingCheck($this->schema()))->verdict($this->mapping($yaml));
    }

    #[Test]
    public function shape_is_judged_before_the_target(): void
    {
        // The entry type is also wrong for this install — but the malformed
        // shape must win, because target errors on a malformed file mislead.
        $verdict = $this->verdict(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            pages:
              ContentPage: { entryType: nopePage, ignore: [], bogus: nope }
            YAML);

        self::assertNotNull($verdict);
        self::assertSame('Mapping is not well-formed', $verdict[0]);
    }

    #[Test]
    public function a_clean_mapping_may_run(): void
    {
        self::assertNull($this->verdict(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            pages:
              ContentPage: { entryType: contentPage, ignore: [] }
            YAML));
    }

    /**
     * The standalone CLI validates before a Craft project exists. Without a
     * target the verdict covers what is checkable — shape and conflicts — and
     * says nothing about handles it cannot see.
     */
    #[Test]
    public function without_a_target_the_verdict_is_shape_and_conflicts(): void
    {
        $check = new MappingCheck();

        self::assertNull($check->verdict($this->mapping(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            pages:
              ContentPage: { entryType: nopePage, ignore: [] }
            YAML)));

        $verdict = $check->verdict($this->mapping(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                map: { contentColumns: body }
                ignore: []
                conflict: { status: open, artifact: says-hero, spec: says-header }
            YAML));

        self::assertNotNull($verdict);
        self::assertStringContainsString('unresolved conflicts', $verdict[0]);
        self::assertSame(['Text: says-hero vs says-header'], $verdict[1]);
    }

    #[Test]
    public function spec_divergence_needs_a_target_to_say_which_fields_exist(): void
    {
        $this->expectException(MappingException::class);

        (new MappingCheck())->verdict($this->mapping("version: 1\n"), SpecNotes::fromDirectory($this->specDir()));
    }

    #[Test]
    public function a_dropped_column_the_spec_gives_a_target_for_blocks(): void
    {
        $verdict = (new MappingCheck($this->schema()))->verdict($this->mapping(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            parts:
              Text:
                table: text_page_parts
                block: contentBlock
                ignore: [background_color]
            YAML), SpecNotes::fromDirectory($this->specDir()));

        self::assertNotNull($verdict);
        self::assertSame('Mapping diverges from the content-model specs', $verdict[0]);
        self::assertCount(1, $verdict[1]);
        self::assertStringContainsString('`background_color` is dropped', $verdict[1][0]);
    }

    #[Test]
    public function warnings_cover_the_target_and_the_legacy_wiring_in_one_list(): void
    {
        $mapping = $this->mapping(<<<'YAML'
            version: 1
            environments:
              COM: { database: legacy, locales: { en: siteEn } }
            parts:
              Cases:
                table: cases_page_parts
                block: contentBlock
                map: {}
                ignore: [hide_title]
            YAML);

        $introspection = Introspection::fromArray([
            'mode' => 'boot',
            'entities' => [
                'App\Entity\PageParts\CasesPagePart' => [
                    'table' => 'cases_page_parts',
                    'columns' => ['title' => ['column' => 'title'], 'hideTitle' => ['column' => 'hide_title']],
                    'associations' => [
                        ['field' => 'items', 'kind' => 'ManyToMany', 'target' => 'Kunstmaan\Node', 'joinTable' => 'casespagepart_node'],
                    ],
                ],
            ],
        ]);

        $warnings = (new MappingCheck($this->schema()))->warnings($mapping, $introspection);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('contentBlock.contentColumns is required but never mapped', $warnings[0]);
        self::assertStringContainsString('casespagepart_node', $warnings[1]);

        self::assertSame([], (new MappingCheck())->warnings($mapping), 'no target, no artifact: nothing to warn about');
    }

    private function specDir(): string
    {
        $dir = sys_get_temp_dir() . '/kuma-check-specs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/contentBlock.md', <<<'MD'
            # Content

            ## Migration notes (Kunstmaan → Craft)

            | Kunstmaan (`Text`) | New field |
            |---|---|
            | `backgroundColor` | `contentColumns` |
            MD);

        return $dir;
    }
}
