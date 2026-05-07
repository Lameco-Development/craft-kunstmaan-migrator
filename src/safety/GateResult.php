<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\safety;

use InvalidArgumentException;

final class GateResult
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_WARNING = 'warning';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_UNKNOWN = 'unknown';

    /** @var list<string> */
    private const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_WARNING,
        self::STATUS_BLOCKED,
        self::STATUS_UNKNOWN,
    ];

    public readonly bool $blocking;

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $status,
        public readonly string $severity,
        public readonly string $message,
        public readonly string $remediation,
        public readonly string $cliCommand,
        bool $blocking,
    ) {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported gate status "%s"; expected one of: %s.',
                $status,
                implode(', ', self::ALLOWED_STATUSES),
            ));
        }

        $this->blocking = $status === self::STATUS_UNKNOWN || $blocking;
    }

    /**
     * @return array{id: string, label: string, status: string, severity: string, message: string, remediation: string, cliCommand: string, blocking: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'severity' => $this->severity,
            'message' => $this->message,
            'remediation' => $this->remediation,
            'cliCommand' => $this->cliCommand,
            'blocking' => $this->blocking,
        ];
    }
}
