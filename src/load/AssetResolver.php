<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\load;

use Lameco\Kunstmaanmigrator\run\EnvironmentContext;

/**
 * The asset service's two JIT lookups, bound to one environment.
 *
 * `CkeditorRewriterService` resolves `{{kuma:media:<id>}}` tokens and raw
 * `/uploads/media/...` URLs as it rewrites, and it has no environment of its
 * own — so it is handed this rather than the service. Which uploads
 * directories a lookup searches is then fixed at the point the environment is
 * opened, not read off a property something else wrote last.
 */
final class AssetResolver
{
    public function __construct(
        private readonly AssetMigrationService $assets,
        private readonly EnvironmentContext $env,
        private readonly MigrationOptions $opts,
    ) {
    }

    public function resolveFromLegacyId(int $kumaMediaId): int
    {
        return $this->assets->resolveFromLegacyId($kumaMediaId, $this->env, $this->opts);
    }

    public function resolveFromLegacyUrl(string $legacyUrl): int
    {
        return $this->assets->resolveFromLegacyUrl($legacyUrl, $this->env, $this->opts);
    }
}
