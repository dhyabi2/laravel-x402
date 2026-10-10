<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Support;

use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;
use JsonException;

final class Json
{
    /**
     * Encode a value for the wire. Slashes are kept unescaped so resource URLs
     * stay readable in decoded headers.
     *
     * @param array<array-key, mixed> $value
     */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Encode a value with object keys sorted recursively, for stable hashing.
     *
     * @param array<array-key, mixed> $value
     */
    public static function canonical(array $value): string
    {
        return self::encode(self::sort($value));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws MalformedPaymentException
     */
    public static function decodeObject(string $json): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalformedPaymentException('Payload is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new MalformedPaymentException('Payload is not a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sort(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }
}
