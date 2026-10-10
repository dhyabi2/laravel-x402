<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Support;

use Requestway\X402\Core\Exceptions\MalformedPaymentException;

/**
 * Base64 codec for x402 wire values.
 *
 * Encoding is always standard base64 with padding, matching the reference
 * implementation. Decoding additionally tolerates unpadded and URL-safe input,
 * because clients in the wild emit both.
 */
final class Base64
{
    public static function encode(string $value): string
    {
        return base64_encode($value);
    }

    /**
     * @throws MalformedPaymentException
     */
    public static function decode(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new MalformedPaymentException('Header value is empty.');
        }

        $normalized = strtr($value, '-_', '+/');

        if (preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $normalized) !== 1) {
            throw new MalformedPaymentException('Header value is not valid base64.');
        }

        $padding = strlen($normalized) % 4;

        if ($padding === 1) {
            throw new MalformedPaymentException('Header value is not valid base64.');
        }

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded === false || $decoded === '') {
            throw new MalformedPaymentException('Header value is not valid base64.');
        }

        return $decoded;
    }
}
