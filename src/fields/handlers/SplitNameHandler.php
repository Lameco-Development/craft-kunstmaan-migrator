<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\fields\handlers;

use lameco\kunstmaanmigrator\fields\FieldHandler;
use lameco\kunstmaanmigrator\fields\ResolverContext;
use RuntimeException;

/**
 * SplitNameHandler — Dutch-aware composite-name splitter (D-08-22).
 *
 * Splits a single legacy `real_name` string (e.g. "Dr. Jan van der Meer")
 * into separate Craft name fields:
 *   - prefix    — academic/professional titles (Dr., Ir., Drs., Prof., Mr., Mw.)
 *   - firstName — first non-prefix token
 *   - infix     — Dutch tussenvoegsel (van, de, der, den, ten, ter, het, 't, van der, de la, ...)
 *   - lastName  — everything after the infix (or after firstName if no infix)
 *   - suffix    — trailing generational markers (Jr., Sr., I, II, III, IV)
 *
 * Usage in mapping.yaml (one spec per target handle, all pointing at the
 * same source column, each picking a different `part`):
 *
 *   fields:
 *     firstName: { source: real_name, handler: splitName, handlerOptions: { part: firstName } }
 *     infix:     { source: real_name, handler: splitName, handlerOptions: { part: infix     } }
 *     lastName:  { source: real_name, handler: splitName, handlerOptions: { part: lastName  } }
 *     prefix:    { source: real_name, handler: splitName, handlerOptions: { part: prefix    } }
 *     suffix:    { source: real_name, handler: splitName, handlerOptions: { part: suffix    } }
 *
 * Each resolve() call parses the full name and returns the requested part
 * (empty string if that part is absent from the legacy value). Parsing is
 * pure-PHP, stateless, allocation-light — safe to call once per field per row.
 *
 * Why per-part rather than fan-out: the existing TransformService dispatch
 * writes one target handle per FieldHandler invocation. Fan-out would require
 * widening the handler contract (return array keyed by target handle). Per-part
 * splits keep the contract stable and make each target explicit in mapping.yaml
 * so operators can see at a glance which legacy column feeds which field.
 */
final class SplitNameHandler implements FieldHandler
{
    /** Tokens that classify as a pre-name title (academic/professional). */
    private const PREFIX_TOKENS = [
        'dr', 'dr.', 'ir', 'ir.', 'drs', 'drs.', 'prof', 'prof.',
        'mr', 'mr.', 'mw', 'mw.', 'ing', 'ing.', 'mrs', 'mrs.', 'ms', 'ms.',
    ];

    /**
     * Dutch tussenvoegsels. Lowercase comparison; multi-word forms (e.g.
     * "van der") are matched greedy-first. Keep this list conservative —
     * unknown lowercase middles default to firstName leakage rather than
     * silent infix mis-classification.
     */
    private const INFIX_TOKENS = [
        'van', 'de', 'der', 'den', 'ten', 'ter', 'het', "'t", 'op',
        'aan', 'bij', 'in', 'uit', 'over', 'onder', 'achter',
        'la', 'le', 'du', 'des', 'del', 'da', 'di', 'von', 'zu',
    ];

    /** Trailing generational markers. Case-insensitive match. */
    private const SUFFIX_TOKENS = [
        'jr', 'jr.', 'sr', 'sr.', 'i', 'ii', 'iii', 'iv', 'v',
        'ba', 'bsc', 'msc', 'ma', 'mba', 'phd', 'ph.d.', 'bc', 'ing',
    ];

    private const VALID_PARTS = ['firstName', 'infix', 'lastName', 'prefix', 'suffix'];

    public function id(): string
    {
        return 'splitName';
    }

    public function resolve(mixed $legacyValue, ResolverContext $ctx, array $options = []): string
    {
        $part = (string) ($options['part'] ?? '');
        if (!in_array($part, self::VALID_PARTS, true)) {
            throw new RuntimeException(sprintf(
                "SplitNameHandler: handlerOptions.part must be one of [%s]; got '%s'.",
                implode(', ', self::VALID_PARTS),
                $part,
            ));
        }

        if ($legacyValue === null || $legacyValue === '') {
            return '';
        }

        $parts = $this->split((string) $legacyValue);
        return $parts[$part];
    }

    /**
     * Parse a composite name into its 5 parts. Pure function, no I/O.
     *
     * @return array{firstName: string, infix: string, lastName: string, prefix: string, suffix: string}
     */
    public function split(string $fullName): array
    {
        $out = ['firstName' => '', 'infix' => '', 'lastName' => '', 'prefix' => '', 'suffix' => ''];

        // Collapse whitespace; split on spaces.
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $fullName));
        if ($normalized === '') {
            return $out;
        }
        $tokens = explode(' ', $normalized);

        // 1) Strip leading prefix tokens (Dr., Ir., Drs., Prof., Mr., Mw., ...).
        $prefixParts = [];
        while ($tokens !== [] && $this->isPrefix($tokens[0])) {
            $prefixParts[] = array_shift($tokens);
        }
        if ($prefixParts !== []) {
            $out['prefix'] = implode(' ', $prefixParts);
        }

        // 2) Strip trailing suffix tokens (Jr., Sr., II, III, ...).
        $suffixParts = [];
        while ($tokens !== [] && $this->isSuffix($tokens[count($tokens) - 1])) {
            array_unshift($suffixParts, array_pop($tokens));
        }
        if ($suffixParts !== []) {
            $out['suffix'] = implode(' ', $suffixParts);
        }

        if ($tokens === []) {
            return $out;
        }

        // 3) firstName = first token.
        $out['firstName'] = array_shift($tokens);
        if ($tokens === []) {
            return $out;
        }

        // 4) Greedy-collect infix tokens from the front of what remains.
        //    Multi-word infixes ("van der", "de la") naturally fall out of
        //    the per-token check. Stop at the first non-infix token — the
        //    rest becomes lastName.
        $infixParts = [];
        while ($tokens !== [] && $this->isInfix($tokens[0])) {
            $infixParts[] = array_shift($tokens);
        }
        if ($infixParts !== []) {
            $out['infix'] = implode(' ', $infixParts);
        }

        // 5) Whatever remains is the lastName. If infix consumed everything,
        //    the last infix token is promoted to lastName so the entry never
        //    saves with an empty lastName — defensive fallback for
        //    "Jan van" style inputs.
        if ($tokens !== []) {
            $out['lastName'] = implode(' ', $tokens);
        } elseif ($out['infix'] !== '') {
            $infixTokens = explode(' ', $out['infix']);
            $out['lastName'] = (string) array_pop($infixTokens);
            $out['infix'] = $infixTokens === [] ? '' : implode(' ', $infixTokens);
        }

        return $out;
    }

    private function isPrefix(string $token): bool
    {
        return in_array(mb_strtolower($token), self::PREFIX_TOKENS, true);
    }

    private function isInfix(string $token): bool
    {
        return in_array(mb_strtolower($token), self::INFIX_TOKENS, true);
    }

    private function isSuffix(string $token): bool
    {
        return in_array(mb_strtolower($token), self::SUFFIX_TOKENS, true);
    }
}
