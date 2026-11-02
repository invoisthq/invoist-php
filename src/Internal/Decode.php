<?php

declare(strict_types=1);

namespace Invoist\Internal;

/**
 * Reads typed fields out of a decoded JSON object, for the generated
 * response classes.
 *
 * Unknown fields are ignored (the API adds fields). A field the spec
 * requires that is missing or has the wrong type throws
 * \UnexpectedValueException naming the field; the client turns that into an
 * InvoistError with the code `unexpected_response`. The opt* variants accept
 * a missing field or null and return null.
 *
 * @internal used by Invoist\Generated; not part of the public API.
 */
final readonly class Decode
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data, private string $type)
    {
    }

    public function string(string $key): string
    {
        $value = $this->required($key);

        return \is_string($value) ? $value : throw $this->wrong($key, 'a string', $value);
    }

    public function optString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return $value === null || \is_string($value) ? $value : throw $this->wrong($key, 'a string', $value);
    }

    public function int(string $key): int
    {
        return self::toInt($this->required($key)) ?? throw $this->wrong($key, 'an integer', $this->data[$key]);
    }

    public function optInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return $value === null ? null : (self::toInt($value) ?? throw $this->wrong($key, 'an integer', $value));
    }

    public function float(string $key): float
    {
        $value = $this->required($key);

        return \is_int($value) || \is_float($value) ? (float) $value : throw $this->wrong($key, 'a number', $value);
    }

    public function optFloat(string $key): ?float
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return \is_int($value) || \is_float($value) ? (float) $value : throw $this->wrong($key, 'a number', $value);
    }

    public function bool(string $key): bool
    {
        $value = $this->required($key);

        return \is_bool($value) ? $value : throw $this->wrong($key, 'a boolean', $value);
    }

    public function optBool(string $key): ?bool
    {
        $value = $this->data[$key] ?? null;

        return $value === null || \is_bool($value) ? $value : throw $this->wrong($key, 'a boolean', $value);
    }

    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $decode
     * @return T
     */
    public function object(string $key, callable $decode): mixed
    {
        $value = $this->required($key);

        return \is_array($value) ? $decode($value) : throw $this->wrong($key, 'an object', $value);
    }

    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $decode
     * @return T|null
     */
    public function optObject(string $key, callable $decode): mixed
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return \is_array($value) ? $decode($value) : throw $this->wrong($key, 'an object', $value);
    }

    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $decode
     * @return list<T>
     */
    public function list(string $key, callable $decode): array
    {
        return $this->decodeList($key, $this->required($key), $decode);
    }

    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $decode
     * @return list<T>|null
     */
    public function optList(string $key, callable $decode): ?array
    {
        $value = $this->data[$key] ?? null;

        return $value === null ? null : $this->decodeList($key, $value, $decode);
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        return $this->decodeStrings($key, $this->required($key));
    }

    /** @return list<string>|null */
    public function optStrings(string $key): ?array
    {
        $value = $this->data[$key] ?? null;

        return $value === null ? null : $this->decodeStrings($key, $value);
    }

    /** @return array<string, mixed> */
    public function map(string $key): array
    {
        return $this->decodeMap($key, $this->required($key));
    }

    /** @return array<string, mixed>|null */
    public function optMap(string $key): ?array
    {
        $value = $this->data[$key] ?? null;

        return $value === null ? null : $this->decodeMap($key, $value);
    }

    /** @return array<string, float> */
    public function floatMap(string $key): array
    {
        return $this->decodeFloatMap($key, $this->required($key));
    }

    /** @return array<string, float>|null */
    public function optFloatMap(string $key): ?array
    {
        $value = $this->data[$key] ?? null;

        return $value === null ? null : $this->decodeFloatMap($key, $value);
    }

    private function required(string $key): mixed
    {
        if (!\array_key_exists($key, $this->data) || $this->data[$key] === null) {
            throw new \UnexpectedValueException(\sprintf('%s.%s is missing from the response', $this->type, $key));
        }

        return $this->data[$key];
    }

    private static function toInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        // json_decode gives a float for 1.0 and for integers beyond PHP_INT_MAX.
        if (\is_float($value) && floor($value) === $value && abs($value) < 9.2e18) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @template T
     * @param callable(array<array-key, mixed>): T $decode
     * @return list<T>
     */
    private function decodeList(string $key, mixed $value, callable $decode): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->wrong($key, 'a list', $value);
        }
        $out = [];
        foreach ($value as $i => $item) {
            if (!\is_array($item)) {
                throw $this->wrong("{$key}[$i]", 'an object', $item);
            }
            $out[] = $decode($item);
        }

        return $out;
    }

    /** @return list<string> */
    private function decodeStrings(string $key, mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->wrong($key, 'a list', $value);
        }
        $out = [];
        foreach ($value as $i => $item) {
            $out[] = \is_string($item) ? $item : throw $this->wrong("{$key}[$i]", 'a string', $item);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function decodeMap(string $key, mixed $value): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->wrong($key, 'an object', $value);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return array<string, float> */
    private function decodeFloatMap(string $key, mixed $value): array
    {
        $out = [];
        foreach ($this->decodeMap($key, $value) as $k => $v) {
            $out[$k] = \is_int($v) || \is_float($v) ? (float) $v : throw $this->wrong("$key.$k", 'a number', $v);
        }

        return $out;
    }

    private function wrong(string $key, string $expected, mixed $value): \UnexpectedValueException
    {
        return new \UnexpectedValueException(
            \sprintf('%s.%s should be %s, got %s', $this->type, $key, $expected, get_debug_type($value)),
        );
    }
}
