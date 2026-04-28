<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields;

/**
 * Contract for a single field-value resolver.
 *
 * A FieldHandler converts a legacy value (scalar / row / array) into the
 * Craft-native shape expected by a target field — WITHOUT writing anything
 * to the database. Writes are the save-helper's responsibility; handlers
 * produce values.
 *
 * Handlers are looked up by id() via {@see FieldHandlerRegistry::get()}
 * and invoked by the declarative driver (Plan 04).
 *
 * Implementations must be stateless with respect to the request — all
 * per-call dependencies come through the ResolverContext.
 */
interface FieldHandler
{
    /**
     * Stable short identifier used as the registry key.
     *
     * Examples: 'plain', 'ckeditor', 'asset', 'relation', 'link',
     *           'dropdown', 'seomatic', 'matrix'.
     */
    public function id(): string;

    /**
     * Resolves a legacy value into the Craft-native field payload.
     *
     * @param mixed                $legacyValue raw value from the legacy row
     * @param \lameco\kunstmaanmigrator\fields\ResolverContext $ctx read-only per-call context
     * @param array<string, mixed> $options     per-call options from FieldSpec
     *
     * @return mixed Craft-ready field value (shape depends on target field)
     */
    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed;
}
