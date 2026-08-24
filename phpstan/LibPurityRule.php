<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\phpstan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * kuma-compile stays pure, mechanically.
 *
 * The lib exists so the compile side tests and runs without a booted Craft;
 * every leak so far (Craft::warning in a pure export builder) was caught by a
 * test dying on "Class Craft not found" — after the fact. This rule catches
 * the reference at analysis time instead: nothing under lib/kuma-compile may
 * name Craft, craft\*, yii\*, or the plugin's own namespace.
 *
 * @implements Rule<Node>
 */
final class LibPurityRule implements Rule
{
    private const FORBIDDEN = ['Craft', 'craft\\', 'yii\\', 'lameco\\kunstmaanmigrator\\'];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_contains($scope->getFile(), 'lib' . DIRECTORY_SEPARATOR . 'kuma-compile' . DIRECTORY_SEPARATOR)) {
            return [];
        }

        $errors = [];

        foreach ($this->referencedNames($node) as $name) {
            foreach (self::FORBIDDEN as $prefix) {
                if ($name === rtrim($prefix, '\\') || str_starts_with($name, $prefix)) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'kuma-compile must stay pure: `%s` belongs to the Craft side. Pass the fact in, or move this code to src/.',
                        $name,
                    ))->identifier('kumaCompile.purity')->line($node->getStartLine())->build();
                }
            }
        }

        return $errors;
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
