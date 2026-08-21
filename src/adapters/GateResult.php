<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

final class GateResult
{
    private function __construct(
        public readonly Adapter $adapter,
        public readonly GateStatus $status,
    ) {
    }

    public static function ready(Adapter $adapter): self
    {
        return new self($adapter, GateStatus::Ready);
    }

    public static function disabledByOperator(Adapter $adapter): self
    {
        return new self($adapter, GateStatus::DisabledByOperator);
    }

    public static function pluginMissing(Adapter $adapter): self
    {
        return new self($adapter, GateStatus::PluginMissing);
    }

    public function isReady(): bool
    {
        return $this->status === GateStatus::Ready;
    }

    /**
     * The line a skipped adapter puts in the run report. Ready adapters have
     * nothing to say, which is why this is null rather than an empty string —
     * a caller that reports it unconditionally should not compile.
     */
    public function reason(): ?string
    {
        return match ($this->status) {
            GateStatus::Ready => null,
            GateStatus::DisabledByOperator => sprintf(
                '%s adapter disabled (explicitly via Settings::%s); %s migration skipped.',
                $this->adapter->label,
                $this->adapter->settingsFlag,
                $this->adapter->handle,
            ),
            GateStatus::PluginMissing => sprintf(
                '%s plugin not installed; %s migration skipped.',
                $this->adapter->pluginHandle ?? $this->adapter->label,
                $this->adapter->handle,
            ),
        };
    }
}
