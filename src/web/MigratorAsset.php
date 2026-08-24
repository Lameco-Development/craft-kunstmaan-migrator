<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\web;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The one JS helper the live screens share.
 *
 * Three screens grew the same swap loop — loading state, sendActionRequest,
 * innerHTML, restore — one copy each, three chances to diverge on the details
 * that should be uniform. The helper owns them once, including the error path
 * none of the copies had.
 */
final class MigratorAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['migrator.js'];

        parent::init();
    }
}
