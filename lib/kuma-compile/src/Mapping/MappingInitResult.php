<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Mapping;

final class MappingInitResult
{
    public function __construct(
        public readonly string $yaml,
        /** No introspection artifact and no source checkout: table names are left as TODO. */
        public readonly bool $tablesUnresolved,
    ) {
    }
}
