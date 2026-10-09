<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Mapping;

/**
 * `forms.submissions:` — whether the legacy form submissions travel, and for
 * which form-owning nodes.
 *
 * Off unless declared. Years of leads moving is a client decision with a
 * data-retention answer attached, so no mapping migrates them by accident:
 *
 *     forms:
 *       submissions:
 *         nodes: all                 # or [222, 8] — the legacy `kuma_form_submissions.node_id`s
 *         volume: formieUploads      # where an uploaded file lands; none leaves file values empty
 *         filesRoot: /var/www/legacy/site/public   # what a legacy `/uploads/formsubmissions/…` url is under
 *
 * Keyed on the node because that is the only thing a submission names; the
 * node's page, live or deleted, decides which Formie form it lands in.
 */
final class SubmissionsLane
{
    /** @param ?list<int> $nodes null is every node that has a submission */
    private function __construct(
        public readonly bool $declared,
        public readonly ?array $nodes,
        public readonly ?string $volume,
        public readonly ?string $filesRoot,
    ) {
    }

    public static function fromSpec(mixed $spec): self
    {
        if ($spec === true) {
            return new self(true, null, null, null);
        }

        if (!is_array($spec) || $spec === []) {
            return new self(false, null, null, null);
        }

        $nodes = $spec['nodes'] ?? 'all';

        return new self(
            declared: true,
            nodes: is_array($nodes) ? array_values(array_map('intval', $nodes)) : null,
            volume: self::string($spec, 'volume'),
            filesRoot: self::string($spec, 'filesRoot'),
        );
    }

    public function includes(int $nodeId): bool
    {
        return $this->declared && ($this->nodes === null || in_array($nodeId, $this->nodes, true));
    }

    /** @param array<string, mixed> $spec */
    private static function string(array $spec, string $key): ?string
    {
        $value = $spec[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
