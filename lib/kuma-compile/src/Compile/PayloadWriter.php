<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Compile;

/**
 * Serialises one payload as a line of NDJSON.
 *
 * PHP cannot tell an empty map from an empty list, so `"fields": {}` would otherwise encode
 * as `"fields": []` and the loader would read a field set as a list of blocks. Keys that are
 * maps by contract are coerced back to objects on the way out.
 */
final class PayloadWriter
{
    private const OBJECT_KEYS = ['fields', 'fieldValues', 'sites'];

    /** @var resource|null */
    private $handle;

    private int $written = 0;

    /** @param resource|null $handle */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function write(array $payload): void
    {
        $this->written++;

        if ($this->handle === null) {
            return;
        }

        fwrite($this->handle, $this->encode($payload) . "\n");
    }

    public function encode(array $payload): string
    {
        return (string) json_encode(
            self::coerce($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function coerce(mixed $value, ?string $key = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $k => $v) {
            $out[$k] = self::coerce($v, is_string($k) ? $k : $key);
        }

        if ($out === [] && $key !== null && in_array($key, self::OBJECT_KEYS, true)) {
            return new \stdClass();
        }

        // A Matrix field whose blocks were built by index must serialise as a list.
        return $out;
    }

    public function count(): int
    {
        return $this->written;
    }
}
