<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\adapters;

use yii\base\Event;

final class RegisterAdaptersEvent extends Event
{
    /** @var list<Adapter> */
    public array $adapters = [];
}
