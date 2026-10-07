<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * Whether a `map:` expression evaluates to an asset — an `{_asset}` node the loader resolves to
 * the migrated file.
 *
 * An Assets field's `children:` row has to: anything else hands Craft a raw legacy id (related to
 * whichever asset happens to carry it) or, through `ref()`, a list nested inside the asset list.
 * It lives in the mapping vocabulary rather than beside `Compile\Transforms` because the target
 * check asks it too, and `Target` sits below `Compile`.
 */
final class AssetExpression
{
    /**
     * The transforms whose result is an `{_asset}` node, by name. A transform that takes
     * arguments — `name | file(uploads/x)` — is matched on its name, so registering one is
     * adding its name here.
     *
     * @var list<string>
     */
    public const TRANSFORMS = ['asset'];

    /**
     * It ends in an asset transform, or it is a `coalesce()` whose every alternative does — read
     * the way `Compile\BlockBuilder` evaluates it.
     */
    public static function yieldsAsset(string $expression): bool
    {
        $expression = trim($expression);

        if (preg_match('/^coalesce\((.*)\)$/s', $expression, $m) === 1) {
            $alternatives = FieldExpression::splitArguments($m[1]);

            return $alternatives !== []
                && array_filter($alternatives, static fn(string $a): bool => !self::yieldsAsset($a)) === [];
        }

        $parts = array_map(trim(...), explode('|', $expression));

        if (count($parts) < 2) {
            return false;
        }

        // `file(uploads/x)` is the transform `file` with an argument.
        $name = (string) preg_replace('/\s*\(.*\)$/s', '', (string) end($parts));

        return in_array($name, self::TRANSFORMS, true);
    }
}
