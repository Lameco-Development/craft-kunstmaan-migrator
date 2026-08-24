<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\FieldExpression;
use PHPUnit\Framework\TestCase;

/**
 * `niv | titleLevel` is two decisions wearing the costume of one string.
 *
 * Split, they are two dropdowns of real options. The risk is the split being
 * lossy: an expression the form cannot hold must keep its text box rather than
 * be silently rewritten into something shorter.
 */
final class FieldExpressionTest extends TestCase
{
    public function testAColumnAndATransformSplitIntoTwoChoices(): void
    {
        $expression = FieldExpression::parse('niv | titleLevel');

        self::assertSame('niv', $expression->column);
        self::assertSame('titleLevel', $expression->transform);
        self::assertFalse($expression->isAdvanced());
    }

    public function testABareColumnHasNoTransform(): void
    {
        $expression = FieldExpression::parse('company_name');

        self::assertSame('company_name', $expression->column);
        self::assertSame('', $expression->transform);
    }

    /**
     * `link(link_url, title, link_new_window)` composes several columns into one
     * field; `ref(CaseCategory)` names an entity rather than a column. Forcing
     * either into two dropdowns would drop the arguments.
     */
    public function testAnExpressionTheFormCannotHoldKeepsItsTextBox(): void
    {
        foreach (['link(link_url, title, link_new_window)', 'category_id | ref(CaseCategory)'] as $raw) {
            $expression = FieldExpression::parse($raw);

            self::assertTrue($expression->isAdvanced(), $raw . ' should stay hand-written');
            self::assertSame($raw, $expression->advanced);
        }
    }

    public function testAnEmptyExpressionIsSimplyEmpty(): void
    {
        $expression = FieldExpression::parse('  ');

        self::assertSame('', $expression->column);
        self::assertFalse($expression->isAdvanced());
    }

    public function testComposingIsTheInverseOfParsing(): void
    {
        foreach (['niv | titleLevel', 'company_name', 'link(a, b)'] as $raw) {
            $expression = FieldExpression::parse($raw);

            self::assertSame($raw, FieldExpression::compose(
                $expression->column,
                $expression->transform,
                $expression->advanced,
            ));
        }
    }

    /** A field nobody fills contributes no key, rather than an empty one. */
    public function testAnUnfilledFieldComposesToNothing(): void
    {
        self::assertSame('', FieldExpression::compose('', '', ''));
        self::assertSame('', FieldExpression::compose('', 'ckeditor', ''));
    }

    /** Hand-written wins: it is the only form that can hold everything. */
    public function testTheHandWrittenFormWinsWhenBothArePresent(): void
    {
        self::assertSame('link(a, b)', FieldExpression::compose('title', 'inlineHtml', 'link(a, b)'));
    }
}
