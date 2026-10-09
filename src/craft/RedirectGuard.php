<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

/**
 * Retour's automatic URI-change redirects, held off while a run writes.
 *
 * Retour (`createUriChangeRedirects`, on by default) stores a 301 whenever an enabled
 * element's URI changes on a save. A multi-site load changes URIs by design: an entry is
 * saved in its primary site, propagates that slug to the other sites, then each site is
 * saved with its own translated slug. Xidoor's first load left 35 redirects that way —
 * `/companies` → `/bedrijven` on NL, `/contact` → `/contactez-nous` on FR — none of them a
 * URL anyone ever visited. The redirects a migration means to write are the ones the
 * redirects adapter imports; this hold keeps Retour from inventing others.
 *
 * Restored to what it was, not switched on: a project that turned it off keeps it off.
 */
interface RedirectGuard
{
    /** Stop URI-change redirects from being created until `resume()`. */
    public function suspend(): void;

    /** Put the setting back as `suspend()` found it. */
    public function resume(): void;
}
