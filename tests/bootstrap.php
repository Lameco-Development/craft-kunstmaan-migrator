<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

// Craft elements need the Yii base class and the generated custom-field
// behavior before they can even be constructed. Loading both here is what lets
// the migration passes be driven with fakes instead of a booted CMS.
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/stubs/CustomFieldBehavior.php';
