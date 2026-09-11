<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\craft;

use nystudio107\retour\models\Settings;
use nystudio107\retour\Retour;

/**
 * Retour's automatic URI-change redirects, off for the rest of this process.
 *
 * With `createUriChangeRedirects` on, Retour answers every URI change with an "old URI →
 * new URI" redirect, and its `saveRedirect()` overwrites whichever existing redirect has
 * that source. A run moves URIs through states nobody ever visited — a new entry saved
 * before its parent's slug settled, a slug de-duplicated to `-2` — so on the Enreach
 * staging copy Retour made 480 redirects from URIs that were never public, and rewrote
 * two editor-era redirects to point at disabled `-2` rows. The redirect pass computes the
 * redirects a run owes from the legacy URLs; Retour's reflex has nothing to add.
 *
 * Process-local: the setting is read from the loaded plugin, never written back to project
 * config, so a CP request or another worker keeps Retour exactly as configured.
 */
final class RetourUriChangeGuard
{
    /** Whether the setting was found and turned off. */
    public static function suspend(): bool
    {
        if (!class_exists(Retour::class)) {
            return false;
        }

        $settings = Retour::$settings ?? null;

        if (!$settings instanceof Settings) {
            return false;
        }

        $settings->createUriChangeRedirects = false;

        return true;
    }
}
