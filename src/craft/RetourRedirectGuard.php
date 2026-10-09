<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use nystudio107\retour\Retour;

/**
 * The production adapter: Retour's `createUriChangeRedirects` setting, switched off on the
 * plugin's live settings model and restored. Both of Retour's element-save handlers read it
 * on every save, so nothing has to be detached. Retour is optional: without it, or before it
 * has booted, there is nothing to hold.
 */
final class RetourRedirectGuard implements RedirectGuard
{
    private ?bool $previous = null;

    public function suspend(): void
    {
        if ($this->previous !== null || !class_exists(Retour::class) || !isset(Retour::$settings)) {
            return;
        }

        $this->previous = Retour::$settings->createUriChangeRedirects;
        Retour::$settings->createUriChangeRedirects = false;
    }

    public function resume(): void
    {
        if ($this->previous === null) {
            return;
        }

        if (isset(Retour::$settings)) {
            Retour::$settings->createUriChangeRedirects = $this->previous;
        }

        $this->previous = null;
    }
}
