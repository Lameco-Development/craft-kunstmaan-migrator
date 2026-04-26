<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields;

use lameco\kunstmaanmigrator\fields\FieldHandler;

/**
 * Keyed collection of FieldHandler implementations.
 *
 * The plugin pre-populates this registry at boot with the built-in
 * handlers ('plain', 'ckeditor', 'asset', 'relation', 'link', 'dropdown',
 * 'seomatic', 'matrix', 'splitName'); consuming projects can register
 * additional handlers through their own bootstrap hook.
 *
 * Lookup is O(1) hash-keyed. Unknown-id lookups throw a descriptive
 * \RuntimeException listing the registered ids so config typos surface
 * at the first invocation rather than silently producing nulls.
 *
 * v2 reshape: throws \RuntimeException directly (v1 used a typed
 * config-error class that does not exist in v2's surface; the operator-
 * facing message — id + registered-ids — is preserved verbatim).
 */
final class FieldHandlerRegistry
{
    /** @var array<string, \lameco\kunstmaanmigrator\fields\FieldHandler> */
    private array $handlers = [];

    public function register(FieldHandler $handler): void
    {
        $this->handlers[$handler->id()] = $handler;
    }

    public function get(string $id): FieldHandler
    {
        if (!isset($this->handlers[$id])) {
            throw new \RuntimeException(sprintf(
                "FieldHandlerRegistry: unknown handler '%s' — registered: [%s].",
                $id,
                implode(', ', array_keys($this->handlers)),
            ));
        }
        return $this->handlers[$id];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->handlers);
    }
}
