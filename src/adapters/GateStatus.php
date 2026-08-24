<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\adapters;

/**
 * Why an adapter is or is not going to run.
 *
 * The distinction between the two skip states is load-bearing rather than
 * cosmetic: a report that cannot tell "the operator turned this off" from
 * "the plugin is not installed" is telling the operator nothing they can act
 * on. The old code recorded that distinction in a comment and expressed it as
 * two similarly-worded warning strings.
 */
enum GateStatus
{
    case Ready;
    case DisabledByOperator;
    case PluginMissing;
}
