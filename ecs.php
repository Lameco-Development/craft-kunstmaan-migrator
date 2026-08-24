<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/lib/kuma-compile/src',
        __DIR__ . '/lib/kuma-compile/tests',
        __FILE__,
    ]);

    $ecsConfig->skip([
        __DIR__ . '/storage',
    ]);

    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);
};
