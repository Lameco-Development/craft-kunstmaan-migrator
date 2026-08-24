<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\phpstan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The compile half stays pure, mechanically.
 *
 * The kernel packages exist so compilation tests and runs without a booted
 * Craft; every leak so far (Craft::warning in a pure export builder) was
 * caught by a test dying on "Class Craft not found" — after the fact. This
 * rule catches the reference at analysis time instead: nothing in a pure
 * package may name Craft, craft\*, yii\*, or any package of the plugin that
 * is not itself pure.
 *
 * Purity is a property of the package, not the directory: the rule keys on
 * the namespace a file declares, compared case-insensitively because PHP
 * does. The kernel's own tests are a pure package too.
 *
 * @implements Rule<Node>
 */
final class LibPurityRule implements Rule
{
    private const VENDOR = 'Lameco\\Kunstmaanmigrator\\';

    /** The packages that may never see a Craft symbol, nor a Craft-side package. */
    private const PURE = ['Payload', 'Source', 'Mapping', 'Target', 'Compile', 'Report', 'Command', 'tests\\kernel'];

    private const FORBIDDEN = ['Craft', 'craft\\', 'yii\\'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null || !self::isPure($namespace . '\\')) {
            return [];
        }

        $errors = [];

        foreach ($this->referencedNames($node) as $name) {
            if (!self::isForbidden($name)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s must stay pure: `%s` belongs to the Craft side. Pass the fact in, or move this code to a Craft-side package.',
                self::package($namespace),
                $name,
            ))->identifier('kumaCompile.purity')->line($node->getStartLine())->build();
        }

        return $errors;
    }

    private static function isForbidden(string $name): bool
    {
        foreach (self::FORBIDDEN as $prefix) {
            if ($name === rtrim($prefix, '\\') || str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return self::inVendor($name) && !self::isPure($name);
    }

    /** Whether a fully-qualified name (or a namespace ending in `\`) sits in a pure package. */
    private static function isPure(string $name): bool
    {
        if (!self::inVendor($name)) {
            return false;
        }

        $rest = substr($name, strlen(self::VENDOR));

        foreach (self::PURE as $package) {
            if (str_starts_with(strtolower($rest), strtolower($package) . '\\')) {
                return true;
            }
        }

        return false;
    }

    private static function inVendor(string $name): bool
    {
        return str_starts_with(strtolower($name), strtolower(self::VENDOR));
    }

    /** `Lameco\Kunstmaanmigrator\Compile` → `Compile`, for the message. */
    private static function package(string $namespace): string
    {
        $rest = substr($namespace, strlen(self::VENDOR));

        return explode('\\', $rest)[0];
    }

    /** @return list<string> */
    private function referencedNames(Node $node): array
    {
        if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            $prefix = $node instanceof Stmt\GroupUse ? $node->prefix->toString() . '\\' : '';

            return array_map(static fn ($use): string => $prefix . $use->name->toString(), $node->uses);
        }

        if (
            ($node instanceof Expr\StaticCall
                || $node instanceof Expr\ClassConstFetch
                || $node instanceof Expr\New_
                || $node instanceof Expr\Instanceof_
                || $node instanceof Expr\StaticPropertyFetch)
            && $node->class instanceof Name
        ) {
            return [$node->class->toString()];
        }

        if ($node instanceof Stmt\Catch_) {
            return array_map(static fn (Name $type): string => $type->toString(), $node->types);
        }

        return [];
    }
}
