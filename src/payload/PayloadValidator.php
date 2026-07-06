<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\payload;

/**
 * Checks a structurally-valid `Payload` against the live Craft schema and
 * the `sourceUid` grammar. Collects every violation in one pass rather than
 * failing fast — see docs/loader-contract.md for the full rule list.
 */
final class PayloadValidator
{
    private const SOURCE_UID_PATTERN = '/^kuma:[A-Za-z0-9_-]+:[a-z0-9_]+:\d+$/D';

    public function __construct(private readonly SchemaGateway $gateway)
    {
    }

    /**
     * @return list<Violation>
     */
    public function validate(Payload $p): array
    {
        $violations = [];

        if (!$this->isValidUid($p->sourceUid)) {
            $violations[] = $this->violation(
                $p,
                'BAD_SOURCE_UID',
                sprintf('sourceUid "%s" does not match the kuma:<ENV>:<table>:<id> grammar.', $p->sourceUid),
            );
        }
        foreach ($p->aliases as $alias) {
            if (!$this->isValidUid($alias)) {
                $violations[] = $this->violation(
                    $p,
                    'BAD_SOURCE_UID',
                    sprintf('alias "%s" does not match the kuma:<ENV>:<table>:<id> grammar.', $alias),
                );
            }
        }

        if ($this->gateway->sectionByHandle($p->section) === null) {
            $violations[] = $this->violation(
                $p,
                'UNKNOWN_SECTION',
                sprintf('Section "%s" is not registered in Craft.', $p->section),
            );
        }

        $entryType = $this->gateway->entryTypeByHandle($p->entryType);
        if ($entryType === null) {
            $violations[] = $this->violation(
                $p,
                'UNKNOWN_ENTRY_TYPE',
                sprintf('Entry type "%s" is not registered in Craft.', $p->entryType),
            );
        }

        $anyEnabled = false;
        foreach ($p->sites as $handle => $data) {
            if ($this->gateway->siteByHandle($handle) === null) {
                $violations[] = $this->violation(
                    $p,
                    'UNKNOWN_SITE',
                    sprintf('Site "%s" is not registered in Craft.', $handle),
                );
            }
            if ($data['enabled']) {
                $anyEnabled = true;
            }
        }
        if (!$anyEnabled) {
            $violations[] = $this->violation($p, 'NO_ENABLED_SITE', 'No site is enabled for this payload.');
        }

        foreach ($p->sites as $handle => $data) {
            if ($entryType !== null) {
                foreach ($data['fieldValues'] as $fieldHandle => $value) {
                    if (!in_array($fieldHandle, $this->gateway->fieldHandlesFor($p->entryType), true)) {
                        $violations[] = $this->violation(
                            $p,
                            'UNKNOWN_FIELD',
                            sprintf('Field "%s" is not in the "%s" field layout (site "%s").', $fieldHandle, $p->entryType, $handle),
                        );
                        continue;
                    }
                    array_push($violations, ...$this->validateBlockTypes($p, $handle, (string) $fieldHandle, $value));
                }

                if (!$entryType['hasTitleFormat'] && $data['enabled'] && !$this->hasNonEmptyString($data['title'])) {
                    $violations[] = $this->violation(
                        $p,
                        'MISSING_TITLE',
                        sprintf('Site "%s" is enabled but has no title, and entry type "%s" has no title format.', $handle, $p->entryType),
                    );
                }
            }

            array_push($violations, ...$this->validateRefs($p, $handle, $data));

            if ($data['postDate'] !== null && !$this->isValidIso8601($data['postDate'])) {
                $violations[] = $this->violation(
                    $p,
                    'BAD_DATE',
                    sprintf('postDate "%s" on site "%s" is not ISO 8601.', $data['postDate'], $handle),
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function validateBlockTypes(Payload $p, string $siteHandle, string $fieldHandle, mixed $value): array
    {
        if (!is_array($value) || !$this->looksLikeMatrixPayload($value)) {
            return [];
        }
        $allowed = $this->gateway->blockTypesFor($p->entryType, $fieldHandle);
        if ($allowed === []) {
            return [];
        }

        $violations = [];
        foreach ($value as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if ($type === null || $type === '' || !in_array($type, $allowed, true)) {
                $violations[] = $this->violation(
                    $p,
                    'UNKNOWN_BLOCK_TYPE',
                    sprintf('Block type "%s" is not allowed on field "%s" (site "%s").', $this->describe($type), $fieldHandle, $siteHandle),
                );
            }
        }

        return $violations;
    }

    /**
     * @param array{enabled: bool, title: ?string, slug: ?string, fieldValues: array<string, mixed>, parentRef: ?string, postDate: ?string} $data
     * @return list<Violation>
     */
    private function validateRefs(Payload $p, string $siteHandle, array $data): array
    {
        $violations = [];

        if ($data['parentRef'] !== null && !$this->isValidUid($data['parentRef'])) {
            $violations[] = $this->violation(
                $p,
                'BAD_REF',
                sprintf('parentRef "%s" on site "%s" does not match the sourceUid grammar.', $data['parentRef'], $siteHandle),
            );
        }

        foreach ($this->findRefs($data['fieldValues']) as $ref) {
            if (!is_string($ref) || !$this->isValidUid($ref)) {
                $violations[] = $this->violation(
                    $p,
                    'BAD_REF',
                    sprintf('_ref "%s" on site "%s" does not match the sourceUid grammar.', $this->describe($ref), $siteHandle),
                );
            }
        }

        return $violations;
    }

    /**
     * Recursively collect every `_ref` value nested anywhere inside a
     * fieldValues hash (matrix blocks, relation lists, ...). Non-string
     * values are collected too (not skipped) so the caller can flag them
     * as BAD_REF instead of letting them silently escape validation.
     *
     * @param array<mixed> $value
     * @return list<mixed>
     */
    private function findRefs(array $value): array
    {
        $refs = [];
        foreach ($value as $key => $item) {
            if ($key === '_ref') {
                $refs[] = $item;
                continue;
            }
            if (is_array($item)) {
                array_push($refs, ...$this->findRefs($item));
            }
        }

        return $refs;
    }

    /**
     * @param array<mixed> $value
     */
    private function looksLikeMatrixPayload(array $value): bool
    {
        $first = reset($value);

        return is_array($first) && array_key_exists('type', $first);
    }

    private function isValidUid(string $uid): bool
    {
        return preg_match(self::SOURCE_UID_PATTERN, $uid) === 1;
    }

    private function isValidIso8601(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value, $m) !== 1) {
            return false;
        }
        [, $year, $month, $day, $hour, $minute, $second] = $m;

        return checkdate((int) $month, (int) $day, (int) $year)
            && (int) $hour < 24 && (int) $minute < 60 && (int) $second < 60;
    }

    private function hasNonEmptyString(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * Safe stringification for violation messages when a value that should
     * have been a string (block `type`, `_ref`) turns out not to be one.
     */
    private function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : gettype($value);
    }

    private function violation(Payload $p, string $code, string $message): Violation
    {
        return new Violation($p->sourceUid, $code, $message);
    }
}
