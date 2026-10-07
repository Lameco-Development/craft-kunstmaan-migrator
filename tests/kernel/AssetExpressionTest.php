<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Mapping\AssetExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether a `map:` expression evaluates to an asset — what an Assets field's `children:` row must.
 * A transform is known by its name, so one that takes arguments (`file(uploads/x)`) is matched on
 * the name alone, and a new asset transform only has to register it.
 */
final class AssetExpressionTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function expressions(): array
    {
        return [
            'the asset transform' => ['media_id | asset', true],
            'spaced oddly' => ['  media_id|asset  ', true],
            'an asset transform with arguments' => ['name | asset(uploads/models_import)', true],
            'a coalesce of assets' => ['coalesce(media_id | asset, weight | asset(uploads))', true],
            'a coalesce with one non-asset' => ['coalesce(media_id | asset, weight)', false],
            'a bare column' => ['media_id', false],
            'an entry ref' => ['media_id | ref(Media)', false],
            'asset then another transform' => ['media_id | asset | url', false],
            'a transform whose name merely starts with asset' => ['media_id | assets', false],
            'a file under an uploads directory' => ['name | file(uploads/models_import)', true],
            'a file carrying its own path' => ['path | file', true],
            'a coalesce of a media asset and a file' => ['coalesce(media_id | asset, name | file(uploads/x))', true],
            'file then another transform' => ['name | file(uploads/x) | url', false],
            'a transform whose name merely starts with file' => ['name | files', false],
        ];
    }

    #[Test]
    #[DataProvider('expressions')]
    public function an_expression_yields_an_asset_when_it_ends_in_an_asset_transform(string $expression, bool $yields): void
    {
        self::assertSame($yields, AssetExpression::yieldsAsset($expression));
    }

    #[Test]
    public function the_registered_asset_transforms_are_named(): void
    {
        self::assertSame(['asset', 'file'], AssetExpression::TRANSFORMS);
    }
}
