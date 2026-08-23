<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Legacy\Introspection;
use Lameco\KumaCompile\Mapping\Mapping;
use Lameco\KumaCompile\Report\IntrospectionCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The three ways a mapping silently disagrees with the source it migrates, checked
 * against the introspection artifact rather than guessed: an unclaimed ManyToMany, an
 * editor-facing column ignored without a reason, and a mapped column the entity lacks.
 */
final class IntrospectionCheckTest extends TestCase
{
    private function introspection(): Introspection
    {
        return Introspection::fromArray([
            'mode' => 'boot',
            'entities' => [
                'App\Entity\PageParts\CasesPagePart' => [
                    'table' => 'cases_page_parts',
                    'columns' => [
                        'title' => ['column' => 'title'],
                        'hideTitle' => ['column' => 'hide_title'],
                    ],
                    'associations' => [
                        ['field' => 'items', 'kind' => 'ManyToMany', 'target' => 'Kunstmaan\Node', 'joinTable' => 'casespagepart_node'],
                        ['field' => 'image', 'kind' => 'ManyToOne', 'target' => 'Media', 'joinColumns' => ['image_id']],
                    ],
                ],
            ],
            'formTypes' => [
                'App\Form\CasesPagePartAdminType' => [
                    'entity' => 'App\Entity\PageParts\CasesPagePart',
                    'fields' => ['title', 'hideTitle'],
                ],
            ],
        ]);
    }

    private function mapping(array $spec): Mapping
    {
        return Mapping::fromArray([
            'version' => 1,
            'environments' => ['COM' => ['database' => 'com', 'locales' => ['en' => 'comEnUs']]],
            'parts' => ['Cases' => ['table' => 'cases_page_parts', 'block' => 'caseBlock'] + $spec],
        ]);
    }

    #[Test]
    public function an_unclaimed_many_to_many_is_a_warning_naming_the_join_table(): void
    {
        $warnings = (new IntrospectionCheck(
            $this->mapping(['map' => ['heading' => 'title'], 'ignore' => ['hide_title' => 'display toggle']]),
            $this->introspection(),
        ))->warnings();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('casespagepart_node', $warnings[0]);
    }

    #[Test]
    public function an_editor_facing_column_silently_ignored_is_a_warning(): void
    {
        $warnings = (new IntrospectionCheck(
            // `hide_title` has a form widget and sits in a list-form ignore; the m2m is not
            // claimed either, so filter to the form-widget warning.
            $this->mapping(['map' => ['heading' => 'title'], 'ignore' => ['hide_title']]),
            $this->introspection(),
        ))->warnings();

        $formWidget = array_values(array_filter($warnings, fn (string $w) => str_contains($w, 'form widget')));

        self::assertCount(1, $formWidget);
        self::assertStringContainsString('hide_title', $formWidget[0]);
    }

    #[Test]
    public function a_mapped_column_the_entity_lacks_is_a_warning_and_a_join_column_is_not(): void
    {
        $warnings = (new IntrospectionCheck(
            $this->mapping(['map' => [
                'heading' => 'titel | inlineHtml',   // typo — not a column
                'image' => 'image_id | asset',       // an association join column — real
            ], 'ignore' => ['hide_title' => 'display toggle']]),
            $this->introspection(),
        ))->warnings();

        $missing = array_values(array_filter($warnings, fn (string $w) => str_contains($w, 'not a column')));

        self::assertCount(1, $missing);
        self::assertStringContainsString('titel', $missing[0]);
    }
}
