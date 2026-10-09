<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\support;

use Lameco\Kunstmaanmigrator\craft\RedirectGuard;

/**
 * The second adapter: Retour's setting as a flag, and a URI change that either becomes a
 * redirect or does not.
 */
final class InMemoryRedirectGuard implements RedirectGuard
{
    public bool $createUriChangeRedirects = true;

    /** @var list<string> every suspend()/resume(), in call order */
    public array $transitions = [];

    /** @var list<string> the redirects Retour would have stored */
    public array $created = [];

    private ?bool $previous = null;

    public function suspend(): void
    {
        $this->transitions[] = 'suspend';
        $this->previous ??= $this->createUriChangeRedirects;
        $this->createUriChangeRedirects = false;
    }

    public function resume(): void
    {
        $this->transitions[] = 'resume';

        if ($this->previous !== null) {
            $this->createUriChangeRedirects = $this->previous;
            $this->previous = null;
        }
    }

    /** What Retour does on a save that changes an enabled element's URI. */
    public function uriChanged(string $from, string $to): void
    {
        if ($this->createUriChangeRedirects) {
            $this->created[] = $from . ' -> ' . $to;
        }
    }
}
