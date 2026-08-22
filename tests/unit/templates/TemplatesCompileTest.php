<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\templates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The templates have to compile.
 *
 * Obvious, and it was not checked. The settings screen shipped with
 * `{% for adapter in adapters if adapter.settings %}` — Twig 2 syntax that
 * Twig 3 removed — and every test around it passed, because they asserted the
 * template *contained* certain strings. A template is code; string assertions
 * about code are not a substitute for compiling it.
 *
 * This parses each template with a bare Twig, which catches a syntax error. It
 * cannot catch a runtime error — an undefined variable, a method that does not
 * exist — because that needs a booted control panel. It catches the class of
 * bug that actually shipped.
 */
final class TemplatesCompileTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function templates(): iterable
    {
        $root = dirname(__DIR__, 3) . '/src/templates';
        $files = [
            ...(glob($root . '/*.twig') ?: []),
            ...(glob($root . '/*/*.twig') ?: []),
        ];

        foreach ($files as $path) {
            yield str_replace($root . '/', '', $path) => [$path];
        }
    }

    #[DataProvider('templates')]
    public function testTheTemplateCompiles(string $path): void
    {
        $name = basename($path);
        $twig = new Environment(new ArrayLoader([$name => (string) file_get_contents($path)]));

        // Craft's own tags are not Twig's. Only the ones these templates use are
        // stubbed — a template reaching for a tag not listed here should fail
        // loudly rather than be quietly skipped.
        foreach (['js', 'css'] as $tag) {
            $twig->addTokenParser(new class ($tag) extends AbstractTokenParser {
                public function __construct(private readonly string $craftTag)
                {
                }

                public function parse(Token $token): Node
                {
                    $stream = $this->parser->getStream();
                    $stream->expect(Token::BLOCK_END_TYPE);
                    $body = $this->parser->subparse(fn (Token $t): bool => $t->test('end' . $this->craftTag));
                    $stream->next();
                    $stream->expect(Token::BLOCK_END_TYPE);

                    return $body;
                }

                public function getTag(): string
                {
                    return $this->craftTag;
                }
            });
        }

        // Craft's filters and functions are validated at parse time like any
        // other, so the ones these templates use are declared here. Same rule as
        // the tags: anything not listed fails loudly.
        foreach (['t'] as $filter) {
            $twig->addFilter(new TwigFilter($filter, static fn (mixed $value): mixed => $value));
        }

        foreach ([
            'actionUrl', 'url', 'siteUrl',
            'csrfInput', 'actionInput', 'redirectInput', 'hiddenInput',
        ] as $function) {
            $twig->addFunction(new TwigFunction($function, static fn (mixed ...$args): string => ''));
        }

        try {
            $twig->parse($twig->tokenize($twig->getLoader()->getSourceContext($name)));
        } catch (SyntaxError $e) {
            self::fail(sprintf('%s does not compile: %s', $name, $e->getMessage()));
        }

        $this->addToAssertionCount(1);
    }
}
