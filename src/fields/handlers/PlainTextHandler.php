<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields\handlers;

use lameco\kunstmaanmigrator\fields\FieldHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use RuntimeException;

/**
 * v2 PlainTextHandler — 4 modes (plain | ckeditor | link | dropdown).
 * v1's 5th SEOmatic mode dropped per Phase 3 / Plan 03-08 — Phase 4 / ADP-01 reinstates
 * the SEOmatic mode + writer method + the payload-builder constructor parameter.
 *
 * PlainTextHandler — collapsed handler (D-08-12b in v1).
 *
 * Absorbs the legacy CKEditor / Link / Dropdown handlers via a single
 * `$mode` dispatcher. Each former handler's id() is preserved so
 * mapping.yaml keeps working without edits: the registry wires one
 * instance per mode with the matching id().
 *
 * Modes:
 *   'plain'    (default) — cast scalar-ish value to string; null → "".
 *   'ckeditor'           — rewrite legacy `[M<id>]` / `[NT<id>]` tokens via
 *                          CkeditorRewriterService. Empty/null → "".
 *   'link'               — classify the legacy link string into
 *                          {email, entry, url} and return Craft 5 Link-field
 *                          shape `['type' => ..., 'value' => ...]`. Empty/null → null.
 *   'dropdown'           — validate against an allowed-list; in-list → string,
 *                          unknown → null (default) or throw on onUnknown='throw'.
 *
 * Accepted handlerOptions surface (audited against mapping.yaml + tests/):
 *   plain:     (none; PlainText fields take no options)
 *   ckeditor:  (none)
 *   link:      (none; state-table 'page' lookup is implicit)
 *   dropdown:  allowed     list<string>   REQUIRED
 *              onUnknown   'skip'|'throw' default 'skip'
 *
 * Security note (Pitfall 5 mitigation): every handlerOptions key that any
 * pre-collapse handler accepted MUST survive verbatim. The list above is
 * the full accepted surface; adding new keys is a mapping.yaml concern and
 * should be validated by MappingValidator at load time.
 */
final class PlainTextHandler implements FieldHandler
{
    public function __construct(
        private readonly string $mode = 'plain',
    ) {
        if (!in_array($this->mode, ['plain', 'ckeditor', 'link', 'dropdown'], true)) {
            throw new RuntimeException("PlainTextHandler: unknown mode '{$this->mode}'.");
        }
    }

    public function id(): string
    {
        return $this->mode === 'plain' ? 'plain' : $this->mode;
    }

    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): mixed
    {
        return match ($this->mode) {
            'plain'    => $this->writePlain($legacyValue),
            'ckeditor' => $this->writeCkeditor($legacyValue, $ctx),
            'link'     => $this->writeLink($legacyValue, $ctx),
            'dropdown' => $this->writeDropdown($legacyValue, $options),
        };
    }

    /** Fallback scalar → string; null becomes "". */
    private function writePlain(mixed $legacyValue): string
    {
        if ($legacyValue === null) {
            return '';
        }
        return (string) $legacyValue;
    }

    /**
     * CKEditor body — delegate ref-token rewriting to the shared service.
     * The `[M<id>]` / `[NT<id>]` placeholder semantics are owned by the
     * rewriter service; this mode is a thin dispatch point.
     */
    private function writeCkeditor(mixed $legacyValue, ResolverContext $ctx): string
    {
        if ($legacyValue === null || $legacyValue === '') {
            return '';
        }
        return $ctx->ck->rewrite((string) $legacyValue, $ctx->siteId);
    }

    /**
     * Classify legacy link into {email, entry, url}. Returns Craft 5
     * Link-field shape or null for empty input.
     *
     *   1. empty / null               → null
     *   2. contains '@' w/o '://'     → email (mailto: prefix added)
     *   3. starts with '/'            → try entry lookup ('page' state)
     *                                   → fall back to url if no match
     *   4. else                       → url
     *
     * @return array{type: string, value: mixed}|null
     */
    private function writeLink(mixed $legacyValue, ResolverContext $ctx): ?array
    {
        if ($legacyValue === null || $legacyValue === '') {
            return null;
        }
        $value = (string) $legacyValue;

        if (str_contains($value, '@') && !str_contains($value, '://')) {
            return [
                'type' => 'email',
                'value' => str_starts_with($value, 'mailto:') ? $value : 'mailto:' . $value,
            ];
        }

        if (str_starts_with($value, '/')) {
            $id = $ctx->state->getTargetId('page', $value, $ctx->siteId);
            if ($id === null) {
                $id = $ctx->state->getTargetId('page', $value, null);
            }
            if ($id !== null) {
                return ['type' => 'entry', 'value' => $id];
            }
        }

        return ['type' => 'url', 'value' => $value];
    }

    /**
     * Validate a scalar value against a fixed allowed-list.
     *
     * Options:
     *   allowed   (list<string>, REQUIRED)
     *   onUnknown (string, default 'skip') — 'skip' | 'throw'
     *
     * @param array<string, mixed> $options
     */
    private function writeDropdown(mixed $legacyValue, array $options): ?string
    {
        $allowed = $options['allowed'] ?? null;
        if (!is_array($allowed)) {
            throw new RuntimeException("PlainTextHandler(dropdown): requires 'allowed' option (list<string>).");
        }
        $allowed = array_map('strval', $allowed);

        $value = $legacyValue === null ? '' : (string) $legacyValue;

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $onUnknown = (string) ($options['onUnknown'] ?? 'skip');
        if ($onUnknown === 'throw') {
            throw new RuntimeException(sprintf(
                "PlainTextHandler(dropdown): unknown value '%s' — allowed: [%s].",
                $value,
                implode(', ', $allowed),
            ));
        }

        return null;
    }
}
