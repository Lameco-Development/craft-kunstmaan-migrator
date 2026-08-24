<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\sites;

/**
 * One legacy locale bound to one Craft site.
 *
 * Three modules used to derive this same join independently — walk Craft's
 * sites, reverse-look-up each handle in the locale map, project the one field
 * that module wanted. Same join, three answers, three chances to disagree.
 */
final class SiteBinding
{
    public function __construct(
        public readonly string $locale,
        public readonly string $handle,
        public readonly int $siteId,
        public readonly string $language,
    ) {
    }
}
