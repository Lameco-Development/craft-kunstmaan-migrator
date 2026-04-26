<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\source;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use yii\base\Component;

/**
 * Resolves the absolute path to the Kunstmaan source checkout (D-30 / D-33).
 *
 * Single source of truth for source-path resolution. Reads the
 * `kunstmaanSourcePath` Settings value (which falls back to the
 * KUNSTMAAN_SOURCE_PATH env var via Settings::init()'s `??=` ladder — Phase 1
 * D-12 pattern) and validates it:
 *
 *   1. Settings value non-empty
 *   2. realpath() resolves (path exists, no broken symlinks)
 *   3. resolved path is a directory
 *   4. resolved path contains `src/Entity/` (Doctrine entity location — the
 *      minimum signature of a Kunstmaan / Symfony source checkout)
 *
 * Returns the absolute realpath on success; null on any validation failure.
 *
 * Consumed by:
 *   - DoctorController::checkKunstmaanSourcePath (5th check — D-31).
 *   - AnalyzeController source-path gate (Plan 5 wires this in).
 *   - KunstmaanSourceScanner / KunstmaanPageStructureScanner (Plans 4-5).
 *
 * Threat model (T-02.1-01-01 mitigation): realpath() resolves `..` segments
 * and symlinks; explicit is_dir() checks reject paths that don't resolve to
 * real directories. Operator input never reaches shell or SQL — this resolver
 * only feeds is_dir() + filesystem reads downstream.
 */
final class KunstmaanSourcePathResolver extends Component
{
    /**
     * Validated absolute path to the Kunstmaan source checkout, or null when
     * unset / invalid. Callers MUST treat null as "fail closed" — analyze
     * cannot proceed without a real source path (D-31; greenfield-fallback
     * dropped).
     */
    public function resolve(): ?string
    {
        $settingsPath = (string) (Plugin::getInstance()->getSettings()->kunstmaanSourcePath ?? '');
        if ($settingsPath === '') {
            return null;
        }
        $real = realpath($settingsPath);
        if ($real === false || !is_dir($real)) {
            return null;
        }
        if (!is_dir($real . '/src/Entity')) {
            return null;
        }
        return $real;
    }
}
