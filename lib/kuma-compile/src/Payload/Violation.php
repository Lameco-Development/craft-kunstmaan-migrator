<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Payload;

/**
 * One failed rule from `PayloadValidator::validate()`. See
 * docs/loader-contract.md for the full list of `$code` values.
 */
final class Violation
{
    public function __construct(
        public readonly string $sourceUid,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    /**
     * @return array{sourceUid: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'sourceUid' => $this->sourceUid,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
