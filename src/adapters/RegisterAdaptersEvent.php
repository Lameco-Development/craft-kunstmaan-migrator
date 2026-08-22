<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

use yii\base\Event;

final class RegisterAdaptersEvent extends Event
{
    /** @var list<Adapter> */
    public array $adapters = [];
}
